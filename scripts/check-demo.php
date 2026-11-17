<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Clear cached configuration before the local demo health check.\n");
    exit(2);
}
$expected = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME'] as $key => $variable) {
    if ((string) config('database.connections.mysql.'.$key) !== ($expected[$variable] ?? '')) {
        fwrite(STDERR, "Runtime database settings differ from the local environment file.\n");
        exit(2);
    }
}
if (config('database.default') !== 'mysql' || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
    fwrite(STDERR, "Demo health requires the explicit MySQL TCP connection.\n");
    exit(2);
}

$connection = DB::selectOne('SELECT DATABASE() AS name, VERSION() AS version, @@session.time_zone AS timezone, @@session.transaction_isolation AS isolation_level');
if (! app()->environment(['local', 'demo']) || $connection->name !== 'mediqueue') {
    fwrite(STDERR, "Demo health check requires the local/demo application database.\n");
    exit(2);
}
$differences = DB::table('v_slot_occupancy_check')->where('difference', '<>', 0)->count();
$report = [
    'status' => $differences === 0 ? 'ok' : 'occupancy-mismatch',
    'php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => $connection,
    'appointments' => DB::table('appointments')->count(), 'orders' => DB::table('payment_orders')->count(),
    'refunds' => DB::table('refunds')->count(), 'occupancy_mismatches' => $differences,
    'mock_payments_enabled' => config('mediqueue.mock_payments_enabled'),
    'routine_admin_configured' => config('database.connections.routine_ddl') !== null,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($differences === 0 ? 0 : 1);
