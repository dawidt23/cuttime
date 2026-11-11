<?php
declare(strict_types=1);

/** Wywoływane przez tests/http.php wyłącznie na jego izolowanej bazie. */
function run_offer_http_tests(PDO $connection, array $sessions): void
{
    $snapshot = static function () use ($connection): array {
        $rows = [];
        foreach (['users', 'service_categories', 'services', 'employees', 'employee_services', 'employee_availability', 'reservations'] as $table) {
            $rows[$table] = $connection->query('SELECT * FROM ' . $table)->fetchAll();
        }
        return $rows;
    };
    $original = $snapshot();
    $paths = ['/services.php', '/service.php?id=1', '/booking.php?service_id=1'];
    $guest = [];
    foreach (['guest' => $guest] + $sessions as $role => $cookies) {
        foreach ($paths as $path) {
            $response = http_request($cookies, $path);
            http_check($response['status'] === 200 && http_safe($response)
                && str_contains($response['body'], 'Strzyżenie damskie')
                && str_contains($response['body'], '80,00 zł')
                && str_contains($response['body'], '60 min')
                && str_contains($response['headers']['content-security-policy'], "form-action 'self'"),
                'Public offer, detail and selection available: ' . $role . ' ' . $path);
            http_check($role === 'guest'
                ? str_contains($response['body'], 'href="/login.php"')
                : str_contains($response['body'], 'action="/logout.php"'), 'Offer reuses account navigation: ' . $role);
        }
    }
    $list = http_request($guest, '/services.php');
    http_check(substr_count($list['body'], '<article class="offer-service"') === 6
        && str_contains($list['body'], '/service.php?id=1')
        && str_contains($list['body'], '/booking.php?service_id=1'), 'Six seed services with detail and next-step links');
    $selected = http_request($guest, '/booking.php?service_id=1');
    foreach (['Pracownik', 'Data', 'Godzina', 'Potwierdzenie', 'Wybierz pracownika'] as $text) {
        http_check(str_contains($selected['body'], $text), 'Selection explains workflow: ' . $text);
    }
    http_check(!str_contains($selected['body'], '<form'), 'Guest selection has no booking submission yet');
    foreach (['/service.php' => 'id', '/booking.php' => 'service_id'] as $path => $key) {
        $response = http_request($guest, $path);
        http_check($response['status'] === 400 && http_safe($response), 'Missing offer ID: ' . $path);
        foreach (['', '0', '-1', '01', '1.5', '1e0', ' 1 ', '+1', '4294967296', str_repeat('9', 2000),
            ['1'], ['nested' => ['1']], "1\0", '<script>alert(1)</script>', '1 OR 1=1'] as $badId) {
            $response = http_request($guest, $path . '?' . http_build_query([$key => $badId]));
            http_check($response['status'] === 400 && http_safe($response)
                && !str_contains($response['body'], '<script>alert(1)</script>')
                && str_contains($response['body'], 'Wróć do oferty'), 'Invalid offer ID safely rejected: ' . $path);
        }
        foreach (['999999', '4294967295'] as $missingId) {
            $response = http_request($guest, $path . '?' . $key . '=' . $missingId);
            http_check($response['status'] === 404 && http_safe($response)
                && !str_contains($response['body'], '80,00 zł'), 'Missing service has no details: ' . $path);
        }
    }
    foreach ($paths as $path) {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $response = http_request($guest, $path, $method, [
                'id' => 1, 'service_id' => 1, 'price' => 0, 'duration' => 1,
                'active' => 1, 'role' => 'admin', 'action' => 'create',
            ]);
            http_check($response['status'] === 405 && $response['headers']['allow'] === 'GET, HEAD'
                && http_safe($response), 'Offer refuses mutation: ' . $path . ' ' . $method);
        }
        $response = http_request($guest, $path, 'HEAD');
        http_check($response['status'] === 200 && $response['body'] === '', 'HEAD offer response: ' . $path);
    }
    $response = http_request($guest, '/booking.php?service_id=1&price=0&duration=1&active=1&role=admin');
    http_check($response['status'] === 200 && str_contains($response['body'], '80,00 zł')
        && str_contains($response['body'], '60 min'), 'Forged selection parameters do not override database values');
    http_check($snapshot() === $original, 'Offer requests do not mutate any application data');

    // Dezaktywacja między listą, szczegółami a kolejnym krokiem.
    $connection->exec('UPDATE services SET active=0 WHERE id=1');
    $response = http_request($guest, '/services.php?active=1');
    http_check(!str_contains($response['body'], 'Strzyżenie damskie'), 'Inactive service hidden from public list');
    foreach (array_slice($paths, 1) as $path) {
        $response = http_request($guest, $path . '&active=1');
        http_check($response['status'] === 404 && !str_contains($response['body'], 'Strzyżenie damskie'),
            'Inactive service cannot be reopened through old or forged link: ' . $path);
    }
    $connection->exec('UPDATE services SET active=1 WHERE id=1');
    $connection->exec('UPDATE service_categories SET active=0 WHERE id=1');
    foreach ($paths as $path) {
        $response = http_request($guest, $path);
        http_check($response['status'] === ($path === '/services.php' ? 200 : 404)
            && !str_contains($response['body'], 'Strzyżenie damskie'), 'Inactive category hides service: ' . $path);
    }
    $connection->exec('UPDATE service_categories SET active=1 WHERE id=1');

    $payload = '<script>alert(1)</script>" onmouseover="alert(2)';
    $update = $connection->prepare('UPDATE services SET name=:name, description=:description, price=:price WHERE id=1');
    $category = $connection->prepare('UPDATE service_categories SET name=:name WHERE id=1');
    $update->execute(['name' => $payload, 'description' => $payload, 'price' => '99999999.99']);
    $category->execute(['name' => $payload]);
    foreach ($paths as $path) {
        $response = http_request($guest, $path);
        http_check($response['status'] === 200 && http_safe($response)
            && str_contains($response['body'], htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            && !str_contains($response['body'], $payload)
            && str_contains($response['body'], '99999999,99 zł'), 'Stored service/category XSS escaped and exact price: ' . $path);
    }
    $update->execute(['name' => $original['services'][0]['name'], 'description' => null, 'price' => '0.00']);
    $category->execute(['name' => $original['service_categories'][0]['name']]);
    $response = http_request($guest, '/service.php?id=1');
    http_check($response['status'] === 200 && str_contains($response['body'], '0,00 zł')
        && str_contains($response['body'], 'Szczegółowy opis tej usługi'), 'Zero price and absent description render safely');
    $update->execute(['name' => $original['services'][0]['name'], 'description' => $original['services'][0]['description'], 'price' => $original['services'][0]['price']]);

    $connection->exec('UPDATE services SET active=0');
    $response = http_request($guest, '/services.php');
    http_check($response['status'] === 200 && str_contains($response['body'], 'Obecnie nie ma dostępnych usług.')
        && !str_contains($response['body'], '/booking.php?'), 'Empty active offer is safe and offers no inactive bookings');
    $active = $connection->prepare('UPDATE services SET active=:active WHERE id=:id');
    foreach ($original['services'] as $row) { $active->execute(['id' => $row['id'], 'active' => $row['active']]); }
    http_check($snapshot() === $original, 'Offer tests restore seed data and preserve reservation history');

    $connection->exec('RENAME TABLE services TO services_offer_unavailable');
    try {
        foreach ($paths as $path) {
            $response = http_request($guest, $path);
            http_check($response['status'] === 500 && http_safe($response)
                && $response['body'] === 'Wystąpił błąd aplikacji. Spróbuj ponownie później.', 'Offer uses existing safe database error handler: ' . $path);
        }
    } finally {
        $connection->exec('RENAME TABLE services_offer_unavailable TO services');
    }
}
