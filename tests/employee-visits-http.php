<?php
declare(strict_types=1);

function run_employee_visits_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/employee-reservations.php';
    $before = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $baselineId = (int) $connection->query('SELECT COALESCE(MAX(id),0) FROM reservations')->fetchColumn();
    $client = $connection->query('SELECT name, surname, phone FROM users WHERE id=4')->fetch();
    $service = $connection->query('SELECT name FROM services WHERE id=1')->fetchColumn();
    $today = booking_now()->format('Y-m-d');
    $tomorrow = booking_now()->modify('+1 day')->format('Y-m-d');
    $yesterday = booking_now()->modify('-1 day')->format('Y-m-d');
    $cookies = $sessions['employee'];
    $ids = static function (array $response): array {
        preg_match_all('/data-reservation-id="(\d+)"/', $response['body'], $matches);
        return array_map('intval', $matches[1]);
    };
    $create = static function (int $employee, string $day, string $start, string $status, string $comment = '') use ($connection): int {
        $statement = $connection->prepare('INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status, comment) VALUES (4,:employee,:service,:day,:start,:end,:status,:comment)');
        $statement->execute(['employee' => $employee, 'service' => $employee === 1 ? 1 : 2, 'day' => $day,
            'start' => $start, 'end' => (new DateTimeImmutable($start))->modify('+1 hour')->format('H:i:s'), 'status' => $status, 'comment' => $comment]);
        return (int) $connection->lastInsertId();
    };
    $read = static function (int $id) use ($connection): array {
        $statement = $connection->prepare('SELECT * FROM reservations WHERE id=:id');
        $statement->execute(['id' => $id]);
        return $statement->fetch();
    };
    try {
        $todayId = $create(1, $today, '09:00', 'pending', '<script>alert(1)</script>');
        $future = $create(1, $tomorrow, '09:00', 'pending');
        $confirmed = $create(1, $tomorrow, '11:00', 'confirmed');
        $past = $create(1, $yesterday, '09:00', 'confirmed');
        $completed = $create(1, $tomorrow, '15:00', 'completed');
        $cancelled = $create(1, $tomorrow, '16:00', 'cancelled');
        $foreign = $create(2, $tomorrow, '10:00', 'pending', 'PRIVATE_STAFF_B');
        $todayPage = http_request($cookies, '/employee.php?employee_id=2&user_id=3');
        http_check($todayPage['status'] === 200 && $ids($todayPage) === [$todayId], 'Employee identity comes from account mapping, not request');
        $futurePage = http_request($cookies, '/employee.php?group=future');
        http_check($ids($futurePage) === [$future, $confirmed], 'Future group shows own pending and confirmed visits from tomorrow');
        $history = http_request($cookies, '/employee.php?group=history');
        foreach ([$past, $completed, $cancelled] as $id) { http_check(in_array($id, $ids($history), true), 'History includes past and terminal visits'); }
        foreach (['today', 'future', 'history'] as $group) {
            $response = http_request($cookies, '/employee.php?group=' . $group . '&employee_id=2');
            foreach ($ids($response) as $id) { http_check((int) $read($id)['employee_id'] === 1, 'Every listed visit belongs to mapped employee'); }
        }
        http_check(str_contains($todayPage['body'], e($client['name'] . ' ' . $client['surname']))
            && str_contains($todayPage['body'], e($service)) && str_contains($todayPage['body'], '09:00:00–10:00:00')
            && str_contains($todayPage['body'], 'Oczekująca'), 'Employee list shows client, service, time and status');
        $details = http_request($cookies, '/employee.php?action=details&reservation_id=' . $todayId);
        http_check($details['status'] === 200 && str_contains($details['body'], e($client['phone']))
            && str_contains($details['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
            && !str_contains($details['body'], '<script>alert(1)</script>'), 'Own details show contact and safely escaped comment');
        $csrf = http_csrf($details);
        $url = '/employee.php?action=status';
        $post = ['reservation_id' => (string) $future, '_csrf' => $csrf, 'status' => 'confirmed', 'expected_status' => 'pending', 'confirm' => '1'];
        $other = http_request($cookies, '/employee.php?action=details&reservation_id=' . $foreign . '&employee_id=2');
        $missing = http_request($cookies, '/employee.php?action=details&reservation_id=4294967295');
        http_check($other['status'] === 404 && $other['body'] === $missing['body'] && !str_contains($other['body'], 'PRIVATE_STAFF_B'), 'Foreign and missing visits have identical safe details response');
        $otherPost = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $foreign, 'employee_id' => '2', 'user_id' => '3']));
        $missingPost = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => '4294967295']));
        http_check($otherPost['status'] === 404 && $otherPost['body'] === $missingPost['body'] && $read($foreign)['status'] === 'pending', 'Foreign status update and identity spoofing denied');
        $staffB = [];
        http_login($staffB, 'piotr.wisniewski@cuttime.test', '/employee.php');
        $bDetails = http_request($staffB, '/employee.php?action=details&reservation_id=' . $foreign);
        http_check($bDetails['status'] === 200 && str_contains($bDetails['body'], 'PRIVATE_STAFF_B'), 'Employee B can view own visit');
        http_check(http_request($staffB, '/employee.php?action=details&reservation_id=' . $future)['status'] === 404, 'Employee B cannot view employee A visit');
        $bPost = array_replace($post, ['_csrf' => http_csrf($bDetails)]);
        http_check(http_request($staffB, $url, 'POST', $bPost)['status'] === 404, 'Employee B cannot change employee A status');
        foreach (['guest' => [], 'client' => $sessions['client'], 'admin' => $sessions['admin']] as $role => $jar) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($jar, $method === 'POST' ? $url : '/employee.php', $method, $method === 'POST' ? $post : null);
                http_check($response['status'] === 303 && $response['body'] === '', 'Employee panel rejects role: ' . $role . ' ' . $method);
            }
        }
        foreach (['', 'bad', ['token']] as $token) {
            $response = http_request($cookies, $url, 'POST', array_replace($post, ['_csrf' => $token]));
            http_check($response['status'] === 403 && http_safe($response), 'Employee status CSRF required');
        }
        foreach (['0', '-1', '1 OR 1=1', ['1'], '4294967296', '<script>alert(1)</script>'] as $id) {
            http_check(http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => $id]))['status'] === 400, 'Employee mutation rejects malformed ID');
        }
        foreach (['administrator', '', '<script>alert(1)</script>', ['confirmed']] as $status) {
            $response = http_request($cookies, $url, 'POST', array_replace($post, ['status' => $status]));
            http_check($response['status'] === 422 && http_safe($response) && !str_contains($response['body'], '<script>alert(1)</script>'), 'Unknown or malformed status rejected safely');
        }
        foreach ([['confirm' => '0'], ['confirm' => ['1']], ['expected_status' => ['pending']]] as $bad) {
            http_check(http_request($cookies, $url, 'POST', array_replace($post, $bad))['status'] === 422, 'Confirmation and expected status validated');
        }
        foreach (['?group[]=today', '?group=other', '?page=0', '?page[]=1', '?action[]=list', '?action=details&reservation_id[]=1'] as $query) {
            $response = http_request($cookies, '/employee.php' . $query);
            http_check($response['status'] === 400 && http_safe($response), 'Employee view rejects malformed parameters');
        }
        http_check(http_request($cookies, $url . '&reservation_id=' . $future)['status'] === 405, 'GET cannot update employee visit');
        http_check(http_request($cookies, '/employee.php', 'POST', $post)['status'] === 405, 'POST to list cannot update visit');
        http_check(http_request($cookies, $url, 'POST', $post + ['large' => str_repeat('x', 17000)])['status'] === 413, 'Oversized employee request rejected');
        http_check(http_request($cookies, $url, 'POST', array_replace($post, ['status' => 'completed']))['status'] === 409, 'Cannot skip confirmation transition');
        http_check(http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $confirmed,
            'status' => 'completed', 'expected_status' => 'confirmed', 'end_time' => '00:00', 'reservation_date' => $yesterday]))['status'] === 409, 'Cannot complete future visit by spoofing end time');
        http_check($read($future)['status'] === 'pending', 'Invalid requests preserve own pending visit');

        $connection->exec('UPDATE employees SET active=0 WHERE id=1');
        http_check(http_request($cookies, '/employee.php')['status'] === 403
            && http_request($cookies, $url, 'POST', $post)['status'] === 403, 'Inactive employee profile denied for reads and writes');
        $connection->exec('UPDATE employees SET active=1, user_id=5 WHERE id=1');
        http_check(http_request($cookies, '/employee.php')['status'] === 403
            && http_request($cookies, $url, 'POST', $post)['status'] === 403, 'Missing employee profile denied without trusting submitted ID');
        $connection->exec('UPDATE employees SET user_id=2 WHERE id=1');

        $xss = '<script>alert(1)</script>';
        $connection->prepare('UPDATE users SET name=:name WHERE id=4')->execute(['name' => $xss]);
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $xss]);
        $response = http_request($cookies, '/employee.php');
        http_check(substr_count($response['body'], e($xss)) === 2 && !str_contains($response['body'], $xss), 'Client name and service escaped in employee list');
        $connection->prepare('UPDATE users SET name=:name WHERE id=4')->execute(['name' => $client['name']]);
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $service]);

        $original = $read($future);
        $response = http_request($cookies, $url . '&reservation_id=' . $foreign, 'POST', $post + ['employee_id' => '2', 'user_id' => '3', 'role' => 'admin']);
        http_check($response['status'] === 303 && $response['headers']['location'] === '/employee.php?action=details&reservation_id=' . $future, 'Own pending visit can be confirmed with PRG');
        $original['status'] = 'confirmed';
        http_check($read($future) === $original, 'Status update changes no other reservation fields');
        http_check(str_contains(http_request($cookies, $response['headers']['location'])['body'], 'Zmieniono status wizyty na: Potwierdzona.'), 'Employee sees success flash');
        http_check(http_request($cookies, $url, 'POST', $post)['status'] === 409, 'Stale repeated status POST rejected');
        $response = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $past, 'expected_status' => 'confirmed', 'status' => 'completed']));
        http_check($response['status'] === 303 && $read($past)['status'] === 'completed', 'Confirmed past visit can be completed');
        foreach ([$past => 'completed', $cancelled => 'cancelled'] as $id => $status) {
            foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $target) {
                $response = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $id, 'expected_status' => $status, 'status' => $target]));
                http_check($response['status'] === 409 && $read($id)['status'] === $status, 'Terminal visit cannot be reopened or changed');
            }
            $page = http_request($cookies, '/employee.php?action=details&reservation_id=' . $id);
            http_check(!str_contains($page['body'], 'name="expected_status"'), 'Terminal visit has no status form');
        }
        $response = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $confirmed, 'expected_status' => 'confirmed', 'status' => 'cancelled']));
        http_check($response['status'] === 303 && $read($confirmed)['status'] === 'cancelled', 'Employee may cancel own confirmed visit');
        // Formularz otwarty przed anulowaniem nie może nadpisać nowego statusu.
        http_check(http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $confirmed, 'expected_status' => 'confirmed', 'status' => 'completed']))['status'] === 409, 'Stale status cannot override cancellation');
        $response = http_request($staffB, $url, 'POST', array_replace($bPost, ['reservation_id' => (string) $foreign, 'status' => 'cancelled']));
        http_check($response['status'] === 303 && $read($foreign)['status'] === 'cancelled', 'Employee B may cancel own pending visit');

        for ($i = 1; $i <= 21; $i++) { $create(1, booking_now()->modify('+' . ($i + 2) . ' days')->format('Y-m-d'), '09:00', 'pending'); }
        $first = http_request($cookies, '/employee.php?group=future');
        $second = http_request($cookies, '/employee.php?group=future&page=2&employee_id=2');
        http_check(count($ids($first)) === 20 && count($ids($second)) === 2 && array_intersect($ids($first), $ids($second)) === [], 'Employee pagination limits SQL and preserves group');
        foreach ($ids($second) as $id) { http_check((int) $read($id)['employee_id'] === 1, 'Employee pagination preserves ownership'); }
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baselineId]);
        $connection->exec('UPDATE employees SET active=1, user_id=2 WHERE id=1');
        $connection->prepare('UPDATE users SET name=:name WHERE id=4')->execute(['name' => $client['name']]);
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $service]);
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $before, 'Employee visit tests preserve seed history');
}
