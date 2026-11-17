<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MysqlFoundationTest extends TestCase
{
    public function test_mysql_connection_uses_required_storage_and_time_semantics(): void
    {
        $settings = DB::selectOne(
            'SELECT VERSION() AS version, @@session.time_zone AS timezone, '
            .'@@session.transaction_isolation AS isolation_level, '
            .'@@character_set_connection AS charset, @@foreign_key_checks AS foreign_keys',
        );

        $this->assertMatchesRegularExpression('/^8\./', $settings->version);
        $this->assertSame('+00:00', $settings->timezone);
        $this->assertSame('READ-COMMITTED', $settings->isolation_level);
        $this->assertSame('utf8mb4', $settings->charset);
        $this->assertSame(1, (int) $settings->foreign_keys);
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Shanghai', config('mediqueue.hospital_timezone'));

        $table = DB::selectOne(
            'SELECT ENGINE AS engine, TABLE_COLLATION AS collation_name '
            .'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['sessions'],
        );

        $this->assertSame('InnoDB', $table->engine);
        $this->assertSame('utf8mb4_0900_ai_ci', $table->collation_name);
    }

    public function test_sessions_migration_can_roll_back_and_run_again(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));

        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('sessions'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasColumns('sessions', [
            'id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity',
        ]));
    }
}
