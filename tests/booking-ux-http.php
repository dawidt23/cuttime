<?php
declare(strict_types=1);

function run_booking_ux_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/booking-save.php';
    $date = booking_now()->modify('next monday')->setTime(0, 0);
    $day = $date->format('Y-m-d');
    $base = '/booking.php?service_id=1';
    $employeeUrl = $base . '&employee_id=1';
    $timeUrl = $employeeUrl . '&date=' . $day;
    $cookies = $sessions['client'];
    $staff = $sessions['employee'];
    $pages = [];
    foreach (['offer' => '/services.php', 'service' => '/service.php?id=1', 'employee' => $base,
        'date' => $employeeUrl, 'time' => $timeUrl, 'summary' => $timeUrl . '&time=09%3A00'] as $name => $url) {
        $pages[$name] = http_request($cookies, $url);
        http_check($pages[$name]['status'] === 200 && substr_count($pages[$name]['body'], 'aria-current="step"') === 1
            && str_contains($pages[$name]['body'], 'Podsumowanie'), 'Single current booking step: ' . $name);
    }
    foreach (['Usługa', 'Pracownik', 'Data', 'Wybrana godzina', 'Czas trwania', 'Aktualna cena usługi'] as $label) {
        http_check(str_contains($pages['summary']['body'], '<dt>' . $label . '</dt>'), 'Summary includes ' . $label);
    }
    http_check(str_contains($pages['summary']['body'], 'booking-selected-time')
        && str_contains($pages['summary']['body'], '6. Potwierdź rezerwację'), 'Selected time and final action visible');
    $pages['empty'] = http_request($cookies, $employeeUrl . '&date=' . $date->modify('next sunday')->format('Y-m-d'));
    http_check(str_contains($pages['empty']['body'], 'Brak wolnych terminów') && str_contains($pages['empty']['body'], 'Zmień datę'), 'Empty day offers date change');
    $pages['selection-error'] = http_request($cookies, $timeUrl . '&time=08%3A00');
    http_check($pages['selection-error']['status'] === 422 && str_contains($pages['selection-error']['body'], 'href="' . e($timeUrl) . '"'), 'Invalid time recovers to same employee and date');
    http_check(booking_retry_url(['service_id' => ['1']]) === '/services.php'
        && booking_retry_url(['service_id' => '1', 'employee_id' => '//evil.example', 'date' => ['x']]) === $base,
        'Recovery URL is local and validates malformed input');
    $service = $connection->query('SELECT * FROM services WHERE id=1')->fetch();
    $hours = $connection->query('SELECT id, start_time, end_time FROM employee_availability WHERE employee_id=1 AND day_of_week=1 ORDER BY id LIMIT 1')->fetch();
    $before = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $baselineId = (int) $connection->query('SELECT COALESCE(MAX(id),0) FROM reservations')->fetchColumn();
    $post = ['service_id' => '1', 'employee_id' => '1', 'date' => $day, 'time' => '09:00', 'confirm' => '1', '_csrf' => http_csrf($pages['summary'])];
    try {
        $connection->prepare('UPDATE employee_availability SET start_time=:start WHERE id=:id')->execute(['start' => '09:00:30', 'id' => $hours['id']]);
        $times = array_column(booking_available_slots($service, ['id' => 1], $date, $connection), 'time');
        http_check(!in_array('09:00', $times, true) && in_array('09:15', $times, true), 'Availability seconds do not advertise outside-work slot');
        $result = booking_save(4, $post, $connection);
        http_check(!$result['success'] && $result['status'] === 422, 'Save agrees with exact availability seconds');
        $connection->prepare('UPDATE employee_availability SET start_time=:start WHERE id=:id')->execute(['start' => $hours['start_time'], 'id' => $hours['id']]);
        $connection->prepare("INSERT INTO reservations(user_id, employee_id, service_id, reservation_date, start_time, end_time, status) VALUES (4,1,1,:date,'10:00:00','11:00:30','confirmed')")->execute(['date' => $day]);
        $id = (int) $connection->lastInsertId();
        $times = array_column(booking_available_slots($service, ['id' => 1], $date, $connection), 'time');
        http_check(!in_array('11:00', $times, true) && in_array('11:15', $times, true), 'Reservation seconds do not advertise partial conflict');
        $pages['save-error'] = http_request($cookies, '/booking-save.php', 'POST', array_replace($post, ['time' => '11:00']));
        http_check($pages['save-error']['status'] === 409 && str_contains($pages['save-error']['body'], 'Wybrany termin został właśnie zajęty')
            && str_contains($pages['save-error']['body'], 'href="' . e($timeUrl) . '"'), 'Save conflict offers readable recovery without retrying INSERT');
        $pages['client-list'] = http_request($cookies, '/client-reservations.php');
        $pages['client-details'] = http_request($cookies, '/client-reservations.php?action=details&reservation_id=' . $id);
        $pages['staff-list'] = http_request($staff, '/employee.php?group=future');
        $pages['staff-details'] = http_request($staff, '/employee.php?action=details&reservation_id=' . $id);

        // Zmiana danych od podsumowania nie pozwala skrócić usługi ukrytym polem.
        $connection->prepare('UPDATE services SET duration=:duration WHERE id=1')->execute(['duration' => 120]);
        $response = http_request($cookies, '/booking-save.php', 'POST', $post + ['duration' => '1', 'end_time' => '09:01', 'price' => '0', 'status' => 'cancelled', 'user_id' => '5']);
        http_check($response['status'] === 409, 'Final save reloads changed duration and rejects collision despite forged fields');
        $connection->prepare('UPDATE services SET duration=:duration WHERE id=1')->execute(['duration' => $service['duration']]);
        foreach ([['service_id' => '999999'], ['employee_id' => '2'], ['date' => '2000-01-01'], ['time' => '08:00'], ['time' => '09:01']] as $bad) {
            $response = http_request($cookies, '/booking-save.php', 'POST', array_replace($post, $bad));
            http_check($response['status'] === 422 && http_safe($response) && str_contains($response['body'], 'Wróć do wyboru terminu'), 'Tampered selection rejects safely with next action');
        }
    } finally {
        $connection->prepare('DELETE FROM reservations WHERE id > :id')->execute(['id' => $baselineId]);
        $connection->prepare('UPDATE services SET duration=:duration WHERE id=1')->execute(['duration' => $service['duration']]);
        $connection->prepare('UPDATE employee_availability SET start_time=:start, end_time=:end WHERE id=:id')->execute(['start' => $hours['start_time'], 'end' => $hours['end_time'], 'id' => $hours['id']]);
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $before, 'UX tests preserve seed reservations');
    // Opcjonalne migawki do renderowania w przeglądarce; tylko nowy katalog testowy w TEMP.
    $export = getenv('CUTTIME_UI_DIR');
    if ($export && is_dir($export) && realpath(dirname($export)) === realpath(sys_get_temp_dir())
        && preg_match('/\Acuttime-ux-[a-f0-9]{16}\z/', basename($export))) {
        copy(dirname(__DIR__) . '/public/css/auth.css', $export . '/auth.css');
        foreach ($pages as $name => $page) {
            $html = str_replace(['/css/auth.css', '<script src="/js/session.js" defer></script>'], ['auth.css', ''], $page['body']);
            file_put_contents($export . '/' . $name . '.html', $html);
        }
        file_put_contents($export . '/pages.json', json_encode(array_keys($pages), JSON_THROW_ON_ERROR));
    }
}
