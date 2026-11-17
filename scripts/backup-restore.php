<?php

// A local logical backup drill: never overwrite the source or an existing target database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$root = dirname(__DIR__);
$options = getopt('', ['mysql-bin:', 'label:']);
$label = $options['label'] ?? gmdate('Ymd_His');
if (! preg_match('/\A[a-z0-9_]{1,24}\z/', $label) || empty($options['mysql-bin'])) {
    fwrite(STDERR, "Usage: php scripts/backup-restore.php --mysql-bin=PATH --label=unique_label\n");
    exit(2);
}
if (is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Clear application config cache before the isolated restore drill.\n");
    exit(2);
}
$appEnv = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
$dbEnv = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.database'));
if (($appEnv['DB_DATABASE'] ?? '') !== 'mediqueue' || ! in_array($appEnv['APP_ENV'] ?? '', ['local', 'demo'], true)
    || ! empty($appEnv['DB_URL']) || ! in_array($appEnv['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This drill requires the named local/demo database on loopback.');
}
$target = 'mediqueue_restore_p6_'.$label;
$directory = $root.'/.local/backups/'.$label;
if (is_dir($directory)) {
    throw new RuntimeException('Backup label already exists; use a new label.');
}
mkdir($directory, 0700, true);
// Restrict private dump files (including password hashes and sessions) to the current OS user.
if (PHP_OS_FAMILY === 'Windows') {
    $identity = getenv('USERDOMAIN').'\\'.getenv('USERNAME');
    $process = proc_open(['icacls.exe', $directory, '/inheritance:r', '/grant:r', $identity.':(OI)(CI)F'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory.'/acl.log', 'w'], 2 => ['file', $directory.'/acl.err', 'w']], $pipes);
    fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Could not restrict the private backup directory.');
    }
}
$clientFile = $directory.'/client.cnf';
$runtimeUser = null;
try {
    $escape = static fn (string $value): string => str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
    file_put_contents($clientFile, "[client]\nuser=root\npassword=\"".$escape($dbEnv['MYSQL_ROOT_PASSWORD'])."\"\nhost=".$appEnv['DB_HOST']."\nport=".$appEnv['DB_PORT']."\nprotocol=tcp\n");
    chmod($clientFile, 0600);
    $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
    $mysql = rtrim($options['mysql-bin'], '/\\').'/mysql'.$suffix;
    $dump = rtrim($options['mysql-bin'], '/\\').'/mysqldump'.$suffix;
    $pdo = new PDO('mysql:host='.$appEnv['DB_HOST'].';port='.$appEnv['DB_PORT'].';charset=utf8mb4', 'root', $dbEnv['MYSQL_ROOT_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $existing = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existing->execute([$target]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('Restore target exists; never overwrite it.');
    }
    $report = ['captured_at_utc' => gmdate('c'), 'source' => 'mediqueue', 'target' => $target,
        'version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'commands' => [], 'files' => []];
    $run = static function (string $name, array $command, string $output, ?string $input = null) use (&$report, $directory): void {
        $begin = hrtime(true);
        $process = proc_open($command, [0 => $input ? ['file', $input, 'r'] : ['pipe', 'r'],
            1 => ['file', $output, 'w'], 2 => ['file', $directory.'/'.$name.'.err', 'w']], $pipes);
        if ($input === null) {
            fclose($pipes[0]);
        }
        $code = proc_close($process);
        $report['commands'][$name] = ['exit_code' => $code, 'seconds' => round((hrtime(true) - $begin) / 1e9, 6)];
        if ($code !== 0) {
            throw new RuntimeException($name.' failed; inspect the private backup directory, not public logs.');
        }
    };
    $tables = ['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds', 'audit_logs', 'sessions', 'migrations'];
    $fingerprint = static function (string $database) use ($pdo, $tables): array {
        $result = [];
        foreach ($tables as $table) {
            $rows = $pdo->query("SELECT * FROM `{$database}`.`{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }

        return $result;
    };
    $before = $fingerprint('mediqueue');
    $common = ['--defaults-extra-file='.$clientFile, '--single-transaction', '--quick', '--set-gtid-purged=OFF', '--no-tablespaces', '--default-character-set=utf8mb4'];
    $run('dump-schema', [$dump, ...$common, '--no-data', '--routines', '--events', '--triggers', '--skip-add-drop-table', 'mediqueue'], $directory.'/schema.sql');
    $run('dump-data', [$dump, ...$common, '--no-create-info', '--skip-triggers', '--hex-blob', '--skip-add-locks', '--skip-disable-keys', 'mediqueue'], $directory.'/data.sql');
    $after = $fingerprint('mediqueue');
    if ($before !== $after) {
        throw new RuntimeException('Source changed during the drill. Keep the backup, then retry with an idle demo and a fresh label.');
    }
    // Only DDL is rebased; never search/replace arbitrary patient data in INSERT statements.
    $schema = str_replace('`mediqueue`.', '`'.$target.'`.', file_get_contents($directory.'/schema.sql'));
    file_put_contents($directory.'/restore-schema.sql', $schema);
    $pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $run('restore-schema', [$mysql, '--defaults-extra-file='.$clientFile, '--default-character-set=utf8mb4', $target], $directory.'/restore-schema.out', $directory.'/restore-schema.sql');
    $run('restore-data', [$mysql, '--defaults-extra-file='.$clientFile, '--default-character-set=utf8mb4', $target], $directory.'/restore-data.out', $directory.'/data.sql');
    $restored = $fingerprint($target);
    if ($before !== $restored) {
        throw new RuntimeException('Restored row fingerprints differ from the source.');
    }
    $report['tables_before_smoke'] = $restored;
    $report['source_unchanged_during_backup'] = true;
    $report['all_row_fingerprints_equal'] = true;
    $constraints = $pdo->prepare('SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME');
    $constraints->execute(['mediqueue']);
    $sourceConstraints = $constraints->fetchAll(PDO::FETCH_ASSOC);
    $constraints->execute([$target]);
    $restoredConstraints = $constraints->fetchAll(PDO::FETCH_ASSOC);
    if ($sourceConstraints !== $restoredConstraints) {
        throw new RuntimeException('Restored constraints differ from the source.');
    }
    $report['constraints_equal'] = true;
    $report['constraint_counts'] = array_count_values(array_column($restoredConstraints, 'CONSTRAINT_TYPE'));
    foreach (['schema.sql', 'data.sql', 'restore-schema.sql'] as $file) {
        $report['files'][$file] = ['bytes' => filesize($directory.'/'.$file), 'sha256' => hash_file('sha256', $directory.'/'.$file)];
    }
    $pdo->exec("USE `{$target}`");
    $objects = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $objects->execute([$target]);
    $report['tables_and_views'] = $objects->fetchAll(PDO::FETCH_ASSOC);
    $routines = $pdo->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME');
    $routines->execute([$target]);
    $report['routines'] = $routines->fetchAll(PDO::FETCH_ASSOC);
    if (count($report['tables_and_views']) !== 14 || count($report['routines']) !== 2) {
        throw new RuntimeException('Missing restored tables, views or routines.');
    }
    $viewDefinitions = $pdo->prepare('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?');
    $viewDefinitions->execute([$target]);
    foreach ($viewDefinitions->fetchAll(PDO::FETCH_COLUMN) as $definition) {
        if (str_contains($definition, '`mediqueue`.')) {
            throw new RuntimeException('A restored view still references the source database.');
        }
    }
    $foreignKeys = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $foreignKeys->execute([$target]);
    $report['foreign_key_orphans'] = [];
    foreach ($foreignKeys->fetchAll(PDO::FETCH_ASSOC) as $fk) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$fk['TABLE_NAME']}` c LEFT JOIN `{$fk['REFERENCED_TABLE_NAME']}` p ON c.`{$fk['COLUMN_NAME']}` = p.`{$fk['REFERENCED_COLUMN_NAME']}` WHERE c.`{$fk['COLUMN_NAME']}` IS NOT NULL AND p.`{$fk['REFERENCED_COLUMN_NAME']}` IS NULL")->fetchColumn();
        $report['foreign_key_orphans'][$fk['TABLE_NAME'].'.'.$fk['COLUMN_NAME']] = $count;
    }
    $checks = [
        'occupancy_mismatches' => 'SELECT COUNT(*) FROM v_slot_occupancy_check WHERE difference <> 0',
        'missing_orders' => 'SELECT COUNT(*) FROM appointments a LEFT JOIN payment_orders o ON o.appointment_id=a.id WHERE o.id IS NULL',
        'order_amount_mismatches' => 'SELECT COUNT(*) FROM appointments a JOIN payment_orders o ON o.appointment_id=a.id WHERE a.fee_amount<>o.amount',
        'refund_amount_mismatches' => 'SELECT COUNT(*) FROM refunds r JOIN payment_orders o ON o.id=r.payment_order_id WHERE r.amount<>o.amount',
    ];
    foreach ($checks as $name => $sql) {
        $report['integrity'][$name] = (int) $pdo->query($sql)->fetchColumn();
    }
    if (array_sum($report['foreign_key_orphans']) + array_sum($report['integrity']) !== 0) {
        throw new RuntimeException('Restored data integrity check failed.');
    }
    $runtimeUser = 'mq_restore_'.bin2hex(random_bytes(6));
    $runtimePassword = bin2hex(random_bytes(24));
    $account = $pdo->quote($runtimeUser)."@'localhost'";
    $pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($runtimePassword));
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$target}`.* TO ".$account);
    $pdo->exec("GRANT EXECUTE ON PROCEDURE `{$target}`.sp_department_statistics TO ".$account);
    $pdo->exec("GRANT EXECUTE ON FUNCTION `{$target}`.fn_intervals_overlap TO ".$account);
    // The application boot and service smoke use a dedicated DML account, not the restore DBA.
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
        'database.connections.mysql.unix_socket' => '', 'database.connections.mysql.database' => $target,
        'database.connections.mysql.host' => $appEnv['DB_HOST'], 'database.connections.mysql.port' => $appEnv['DB_PORT'],
        'database.connections.mysql.username' => $runtimeUser, 'database.connections.mysql.password' => $runtimePassword,
        'mediqueue.mock_payments_enabled' => true]);
    DB::purge();
    if (DB::selectOne('SELECT DATABASE() AS db')->db !== $target) {
        throw new RuntimeException('Application smoke is not isolated.');
    }
    $report['read_smoke'] = [
        'view_rows' => DB::table('v_appointment_details')->count(),
        'adjacent_overlap' => DB::selectOne("SELECT fn_intervals_overlap('2026-11-17 01:00:00','2026-11-17 02:00:00','2026-11-17 02:00:00','2026-11-17 03:00:00') AS value")->value,
        'department_rows' => count(DB::select("CALL sp_department_statistics('2026-01-01','2027-12-31')")),
    ];
    $now = CarbonImmutable::now('UTC');
    $slot = DB::table('slots as sl')->join('schedules as s', 's.id', '=', 'sl.schedule_id')
        ->join('doctors as d', 'd.id', '=', 's.doctor_id')->join('departments as dep', 'dep.id', '=', 's.department_id')
        ->join('users as u', 'u.id', '=', 'd.user_id')->where('sl.status', 'OPEN')->where('s.status', 'OPEN')
        ->where('d.status', 'ACTIVE')->where('dep.status', 'ACTIVE')->where('u.status', 'ACTIVE')
        ->whereColumn('sl.occupied_count', '<', 'sl.capacity')->where('sl.starts_at', '>', $now->addMinutes(1)->format('Y-m-d H:i:s.v'))
        ->whereBetween('s.work_date', [$now->setTimezone('Asia/Shanghai')->toDateString(), $now->setTimezone('Asia/Shanghai')->addDays(6)->toDateString()])
        ->orderBy('sl.id')->first(['sl.id', 'sl.occupied_count']);
    if (! $slot) {
        throw new RuntimeException('Restore is verified, but no future open demo slot is available for the write smoke.');
    }
    $user = DB::transaction(function () use ($now): User {
        $user = User::create(['username' => 'restore_smoke_'.bin2hex(random_bytes(5)), 'password_hash' => Hash::make(bin2hex(random_bytes(24))), 'role' => 'PATIENT', 'status' => 'ACTIVE']);
        DB::table('patients')->insert(['user_id' => $user->id, 'full_name' => '恢复验证虚构患者', 'phone' => 'RESTORE-DEMO', 'created_at' => $now, 'updated_at' => $now]);

        return $user;
    });
    $admin = DB::table('users')->where('role', 'ADMIN')->where('status', 'ACTIVE')->value('id');
    $id = app(AppointmentService::class)->create($user->id, $slot->id, 'restore_smoke_'.bin2hex(random_bytes(12)));
    $life = app(AppointmentLifecycleService::class);
    $states = [$life->pay($user->id, $id), $life->cancel($user->id, $id), $life->refund($admin, $id, 'failed'), $life->refund($admin, $id, 'success')];
    if ($states !== ['CONFIRMED', 'CANCELLED', 'FAILED', 'SUCCEEDED'] || DB::table('slots')->where('id', $slot->id)->value('occupied_count') !== $slot->occupied_count) {
        throw new RuntimeException('Restored service smoke failed.');
    }
    $report['write_smoke'] = ['database' => $target, 'appointment_id' => $id, 'states' => $states,
        'order_status' => DB::table('payment_orders')->where('appointment_id', $id)->value('status'),
        'occupancy_mismatches' => DB::table('v_slot_occupancy_check')->where('difference', '<>', 0)->count()];
    $report['source_unchanged_after_restore_smoke'] = $before === $fingerprint('mediqueue');
    if (! $report['source_unchanged_after_restore_smoke']) {
        throw new RuntimeException('Source changed during restore; investigate concurrent demo activity.');
    }
    $report['status'] = 'passed';
    $report['private_backup_directory'] = '.local/backups/'.$label;
    file_put_contents($root.'/docs/verification/p6-backup-results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDOUT, "Backup, isolated restore, row fingerprints, associations and DML service smoke passed. See docs/verification/p6-backup-results.json.\n");
} finally {
    try {
        if ($runtimeUser !== null) {
            if (isset($app)) {
                DB::disconnect();
            }
            $pdo->exec('DROP USER IF EXISTS '.$pdo->quote($runtimeUser)."@'localhost'");
        }
    } finally {
        if (is_file($clientFile)) {
            unlink($clientFile);
        }
    }
}
