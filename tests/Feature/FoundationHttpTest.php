<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class FoundationHttpTest extends TestCase
{
    public function test_home_page_identifies_the_current_phase_and_mock_payment_boundary(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('MediQueue')
            ->assertSee('P1')
            ->assertSee('模拟微信支付')
            ->assertSee('无实际扣款');
    }

    public function test_application_liveness_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_database_readiness_uses_the_real_migrated_mysql_database(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_database_failure_is_unavailable_without_exposing_connection_details(): void
    {
        // Laravel's test response resolves a session even for this stateless route.
        config(['session.driver' => 'array']);

        DB::shouldReceive('table')
            ->once()
            ->with('sessions')
            ->andThrow(new QueryException(
                'mysql',
                'SELECT id FROM sessions LIMIT 1',
                [],
                new PDOException('Synthetic internal database details'),
            ));

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable']);
    }
}
