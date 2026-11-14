<?php
declare(strict_types=1);

function run_client_reservations_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/client-reservations.php';
    $before = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $baselineId = (int) $connection->query('SELECT COALESCE(MAX(id),0) FROM reservations')->fetchColumn();
    $service = $connection->query('SELECT * FROM services WHERE id=1')->fetch();
    $staff = $connection->query('SELECT name, surname FROM users WHERE id=2')->fetch();
    $date = booking_now()->modify('next monday')->setTime(0, 0);
    $cookies = $sessions['client'];
    $idsOnPage = static function (array $response): array {
        preg_match_all('/data-reservation-id="(\d+)"/', $response['body'], $matches);
        return array_map('intval', $matches[1]);
    };
    $create = static function (int $owner, string $status, string $day, string $start) use ($connection): int {
        $end = (new DateTimeImmutable($start))->modify('+1 hour')->format('H:i:s');
        $insert = $connection->prepare('INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status) VALUES (:owner,1,1,:day,:start,:end,:status)');
        $insert->execute(['owner' => $owner, 'day' => $day, 'start' => $start, 'end' => $end, 'status' => $status]);
        return (int) $connection->lastInsertId();
    };
    $read = static function (int $id) use ($connection): array {
        $statement = $connection->prepare('SELECT * FROM reservations WHERE id=:id');
        $statement->execute(['id' => $id]);
        return $statement->fetch();
    };
    try {
        $own = $create(4, 'pending', $date->format('Y-m-d'), '09:00');
        $confirmed = $create(4, 'confirmed', $date->format('Y-m-d'), '10:00');
        $cancelled = $create(4, 'cancelled', $date->format('Y-m-d'), '11:00');
        $completed = $create(4, 'completed', $date->format('Y-m-d'), '12:00');
        $foreign = $create(5, 'pending', $date->format('Y-m-d'), '14:00');
        $past = $create(4, 'pending', booking_now()->modify('-1 day')->format('Y-m-d'), '09:00');
        // Wizyta rozpoczęta sekundę temu: także dzisiejszy termin nie jest przyszły.
        $startedAt = booking_now()->modify('-1 second');
        $started = $create(4, 'confirmed', $startedAt->format('Y-m-d'), '00:00');
        $connection->prepare('UPDATE reservations SET start_time=:start, end_time=:end WHERE id=:id')->execute([
            'start' => $startedAt->format('H:i:s'), 'end' => '23:59:59', 'id' => $started,
        ]);

        $list = http_request($cookies, '/client-reservations.php?user_id=5');
        http_check($list['status'] === 200 && $idsOnPage($list) === [$own, $confirmed], 'Client list SQL isolates owner and upcoming group');
        http_check(str_contains($list['body'], e($service['name'])) && str_contains($list['body'], e($staff['name'] . ' ' . $staff['surname']))
            && str_contains($list['body'], str_replace('.', ',', $service['price']) . ' zł')
            && str_contains($list['body'], '09:00:00–10:00:00') && str_contains($list['body'], 'Oczekująca'), 'Client sees service, employee, times, current price and status');
        http_check(str_contains($list['body'], 'nie przechowuje ceny z dnia rezerwacji'), 'Price is explicitly current, not a historical snapshot');
        $history = http_request($cookies, '/client-reservations.php?group=history');
        foreach ([$past, $started, $completed] as $id) { http_check(in_array($id, $idsOnPage($history), true), 'History includes past, started and completed visits'); }
        $cancelledPage = http_request($cookies, '/client-reservations.php?group=cancelled');
        http_check(in_array($cancelled, $idsOnPage($cancelledPage), true) && !in_array($own, $idsOnPage($cancelledPage), true), 'Cancelled group uses actual status');
        foreach (['upcoming', 'history', 'cancelled'] as $group) {
            $page = http_request($cookies, '/client-reservations.php?group=' . $group . '&user_id=5&role=admin');
            foreach ($idsOnPage($page) as $id) { http_check((int) $read($id)['user_id'] === 4, 'Every rendered visit belongs to session user'); }
        }
        $details = http_request($cookies, '/client-reservations.php?action=details&reservation_id=' . $own . '&user_id=5');
        $csrf = http_csrf($details);
        http_check($details['status'] === 200 && $idsOnPage($details) === [$own] && $csrf !== ''
            && str_contains($details['body'], 'name="confirm"') && str_contains($details['body'], 'button-danger'), 'Own details show explicit cancellation confirmation');
        $post = ['reservation_id' => (string) $own, '_csrf' => $csrf, 'confirm' => '1'];
        $url = '/client-reservations.php?action=cancel';
        $foreignResponse = http_request($cookies, '/client-reservations.php?action=details&reservation_id=' . $foreign);
        $missingResponse = http_request($cookies, '/client-reservations.php?action=details&reservation_id=4294967295');
        http_check($foreignResponse['status'] === 404 && $foreignResponse['body'] === $missingResponse['body'], 'Foreign and missing details return the same safe response');
        $foreignCancel = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => (string) $foreign, 'user_id' => '5']));
        $missingCancel = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => '4294967295']));
        http_check($foreignCancel['status'] === 404 && $foreignCancel['body'] === $missingCancel['body']
            && $read($foreign)['status'] === 'pending', 'Foreign cancellation and owner spoofing fail without disclosing data');
        foreach (['guest' => [], 'employee' => $sessions['employee'], 'admin' => $sessions['admin']] as $role => $jar) {
            foreach (['GET', 'POST'] as $method) {
                $response = http_request($jar, $method === 'POST' ? $url : '/client-reservations.php', $method, $method === 'POST' ? $post : null);
                http_check($response['status'] === 303 && $response['body'] === '', 'Client reservations reject role: ' . $role . ' ' . $method);
            }
        }
        foreach (['', 'wrong', ['invalid']] as $token) {
            $response = http_request($cookies, $url, 'POST', array_replace($post, ['_csrf' => $token]));
            http_check($response['status'] === 403 && http_safe($response), 'Cancellation CSRF is enforced');
        }
        foreach (['0', '-1', '1 OR 1=1', ['1'], '4294967296', '<script>alert(1)</script>'] as $badId) {
            $response = http_request($cookies, $url, 'POST', array_replace($post, ['reservation_id' => $badId]));
            http_check($response['status'] === 400 && http_safe($response) && !str_contains($response['body'], '<script>alert(1)</script>'), 'Cancellation ID validation rejects malformed input');
        }
        foreach (['?group[]=upcoming', '?group=other', '?page[]=1', '?page=0', '?action[]=details', '?action=details&reservation_id[]=1'] as $query) {
            $response = http_request($cookies, '/client-reservations.php' . $query);
            http_check($response['status'] === 400 && http_safe($response), 'Malformed list/details parameters rejected');
        }
        http_check(http_request($cookies, $url . '&reservation_id=' . $own)['status'] === 405, 'GET never cancels');
        http_check(http_request($cookies, '/client-reservations.php', 'POST', $post)['status'] === 405, 'POST to list never cancels');
        http_check(http_request($cookies, $url, 'POST', array_diff_key($post, ['confirm' => true]))['status'] === 422, 'Cancellation requires explicit confirmation');
        http_check(http_request($cookies, $url, 'POST', $post + ['large' => str_repeat('x', 17000)])['status'] === 413, 'Oversized cancellation request rejected');
        foreach ([$past, $started, $cancelled, $completed] as $id) {
            $previous = $read($id);
            $response = http_request($cookies, $url, 'POST', array_replace($post, [
                'reservation_id' => (string) $id, 'status' => 'pending', 'date' => $date->format('Y-m-d'), 'start_time' => '23:00',
            ]));
            http_check($response['status'] === 409 && http_safe($response) && $read($id) === $previous, 'Past/started/cancelled/completed cannot be cancelled by forged POST');
            $page = http_request($cookies, '/client-reservations.php?action=details&reservation_id=' . $id);
            http_check(!str_contains($page['body'], 'name="confirm"'), 'Non-cancellable visit has no cancellation form');
        }
        http_check($read($own)['status'] === 'pending', 'All invalid requests preserve valid reservation');

        // Escapowanie wartości z bazy również w szczegółach (bez zmiany danych produkcyjnych).
        $xss = '<script>alert(1)</script>';
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $xss]);
        $response = http_request($cookies, '/client-reservations.php?action=details&reservation_id=' . $own);
        http_check(str_contains($response['body'], e($xss)) && !str_contains($response['body'], $xss), 'Client details escape service name from database');
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $service['name']]);

        $slots = booking_available_slots($service, ['id' => 1], $date, $connection);
        http_check(!in_array('09:00', array_column($slots, 'time'), true), 'Visit blocks its slot before cancellation');
        foreach ([$own, $confirmed] as $id) {
            $previous = $read($id);
            $response = http_request($cookies, $url . '&reservation_id=' . $foreign . '&user_id=5', 'POST', array_replace($post, [
                'reservation_id' => (string) $id, 'user_id' => '5', 'status' => 'completed', 'role' => 'admin', 'employee_id' => '2',
            ]));
            http_check($response['status'] === 303 && $response['headers']['location'] === '/client-reservations.php?group=cancelled', 'Valid own cancellation redirects to history');
            $previous['status'] = 'cancelled';
            http_check($read($id) === $previous, 'Cancellation updates only status and preserves the record');
        }
        $success = http_request($cookies, '/client-reservations.php?group=cancelled');
        http_check(str_contains($success['body'], 'Rezerwacja została anulowana.') && in_array($own, $idsOnPage($success), true), 'Success message and cancelled visit visible');
        http_check(http_request($cookies, $url, 'POST', $post)['status'] === 409, 'Repeated manual POST is rejected');
        $slots = booking_available_slots($service, ['id' => 1], $date, $connection);
        http_check(in_array('09:00', array_column($slots, 'time'), true), 'Cancellation frees slot for future bookings');
        http_check($read($foreign)['status'] === 'pending', 'Foreign reservation untouched after all operations');
        http_check($idsOnPage(http_request($cookies, '/client-reservations.php')) === [], 'Empty upcoming list remains usable');

        // Wiele własnych rekordów: paginacja i licznik też ograniczone właścicielem.
        for ($i = 1; $i <= 22; $i++) { $create(4, 'pending', $date->modify('+' . $i . ' days')->format('Y-m-d'), '09:00'); }
        $first = http_request($cookies, '/client-reservations.php?group=upcoming');
        $second = http_request($cookies, '/client-reservations.php?group=upcoming&page=2&user_id=5');
        http_check(count($idsOnPage($first)) === 20 && count($idsOnPage($second)) === 2
            && array_intersect($idsOnPage($first), $idsOnPage($second)) === [], 'Client pagination uses distinct SQL pages');
        http_check(str_contains($first['body'], 'group=upcoming&amp;page=2') && !in_array($foreign, $idsOnPage($second), true), 'Pagination keeps group and owner restriction');
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baselineId]);
        $connection->prepare('UPDATE services SET name=:name WHERE id=1')->execute(['name' => $service['name']]);
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $before, 'Client reservation tests preserve seed history');
}
