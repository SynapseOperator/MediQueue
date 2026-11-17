<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppointmentService
{
    /** The HTTP boundary validates input; all lifecycle writes start with this patient's lock. */
    public function create(int $userId, int $slotId, string $requestKey): int
    {
        try {
            return DB::transaction(function () use ($userId, $slotId, $requestKey): int {
                $patient = DB::table('patients')->where('user_id', $userId)->lockForUpdate()->first();
                abort_unless($patient, 403, '需要有效的患者档案。');
                $account = DB::table('users')->where('id', $userId)->sharedLock()->first();
                abort_unless($account?->role === 'PATIENT' && $account->status === 'ACTIVE', 403);

                // Read-only lookup under the patient mutex: never lock an appointment before its resources.
                if (($existing = $this->existing($userId, $slotId, $requestKey)) !== null) {
                    return $existing;
                }

                $candidate = DB::table('slots')->join('schedules', 'schedules.id', '=', 'slots.schedule_id')
                    ->where('slots.id', $slotId)->first(['slots.schedule_id', 'schedules.doctor_id', 'schedules.department_id']);
                if (! $candidate) {
                    throw ValidationException::withMessages(['slot_id' => '号源不存在。']);
                }
                // P2 management takes exclusive locks in this same parent-to-child order.
                $department = DB::table('departments')->where('id', $candidate->department_id)->sharedLock()->first();
                $doctor = DB::table('doctors')->where('id', $candidate->doctor_id)->sharedLock()->first();
                $doctorAccount = DB::table('users')->where('id', $doctor->user_id)->sharedLock()->first();
                $schedule = DB::table('schedules')->where('id', $candidate->schedule_id)->sharedLock()->first();
                $slot = DB::table('slots')->where('id', $slotId)->lockForUpdate()->first();

                if ($slot->schedule_id !== $schedule->id || $schedule->doctor_id !== $doctor->id
                    || $schedule->department_id !== $department->id || $doctor->department_id !== $department->id) {
                    throw ValidationException::withMessages(['slot_id' => '号源归属已变化，请使用原请求标识重试。']);
                }
                if ($department->status !== 'ACTIVE' || $doctor->status !== 'ACTIVE'
                    || $doctorAccount->role !== 'DOCTOR' || $doctorAccount->status !== 'ACTIVE'
                    || $schedule->status !== 'OPEN' || $slot->status !== 'OPEN') {
                    throw ValidationException::withMessages(['slot_id' => '该号源或上级资源未开放预约。']);
                }

                // Take authoritative time only after the potentially blocking resource locks.
                $clock = CarbonImmutable::now('UTC');
                $now = $clock->format('Y-m-d H:i:s.v');
                $today = $clock->setTimezone(config('mediqueue.hospital_timezone'))->startOfDay();
                if ($slot->starts_at <= $now || $slot->starts_at < $schedule->starts_at || $slot->ends_at > $schedule->ends_at
                    || $schedule->work_date < $today->toDateString()
                    || $schedule->work_date > $today->addDays(config('mediqueue.availability_days') - 1)->toDateString()) {
                    throw ValidationException::withMessages(['slot_id' => '号源已开始或不在可预约日期范围内。']);
                }

                // READ COMMITTED sees the preceding transaction of this same patient.
                // Expired-but-unreclaimed pending rows still consume capacity, but are not valid time conflicts (BR-01).
                $conflict = DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
                    ->where('appointments.patient_id', $patient->id)
                    ->where('slots.starts_at', '<', $slot->ends_at)->where('slots.ends_at', '>', $slot->starts_at)
                    ->where(function ($query) use ($now): void {
                        $query->whereIn('appointments.status', ['CONFIRMED', 'COMPLETED'])
                            ->orWhere(function ($query) use ($now): void {
                                $query->where('appointments.status', 'PENDING_PAYMENT')->where('appointments.expires_at', '>', $now);
                            });
                    })->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['slot_id' => '您已有时间重叠的有效预约。']);
                }
                $updated = DB::table('slots')->where('id', $slotId)->whereColumn('occupied_count', '<', 'capacity')
                    ->increment('occupied_count', 1, ['updated_at' => $now]);
                if ($updated !== 1) {
                    throw ValidationException::withMessages(['slot_id' => '号源已售罄；到期名额须完成回收后才能再次预约。']);
                }

                $expires = min($clock->addMinutes(15)->format('Y-m-d H:i:s.v'), $slot->starts_at);
                $id = DB::table('appointments')->insertGetId([
                    'appointment_no' => 'A'.Str::ulid(), 'patient_id' => $patient->id, 'slot_id' => $slotId,
                    'request_key' => $requestKey, 'status' => 'PENDING_PAYMENT', 'fee_amount' => $slot->fee_amount,
                    'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $orderId = DB::table('payment_orders')->insertGetId([
                    'appointment_id' => $id, 'order_no' => 'O'.Str::ulid(), 'amount' => $slot->fee_amount,
                    'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'status' => 'UNPAID',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('audit_logs')->insert([
                    'actor_type' => 'USER', 'operator_id' => $userId, 'action' => 'APPOINTMENT_CREATED',
                    'target_type' => 'appointments', 'target_id' => $id, 'request_id' => $requestKey,
                    'before_state' => json_encode(['occupied_count' => $slot->occupied_count], JSON_THROW_ON_ERROR),
                    'after_state' => json_encode([
                        'slot_id' => $slotId, 'payment_order_id' => $orderId, 'status' => 'PENDING_PAYMENT',
                        'fee_amount' => $slot->fee_amount, 'expires_at' => $expires, 'occupied_count' => $slot->occupied_count + 1,
                    ], JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);

                return $id;
            }, 3);
        } catch (Throwable $exception) {
            // Laravel rolls back the complete transaction for both deadlocks and lock timeouts, up to three attempts.
            if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                throw ValidationException::withMessages(['request_key' => '预约繁忙，请使用原请求标识重试。']);
            }
            if (! (new LostConnectionDetector)->causedByLostConnection($exception)) {
                throw $exception;
            }

            // A disconnected COMMIT has an unknown outcome. Reconnect and LOOK UP the same identity; never resubmit here.
            DB::purge();
            try {
                $existing = $this->existing($userId, $slotId, $requestKey);
            } catch (Throwable $lookupException) {
                if (! (new LostConnectionDetector)->causedByLostConnection($lookupException)) {
                    throw $lookupException;
                }
                $existing = null;
            }
            if ($existing !== null) {
                return $existing;
            }

            throw ValidationException::withMessages(['request_key' => '提交结果尚未确认，请查看我的预约，或使用原请求标识重试；不要新建请求标识。']);
        }
    }

    private function existing(int $userId, int $slotId, string $requestKey): ?int
    {
        $existing = DB::table('appointments')->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->where('patients.user_id', $userId)->where('appointments.request_key', $requestKey)
            ->first(['appointments.id', 'appointments.slot_id']);
        if ($existing && $existing->slot_id !== $slotId) {
            throw ValidationException::withMessages(['request_key' => '该请求标识已经用于不同号源，请勿改变重试参数。']);
        }

        return $existing?->id;
    }
}
