<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/registration.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

$sessionDirectory = sys_get_temp_dir() . '/cuttime-registration-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.save_path', $sessionDirectory);
start_secure_session();
$failed = 0;
$passed = 0;

function registration_check(bool $condition, string $message = 'Unexpected result'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function registration_test(string $name, callable $test): void
{
    global $failed, $passed;
    try {
        $test();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        // Nie drukujemy potencjalnych danych dostępowych z wyjątków PDO.
        echo "FAIL {$name} (" . get_class($error) . ")\n";
    }
}

$valid = [
    'name' => 'Łukasz', 'surname' => "O’Connor-Kowalski", 'email' => 'client@example.test',
    'phone' => '+48 500-100-200', 'password' => 'Moje hasło 2026!',
    'password_confirmation' => 'Moje hasło 2026!',
];

try {
    registration_test('Valid Unicode names and normalized email/phone', static function () use ($valid): void {
        $result = validate_registration(array_replace($valid, ['email' => '  CLIENT@EXAMPLE.TEST  ']));
        registration_check($result['errors'] === []);
        registration_check($result['values']['email'] === 'client@example.test');
        registration_check($result['values']['phone'] === '+48500100200');
    });
    registration_test('Missing, empty and whitespace-only fields', static function () use ($valid): void {
        registration_check(count(validate_registration([])['errors']) === 6);
        foreach (['', '   ', "\t\n", "\u{00A0}\u{2003}"] as $empty) {
            $result = register_client(array_fill_keys(array_keys($valid), $empty));
            registration_check(!$result['success'] && count($result['errors']) === 6);
        }
    });
    registration_test('Email format', static function () use ($valid): void {
        foreach (['not-an-email', 'a@', 'a b@example.test', '<script>alert(1)</script>'] as $email) {
            registration_check(isset(validate_registration(array_replace($valid, ['email' => $email]))['errors']['email']));
        }
    });
    registration_test('Phone format', static function () use ($valid): void {
        foreach (['123', '000000000', '+44 500100200', '500<script>', '5001002000'] as $phone) {
            registration_check(isset(validate_registration(array_replace($valid, ['phone' => $phone]))['errors']['phone']));
        }
    });
    registration_test('Mismatched and short passwords', static function () use ($valid): void {
        registration_check(isset(validate_registration(array_replace($valid, ['password_confirmation' => 'inne hasło 2026']))['errors']['password_confirmation']));
        registration_check(isset(validate_registration(array_replace($valid, ['password' => 'short']))['errors']['password']));
    });
    registration_test('Long payloads and bcrypt byte boundary', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            registration_check(isset(validate_registration(array_replace($valid, [$field => str_repeat('a', 100000)]))['errors'][$field]));
        }
        $longUnicode = str_repeat('ą', 37);
        registration_check(isset(validate_registration(array_replace($valid, ['password' => $longUnicode]))['errors']['password']));
        $boundary = str_repeat('a', 72);
        registration_check(validate_registration(array_replace($valid, ['password' => $boundary, 'password_confirmation' => $boundary]))['errors'] === []);
    });
    registration_test('Name lengths match database columns', static function () use ($valid): void {
        registration_check(validate_registration(array_replace($valid, ['name' => str_repeat('ą', 50), 'surname' => str_repeat('Ż', 80)]))['errors'] === []);
        registration_check(isset(validate_registration(array_replace($valid, ['name' => str_repeat('ą', 51)]))['errors']['name']));
        registration_check(isset(validate_registration(array_replace($valid, ['surname' => str_repeat('Ż', 81)]))['errors']['surname']));
    });
    registration_test('Arrays, objects, invalid UTF-8 and NUL', static function () use ($valid): void {
        foreach (array_keys($valid) as $field) {
            foreach ([[], ['nested' => 'value'], new stdClass(), "bad\xFF", "bad\0value", 123, false] as $bad) {
                registration_check(isset(validate_registration(array_replace($valid, [$field => $bad]))['errors'][$field]));
            }
        }
    });
    registration_test('XSS is rejected and redisplayed only as escaped text', static function () use ($valid): void {
        $attack = '<script>alert(1)</script>';
        $result = validate_registration(array_replace($valid, ['name' => $attack, 'email' => '" autofocus onfocus="alert(1)']));
        registration_check(isset($result['errors']['name'], $result['errors']['email']));
        registration_check(e($result['values']['name']) === '&lt;script&gt;alert(1)&lt;/script&gt;');
        registration_check(!str_contains(e($result['values']['email']), '"'));
    });
    registration_test('Passwords are preserved verbatim and absent from safe values', static function () use ($valid): void {
        $password = '  <script> & hasło  ';
        $result = validate_registration(array_replace($valid, ['password' => $password, 'password_confirmation' => $password]));
        registration_check($result['errors'] === [] && $result['password'] === $password);
        registration_check(!isset($result['values']['password'], $result['values']['password_confirmation']));
    });
    registration_test('CSRF requires session-bound scalar token', static function (): void {
        foreach ([null, [], '', str_repeat('a', 64)] as $token) {
            registration_check(!csrf_is_valid($token));
        }
        $token = csrf_token();
        registration_check(strlen($token) === 64 && csrf_is_valid($token) && csrf_token() === $token);
        unset($_SESSION['_csrf_token']);
        registration_check(!csrf_is_valid($token));
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
        // Tabela tymczasowa z rzeczywistego SQL zasłania users tylko w tej sesji PDO.
        // Nie wykonujemy DROP/DELETE na istniejącej bazie ani importu jej danych.
        $sql = file_get_contents(dirname(__DIR__) . '/database/database.sql');
        preg_match('/CREATE TABLE users \(.*?\) ENGINE=.*?;/s', $sql, $match);
        $connection->exec(str_replace('CREATE TABLE users', 'CREATE TEMPORARY TABLE users', $match[0]));

        registration_test('Insert, hash verification, client role and ignored active=1', static function () use ($connection, $valid): void {
            $result = register_client($valid + ['role' => 'administrator', 'active' => '1'], $connection);
            registration_check($result['success'] && $result['errors'] === []);
            $user = $connection->query('SELECT * FROM users')->fetch();
            registration_check($user['role'] === 'client' && (int) $user['active'] === 1);
            registration_check($user['password'] !== $valid['password'] && password_verify($valid['password'], $user['password']));
            registration_check(!array_key_exists('password_confirmation', $user));
            registration_check(!array_key_exists('password', $result));
        });
        registration_test('Existing email, case-insensitivity and no second insert', static function () use ($connection, $valid): void {
            $result = register_client(array_replace($valid, ['email' => 'CLIENT@EXAMPLE.TEST']), $connection);
            registration_check(!$result['success'] && isset($result['errors']['email']));
            registration_check((int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
        });
        registration_test('Posted active=0 and admin role cannot change server policy', static function () use ($connection, $valid): void {
            $result = register_client(array_replace($valid, ['email' => 'second@example.test', 'role' => 'admin', 'active' => '0']), $connection);
            registration_check($result['success']);
            $user = $connection->query('SELECT role, active FROM users ORDER BY id DESC LIMIT 1')->fetch();
            registration_check($user['role'] === 'client' && (int) $user['active'] === 1);
        });

        // Wymuszamy dokładny scenariusz wyścigu: INSERT trafia w UNIQUE mimo wcześniejszego SELECT.
        $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RegistrationRaceStatement::class]);
        registration_test('Duplicate during INSERT returns a friendly email error', static function () use ($connection, $valid): void {
            $result = register_client($valid, $connection);
            registration_check(!$result['success'] && isset($result['errors']['email']));
        });
        $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        registration_test('Unexpected database error is not disguised as duplicate email', static function () use ($connection, $valid): void {
            $connection->exec('DROP TEMPORARY TABLE users');
            $connection->exec('CREATE TEMPORARY TABLE users (id INT)');
            try {
                register_client($valid, $connection);
            } catch (PDOException $expected) {
                return; // Błąd trafi do istniejącego handlera aplikacji.
            }
            throw new RuntimeException('Expected database exception');
        });
    }
} finally {
    session_destroy();
    foreach (glob($sessionDirectory . '/*') as $file) {
        unlink($file);
    }
    rmdir($sessionDirectory);
}

echo "Result: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);

class RegistrationRaceStatement extends PDOStatement
{
    protected function __construct() {}

    public function fetchColumn(int $column = 0): mixed
    {
        // INSERT nadal wykonuje rzeczywisty sterownik MariaDB/MySQL.
        return false;
    }
}
