<?php
declare(strict_types=1);

// Uruchamiaj z CLI: php tests/foundation.php. Bez bazy i bez danych użytkownika.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/cuttime-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
// Kopia kodu w izolacji: test nigdy nie odczytuje prawdziwego database.local.php.
copy($root . '/config/database.php', $temporary . '/database.php');
$prefix = '<?php declare(strict_types=1);'
    . 'ini_set("session.save_path", ' . var_export($temporary, true) . ');'
    . 'ini_set("error_log", ' . var_export($temporary . '/errors.log', true) . ');'
    . 'require ' . var_export($root . '/includes/errors.php', true) . ';'
    . 'initialize_error_handling();'
    . 'require ' . var_export($root . '/includes/session.php', true) . ';'
    . 'require ' . var_export($root . '/includes/helpers.php', true) . ';'
    . 'require ' . var_export($root . '/includes/auth.php', true) . ';'
    . 'require ' . var_export($temporary . '/database.php', true) . ';'
    . 'function check(bool $ok): void { if (!$ok) { throw new RuntimeException("TEST FAILED"); } }';
$publicError = 'Wystąpił błąd aplikacji. Spróbuj ponownie później.';

$cases = [
    'HTML escaping and invalid UTF-8' => [<<<'PHP'
check(e('<script>"\'&') === '&lt;script&gt;&quot;&#039;&amp;');
check(e(null) === '');
check(e("\xFF") === "\xEF\xBF\xBD");
echo 'OK';
PHP, 0, 'OK'],
    'Session flags, idempotence and guest' => [<<<'PHP'
start_secure_session();
$id = session_id();
start_secure_session();
check($id !== '' && session_id() === $id);
$cookie = session_get_cookie_params();
check($cookie['httponly'] && $cookie['samesite'] === 'Lax' && !$cookie['secure']);
check(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1');
check(current_user() === null && !is_logged_in() && current_user_role() === null);
foreach ([-1, 0, '1', [], true, 1.2] as $badId) {
    $_SESSION['user_id'] = $badId;
    check(current_user_id() === null);
}
$_SESSION['user_id'] = 1;
check(current_user_id() === 1);
echo 'OK';
PHP, 0, 'OK'],
    'HTTPS cookie' => [<<<'PHP'
$_SERVER['HTTPS'] = 'on';
start_secure_session();
check(session_get_cookie_params()['secure']);
echo 'OK';
PHP, 0, 'OK'],
    'Reject unknown session ID' => [<<<'PHP'
session_id('attacker-controlled-session');
start_secure_session();
check(session_id() !== 'attacker-controlled-session');
echo 'OK';
PHP, 0, 'OK'],
    'One-time flash messages' => [<<<'PHP'
flash('success', '<b>test</b>');
check(consume_flash() === [['type' => 'success', 'message' => '<b>test</b>']]);
check(consume_flash() === []);
try { flash('invalid', 'test'); throw new RuntimeException('TEST FAILED'); }
catch (InvalidArgumentException $expected) {}
echo 'OK';
PHP, 0, 'OK'],
    'Reject external redirects and header injection' => [<<<'PHP'
foreach (['', 'https://example.com', '//example.com', '/%2fexample.com', '/\\example.com', '/%5cexample.com', "/ok\r\nX-Test: bad", '/ok%0d%0aX-Test:bad'] as $badPath) {
    try { redirect($badPath); }
    catch (InvalidArgumentException $expected) { continue; }
    throw new RuntimeException('TEST FAILED');
}
echo 'OK';
PHP, 0, 'OK'],
    'Local redirect terminates execution' => ["redirect('/future-page'); echo 'UNREACHABLE';", 0, ''],
    'Missing local config has safe response' => ['db();', 1, $publicError],
    'Invalid local config has safe response' => [<<<'PHP'
file_put_contents(__DIR__ . '/database.local.php', '<?php return false;');
db();
PHP, 1, $publicError],
    'DSN parameter injection is rejected' => [<<<'PHP'
file_put_contents(__DIR__ . '/database.local.php', '<?php return ' . var_export([
    'host' => '127.0.0.1;dbname=other', 'port' => 3306,
    'name' => 'cuttime', 'user' => 'test', 'password' => '',
], true) . ';');
try { db(); }
catch (RuntimeException $expected) {
    check(get_class($expected) === RuntimeException::class);
    echo 'OK';
}
PHP, 0, 'OK'],
    'PDO exception has safe response' => ["throw new PDOException('SENSITIVE_TEST_VALUE SELECT secret FROM users');", 1, $publicError],
    'Warning has safe response' => ["trigger_error('SENSITIVE_TEST_VALUE', E_USER_WARNING);", 1, $publicError],
    'Buffered output removed on error' => ["ob_start(); echo 'SENSITIVE_TEST_VALUE'; throw new RuntimeException('SENSITIVE_TEST_VALUE');", 1, $publicError],
];

$failed = 0;
try {
    foreach ($cases as $name => [$code, $expectedExit, $expectedOutput]) {
        $script = $temporary . '/case.php';
        file_put_contents($script, $prefix . $code);
        $process = proc_open([
            PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'display_startup_errors=0', $script,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP test process.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $passed = $exit === $expectedExit && $output === $expectedOutput && $errors === '';
        echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
        if (!$passed) {
            echo '  Exit: ' . $exit . '; output: ' . var_export($output, true)
                . '; stderr: ' . var_export($errors, true) . PHP_EOL;
            $failed++;
        }
    }
    $log = file_exists($temporary . '/errors.log') ? file_get_contents($temporary . '/errors.log') : '';
    if (str_contains($log, 'SENSITIVE_TEST_VALUE')) {
        echo 'FAIL Sensitive exception message in log' . PHP_EOL;
        $failed++;
    }
} finally {
    // Usuwamy wyłącznie pliki z własnego losowego katalogu, bez rekursji.
    foreach (glob($temporary . '/*') as $file) {
        unlink($file);
    }
    rmdir($temporary);
}

exit($failed === 0 ? 0 : 1);
