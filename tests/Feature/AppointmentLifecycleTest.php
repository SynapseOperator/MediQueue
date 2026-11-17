<?php

namespace Tests\Feature;

use App\Payments\PaymentGateway;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
{
    private int $patient;

    private int $admin;

    private int $slot;

    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        config(['mediqueue.mock_payments_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00.123Z'));
        $this->seed(DatabaseSeeder::class);
        $this->patient = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $this->admin = DB::table('users')->where('username', 'demo_admin')->value('id');
        $this->slot = DB::table('slots')->orderBy('id')->value('id');
        $this->appointment = app(AppointmentService::class)->create($this->patient, $this->slot, 'lifecycle-fixture');
    }

    public function test_paid_cancellation_creates_one_refund_and_retry_preserves_identity(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $this->assertSame('CONFIRMED', $service->pay($this->patient, $this->appointment));
        $this->assertSame('CONFIRMED', $service->pay($this->patient, $this->appointment));
        $this->assertSame('CANCELLED', $service->cancel($this->patient, $this->appointment));
        $this->assertSame('CANCELLED', $service->pay($this->patient, $this->appointment));
        $this->assertSame('CANCELLED', $service->cancel($this->patient, $this->appointment));
        $order = DB::table('payment_orders')->where('appointment_id', $this->appointment)->sole();
        $this->assertSame('REFUND_PENDING', $order->status);
        $refund = DB::table('refunds')->where('payment_order_id', $order->id)->sole();
        $this->assertSame('PENDING', $refund->status);
        $this->assertSame($order->amount, $refund->amount);
        $this->assertNull($order->external_txn_id);
        $this->assertNull($order->external_paid_at);
        $this->assertSame(0, DB::table('slots')->where('id', $this->slot)->value('occupied_count'));
        $this->assertSame('FAILED', $service->refund($this->admin, $this->appointment, 'failed'));
        $this->assertSame('PENDING', $service->refund($this->admin, $this->appointment, 'unknown'));
        $this->assertSame('SUCCEEDED', $service->refund($this->admin, $this->appointment, 'success'));
        $this->assertSame('SUCCEEDED', $service->refund($this->admin, $this->appointment, 'success'));
        $this->assertSame($refund->id, DB::table('refunds')->where('payment_order_id', $order->id)->sole()->id);
        $this->assertNull(DB::table('refunds')->where('id', $refund->id)->value('external_refund_id'));
        $this->assertDatabaseHas('payment_orders', ['id' => $order->id, 'status' => 'REFUNDED']);
    }

    public function test_expired_pending_is_reclaimed_once_even_when_account_disabled(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $expires = DB::table('appointments')->where('id', $this->appointment)->value('expires_at');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($expires, 'UTC'));
        DB::table('users')->where('id', $this->patient)->update(['status' => 'DISABLED']);
        $this->assertTrue($service->expire($this->appointment));
        $this->assertFalse($service->expire($this->appointment));
        $this->assertDatabaseHas('appointments', ['id' => $this->appointment, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $this->appointment, 'status' => 'CLOSED']);
        $this->assertSame(0, DB::table('slots')->where('id', $this->slot)->value('occupied_count'));
    }

    public function test_expiry_precedes_pay_and_cancel(): void
    {
        $expires = DB::table('appointments')->where('id', $this->appointment)->value('expires_at');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($expires, 'UTC'));
        $service = app(AppointmentLifecycleService::class);
        $this->assertSame('EXPIRED', $service->pay($this->patient, $this->appointment));
        $this->assertSame('EXPIRED', $service->cancel($this->patient, $this->appointment));
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $this->appointment, 'status' => 'CLOSED', 'paid_at' => null]);
    }

    public function test_completion_is_manual_idempotent_and_retains_capacity(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $service->pay($this->patient, $this->appointment);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('slots')->where('id', $this->slot)->value('starts_at'), 'UTC'));
        $this->assertSame('COMPLETED', $service->complete($this->admin, $this->appointment));
        $this->assertSame('COMPLETED', $service->complete($this->admin, $this->appointment));
        $this->assertSame(1, DB::table('slots')->where('id', $this->slot)->value('occupied_count'));
    }

    public function test_mock_decline_and_unknown_leave_order_unpaid(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $this->assertSame('DECLINED', $service->pay($this->patient, $this->appointment, 'declined'));
        $this->assertSame('UNKNOWN', $service->pay($this->patient, $this->appointment, 'unknown'));
        $this->assertDatabaseHas('appointments', ['id' => $this->appointment, 'status' => 'PENDING_PAYMENT']);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $this->appointment, 'status' => 'UNPAID', 'paid_at' => null]);
    }

    public function test_audit_insert_failure_rolls_back_every_lifecycle_transition(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $inject = false;
        DB::listen(function (QueryExecuted $event) use (&$inject): void {
            if ($inject && str_starts_with(strtolower($event->sql), 'insert into `audit_logs`')) {
                $inject = false;
                throw new RuntimeException('Injected audit failure');
            }
        });
        foreach (['pay', 'cancel', 'expire', 'complete', 'refund'] as $operation) {
            if ($operation === 'expire') {
                CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('appointments')->where('id', $this->appointment)->value('expires_at'), 'UTC'));
            }
            if ($operation === 'complete') {
                CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00.123Z'));
                $service->pay($this->patient, $this->appointment);
                CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('slots')->where('id', $this->slot)->value('starts_at'), 'UTC'));
            }
            if ($operation === 'refund') {
                CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00.123Z'));
                $service->cancel($this->patient, $this->appointment);
            }
            $before = [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->first(), DB::table('slots')->find($this->slot), DB::table('refunds')->get()->toArray(), DB::table('audit_logs')->orderBy('id')->get()->toArray()];
            $inject = true;
            try {
                match ($operation) {
                    'pay' => $service->pay($this->patient, $this->appointment),
                    'cancel' => $service->cancel($this->patient, $this->appointment),
                    'expire' => $service->expire($this->appointment),
                    'complete' => $service->complete($this->admin, $this->appointment),
                    'refund' => $service->refund($this->admin, $this->appointment),
                };
                $this->fail('Expected injected failure for '.$operation);
            } catch (RuntimeException $e) {
                $this->assertSame('Injected audit failure', $e->getMessage());
            }
            $this->assertFalse($inject);
            $this->assertEquals($before, [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->first(), DB::table('slots')->find($this->slot), DB::table('refunds')->get()->toArray(), DB::table('audit_logs')->orderBy('id')->get()->toArray()]);
        }
    }

    public function test_deadline_and_repeat_payment_preserve_first_confirmation(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $expires = CarbonImmutable::parse(DB::table('appointments')->where('id', $this->appointment)->value('expires_at'), 'UTC');
        CarbonImmutable::setTestNow($expires->subMilliseconds(1));
        $this->assertSame('CONFIRMED', $service->pay($this->patient, $this->appointment));
        $before = [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->sole()];
        CarbonImmutable::setTestNow($expires->addSecond());
        $this->assertSame('CONFIRMED', $service->pay($this->patient, $this->appointment));
        $this->assertEquals($before, [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->sole()]);
    }

    public function test_cancellation_and_completion_boundaries(): void
    {
        $service = app(AppointmentLifecycleService::class);
        foreach ([[$this->admin, 'unpaid'], [$this->patient, 'unauthorized']] as [$actor, $case]) {
            try {
                $service->complete($actor, $this->appointment);
                $this->fail($case);
            } catch (ValidationException|HttpException $e) {
                if ($case === 'unpaid') {
                    $this->assertInstanceOf(ValidationException::class, $e);
                    $this->assertArrayHasKey('appointment', $e->errors());
                } else {
                    $this->assertInstanceOf(HttpException::class, $e);
                    $this->assertSame(403, $e->getStatusCode());
                }
            }
        }
        $service->pay($this->patient, $this->appointment);
        try {
            $service->complete($this->admin, $this->appointment);
            $this->fail('before start');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
        $start = CarbonImmutable::parse(DB::table('slots')->where('id', $this->slot)->value('starts_at'), 'UTC');
        CarbonImmutable::setTestNow($start);
        try {
            $service->cancel($this->patient, $this->appointment);
            $this->fail('patient at start');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
        try {
            $service->cancel($this->admin, $this->appointment, 'ADMIN_CORRECTION', '');
            $this->fail('missing reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
        $this->assertSame('COMPLETED', $service->complete($this->admin, $this->appointment));
        $completed = DB::table('appointments')->where('id', $this->appointment)->value('completed_at');
        CarbonImmutable::setTestNow($start->addMinute());
        $this->assertSame('COMPLETED', $service->complete($this->admin, $this->appointment));
        $this->assertSame($completed, DB::table('appointments')->where('id', $this->appointment)->value('completed_at'));
        try {
            $service->cancel($this->admin, $this->appointment, 'ADMIN_CORRECTION', '演示纠错');
            $this->fail('completed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
    }

    public function test_admin_may_cancel_confirmed_after_start_and_refund_insert_failure_rolls_back(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $service->pay($this->patient, $this->appointment);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('slots')->where('id', $this->slot)->value('starts_at'), 'UTC'));
        $before = [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->sole(), DB::table('slots')->find($this->slot)];
        $inject = true;
        DB::listen(function (QueryExecuted $event) use (&$inject): void {
            if ($inject && str_starts_with(strtolower($event->sql), 'insert into `refunds`')) {
                $inject = false;
                throw new RuntimeException('refund insertion failure');
            }
        });
        try {
            $service->cancel($this->admin, $this->appointment, 'ADMIN_CORRECTION', '演示纠错');
            $this->fail('injected');
        } catch (RuntimeException $e) {
            $this->assertSame('refund insertion failure', $e->getMessage());
        }
        $this->assertFalse($inject);
        $this->assertEquals($before, [DB::table('appointments')->find($this->appointment), DB::table('payment_orders')->where('appointment_id', $this->appointment)->sole(), DB::table('slots')->find($this->slot)]);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame('CANCELLED', $service->cancel($this->admin, $this->appointment, 'ADMIN_CORRECTION', '演示纠错'));
    }

    public function test_mock_pay_and_refund_are_disabled_by_configuration_or_production(): void
    {
        $service = app(AppointmentLifecycleService::class);
        $service->pay($this->patient, $this->appointment);
        $service->cancel($this->patient, $this->appointment);
        foreach (['disabled', 'production'] as $mode) {
            config(['mediqueue.mock_payments_enabled' => $mode !== 'disabled']);
            if ($mode === 'production') {
                app()->detectEnvironment(fn () => 'production');
            }
            foreach ([fn () => $service->pay($this->patient, $this->appointment), fn () => $service->refund($this->admin, $this->appointment)] as $call) {
                try {
                    $call();
                    $this->fail($mode);
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                }
            }
        }
    }

    public function test_payment_rejects_mismatched_order_and_gateway_amount(): void
    {
        $service = app(AppointmentLifecycleService::class);
        DB::table('payment_orders')->where('appointment_id', $this->appointment)->update(['amount' => '99.99']);
        try {
            $service->pay($this->patient, $this->appointment);
            $this->fail('order mismatch');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
        DB::table('payment_orders')->where('appointment_id', $this->appointment)->update(['amount' => DB::table('appointments')->where('id', $this->appointment)->value('fee_amount')]);
        $gateway = \Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('payment')->once()->andReturn(['order_no' => 'wrong', 'amount' => '0.01', 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'result' => 'PAID']);
        $this->app->instance(PaymentGateway::class, $gateway);
        try {
            app(AppointmentLifecycleService::class)->pay($this->patient, $this->appointment);
            $this->fail('event mismatch');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment', $e->errors());
        }
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $this->appointment, 'status' => 'UNPAID']);
    }

    public function test_first_payment_after_deadline_expires_without_payment(): void
    {
        $expires = CarbonImmutable::parse(DB::table('appointments')->where('id', $this->appointment)->value('expires_at'), 'UTC');
        CarbonImmutable::setTestNow($expires->addMillisecond());
        $this->assertSame('EXPIRED', app(AppointmentLifecycleService::class)->pay($this->patient, $this->appointment));
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $this->appointment, 'status' => 'CLOSED', 'paid_at' => null]);
    }
}
