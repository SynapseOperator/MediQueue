<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (getenv('MEDIQUEUE_CONTAINER_DEMO') !== '1' || ! is_file('/.dockerenv')) {
    fwrite(STDERR, "This setup is only for the isolated Compose demo.\n");
    exit(2);
}
$root = dirname(__DIR__, 2);
$credentials = json_decode(file_get_contents('/run/mediqueue-secrets/database.json'), true, flags: JSON_THROW_ON_ERROR);
$pdo = new PDO('mysql:host=mysql;port=3306;charset=utf8mb4', 'root', $credentials['root'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS mediqueue CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
foreach (['app', 'migrator'] as $role) {
    $password = $pdo->quote($credentials[$role]);
    $pdo->exec("CREATE USER IF NOT EXISTS 'mediqueue_{$role}'@'%' IDENTIFIED BY {$password}");
}
$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON mediqueue.* TO 'mediqueue_app'@'%'");
$pdo->exec("GRANT ALL PRIVILEGES ON mediqueue.* TO 'mediqueue_migrator'@'%'");

// Privileged variables exist only in this migration child, never app/scheduler.
$migrationEnvironment = array_merge(getenv(), [
    'APP_ENV' => 'demo', 'DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_DATABASE' => 'mediqueue',
    'DB_USERNAME' => 'mediqueue_migrator', 'DB_PASSWORD' => $credentials['migrator'],
    'DB_URL' => '', 'DB_SOCKET' => '', 'MEDIQUEUE_SCHEMA_RUNNER' => '1',
    'DB_ROUTINE_ADMIN_PASSWORD' => $credentials['root'],
]);
$process = proc_open([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], [STDIN, STDOUT, STDERR], $pipes, $root, $migrationEnvironment);
$migrationStatus = proc_close($process);
if ($migrationStatus !== 0) {
    exit($migrationStatus);
}
$pdo->exec("GRANT EXECUTE ON PROCEDURE mediqueue.sp_department_statistics TO 'mediqueue_app'@'%'");
$pdo->exec("GRANT EXECUTE ON FUNCTION mediqueue.fn_intervals_overlap TO 'mediqueue_app'@'%'");
unset($pdo, $migrationEnvironment, $credentials);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('demo') || DB::selectOne('SELECT DATABASE() AS name')->name !== 'mediqueue') {
    throw new RuntimeException('Unexpected demo database or environment.');
}
if (DB::table('users')->exists()) {
    fwrite(STDOUT, "Demo already initialized; accounts, passwords and all history kept.\n");
    exit(0);
}

$passwords = DB::transaction(function (): array {
    app(DatabaseSeeder::class)->run();
    $passwords = [];
    foreach (DB::table('users')->orderBy('id')->get() as $user) {
        $password = bin2hex(random_bytes(12));
        DB::table('users')->where('id', $user->id)->update(['password_hash' => Hash::make($password)]);
        $passwords[$user->username] = $password;
    }

    return $passwords;
});
fwrite(STDOUT, "Demo created. Payment/refund actions are simulations with no real money.\n");
if (getenv('DEMO_PRINT_CREDENTIALS') !== 'false') {
    fwrite(STDOUT, "Copy these local-only credentials now; setup will not print or reset them again.\n");
    foreach ($passwords as $username => $password) {
        fwrite(STDOUT, $username.'  '.$password.PHP_EOL);
    }
} else {
    fwrite(STDOUT, "Credential output suppressed for CI/shared logs.\n");
}
