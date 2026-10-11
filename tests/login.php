<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/registration.php';

// Bufor pozwala testować regenerację sesji również po wypisaniu wyników testów.
ob_start();
$sessionDirectory = sys_get_temp_dir() . '/cuttime-login-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.save_path', $sessionDirectory);
start_secure_session();
$passed = 0;
$failed = 0;

function login_check(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Unexpected result');
    }
}

function login_test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        // Nigdy nie wypisujemy komunikatów PDO lub konfiguracji testowej.
        echo "FAIL {$name} (" . get_class($error) . ")\n";
    }
}

$valid = ['email' => 'client@example.test', 'password' => 'password'];
try {
    login_test('Missing, empty, whitespace-only and single-field input', static function () use ($valid): void {
        login_check(count(log_in([])['errors']) === 2);
        login_check(isset(log_in(['email' => $valid['email']])['errors']['password']));
        login_check(isset(log_in(['password' => $valid['password']])['errors']['email']));
        foreach (['', '   ', "\t\n", "\u{00A0}\u{2003}"] as $empty) {
            $result = log_in(['email' => $empty, 'password' => $empty]);
            login_check(!$result['success'] && count($result['errors']) === 2);
        }
    });
    login_test('Invalid email including HTML and SQL input', static function () use ($valid): void {
        foreach (['wrong', 'a@', 'a b@example.test', '<script>alert(1)</script>', "' OR 1=1 --"] as $email) {
            login_check(isset(log_in(array_replace($valid, ['email' => $email]))['errors']['email']));
        }
    });
    login_test('Normalization and compatibility with short seeded passwords', static function () use ($valid): void {
        $result = validate_login(array_replace($valid, ['email' => "\u{00A0} CLIENT@EXAMPLE.TEST  "]));
        login_check($result['errors'] === [] && $result['values']['email'] === $valid['email']);
        login_check($result['password'] === 'password');
    });
    login_test('Arrays, nested fields, scalars, invalid UTF-8 and NUL', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            foreach ([[], ['nested' => 'bad'], new stdClass(), 123, false, "bad\xFF", "bad\0value"] as $bad) {
                login_check(isset(log_in(array_replace($valid, [$field => $bad]))['errors'][$field]));
            }
        }
    });
    login_test('Oversized input and bcrypt 72-byte boundary', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            $result = log_in(array_replace($valid, [$field => str_repeat('a', 100000)]));
            login_check(isset($result['errors'][$field]) && !isset($result['password']));
            if ($field === 'email') {
                login_check($result['values']['email'] === '');
            }
        }
        login_check(validate_login(array_replace($valid, ['password' => str_repeat('a', 72)]))['errors'] === []);
        login_check(isset(validate_login(array_replace($valid, ['password' => str_repeat('ą', 37)]))['errors']['password']));
    });
    login_test('XSS escaped and password preserved without redisplay', static function () use ($valid): void {
        $result = validate_login(['email' => '<script>alert(1)</script>', 'password' => '  <script> & hasło  ']);
        login_check(e($result['values']['email']) === '&lt;script&gt;alert(1)&lt;/script&gt;');
        login_check($result['password'] === '  <script> & hasło  ');
        login_check(!isset($result['values']['password']));
        login_check(!str_contains(e('" autofocus onfocus="alert(1)'), '"'));
    });

    $dsn = getenv('CUTTIME_TEST_DSN');
    if ($dsn === false || $dsn === '') {
        echo "SKIP database tests: set CUTTIME_TEST_DSN, CUTTIME_TEST_USER and CUTTIME_TEST_PASSWORD.\n";
    } else {
        $connection = new PDO($dsn, getenv('CUTTIME_TEST_USER') ?: '', getenv('CUTTIME_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Definicja z projektu; tymczasowa tabela zasłania users tylko w tym PDO.
        $sql = file_get_contents(dirname(__DIR__) . '/database/database.sql');
        if (preg_match('/CREATE TABLE users \(.*?\) ENGINE=.*?;/s', $sql, $match) !== 1) {
            throw new RuntimeException('Missing users definition');
        }
        $connection->exec(str_replace('CREATE TABLE users', 'CREATE TEMPORARY TABLE users', $match[0]));
        if (preg_match('/SET @test_password_hash = \'([^\']+)\';/', $sql, $hashMatch) !== 1) {
            throw new RuntimeException('Missing test password hash');
        }
        $seedHash = $hashMatch[1];
        login_test('SQL seed hash verifies the password documented in README', static function () use ($seedHash): void {
            login_check(password_verify('password', $seedHash));
        });
        $insert = $connection->prepare('INSERT INTO users (name, surname, email, password, role, active) VALUES (:name, :surname, :email, :password, :role, :active)');
        foreach (['client', 'employee', 'admin', 'inactive'] as $role) {
            $insert->execute([
                'name' => 'Test', 'surname' => 'Login', 'email' => $role . '@example.test',
                'password' => $seedHash, 'role' => $role === 'inactive' ? 'client' : $role,
                'active' => $role === 'inactive' ? 0 : 1,
            ]);
        }

        login_test('Unknown user, wrong password and inactive account have identical errors', static function () use ($connection, $valid): void {
            $expected = null;
            foreach ([
                ['email' => 'missing@example.test'], ['password' => 'wrong password'],
                ['email' => 'inactive@example.test', 'active' => '1'],
            ] as $changes) {
                $_SESSION = [];
                $oldId = session_id();
                $result = log_in(array_replace($valid, $changes), $connection);
                $expected ??= $result['errors'];
                login_check(!$result['success'] && $result['errors'] === $expected);
                login_check($result['errors']['_form'] === 'Nieprawidłowy adres e-mail lub hasło.');
                login_check($_SESSION === [] && session_id() === $oldId);
                login_check(!isset($result['password']));
            }
        });
        login_test('Successful login for all roles uses database ID/role and ignores POST overrides', static function () use ($connection): void {
            foreach (['client' => 1, 'employee' => 2, 'admin' => 3] as $role => $id) {
                $result = log_in([
                    'email' => strtoupper($role) . '@EXAMPLE.TEST', 'password' => 'password',
                    'role' => 'administrator', 'user_id' => 999, 'user_role' => 'admin', 'active' => '0',
                ], $connection);
                login_check($result['success'] && $result['errors'] === []);
                login_check($_SESSION === ['user_id' => $id, 'user_role' => $role]);
                login_check(!isset($result['password']));
            }
        });
        login_test('Session ID rotates, old file is deleted and authentication survives reopening', static function () use ($connection, $valid, $sessionDirectory): void {
            $_SESSION = ['anonymous_data' => 'discard'];
            $oldToken = csrf_token();
            $oldId = session_id();
            session_write_close();
            start_secure_session();
            login_check(file_exists($sessionDirectory . '/sess_' . $oldId));
            login_check(log_in($valid, $connection)['success']);
            $newId = session_id();
            login_check($oldId !== $newId && !file_exists($sessionDirectory . '/sess_' . $oldId));
            login_check(!csrf_is_valid($oldToken));
            login_check($_SESSION === ['user_id' => 1, 'user_role' => 'client']);
            session_write_close();
            start_secure_session();
            login_check(session_id() === $newId && current_user_id() === 1 && $_SESSION['user_role'] === 'client');
        });
        login_test('Registration-created password including spaces and HTML can log in exactly', static function () use ($connection): void {
            $password = '  <script> & hasło  ';
            $registered = register_client([
                'name' => 'Anna', 'surname' => 'Testowa', 'email' => 'new@example.test', 'phone' => '500100200',
                'password' => $password, 'password_confirmation' => $password,
            ], $connection);
            login_check($registered['success']);
            $_SESSION = [];
            login_check(!log_in(['email' => 'new@example.test', 'password' => trim($password)], $connection)['success']);
            login_check(log_in(['email' => 'new@example.test', 'password' => $password], $connection)['success']);
            login_check($_SESSION === ['user_id' => 5, 'user_role' => 'client']);
        });
        login_test('SQL-shaped valid email is treated as data and cannot bypass authentication', static function () use ($connection): void {
            $_SESSION = [];
            // Apostrof jest dozwolony w local-part: to dociera do rzeczywistego prepared SELECT.
            $result = log_in(['email' => "x'OR'1'='1@example.test", 'password' => 'password'], $connection);
            login_check(isset($result['errors']['_form']) && !$result['success'] && $_SESSION === []);
        });
        login_test('Malformed stored hash fails safely', static function () use ($connection, $valid): void {
            $connection->exec("UPDATE users SET password = 'invalid-hash' WHERE id = 1");
            $_SESSION = [];
            $result = log_in($valid, $connection);
            login_check(!$result['success'] && $result['errors']['_form'] === 'Nieprawidłowy adres e-mail lub hasło.');
            login_check($_SESSION === []);
        });
    }
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL test setup (' . get_class($error) . ")\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // Wyłącznie pliki własnej sesji testowej, bez rekursji.
    foreach (glob($sessionDirectory . '/*') as $file) {
        unlink($file);
    }
    rmdir($sessionDirectory);
}
echo "Results: {$passed} passed, {$failed} failed.\n";
ob_end_flush();
exit($failed === 0 ? 0 : 1);
