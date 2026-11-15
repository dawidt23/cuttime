<?php
declare(strict_types=1);

/** Dwa procesy/PDO czekają za tą samą blokadą pracownika; wynik badamy w bazie. */
function stage4_parallel_attempts(PDO $connection, array $inputs, array $modes = ['booking', 'booking']): array
{
    $schema = $connection->query('SELECT DATABASE()')->fetchColumn();
    $workers = [];
    $connection->beginTransaction();
    $connection->query('SELECT id FROM employees WHERE id=1 FOR UPDATE')->fetch();
    try {
        foreach ($inputs as $index => $input) {
            $command = [PHP_BINARY];
            if (php_ini_loaded_file()) { array_push($command, '-c', php_ini_loaded_file()); }
            array_push($command, __DIR__ . '/booking-save-worker.php', $schema,
                base64_encode(json_encode($input, JSON_THROW_ON_ERROR)), (string) (4 + $index), $modes[$index]);
            $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start race worker'); }
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            $workers[] = [$process, $pipes];
            http_check(trim(fgets($pipes[1]) ?: '') === 'READY', 'Stage 4 independent PDO worker ready');
        }
        usleep(200000);
        $connection->commit();
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        foreach ($workers as [$process, $pipes]) { fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process); }
    }
}

function run_stage4_logic_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/booking-save.php';
    require_once dirname(__DIR__) . '/includes/admin-reservations.php';
    $schema = $connection->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/\Acuttime_http_[a-f0-9]{16}\z/', $schema)) { throw new RuntimeException('Isolated test database required'); }
    $before = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $baseline = (int) $connection->query('SELECT COALESCE(MAX(id),0) FROM reservations')->fetchColumn();
    $day = booking_now()->modify('next monday')->modify('+140 days')->format('Y-m-d');
    $insert = $connection->prepare('INSERT INTO reservations(user_id,employee_id,service_id,reservation_date,start_time,end_time,status) VALUES (4,1,1,:day,:start,:end,:status)');
    $admin = $sessions['admin'];
    $csrf = http_csrf(http_request($admin, '/admin-reservations.php?action=details&id=1'));
    $client = $sessions['client'];
    $clientCsrf = http_csrf(http_request($client, '/client.php'));
    $wipe = static function () use ($connection, $baseline): void {
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baseline]);
    };
    $post = ['service_id' => '1', 'employee_id' => '1', 'date' => $day, 'time' => '09:00', 'confirm' => '1', '_csrf' => $clientCsrf];
    $todayHours = null;
    $shortServiceId = null;
    try {
        $insert->execute(['day' => $day, 'start' => '10:00', 'end' => '11:00', 'status' => 'cancelled']);
        $cancelled = (int) $connection->lastInsertId();
        $insert->execute(['day' => $day, 'start' => '10:30', 'end' => '11:30', 'status' => 'confirmed']);
        $response = http_request($admin, '/admin-reservations.php?action=status&id=' . $cancelled, 'POST', [
            '_csrf' => $csrf, 'expected_status' => 'cancelled', 'status' => 'confirmed', 'confirm' => '1',
        ]);
        http_check($response['status'] === 409 && http_safe($response), 'Restoring cancelled reservation cannot create an active overlap');
        foreach (BOOKING_BLOCKING_STATUSES as $target) {
            $result = reservation_change_status($cancelled, ['expected_status' => 'cancelled', 'status' => $target, 'confirm' => '1'], $connection);
            http_check(!$result['success'] && $result['status'] === 409 && !$connection->inTransaction(), 'Restore to blocking status rejected and rolled back: ' . $target);
        }
        $connection->prepare("UPDATE reservations SET start_time='11:00', end_time='12:00' WHERE id<>:id AND id>:baseline")->execute(['id' => $cancelled, 'baseline' => $baseline]);
        $result = reservation_change_status($cancelled, ['expected_status' => 'cancelled', 'status' => 'confirmed', 'confirm' => '1'], $connection);
        http_check($result['success'] && !$connection->inTransaction(), 'Restoration permits exactly touching neighbour');
        $wipe();

        // Rzeczywiste różne usługi, przypisania i długości, nie tylko podmiana duration jednej usługi.
        foreach ([[2,2,30,600,1080], [5,1,45,540,1020], [1,1,60,540,1020], [4,1,120,540,1020]] as [$serviceId, $employeeId, $duration, $opening, $closing]) {
            foreach ([[$opening - 15, 422, 'before opening'], [$closing - $duration + 15, 422, 'after closing'],
                [$opening, 303, 'at opening'], [$closing - $duration, 303, 'at closing']] as [$start, $expected, $label]) {
                $input = array_replace($post, ['service_id' => (string) $serviceId, 'employee_id' => (string) $employeeId,
                    'time' => booking_time_label($start), 'duration' => ['1'], 'end_time' => '00:01', 'price' => '-1', 'user_id' => '5', 'status' => 'completed']);
                $response = http_request($client, '/booking-save.php', 'POST', $input);
                http_check($response['status'] === $expected && http_safe($response), 'Service ' . $duration . ' minutes: ' . $label);
                if ($expected === 303) {
                    $row = $connection->query('SELECT * FROM reservations ORDER BY id DESC LIMIT 1')->fetch();
                    http_check($row['end_time'] === booking_time_label($start + $duration) . ':00' && $row['status'] === 'pending' && (int) $row['user_id'] === 4,
                        'Server duration/owner/status preserved for ' . $duration . ' minutes: ' . $label);
                }
                $wipe();
            }
        }

        // Każdy status jest sprawdzany w wyliczaniu godzin ORAZ finalnym POST.
        foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $status) {
            $insert->execute(['day' => $day, 'start' => '10:00', 'end' => '11:00', 'status' => $status]);
            $slots = booking_available_slots(['duration' => 60], ['id' => 1], new DateTimeImmutable($day, new DateTimeZone('Europe/Warsaw')), $connection);
            http_check(in_array('10:00', array_column($slots, 'time'), true) === ($status === 'cancelled'), 'Availability status blocking rule: ' . $status);
            $response = http_request($client, '/booking-save.php', 'POST', array_replace($post, ['time' => '10:00']));
            http_check($response['status'] === ($status === 'cancelled' ? 303 : 409) && http_safe($response), 'Final POST status blocking rule: ' . $status);
            $wipe();
        }

        // Kontrolowany zegar istniejącego generatora, bez furtki czasowej w endpointzie zapisu.
        $monday = new DateTimeImmutable($day, new DateTimeZone('Europe/Warsaw'));
        $simulatedNow = $monday->setTime(10,30);
        http_check(booking_date($monday->modify('-1 day')->format('Y-m-d'), $simulatedNow)['error'] !== null, 'Clock simulation: yesterday rejected');
        $slots = booking_available_slots(['duration' => 60], ['id' => 1], $monday, $connection, $simulatedNow);
        http_check(!in_array('10:00', array_column($slots, 'time'), true) && !in_array('10:30', array_column($slots, 'time'), true)
            && in_array('10:45', array_column($slots, 'time'), true), 'Clock simulation: past/equal today blocked, future today offered');
        http_check(booking_date($monday->modify('+1 day')->format('Y-m-d'), $simulatedNow)['error'] === null, 'Clock simulation: future day accepted');

        // Rzeczywisty POST dzisiaj: testowy grafik całego dnia i krótka usługa w izolowanej bazie.
        $now = booking_now();
        $weekday = (int) $now->format('N');
        $readHours = $connection->prepare('SELECT * FROM employee_availability WHERE employee_id=1 AND day_of_week=:day');
        $readHours->execute(['day' => $weekday]);
        $todayHours = ['day' => $weekday, 'rows' => $readHours->fetchAll()];
        $connection->prepare('DELETE FROM employee_availability WHERE employee_id=1 AND day_of_week=:day')->execute(['day' => $weekday]);
        $connection->prepare("INSERT INTO employee_availability(employee_id,day_of_week,start_time,end_time) VALUES(1,:day,'00:00','23:59:59')")->execute(['day' => $weekday]);
        $connection->exec("INSERT INTO services(category_id,name,duration,price) VALUES(1,'Stage 4 temporal fixture',15,1.00)");
        $shortServiceId = (int) $connection->lastInsertId();
        $connection->prepare('INSERT INTO employee_services(employee_id,service_id) VALUES(1,:service)')->execute(['service' => $shortServiceId]);
        foreach ([$now->modify('-1 day')->format('Y-m-d'), $now->format('Y-m-d')] as $pastDate) {
            $response = http_request($client, '/booking-save.php', 'POST', array_replace($post, ['service_id' => (string) $shortServiceId, 'date' => $pastDate, 'time' => '00:00']));
            http_check($response['status'] === 422 && http_safe($response) && str_contains($response['body'], 'przeszłości'), 'Actual POST rejects past date/time: ' . $pastDate . ' 00:00');
        }
        $futureMinute = (int) (ceil((booking_time_seconds($now->format('H:i:s')) + 120) / 900) * 15);
        if ($futureMinute + 15 < 1440) {
            $response = http_request($client, '/booking-save.php', 'POST', array_replace($post, ['service_id' => (string) $shortServiceId,
                'date' => $now->format('Y-m-d'), 'time' => booking_time_label($futureMinute)]));
            http_check($response['status'] === 303, 'Actual POST accepts future hour today');
        } else {
            echo "SKIP actual future-today POST: no full 15-minute slot remains; deterministic clock case above passed.\n";
        }
        $wipe();

        // Macierz wadliwych parametrów: każde odrzucenie ma kontrolowaną odpowiedź HTTP.
        foreach (['service_id', 'employee_id', 'date', 'time'] as $field) {
            foreach ([['bad'], '<script>alert(1)</script>', str_repeat('9', 100)] as $bad) {
                $response = http_request($client, '/booking-save.php', 'POST', array_replace($post, [$field => $bad]));
                http_check($response['status'] === 422 && http_safe($response) && !str_contains($response['body'], '<script>alert(1)</script>'), 'Malformed final POST rejected: ' . $field);
            }
        }
        // Dwie rezerwacje: identyczny, częściowy i zawierający się przedział.
        foreach ([['10:00','10:00',1,1], ['10:00','10:30',1,1], ['10:00','10:15',4,5]] as [$left, $right, $serviceA, $serviceB]) {
            $results = stage4_parallel_attempts($connection, [array_replace($post, ['time' => $left, 'service_id' => (string) $serviceA]),
                array_replace($post, ['time' => $right, 'service_id' => (string) $serviceB])]);
            $wins = array_filter($results, static fn($r) => $r['success']);
            $losses = array_filter($results, static fn($r) => !$r['success'] && $r['status'] === 409 && str_contains($r['message'], 'zajęty'));
            http_check(count($wins) === 1 && count($losses) === 1, 'Concurrent interval ' . $left . '/' . $right . ': one winner and readable conflict');
            $count = $connection->prepare("SELECT COUNT(*) FROM reservations WHERE id>:baseline AND status IN ('pending','confirmed','completed')");
            $count->execute(['baseline' => $baseline]);
            http_check((int) $count->fetchColumn() === 1, 'Concurrent interval leaves exactly one blocking reservation');
            $wipe();
        }
        // Przywrócenie anulowanej wizyty konkuruje o tę samą blokadę z nowym klientem.
        $insert->execute(['day' => $day, 'start' => '10:00', 'end' => '11:00', 'status' => 'cancelled']);
        $restoreId = (int) $connection->lastInsertId();
        $results = stage4_parallel_attempts($connection, [array_replace($post, ['time' => '10:30']),
            ['reservation_id' => $restoreId, 'expected_status' => 'cancelled', 'status' => 'confirmed', 'confirm' => '1']], ['booking', 'restore']);
        http_check(count(array_filter($results, static fn($r) => $r['success'])) === 1
            && count(array_filter($results, static fn($r) => !$r['success'] && $r['status'] === 409 && !empty($r['message'] ?? $r['errors']['_form'] ?? ''))) === 1,
            'Concurrent booking versus admin restore: one winner, one controlled conflict');
        $pairs = $connection->prepare("SELECT COUNT(*) FROM reservations a JOIN reservations b ON a.id<b.id AND a.employee_id=b.employee_id
            AND a.reservation_date=b.reservation_date AND a.start_time<b.end_time AND a.end_time>b.start_time
            WHERE a.status IN ('pending','confirmed','completed') AND b.status IN ('pending','confirmed','completed') AND a.reservation_date=:day");
        $pairs->execute(['day' => $day]);
        http_check((int) $pairs->fetchColumn() === 0, 'No active conflicting pairs after concurrent restore/booking');
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baseline]);
        if ($shortServiceId !== null) {
            $connection->prepare('DELETE FROM employee_services WHERE service_id=:id')->execute(['id' => $shortServiceId]);
            $connection->prepare('DELETE FROM services WHERE id=:id')->execute(['id' => $shortServiceId]);
        }
        if ($todayHours !== null) {
            $connection->prepare('DELETE FROM employee_availability WHERE employee_id=1 AND day_of_week=:day')->execute(['day' => $todayHours['day']]);
            $restore = $connection->prepare('INSERT INTO employee_availability(id,employee_id,day_of_week,start_time,end_time) VALUES(:id,:employee_id,:day_of_week,:start_time,:end_time)');
            foreach ($todayHours['rows'] as $row) { $restore->execute($row); }
        }
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $before, 'Stage 4 logic tests preserve existing history');
}
