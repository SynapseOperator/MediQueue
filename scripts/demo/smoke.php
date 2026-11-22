<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (getenv('MEDIQUEUE_CONTAINER_DEMO') !== '1' || ! is_file('/.dockerenv')) {
    fwrite(STDERR, "Smoke tests require the isolated Compose demo.\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$check = static function (bool $result, string $label): void {
    if (! $result) {
        throw new RuntimeException('Demo smoke failed: '.$label);
    }
    fwrite(STDOUT, 'PASS '.$label.PHP_EOL);
};
$check(app()->environment('demo') && DB::selectOne('SELECT DATABASE() AS db')->db === 'mediqueue', 'isolated demo connection');
$snapshot = static function (): string {
    $rows = [];
    foreach (['users', 'patients', 'departments', 'doctors', 'schedules', 'slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};
$snapshotFile = '/run/mediqueue-secrets/smoke-snapshot';
if (($argv[1] ?? '') === '--verify-restart') {
    $check(is_file($snapshotFile) && hash_equals(trim(file_get_contents($snapshotFile)), $snapshot()), 'restart preserved accounts, password hashes and resources');
    exit(0);
}
$check(DB::table('information_schema.tables')->where('table_schema', 'mediqueue')->where('table_type', 'BASE TABLE')->count() === 12, 'ten core tables plus sessions and migrations');
$check(DB::table('information_schema.tables')->where('table_schema', 'mediqueue')->where('table_type', 'VIEW')->count() === 2, 'two views');
$check(DB::table('information_schema.routines')->where('routine_schema', 'mediqueue')->count() === 2, 'procedure and function');
$check((int) DB::selectOne("SELECT fn_intervals_overlap('2030-01-01 09:00:00', '2030-01-01 10:00:00', '2030-01-01 09:30:00', '2030-01-01 10:30:00') AS overlapping")->overlapping === 1, 'runtime can execute reporting function');
$departmentIds = DB::table('departments')->orderBy('id')->pluck('id')->all();
$statistics = DB::select("CALL sp_department_statistics('2020-01-01', '2099-01-01')");
$check($departmentIds !== [] && array_column($statistics, 'department_id') === $departmentIds, 'runtime statistics include each seeded department exactly once');
$check(DB::table('v_slot_occupancy_check')->where('difference', '<>', 0)->count() === 0, 'slot occupancy agrees with appointments');
$grants = json_encode(DB::select('SHOW GRANTS'), JSON_THROW_ON_ERROR);
$check(! preg_match('/\b(ALL PRIVILEGES|CREATE|ALTER|DROP|GRANT OPTION)\b/', $grants), 'runtime has no DDL or grant privilege');
$check(config('database.connections.routine_ddl') === null, 'runtime has no routine administrator connection');

// These additional random accounts belong only to this ephemeral CI/demo smoke dataset.
// Existing demo passwords are not read or changed, and no credential/cookie is printed.
foreach (['PATIENT' => '/patient/profile', 'DOCTOR' => '/doctor/workbench', 'ADMIN' => '/admin'] as $role => $page) {
    $username = 'smoke_'.strtolower($role).'_'.bin2hex(random_bytes(5));
    $password = bin2hex(random_bytes(12));
    DB::transaction(function () use ($username, $password, $role): void {
        $timestamps = ['created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => now('UTC')->format('Y-m-d H:i:s.v')];
        $userId = DB::table('users')->insertGetId(['username' => $username, 'password_hash' => Hash::make($password), 'role' => $role, 'status' => 'ACTIVE', ...$timestamps]);
        if ($role === 'PATIENT') {
            DB::table('patients')->insert(['user_id' => $userId, 'full_name' => 'CI演示患者', 'phone' => 'SYNTHETIC-CI', ...$timestamps]);
        } elseif ($role === 'DOCTOR') {
            DB::table('doctors')->insert(['user_id' => $userId, 'department_id' => DB::table('departments')->min('id'), 'full_name' => 'CI演示医生', 'title' => '演示医师', 'status' => 'ACTIVE', ...$timestamps]);
        }
    });
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
    curl_setopt($curl, CURLOPT_URL, 'http://app:8000/login');
    $html = curl_exec($curl);
    $check(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && preg_match('/name="_token" value="([^"]+)"/', $html, $token) === 1, $role.' login form and CSRF');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_token' => html_entity_decode($token[1]), 'username' => $username, 'password' => $password])]);
    curl_exec($curl);
    $check(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 302, $role.' session login');
    curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_URL => 'http://app:8000'.$page]);
    curl_exec($curl);
    $check(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200, $role.' authorized page');
    curl_close($curl);
}
file_put_contents($snapshotFile, $snapshot());
chmod($snapshotFile, 0600);
fwrite(STDOUT, "Demo smoke complete. No credentials or sessions were printed.\n");
