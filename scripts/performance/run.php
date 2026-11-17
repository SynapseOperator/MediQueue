<?php

// Explicit, isolated and non-destructive: refuses an existing performance database.
$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Cached configuration is unsafe for performance isolation.\n");
    exit(2);
}
$envFile = static function (string $file): array {
    $result = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
            $result[$m[1]] = trim($m[2], "\"'");
        }
    }

    return $result;
};
$db = $envFile($root.'/.env.database');
$app = $envFile($root.'/.env.migration');
$host = $app['DB_HOST'];
$port = $app['DB_PORT'];
$password = $db['MYSQL_ROOT_PASSWORD'];
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'mediqueue_perf_p6';
$check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$check->execute([$name]);
if ($check->fetchColumn()) {
    if (($argv[1] ?? '') !== '--measure-existing') {
        fwrite(STDERR, "Performance database exists; refusing to overwrite.\n");
        exit(1);
    }
    $pdo->exec("USE `$name`");
    goto measure;
}
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$env = getenv();
$env['DB_CONNECTION'] = 'mysql';
$env['DB_URL'] = '';
$env['DB_SOCKET'] = '';
$env['DB_HOST'] = $host;
$env['DB_PORT'] = $port;
$env['DB_DATABASE'] = $name;
$env['DB_USERNAME'] = 'root';
$env['DB_PASSWORD'] = $password;
$env['MEDIQUEUE_SCHEMA_RUNNER'] = '1';
$env['DB_ROUTINE_ADMIN_PASSWORD'] = $password;
$proc = proc_open([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], [STDIN, STDOUT, STDERR], $pipes, $root, $env);
if (! is_resource($proc) || proc_close($proc) !== 0) {
    throw new RuntimeException('Migration failed; performance database retained for inspection.');
}
$pdo->exec("USE `$name`");
$hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$hashSql = $pdo->quote($hash);
$now = '2026-11-16 16:00:00.000';
$pdo->beginTransaction();
$pdo->exec("INSERT INTO users (username,password_hash,role,status,created_at,updated_at) VALUES ('perf_admin',$hashSql,'ADMIN','ACTIVE','$now','$now'),('perf_doctor',$hashSql,'DOCTOR','ACTIVE','$now','$now')");
$pdo->exec("INSERT INTO departments (code,name,status,created_at,updated_at) VALUES ('PERF','合成性能科','ACTIVE','$now','$now')");
$pdo->exec("INSERT INTO doctors (user_id,department_id,full_name,title,status,created_at,updated_at) VALUES (2,1,'合成性能医生','演示医师','ACTIVE','$now','$now')");
$pdo->exec("INSERT INTO schedules (doctor_id,department_id,work_date,session_code,starts_at,ends_at,status,created_by,created_at,updated_at) VALUES (1,1,'2026-11-18','MORNING','2026-11-17 16:00:00.000','2026-11-18 08:40:00.000','OPEN',1,'$now','$now')");
$u = $pdo->prepare('INSERT INTO users (username,password_hash,role,status,created_at,updated_at) VALUES (?, ?, ?, ?, ?, ?)');
$p = $pdo->prepare('INSERT INTO patients (user_id,full_name,phone,created_at,updated_at) VALUES (?, ?, ?, ?, ?)');
for ($i = 1; $i <= 50000; $i++) {
    $u->execute(['perf_patient_'.$i, $hash, 'PATIENT', 'ACTIVE', $now, $now]);
    $p->execute([$pdo->lastInsertId(), '合成患者'.$i, 'PERF-'.$i, $now, $now]);
}
$sl = $pdo->prepare('INSERT INTO slots (schedule_id,starts_at,ends_at,capacity,occupied_count,fee_amount,status,created_at,updated_at) VALUES (1,?,?,50,50,12.34,\'OPEN\',?,?)');
$start = new DateTimeImmutable('2026-11-17 16:00:00', new DateTimeZone('UTC'));
for ($i = 0; $i < 1000; $i++) {
    $sl->execute([$start->modify("+$i minutes")->format('Y-m-d H:i:s.v'), $start->modify('+'.($i + 1).' minutes')->format('Y-m-d H:i:s.v'), $now, $now]);
}
$a = $pdo->prepare('INSERT INTO appointments (appointment_no,patient_id,slot_id,request_key,status,fee_amount,expires_at,created_at,updated_at) VALUES (?,?,?,?,\'PENDING_PAYMENT\',12.34,?,?,?)');
$o = $pdo->prepare('INSERT INTO payment_orders (appointment_id,order_no,amount,currency,channel,status,created_at,updated_at) VALUES (?,?,12.34,\'CNY\',\'MOCK_WECHAT\',\'UNPAID\',?,?)');
for ($i = 1; $i <= 50000; $i++) {
    $patient = $i;
    $slot = intdiv($i - 1, 50) + 1;
    $a->execute(['P'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $patient, $slot, 'request-'.$i, '2026-11-16 16:15:00.000', $now, $now]);
    $o->execute([$pdo->lastInsertId(), 'O'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $now, $now]);
}
$pdo->commit();
measure:
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
    throw new RuntimeException('Unexpected database target');
}
$pdo->prepare('UPDATE users SET password_hash = ?')->execute([password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
if (($argv[1] ?? '') === '--measure-existing') {
    $pdo->exec("UPDATE appointments SET created_at='2026-11-16 16:00:00.000', updated_at='2026-11-16 16:00:00.000', expires_at='2026-11-16 16:15:00.000'");
    $pdo->exec("UPDATE payment_orders SET created_at='2026-11-16 16:00:00.000', updated_at='2026-11-16 16:00:00.000'");
}
$pdo->query('ANALYZE TABLE appointments, slots, payment_orders')->fetchAll();
$checks = $pdo->query('SELECT (SELECT COUNT(*) FROM appointments) appointments,(SELECT COUNT(*) FROM payment_orders) orders,(SELECT COUNT(*) FROM v_slot_occupancy_check WHERE difference <> 0) mismatches')->fetch(PDO::FETCH_ASSOC);
$queries = [
    'expiry' => ["SELECT id FROM appointments WHERE status='PENDING_PAYMENT' AND expires_at <= '2026-11-16 16:16:00' ORDER BY expires_at,id LIMIT 100", 'appointments_status_expires_at_id_index'],
    'patient_history' => ['SELECT id,created_at FROM appointments WHERE patient_id=42 ORDER BY created_at DESC,id DESC LIMIT 20', 'appointments_patient_id_created_at_id_index'],
];
$result = ['version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'schema' => $name, 'checks' => $checks, 'rows' => 50000, 'description' => '50000 synthetic patients, 1000 one-minute slots, 50 expired-unreclaimed pending appointments per slot; all orders UNPAID.', 'experiments' => []];
foreach ($queries as $label => [$query,$index]) {
    foreach (['normal' => $query, 'ignore_index' => str_replace('FROM appointments', "FROM appointments IGNORE INDEX (`$index`)", $query)] as $variant => $sql) {
        $times = [];
        $output = null;
        for ($i = 0; $i < 8; $i++) {
            $t = hrtime(true);
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $elapsed = (hrtime(true) - $t) / 1e6;
            if ($i > 0) {
                $times[] = $elapsed;
            } $output = $rows;
        }
        $sorted = $times;
        sort($sorted);
        $plan = $pdo->query('EXPLAIN ANALYZE '.$sql)->fetchAll(PDO::FETCH_COLUMN);
        $result['experiments'][$label][$variant] = ['sql' => $sql, 'milliseconds' => $times, 'median_ms' => $sorted[3], 'result_sha256' => hash('sha256', json_encode($output)), 'rows_returned' => count($output), 'explain_analyze' => $plan];
    }
}
file_put_contents($root.'/docs/performance/p6-results.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, "Performance results saved; database retained for inspection.\n");
