<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }
require_once dirname(__DIR__) . '/includes/admin-services.php';

class ServiceRaceStatement extends PDOStatement
{
    protected function __construct() {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (is_array($row) && array_key_exists('reservations_count', $row)) { $row['reservations_count'] = 0; }
        return $row;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        return str_contains($this->queryString, 'SELECT id FROM service_categories WHERE id') ? 1 : parent::fetchColumn($column);
    }
}

function run_service_http_tests(PDO $connection, array $sessions): void
{
    $base = '/admin-services.php';
    $admin = $sessions['admin'];
    $snapshot = static fn(): array => $connection->query('SELECT * FROM services ORDER BY id')->fetchAll();
    $original = $snapshot();
    $reservations = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $assignments = $connection->query('SELECT * FROM employee_services ORDER BY employee_id, service_id')->fetchAll();
    $employees = $connection->query('SELECT * FROM employees ORDER BY id')->fetchAll();
    $token = http_csrf(http_request($admin, $base . '?action=create'));
    $valid = ['name' => 'Usługa testowa', 'description' => '', 'category_id' => '1', 'price' => '12,50', 'duration' => '30', 'active' => '1', '_csrf' => $token];
    $actions = ['create', 'edit', 'delete', 'activate', 'deactivate'];
    foreach (['guest' => [], 'client' => $sessions['client'], 'employee' => $sessions['employee']] as $role => $cookies) {
        foreach ($actions as $action) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($cookies, $base . '?action=' . $action . '&id=1&role=admin', $method,
                    $method === 'POST' ? $valid + ['confirm' => '1', 'role' => 'admin'] : null);
                http_check($response['status'] === 303 && $response['body'] === ''
                    && $response['headers']['location'] === ($role === 'guest' ? '/login.php' : '/' . $role . '.php'), 'Service authorization: ' . $role . ' ' . $action . ' ' . $method);
            }
        }
    }
    foreach ($actions as $action) {
        foreach ([null, 'invalid', ['nested']] as $badToken) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', array_replace($valid, ['_csrf' => $badToken, 'confirm' => '1']));
            http_check($response['status'] === 403 && http_safe($response) && $snapshot() === $original, 'Service CSRF: ' . $action);
        }
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'PUT', $valid);
        http_check($response['status'] === 405 && $snapshot() === $original, 'Service unsupported method: ' . $action);
    }
    foreach (['create', 'edit&id=1'] as $action) {
        foreach ([
            ['name' => ''], ['name' => " \u{00A0}\u{2003}"], ['name' => ['array']], ['description' => ['array']],
            ['name' => str_repeat('ą', 151)], ['description' => str_repeat('ą', 2001)], ['name' => "bad\0"], ['name' => "bad\xFF"],
            ['category_id' => ''], ['category_id' => ['array']], ['category_id' => '4294967295'], ['category_id' => '1 OR 1=1'],
            ['price' => ''], ['price' => '-0.01'], ['price' => '1.001'], ['price' => '1e2'], ['price' => 'NaN'], ['price' => 'INF'],
            ['price' => '100000000'], ['price' => '1,234.50'], ['price' => ['array']], ['price' => '00'],
            ['duration' => ''], ['duration' => '0'], ['duration' => '-1'], ['duration' => '1.5'], ['duration' => '1e2'],
            ['duration' => '1441'], ['duration' => str_repeat('9', 100)], ['duration' => ['array']], ['active' => '2'], ['active' => ['array']],
        ] as $bad) {
            $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, $bad));
            http_check($response['status'] === 422 && http_safe($response) && $snapshot() === $original, 'Service validation: ' . $action . ' ' . array_key_first($bad));
        }
        $response = http_request($admin, $base . '?action=' . $action, 'POST', ['_csrf' => $token]);
        http_check($response['status'] === 422 && $snapshot() === $original, 'Service required fields: ' . $action);
    }
    foreach (['edit', 'delete', 'activate', 'deactivate'] as $action) {
        foreach (['', '0', '-1', '01', '1e2', '4294967296', ['nested']] as $badId) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($admin, $base . '?' . http_build_query(['action' => $action, 'id' => $badId]), $method, $method === 'POST' ? $valid + ['confirm' => '1'] : null);
                http_check($response['status'] === 400 && http_safe($response) && $snapshot() === $original, 'Service invalid ID: ' . $action . ' ' . $method);
            }
        }
        http_check(http_request($admin, $base . '?action=' . $action . '&id=4294967295')['status'] === 404, 'Service missing record: ' . $action);
    }
    foreach (['?action[]=delete', '?action=unknown', '?page[]=1', '?page=0'] as $query) {
        http_check(http_request($admin, $base . $query)['status'] === 400, 'Service invalid route/page: ' . $query);
    }
    http_check(http_request($admin, $base, 'POST', $valid)['status'] === 405, 'Service list cannot mutate');
    foreach ($actions as $action) {
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', $valid + ['extra' => str_repeat('x', 40000)]);
        http_check($response['status'] === 413 && $snapshot() === $original, 'Service body size limit: ' . $action);
    }

    $name = '<img src=x onerror=alert(1)> & Łódź';
    $description = "</textarea><script>alert(1)</script>\nO'Connor";
    $response = http_request($admin, $base . '?action=create', 'POST', array_replace($valid, ['name' => $name, 'description' => $description, 'id' => '1', 'active' => '0']));
    $created = $connection->query('SELECT * FROM services ORDER BY id DESC LIMIT 1')->fetch();
    $id = (int) $created['id'];
    http_check($response['status'] === 303 && $response['headers']['location'] === $base && $id > 6
        && $created['price'] === '12.50' && (int) $created['duration'] === 30 && (int) $created['active'] === 0
        && $created['name'] === $name && $created['description'] === $description, 'Service create stores exact decimal, text and explicit activity');
    http_check(str_contains(http_request($admin, $base)['body'], 'Dodano usługę.')
        && !str_contains(http_request($admin, $base)['body'], 'Dodano usługę.'), 'Service success flash is one-time');
    foreach (['', '?action=edit&id=' . $id, '?action=delete&id=' . $id, '?action=activate&id=' . $id] as $query) {
        $response = http_request($admin, $base . $query);
        http_check($response['status'] === 200 && str_contains($response['body'], e($name))
            && !str_contains($response['body'], '<img src=x') && !str_contains($response['body'], '<script>alert(1)</script>'), 'Service XSS escaped: ' . $query);
    }
    foreach (['0' => '0.00', '0,01' => '0.01', ' 17.5 ' => '17.50', '99999999.99' => '99999999.99'] as $price => $expected) {
        $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', array_replace($valid, ['price' => (string) $price, 'duration' => '1440', 'id' => '1']));
        http_check($response['status'] === 303 && service_find($id, $connection)['price'] === $expected
            && (int) service_find($id, $connection)['duration'] === 1440 && $snapshot()[0] === $original[0], 'Exact price and duration boundary: ' . $expected);
    }
    $connection->exec('UPDATE service_categories SET active = 0 WHERE id = 3');
    $edited = array_replace($valid, ['name' => str_repeat('ą', 150), 'description' => str_repeat('😀', 2000), 'duration' => '1', 'category_id' => '3']);
    foreach ([1, 2] as $attempt) {
        $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', $edited);
        $row = service_find($id, $connection);
        http_check($response['status'] === 303 && (int) $row['category_id'] === 3 && $row['name'] === $edited['name'] && $row['description'] === $edited['description']
            && (int) $row['duration'] === 1, 'Service Unicode, inactive category and unchanged save: ' . $attempt);
    }
    $response = http_request($admin, $base . '?action=edit&id=' . $id);
    http_check(str_contains($response['body'], 'Stylizacja (nieaktywna)'), 'Inactive category is explicitly labelled');
    $connection->exec('UPDATE service_categories SET active = 1 WHERE id = 3');

    // Rezerwacje wszystkich statusów blokują fizyczne usunięcie, także anulowane.
    foreach ([1, 6] as $reservedId) {
        $response = http_request($admin, $base . '?action=delete&id=' . $reservedId, 'POST', ['_csrf' => $token, 'confirm' => '1']);
        http_check($response['status'] === 409 && str_contains($response['body'], 'Przejdź do dezaktywacji'), 'Reserved service offers deactivation: ' . $reservedId);
    }
    foreach (['delete', 'activate', 'deactivate'] as $action) {
        $before = service_find($id, $connection);
        $response = http_request($admin, $base . '?action=' . $action . '&id=' . $id);
        http_check($response['status'] === 200 && $before === service_find($id, $connection), 'GET service operation only confirms: ' . $action);
        $response = http_request($admin, $base . '?action=' . $action . '&id=' . $id, 'POST', ['_csrf' => $token]);
        http_check($response['status'] === 422 && $before === service_find($id, $connection), 'Service operation needs confirmation: ' . $action);
    }
    foreach (['deactivate', 'deactivate', 'activate'] as $action) {
        $response = http_request($admin, $base . '?action=' . $action . '&id=1', 'POST', ['_csrf' => $token, 'confirm' => '1', 'active' => '999', 'id' => $id]);
        $row = service_find(1, $connection);
        http_check($response['status'] === 303 && (int) $row['active'] === ($action === 'activate' ? 1 : 0)
            && $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $reservations
            && $connection->query('SELECT * FROM employee_services ORDER BY employee_id, service_id')->fetchAll() === $assignments, 'Service activity is explicit, repeatable and preserves relations: ' . $action);
    }
    $response = http_request($admin, $base . '?action=edit&id=1', 'POST', array_replace($valid, ['duration' => '90', 'price' => '99.99', 'active' => '0']));
    http_check($response['status'] === 303 && $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $reservations, 'Editing reserved service never shifts existing appointments');
    $restore = $connection->prepare('UPDATE services SET category_id=:category_id, name=:name, description=:description, duration=:duration, price=:price, active=:active WHERE id=:id');
    $restore->execute($original[0]);

    $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ServiceRaceStatement::class]);
    try {
        $result = service_delete(1, $connection);
        http_check(!$result['success'] && $result['status'] === 409, 'Actual reservation FK error is handled after stale read');
        foreach ([null, $id] as $target) {
            $result = service_save(array_replace($valid, ['category_id' => '4294967295']), $target, $connection);
            http_check(!$result['success'] && isset($result['errors']['category_id']), 'Actual missing-category FK error becomes field validation');
        }
    } finally {
        $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }
    $statement = $connection->prepare('INSERT INTO employee_services (employee_id, service_id) VALUES (1, :id)');
    $statement->execute(['id' => $id]);
    $response = http_request($admin, $base . '?action=delete&id=' . $id);
    http_check(str_contains($response['body'], 'przypisania tej usługi do pracowników: <strong>1</strong>'), 'Delete confirmation discloses assignment removal');
    $statement = $connection->prepare("INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time) VALUES (4, 1, :id, '2026-11-09', '09:00:00', '09:01:00')");
    $statement->execute(['id' => $id]);
    $reservationId = (int) $connection->lastInsertId();
    $response = http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1']);
    http_check($response['status'] === 409 && (int) service_find($id, $connection)['employees_count'] === 1, 'Reservation added after confirmation prevents deletion and retains assignment');
    $statement = $connection->prepare('DELETE FROM reservations WHERE id = :id');
    $statement->execute(['id' => $reservationId]);
    $response = http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1']);
    http_check($response['status'] === 303 && service_find($id, $connection) === null
        && $connection->query('SELECT * FROM employee_services ORDER BY employee_id, service_id')->fetchAll() === $assignments
        && $connection->query('SELECT * FROM employees ORDER BY id')->fetchAll() === $employees, 'Service deletion cascades only assignments and preserves employees');
    http_check(http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1'])['status'] === 404, 'Repeated service deletion is safe');
    $statement = $connection->prepare('INSERT INTO services (category_id, name, price, duration) VALUES (1, :name, 0, 1)');
    $ids = [];
    for ($i = 0; $i < 21; $i++) { $statement->execute(['name' => 'Test paginacji ' . $i]); $ids[] = (int) $connection->lastInsertId(); }
    foreach ([1 => 20, 2 => 7, 999 => 7] as $page => $count) {
        $response = http_request($admin, $base . '?page=' . $page);
        http_check($response['status'] === 200 && substr_count($response['body'], '<th scope="row">') === $count, 'Service pagination: ' . $page);
    }
    $statement = $connection->prepare('DELETE FROM services WHERE id = :id');
    foreach ($ids as $bulkId) { $statement->execute(['id' => $bulkId]); }
    http_check($snapshot() === $original && $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $reservations, 'Service tests preserve original services and reservation history');
}
