<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }

function run_admin_polish_http_tests(PDO $connection, array $sessions): void
{
    $admin = $sessions['admin'];
    // Wszystkie rekordy powstają wyłącznie w losowym schemacie zestawu HTTP.
    $created = array_fill_keys(['reservations', 'employees', 'users', 'services', 'service_categories'], []);
    $counts = [];
    foreach (array_keys($created) as $table) {
        $counts[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    $hash = $connection->query('SELECT password FROM users WHERE id=2')->fetchColumn();
    $longDescription = str_repeat('ą', 200) . 'Pełny opis do edycji.';
    try {
        $category = $connection->prepare('INSERT INTO service_categories (name) VALUES (:name)');
        $service = $connection->prepare('INSERT INTO services (category_id,name,duration,price) VALUES (1,:name,30,50.00)');
        $user = $connection->prepare("INSERT INTO users (name,surname,email,password,role) VALUES ('Audyt','Pracownik',:email,:password,'employee')");
        $employee = $connection->prepare('INSERT INTO employees (user_id,description) VALUES (:user_id,:description)');
        $reservation = $connection->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,status) VALUES (4,1,1,'2027-01-01','09:00','10:00','pending')");
        for ($i = 0; $i < 45; $i++) {
            $category->execute(['name' => 'Audyt kategorii ' . $i]);
            $created['service_categories'][] = (int) $connection->lastInsertId();
            $service->execute(['name' => 'Audyt usługi ' . $i]);
            $created['services'][] = (int) $connection->lastInsertId();
            $user->execute(['email' => 'audit-page-' . $i . '@example.test', 'password' => $hash]);
            $created['users'][] = (int) $connection->lastInsertId();
            $employee->execute(['user_id' => end($created['users']), 'description' => $longDescription]);
            $created['employees'][] = (int) $connection->lastInsertId();
            $reservation->execute();
            $created['reservations'][] = (int) $connection->lastInsertId();
        }
        $routes = [
            ['/admin-categories.php', [], $counts['service_categories'] + 45],
            ['/admin-services.php', [], $counts['services'] + 45],
            ['/admin-employees.php', [], $counts['employees'] + 45],
            ['/admin-employee-services.php', [], $counts['employees'] + 45],
            ['/admin-availability.php', [], $counts['employees'] + 45],
            ['/admin-reservations.php', ['date' => '2027-01-01', 'status' => 'pending', 'employee_id' => '1'], 45],
        ];
        foreach ($routes as [$path, $filters, $total]) {
            $url = static fn($page): string => $path . '?' . http_build_query($filters + ['page' => $page]);
            foreach ([1, 2, 3, 4294967295] as $requestedPage) {
                $page = min($requestedPage, 3);
                $response = http_request($admin, $url($requestedPage));
                preg_match('/<nav class="category-pagination"[^>]*>(.*?)<\/nav>/s', $response['body'], $match);
                $navigation = $match[1] ?? '';
                $range = 'Wyniki ' . (($page - 1) * 20 + 1) . '–' . min($page * 20, $total) . ' z ' . $total;
                http_check($response['status'] === 200 && str_contains($navigation, 'Strona ' . $page . ' z 3')
                    && str_contains($navigation, $range), 'Common pagination range and bounds: ' . $path . ' ' . $requestedPage);
                $expectedLinks = match ($page) {
                    1 => ['Następna →' => 2, 'Ostatnia' => 3],
                    2 => ['← Poprzednia' => 1, 'Następna →' => 3],
                    3 => ['Pierwsza' => 1, '← Poprzednia' => 2],
                };
                foreach ($expectedLinks as $label => $targetPage) {
                    http_check(str_contains($navigation, 'href="' . htmlspecialchars($url($targetPage), ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>'),
                        'Pagination link keeps context: ' . $path . ' ' . $label);
                }
                http_check(substr_count($navigation, '<a ') === 2, 'No redundant or out-of-bounds page links: ' . $path . ' ' . $page);
            }
            foreach (['+1', '1.5', ' 1', ['1']] as $invalidPage) {
                $response = http_request($admin, $url($invalidPage));
                http_check($response['status'] === 400 && http_safe($response), 'Shared page validation: ' . $path);
            }
        }
        $response = http_request($admin, '/admin-employees.php');
        http_check(str_contains($response['body'], str_repeat('ą', 160)) && !str_contains($response['body'], $longDescription), 'Employee list limits long descriptions');
        $response = http_request($admin, '/admin-employees.php?action=edit&id=' . $created['employees'][0]);
        http_check(str_contains($response['body'], $longDescription), 'Employee edit retains full description');
    } finally {
        // Stałe nazwy tabel z powyższej mapy; usuwamy tylko własne ID, dzieci przed rodzicami.
        foreach ($created as $table => $ids) {
            $delete = $connection->prepare('DELETE FROM ' . $table . ' WHERE id=:id');
            foreach ($ids as $id) { $delete->execute(['id' => $id]); }
        }
    }
    foreach (array_keys($created) as $table) {
        http_check((int) $connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $counts[$table], 'Audit fixtures cleaned up: ' . $table);
    }
    // Jeden wspólny, dostępny komunikat dla każdego rodzaju formularza.
    foreach ([
        '/admin-categories.php?action=create', '/admin-services.php?action=create',
        '/admin-employees.php?action=create', '/admin-availability.php?action=create&employee_id=1',
        '/admin-employee-services.php?action=edit&employee_id=1', '/admin-reservations.php?action=status&id=1',
    ] as $path) {
        $token = http_csrf(http_request($admin, $path));
        $response = http_request($admin, $path, 'POST', ['_csrf' => $token]);
        http_check($response['status'] === 422 && http_safe($response)
            && substr_count($response['body'], 'class="notice notice-error" role="alert" tabindex="-1" autofocus') === 1,
            'Empty admin form has one accessible validation summary: ' . $path);
    }
    foreach (['date' => '2026-02-30', 'status' => 'invalid', 'employee_id' => '0'] as $field => $invalid) {
        $response = http_request($admin, '/admin-reservations.php?' . http_build_query([$field => $invalid]));
        $errorId = 'filter-' . ($field === 'employee_id' ? 'employee' : $field) . '-error';
        http_check($response['status'] === 400 && str_contains($response['body'], 'aria-describedby="' . $errorId . '"')
            && str_contains($response['body'], 'id="' . $errorId . '"'), 'Reservation filter error describes its field: ' . $field);
    }
    $response = http_request($admin, '/admin.php');
    http_check(!str_contains($response['body'], 'Pozostałe moduły panelu będą udostępniane'), 'Dashboard describes currently available modules');
}
