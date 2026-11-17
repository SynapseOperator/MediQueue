<?php

namespace App\Http\Controllers;

use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
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
        $appointments = DB::table('appointments')->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->where('patients.user_id', $request->user()->id)
            ->select('appointments.id', 'appointments.appointment_no', 'appointments.status', 'appointments.created_at')
            ->orderByDesc('appointments.id')->paginate(20);

        return view('appointments.index', compact('appointments'));
    }

    public function show(Request $request, int $id): View
    {
        $appointment = DB::table('appointments')
            ->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->join('payment_orders', 'payment_orders.appointment_id', '=', 'appointments.id')
            ->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->join('users as doctor_users', 'doctor_users.id', '=', 'doctors.user_id')
            ->where('patients.user_id', $request->user()->id)->where('appointments.id', $id)
            ->select('appointments.*', 'payment_orders.order_no', 'payment_orders.status as order_status',
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

        return view('appointments.show', compact('appointment', 'expires', 'remaining', 'expired', 'suspended'));
    }
}
