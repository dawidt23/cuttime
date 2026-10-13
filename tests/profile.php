<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/profile.php';
require_once dirname(__DIR__) . '/includes/registration.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

ob_start();
$temporary = sys_get_temp_dir() . '/cuttime-profile-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
ini_set('session.save_path', $temporary);
start_secure_session();
$_SESSION['user_id'] = 1;
$passed = 0;
$failed = 0;

function profile_check(bool $ok): void
{
    if (!$ok) { throw new RuntimeException('Unexpected result'); }
}

function profile_test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL {$name} (" . get_class($error) . ")\n";
    }
}

function profile_row(PDO $connection, int $id): array
{
    $statement = $connection->prepare('SELECT * FROM users WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch();
}

// Pozwala wymusić wyścig po SELECT; UPDATE wykonuje rzeczywisty silnik bazy.
class ProfileRaceStatement extends PDOStatement
{
    protected function __construct() {}
    public function fetchColumn(int $column = 0): mixed
    {
        return str_contains($this->queryString, 'WHERE email = :email') ? false : parent::fetchColumn($column);
    }
}

$valid = ['name' => 'Łukasz', 'surname' => 'O’Connor-Kowalski', 'email' => 'client@example.test', 'phone' => '+48 500-100-200'];
try {
    profile_test('Four required fields: missing, empty and Unicode whitespace', static function () use ($valid): void {
        foreach ([null, '', '   ', "\t\n", "\u{00A0}\u{2003}"] as $empty) {
            $input = $empty === null ? [] : array_fill_keys(array_keys($valid), $empty);
            $result = update_own_profile($input);
            profile_check(!$result['success'] && count($result['errors']) === 4);
        }
    });
    profile_test('Registration and profile share normalization and validation', static function () use ($valid): void {
        $input = array_replace($valid, ['email' => '  CLIENT@EXAMPLE.TEST  ']);
        $details = validate_user_details($input);
        $registered = validate_registration($input + ['password' => 'Poprawne hasło 2026', 'password_confirmation' => 'Poprawne hasło 2026']);
        profile_check($details['errors'] === [] && $details['values'] === $registered['values']);
        profile_check($details['values']['email'] === 'client@example.test' && $details['values']['phone'] === '+48500100200');
    });
    profile_test('Invalid email and phone', static function () use ($valid): void {
        foreach (['not-an-email', 'a@', 'a b@example.test'] as $email) {
            profile_check(isset(validate_user_details(array_replace($valid, ['email' => $email]))['errors']['email']));
        }
        foreach (['123', '000000000', '+44 500100200', '5001002000', '<script>'] as $phone) {
            profile_check(isset(validate_user_details(array_replace($valid, ['phone' => $phone]))['errors']['phone']));
        }
    });
    profile_test('Byte limits and name lengths match the database', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            $result = validate_user_details(array_replace($valid, [$field => str_repeat('a', 100000)]));
            profile_check(isset($result['errors'][$field]) && $result['values'][$field] === '');
        }
        profile_check(validate_user_details(array_replace($valid, ['name' => str_repeat('ą', 50), 'surname' => str_repeat('Ż', 80)]))['errors'] === []);
        profile_check(isset(validate_user_details(array_replace($valid, ['name' => str_repeat('ą', 51)]))['errors']['name']));
        profile_check(isset(validate_user_details(array_replace($valid, ['surname' => str_repeat('Ż', 81)]))['errors']['surname']));
    });
    profile_test('Arrays, objects, non-text values, NUL and invalid UTF-8', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            foreach ([[], ['nested' => 'bad'], new stdClass(), false, 123, "bad\0value", "bad\xFF"] as $bad) {
                profile_check(isset(validate_user_details(array_replace($valid, [$field => $bad]))['errors'][$field]));
            }
        }
    });
    profile_test('XSS input is rejected and only escaped text may be displayed', static function () use ($valid): void {
        $attack = '<script>alert(1)</script>';
        foreach (array_keys($valid) as $field) {
            profile_check(isset(validate_user_details(array_replace($valid, [$field => $attack]))['errors'][$field]));
        }
        $result = validate_user_details(array_replace($valid, ['name' => $attack]));
        profile_check(e($result['values']['name']) === '&lt;script&gt;alert(1)&lt;/script&gt;');
    });
    profile_test('Guest cannot select an account by submitting ID', static function () use ($valid): void {
        $_SESSION = [];
        $result = update_own_profile($valid + ['id' => 1, 'user_id' => 1]);
        profile_check(!$result['success'] && isset($result['errors']['_form']));
        $_SESSION['user_id'] = 1;
    });

    $dsn = getenv('CUTTIME_TEST_DSN');
    if ($dsn === false || $dsn === '') {
        echo "SKIP database tests: set CUTTIME_TEST_DSN, CUTTIME_TEST_USER and CUTTIME_TEST_PASSWORD.\n";
    } else {
        $connection = new PDO($dsn, getenv('CUTTIME_TEST_USER') ?: '', getenv('CUTTIME_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $sql = file_get_contents(dirname(__DIR__) . '/database/database.sql');
        preg_match('/CREATE TABLE users \(.*?\) ENGINE=.*?;/s', $sql, $match);
        preg_match('/SET @test_password_hash = \'([^\']+)\';/', $sql, $hashMatch);
        $connection->exec(str_replace('CREATE TABLE users', 'CREATE TEMPORARY TABLE users', $match[0]));
        $insert = $connection->prepare('INSERT INTO users (name, surname, email, password, phone, role) VALUES (:name, :surname, :email, :password, :phone, :role)');
        foreach (['client', 'employee', 'admin'] as $role) {
            $insert->execute(['name' => 'Test', 'surname' => 'Profil', 'email' => $role . '@example.test', 'password' => $hashMatch[1], 'phone' => '+48500100200', 'role' => $role]);
        }
        profile_test('Own email is accepted and unchanged save succeeds', static function () use ($connection, $valid): void {
            $result = update_own_profile($valid, $connection);
            profile_check($result['success']);
            profile_check(update_own_profile($valid, $connection)['success']);
        });
        profile_test('Other user email, including case variant, is rejected without writes', static function () use ($connection, $valid): void {
            $before = profile_row($connection, 1);
            $result = update_own_profile(array_replace($valid, ['email' => 'EMPLOYEE@EXAMPLE.TEST']), $connection);
            profile_check(!$result['success'] && isset($result['errors']['email']));
            profile_check(profile_row($connection, 1) === $before);
        });
        profile_test('Only session owner changes; ID, role, active, password and timestamps are ignored', static function () use ($connection, $valid): void {
            $before = profile_row($connection, 1);
            $other = profile_row($connection, 3);
            $_GET = ['id' => 3, 'user_id' => 3, 'role' => 'admin'];
            $result = update_own_profile(array_replace($valid, ['name' => 'Anna', 'email' => 'new@example.test']) + [
                'id' => 3, 'user_id' => 3, 'role' => 'administrator', 'active' => 1,
                'password' => 'forged-password', 'created_at' => '2000-01-01 00:00:00',
            ], $connection);
            profile_check($result['success']);
            $after = profile_row($connection, 1);
            profile_check($after['name'] === 'Anna' && $after['email'] === 'new@example.test');
            foreach (['id', 'role', 'active', 'password', 'created_at'] as $field) { profile_check($after[$field] === $before[$field]); }
            profile_check(profile_row($connection, 3) === $other);
            profile_check(array_keys($result['values']) === ['name', 'surname', 'email', 'phone']);
        });
        profile_test('All roles may edit only their own basic fields', static function () use ($connection, $valid): void {
            foreach (['client' => 1, 'employee' => 2, 'admin' => 3] as $role => $id) {
                $_SESSION['user_id'] = $id;
                $email = $role . '-updated@example.test';
                profile_check(update_own_profile(array_replace($valid, ['email' => $email]) + ['active' => 0, 'role' => 'admin', 'id' => 999], $connection)['success']);
                $row = profile_row($connection, $id);
                profile_check($row['role'] === $role && (int) $row['active'] === 1 && $row['email'] === $email);
            }
            $_SESSION['user_id'] = 1;
        });
        profile_test('Email uniqueness race during UPDATE has a friendly error', static function () use ($connection, $valid): void {
            $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfileRaceStatement::class]);
            try {
                $before = profile_row($connection, 1);
                $result = update_own_profile(array_replace($valid, ['email' => 'employee-updated@example.test']), $connection);
                profile_check(!$result['success'] && isset($result['errors']['email']));
                profile_check(profile_row($connection, 1) === $before);
            } finally { $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]); }
        });
        profile_test('Inactive or missing session owner cannot be edited or reactivated by POST', static function () use ($connection, $valid): void {
            $connection->exec('UPDATE users SET active = 0 WHERE id = 1');
            $before = profile_row($connection, 1);
            profile_check(!update_own_profile($valid + ['active' => 1], $connection)['success']);
            profile_check(profile_row($connection, 1) === $before);
            $_SESSION['user_id'] = 999;
            profile_check(!update_own_profile($valid + ['id' => 2], $connection)['success']);
        });
    }
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL setup (' . get_class($error) . ")\n";
} finally {
    session_destroy();
    foreach (glob($temporary . '/*') as $file) { unlink($file); }
    rmdir($temporary);
}
echo "Results: {$passed} passed, {$failed} failed.\n";
ob_end_flush();
exit($failed === 0 ? 0 : 1);
