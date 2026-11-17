<?php

use App\Http\Controllers\AdminResourceController;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::selectOne('SELECT DATABASE() AS name')->name !== 'mediqueue_test') {
        throw new RuntimeException('Lifecycle workers require the real mediqueue_test MySQL database.');
    }
    $job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    // Only this dedicated testing process controls time; production deadlines remain unchanged.
    CarbonImmutable::setTestNow(CarbonImmutable::parse($job['test_now'], 'UTC'));
    config(['mediqueue.mock_payments_enabled' => true]);
    $user = isset($job['user_id']) ? User::findOrFail($job['user_id']) : null;
    if ($user) {
        Auth::setUser($user);
    }
    $request = Request::create('/lifecycle-concurrency-test', 'POST', $job['input'] ?? []);
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    $identity = ['pid' => getmypid(), 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
        'operation' => $job['operation'], 'appointment_id' => $job['appointment_id'], 'test_now' => $job['test_now']];
    $emit = static function (array $event) use ($identity): void {
        fwrite(STDOUT, json_encode($event + $identity, JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);
    };
    $isGateLock = static function (string $query) use ($job): bool {
        $query = strtolower($query);

        return str_contains($query, 'from `'.$job['gate_table'].'`')
            && (str_contains($query, 'for update') || str_contains($query, 'for share') || str_contains($query, 'lock in share mode'));
    };
    $announced = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$announced, $isGateLock, $emit): void {
        if (! $announced && $isGateLock($query)) {
            $announced = true;
            $emit(['phase' => 'locking']);
        }
    });
    if ($job['pause_after_lock'] ?? false) {
        $paused = false;
        DB::listen(function (QueryExecuted $event) use (&$paused, $isGateLock, $emit): void {
            if (! $paused && $isGateLock($event->sql)) {
                $paused = true;
                $emit(['phase' => 'holding']);
                if (trim((string) fgets(STDIN)) !== 'RELEASE') {
                    throw new RuntimeException('Expected acquired-lock release signal.');
                }
            }
        });
    }
    $emit(['phase' => 'ready']);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Expected synchronized start signal.');
    }
    try {
        $service = $app->make(AppointmentLifecycleService::class);
        if ($job['operation'] === 'suspend') {
            $app->make(AdminResourceController::class)->updateSchedule($request, $job['schedule_id']);
            $result = 'SUSPENDED';
        } else {
            $result = match ($job['operation']) {
                'pay' => $service->pay($job['user_id'], $job['appointment_id']),
                'cancel' => $service->cancel($job['user_id'], $job['appointment_id']),
                'expire' => $service->expire($job['appointment_id']),
                'reclaim-timer' => $service->reclaim(),
                'reclaim-on-demand' => $service->reclaim($job['patient_id'], $job['slot_id']),
                'refund' => $service->refund($job['user_id'], $job['appointment_id'], $job['scenario']),
                default => throw new RuntimeException('Unknown lifecycle worker operation.'),
            };
        }
        $emit(['phase' => 'result', 'outcome' => 'returned', 'result' => $result]);
    } catch (ValidationException $exception) {
        $emit(['phase' => 'result', 'outcome' => 'rejected', 'fields' => array_keys($exception->errors())]);
    }
} catch (Throwable $exception) {
    // Keep SQL bindings, credentials and personal data out of evidence and diagnostics.
    fwrite(STDERR, $exception::class.' at '.$exception->getFile().':'.$exception->getLine()."\n");
    exit(1);
}
