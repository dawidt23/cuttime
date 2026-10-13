<?php
declare(strict_types=1);
require_once __DIR__ . '/user-validation.php';

/** Aktualizuje tylko własne dane. ID pochodzi z sesji, nigdy z input. */
function update_own_profile(array $input, ?PDO $connection = null): array
{
    $validated = validate_user_details($input);
    $result = $validated + ['success' => false];
    $id = current_user_id();
    $unavailable = 'Nie udało się zapisać danych. Odśwież stronę i spróbuj ponownie.';
    if ($id === null) {
        $result['errors']['_form'] = $unavailable;
        return $result;
    }
    if ($result['errors'] !== []) {
        return $result;
    }

    $connection ??= db();
    $owner = $connection->prepare('SELECT id FROM users WHERE id = :id AND active = 1');
    $owner->execute(['id' => $id]);
    if ($owner->fetchColumn() === false) {
        $result['errors']['_form'] = $unavailable;
        return $result;
    }

    $duplicateMessage = 'Ten adres e-mail jest już zajęty. Wpisz inny adres.';
    $duplicate = $connection->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $duplicate->execute(['email' => $result['values']['email'], 'id' => $id]);
    if ($duplicate->fetchColumn() !== false) {
        $result['errors']['email'] = $duplicateMessage;
        return $result;
    }

    try {
        // Jawna lista czterech pól. Hasło, rola, active i ID nie podlegają edycji.
        $statement = $connection->prepare(
            'UPDATE users SET name = :name, surname = :surname, email = :email, phone = :phone
             WHERE id = :id AND active = 1'
        );
        $statement->execute($result['values'] + ['id' => $id]);
    } catch (PDOException $error) {
        // UNIQUE chroni też równoczesny zapis po sprawdzeniu dostępności e-maila.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            $result['errors']['email'] = $duplicateMessage;
            return $result;
        }
        throw $error; // Bezpieczna odpowiedź należy do istniejącego handlera.
    }

    if ($statement->rowCount() === 0) {
        // Brak zmian jest poprawnym zapisem; odróżniamy go od utraty aktywnego konta.
        $owner->execute(['id' => $id]);
        if ($owner->fetchColumn() === false) {
            $result['errors']['_form'] = $unavailable;
            return $result;
        }
    }
    $result['success'] = true;
    return $result;
}
