<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/cuttime-authorization-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$prefix = '<?php declare(strict_types=1);'
    . 'ini_set("session.save_path", ' . var_export($temporary, true) . ');'
    . 'ini_set("error_log", ' . var_export($temporary . '/errors.log', true) . ');'
    . 'require ' . var_export($root . '/includes/errors.php', true) . ';'
    . 'initialize_error_handling();'
    . 'require ' . var_export($root . '/includes/session.php', true) . ';'
    . 'require ' . var_export($root . '/includes/helpers.php', true) . ';'
    . 'require ' . var_export($root . '/includes/auth.php', true) . ';'
    . 'function check(bool $ok): void { if (!$ok) { throw new RuntimeException("TEST FAILED"); } }'
    . 'const TEST_SCHEMA = ' . var_export($root . '/database/database.sql', true) . ';'
    . <<<'PHP'
// Połączenie wyłącznie testowe, bez wczytywania database.local.php.
// Każdy proces używa tabeli tymczasowej zasłaniającej users tylko w swoim PDO.
function db(): PDO {
    static $connection = null;
    if ($connection !== null) { return $connection; }
    $connection = new PDO(getenv('CUTTIME_TEST_DSN'), getenv('CUTTIME_TEST_USER') ?: '', getenv('CUTTIME_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $sql = file_get_contents(TEST_SCHEMA);
    preg_match('/CREATE TABLE users \(.*?\) ENGINE=.*?;/s', $sql, $match);
    preg_match('/SET @test_password_hash = \'([^\']+)\';/', $sql, $hashMatch);
    $connection->exec(str_replace('CREATE TABLE users', 'CREATE TEMPORARY TABLE users', $match[0]));
    $insert = $connection->prepare('INSERT INTO users (name, surname, email, password, role) VALUES (:name, :surname, :email, :password, :role)');
    foreach (['client', 'employee', 'admin'] as $role) {
        $insert->execute(['name' => 'Test', 'surname' => 'Authorization', 'email' => $role . '@example.test', 'password' => $hashMatch[1], 'role' => $role]);
    }
    return $connection;
}
start_secure_session();
PHP;

$cases = [
    'Fixed landing pages for database roles' => [<<<'PHP'
check(role_home('client') === '/client.php');
check(role_home('employee') === '/employee.php');
check(role_home('admin') === '/admin.php');
foreach (['', 'administrator', 'ADMIN', '//example.test', '../admin.php'] as $role) {
    try { role_home($role); throw new RuntimeException('TEST FAILED'); }
    catch (InvalidArgumentException $expected) {}
}
echo 'OK';
PHP, 'OK'],
    'Invalid allowlists fail closed without reading database' => [<<<'PHP'
foreach ([[], ['administrator'], ['client', 'invalid'], [true], [123], [[]]] as $roles) {
    try { require_roles($roles); throw new RuntimeException('TEST FAILED'); }
    catch (InvalidArgumentException $expected) {}
}
echo 'OK';
PHP, 'OK'],
    'Invalid configuration uses the safe error handler' => ["require_role('invalid'); echo 'PRIVATE CONTENT';", 'Wystąpił błąd aplikacji. Spróbuj ponownie później.', 1],
];

foreach (['client', 'employee', 'admin'] as $role) {
    $cases['Guest denied ' . $role . ' despite forged request/session role'] = [
        '$_SESSION["user_role"] = "admin"; $_GET = $_POST = ["role" => "admin", "user_id" => 3];'
        . 'require_role(' . var_export($role, true) . '); echo "PRIVATE CONTENT";', '',
    ];
}
$cases['Guest denied common page'] = ['require_roles(["client", "employee", "admin"]); echo "PRIVATE CONTENT";', ''];

$dsn = getenv('CUTTIME_TEST_DSN');
if ($dsn !== false && $dsn !== '') {
    foreach (['client' => 1, 'employee' => 2, 'admin' => 3] as $role => $id) {
        $session = '$_SESSION = ["user_id" => ' . $id . ', "user_role" => "admin"];'
            . '$_GET = $_POST = ["role" => "admin", "active" => 1, "user_id" => 3];';
        foreach (['client', 'employee', 'admin'] as $required) {
            $cases[$role . ' -> ' . $required] = [
                $session . '$user = require_role(' . var_export($required, true) . '); echo "AUTHORIZED";',
                $role === $required ? 'AUTHORIZED' : '',
            ];
        }
        $cases[$role . ' -> common page'] = [
            $session . 'require_roles(["client", "employee", "admin"]); echo "AUTHORIZED";', 'AUTHORIZED',
        ];
        $cases[$role . ' -> employee or admin'] = [
            $session . 'require_roles(["employee", "admin"]); echo "AUTHORIZED";',
            $role === 'client' ? '' : 'AUTHORIZED',
        ];
    }
    $cases['Role helpers replace forged session role with database value'] = [<<<'PHP'
$_SESSION = ['user_id' => 1, 'user_role' => 'admin'];
check(is_logged_in() && current_user_id() === 1 && current_user_role() === 'client');
check($_SESSION['user_role'] === 'client');
$user = require_role('client');
check(!array_key_exists('password', $user));
echo 'OK';
PHP, 'OK'];
    $cases['Role change revokes prior access on next check'] = [<<<'PHP'
$_SESSION = ['user_id' => 3, 'user_role' => 'admin'];
db()->exec("UPDATE users SET role = 'client' WHERE id = 3");
require_role('admin');
echo 'PRIVATE CONTENT';
PHP, ''];
    $cases['Inactive account cannot use any protected page'] = [<<<'PHP'
$_SESSION = ['user_id' => 3, 'user_role' => 'admin'];
db()->exec('UPDATE users SET active = 0 WHERE id = 3');
check(current_user() === null && !isset($_SESSION['user_id'], $_SESSION['user_role']));
require_role('admin');
echo 'PRIVATE CONTENT';
PHP, ''];
    $cases['Deleted or missing user cannot use stale authentication'] = [<<<'PHP'
$_SESSION = ['user_id' => 999, 'user_role' => 'admin'];
require_roles(['client', 'employee', 'admin']);
echo 'PRIVATE CONTENT';
PHP, ''];
} else {
    echo "SKIP database tests: set CUTTIME_TEST_DSN, CUTTIME_TEST_USER and CUTTIME_TEST_PASSWORD.\n";
}

$passed = 0;
$failed = 0;
try {
    foreach ($cases as $name => $case) {
        [$code, $expectedOutput] = $case;
        $expectedExit = $case[2] ?? 0;
        $script = $temporary . '/case.php';
        file_put_contents($script, $prefix . $code);
        // Używamy aktywnego php.ini, aby proces miał to samo pdo_mysql.
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) {
            array_push($command, '-c', php_ini_loaded_file());
        }
        array_push($command, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', $script);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start test process'); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === $expectedExit && $output === $expectedOutput && $errors === '') {
            $passed++;
            echo "PASS {$name}\n";
        } else {
            $failed++;
            echo "FAIL {$name}\n"; // Bez treści błędów i danych dostępowych.
        }
    }
} finally {
    foreach (glob($temporary . '/*') as $file) { unlink($file); }
    rmdir($temporary);
}
echo "Results: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
