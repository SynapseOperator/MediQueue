<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class AppointmentLifecycleService
{
    public function __construct(private PaymentGateway $gateway) {}

    /** @param 'success'|'declined'|'unknown' $scenario */
    public function pay(int $userId, int $id, string $scenario = 'success'): string
    {
        $this->ensureMockEnabled();

        return $this->transition($id, $userId,
            fn (LockedAppointmentContext $context): string => $this->payLocked($context, $userId, $scenario));
    }

    /** @param 'success'|'declined'|'unknown' $scenario */
    private function payLocked(LockedAppointmentContext $context, int $userId, string $scenario): string
    {
        $this->authorize($context, $userId, ['PATIENT']);
        $appointment = $context->appointment;
        $order = $context->order;
        $this->checkAmount($appointment, $order);
        // An already handled payment retains its original fact even after expiry, cancellation or refund.
        if ($order->paid_at !== null) {
            return $appointment->status;
        }
        if (in_array($context->appointmentStatus, [AppointmentStatus::Cancelled, AppointmentStatus::Expired], true)) {
            return $appointment->status;
        }
        $this->requireState(
            $context->appointmentStatus === AppointmentStatus::PendingPayment
                && $context->paymentStatus === PaymentStatus::Unpaid,
            '当前订单不能支付。',
        );
        if ($context->now >= $appointment->expires_at) {
            $this->expireLocked($context);

            return AppointmentStatus::Expired->value;
        }
        $this->requireState(
            $context->now < $context->slot->starts_at
                && $context->schedule->status === 'OPEN'
                && $context->slot->status === 'OPEN'
                && $context->department->status === 'ACTIVE'
                && $context->doctor->status === 'ACTIVE'
                && $context->doctorAccount->status === 'ACTIVE'
                && $context->doctorAccount->role === 'DOCTOR',
            '出诊资源已停诊、关闭或开始，不能付款。',
        );
        $event = $this->gateway->payment($order, $scenario);
        $this->checkEvent($event, $order);
        $this->requireState(
            in_array($event['result'], [PaymentStatus::Paid->value, 'DECLINED', 'UNKNOWN'], true),
            '模拟支付结果无法核实。',
        );
        if ($event['result'] !== PaymentStatus::Paid->value) {
            $this->audit(
                $context,
                'PAYMENT_'.$event['result'],
                'payment_orders',
                $order->id,
                ['status' => PaymentStatus::Unpaid->value],
                ['status' => PaymentStatus::Unpaid->value, 'result' => $event['result']],
            );

            return $event['result'];
        }
        DB::table('payment_orders')->where('id', $order->id)->update([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => $context->now,
            'updated_at' => $context->now,
        ]);
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Confirmed->value,
            'confirmed_at' => $context->now,
            'updated_at' => $context->now,
        ]);
        $this->audit(
            $context,
            'PAYMENT_CONFIRMED',
            'appointments',
            $appointment->id,
            ['status' => $appointment->status],
            [
                'status' => AppointmentStatus::Confirmed->value,
                'payment_order_id' => $order->id,
                'amount' => $order->amount,
            ],
        );

        return AppointmentStatus::Confirmed->value;
    }

    public function cancel(int $actorId, int $id, string $reasonCode = 'PATIENT_REQUEST', ?string $reason = null): string
    {
        return $this->transition($id, $actorId,
            fn (LockedAppointmentContext $context): string => $this->cancelLocked($context, $actorId, $reasonCode, $reason));
    }

    private function cancelLocked(LockedAppointmentContext $context, int $actorId, string $reasonCode, ?string $reason): string
    {
        $this->authorize($context, $actorId, ['PATIENT', 'ADMIN']);
        $appointment = $context->appointment;
        $order = $context->order;
        if (in_array($context->appointmentStatus, [AppointmentStatus::Cancelled, AppointmentStatus::Expired], true)) {
            return $appointment->status;
        }
        $this->requireState(
            in_array($context->appointmentStatus, [AppointmentStatus::PendingPayment, AppointmentStatus::Confirmed], true),
            '已完成预约不能取消。',
        );
        if ($context->appointmentStatus === AppointmentStatus::PendingPayment && $context->now >= $appointment->expires_at) {
            $this->expireLocked($context);

            return AppointmentStatus::Expired->value;
        }
        if ($context->actor->role === 'PATIENT') {
            $this->requireState(
                $reasonCode === 'PATIENT_REQUEST'
                    && $context->now < $context->slot->starts_at,
                '患者只能在就诊开始前取消本人预约。',
            );
            $reason = null;
        } else {
            $this->requireState(
                in_array($reasonCode, ['CLINIC_SUSPENDED', 'ADMIN_CORRECTION'], true)
                    && is_string($reason)
                    && trim($reason) !== '',
                '管理员异常取消必须提供原因。',
            );
            if ($reasonCode === 'CLINIC_SUSPENDED') {
                $this->requireState($context->schedule->status === 'SUSPENDED', '该排班尚未停诊。');
            }
        }
        $this->checkAmount($appointment, $order);
        $this->requireState(
            ($context->appointmentStatus === AppointmentStatus::PendingPayment
                && $context->paymentStatus === PaymentStatus::Unpaid)
                || ($context->appointmentStatus === AppointmentStatus::Confirmed
                && $context->paymentStatus === PaymentStatus::Paid),
            '预约和订单状态不一致，请核实。',
        );
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Cancelled->value, 'cancelled_at' => $context->now, 'cancelled_by' => $actorId,
            'cancel_reason_code' => $reasonCode, 'cancel_reason' => $reason,
            'released_at' => $context->now, 'updated_at' => $context->now,
        ]);
        $this->release($context);
        if ($context->paymentStatus === PaymentStatus::Paid) {
            DB::table('refunds')->insert([
                'payment_order_id' => $order->id, 'refund_no' => 'R'.Str::ulid(), 'amount' => $order->amount,
                'status' => RefundStatus::Pending->value, 'reason_code' => $reasonCode, 'requested_by' => $actorId,
                'created_at' => $context->now, 'updated_at' => $context->now,
            ]);
            DB::table('payment_orders')->where('id', $order->id)->update([
                'status' => PaymentStatus::RefundPending->value,
                'updated_at' => $context->now,
            ]);
        } else {
            DB::table('payment_orders')->where('id', $order->id)->update([
                'status' => $this->gateway->close($order),
                'closed_at' => $context->now,
                'updated_at' => $context->now,
            ]);
        }
        $this->audit(
            $context,
            'APPOINTMENT_CANCELLED',
            'appointments',
            $appointment->id,
            ['status' => $appointment->status, 'occupied_count' => $context->slot->occupied_count],
            [
                'status' => AppointmentStatus::Cancelled->value,
                'reason_code' => $reasonCode,
                'occupied_count' => $context->slot->occupied_count - 1,
                'payment_order_id' => $order->id,
            ],
        );

        return AppointmentStatus::Cancelled->value;
    }

    public function expire(int $id): bool
    {
        return $this->transition($id, null, fn (LockedAppointmentContext $context): bool => $this->expireLocked($context));
    }

    /** Scan outside any business locks; each candidate uses the same short patient-first transaction. */
    public function reclaim(?int $patientId = null, ?int $slotId = null, int $limit = 100): int
    {
        $ids = DB::table('appointments')->where('status', AppointmentStatus::PendingPayment->value)
            ->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'))
            ->when($patientId !== null || $slotId !== null, function ($query) use ($patientId, $slotId): void {
                $query->where(function ($query) use ($patientId, $slotId): void {
                    if ($patientId !== null) {
                        $query->where('patient_id', $patientId);
                    }
                    if ($slotId !== null) {
                        $query->orWhere('slot_id', $slotId);
                    }
                });
            })->orderBy('expires_at')->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += (int) $this->expire($id);
        }

        return $expired;
    }

    public function complete(int $adminId, int $id): string
    {
        return $this->transition($id, $adminId,
            fn (LockedAppointmentContext $context): string => $this->completeLocked($context, $adminId));
    }

    private function completeLocked(LockedAppointmentContext $context, int $adminId): string
    {
        $this->authorize($context, $adminId, ['ADMIN']);
        $appointment = $context->appointment;
        if ($context->appointmentStatus === AppointmentStatus::Completed) {
            return AppointmentStatus::Completed->value;
        }
        $this->checkAmount($appointment, $context->order);
        $this->requireState(
            $context->appointmentStatus === AppointmentStatus::Confirmed
                && $context->paymentStatus === PaymentStatus::Paid
                && $context->order->paid_at !== null
                && $context->now >= $context->slot->starts_at,
            '只有已开始、已付款并由管理员核实的预约可登记完成。',
        );
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Completed->value,
            'completed_at' => $context->now,
            'completed_by' => $adminId,
            'updated_at' => $context->now,
        ]);
        $this->audit(
            $context,
            'APPOINTMENT_COMPLETED',
            'appointments',
            $appointment->id,
            ['status' => AppointmentStatus::Confirmed->value],
            ['status' => AppointmentStatus::Completed->value, 'completed_by' => $adminId],
        );

        return AppointmentStatus::Completed->value;
    }

    /** @param 'success'|'failed'|'unknown' $scenario */
    public function refund(int $adminId, int $id, string $scenario = 'success'): string
    {
        $this->ensureMockEnabled();

        return $this->transition($id, $adminId,
            fn (LockedAppointmentContext $context): string => $this->refundLocked($context, $adminId, $scenario));
    }

    /** @param 'success'|'failed'|'unknown' $scenario */
    private function refundLocked(LockedAppointmentContext $context, int $adminId, string $scenario): string
    {
        $this->authorize($context, $adminId, ['ADMIN']);
        $refund = $context->refund;
        $order = $context->order;
        $this->requireState($refund !== null, '没有待处理的模拟退款。');
        $this->checkAmount($context->appointment, $order);
        $this->requireState($refund->amount === $order->amount && $order->paid_at !== null, '退款金额或付款事实不一致。');
        if ($context->refundStatus === RefundStatus::Succeeded) {
            return RefundStatus::Succeeded->value;
        }
        $this->requireState(
            $context->appointmentStatus === AppointmentStatus::Cancelled
                && $context->paymentStatus === PaymentStatus::RefundPending,
            '当前记录不能退款。',
        );
        $event = $this->gateway->refund($order, $refund, $scenario);
        $this->checkEvent($event, $order);
        $this->requireState(
            $event['refund_no'] === $refund->refund_no
                && $event['refund_amount'] === $refund->amount
                && in_array($event['result'], [RefundStatus::Succeeded->value, RefundStatus::Failed->value, RefundStatus::Pending->value], true),
            '模拟退款结果无法核实。',
        );
        $state = $event['result'];
        DB::table('refunds')->where('id', $refund->id)->update([
            'status' => $state, 'succeeded_at' => $state === RefundStatus::Succeeded->value ? $context->now : null,
            'last_error' => match ($state) {
                RefundStatus::Failed->value => '模拟退款被拒绝，可重试原退款。', RefundStatus::Pending->value => '模拟退款结果未知，待核实；未确认退款成功。', default => null
            },
            'updated_at' => $context->now,
        ]);
        if ($state === RefundStatus::Succeeded->value) {
            DB::table('payment_orders')->where('id', $order->id)->update([
                'status' => PaymentStatus::Refunded->value,
                'updated_at' => $context->now,
            ]);
        }
        $this->audit(
            $context,
            'REFUND_'.$state,
            'refunds',
            $refund->id,
            ['status' => $refund->status],
            ['status' => $state, 'payment_order_id' => $order->id, 'amount' => $refund->amount],
        );

        return $state;
    }

    private function transition(int $id, ?int $actorId, Closure $change): mixed
    {
        try {
            return DB::transaction(fn () => $change($this->locked($id, $actorId)), 3);
        } catch (Throwable $exception) {
            if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                throw ValidationException::withMessages(['appointment' => '操作繁忙，请重试此预约的原操作。']);
            }
            if ((new LostConnectionDetector)->causedByLostConnection($exception)) {
                DB::purge();
                // Every operation is keyed by the same persisted appointment/order/refund, never by a new transaction identity.
                throw ValidationException::withMessages(['appointment' => '提交结果尚未确认，请刷新此预约核实状态后重试原操作。']);
            }
            throw $exception;
        }
    }

    private function locked(int $id, ?int $actorId): LockedAppointmentContext
    {
        $candidate = DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')->where('appointments.id', $id)
            ->first(['appointments.patient_id', 'appointments.slot_id', 'slots.schedule_id', 'schedules.department_id', 'schedules.doctor_id']);
        abort_unless($candidate !== null, 404);
        $patient = DB::table('patients')->where('id', $candidate->patient_id)->lockForUpdate()->first();
        $accounts = DB::table('users')->whereIn('id', array_filter([$patient->user_id, $actorId]))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $actor = $actorId === null ? null : $accounts->get($actorId);
        $department = DB::table('departments')->where('id', $candidate->department_id)->sharedLock()->first();
        $doctor = DB::table('doctors')->where('id', $candidate->doctor_id)->sharedLock()->first();
        $doctorAccount = DB::table('users')->where('id', $doctor->user_id)->sharedLock()->first();
        $schedule = DB::table('schedules')->where('id', $candidate->schedule_id)->sharedLock()->first();
        $slot = DB::table('slots')->where('id', $candidate->slot_id)->lockForUpdate()->first();
        $appointment = DB::table('appointments')->where('id', $id)->lockForUpdate()->first();
        $order = DB::table('payment_orders')->where('appointment_id', $id)->lockForUpdate()->first();
        $refund = DB::table('refunds')->where('payment_order_id', $order->id)->lockForUpdate()->first();
        $this->requireState(
            $appointment->patient_id === $patient->id
                && $appointment->slot_id === $slot->id
                && $slot->schedule_id === $schedule->id
                && $schedule->doctor_id === $doctor->id
                && $schedule->department_id === $department->id,
            '历史关联已变化，请核实。',
        );
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');

        return new LockedAppointmentContext(
            $patient, $actor, $department, $doctor, $doctorAccount,
            $schedule, $slot, $appointment, $order, $refund, $now,
        );
    }

    private function authorize(LockedAppointmentContext $context, int $actorId, array $roles): void
    {
        abort_unless($context->actor?->status === 'ACTIVE' && in_array($context->actor->role, $roles, true), 403);
        if ($context->actor->role === 'PATIENT') {
            abort_unless($context->patient->user_id === $actorId, 404);
        }
    }

    private function expireLocked(LockedAppointmentContext $context): bool
    {
        $appointment = $context->appointment;
        $order = $context->order;
        if ($context->appointmentStatus !== AppointmentStatus::PendingPayment || $context->now < $appointment->expires_at) {
            return false;
        }
        $this->checkAmount($appointment, $order);
        $this->requireState($context->paymentStatus === PaymentStatus::Unpaid, '到期记录存在不一致付款状态，请核实。');
        DB::table('appointments')->where('id', $appointment->id)->update([
            'status' => AppointmentStatus::Expired->value,
            'released_at' => $context->now,
            'updated_at' => $context->now,
        ]);
        DB::table('payment_orders')->where('id', $order->id)->update([
            'status' => $this->gateway->close($order),
            'closed_at' => $context->now,
            'updated_at' => $context->now,
        ]);
        $this->release($context);
        $this->audit(
            $context,
            'APPOINTMENT_EXPIRED',
            'appointments',
            $appointment->id,
            ['status' => $appointment->status, 'occupied_count' => $context->slot->occupied_count],
            [
                'status' => AppointmentStatus::Expired->value,
                'occupied_count' => $context->slot->occupied_count - 1,
            ],
        );

        return true;
    }

    private function release(LockedAppointmentContext $context): void
    {
        $updated = DB::table('slots')->where('id', $context->slot->id)->where('occupied_count', '>', 0)
            ->decrement('occupied_count', 1, ['updated_at' => $context->now]);
        $this->requireState($updated === 1, '名额占用异常，已回滚，请核实。');
    }

    private function checkAmount(stdClass $appointment, stdClass $order): void
    {
        $this->requireState(
            $appointment->fee_amount === $order->amount
                && $order->currency === 'CNY'
                && $order->channel === 'MOCK_WECHAT',
            '订单金额、币种或渠道不一致。',
        );
    }

    private function checkEvent(array $event, stdClass $order): void
    {
        $this->requireState(
            $event['order_no'] === $order->order_no
                && $event['amount'] === $order->amount
                && $event['currency'] === 'CNY'
                && $event['channel'] === 'MOCK_WECHAT',
            '模拟事件与订单不匹配。',
        );
    }

    private function ensureMockEnabled(): void
    {
        abort_unless(app()->environment(['local', 'demo', 'testing']) && config('mediqueue.mock_payments_enabled'), 403);
    }

    private function requireState(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['appointment' => $message]);
        }
    }

    private function audit(LockedAppointmentContext $context, string $action, string $target, int $id, array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => $context->actor ? 'USER' : 'SYSTEM', 'operator_id' => $context->actor?->id,
            'action' => $action, 'target_type' => $target, 'target_id' => $id,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => $context->now,
        ]);
    }
}
