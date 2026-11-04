<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-forms.php';

// Operacje modułu; publiczny kontroler najpierw wymaga roli admin i CSRF dla POST.
function validate_category(array $input): array
{
    return validate_offer_details($input, 100, 'kategorii');
}

function category_list(int $page, ?PDO $connection = null): array
{
    $connection ??= db();
    $count = $connection->prepare('SELECT COUNT(*) FROM service_categories');
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 20));
    $page = min(max(1, $page), $pages);
    $statement = $connection->prepare(
        'SELECT c.id, c.name, LEFT(c.description, 160) AS description, c.active,
            (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) AS services_count
         FROM service_categories c ORDER BY c.name, c.id LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', 20, PDO::PARAM_INT);
    $statement->bindValue('offset', ($page - 1) * 20, PDO::PARAM_INT);
    $statement->execute();
    return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function category_find(int $id, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT c.id, c.name, c.description, c.active,
            (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) AS services_count
         FROM service_categories c WHERE c.id = :id'
    );
    $statement->execute(['id' => $id]);
    $category = $statement->fetch();
    return $category === false ? null : $category;
}

function category_save(array $input, ?int $id = null, ?PDO $connection = null): array
{
    $result = validate_category($input) + ['success' => false, 'status' => 422];
    if ($result['errors'] !== []) {
        return $result;
    }
    $connection ??= db();
    if ($id !== null && category_find($id, $connection) === null) {
        $result['errors']['_form'] = 'Nie znaleziono kategorii. Mogła zostać usunięta.';
        $result['status'] = 404;
        return $result;
    }
    $duplicateMessage = 'Kategoria o tej nazwie już istnieje. Wybierz inną nazwę.';
    $duplicate = $connection->prepare('SELECT id FROM service_categories WHERE name = :name AND id <> :id LIMIT 1');
    $duplicate->execute(['name' => $result['values']['name'], 'id' => $id ?? 0]);
    if ($duplicate->fetchColumn() !== false) {
        $result['errors']['name'] = $duplicateMessage;
        return $result;
    }
    $data = $result['values'];
    $data['description'] = $data['description'] === '' ? null : $data['description'];
    try {
        if ($id === null) {
            $statement = $connection->prepare('INSERT INTO service_categories (name, description, active) VALUES (:name, :description, :active)');
        } else {
            $statement = $connection->prepare('UPDATE service_categories SET name = :name, description = :description, active = :active WHERE id = :id');
            $data['id'] = $id;
        }
        $statement->execute($data);
    } catch (PDOException $error) {
        // UNIQUE chroni także równoległy zapis po wstępnym SELECT.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            $result['errors']['name'] = $duplicateMessage;
            return $result;
        }
        throw $error;
    }
    if ($id !== null && $statement->rowCount() === 0 && category_find($id, $connection) === null) {
        $result['errors']['_form'] = 'Nie znaleziono kategorii. Mogła zostać usunięta.';
        $result['status'] = 404;
        return $result;
    }
    $result['success'] = true;
    return $result;
}

function category_delete(int $id, ?PDO $connection = null): array
{
    $connection ??= db();
    $category = category_find($id, $connection);
    $missing = ['success' => false, 'status' => 404, 'errors' => ['_form' => 'Nie znaleziono kategorii. Mogła zostać usunięta.']];
    $dependent = ['success' => false, 'status' => 409, 'errors' => ['_form' => 'Nie można usunąć kategorii, ponieważ ma przypisane usługi.']];
    if ($category === null) {
        return $missing;
    }
    if ((int) $category['services_count'] > 0) {
        return $dependent;
    }
    try {
        $statement = $connection->prepare('DELETE FROM service_categories WHERE id = :id');
        $statement->execute(['id' => $id]);
    } catch (PDOException $error) {
        // Usługa mogła zostać przypisana po SELECT. Istniejący FK RESTRICT nadal chroni dane.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1451) {
            return $dependent;
        }
        throw $error;
    }
    return $statement->rowCount() === 1 ? ['success' => true, 'errors' => []] : $missing;
}
