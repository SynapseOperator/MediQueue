<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    private const TABLES = ['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds', 'audit_logs'];

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->seed();

        foreach (array_slice(self::TABLES, 0, 6) as $table) {
            $this->ids[$table] = DB::table($table)->min('id');
        }

        // A synthetic cancelled, paid appointment with a pending full refund.
        // Direct fixture inserts exercise schema constraints, not payment services.
        $time = '2026-11-16 01:00:00.123';
        $timestamps = ['created_at' => $time, 'updated_at' => $time];
        $this->ids['appointments'] = DB::table('appointments')->insertGetId([
            'appointment_no' => 'TEST-APPOINTMENT-1',
            'patient_id' => $this->ids['patients'], 'slot_id' => $this->ids['slots'],
            'request_key' => 'Request-A', 'status' => 'CANCELLED', 'fee_amount' => '12.34',
            'expires_at' => '2026-11-16 01:15:00.123', 'confirmed_at' => $time,
            'cancelled_at' => $time, 'cancelled_by' => $this->ids['users'],
            'cancel_reason_code' => 'ADMIN_CORRECTION', 'cancel_reason' => '合成数据库测试',
            'released_at' => $time, ...$timestamps,
        ]);
        $this->ids['payment_orders'] = DB::table('payment_orders')->insertGetId([
            'appointment_id' => $this->ids['appointments'], 'order_no' => 'TEST-ORDER-1',
            'amount' => '12.34', 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT',
            'status' => 'REFUND_PENDING', 'paid_at' => $time, ...$timestamps,
        ]);
        $this->ids['refunds'] = DB::table('refunds')->insertGetId([
            'payment_order_id' => $this->ids['payment_orders'], 'refund_no' => 'TEST-REFUND-1',
            'amount' => '12.34', 'status' => 'PENDING', 'reason_code' => 'ADMIN_CORRECTION',
            'requested_by' => $this->ids['users'], ...$timestamps,
        ]);
        $this->ids['audit_logs'] = DB::table('audit_logs')->insertGetId([
            'actor_type' => 'USER', 'operator_id' => $this->ids['users'],
            'action' => 'SCHEMA_TEST_FIXTURE', 'target_type' => 'appointments',
            'target_id' => $this->ids['appointments'],
            'after_state' => json_encode(['status' => 'CANCELLED']), 'created_at' => $time,
        ]);
    }

    public function test_core_tables_have_unsigned_keys_nonnull_millisecond_dates_and_innodb(): void
    {
        $tables = DB::table('information_schema.TABLES')->where('TABLE_TYPE', 'BASE TABLE')
            ->where('TABLE_SCHEMA', 'mediqueue_test')->pluck('ENGINE', 'TABLE_NAME')->all();
        $this->assertEqualsCanonicalizing([...self::TABLES, 'sessions', 'migrations'], array_keys($tables));

        foreach (self::TABLES as $table) {
            $this->assertSame('InnoDB', $tables[$table]);
            $columns = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', 'mediqueue_test')->where('TABLE_NAME', $table)
                ->get()->keyBy('COLUMN_NAME');
            $this->assertSame('bigint unsigned', $columns['id']->COLUMN_TYPE);
            $this->assertSame('PRI', $columns['id']->COLUMN_KEY);
            foreach ($columns as $column) {
                if ($column->DATA_TYPE === 'datetime') {
                    $this->assertSame(3, (int) $column->DATETIME_PRECISION);
                }
            }
            foreach ($table === 'audit_logs' ? ['created_at'] : ['created_at', 'updated_at'] as $name) {
                $this->assertSame('datetime', $columns[$name]->DATA_TYPE);
                $this->assertSame('NO', $columns[$name]->IS_NULLABLE);
            }
        }
    }

    #[DataProvider('foreignKeys')]
    public function test_each_core_foreign_key_rejects_orphans(string $table, string $column, string $parent): void
    {
        if ($column === 'completed_by') {
            DB::table('appointments')->where('id', $this->ids['appointments'])->update([
                'status' => 'COMPLETED', 'completed_at' => '2026-11-16 02:00:00.123',
                'completed_by' => $this->ids['users'], 'released_at' => null,
                'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason_code' => null, 'cancel_reason' => null,
            ]);
        }
        $this->rejects(1452, fn () => DB::table($table)->where('id', $this->ids[$table])->update([$column => 999999999]));

        $rule = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join): void {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.TABLE_SCHEMA', 'mediqueue_test')->where('k.TABLE_NAME', $table)
            ->where('k.COLUMN_NAME', $column)->first();
        $this->assertSame($parent, $rule->REFERENCED_TABLE_NAME);
        $this->assertSame('RESTRICT', $rule->UPDATE_RULE);
        $this->assertSame('RESTRICT', $rule->DELETE_RULE);
    }

    public static function foreignKeys(): array
    {
        return [
            ['patients', 'user_id', 'users'], ['doctors', 'user_id', 'users'],
            ['doctors', 'department_id', 'departments'], ['schedules', 'doctor_id', 'doctors'],
            ['schedules', 'department_id', 'departments'], ['schedules', 'created_by', 'users'],
            ['slots', 'schedule_id', 'schedules'], ['appointments', 'patient_id', 'patients'],
            ['appointments', 'slot_id', 'slots'], ['appointments', 'cancelled_by', 'users'],
            ['appointments', 'completed_by', 'users'], ['payment_orders', 'appointment_id', 'appointments'],
            ['refunds', 'payment_order_id', 'payment_orders'], ['refunds', 'requested_by', 'users'],
            ['audit_logs', 'operator_id', 'users'],
        ];
    }

    #[DataProvider('historyParents')]
    public function test_referenced_history_cannot_be_deleted_or_have_its_primary_key_changed(string $table): void
    {
        $this->rejects(1451, fn () => DB::table($table)->where('id', $this->ids[$table])->delete());
        $this->rejects(1451, fn () => DB::table($table)->where('id', $this->ids[$table])->update(['id' => 999999999]));
        $this->assertDatabaseHas($table, ['id' => $this->ids[$table]]);
    }

    public static function historyParents(): array
    {
        return array_map(fn (string $table) => [$table], ['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders']);
    }

    #[DataProvider('invalidRows')]
    public function test_check_constraints_reject_invalid_local_state(string $table, array $changes): void
    {
        $this->rejects(3819, fn () => DB::table($table)->where('id', $this->ids[$table])->update($changes));
    }

    public static function invalidRows(): array
    {
        return [
            'unknown role' => ['users', ['role' => 'OWNER']],
            'case sensitive role' => ['users', ['role' => 'admin']],
            'no padded role' => ['users', ['role' => 'ADMIN ']],
            'unknown account status' => ['users', ['status' => 'DELETED']],
            'department status' => ['departments', ['status' => 'UNKNOWN']],
            'doctor status' => ['doctors', ['status' => 'UNKNOWN']],
            'schedule status' => ['schedules', ['status' => 'UNKNOWN']],
            'invalid session' => ['schedules', ['session_code' => 'INVALID']],
            'schedule reversed time' => ['schedules', ['ends_at' => '2000-01-01 00:00:00.000']],
            'suspension without reason' => ['schedules', ['status' => 'SUSPENDED', 'suspend_reason' => null]],
            'blank suspension reason' => ['schedules', ['status' => 'SUSPENDED', 'suspend_reason' => '   ']],
            'slot status' => ['slots', ['status' => 'UNKNOWN']],
            'slot reversed time' => ['slots', ['ends_at' => '2000-01-01 00:00:00.000']],
            'zero capacity' => ['slots', ['capacity' => 0]],
            'negative capacity' => ['slots', ['capacity' => -1]],
            'negative occupied count' => ['slots', ['occupied_count' => -1]],
            'over capacity' => ['slots', ['capacity' => 2, 'occupied_count' => 3]],
            'zero slot fee' => ['slots', ['fee_amount' => '0.00']],
            'negative slot fee' => ['slots', ['fee_amount' => '-0.01']],
            'unknown appointment state' => ['appointments', ['status' => 'PAID']],
            'zero appointment fee' => ['appointments', ['fee_amount' => '0.00']],
            'late expiry' => ['appointments', ['expires_at' => '2026-11-16 01:15:00.124']],
            'expiry at creation' => ['appointments', ['expires_at' => '2026-11-16 01:00:00.123']],
            'cancelled must release' => ['appointments', ['released_at' => null]],
            'cancelled must record time' => ['appointments', ['cancelled_at' => null]],
            'cancelled must record actor' => ['appointments', ['cancelled_by' => null]],
            'cancelled must record reason code' => ['appointments', ['cancel_reason_code' => null]],
            'unknown cancel reason' => ['appointments', ['cancel_reason_code' => 'INVALID']],
            'confirmed must not release' => ['appointments', ['status' => 'CONFIRMED']],
            'zero payment' => ['payment_orders', ['amount' => '0.00']],
            'payment state' => ['payment_orders', ['status' => 'SUCCESS']],
            'payment case' => ['payment_orders', ['status' => 'refund_pending']],
            'payment currency' => ['payment_orders', ['currency' => 'USD']],
            'real payment channel' => ['payment_orders', ['channel' => 'WECHAT']],
            'fake external transaction' => ['payment_orders', ['external_txn_id' => 'fake']],
            'fake external paid time' => ['payment_orders', ['external_paid_at' => '2026-11-16 01:00:00.123']],
            'paid fact required' => ['payment_orders', ['paid_at' => null]],
            'zero refund' => ['refunds', ['amount' => '0.00']],
            'refund status' => ['refunds', ['status' => 'UNKNOWN']],
            'fake external refund' => ['refunds', ['external_refund_id' => 'fake']],
            'succeeded refund requires time' => ['refunds', ['status' => 'SUCCEEDED']],
            'pending refund no success time' => ['refunds', ['succeeded_at' => '2026-11-16 01:00:00.123']],
            'audit actor' => ['audit_logs', ['actor_type' => 'UNKNOWN']],
            'user audit needs operator' => ['audit_logs', ['operator_id' => null]],
            'audit target' => ['audit_logs', ['target_type' => 'unknown']],
        ];
    }

    public function test_unique_business_and_one_to_one_keys_are_enforced(): void
    {
        foreach (['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds'] as $table) {
            $copy = (array) DB::table($table)->where('id', $this->ids[$table])->first();
            unset($copy['id']);
            $this->rejects(1062, fn () => DB::table($table)->insert($copy));
        }
        $this->rejects(1062, fn () => DB::table('users')->where('username', 'demo_patient_1')->update(['username' => 'DEMO_ADMIN']));

        foreach (['payment_orders' => 'order_no', 'refunds' => 'refund_no'] as $table => $number) {
            $copy = (array) DB::table($table)->where('id', $this->ids[$table])->first();
            unset($copy['id']);
            $copy[$number] = 'ANOTHER-BUSINESS-NUMBER';
            $this->rejects(1062, fn () => DB::table($table)->insert($copy));
        }
    }

    public function test_business_unique_keys_and_query_indexes_match_the_design(): void
    {
        $expected = [
            'users' => [['username']], 'patients' => [['user_id']],
            'departments' => [['code'], ['name']], 'doctors' => [['user_id']],
            'schedules' => [['doctor_id', 'work_date', 'session_code']],
            'slots' => [['schedule_id', 'starts_at']],
            'appointments' => [['appointment_no'], ['patient_id', 'request_key']],
            'payment_orders' => [['appointment_id'], ['order_no'], ['channel', 'external_txn_id']],
            'refunds' => [['payment_order_id'], ['refund_no']],
        ];

        foreach ($expected as $table => $keys) {
            $indexes = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', 'mediqueue_test')->where('TABLE_NAME', $table)
                ->where('NON_UNIQUE', 0)->where('INDEX_NAME', '!=', 'PRIMARY')
                ->orderBy('SEQ_IN_INDEX')->get()->groupBy('INDEX_NAME')
                ->map(fn ($columns) => $columns->pluck('COLUMN_NAME')->all())->values()->all();
            $this->assertEqualsCanonicalizing($keys, $indexes, $table);
        }

        $appointmentIndexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', 'mediqueue_test')->where('TABLE_NAME', 'appointments')
            ->orderBy('SEQ_IN_INDEX')->get()->groupBy('INDEX_NAME')
            ->map(fn ($columns) => $columns->pluck('COLUMN_NAME')->all())->values()->all();
        foreach ([['patient_id', 'status', 'slot_id'], ['patient_id', 'created_at', 'id'], ['slot_id', 'status', 'expires_at', 'id'], ['status', 'expires_at', 'id']] as $key) {
            $this->assertContains($key, $appointmentIndexes);
        }
        $this->assertNotContains(['patient_id', 'slot_id'], $appointmentIndexes);
    }

    public function test_request_keys_are_patient_scoped_case_sensitive_and_no_pad(): void
    {
        $copy = (array) DB::table('appointments')->where('id', $this->ids['appointments'])->first();
        unset($copy['id']);
        $copy['appointment_no'] = 'TEST-APPOINTMENT-2';
        $this->rejects(1062, fn () => DB::table('appointments')->insert($copy));

        foreach (['request-a', 'Request-A '] as $index => $key) {
            DB::table('appointments')->insert([...$copy, 'appointment_no' => 'TEST-KEY-'.$index, 'request_key' => $key]);
        }
        $otherPatient = DB::table('patients')->where('id', '!=', $this->ids['patients'])->value('id');
        DB::table('appointments')->insert([...$copy, 'patient_id' => $otherPatient]);
        $this->assertDatabaseCount('appointments', 4);
        // Same patient + same slot can retain multiple cancelled histories with new keys.
        $this->assertSame(3, DB::table('appointments')->where('patient_id', $this->ids['patients'])->where('slot_id', $this->ids['slots'])->count());
    }

    public function test_exact_money_and_milliseconds_survive_round_trip_and_price_changes(): void
    {
        DB::table('slots')->where('id', $this->ids['slots'])->update(['fee_amount' => '99999999.99']);
        $this->assertSame('99999999.99', DB::table('slots')->where('id', $this->ids['slots'])->value('fee_amount'));
        $this->assertSame('12.34', DB::table('appointments')->value('fee_amount'));
        $this->assertSame('12.34', DB::table('payment_orders')->value('amount'));
        $this->assertSame('12.34', DB::table('refunds')->value('amount'));
        $this->assertSame('2026-11-16 01:00:00.123', DB::table('appointments')->value('created_at'));
        $this->rejects(1264, fn () => DB::table('slots')->where('id', $this->ids['slots'])->update(['fee_amount' => '100000000.00']));
    }

    public function test_capacity_accepts_boundaries_and_rejects_reduction_below_occupancy(): void
    {
        DB::table('slots')->where('id', $this->ids['slots'])->update(['capacity' => 3, 'occupied_count' => 3]);
        $this->rejects(3819, fn () => DB::table('slots')->where('id', $this->ids['slots'])->update(['capacity' => 2]));
        DB::table('slots')->where('id', $this->ids['slots'])->update(['occupied_count' => 0]);
        $this->assertDatabaseHas('slots', ['id' => $this->ids['slots'], 'capacity' => 3, 'occupied_count' => 0]);
    }

    public function test_duplicate_phone_is_allowed_and_required_fields_are_not_nullable(): void
    {
        $phone = DB::table('patients')->where('id', $this->ids['patients'])->value('phone');
        DB::table('patients')->update(['phone' => $phone]);
        $this->assertSame(3, DB::table('patients')->where('phone', $phone)->count());
        $this->rejects(1048, fn () => DB::table('patients')->where('id', $this->ids['patients'])->update(['phone' => null]));
        $this->rejects(1048, fn () => DB::table('appointments')->where('id', $this->ids['appointments'])->update(['created_at' => null]));
    }

    public function test_valid_local_status_combinations_are_accepted_and_required_event_fields_are_enforced(): void
    {
        $time = '2026-11-16 01:05:00.123';
        foreach (['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED', 'EXPIRED', 'CANCELLED'] as $status) {
            $fields = [
                'status' => $status,
                'confirmed_at' => in_array($status, ['CONFIRMED', 'COMPLETED']) ? $time : null,
                'completed_at' => $status === 'COMPLETED' ? $time : null,
                'completed_by' => $status === 'COMPLETED' ? $this->ids['users'] : null,
                'cancelled_at' => $status === 'CANCELLED' ? $time : null,
                'cancelled_by' => $status === 'CANCELLED' ? $this->ids['users'] : null,
                'cancel_reason_code' => $status === 'CANCELLED' ? 'PATIENT_REQUEST' : null,
                'cancel_reason' => null,
                'released_at' => in_array($status, ['CANCELLED', 'EXPIRED']) ? $time : null,
            ];
            DB::table('appointments')->where('id', $this->ids['appointments'])->update($fields);
            $this->assertSame($status, DB::table('appointments')->value('status'));
            if ($status === 'CONFIRMED') {
                $this->rejects(3819, fn () => DB::table('appointments')->update(['released_at' => $time]), 'appointments_release_check');
                $this->rejects(3819, fn () => DB::table('appointments')->update(['confirmed_at' => null]), 'appointments_confirmation_check');
            }
            if ($status === 'COMPLETED') {
                foreach (['completed_at', 'completed_by'] as $column) {
                    $this->rejects(3819, fn () => DB::table('appointments')->update([$column => null]), 'appointments_completion_check');
                }
            }
        }
        foreach (['UNPAID', 'PAID', 'CLOSED', 'REFUND_PENDING', 'REFUNDED'] as $status) {
            DB::table('payment_orders')->update([
                'status' => $status,
                'paid_at' => in_array($status, ['UNPAID', 'CLOSED']) ? null : $time,
                'closed_at' => $status === 'CLOSED' ? $time : null,
            ]);
            $this->assertSame($status, DB::table('payment_orders')->value('status'));
            if ($status === 'CLOSED') {
                $this->rejects(3819, fn () => DB::table('payment_orders')->update(['closed_at' => null]), 'payment_orders_closed_check');
            }
        }
        foreach (['PENDING', 'FAILED', 'SUCCEEDED'] as $status) {
            DB::table('refunds')->update(['status' => $status, 'succeeded_at' => $status === 'SUCCEEDED' ? $time : null]);
            $this->assertSame($status, DB::table('refunds')->value('status'));
        }
    }

    public function test_system_audit_can_omit_or_retain_a_valid_related_user(): void
    {
        DB::table('audit_logs')->update(['actor_type' => 'SYSTEM']);
        $this->assertSame($this->ids['users'], DB::table('audit_logs')->value('operator_id'));
        DB::table('audit_logs')->update(['operator_id' => null]);
        $this->assertNull(DB::table('audit_logs')->value('operator_id'));
    }

    private function rejects(int $errorCode, callable $write, ?string $constraint = null): void
    {
        try {
            $write();
            $this->fail('MySQL accepted an invalid write.');
        } catch (QueryException $exception) {
            $this->assertSame($errorCode, $exception->errorInfo[1]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }
}
