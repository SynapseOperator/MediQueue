<?php

// A short-lived CLI container gets a separate test database; HTTP never gets these grants.
if (getenv('MEDIQUEUE_CONTAINER_DEMO') !== '1' || ! is_file('/.dockerenv')) {
    fwrite(STDERR, "Tests require the isolated Compose demo.\n");
    exit(2);
}
$credentials = json_decode(file_get_contents('/run/mediqueue-secrets/database.json'), true, flags: JSON_THROW_ON_ERROR);
$testPassword = bin2hex(random_bytes(24));
$pdo = new PDO('mysql:host=mysql;port=3306;charset=utf8mb4', 'root', $credentials['root'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS mediqueue_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$pdo->exec("CREATE USER IF NOT EXISTS 'mediqueue_test'@'%' IDENTIFIED BY ".$pdo->quote($testPassword));
$pdo->exec("ALTER USER 'mediqueue_test'@'%' IDENTIFIED BY ".$pdo->quote($testPassword));
$pdo->exec("GRANT ALL PRIVILEGES ON mediqueue_test.* TO 'mediqueue_test'@'%'");
$environment = array_merge(getenv(), [
    'APP_ENV' => 'testing', 'APP_KEY' => $credentials['key'],
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_DATABASE' => 'mediqueue_test',
    'DB_USERNAME' => 'mediqueue_test', 'DB_PASSWORD' => $testPassword,
    'DB_RUNTIME_TEST_USERNAME' => 'mediqueue_app', 'DB_RUNTIME_TEST_PASSWORD' => $credentials['app'],
    'DB_URL' => '', 'DB_SOCKET' => '', 'MEDIQUEUE_SCHEMA_RUNNER' => '1',
    'DB_ROUTINE_ADMIN_PASSWORD' => $credentials['root'],
]);
$process = proc_open([PHP_BINARY, 'artisan', 'test', ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__, 2), $environment);
exit(proc_close($process));
