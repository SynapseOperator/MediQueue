<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeDatabasePermissionsTest extends TestCase
{
    public function test_runtime_account_can_read_sessions_but_cannot_manage_schema(): void
    {
        $username = env('DB_RUNTIME_TEST_USERNAME');
        $password = env('DB_RUNTIME_TEST_PASSWORD');
        $this->assertNotEmpty($username, 'Configure the runtime account in .env.testing.');
        $this->assertNotEmpty($password, 'Configure the runtime account in .env.testing.');

        config(['database.connections.runtime_probe' => array_replace(config('database.connections.mysql'), [
            'database' => 'mediqueue',
            'username' => $username,
            'password' => $password,
        ])]);

        $runtime = DB::connection('runtime_probe');
        $this->assertSame('mediqueue', $runtime->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertIsArray($runtime->select('SELECT id FROM sessions LIMIT 1'));
        $this->assertIsArray($runtime->select('SELECT appointment_id FROM v_appointment_details LIMIT 1'));
        $this->assertIsArray($runtime->select('SELECT slot_id FROM v_slot_occupancy_check LIMIT 1'));
        $this->assertSame(0, (int) $runtime->selectOne(
            "SELECT fn_intervals_overlap('2026-01-01 00:00:00', '2026-01-01 01:00:00', '2026-01-01 01:00:00', '2026-01-01 02:00:00') AS overlaps"
        )->overlaps);
        $this->assertIsArray($runtime->select("CALL sp_department_statistics('2000-01-01', '2000-01-01')"));

        try {
            // The existing table makes this probe non-mutating even if CREATE was accidentally granted.
            $runtime->statement('CREATE TABLE sessions (id BIGINT UNSIGNED PRIMARY KEY)');
            $this->fail('The runtime account unexpectedly created a table.');
        } catch (QueryException $exception) {
            $this->assertSame(1142, $exception->errorInfo[1], 'The runtime account must not have CREATE privileges.');
        }
    }
}
