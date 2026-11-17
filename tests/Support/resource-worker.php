<?php

use App\Http\Controllers\AdminResourceController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // Every child independently verifies the real connection before any mutation.
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::selectOne('SELECT DATABASE() AS name')->name !== 'mediqueue_test') {
        throw new RuntimeException('Resource workers require the real mediqueue_test MySQL database.');
    }

    $operation = $argv[1];
    $payload = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    $user = User::findOrFail((int) $argv[2]);
    Auth::setUser($user);
    $request = Request::create('/admin/'.($operation === 'schedule' ? 'schedules' : 'slots'), 'POST', $payload);
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    $identity = ['pid' => getmypid(), 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
    $emit = static function (array $event) use ($identity): void {
        fwrite(STDOUT, json_encode($event + $identity, JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);
    };
    $announced = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$announced, $emit): void {
        $query = strtolower($query);
        if (! $announced && str_contains($query, 'from `departments`')
            && (str_contains($query, 'for update') || str_contains($query, 'for share') || str_contains($query, 'lock in share mode'))) {
            $announced = true;
            $emit(['phase' => 'locking']);
        }
    });
    $emit(['phase' => 'ready']);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Worker did not receive the concurrency start signal.');
    }

    try {
        $controller = $app->make(AdminResourceController::class);
        match ($operation) {
            'schedule' => $controller->storeSchedule($request),
            'slot' => $controller->storeSlot($request),
            default => throw new RuntimeException('Unknown resource worker operation.'),
        };
        $emit(['phase' => 'result', 'outcome' => 'created']);
    } catch (ValidationException $exception) {
        $emit(['phase' => 'result', 'outcome' => 'rejected', 'fields' => array_keys($exception->errors())]);
    }
} catch (Throwable $exception) {
    // Avoid printing SQL bindings or environment secrets in CI failure diagnostics.
    fwrite(STDERR, $exception::class.' at '.$exception->getFile().':'.$exception->getLine()."\n");
    exit(1);
}
