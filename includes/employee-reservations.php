<?php
declare(strict_types=1);
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/admin-pagination.php';

const EMPLOYEE_RESERVATION_GROUPS = ['today' => 'Dzisiaj', 'future' => 'Przyszłe wizyty', 'history' => 'Historia'];
const EMPLOYEE_RESERVATION_FROM = ' FROM reservations r
    JOIN employees e ON e.id = r.employee_id
    JOIN users c ON c.id = r.user_id JOIN services s ON s.id = r.service_id';
const EMPLOYEE_RESERVATION_FIELDS = 'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status,
    c.name AS client_name, c.surname AS client_surname, s.name AS service_name';

/** $userId pochodzi wyłącznie z require_role(employee). */
function employee_reservation_profile(int $userId, ?PDO $connection = null, bool $lock = false): ?array
{
    $connection ??= db();
    $read = $connection->prepare('SELECT id FROM employees WHERE user_id = :user AND active = 1' . ($lock ? ' FOR UPDATE' : ''));
    $read->execute(['user' => $userId]);
    $row = $read->fetch();
    return $row === false ? null : $row;
}

function employee_reservations_list(int $userId, string $group, int $page, ?PDO $connection = null): array
{
    $connection ??= db();
    $condition = match ($group) {
        'today' => 'r.reservation_date = :today',
        'future' => "r.reservation_date > :today AND r.status IN ('pending', 'confirmed')",
        'history' => "(r.reservation_date < :today OR r.status IN ('completed', 'cancelled'))",
        default => throw new InvalidArgumentException('Unknown visit group'),
    };
    $where = ' WHERE e.user_id = :user AND e.active = 1 AND ' . $condition;
    $parameters = ['user' => $userId, 'today' => booking_now()->format('Y-m-d')];
    $count = $connection->prepare('SELECT COUNT(*) FROM reservations r JOIN employees e ON e.id = r.employee_id' . $where);
    $count->execute($parameters);
    $listing = admin_pagination((int) $count->fetchColumn(), $page);
    $order = $group === 'history' ? ' DESC' : ' ASC';
    $read = $connection->prepare(EMPLOYEE_RESERVATION_FIELDS . EMPLOYEE_RESERVATION_FROM . $where
        . ' ORDER BY r.reservation_date' . $order . ', r.start_time' . $order . ', r.id' . $order . ' LIMIT :limit OFFSET :offset');
    foreach ($parameters as $key => $value) { $read->bindValue($key, $value); }
    $read->bindValue('limit', $listing['limit'], PDO::PARAM_INT);
    $read->bindValue('offset', $listing['offset'], PDO::PARAM_INT);
    $read->execute();
    $listing['rows'] = $read->fetchAll();
    return $listing;
}

function employee_reservation_find(int $userId, int $id, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $read = $connection->prepare(EMPLOYEE_RESERVATION_FIELDS . ', c.phone AS client_phone, r.comment'
        . EMPLOYEE_RESERVATION_FROM . ' WHERE r.id = :id AND e.user_id = :user AND e.active = 1');
    $read->execute(['id' => $id, 'user' => $userId]);
    $row = $read->fetch();
    return $row === false ? null : $row;
}

/** Jeden model przejść dla formularza i kontroli bezpośrednio przed UPDATE. */
function employee_reservation_status_options(array $row): array
{
    $options = EMPLOYEE_RESERVATION_TRANSITIONS[$row['status']] ?? [];
    $end = new DateTimeImmutable($row['reservation_date'] . ' ' . $row['end_time'], new DateTimeZone('Europe/Warsaw'));
    if ($end > booking_now()) { $options = array_values(array_diff($options, ['completed'])); }
    return $options;
}

function employee_reservation_change_status(int $userId, int $id, array $input, ?PDO $connection = null): array
{
    $fail = static fn(string $message, int $status = 422): array => ['success' => false, 'status' => $status, 'message' => $message];
    $target = $input['status'] ?? null;
    $expected = $input['expected_status'] ?? null;
    if (!is_string($target) || !isset(RESERVATION_STATUSES[$target])
        || !is_string($expected) || !isset(RESERVATION_STATUSES[$expected])) {
        return $fail('Wybierz poprawny status z formularza wizyty.');
    }
    if (($input['confirm'] ?? null) !== '1') { return $fail('Potwierdź zmianę statusu wizyty.'); }
    $connection ??= db();
    try {
        $connection->beginTransaction();
        // Ta sama kolejność co zapis/anulowanie klienta: pracownik, potem wizyta.
        $employee = employee_reservation_profile($userId, $connection, true);
        if ($employee === null) { return $fail('Nie masz aktywnego profilu pracownika. Skontaktuj się z administratorem.', 403); }
        $read = $connection->prepare('SELECT reservation_date, end_time, status FROM reservations WHERE id = :id AND employee_id = :employee FOR UPDATE');
        $read->execute(['id' => $id, 'employee' => $employee['id']]);
        $row = $read->fetch();
        if ($row === false) { return $fail('Nie znaleziono wizyty w Twoim terminarzu.', 404); }
        if ($row['status'] !== $expected) { return $fail('Status wizyty zmienił się. Otwórz szczegóły ponownie.', 409); }
        if (!in_array($target, employee_reservation_status_options($row), true)) {
            return $fail('Ta zmiana statusu nie jest dozwolona. Realizację można oznaczyć dopiero po planowanym końcu wizyty.', 409);
        }
        $update = $connection->prepare('UPDATE reservations SET status = :status WHERE id = :id AND employee_id = :employee AND status = :expected');
        $update->execute(['status' => $target, 'id' => $id, 'employee' => $employee['id'], 'expected' => $expected]);
        if ($update->rowCount() !== 1) { return $fail('Nie udało się zmienić statusu. Odśwież terminarz.', 409); }
        $connection->commit();
        return ['success' => true, 'status' => 200, 'message' => 'Zmieniono status wizyty na: ' . RESERVATION_STATUSES[$target] . '.'];
    } catch (PDOException $error) {
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
            return $fail('Wizyta jest właśnie aktualizowana. Odśwież szczegóły i spróbuj ponownie.', 409);
        }
        throw $error;
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
    }
}
