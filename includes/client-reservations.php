<?php
declare(strict_types=1);
require_once __DIR__ . '/booking.php';
// Istniejący helper paginacji nie wykonuje autoryzacji ani zapytań administracyjnych.
require_once __DIR__ . '/admin-pagination.php';

const CLIENT_RESERVATION_GROUPS = [
    'upcoming' => 'Nadchodzące', 'history' => 'Historia', 'cancelled' => 'Anulowane',
];
const CLIENT_RESERVATION_SELECT = 'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status,
    s.name AS service_name, s.price, u.name AS employee_name, u.surname AS employee_surname
    FROM reservations r JOIN services s ON s.id = r.service_id
    JOIN employees e ON e.id = r.employee_id JOIN users u ON u.id = e.user_id';

/** ID klienta zawsze pochodzi z require_role(), nigdy z filtrów. */
function client_reservations_list(int $clientId, string $group, int $page, ?PDO $connection = null): array
{
    $connection ??= db();
    $now = booking_now()->format('Y-m-d H:i:s');
    $condition = match ($group) {
        'upcoming' => "r.status IN ('pending', 'confirmed') AND TIMESTAMP(r.reservation_date, r.start_time) > :now",
        'history' => "r.status <> 'cancelled' AND (r.status = 'completed' OR TIMESTAMP(r.reservation_date, r.start_time) <= :now)",
        'cancelled' => "r.status = 'cancelled'",
        default => throw new InvalidArgumentException('Unknown reservation group'),
    };
    $where = ' WHERE r.user_id = :client AND ' . $condition;
    $parameters = ['client' => $clientId];
    if ($group !== 'cancelled') { $parameters['now'] = $now; }
    $count = $connection->prepare('SELECT COUNT(*) FROM reservations r' . $where);
    $count->execute($parameters);
    $listing = admin_pagination((int) $count->fetchColumn(), $page);
    $order = $group === 'upcoming' ? ' ASC' : ' DESC';
    $read = $connection->prepare(CLIENT_RESERVATION_SELECT . $where
        . ' ORDER BY r.reservation_date' . $order . ', r.start_time' . $order . ', r.id' . $order
        . ' LIMIT :limit OFFSET :offset');
    foreach ($parameters as $key => $value) { $read->bindValue($key, $value); }
    $read->bindValue('limit', $listing['limit'], PDO::PARAM_INT);
    $read->bindValue('offset', $listing['offset'], PDO::PARAM_INT);
    $read->execute();
    $listing['rows'] = $read->fetchAll();
    return $listing;
}

function client_reservation_find(int $clientId, int $id, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $read = $connection->prepare(CLIENT_RESERVATION_SELECT . ' WHERE r.id = :id AND r.user_id = :client');
    $read->execute(['id' => $id, 'client' => $clientId]);
    $row = $read->fetch();
    return $row === false ? null : $row;
}

function client_reservation_can_cancel(array $row): bool
{
    return in_array($row['status'], RESERVATION_CANCELLABLE_STATUSES, true)
        && new DateTimeImmutable($row['reservation_date'] . ' ' . $row['start_time'], new DateTimeZone('Europe/Warsaw')) > booking_now();
}

/** Ponowna kontrola własności, czasu i statusu po uzyskaniu blokad. */
function client_reservation_cancel(int $clientId, int $id, ?PDO $connection = null): array
{
    $connection ??= db();
    $missing = ['success' => false, 'status' => 404, 'message' => 'Nie znaleziono rezerwacji na Twoim koncie.'];
    // Odczyt dotyczy wyłącznie właściciela; cudze ID nie ujawnia żadnych danych.
    $lookup = $connection->prepare('SELECT employee_id FROM reservations WHERE id = :id AND user_id = :client');
    $lookup->execute(['id' => $id, 'client' => $clientId]);
    $employeeId = $lookup->fetchColumn();
    if ($employeeId === false) { return $missing; }
    try {
        $connection->beginTransaction();
        // Ta sama kolejność blokad co podczas tworzenia wizyty.
        $lock = $connection->prepare('SELECT id FROM employees WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $employeeId]);
        $lock->fetchColumn();
        $read = $connection->prepare('SELECT employee_id, reservation_date, start_time, status FROM reservations WHERE id = :id AND user_id = :client FOR UPDATE');
        $read->execute(['id' => $id, 'client' => $clientId]);
        $row = $read->fetch();
        if ($row === false) { return $missing; }
        if ((int) $row['employee_id'] !== (int) $employeeId || !client_reservation_can_cancel($row)) {
            return ['success' => false, 'status' => 409, 'message' => 'Możesz anulować tylko przyszłą wizytę oczekującą lub potwierdzoną. Odśwież listę rezerwacji.'];
        }
        $update = $connection->prepare("UPDATE reservations SET status = 'cancelled'
            WHERE id = :id AND user_id = :client AND status = :expected
            AND TIMESTAMP(reservation_date, start_time) > :now");
        $update->execute(['id' => $id, 'client' => $clientId, 'expected' => $row['status'], 'now' => booking_now()->format('Y-m-d H:i:s')]);
        if ($update->rowCount() !== 1) {
            return ['success' => false, 'status' => 409, 'message' => 'Nie można już anulować tej wizyty. Odśwież listę rezerwacji.'];
        }
        $connection->commit();
        return ['success' => true, 'status' => 200, 'message' => 'Rezerwacja została anulowana. Znajdziesz ją w zakładce Anulowane.'];
    } catch (PDOException $error) {
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
            return ['success' => false, 'status' => 409, 'message' => 'Wizyta jest właśnie aktualizowana. Odśwież listę i spróbuj ponownie.'];
        }
        throw $error;
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
    }
}
