<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-employee-relations.php';

const AVAILABILITY_DAYS = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'];

function validate_availability(array $input): array
{
    $result = ['values' => ['day_of_week' => '', 'start_time' => '', 'end_time' => ''], 'errors' => [], 'success' => false, 'status' => 422];
    $day = $input['day_of_week'] ?? null;
    if (!is_string($day) || !preg_match('/\A[1-7]\z/', $day)) {
        $result['errors']['day_of_week'] = 'Wybierz dzień tygodnia od poniedziałku do niedzieli.';
    } else { $result['values']['day_of_week'] = $day; }
    foreach (['start_time', 'end_time'] as $field) {
        $time = $input[$field] ?? null;
        if (!is_string($time) || strlen($time) > 8 || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $time)) {
            $result['errors'][$field] = 'Podaj poprawną godzinę od 00:00:00 do 23:59:59 (HH:MM lub HH:MM:SS).';
        } else { $result['values'][$field] = strlen($time) === 5 ? $time . ':00' : $time; }
    }
    if (!isset($result['errors']['start_time'], $result['errors']['end_time'])
        && $result['values']['start_time'] !== '' && $result['values']['end_time'] !== ''
        && $result['values']['start_time'] >= $result['values']['end_time']) {
        $result['errors']['end_time'] = 'Koniec musi być późniejszy niż początek w tym samym dniu.';
    }
    return $result;
}

function availability_list(int $employeeId, ?PDO $connection = null): array
{
    $connection ??= db();
    $statement = $connection->prepare('SELECT id, employee_id, day_of_week, start_time, end_time FROM employee_availability WHERE employee_id = :employee_id ORDER BY day_of_week, start_time, id');
    $statement->execute(['employee_id' => $employeeId]);
    return $statement->fetchAll();
}

function availability_find(int $employeeId, int $id, ?PDO $connection = null, bool $lock = false): ?array
{
    $connection ??= db();
    $statement = $connection->prepare('SELECT id, employee_id, day_of_week, start_time, end_time FROM employee_availability WHERE employee_id = :employee_id AND id = :id' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['employee_id' => $employeeId, 'id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function availability_missing(): array
{
    return ['success' => false, 'status' => 404, 'errors' => ['_form' => 'Nie znaleziono tego wpisu dostępności u wybranego pracownika.']];
}

function availability_save(int $employeeId, array $input, ?int $id = null, ?PDO $connection = null): array
{
    $result = validate_availability($input);
    if ($result['errors'] !== []) { return $result; }
    $values = $result['values'];
    $saved = employee_relation_change($employeeId, static function (PDO $connection) use ($employeeId, $id, $values): array {
        if ($id !== null && availability_find($employeeId, $id, $connection, true) === null) { return availability_missing(); }
        // Odczyt blokujący widzi zapis poprzedniej transakcji po oczekiwaniu na pracownika.
        $conflict = $connection->prepare(
            'SELECT id FROM employee_availability WHERE employee_id = :employee_id AND day_of_week = :day
             AND start_time < :end_time AND end_time > :start_time AND id <> :id LIMIT 1 FOR UPDATE'
        );
        $conflict->execute(['employee_id' => $employeeId, 'day' => $values['day_of_week'], 'start_time' => $values['start_time'], 'end_time' => $values['end_time'], 'id' => $id ?? 0]);
        if ($conflict->fetchColumn() !== false) {
            return ['success' => false, 'status' => 409, 'errors' => ['start_time' => 'Ten przedział nakłada się na istniejącą dostępność pracownika w wybranym dniu. Wybierz inne godziny.']];
        }
        if ($id === null) {
            $statement = $connection->prepare('INSERT INTO employee_availability (employee_id, day_of_week, start_time, end_time) VALUES (:employee_id, :day_of_week, :start_time, :end_time)');
        } else {
            $statement = $connection->prepare('UPDATE employee_availability SET day_of_week = :day_of_week, start_time = :start_time, end_time = :end_time WHERE employee_id = :employee_id AND id = :id');
        }
        $statement->execute($values + ['employee_id' => $employeeId] + ($id === null ? [] : ['id' => $id]));
        return ['success' => true, 'errors' => []];
    }, $connection);
    return array_replace($result, $saved);
}

function availability_delete(int $employeeId, int $id, ?PDO $connection = null): array
{
    return employee_relation_change($employeeId, static function (PDO $connection) use ($employeeId, $id): array {
        if (availability_find($employeeId, $id, $connection, true) === null) { return availability_missing(); }
        $statement = $connection->prepare('DELETE FROM employee_availability WHERE employee_id = :employee_id AND id = :id');
        $statement->execute(['employee_id' => $employeeId, 'id' => $id]);
        return ['success' => true, 'errors' => []];
    }, $connection);
}
