<?php
declare(strict_types=1);

function run_booking_save_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/helpers.php';
    require_once dirname(__DIR__) . '/includes/booking-save.php';
    $date = booking_now()->modify('next monday')->format('Y-m-d');
    $input = ['service_id' => '1', 'employee_id' => '1', 'date' => $date, 'time' => '09:00', 'confirm' => '1'];
    $before = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $baselineId = (int) $connection->query('SELECT COALESCE(MAX(id),0) FROM reservations')->fetchColumn();
    $duration = (int) $connection->query('SELECT duration FROM services WHERE id=1')->fetchColumn();
    $cookies = $sessions['client'];
    $csrf = http_csrf(http_request($cookies, '/client.php'));
    try {
        foreach (['guest' => [], 'employee' => $sessions['employee'], 'admin' => $sessions['admin']] as $role => $jar) {
            $response = http_request($jar, '/booking-save.php', 'POST', $input + ['_csrf' => $csrf]);
            http_check($response['status'] === 303, 'Booking save rejects role: ' . $role);
        }
        foreach (['', ['wrong'], 'invalid'] as $token) {
            http_check(http_request($cookies, '/booking-save.php', 'POST', $input + ['_csrf' => $token])['status'] === 403, 'Booking CSRF rejected');
        }
        http_check(http_request($cookies, '/booking-save.php')['status'] === 405, 'Booking GET never writes');
        foreach ([['service_id' => ['1']], ['service_id' => '999999'], ['employee_id' => '2'], ['date' => '2000-01-01'], ['time' => '08:00'], ['time' => '16:15'], ['time' => ['09:00']], ['confirm' => '0']] as $bad) {
            $response = http_request($cookies, '/booking-save.php', 'POST', array_replace($input, $bad, ['_csrf' => $csrf]));
            http_check($response['status'] === 422 && http_safe($response), 'Booking server validation rejects modified POST');
        }
        foreach (['services', 'employees'] as $table) {
            $connection->exec('UPDATE ' . $table . ' SET active=0 WHERE id=1');
            $result = booking_save(4, $input, $connection);
            $connection->exec('UPDATE ' . $table . ' SET active=1 WHERE id=1');
            http_check(!$result['success'] && !$connection->inTransaction(), 'Inactive entity rejected and lock released: ' . $table);
        }
        $fixture = $connection->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,status) VALUES (5,1,1,:date,'10:00','11:00','confirmed')");
        $fixture->execute(['date' => $date]);
        foreach ([['09:30',60],['10:00',60],['10:15',30],['10:30',60],['09:30',120]] as [$time,$minutes]) {
            $connection->prepare('UPDATE services SET duration=:duration WHERE id=1')->execute(['duration' => $minutes]);
            $result = booking_save(4, array_replace($input, ['time' => $time, 'duration' => '1', 'end_time' => '09:31']), $connection);
            http_check(!$result['success'] && $result['status'] === 409 && !$connection->inTransaction(), 'SQL conflict blocks ' . $time . ' / ' . $minutes);
        }
        $connection->prepare('UPDATE services SET duration=:duration WHERE id=1')->execute(['duration' => $duration]);
        foreach (['09:00', '11:00'] as $time) {
            $response = http_request($cookies, '/booking-save.php', 'POST', array_replace($input, ['time' => $time, '_csrf' => $csrf, 'user_id' => '5', 'status' => 'cancelled', 'duration' => '1', 'end_time' => '23:00', 'role' => 'admin']));
            http_check($response['status'] === 303 && $response['headers']['location'] === '/client.php', 'Boundary accepted and PRG success: ' . $time);
        }
        $row = $connection->query('SELECT * FROM reservations ORDER BY id DESC LIMIT 1')->fetch();
        http_check((int) $row['user_id'] === 4 && $row['status'] === 'pending' && $row['end_time'] === '12:00:00', 'Owner, status and duration are server-controlled');
        http_check(str_contains(http_request($cookies, '/client.php')['body'], 'Zapisano rezerwację'), 'Success flash shown after redirect');
        http_check(http_request($cookies, '/booking-save.php', 'POST', $input + ['_csrf' => $csrf])['status'] === 409, 'Repeated POST cannot double-book');

        // Dwie osobne sesje PDO i procesy, wstrzymane za wspólną blokadą pracownika.
        $raceInput = array_replace($input, ['time' => '14:00']);
        $schema = $connection->query('SELECT DATABASE()')->fetchColumn();
        $workers = [];
        $connection->beginTransaction();
        $connection->query('SELECT id FROM employees WHERE id=1 FOR UPDATE')->fetch();
        try {
            foreach ([4, 5] as $client) {
                $command = [PHP_BINARY];
                if (php_ini_loaded_file()) { array_push($command, '-c', php_ini_loaded_file()); }
                array_push($command, __DIR__ . '/booking-save-worker.php', $schema, base64_encode(json_encode($raceInput)), (string) $client);
                $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
                http_check(trim(fgets($pipes[1]) ?: '') === 'READY', 'Independent booking worker ready');
            }
            usleep(200000);
            $connection->commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            }
            http_check(count(array_filter($results, static fn($r) => $r['success'])) === 1
                && count(array_filter($results, static fn($r) => !$r['success'] && $r['status'] === 409)) === 1, 'Concurrent booking: exactly one winner, one readable conflict');
            $count = $connection->prepare("SELECT COUNT(*) FROM reservations WHERE employee_id=1 AND reservation_date=:date AND start_time='14:00'");
            $count->execute(['date' => $date]);
            http_check((int) $count->fetchColumn() === 1, 'Race inserts exactly one reservation');
        } finally {
            if ($connection->inTransaction()) { $connection->rollBack(); }
            foreach ($workers as [$process, $pipes]) { fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process); }
        }
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baselineId]);
        $connection->prepare('UPDATE services SET duration=:duration, active=1 WHERE id=1')->execute(['duration' => $duration]);
        $connection->exec('UPDATE employees SET active=1 WHERE id=1');
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $before, 'Save tests preserve seed reservations');
}
