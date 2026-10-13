<?php
declare(strict_types=1);
require_once __DIR__ . '/user-validation.php';

/** Dane podstawowe mają te same reguły co profil; hasła dotyczą tylko rejestracji. */
function validate_registration(array $input): array
{
    $details = validate_user_details($input);
    $values = $details['values'];
    $errors = $details['errors'];
    $data = [];

    foreach (['password', 'password_confirmation'] as $field) {
        $raw = $input[$field] ?? '';
        if (!is_string($raw)) {
            $errors[$field] = 'Wpisz pojedynczą wartość tekstową.';
        } elseif (strlen($raw) > 72) {
            $errors[$field] = 'Hasło może mieć maksymalnie 72 bajty (polskie znaki zajmują więcej niż jeden).';
        } elseif (preg_match('//u', $raw) !== 1) {
            $errors[$field] = 'Wpisz poprawny tekst.';
        } elseif (preg_match('/\\A[\\s\\p{Z}]*\\z/u', $raw) === 1) {
            $errors[$field] = 'Uzupełnij to pole.';
        } elseif (preg_match('/\\p{C}/u', $raw) === 1) {
            $errors[$field] = 'Usuń niewidoczne lub sterujące znaki.';
        } else {
            $data[$field] = $raw; // Bez trimowania hasła.
        }
    }

    if (isset($data['password']) && preg_match_all('/./us', $data['password']) < 12) {
        $errors['password'] = 'Hasło musi mieć co najmniej 12 znaków.';
    }
    if (isset($data['password'], $data['password_confirmation'])
        && $data['password'] !== $data['password_confirmation']) {
        $errors['password_confirmation'] = 'Podane hasła nie są takie same.';
    }

    return ['values' => $values, 'errors' => $errors, 'password' => $data['password'] ?? ''];
}

/** Opcjonalne PDO służy testom; aplikacja używa istniejącej funkcji db(). */
function register_client(array $input, ?PDO $connection = null): array
{
    $validated = validate_registration($input);
    $result = ['values' => $validated['values'], 'errors' => $validated['errors'], 'success' => false];
    if ($result['errors'] !== []) {
        return $result;
    }

    $connection ??= db();
    $duplicateMessage = 'Konto z tym adresem e-mail już istnieje. Użyj innego adresu.';
    $statement = $connection->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $result['values']['email']]);
    if ($statement->fetchColumn() !== false) {
        $result['errors']['email'] = $duplicateMessage;
        return $result;
    }

    $hash = password_hash($validated['password'], PASSWORD_BCRYPT);
    try {
        // Rola i aktywność są decyzją serwera, niezależną od zawartości POST.
        $statement = $connection->prepare(
            "INSERT INTO users (name, surname, email, phone, password, role, active)
             VALUES (:name, :surname, :email, :phone, :password, 'client', 1)"
        );
        $statement->execute($result['values'] + ['password' => $hash]);
    } catch (PDOException $error) {
        // UNIQUE chroni również wyścig dwóch żądań po sprawdzeniu SELECT.
        if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            $result['errors']['email'] = $duplicateMessage;
            return $result;
        }
        throw $error; // Pozostałe błędy obsługuje istniejący bezpieczny handler.
    }

    $result['success'] = true;
    return $result;
}
