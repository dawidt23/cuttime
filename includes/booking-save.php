<?php
declare(strict_types=1);
require_once __DIR__ . '/booking.php';

/** $clientId pochodzi wyłącznie z require_role('client'), nigdy z żądania. */
function booking_save(int $clientId, array $input, ?PDO $connection = null): array
{
    $fail = static fn(string $message, int $status = 422): array => ['success' => false, 'status' => $status, 'message' => $message];
    $serviceId = record_id($input['service_id'] ?? null);
    $employeeId = record_id($input['employee_id'] ?? null);
    $time = booking_selected_time($input['time'] ?? null);
    $checked = booking_date($input['date'] ?? null);
    if ($serviceId === null || $employeeId === null || $time === null || $checked['error'] !== null) {
        return $fail($checked['error'] ?? 'Wybierz poprawną usługę, pracownika i godzinę.');
    }
    if (($input['confirm'] ?? null) !== '1') { return $fail('Potwierdź wybrany termin wizyty.'); }
    $connection ??= db();
    try {
        $connection->beginTransaction();
        // Ten sam rodzic co w administracyjnej zmianie grafiku i przypisań.
        // Blokada działa także, gdy pracownik nie ma jeszcze żadnej rezerwacji.
        $lock = $connection->prepare('SELECT id FROM employees WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $employeeId]);
        if ($lock->fetchColumn() === false) { return $fail('Wybrany pracownik nie jest dostępny.'); }

        // Odczyty blokujące widzą aktualny stan po oczekiwaniu na blokadę.
        // Utrzymujemy aktywność, rolę, usługę i kwalifikacje do końca transakcji.
        $read = $connection->prepare(
            "SELECT s.duration FROM services s
             JOIN service_categories c ON c.id = s.category_id
             JOIN employee_services es ON es.service_id = s.id
             JOIN employees e ON e.id = es.employee_id
             JOIN users u ON u.id = e.user_id
             WHERE s.id = :service AND e.id = :employee AND s.active = 1 AND c.active = 1
               AND e.active = 1 AND u.active = 1 AND u.role = 'employee' FOR UPDATE"
        );
        $read->execute(['service' => $serviceId, 'employee' => $employeeId]);
        $duration = $read->fetchColumn();
        if ($duration === false) { return $fail('Usługa lub pracownik nie są już dostępni. Wybierz termin ponownie.'); }
        $client = $connection->prepare("SELECT id FROM users WHERE id = :id AND role = 'client' AND active = 1 LOCK IN SHARE MODE");
        $client->execute(['id' => $clientId]);
        if ($client->fetchColumn() === false) { return $fail('Zaloguj się na aktywne konto klienta.', 403); }

        $start = booking_time_minutes($time);
        $end = $start + (int) $duration;
        $date = $checked['date'];
        if ($start % BOOKING_SLOT_MINUTES !== 0 || $end >= 1440 || (int) $duration < 1) {
            return $fail('Ta godzina nie jest dostępna dla wybranej usługi.');
        }
        $startTime = $time . ':00';
        $endTime = booking_time_label($end) . ':00';
        $hours = $connection->prepare(
            'SELECT id FROM employee_availability WHERE employee_id = :employee AND day_of_week = :day
             AND start_time <= :start AND end_time >= :end LIMIT 1 FOR UPDATE'
        );
        $hours->execute(['employee' => $employeeId, 'day' => (int) $date->format('N'), 'start' => $startTime, 'end' => $endTime]);
        if ($hours->fetchColumn() === false) { return $fail('Cała wizyta musi mieścić się w godzinach pracy pracownika.'); }

        $parameters = ['employee' => $employeeId, 'date' => $date->format('Y-m-d'), 'start' => $startTime, 'end' => $endTime];
        $statuses = [];
        foreach (BOOKING_BLOCKING_STATUSES as $index => $status) {
            $statuses[] = ':status' . $index;
            $parameters['status' . $index] = $status;
        }
        $conflict = $connection->prepare(
            'SELECT id FROM reservations WHERE employee_id = :employee AND reservation_date = :date
             AND start_time < :end AND end_time > :start AND status IN (' . implode(',', $statuses) . ') LIMIT 1 FOR UPDATE'
        );
        $conflict->execute($parameters);
        if ($conflict->fetchColumn() !== false) {
            return $fail('Wybrany termin został właśnie zajęty. Wybierz inną godzinę.', 409);
        }
        // Czas sprawdzamy ponownie po wszystkich możliwych oczekiwaniach na blokady.
        if (booking_slot_start($date, $start) <= booking_now()) { return $fail('Nie można zarezerwować terminu w przeszłości.'); }
        $insert = $connection->prepare(
            "INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status)
             VALUES (:client, :employee, :service, :date, :start, :end, 'pending')"
        );
        $insert->execute(['client' => $clientId, 'employee' => $employeeId, 'service' => $serviceId,
            'date' => $date->format('Y-m-d'), 'start' => $startTime, 'end' => $endTime]);
        $id = (int) $connection->lastInsertId();
        $connection->commit();
        return ['success' => true, 'id' => $id, 'message' => 'Zapisano rezerwację nr ' . $id . ' na ' . $date->format('d.m.Y') . ', ' . $time . '–' . booking_time_label($end) . '. Status: oczekująca.'];
    } catch (PDOException $error) {
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 1452, 1062], true)) {
            return $fail('Dane zmieniły się podczas zapisu lub termin jest teraz rezerwowany. Wybierz godzinę ponownie.', 409);
        }
        throw $error; // Istniejący handler usuwa szczegóły techniczne z odpowiedzi.
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
    }
}
