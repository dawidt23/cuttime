<?php
declare(strict_types=1);

/** Weryfikacja wyboru pracownika i serwerowego terminarza na własnej bazie HTTP. */
function run_booking_http_tests(PDO $connection, array $sessions): void
{
    require_once dirname(__DIR__) . '/includes/offer.php';
    require_once dirname(__DIR__) . '/includes/booking.php';

    $guest = [];
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    $monday = $now->modify('next monday')->setTime(0, 0);
    $date = $monday->format('Y-m-d');
    $base = '/booking.php?service_id=1';
    $employeeUrl = $base . '&employee_id=1';
    $dateUrl = $employeeUrl . '&date=' . rawurlencode($date);
    $baseline = $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();

    foreach ([[570, 630], [600, 660], [615, 645], [630, 690], [570, 690]] as [$start, $end]) {
        http_check(booking_intervals_overlap($start, $end, 600, 660), 'Pure overlap rule blocks: ' . $start . '-' . $end);
    }
    foreach ([[540, 600], [660, 720]] as [$start, $end]) {
        http_check(!booking_intervals_overlap($start, $end, 600, 660), 'Pure overlap rule permits touching boundary: ' . $start . '-' . $end);
    }

    $response = http_request($guest, $base);
    http_check($response['status'] === 200 && http_safe($response)
        && str_contains($response['body'], 'Anna Kowalska')
        && !str_contains($response['body'], 'Piotr Wiśniewski')
        && str_contains($response['body'], 'employee_id=1'), 'Only active employees assigned to service are displayed');
    foreach (['2', '999999', '4294967295'] as $employeeId) {
        $response = http_request($guest, $base . '&employee_id=' . $employeeId);
        http_check($response['status'] === 404 && http_safe($response), 'Unassigned or missing employee rejected: ' . $employeeId);
    }
    foreach (['', '0', '-1', '01', '1.5', '1e0', '+1', ' 1 ', ['1'], '<script>alert(1)</script>'] as $employeeId) {
        $response = http_request($guest, $base . '&' . http_build_query(['employee_id' => $employeeId]));
        http_check($response['status'] === 400 && http_safe($response)
            && !str_contains($response['body'], '<script>alert(1)</script>'), 'Invalid employee ID rejected safely');
    }
    $response = http_request($guest, $employeeUrl);
    http_check($response['status'] === 200 && str_contains($response['body'], 'name="date"')
        && str_contains($response['body'], 'min="' . $now->format('Y-m-d') . '"'), 'Selected employee leads to server-backed date form');
    foreach ([$now->modify('-1 day')->format('Y-m-d'), '2026-02-30', 'not-a-date', '99999-01-01', ['2027-01-01']] as $badDate) {
        $response = http_request($guest, $employeeUrl . '&' . http_build_query(['date' => $badDate]));
        http_check($response['status'] === 422 && http_safe($response), 'Past or invalid date rejected: ' . (is_array($badDate) ? 'array' : $badDate));
    }

    $insert = $connection->prepare(
        'INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status)
         VALUES (4, 1, 1, :date, :start, :end, :status)'
    );
    $fixtureIds = [];
    foreach ([
        ['10:00:00', '11:00:00', 'confirmed'],
        ['12:00:00', '13:00:00', 'pending'],
        ['14:00:00', '15:00:00', 'cancelled'],
        ['15:00:00', '16:00:00', 'completed'],
    ] as [$start, $end, $status]) {
        $insert->execute(['date' => $date, 'start' => $start, 'end' => $end, 'status' => $status]);
        $fixtureIds[] = (int) $connection->lastInsertId();
    }
    try {
        $response = http_request($guest, $dateUrl . '&duration=1&active=0&role=admin');
        foreach (['09%3A00', '11%3A00', '13%3A00', '14%3A00', '16%3A00'] as $time) {
            http_check($response['status'] === 200 && str_contains($response['body'], 'time=' . $time), 'Available full interval shown: ' . $time);
        }
        foreach (['09%3A15', '09%3A30', '09%3A45', '10%3A00', '10%3A15', '10%3A30', '10%3A45', '11%3A15', '12%3A00', '15%3A00', '15%3A15', '16%3A15'] as $time) {
            http_check(!str_contains($response['body'], 'time=' . $time), 'Overlapping or outside-work slot excluded: ' . $time);
        }
        $response = http_request($guest, $dateUrl . '&time=09%3A00');
        http_check($response['status'] === 200 && str_contains($response['body'], 'Rezerwacja nie została jeszcze utworzona'), 'Available time reaches summary without write');
        foreach (['09:15', '10:00', '10:15', '10:30', '10:45', '11:15', '12:00', '15:00', '16:15'] as $time) {
            $response = http_request($guest, $dateUrl . '&' . http_build_query(['time' => $time]));
            http_check($response['status'] === 422 && http_safe($response), 'Forged occupied or outside time rejected: ' . $time);
        }
        foreach (['09:00:00', '9:00', '25:00', '09:60', '09:00<script>', ['09:00']] as $time) {
            $response = http_request($guest, $dateUrl . '&' . http_build_query(['time' => $time]));
            http_check($response['status'] === 400 && http_safe($response), 'Malformed time rejected safely');
        }
        $service = offered_services(1, $connection)[0];
        $employee = booking_employee_for_service(1, 1, $connection);
        $slots = booking_available_slots($service, $employee, $monday, $connection, $monday->setTime(8, 0));
        $slotTimes = array_column($slots, 'time');
        http_check(in_array('09:00', $slotTimes, true) && in_array('11:00', $slotTimes, true)
            && !in_array('09:30', $slotTimes, true) && !in_array('10:30', $slotTimes, true),
            'Overlap rule blocks partial and nested collisions but permits touching boundaries');
        http_check(in_array('14:00', $slotTimes, true), 'Cancelled reservation does not block a touching available slot');
        $sameDaySlots = booking_available_slots($service, $employee, $monday, $connection, $monday->setTime(16, 5));
        http_check(!in_array('16:00', array_column($sameDaySlots, 'time'), true), 'Past start time on current day is never proposed');
        http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() !== $baseline, 'Fixtures exist only during booking availability test');
    } finally {
        $delete = $connection->prepare('DELETE FROM reservations WHERE id = :id');
        foreach ($fixtureIds as $fixtureId) {
            $delete->execute(['id' => $fixtureId]);
        }
    }
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $baseline, 'Booking availability tests restore all reservations');

    $connection->prepare('UPDATE employees SET active = 0 WHERE id = 1')->execute();
    try {
        $response = http_request($guest, $base);
        http_check($response['status'] === 200 && !str_contains($response['body'], 'Anna Kowalska'), 'Inactive employee hidden from selection');
        $response = http_request($guest, $employeeUrl);
        http_check($response['status'] === 404 && http_safe($response), 'Inactive employee cannot be restored through URL');
    } finally {
        $connection->prepare('UPDATE employees SET active = 1 WHERE id = 1')->execute();
    }
    $response = http_request($guest, $employeeUrl . '&date=' . $monday->modify('+6 days')->format('Y-m-d'));
    http_check($response['status'] === 200 && str_contains($response['body'], 'Brak wolnych terminów'), 'Day without employee availability has no proposed slots');
    http_check($connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll() === $baseline, 'Selection pages never insert or update reservations');
}
