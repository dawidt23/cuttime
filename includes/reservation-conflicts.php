<?php
declare(strict_types=1);
require_once __DIR__ . '/reservation-statuses.php';

/** Wywołuj w transakcji PO zablokowaniu rekordu pracownika FOR UPDATE. */
function reservation_has_conflict(PDO $connection, int $employeeId, string $date, string $start, string $end, ?int $excludeId = null): bool
{
    $parameters = ['employee' => $employeeId, 'date' => $date, 'start' => $start, 'end' => $end];
    $statuses = [];
    foreach (BOOKING_BLOCKING_STATUSES as $index => $status) {
        $statuses[] = ':status' . $index;
        $parameters['status' . $index] = $status;
    }
    $exclude = '';
    if ($excludeId !== null) {
        $exclude = ' AND id <> :exclude';
        $parameters['exclude'] = $excludeId;
    }
    // Aktualny odczyt, nie snapshot sprzed oczekiwania na blokadę rodzica.
    $read = $connection->prepare('SELECT id FROM reservations WHERE employee_id = :employee AND reservation_date = :date
        AND start_time < :end AND end_time > :start AND status IN (' . implode(',', $statuses) . ')' . $exclude . ' LIMIT 1 FOR UPDATE');
    $read->execute($parameters);
    return $read->fetchColumn() !== false;
}
