<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/registration.php';

function validate_employee(array $input, bool $creating): array
{
    // Korzystamy z tych samych reguł danych i haseł co rejestracja/profil.
    $result = $creating ? validate_registration($input) : validate_user_details($input);
    $description = validate_admin_text($input['description'] ?? '', 2000, true);
    $result['values']['description'] = $description['value'];
    if ($description['error'] !== null) {
        $result['errors']['description'] = $description['error'];
    }
    if ($creating) {
        $result['values']['active'] = '1';
        if (!isset($input['active']) || !in_array($input['active'], ['0', '1'], true)) {
            $result['errors']['active'] = 'Wybierz poprawny status pracownika.';
        } else {
            $result['values']['active'] = $input['active'];
        }
    }
    return $result;
}

function employee_list(int $page, ?PDO $connection = null): array
{
    $connection ??= db();
    $count = $connection->prepare('SELECT COUNT(*) FROM employees');
    $count->execute();
    $total = (int) $count->fetchColumn();
    $pagination = admin_pagination($total, $page);
    $statement = $connection->prepare(
        'SELECT e.id, LEFT(e.description, 160) AS description, e.active, u.name, u.surname, u.email, u.phone, u.role, u.active AS account_active
         FROM employees e JOIN users u ON u.id = e.user_id
         ORDER BY u.surname, u.name, e.id LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', $pagination['limit'], PDO::PARAM_INT);
    $statement->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $statement->execute();
    return ['rows' => $statement->fetchAll()] + $pagination;
}

/** ID oznacza employees.id. Blokadę stosujemy tylko wewnątrz transakcji zapisu. */
function employee_find(int $id, ?PDO $connection = null, bool $lock = false): ?array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT e.id, e.user_id, e.description, e.active, u.name, u.surname, u.email, u.phone, u.role, u.active AS account_active
         FROM employees e JOIN users u ON u.id = e.user_id WHERE e.id = :id' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $id]);
    $employee = $statement->fetch();
    return $employee === false ? null : $employee;
}

/** SQL wymusza FK i UNIQUE, ale rolę właściciela profilu sprawdza aplikacja. */
function employee_record_error(?array $employee): ?array
{
    if ($employee === null) {
        return ['status' => 404, 'errors' => ['_form' => 'Nie znaleziono pracownika. Odśwież listę.']];
    }
    if ($employee['role'] !== 'employee') {
        return ['status' => 409, 'errors' => ['_form' => 'Profil pracownika jest powiązany z kontem o innej roli. Operacja została zablokowana.']];
    }
    return null;
}

function employee_save(array $input, ?int $id = null, ?PDO $connection = null): array
{
    $validated = validate_employee($input, $id === null);
    // Hasło nigdy nie wraca do kontrolera, widoku ani sesji.
    $result = ['values' => $validated['values'], 'errors' => $validated['errors'], 'success' => false, 'status' => 422];
    if ($result['errors'] !== []) { return $result; }
    $connection ??= db();
    $hash = $id === null ? password_hash($validated['password'], PASSWORD_BCRYPT) : null;
    $duplicateMessage = 'Ten adres e-mail jest już zajęty. Wpisz inny adres.';
    $connection->beginTransaction();
    try {
        $employee = $id === null ? null : employee_find($id, $connection, true);
        if ($id !== null && ($failure = employee_record_error($employee)) !== null) {
            $connection->rollBack();
            return array_replace($result, $failure);
        }
        $duplicate = $connection->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $duplicate->execute(['email' => $result['values']['email'], 'id' => $employee['user_id'] ?? 0]);
        if ($duplicate->fetchColumn() !== false) {
            $connection->rollBack();
            $result['errors']['email'] = $duplicateMessage;
            return $result;
        }
        $data = array_intersect_key($result['values'], array_flip(['name', 'surname', 'email', 'phone']));
        $description = $result['values']['description'] === '' ? null : $result['values']['description'];
        if ($id === null) {
            // Tylko nowe konto, zawsze employee. Nie przejmujemy istniejącego adresu/konta.
            $statement = $connection->prepare(
                "INSERT INTO users (name, surname, email, phone, password, role, active)
                 VALUES (:name, :surname, :email, :phone, :password, 'employee', :active)"
            );
            $statement->execute($data + ['password' => $hash, 'active' => $result['values']['active']]);
            $userId = (int) $connection->lastInsertId();
            $statement = $connection->prepare('INSERT INTO employees (user_id, description, active) VALUES (:user_id, :description, :active)');
            $statement->execute(['user_id' => $userId, 'description' => $description, 'active' => $result['values']['active']]);
        } else {
            // ID konta tylko z zablokowanego rekordu. Edycja nie zmienia roli, hasła ani aktywności.
            $statement = $connection->prepare('UPDATE users SET name = :name, surname = :surname, email = :email, phone = :phone WHERE id = :id');
            $statement->execute($data + ['id' => $employee['user_id']]);
            $statement = $connection->prepare('UPDATE employees SET description = :description WHERE id = :id');
            $statement->execute(['description' => $description, 'id' => $id]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        // UNIQUE rozstrzyga także równoległe zapisy po sprawdzeniu SELECT.
        if ($error instanceof PDOException && $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            $result['errors']['email'] = $duplicateMessage;
            return $result;
        }
        throw $error;
    }
    $result['success'] = true;
    return $result;
}

function employee_set_active(int $id, bool $active, ?PDO $connection = null): array
{
    $connection ??= db();
    $connection->beginTransaction();
    try {
        $employee = employee_find($id, $connection, true);
        if (($failure = employee_record_error($employee)) !== null) {
            $connection->rollBack();
            return ['success' => false] + $failure;
        }
        // Jedna decyzja administracyjna: profil i możliwość logowania. Stan jawny, nie toggle.
        $statement = $connection->prepare('UPDATE users SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $employee['user_id']]);
        $statement = $connection->prepare('UPDATE employees SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        throw $error;
    }
    return ['success' => true, 'errors' => []];
}
