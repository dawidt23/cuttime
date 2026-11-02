<?php
declare(strict_types=1);

/** Tylko odczyt. Kontroler musi wcześniej wywołać require_role('admin'). */
function admin_dashboard(?PDO $connection = null, ?DateTimeImmutable $now = null): array
{
    $connection ??= db();
    // Jedna data dla zapytania i widoku, niezależna od strefy PHP/MySQL.
    $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Warsaw'));
    $statement = $connection->prepare(
        'SELECT
            (SELECT COUNT(*) FROM users WHERE role = :client_role) AS clients,
            (SELECT COUNT(*) FROM employees) AS employees,
            (SELECT COUNT(*) FROM services) AS services,
            (SELECT COUNT(*) FROM reservations) AS reservations,
            (SELECT COUNT(*) FROM reservations WHERE reservation_date = :today) AS today_reservations,
            (SELECT COUNT(*) FROM reservations WHERE status = :pending_status) AS pending_reservations'
    );
    $statement->execute([
        'client_role' => 'client',
        'today' => $today->format('Y-m-d'),
        'pending_status' => 'pending',
    ]);
    $counts = $statement->fetch(PDO::FETCH_ASSOC);
    if ($counts === false) {
        throw new RuntimeException('Unable to read dashboard statistics.');
    }

    return [
        'date' => $today->format('Y-m-d'),
        'date_label' => $today->format('d.m.Y'),
        'counts' => array_map(static fn($count): int => (int) $count, $counts),
    ];
}
