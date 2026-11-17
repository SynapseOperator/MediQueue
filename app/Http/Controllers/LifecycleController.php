<?php

namespace App\Http\Controllers;

use App\Services\AppointmentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LifecycleController extends Controller
{
    public function pay(Request $request, int $id, AppointmentLifecycleService $service): RedirectResponse
    {
        $this->mockEnabled();
        $this->owned($request, $id);
        $this->only($request, ['scenario']);
        $data = $request->validate(['scenario' => ['required', 'in:success,declined,unknown']]);
        $result = $service->pay((int) $request->user()->id, $id, $data['scenario']);

        $message = $result === 'UNKNOWN'
            ? '模拟支付结果未知，请刷新本预约核实后重试原操作（无实际扣款）。'
            : '模拟微信支付结果：'.$result.'（无实际扣款）。';

        return redirect()->route('appointments.show', $id)->with('status', $message);
    }

    public function patientCancel(Request $request, int $id, AppointmentLifecycleService $service): RedirectResponse
    {
        $this->owned($request, $id);
        $this->only($request, []);
        $service->cancel((int) $request->user()->id, $id);

        return redirect()->route('appointments.show', $id)->with('status', '已提交取消；如有退款，请查看退款状态。');
    }

    public function adminIndex(): View
    {
        $appointments = DB::table('appointments')->join('payment_orders', 'payment_orders.appointment_id', '=', 'appointments.id')
            ->leftJoin('refunds', 'refunds.payment_order_id', '=', 'payment_orders.id')
            ->select('appointments.id', 'appointments.appointment_no', 'appointments.status', 'payment_orders.status as order_status', 'refunds.status as refund_status')
            ->orderByDesc('appointments.id')->paginate(20);

        return view('admin.appointments', compact('appointments'));
    }

    public function adminShow(int $id): View
    {
        $appointment = DB::table('appointments')->join('payment_orders', 'payment_orders.appointment_id', '=', 'appointments.id')
            ->leftJoin('refunds', 'refunds.payment_order_id', '=', 'payment_orders.id')
            ->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
            ->join('departments', 'departments.id', '=', 'schedules.department_id')
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->select('appointments.*', 'payment_orders.order_no', 'payment_orders.status as order_status', 'refunds.refund_no', 'refunds.status as refund_status', 'refunds.last_error', 'slots.starts_at', 'slots.ends_at', 'slots.status as slot_status', 'schedules.status as schedule_status', 'departments.name as department_name', 'doctors.full_name as doctor_name', 'patients.full_name as patient_name', 'patients.phone as patient_phone')
            ->where('appointments.id', $id)->firstOrFail();

        return view('admin.appointment', compact('appointment'));
    }

    public function adminCancel(Request $request, int $id, AppointmentLifecycleService $service): RedirectResponse
    {
        $this->only($request, ['reason_code', 'reason', 'verified_unseen']);
        $data = $request->validate(['reason_code' => ['required', 'in:CLINIC_SUSPENDED,ADMIN_CORRECTION'], 'reason' => ['required', 'string', 'max:255'], 'verified_unseen' => ['required', 'accepted']]);
        $service->cancel((int) $request->user()->id, $id, $data['reason_code'], $data['reason']);

        return back()->with('status', '取消已处理；已支付订单的退款需逐条处理。');
    }

    public function complete(Request $request, int $id, AppointmentLifecycleService $service): RedirectResponse
    {
        $this->only($request, ['verified']);
        $request->validate(['verified' => ['required', 'accepted']]);
        $service->complete((int) $request->user()->id, $id);

        return back()->with('status', '已人工登记完成。');
    }

    public function refund(Request $request, int $id, AppointmentLifecycleService $service): RedirectResponse
    {
        $this->mockEnabled();
        $this->only($request, ['scenario']);
        $data = $request->validate(['scenario' => ['required', 'in:success,failed,unknown']]);
        $result = $service->refund((int) $request->user()->id, $id, $data['scenario']);

        return back()->with('status', '模拟退款结果：'.$result.'（无实际资金流转）。');
    }

    private function mockEnabled(): void
    {
        abort_unless(config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']), 404);
    }

    private function owned(Request $request, int $id): void
    {
        abort_unless(DB::table('appointments')->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->where('patients.user_id', $request->user()->id)->where('appointments.id', $id)->exists(), 404);
    }

    private function only(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->request->all()), ['_token', ...$allowed])) {
            throw ValidationException::withMessages(['appointment' => '请求包含不允许的字段。']);
        }
    }
}
