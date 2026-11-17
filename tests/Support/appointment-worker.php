<?php

use App\Http\Controllers\AdminResourceController;
use App\Models\User;
use App\Services\AppointmentService;
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
        throw new RuntimeException('Appointment workers require the real mediqueue_test MySQL database.');
    }
    $job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $user = User::findOrFail($job['user_id']);
    Auth::setUser($user);
    $request = Request::create('/concurrency-test', 'POST', $job['input'] ?? []);
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    $identity = ['pid' => getmypid(), 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
        'operation' => $job['operation'], 'user_id' => $job['user_id'], 'slot_id' => $job['slot_id'] ?? null];
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
                    throw new RuntimeException('Expected the acquired-lock release signal.');
                }
            }
        });
    }
    $emit(['phase' => 'ready']);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Expected the synchronized start signal.');
    }

    try {
        if ($job['operation'] === 'appointment') {
            $id = $app->make(AppointmentService::class)->create($job['user_id'], $job['slot_id'], $job['request_key']);
            $emit(['phase' => 'result', 'outcome' => 'created', 'appointment_id' => $id]);
        } elseif ($job['operation'] === 'suspend') {
            $app->make(AdminResourceController::class)->updateSchedule($request, $job['schedule_id']);
            $emit(['phase' => 'result', 'outcome' => 'suspended']);
        } else {
            throw new RuntimeException('Unknown appointment worker operation.');
        }
    } catch (ValidationException $exception) {
        $emit(['phase' => 'result', 'outcome' => 'rejected', 'fields' => array_keys($exception->errors())]);
    }
} catch (Throwable $exception) {
    // Never print SQL bindings or environment credentials into concurrency evidence.
    fwrite(STDERR, $exception::class.' at '.$exception->getFile().':'.$exception->getLine()."\n");
    exit(1);
}
