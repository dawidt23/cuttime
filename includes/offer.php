<?php
declare(strict_types=1);

/** Publiczna oferta: te same warunki dla listy, szczegółów i rozpoczęcia rezerwacji. */
function offered_services(?int $id = null, ?PDO $connection = null): array
{
    $connection ??= db();
    $sql = 'SELECT s.id, s.name, s.description, s.price, s.duration, c.name AS category_name
            FROM services s JOIN service_categories c ON c.id = s.category_id
            WHERE s.active = 1 AND c.active = 1';
    if ($id !== null) {
        $sql .= ' AND s.id = :id';
    }
    $statement = $connection->prepare($sql . ' ORDER BY c.name, s.name, s.id');
    $statement->execute($id === null ? [] : ['id' => $id]);
    return $statement->fetchAll();
}
