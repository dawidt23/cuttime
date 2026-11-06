<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-employee-relations.php';

function employee_service_options(int $employeeId, ?PDO $connection = null): array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT s.id, s.name, s.duration, s.price, s.active, c.name AS category_name, c.active AS category_active,
            es.service_id IS NOT NULL AS assigned
         FROM services s JOIN service_categories c ON c.id = s.category_id
         LEFT JOIN employee_services es ON es.service_id = s.id AND es.employee_id = :employee_id
         ORDER BY c.name, s.name, s.id'
    );
    $statement->execute(['employee_id' => $employeeId]);
    return $statement->fetchAll();
}

function validate_employee_services(array $input): array
{
    $result = ['values' => [], 'errors' => [], 'success' => false, 'status' => 422];
    // Brak zaznaczonych pól oznacza pusty zestaw, lecz tylko po potwierdzeniu pełnego formularza.
    $raw = array_key_exists('service_ids', $input) ? $input['service_ids'] : [];
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > 500) {
        $result['errors']['service_ids'] = 'Wybierz poprawną listę usług (maksymalnie 500).';
    } else {
        foreach ($raw as $value) {
            $id = admin_record_id($value);
            if ($id === null) {
                $result['errors']['service_ids'] = 'Lista zawiera nieprawidłowy identyfikator usługi.';
            } else {
                $result['values'][] = $id;
            }
        }
        $result['values'] = array_values(array_unique($result['values']));
        sort($result['values'], SORT_NUMERIC);
    }
    if (($input['confirm'] ?? null) !== '1' || ($input['_complete'] ?? null) !== '1') {
        $result['errors']['_form'] = 'Potwierdź wybrany zestaw usług i wyślij cały formularz. W razie problemu otwórz go ponownie.';
    }
    return $result;
}

function employee_services_sync(int $employeeId, array $input, ?PDO $connection = null): array
{
    $result = validate_employee_services($input);
    if ($result['errors'] !== []) { return $result; }
    $selected = $result['values'];
    $saved = employee_relation_change($employeeId, static function (PDO $connection) use ($employeeId, $selected): array {
        if ($selected !== []) {
            // Fragment SQL pochodzi tylko z liczby parametrów, ID są wiązane osobno.
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $statement = $connection->prepare('SELECT id FROM services WHERE id IN (' . $placeholders . ') ORDER BY id LOCK IN SHARE MODE');
            $statement->execute($selected);
            if (count($statement->fetchAll()) !== count($selected)) {
                return ['success' => false, 'status' => 422, 'errors' => ['service_ids' => 'Co najmniej jedna wybrana usługa już nie istnieje. Odśwież listę i wybierz usługi ponownie.']];
            }
        }
        $statement = $connection->prepare('SELECT service_id FROM employee_services WHERE employee_id = :employee_id FOR UPDATE');
        $statement->execute(['employee_id' => $employeeId]);
        $current = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $remove = $connection->prepare('DELETE FROM employee_services WHERE employee_id = :employee_id AND service_id = :service_id');
        foreach (array_diff($current, $selected) as $serviceId) {
            $remove->execute(['employee_id' => $employeeId, 'service_id' => $serviceId]);
        }
        $add = $connection->prepare('INSERT INTO employee_services (employee_id, service_id) VALUES (:employee_id, :service_id)');
        foreach (array_diff($selected, $current) as $serviceId) {
            $add->execute(['employee_id' => $employeeId, 'service_id' => $serviceId]);
        }
        return ['success' => true, 'errors' => []];
    }, $connection);
    return array_replace($result, $saved);
}
