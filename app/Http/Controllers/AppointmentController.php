<?php

namespace App\Http\Controllers;

use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function confirm(Request $request): View
    {
        $input = $request->validate([
            'slot_id' => ['required', 'integer', 'min:1'],
            'request_key' => ['nullable', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,64}\z/'],
        ]);
        $slot = DB::table('slots')->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->where('slots.id', $input['slot_id'])->where('departments.status', 'ACTIVE')
            ->where('doctors.status', 'ACTIVE')->where('users.status', 'ACTIVE')->where('users.role', 'DOCTOR')
            ->select('slots.*', 'slots.status as slot_status', 'schedules.status as schedule_status', 'schedules.work_date',
                'doctors.id as doctor_id', 'doctors.full_name as doctor_name', 'doctors.title', 'departments.name as department_name')->firstOrFail();
        $patient = DB::table('patients')->where('user_id', $request->user()->id)->firstOrFail();
        $today = CarbonImmutable::now(config('mediqueue.hospital_timezone'))->startOfDay();
        $available = $slot->slot_status === 'OPEN' && $slot->schedule_status === 'OPEN'
            && $slot->occupied_count < $slot->capacity && $slot->starts_at > CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v')
            && $slot->work_date >= $today->toDateString() && $slot->work_date <= $today->addDays(config('mediqueue.availability_days') - 1)->toDateString();
        // A read-only preview never reserves capacity; the existing transaction is the final authority.
        $oldKey = $request->old('request_key');
        $requestKey = $request->old('slot_id') == $slot->id && is_string($oldKey) && preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $oldKey)
            ? $oldKey : ($input['request_key'] ?? (string) Str::uuid());

        return view('appointments.confirm', compact('slot', 'patient', 'requestKey', 'available'));
    }

    public function store(Request $request, AppointmentService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->all()), ['_token', 'slot_id', 'request_key'])) {
            throw ValidationException::withMessages(['slot_id' => '请求包含不允许的字段。']);
        }
        $data = $request->validate([
            'slot_id' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,64}\z/'],
        ]);
        $id = $service->create((int) $request->user()->id, (int) $data['slot_id'], $data['request_key']);

        return redirect()->route('appointments.show', $id);
    }

    public function index(Request $request): View
    {
        $input = $request->validate(['status' => ['nullable', 'in:PENDING_PAYMENT,CONFIRMED,COMPLETED,CANCELLED,EXPIRED']]);
        $appointments = DB::table('appointments')->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->join('slots', 'slots.id', '=', 'appointments.slot_id')->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->join('payment_orders', 'payment_orders.appointment_id', '=', 'appointments.id')->leftJoin('refunds', 'refunds.payment_order_id', '=', 'payment_orders.id')
            ->where('patients.user_id', $request->user()->id)
            ->when($input['status'] ?? null, fn ($query, $status) => $query->where('appointments.status', $status))
            ->select('appointments.id', 'appointments.appointment_no', 'appointments.status', 'appointments.created_at', 'appointments.fee_amount',
                'doctors.full_name as doctor_name', 'departments.name as department_name', 'slots.starts_at', 'slots.ends_at',
                'payment_orders.status as order_status', 'refunds.status as refund_status')
            ->orderByDesc('appointments.id')->paginate(20)->withQueryString();

        return view('appointments.index', compact('appointments'));
    }

    public function show(Request $request, int $id): View
    {
        $appointment = DB::table('appointments')
            ->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->join('payment_orders', 'payment_orders.appointment_id', '=', 'appointments.id')
            ->leftJoin('refunds', 'refunds.payment_order_id', '=', 'payment_orders.id')
            ->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->join('users as doctor_users', 'doctor_users.id', '=', 'doctors.user_id')
            ->where('patients.user_id', $request->user()->id)->where('appointments.id', $id)
            ->select('appointments.*', 'payment_orders.order_no', 'payment_orders.status as order_status', 'refunds.refund_no', 'refunds.status as refund_status', 'refunds.last_error',
                'slots.starts_at', 'slots.ends_at', 'slots.status as slot_status', 'schedules.status as schedule_status',
                'departments.status as department_status', 'departments.name as department_name',
                'doctors.status as doctor_status', 'doctors.full_name as doctor_name', 'doctor_users.status as doctor_user_status')
            ->firstOrFail();
        $now = CarbonImmutable::now('UTC');
        $expires = CarbonImmutable::parse($appointment->expires_at, 'UTC');
        $remaining = max(0, (int) $now->diffInSeconds($expires, false));
        $expired = $now->greaterThanOrEqualTo($expires);
        $suspended = $appointment->schedule_status !== 'OPEN' || $appointment->slot_status !== 'OPEN'
            || $appointment->department_status !== 'ACTIVE' || $appointment->doctor_status !== 'ACTIVE'
            || $appointment->doctor_user_status !== 'ACTIVE';

        return view($request->routeIs('appointments.checkout') ? 'appointments.payment' : 'appointments.show', compact('appointment', 'expires', 'remaining', 'expired', 'suspended'));
    }
}
