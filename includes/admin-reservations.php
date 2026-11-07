<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-forms.php';

// Wartości zgodne z reservations.status w database/database.sql.
const RESERVATION_STATUSES = [
    'pending' => 'Oczekująca', 'confirmed' => 'Potwierdzona',
    'completed' => 'Zrealizowana', 'cancelled' => 'Anulowana',
];
const RESERVATION_FROM = ' FROM reservations r
    JOIN users client ON client.id = r.user_id
    JOIN employees employee ON employee.id = r.employee_id
    JOIN users staff ON staff.id = employee.user_id
    JOIN services service ON service.id = r.service_id';

function validate_reservation_filters(array $input, ?PDO $connection = null): array
{
    $result = ['values' => ['date' => '', 'status' => '', 'employee_id' => '', 'page' => 1], 'errors' => []];
    foreach (['date', 'status', 'employee_id'] as $field) {
        $value = array_key_exists($field, $input) ? $input[$field] : '';
        if (!is_string($value)) {
            $result['errors'][$field] = 'Wybierz pojedynczą, poprawną wartość filtra.';
            continue;
        }
        if ($value === '') { continue; }
        if ($field === 'date') {
            if (strlen($value) !== 10 || !preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value)
                || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                $result['errors'][$field] = 'Podaj istniejącą datę w formacie RRRR-MM-DD (rok 1000–9999).';
                continue;
            }
        } elseif ($field === 'status') {
            if (!isset(RESERVATION_STATUSES[$value])) {
                $result['errors'][$field] = 'Wybierz status z dostępnej listy.';
                continue;
            }
        } elseif (admin_record_id($value) === null) {
            $result['errors'][$field] = 'Wybierz poprawnego pracownika.';
            continue;
        }
        $result['values'][$field] = $value;
    }
    if (array_key_exists('page', $input)) {
        $page = admin_record_id($input['page']);
        if ($page === null) { $result['errors']['page'] = 'Nieprawidłowy numer strony.'; }
        else { $result['values']['page'] = $page; }
    }
    if ($result['errors'] === [] && $result['values']['employee_id'] !== '') {
        $connection ??= db();
        $statement = $connection->prepare('SELECT id FROM employees WHERE id = :id');
        $statement->execute(['id' => $result['values']['employee_id']]);
        if ($statement->fetchColumn() === false) {
            $result['errors']['employee_id'] = 'Wybrany pracownik nie istnieje. Wybierz go ponownie.';
        }
    }
    return $result;
}

function reservation_employee_options(?PDO $connection = null): array
{
    $connection ??= db();
    // Historia pozostaje dostępna także po dezaktywacji profilu lub konta.
    $statement = $connection->prepare('SELECT e.id, u.name, u.surname, e.active, u.active AS account_active FROM employees e JOIN users u ON u.id = e.user_id ORDER BY u.surname, u.name, e.id');
    $statement->execute();
    return $statement->fetchAll();
}

/** Wyłącznie zwalidowane filtry; fragmenty SQL są stałe, wartości wiązane osobno. */
function reservation_list(array $filters, ?PDO $connection = null): array
{
    $connection ??= db();
    $conditions = [];
    $parameters = [];
    foreach (['date' => 'r.reservation_date', 'status' => 'r.status', 'employee_id' => 'r.employee_id'] as $key => $column) {
        if ($filters[$key] !== '') {
            $conditions[] = $column . ' = :' . $key;
            $parameters[$key] = $filters[$key];
        }
    }
    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $count = $connection->prepare('SELECT COUNT(*) FROM reservations r' . $where);
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 20));
    $page = min(max(1, $filters['page']), $pages);
    $statement = $connection->prepare(
        'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status,
            client.name AS client_name, client.surname AS client_surname,
            staff.name AS employee_name, staff.surname AS employee_surname, service.name AS service_name'
        . RESERVATION_FROM . $where . ' ORDER BY r.reservation_date DESC, r.start_time DESC, r.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $key => $value) { $statement->bindValue($key, $value, PDO::PARAM_STR); }
    $statement->bindValue('limit', 20, PDO::PARAM_INT);
    $statement->bindValue('offset', ($page - 1) * 20, PDO::PARAM_INT);
    $statement->execute();
    return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function reservation_find(int $id, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status, r.comment,
            client.name AS client_name, client.surname AS client_surname, client.email AS client_email, client.phone AS client_phone,
            staff.name AS employee_name, staff.surname AS employee_surname, staff.email AS employee_email, staff.phone AS employee_phone,
            service.name AS service_name' . RESERVATION_FROM . ' WHERE r.id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function reservation_change_status(int $id, array $input, ?PDO $connection = null): array
{
    $result = ['success' => false, 'status' => 422, 'errors' => []];
    $status = $input['status'] ?? null;
    $expected = $input['expected_status'] ?? null;
    if (!is_string($status) || !isset(RESERVATION_STATUSES[$status])) {
        $result['errors']['status'] = 'Wybierz poprawny status rezerwacji.';
    }
    if (!is_string($expected) || !isset(RESERVATION_STATUSES[$expected])) {
        $result['errors']['_form'] = 'Otwórz szczegóły rezerwacji ponownie i spróbuj zapisać status.';
    }
    if (($input['confirm'] ?? null) !== '1') {
        $result['errors']['confirm'] = 'Potwierdź zmianę statusu.';
    }
    if ($result['errors'] !== []) { return $result; }
    $connection ??= db();
    // Jedna atomowa operacja, tylko status. Warunek chroni przed starym formularzem.
    $statement = $connection->prepare('UPDATE reservations SET status = :status WHERE id = :id AND status = :expected');
    $statement->execute(['status' => $status, 'id' => $id, 'expected' => $expected]);
    if ($statement->rowCount() === 0) {
        $read = $connection->prepare('SELECT status FROM reservations WHERE id = :id');
        $read->execute(['id' => $id]);
        $current = $read->fetchColumn();
        if ($current === false) {
            $result['status'] = 404;
            $result['errors']['_form'] = 'Nie znaleziono rezerwacji. Odśwież listę.';
            return $result;
        }
        if ($current !== $expected) {
            $result['status'] = 409;
            $result['errors']['_form'] = 'Status zmienił się od otwarcia formularza. Sprawdź aktualny status poniżej i potwierdź zmianę ponownie.';
            return $result;
        }
        // Zapis bez zmiany statusu jest poprawny, nie oznacza braku rekordu.
    }
    $result['success'] = true;
    return $result;
}

/** Lokalne adresy generowane z normalizowanych danych, bez parametru return_url. */
function reservation_url(array $filters, string $action = 'list', ?int $id = null, ?int $page = null): string
{
    $query = [];
    foreach (['date', 'status', 'employee_id'] as $key) { if ($filters[$key] !== '') { $query[$key] = $filters[$key]; } }
    $page ??= $filters['page'];
    if ($page > 1) { $query['page'] = $page; }
    if ($action !== 'list') { $query['action'] = $action; }
    if ($id !== null) { $query['id'] = $id; }
    return '/admin-reservations.php' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}
