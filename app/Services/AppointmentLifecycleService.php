<?php

namespace App\Services;

use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppointmentLifecycleService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function pay(int $userId, int $id, string $scenario = 'success'): string
    {
        $this->ensureMockEnabled();

        return $this->transition($id, $userId, function (array $c) use ($userId, $scenario): string {
            $this->authorize($c, $userId, ['PATIENT']);
            $a = $c['appointment'];
            $o = $c['order'];
            $this->checkAmount($a, $o);
            // An already handled payment retains its original fact even after expiry, cancellation or refund.
            if ($o->paid_at !== null) {
                return $a->status;
            }
            if (in_array($a->status, ['CANCELLED', 'EXPIRED'], true)) {
                return $a->status;
            }
            $this->requireState($a->status === 'PENDING_PAYMENT' && $o->status === 'UNPAID', '当前订单不能支付。');
            if ($c['now'] >= $a->expires_at) {
                $this->expireLocked($c);

                return 'EXPIRED';
            }
            $this->requireState($c['now'] < $c['slot']->starts_at && $c['schedule']->status === 'OPEN'
                && $c['slot']->status === 'OPEN' && $c['department']->status === 'ACTIVE' && $c['doctor']->status === 'ACTIVE'
                && $c['doctorAccount']->status === 'ACTIVE' && $c['doctorAccount']->role === 'DOCTOR', '出诊资源已停诊、关闭或开始，不能付款。');
            $event = $this->gateway->payment($o, $scenario);
            $this->checkEvent($event, $o);
            $this->requireState(in_array($event['result'], ['PAID', 'DECLINED', 'UNKNOWN'], true), '模拟支付结果无法核实。');
            if ($event['result'] !== 'PAID') {
                $this->audit($c, 'PAYMENT_'.$event['result'], 'payment_orders', $o->id, ['status' => 'UNPAID'], ['status' => 'UNPAID', 'result' => $event['result']]);

                return $event['result'];
            }
            DB::table('payment_orders')->where('id', $o->id)->update(['status' => 'PAID', 'paid_at' => $c['now'], 'updated_at' => $c['now']]);
            DB::table('appointments')->where('id', $a->id)->update(['status' => 'CONFIRMED', 'confirmed_at' => $c['now'], 'updated_at' => $c['now']]);
            $this->audit($c, 'PAYMENT_CONFIRMED', 'appointments', $a->id, ['status' => $a->status], ['status' => 'CONFIRMED', 'payment_order_id' => $o->id, 'amount' => $o->amount]);

            return 'CONFIRMED';
        });
    }

    public function cancel(int $actorId, int $id, string $reasonCode = 'PATIENT_REQUEST', ?string $reason = null): string
    {
        return $this->transition($id, $actorId, function (array $c) use ($actorId, $reasonCode, $reason): string {
            $this->authorize($c, $actorId, ['PATIENT', 'ADMIN']);
            $a = $c['appointment'];
            $o = $c['order'];
            if (in_array($a->status, ['CANCELLED', 'EXPIRED'], true)) {
                return $a->status;
            }
            $this->requireState(in_array($a->status, ['PENDING_PAYMENT', 'CONFIRMED'], true), '已完成预约不能取消。');
            if ($a->status === 'PENDING_PAYMENT' && $c['now'] >= $a->expires_at) {
                $this->expireLocked($c);

                return 'EXPIRED';
            }
            if ($c['actor']->role === 'PATIENT') {
                $this->requireState($reasonCode === 'PATIENT_REQUEST' && $c['now'] < $c['slot']->starts_at, '患者只能在就诊开始前取消本人预约。');
                $reason = null;
            } else {
                $this->requireState(in_array($reasonCode, ['CLINIC_SUSPENDED', 'ADMIN_CORRECTION'], true) && is_string($reason) && trim($reason) !== '', '管理员异常取消必须提供原因。');
                if ($reasonCode === 'CLINIC_SUSPENDED') {
                    $this->requireState($c['schedule']->status === 'SUSPENDED', '该排班尚未停诊。');
                }
            }
            $this->checkAmount($a, $o);
            $this->requireState(($a->status === 'PENDING_PAYMENT' && $o->status === 'UNPAID')
                || ($a->status === 'CONFIRMED' && $o->status === 'PAID'), '预约和订单状态不一致，请核实。');
            DB::table('appointments')->where('id', $a->id)->update([
                'status' => 'CANCELLED', 'cancelled_at' => $c['now'], 'cancelled_by' => $actorId,
                'cancel_reason_code' => $reasonCode, 'cancel_reason' => $reason,
                'released_at' => $c['now'], 'updated_at' => $c['now'],
            ]);
            $this->release($c);
            if ($o->status === 'PAID') {
                DB::table('refunds')->insert([
                    'payment_order_id' => $o->id, 'refund_no' => 'R'.Str::ulid(), 'amount' => $o->amount,
                    'status' => 'PENDING', 'reason_code' => $reasonCode, 'requested_by' => $actorId,
                    'created_at' => $c['now'], 'updated_at' => $c['now'],
                ]);
                DB::table('payment_orders')->where('id', $o->id)->update(['status' => 'REFUND_PENDING', 'updated_at' => $c['now']]);
            } else {
                DB::table('payment_orders')->where('id', $o->id)->update(['status' => $this->gateway->close($o), 'closed_at' => $c['now'], 'updated_at' => $c['now']]);
            }
            $this->audit($c, 'APPOINTMENT_CANCELLED', 'appointments', $a->id,
                ['status' => $a->status, 'occupied_count' => $c['slot']->occupied_count],
                ['status' => 'CANCELLED', 'reason_code' => $reasonCode, 'occupied_count' => $c['slot']->occupied_count - 1, 'payment_order_id' => $o->id]);

            return 'CANCELLED';
        });
    }

    public function expire(int $id): bool
    {
        return $this->transition($id, null, fn (array $c): bool => $this->expireLocked($c));
    }

    /** Scan outside any business locks; each candidate uses the same short patient-first transaction. */
    public function reclaim(?int $patientId = null, ?int $slotId = null, int $limit = 100): int
    {
        $ids = DB::table('appointments')->where('status', 'PENDING_PAYMENT')
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
        return $this->transition($id, $adminId, function (array $c) use ($adminId): string {
            $this->authorize($c, $adminId, ['ADMIN']);
            $a = $c['appointment'];
            if ($a->status === 'COMPLETED') {
                return 'COMPLETED';
            }
            $this->checkAmount($a, $c['order']);
            $this->requireState($a->status === 'CONFIRMED' && $c['order']->status === 'PAID'
                && $c['order']->paid_at !== null && $c['now'] >= $c['slot']->starts_at, '只有已开始、已付款并由管理员核实的预约可登记完成。');
            DB::table('appointments')->where('id', $a->id)->update(['status' => 'COMPLETED', 'completed_at' => $c['now'], 'completed_by' => $adminId, 'updated_at' => $c['now']]);
            $this->audit($c, 'APPOINTMENT_COMPLETED', 'appointments', $a->id, ['status' => 'CONFIRMED'], ['status' => 'COMPLETED', 'completed_by' => $adminId]);

            return 'COMPLETED';
        });
    }

    public function refund(int $adminId, int $id, string $scenario = 'success'): string
    {
        $this->ensureMockEnabled();

        return $this->transition($id, $adminId, function (array $c) use ($adminId, $scenario): string {
            $this->authorize($c, $adminId, ['ADMIN']);
            $r = $c['refund'];
            $o = $c['order'];
            $this->requireState($r !== null, '没有待处理的模拟退款。');
            $this->checkAmount($c['appointment'], $o);
            $this->requireState($r->amount === $o->amount && $o->paid_at !== null, '退款金额或付款事实不一致。');
            if ($r->status === 'SUCCEEDED') {
                return 'SUCCEEDED';
            }
            $this->requireState($c['appointment']->status === 'CANCELLED' && $o->status === 'REFUND_PENDING', '当前记录不能退款。');
            $event = $this->gateway->refund($o, $r, $scenario);
            $this->checkEvent($event, $o);
            $this->requireState($event['refund_no'] === $r->refund_no && $event['refund_amount'] === $r->amount
                && in_array($event['result'], ['SUCCEEDED', 'FAILED', 'PENDING'], true), '模拟退款结果无法核实。');
            $state = $event['result'];
            DB::table('refunds')->where('id', $r->id)->update([
                'status' => $state, 'succeeded_at' => $state === 'SUCCEEDED' ? $c['now'] : null,
                'last_error' => match ($state) {
                    'FAILED' => '模拟退款被拒绝，可重试原退款。', 'PENDING' => '模拟退款结果未知，待核实；未确认退款成功。', default => null
                },
                'updated_at' => $c['now'],
            ]);
            if ($state === 'SUCCEEDED') {
                DB::table('payment_orders')->where('id', $o->id)->update(['status' => 'REFUNDED', 'updated_at' => $c['now']]);
            }
            $this->audit($c, 'REFUND_'.$state, 'refunds', $r->id, ['status' => $r->status], ['status' => $state, 'payment_order_id' => $o->id, 'amount' => $r->amount]);

            return $state;
        });
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

    private function locked(int $id, ?int $actorId): array
    {
        $candidate = DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->join('schedules', 'schedules.id', '=', 'slots.schedule_id')->where('appointments.id', $id)
            ->first(['appointments.patient_id', 'appointments.slot_id', 'slots.schedule_id', 'schedules.department_id', 'schedules.doctor_id']);
        abort_unless($candidate, 404);
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
        $this->requireState($appointment->patient_id === $patient->id && $appointment->slot_id === $slot->id
            && $slot->schedule_id === $schedule->id && $schedule->doctor_id === $doctor->id && $schedule->department_id === $department->id, '历史关联已变化，请核实。');
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');

        return compact('patient', 'actor', 'department', 'doctor', 'doctorAccount', 'schedule', 'slot', 'appointment', 'order', 'refund', 'now');
    }

    private function authorize(array $c, int $actorId, array $roles): void
    {
        abort_unless($c['actor']?->status === 'ACTIVE' && in_array($c['actor']->role, $roles, true), 403);
        if ($c['actor']->role === 'PATIENT') {
            abort_unless($c['patient']->user_id === $actorId, 404);
        }
    }

    private function expireLocked(array $c): bool
    {
        $a = $c['appointment'];
        $o = $c['order'];
        if ($a->status !== 'PENDING_PAYMENT' || $c['now'] < $a->expires_at) {
            return false;
        }
        $this->checkAmount($a, $o);
        $this->requireState($o->status === 'UNPAID', '到期记录存在不一致付款状态，请核实。');
        DB::table('appointments')->where('id', $a->id)->update(['status' => 'EXPIRED', 'released_at' => $c['now'], 'updated_at' => $c['now']]);
        DB::table('payment_orders')->where('id', $o->id)->update(['status' => $this->gateway->close($o), 'closed_at' => $c['now'], 'updated_at' => $c['now']]);
        $this->release($c);
        $this->audit($c, 'APPOINTMENT_EXPIRED', 'appointments', $a->id,
            ['status' => $a->status, 'occupied_count' => $c['slot']->occupied_count],
            ['status' => 'EXPIRED', 'occupied_count' => $c['slot']->occupied_count - 1]);

        return true;
    }

    private function release(array $c): void
    {
        $updated = DB::table('slots')->where('id', $c['slot']->id)->where('occupied_count', '>', 0)
            ->decrement('occupied_count', 1, ['updated_at' => $c['now']]);
        $this->requireState($updated === 1, '名额占用异常，已回滚，请核实。');
    }

    private function checkAmount(object $appointment, object $order): void
    {
        $this->requireState($appointment->fee_amount === $order->amount && $order->currency === 'CNY' && $order->channel === 'MOCK_WECHAT', '订单金额、币种或渠道不一致。');
    }

    private function checkEvent(array $event, object $order): void
    {
        $this->requireState($event['order_no'] === $order->order_no && $event['amount'] === $order->amount
            && $event['currency'] === 'CNY' && $event['channel'] === 'MOCK_WECHAT', '模拟事件与订单不匹配。');
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

    private function audit(array $c, string $action, string $target, int $id, array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => $c['actor'] ? 'USER' : 'SYSTEM', 'operator_id' => $c['actor']?->id,
            'action' => $action, 'target_type' => $target, 'target_id' => $id,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => $c['now'],
        ]);
    }
}
