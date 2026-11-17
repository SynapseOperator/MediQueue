<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Intentionally limited diagnostics for public CI: no env values, hashes, SQL or error messages.
$root = dirname(__DIR__, 2);
$report = [
    'demo_env_readable' => is_readable($root.'/.env.demo'),
    'runtime_env_readable' => is_readable('/run/mediqueue-runtime/app.env'),
    'storage_writable' => is_writable($root.'/storage'),
    'views_writable' => is_writable($root.'/storage/framework/views'),
    'cache_writable' => is_writable($root.'/bootstrap/cache'),
    'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
];
$curl = curl_init('http://127.0.0.1:8000/health');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$body = curl_exec($curl);
$report['http_status'] = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$report['curl_error_number'] = curl_errno($curl);
$report['health_json_ok'] = json_decode($body ?: '{}', true) === ['status' => 'ok', 'database' => 'ok'];
curl_close($curl);

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $report['application_booted'] = true;
    $report['environment_is_demo'] = app()->environment('demo');
    $report['application_key_present'] = is_string(config('app.key')) && config('app.key') !== '';
    $report['runtime_account_configured'] = config('database.connections.mysql.username') === 'mediqueue_app';
    $report['schema_account_configured'] = config('database.connections.routine_ddl') !== null;
    DB::table('sessions')->limit(1)->value('id');
    $report['session_table_readable'] = true;
} catch (Throwable $exception) {
    $report['exception_class'] = get_class($exception);
}

$applicationLog = $root.'/storage/logs/laravel.log';
$classes = [
    'Illuminate\\Encryption\\MissingAppKeyException',
    'Illuminate\\Database\\QueryException',
    'Illuminate\\View\\ViewException',
    'PDOException', 'RuntimeException', 'ErrorException',
];
if (is_readable($applicationLog)) {
    $log = file_get_contents($applicationLog);
    $report['observed_error_classes'] = array_values(array_filter($classes, fn (string $class): bool => str_contains($log, $class)));
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
