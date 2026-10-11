<?php
declare(strict_types=1);

/** Zwraca tylko pola formularza; role/active i inne dodatkowe dane są ignorowane. */
function validate_registration(array $input): array
{
    $values = array_fill_keys(['name', 'surname', 'email', 'phone'], '');
    $errors = [];
    $data = [];
    // Najpierw tani limit bajtów, zanim uruchomimy operacje na Unicode.
    $limits = ['name' => 200, 'surname' => 320, 'email' => 254, 'phone' => 32,
        'password' => 72, 'password_confirmation' => 72];

    foreach ($limits as $field => $byteLimit) {
        $raw = $input[$field] ?? '';
        if (!is_string($raw)) {
            $errors[$field] = 'Wpisz pojedynczą wartość tekstową.';
            continue;
        }
        if (strlen($raw) > $byteLimit) {
            $errors[$field] = $field === 'password' || $field === 'password_confirmation'
                ? 'Hasło może mieć maksymalnie 72 bajty (polskie znaki zajmują więcej niż jeden).'
                : 'Wpisana wartość jest zbyt długa.';
            continue;
        }
        if (preg_match('//u', $raw) !== 1) {
            $errors[$field] = 'Wpisz poprawny tekst.';
            continue;
        }
        if (preg_match('/\A[\s\p{Z}]*\z/u', $raw) === 1) {
            $errors[$field] = 'Uzupełnij to pole.';
            continue;
        }
        if (preg_match('/\p{C}/u', $raw) === 1) {
            $errors[$field] = 'Usuń niewidoczne lub sterujące znaki.';
            continue;
        }

        // Hasła porównujemy i hashujemy dokładnie, bez trimowania.
        $data[$field] = str_starts_with($field, 'password')
            ? $raw : preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $raw);
        if (array_key_exists($field, $values)) {
            $values[$field] = $data[$field];
        }
    }

    foreach (['name' => 50, 'surname' => 80] as $field => $limit) {
        if (isset($data[$field])) {
            if (preg_match_all('/./us', $data[$field]) > $limit) {
                $errors[$field] = "Wpisz maksymalnie {$limit} znaków.";
            } elseif (!preg_match("/\A\p{L}[\p{L}\p{M}]*(?:[ '\x{2019}-]\p{L}[\p{L}\p{M}]*)*\z/u", $data[$field])) {
                $errors[$field] = 'Użyj liter, spacji, apostrofu lub myślnika.';
            }
        }
    }

    if (isset($data['email'])) {
        $values['email'] = strtolower($data['email']);
        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Podaj poprawny adres e-mail, np. anna@example.com.';
        }
    }

    if (isset($data['phone'])) {
        $phone = str_replace([' ', '-'], '', $data['phone']);
        if (!preg_match('/\A(?:\+48)?[1-9][0-9]{8}\z/', $phone)) {
            $errors['phone'] = 'Podaj 9 cyfr polskiego numeru, opcjonalnie z prefiksem +48.';
        } else {
            $values['phone'] = str_starts_with($phone, '+48') ? $phone : '+48' . $phone;
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
