<?php

// Only this short-lived CLI runner receives the DBA password. Never print its environment.
$root = dirname(__DIR__);
if (is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Run php artisan config:clear before schema or test commands; a cached environment cannot be used safely.\n");
    exit(2);
}
$mode = $argv[1] ?? '';
$commands = [
    'migrate' => ['artisan', 'migrate', '--env=migration', '--force'],
    'testing' => ['artisan', 'migrate', '--env=testing', '--force'],
    'rollback-testing' => ['artisan', 'migrate:rollback', '--env=testing', '--force'],
    'test' => ['artisan', 'test'],
];
if (! isset($commands[$mode]) && $mode !== 'grant') {
    fwrite(STDERR, "Usage: php scripts/run-schema.php migrate|testing|rollback-testing|test|grant [test options]\n");
    exit(2);
}
if ($mode !== 'test' && count($argv) > 2) {
    fwrite(STDERR, "Additional arguments are supported only for test.\n");
    exit(2);
}
$readEnv = static function (string $path): array {
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
            $result[$matches[1]] = trim($matches[2], "\"'");
        }
    }

    return $result;
};
$database = $readEnv($root.'/.env.database');
$target = $readEnv($root.(in_array($mode, ['testing', 'rollback-testing', 'test'], true) ? '/.env.testing' : '/.env.migration'));
$expectedDatabase = in_array($mode, ['testing', 'rollback-testing', 'test'], true) ? 'mediqueue_test' : 'mediqueue';
if (($target['DB_DATABASE'] ?? '') !== $expectedDatabase || ! empty($target['DB_URL'])) {
    fwrite(STDERR, "Schema runner requires the isolated named database and explicit connection settings.\n");
    exit(2);
}
if (! isset($database['MYSQL_ROOT_PASSWORD']) || $database['MYSQL_ROOT_PASSWORD'] === '') {
    fwrite(STDERR, "Missing database administrator password in .env.database.\n");
    exit(1);
}
if ($mode === 'grant') {
    $app = $readEnv($root.'/.env');
    if (($app['DB_DATABASE'] ?? '') !== 'mediqueue' || ! empty($app['DB_URL'])
        || ($app['DB_HOST'] ?? '') !== ($target['DB_HOST'] ?? '') || ($app['DB_PORT'] ?? '') !== ($target['DB_PORT'] ?? '')) {
        fwrite(STDERR, "Runtime and migration environments must target the same application database.\n");
        exit(2);
    }
    $pdo = new PDO('mysql:host='.($app['DB_HOST'] ?? '127.0.0.1').';port='.($app['DB_PORT'] ?? '3307').';dbname=mediqueue;charset=utf8mb4', 'root', $database['MYSQL_ROOT_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("GRANT EXECUTE ON PROCEDURE mediqueue.sp_department_statistics TO 'mediqueue_app'@'%'");
    $pdo->exec("GRANT EXECUTE ON FUNCTION mediqueue.fn_intervals_overlap TO 'mediqueue_app'@'%'");
    fwrite(STDOUT, "Granted execute on the two reporting routines.\n");
    exit(0);
}
$env = getenv();
// Laravel prefers inherited process variables to .env files. Pin the validated connection.
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $env[$key] = $target[$key] ?? '';
}
$env['DB_URL'] = '';
$env['DB_SOCKET'] = '';
$env['MEDIQUEUE_SCHEMA_RUNNER'] = '1';
$env['DB_ROUTINE_ADMIN_PASSWORD'] = $database['MYSQL_ROOT_PASSWORD'];
$command = [PHP_BINARY, ...$commands[$mode], ...array_slice($argv, 2)];
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $env);
if (! is_resource($process)) {
    fwrite(STDERR, "Could not start schema command.\n");
    exit(1);
}
exit(proc_close($process));
