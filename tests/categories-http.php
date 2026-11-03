<?php
declare(strict_types=1);
// Korzysta z infrastruktury tests/http.php i jego własnego, losowego schematu.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }
require_once dirname(__DIR__) . '/includes/admin-categories.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

// Pomijamy wstępny odczyt zależności/duplikatu, by sprawdzić prawdziwy błąd FK/UNIQUE.
class CategoryRaceStatement extends PDOStatement
{
    protected function __construct() {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (is_array($row) && array_key_exists('services_count', $row)) { $row['services_count'] = 0; }
        return $row;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        return str_contains($this->queryString, 'WHERE name = :name') ? false : parent::fetchColumn($column);
    }
}

function run_category_http_tests(PDO $connection, array $sessions): void
{
    $base = '/admin-categories.php';
    $admin = $sessions['admin'];
    $snapshot = static fn(): array => $connection->query('SELECT * FROM service_categories ORDER BY id')->fetchAll();
    $original = $snapshot();
    $originalServices = $connection->query('SELECT * FROM services ORDER BY id')->fetchAll();
    $token = http_csrf(http_request($admin, $base . '?action=create'));
    $valid = ['name' => 'Nowa kategoria testowa', 'description' => '', 'active' => '1', '_csrf' => $token];

    foreach (['guest' => [], 'client' => $sessions['client'], 'employee' => $sessions['employee']] as $role => $cookies) {
        foreach (['create', 'edit', 'delete'] as $action) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($cookies, $base . '?action=' . $action . '&id=1&role=admin', $method,
                    $method === 'POST' ? $valid + ['role' => 'admin', 'confirm' => '1'] : null);
                http_check($response['status'] === 303 && $response['body'] === ''
                    && $response['headers']['location'] === ($role === 'guest' ? '/login.php' : '/' . $role . '.php'),
                    'Category access denied: ' . $role . ' ' . $action . ' ' . $method);
            }
        }
    }
    http_check($snapshot() === $original, 'Unauthorized category requests do not write');
    foreach (['create', 'edit&id=1', 'delete&id=1'] as $action) {
        foreach ([null, 'bad', ['nested']] as $badToken) {
            $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, ['_csrf' => $badToken, 'confirm' => '1']));
            http_check($response['status'] === 403 && http_safe($response) && $snapshot() === $original, 'Category CSRF rejected: ' . $action);
        }
    }
    foreach (['create', 'edit&id=1'] as $action) {
        foreach ([
            ['name' => ''], ['name' => " \u{00A0}\u{2003}"], ['name' => ['array']],
            ['description' => ['array']], ['active' => ['array']], ['active' => '2'],
            ['name' => str_repeat('ą', 101)], ['description' => str_repeat('ą', 2001)],
            ['name' => "bad\0name"], ['name' => "bad\xFF"], ['description' => "bad\x01"],
        ] as $bad) {
            $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, $bad));
            http_check($response['status'] === 422 && http_safe($response) && $snapshot() === $original, 'Category invalid form: ' . $action . ' ' . array_key_first($bad));
        }
        $response = http_request($admin, $base . '?action=' . $action, 'POST', ['_csrf' => $token]);
        http_check($response['status'] === 422 && $snapshot() === $original, 'Category required fields: ' . $action);
    }
    foreach (['create', 'edit&id=1', 'delete&id=1'] as $action) {
        $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, ['description' => str_repeat('x', 40000), 'confirm' => '1']));
        http_check($response['status'] === 413 && $snapshot() === $original, 'Category request size limit: ' . $action);
    }
    foreach (['', '0', '-1', '01', '1e0', '1.0', '4294967296', str_repeat('9', 100), '1 OR 1=1', ['bad']] as $badId) {
        foreach (['edit', 'delete'] as $action) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($admin, $base . '?' . http_build_query(['action' => $action, 'id' => $badId]), $method,
                    $method === 'POST' ? $valid + ['confirm' => '1'] : null);
                http_check($response['status'] === 400 && http_safe($response) && $snapshot() === $original, 'Category rejects malformed ID: ' . $action . ' ' . $method);
            }
        }
    }
    foreach (['?action=unknown', '?action[]=edit', '?page[]=1', '?page=0'] as $query) {
        http_check(http_request($admin, $base . $query)['status'] === 400, 'Category rejects routing input: ' . $query);
    }
    foreach (['edit', 'delete'] as $action) {
        foreach (['GET', 'POST'] as $method) {
            $response = http_request($admin, $base . '?action=' . $action . '&id=4294967295', $method, $method === 'POST' ? $valid + ['confirm' => '1'] : null);
            http_check($response['status'] === 404 && http_safe($response), 'Category missing record: ' . $action . ' ' . $method);
        }
    }
    foreach (['', '?action=create', '?action=edit&id=1', '?action=delete&id=1'] as $query) {
        $response = http_request($admin, $base . $query, 'DELETE');
        http_check($response['status'] === 405 && $snapshot() === $original, 'Category unsupported method: ' . $query);
    }
    http_check(http_request($admin, $base, 'POST', $valid)['status'] === 405, 'Category list is read-only');

    $name = '<img src=x onerror=alert(1)> & Łódź';
    $description = "</textarea><script>alert(1)</script>\nO'Connor — pielęgnacja";
    $response = http_request($admin, $base . '?action=create', 'POST', array_replace($valid,
        ['name' => $name, 'description' => $description, 'active' => '0', 'id' => '1', 'role' => 'admin']));
    $created = $connection->query('SELECT * FROM service_categories ORDER BY id DESC LIMIT 1')->fetch();
    $id = (int) $created['id'];
    http_check($response['status'] === 303 && $response['headers']['location'] === $base
        && $created['name'] === $name && $created['description'] === $description && (int) $created['active'] === 0
        && $id > 4 && category_find(1, $connection)['name'] === $original[0]['name'], 'Create category stores literal text and ignores extra ID');
    $response = http_request($admin, $base);
    http_check(str_contains($response['body'], 'Dodano kategorię.') && !str_contains(http_request($admin, $base)['body'], 'Dodano kategorię.'), 'Category create flash is one-time after redirect');
    foreach (['', '?action=edit&id=' . $id, '?action=delete&id=' . $id] as $query) {
        $response = http_request($admin, $base . $query);
        http_check($response['status'] === 200 && str_contains($response['body'], e($name))
            && !str_contains($response['body'], '<img src=x') && !str_contains($response['body'], '<script>alert(1)</script>'), 'Category stored XSS escaped: ' . $query);
    }
    $response = http_request($admin, $base . '?action=delete&id=' . $id);
    http_check(str_contains($response['body'], 'name="confirm"') && category_find($id, $connection) !== null, 'GET delete displays confirmation and does not delete');
    $response = http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token]);
    http_check($response['status'] === 422 && category_find($id, $connection) !== null, 'Delete requires explicit confirmation');
    foreach (['create', 'edit&id=' . $id] as $action) {
        $response = http_request($admin, $base . '?action=' . $action, 'POST', array_replace($valid, ['name' => 'STRZYŻENIE']));
        http_check($response['status'] === 422 && str_contains($response['body'], 'Kategoria o tej nazwie już istnieje.'), 'Category duplicate name respects database collation: ' . $action);
    }
    $edited = array_replace($valid, ['name' => "  Fryzury O'Connor  ", 'description' => " \u{00A0}", 'active' => '1', 'id' => '1']);
    foreach ([1, 2] as $attempt) {
        $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', $edited);
        $row = category_find($id, $connection);
        http_check($response['status'] === 303 && $row['name'] === "Fryzury O'Connor" && $row['description'] === null
            && (int) $row['active'] === 1 && category_find(1, $connection)['name'] === $original[0]['name'], 'Category update, unchanged save, own name and ignored POST ID: ' . $attempt);
    }
    $limits = array_replace($valid, ['name' => str_repeat('ą', 100), 'description' => str_repeat('😀', 2000)]);
    $response = http_request($admin, $base . '?action=edit&id=' . $id, 'POST', $limits);
    http_check($response['status'] === 303 && category_find($id, $connection)['description'] === $limits['description'], 'Category accepts Unicode character limits including four-byte text');

    $response = http_request($admin, $base . '?action=delete&id=1', 'POST', ['_csrf' => $token, 'confirm' => '1']);
    http_check($response['status'] === 409 && category_find(1, $connection) !== null
        && $connection->query('SELECT * FROM services ORDER BY id')->fetchAll() === $originalServices, 'Category with services cannot be deleted');
    // Nowa nieaktywna usługa przypisana już po wyświetleniu potwierdzenia.
    http_request($admin, $base . '?action=delete&id=' . $id);
    $statement = $connection->prepare('INSERT INTO services (category_id, name, duration, price, active) VALUES (:id, :name, 30, 10, 0)');
    $statement->execute(['id' => $id, 'name' => 'Test zależności']);
    $serviceId = (int) $connection->lastInsertId();
    $response = http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1']);
    http_check($response['status'] === 409 && category_find($id, $connection) !== null, 'New inactive service after confirmation blocks deletion');
    $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CategoryRaceStatement::class]);
    try {
        $result = category_delete($id, $connection);
        http_check(!$result['success'] && $result['status'] === 409, 'Actual FK error after stale dependency read becomes friendly conflict');
        foreach ([null, $id] as $duplicateId) {
            $result = category_save(['name' => 'Strzyżenie', 'description' => '', 'active' => '1'], $duplicateId, $connection);
            http_check(!$result['success'] && isset($result['errors']['name']), 'Actual UNIQUE error after stale duplicate read is handled');
        }
    } finally {
        $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }
    $statement = $connection->prepare('DELETE FROM services WHERE id = :id');
    $statement->execute(['id' => $serviceId]);
    $response = http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1']);
    http_check($response['status'] === 303 && category_find($id, $connection) === null, 'Confirmed category deletion succeeds');
    http_check(http_request($admin, $base . '?action=delete&id=' . $id, 'POST', ['_csrf' => $token, 'confirm' => '1'])['status'] === 404, 'Repeated deletion is safely rejected');

    $statement = $connection->prepare('INSERT INTO service_categories (name) VALUES (:name)');
    $paginationIds = [];
    for ($i = 0; $i < 21; $i++) {
        $statement->execute(['name' => sprintf('Test paginacji %02d', $i)]);
        $paginationIds[] = (int) $connection->lastInsertId();
    }
    foreach ([1 => 20, 2 => 5, 999 => 5] as $page => $count) {
        $response = http_request($admin, $base . '?page=' . $page);
        http_check($response['status'] === 200 && substr_count($response['body'], '<th scope="row">') === $count, 'Category pagination bounds: ' . $page);
    }
    $statement = $connection->prepare('DELETE FROM service_categories WHERE id = :id');
    foreach ($paginationIds as $paginationId) { $statement->execute(['id' => $paginationId]); }
    http_check($snapshot() === $original && $connection->query('SELECT * FROM services ORDER BY id')->fetchAll() === $originalServices, 'Category tests preserve original categories and services');
}
