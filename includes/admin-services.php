<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-forms.php';

function validate_service(array $input): array
{
    $result = validate_offer_details($input, 150, 'usługi');
    $result['values'] += ['category_id' => '', 'price' => '', 'duration' => ''];
    $categoryId = admin_record_id($input['category_id'] ?? null);
    if ($categoryId === null) {
        $result['errors']['category_id'] = 'Wybierz poprawną kategorię.';
    } else {
        $result['values']['category_id'] = (string) $categoryId;
    }

    // DECIMAL(10,2): maks. 8 cyfr przed separatorem. Nigdy nie zaokrąglamy floatów.
    $price = $input['price'] ?? '';
    if (!is_string($price) || strlen($price) > 32
        || !preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:[.,][0-9]{1,2})?\z/', trim($price, ' '))) {
        $result['errors']['price'] = 'Podaj cenę od 0 do 99999999,99 zł, z maksymalnie 2 cyframi po przecinku.';
    } else {
        $parts = explode('.', str_replace(',', '.', trim($price, ' ')));
        $result['values']['price'] = $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }
    $duration = admin_record_id($input['duration'] ?? null);
    if ($duration === null || $duration > 1440) {
        $result['errors']['duration'] = 'Podaj czas trwania w pełnych minutach od 1 do 1440.';
    } else {
        $result['values']['duration'] = (string) $duration;
    }
    return $result;
}

function service_categories(?PDO $connection = null): array
{
    $connection ??= db();
    $statement = $connection->prepare('SELECT id, name, active FROM service_categories ORDER BY name, id');
    $statement->execute();
    return $statement->fetchAll();
}

function service_list(int $page, ?PDO $connection = null): array
{
    $connection ??= db();
    $count = $connection->prepare('SELECT COUNT(*) FROM services');
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 20));
    $page = min(max(1, $page), $pages);
    $statement = $connection->prepare(
        'SELECT s.id, s.name, s.price, s.duration, s.active, c.name AS category_name, c.active AS category_active,
            (SELECT COUNT(*) FROM reservations r WHERE r.service_id = s.id) AS reservations_count
         FROM services s JOIN service_categories c ON c.id = s.category_id
         ORDER BY s.name, s.id LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', 20, PDO::PARAM_INT);
    $statement->bindValue('offset', ($page - 1) * 20, PDO::PARAM_INT);
    $statement->execute();
    return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function service_find(int $id, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT s.id, s.category_id, s.name, s.description, s.price, s.duration, s.active,
            (SELECT COUNT(*) FROM reservations r WHERE r.service_id = s.id) AS reservations_count,
            (SELECT COUNT(*) FROM employee_services es WHERE es.service_id = s.id) AS employees_count
         FROM services s WHERE s.id = :id'
    );
    $statement->execute(['id' => $id]);
    $service = $statement->fetch();
    return $service === false ? null : $service;
}

function service_save(array $input, ?int $id = null, ?PDO $connection = null): array
{
    $result = validate_service($input) + ['success' => false, 'status' => 422];
    if ($result['errors'] !== []) {
        return $result;
    }
    $connection ??= db();
    if ($id !== null && service_find($id, $connection) === null) {
        $result['errors']['_form'] = 'Nie znaleziono usługi. Mogła zostać usunięta.';
        $result['status'] = 404;
        return $result;
    }
    $category = $connection->prepare('SELECT id FROM service_categories WHERE id = :id');
    $category->execute(['id' => $result['values']['category_id']]);
    $categoryError = 'Wybrana kategoria nie istnieje. Wybierz ją ponownie.';
    if ($category->fetchColumn() === false) {
        $result['errors']['category_id'] = $categoryError;
        return $result;
    }
    $data = $result['values'];
    $data['description'] = $data['description'] === '' ? null : $data['description'];
    try {
        if ($id === null) {
            $statement = $connection->prepare(
                'INSERT INTO services (category_id, name, description, price, duration, active)
                 VALUES (:category_id, :name, :description, :price, :duration, :active)'
            );
        } else {
            $statement = $connection->prepare(
                'UPDATE services SET category_id = :category_id, name = :name, description = :description,
                 price = :price, duration = :duration, active = :active WHERE id = :id'
            );
            $data['id'] = $id;
        }
        $statement->execute($data);
    } catch (PDOException $error) {
        // Kategoria może zniknąć pomiędzy odczytem a zapisem. FK pozostaje rozstrzygające.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1452) {
            $result['errors']['category_id'] = $categoryError;
            return $result;
        }
        throw $error;
    }
    if ($id !== null && $statement->rowCount() === 0 && service_find($id, $connection) === null) {
        $result['errors']['_form'] = 'Nie znaleziono usługi. Mogła zostać usunięta.';
        $result['status'] = 404;
        return $result;
    }
    $result['success'] = true;
    return $result;
}

function service_set_active(int $id, bool $active, ?PDO $connection = null): array
{
    $connection ??= db();
    // Jawny docelowy stan; ponowne wysłanie nie odwraca przypadkowo statusu.
    $statement = $connection->prepare('UPDATE services SET active = :active WHERE id = :id');
    $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    if ($statement->rowCount() === 0 && service_find($id, $connection) === null) {
        return ['success' => false, 'status' => 404, 'errors' => ['_form' => 'Nie znaleziono usługi. Mogła zostać usunięta.']];
    }
    return ['success' => true, 'errors' => []];
}

function service_delete(int $id, ?PDO $connection = null): array
{
    $connection ??= db();
    $service = service_find($id, $connection);
    $missing = ['success' => false, 'status' => 404, 'errors' => ['_form' => 'Nie znaleziono usługi. Mogła zostać usunięta.']];
    $dependent = ['success' => false, 'status' => 409, 'errors' => ['_form' => 'Usługa ma rezerwacje i nie może zostać usunięta. Zamiast tego możesz ją dezaktywować.']];
    if ($service === null) {
        return $missing;
    }
    if ((int) $service['reservations_count'] > 0) {
        return $dependent;
    }
    try {
        // FK CASCADE usuwa wyłącznie powiązania employee_services; nie usuwa pracowników.
        $statement = $connection->prepare('DELETE FROM services WHERE id = :id');
        $statement->execute(['id' => $id]);
    } catch (PDOException $error) {
        // Nowa rezerwacja między SELECT a DELETE: RESTRICT zachowuje też wszystkie przypisania.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1451) {
            return $dependent;
        }
        throw $error;
    }
    return $statement->rowCount() === 1 ? ['success' => true, 'errors' => []] : $missing;
}
