<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }
require_once dirname(__DIR__) . '/includes/admin-employees.php';

// Wymuszamy nieaktualny odczyt e-maila, by sprawdzić rzeczywistą odmowę UNIQUE.
class EmployeeRaceStatement extends PDOStatement
{
    protected function __construct() {}
    public function fetchColumn(int $column = 0): mixed
    {
        return str_contains($this->queryString, 'SELECT id FROM users WHERE email') ? false : parent::fetchColumn($column);
    }
}

function run_employee_http_tests(PDO $connection, array $sessions): void
{
    $base = '/admin-employees.php';
    $admin = $sessions['admin'];
    $snapshot = static fn(): array => [
        $connection->query('SELECT * FROM users ORDER BY id')->fetchAll(),
        $connection->query('SELECT * FROM employees ORDER BY id')->fetchAll(),
    ];
    $dependencies = static fn(): array => [
        $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll(),
        $connection->query('SELECT * FROM employee_services ORDER BY employee_id, service_id')->fetchAll(),
        $connection->query('SELECT * FROM employee_availability ORDER BY id')->fetchAll(),
    ];
    $original = $snapshot();
    $history = $dependencies();
    $token = http_csrf(http_request($admin, $base . '?action=create'));
    $valid = ['name' => 'Łukasz', 'surname' => 'O’Connor-Kowalski', 'email' => 'worker@example.test',
        'phone' => '+48 500-100-200', 'password' => 'Bezpieczne hasło 2026', 'password_confirmation' => 'Bezpieczne hasło 2026',
        'description' => '', 'active' => '1', '_csrf' => $token];
    $actions = ['create', 'edit', 'activate', 'deactivate'];
    foreach (['guest' => [], 'client' => $sessions['client'], 'employee' => $sessions['employee']] as $role => $cookies) {
        foreach ($actions as $action) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($cookies, $base . '?action=' . $action . '&id=1&role=admin', $method,
                    $method === 'POST' ? $valid + ['confirm' => '1', 'role' => 'admin'] : null);
                http_check($response['status'] === 303 && $response['body'] === ''
                    && $response['headers']['location'] === ($role === 'guest' ? '/login.php' : '/' . $role . '.php'), 'Employee authorization: ' . $role . ' ' . $action . ' ' . $method);
            }
        }
    }
    foreach ($actions as $action) {
        foreach ([null, 'invalid', ['nested']] as $badToken) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', array_replace($valid, ['_csrf' => $badToken, 'confirm' => '1']));
            http_check($response['status'] === 403 && http_safe($response) && $snapshot() === $original, 'Employee CSRF: ' . $action);
        }
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'PUT', $valid);
        http_check($response['status'] === 405 && $snapshot() === $original, 'Employee unsupported method: ' . $action);
    }
    $invalid = [
        ['name' => ''], ['surname' => ''], ['email' => ''], ['phone' => ''],
        ['name' => " \u{00A0}\u{2003}"], ['name' => str_repeat('ą', 51)], ['surname' => str_repeat('a', 81)],
        ['email' => str_repeat('a', 255)], ['email' => 'not-email'], ['email' => 'ADMIN@CUTTIME.TEST'],
        ['phone' => '012345678'], ['phone' => '+49 123456789'], ['phone' => str_repeat('1', 33)],
        ['name' => '<script>alert(1)</script>'], ['surname' => '<b>Anna</b>'], ['email' => '" autofocus onfocus="alert(1)'],
        ['name' => "Anna\0"], ['name' => "Anna\xFF"], ['surname' => "Anna\u{200B}"],
        ['description' => str_repeat('ą', 2001)], ['description' => "bad\0"], ['description' => "bad\xFF"],
    ];
    foreach (['name', 'surname', 'email', 'phone', 'description'] as $field) { $invalid[] = [$field => ['nested']]; }
    foreach (['create', 'edit&id=1'] as $action) {
        foreach ($invalid as $bad) {
            $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, $bad));
            http_check($response['status'] === 422 && http_safe($response) && $snapshot() === $original
                && !str_contains($response['body'], '<script>alert(1)</script>') && !str_contains($response['body'], 'value="' . $valid['password'] . '"'),
                'Employee validation: ' . $action . ' ' . array_key_first($bad));
        }
        $response = http_request($admin, $base . '?action=' . $action, 'POST', ['_csrf' => $token]);
        http_check($response['status'] === 422 && $snapshot() === $original, 'Empty employee form: ' . $action);
        $response = http_request($admin, $base . '?action=' . $action, 'POST', $valid + ['extra' => str_repeat('x', 33000)]);
        http_check($response['status'] === 413 && $snapshot() === $original, 'Oversized employee form: ' . $action);
    }
    foreach ([['active' => '2'], ['active' => ['array']], ['password' => 'short'], ['password' => str_repeat('x', 73)],
        ['password' => '   '], ['password' => "bad\0"], ['password' => ['array']], ['password_confirmation' => ['array']],
        ['password_confirmation' => 'Different password'], ['password_confirmation' => '']] as $bad) {
        $response = http_request($admin, $base . '?action=create', 'POST', array_replace($valid, $bad));
        http_check($response['status'] === 422 && http_safe($response) && $snapshot() === $original, 'Employee initial password/status validation: ' . array_key_first($bad));
    }
    foreach (['edit', 'activate', 'deactivate'] as $action) {
        foreach (['', '0', '-1', '01', '1e2', '1 OR 1=1', '4294967296', ['array']] as $id) {
            $response = http_request($admin, $base . '?' . http_build_query(['action' => $action, 'id' => $id]));
            http_check($response['status'] === 400 && http_safe($response), 'Invalid employee ID: ' . $action);
        }
        $response = http_request($admin, $base . '?action=' . $action . '&id=4294967295', 'POST', $valid + ['confirm' => '1']);
        http_check($response['status'] === 404 && $snapshot() === $original, 'Missing employee: ' . $action);
    }
    foreach (['?action=delete&id=1', '?action[]=edit', '?page[]=1', '?page=0'] as $query) {
        $response = http_request($admin, $base . $query);
        http_check($response['status'] === 400 && http_safe($response), 'Unsupported employee route: ' . $query);
    }
    $response = http_request($admin, $base, 'POST', $valid);
    http_check($response['status'] === 405 && $snapshot() === $original, 'List is read-only');
    $response = http_request($admin, $base . '?action=delete&id=1', 'POST', $valid + ['confirm' => '1']);
    http_check($response['status'] === 400 && $snapshot() === $original && $dependencies() === $history, 'No physical employee deletion');

    // Awarie drugiego zapisu nie mogą zostawić konta bez profilu ani częściowej edycji.
    $connection->exec("CREATE TRIGGER test_employee_insert BEFORE INSERT ON employees FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected failure'");
    try {
        $response = http_request($admin, $base . '?action=create', 'POST', $valid);
        http_check($response['status'] === 500 && http_safe($response) && $snapshot() === $original, 'Employee creation rolls back user when profile insert fails');
    } finally { $connection->exec('DROP TRIGGER test_employee_insert'); }
    $connection->exec("CREATE TRIGGER test_employee_update BEFORE UPDATE ON employees FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected failure'");
    try {
        foreach (['edit', 'deactivate'] as $action) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', $valid + ['confirm' => '1']);
            http_check($response['status'] === 500 && http_safe($response) && $snapshot() === $original, 'Employee rollback of both records: ' . $action);
        }
    } finally { $connection->exec('DROP TRIGGER test_employee_update'); }

    $payload = '<script>alert(1)</script> " </textarea><img src=x onerror=alert(1)>';
    $response = http_request($admin, $base . '?action=create', 'POST', array_replace($valid, [
        'email' => ' WORKER@EXAMPLE.TEST ', 'description' => $payload, 'role' => 'admin', 'user_id' => '1', 'id' => '1',
    ]));
    $row = $connection->query("SELECT e.id, e.user_id, e.description, e.active, u.role, u.password, u.email, u.phone FROM employees e JOIN users u ON u.id=e.user_id WHERE u.email='worker@example.test'")->fetch();
    http_check($response['status'] === 303 && $response['headers']['location'] === $base && $row !== false
        && $row['role'] === 'employee' && (int) $row['active'] === 1 && $row['phone'] === '+48500100200'
        && password_verify($valid['password'], $row['password']) && $row['description'] === $payload
        && http_user(1) === $original[0][0], 'Create linked employee with server role, normalized data and password_hash');
    $id = (int) $row['id'];
    $userId = (int) $row['user_id'];
    foreach (['', '?action=edit&id=' . $id, '?action=deactivate&id=' . $id] as $query) {
        $response = http_request($admin, $base . $query);
        http_check($response['status'] === 200 && http_safe($response) && !str_contains($response['body'], '<script>alert(1)</script>')
            && !str_contains($response['body'], $valid['password']), 'Employee views do not leak password or execute HTML: ' . $query);
        if ($query !== '?action=deactivate&id=' . $id) {
            http_check(str_contains($response['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Employee description escaped');
        }
    }
    $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', array_replace($valid, [
        'name' => 'Żaneta', 'description' => str_repeat('😀', 2000), 'id' => '1', 'user_id' => '1', 'role' => ['admin'], 'active' => '0',
        'password' => 'Replaced password', 'password_confirmation' => 'mismatch',
    ]));
    $edited = employee_find($id, $connection);
    http_check($response['status'] === 303 && $edited['name'] === 'Żaneta' && $edited['description'] === str_repeat('😀', 2000)
        && $edited['role'] === 'employee' && (int) $edited['active'] === 1 && (int) $edited['account_active'] === 1
        && http_user($userId)['password'] === $row['password'] && http_user(1) === $original[0][0], 'Employee edit ignores posted IDs, role, password and active');
    $editedInput = array_replace($valid, ['name' => str_repeat('ą', 50), 'surname' => str_repeat('a', 80), 'description' => " \u{00A0}"]);
    foreach ([1, 2] as $attempt) {
        $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', $editedInput);
        http_check($response['status'] === 303 && employee_find($id, $connection)['description'] === null, 'Employee boundary lengths, unchanged email and empty description: ' . $attempt);
    }
    $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EmployeeRaceStatement::class]);
    $before = $snapshot();
    try {
        foreach ([null, $id] as $target) {
            $result = employee_save(array_replace($valid, ['email' => 'admin@cuttime.test']), $target, $connection);
            http_check(!$result['success'] && isset($result['errors']['email']) && $snapshot() === $before && !$connection->inTransaction(), 'Concurrent email UNIQUE handled and rolled back');
        }
    } finally { $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]); }

    // Dezaktywacja istniejącego pracownika zachowuje WSZYSTKIE zależności.
    $worker = [];
    http_login($worker, 'anna.kowalska@cuttime.test', '/employee.php');
    $oldWorker = $worker;
    foreach (['activate', 'deactivate'] as $action) {
        $before = $snapshot();
        $response = http_request($admin, $base . '?action=' . $action . '&id=1');
        http_check($response['status'] === 200 && $snapshot() === $before, 'Employee GET is confirmation only: ' . $action);
        foreach ([null, '0', ['1']] as $confirm) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', ['_csrf' => $token, 'confirm' => $confirm]);
            http_check($response['status'] === 422 && $snapshot() === $before, 'Employee status requires explicit confirmation: ' . $action);
        }
    }
    foreach (['deactivate', 'deactivate'] as $action) {
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', ['_csrf' => $token, 'confirm' => '1', 'active' => '1', 'user_id' => '1']);
        $deactivated = employee_find(1, $connection);
        http_check($response['status'] === 303 && (int) $deactivated['active'] === 0 && (int) $deactivated['account_active'] === 0
            && $history === $dependencies() && http_user(1) === $original[0][0], 'Deactivation is atomic, idempotent and keeps history');
    }
    foreach (['/employee.php', '/profile.php', '/profile-edit.php', '/account.php'] as $path) {
        $response = http_request($oldWorker, $path);
        http_check($response['status'] === 303 && $response['headers']['location'] === '/login.php', 'Deactivated employee session revoked: ' . $path);
    }
    $login = http_request($worker, '/login.php');
    $response = http_request($worker, '/login.php', 'POST', ['_csrf' => http_csrf($login), 'email' => 'anna.kowalska@cuttime.test', 'password' => 'password']);
    http_check($response['status'] === 422 && http_safe($response), 'Deactivated employee cannot log in');
    foreach (['activate', 'activate'] as $action) {
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', ['_csrf' => $token, 'confirm' => '1']);
        $reactivated = employee_find(1, $connection);
        http_check($response['status'] === 303 && (int) $reactivated['active'] === 1 && (int) $reactivated['account_active'] === 1
            && $history === $dependencies(), 'Activation preserves existing dependencies and password');
    }
    http_login($worker, 'anna.kowalska@cuttime.test', '/employee.php');

    // Rozbieżne stare statusy są widoczne; jawna akcja synchronizuje oba.
    $connection->exec('UPDATE employees SET active=0 WHERE id=1');
    $response = http_request($admin, $base);
    http_check(str_contains($response['body'], 'Profil: nieaktywny') && str_contains($response['body'], 'Konto: aktywne'), 'Both existing active flags are visible');
    employee_set_active(1, true, $connection);
    $connection->exec('INSERT INTO employees (user_id) VALUES (4)');
    $wrongRoleId = (int) $connection->lastInsertId();
    try {
        $before = $snapshot();
        foreach (['edit', 'activate', 'deactivate'] as $action) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=' . $wrongRoleId, 'POST', $valid + ['confirm' => '1']);
            http_check($response['status'] === 409 && http_safe($response) && $snapshot() === $before, 'Wrong database role blocks employee operation: ' . $action);
        }
        $result = employee_set_active($wrongRoleId, false, $connection);
        http_check(!$result['success'] && $result['status'] === 409 && !$connection->inTransaction(), 'Domain rechecks role under transaction lock');
    } finally { $connection->exec('DELETE FROM employees WHERE id=' . $wrongRoleId); }

    // Nowe konto nieaktywne; nie powstają automatyczne usługi ani dostępność.
    $response = http_request($admin, $base . '?action=create', 'POST', array_replace($valid, ['email' => 'inactive-worker@example.test', 'active' => '0', 'role' => 'administrator']));
    $inactive = $connection->query("SELECT e.active, u.active AS account_active FROM employees e JOIN users u ON u.id=e.user_id WHERE u.email='inactive-worker@example.test'")->fetch();
    http_check($response['status'] === 303 && (int) $inactive['active'] === 0 && (int) $inactive['account_active'] === 0 && $history === $dependencies(), 'Create inactive employee without touching dependencies');
    // Duży zbiór: uzupełnienie wyłącznie we własnym schemacie testowym.
    $insert = $connection->prepare("INSERT INTO users (name,surname,email,phone,password,role) VALUES ('Anna','Testowa',:email,'500100100',:password,'employee')");
    $profile = $connection->prepare('INSERT INTO employees (user_id) VALUES (:user_id)');
    for ($i=0; $i<20; $i++) {
        $insert->execute(['email' => 'page-worker-' . $i . '@example.test', 'password' => $row['password']]);
        $profile->execute(['user_id' => $connection->lastInsertId()]);
    }
    foreach ([1 => 20, 2 => 4] as $page => $count) {
        $listing = employee_list($page, $connection);
        $response = http_request($admin, $base . '?page=' . $page);
        http_check(count($listing['rows']) === $count && $listing['total'] === 24 && $response['status'] === 200
            && str_contains($response['body'], 'Strony pracowników'), 'Employee pagination: ' . $page);
    }
    $missing = employee_save($valid, 4294967295, $connection);
    http_check(!$missing['success'] && $missing['status'] === 404 && !$connection->inTransaction(), 'Missing employee after pre-read is safe');
    // Usuwamy tylko dodane przez ten test profile/konta; przywracamy dane bazowe dla kolejnych testów.
    $added = $connection->query('SELECT id, user_id FROM employees WHERE id NOT IN (1,2)')->fetchAll();
    $deleteProfile = $connection->prepare('DELETE FROM employees WHERE id=:id');
    $deleteUser = $connection->prepare('DELETE FROM users WHERE id=:id');
    foreach ($added as $item) {
        $deleteProfile->execute(['id' => $item['id']]);
        $deleteUser->execute(['id' => $item['user_id']]);
    }
    http_check($snapshot() === $original && $dependencies() === $history, 'Employee tests preserve all original records and dependencies');
}
