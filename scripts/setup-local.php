<?php

// Generate local-only credentials. Existing environments are never overwritten.
$root = dirname(__DIR__);
$files = ['.env', '.env.migration', '.env.testing', '.env.database'];
foreach ($files as $file) {
    if (file_exists($root.'/'.$file)) {
        fwrite(STDERR, "Existing environment found. Keep its credentials; configure missing files manually.\n");
        exit(1);
    }
}

$passwords = [];
foreach (['app', 'migrator', 'test'] as $role) {
    $passwords[$role] = bin2hex(random_bytes(24));
}
$rootPassword = getenv('MYSQL_ROOT_PASSWORD') ?: bin2hex(random_bytes(24));
$key = 'base64:'.base64_encode(random_bytes(32));
$application = file_get_contents($root.'/.env.example');
$testing = file_get_contents($root.'/.env.testing.example');

$environments = [
    '.env' => str_replace(['APP_KEY=', 'DB_PASSWORD='], ['APP_KEY='.$key, 'DB_PASSWORD='.$passwords['app']], $application),
    '.env.migration' => str_replace(
        ['APP_KEY=', 'DB_USERNAME=mediqueue_app', 'DB_PASSWORD='],
        ['APP_KEY='.$key, 'DB_USERNAME=mediqueue_migrator', 'DB_PASSWORD='.$passwords['migrator']],
        $application,
    ),
    '.env.testing' => str_replace(
        ['APP_KEY=', 'DB_PASSWORD=', 'DB_RUNTIME_TEST_PASSWORD='],
        ['APP_KEY='.$key, 'DB_PASSWORD='.$passwords['test'], 'DB_RUNTIME_TEST_PASSWORD='.$passwords['app']],
        $testing,
    ),
    '.env.database' => "MYSQL_ROOT_PASSWORD={$rootPassword}\n"
        ."MEDIQUEUE_APP_PASSWORD={$passwords['app']}\n"
        ."MEDIQUEUE_MIGRATOR_PASSWORD={$passwords['migrator']}\n"
        ."MEDIQUEUE_TEST_PASSWORD={$passwords['test']}\n",
];

foreach ($environments as $file => $contents) {
    file_put_contents($root.'/'.$file, $contents);
    chmod($root.'/'.$file, 0600);
}

fwrite(STDOUT, "Created local application, migration, test and database environments; no credentials printed.\n");
