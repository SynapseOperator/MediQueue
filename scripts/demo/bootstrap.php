<?php

// Runs only in the disposable Compose demo. The host's environment is never read.
if (getenv('MEDIQUEUE_CONTAINER_DEMO') !== '1' || ! is_file('/.dockerenv')) {
    fwrite(STDERR, "This initializer is only for the isolated Compose demo.\n");
    exit(2);
}

$secretsDirectory = '/run/mediqueue-secrets';
$runtimeDirectory = '/run/mediqueue-runtime';
foreach ([$secretsDirectory, $runtimeDirectory] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}
$credentialsPath = $secretsDirectory.'/database.json';
if (! is_file($credentialsPath)) {
    $credentials = [
        'root' => bin2hex(random_bytes(24)),
        'app' => bin2hex(random_bytes(24)),
        'migrator' => bin2hex(random_bytes(24)),
        'key' => 'base64:'.base64_encode(random_bytes(32)),
    ];
    file_put_contents($credentialsPath, json_encode($credentials, JSON_THROW_ON_ERROR));
    chmod($credentialsPath, 0600);
}
$credentials = json_decode(file_get_contents($credentialsPath), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($secretsDirectory.'/mysql-root.password', $credentials['root']);
chmod($secretsDirectory.'/mysql-root.password', 0444);

// This separate volume contains only runtime credentials; HTTP never mounts database.json.
$environment = file_get_contents(dirname(__DIR__, 2).'/.env.example');
$environment = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=demo', $environment);
$environment = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$credentials['key'], $environment);
$environment = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://localhost:8080', $environment);
$environment = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=mysql', $environment);
$environment = preg_replace('/^DB_PORT=.*$/m', 'DB_PORT=3306', $environment);
$environment = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$credentials['app'], $environment);
$environment = preg_replace('/^MOCK_PAYMENTS_ENABLED=.*$/m', 'MOCK_PAYMENTS_ENABLED=true', $environment);
file_put_contents($runtimeDirectory.'/app.env', $environment);
chmod($runtimeDirectory.'/app.env', 0644);
fwrite(STDOUT, "Demo configuration ready; existing keys and passwords preserved.\n");
