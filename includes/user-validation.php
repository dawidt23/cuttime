<?php
declare(strict_types=1);

/** Wspólne reguły podstawowych danych dla rejestracji i edycji własnego profilu. */
function validate_user_details(array $input): array
{
    $values = array_fill_keys(['name', 'surname', 'email', 'phone'], '');
    $errors = [];
    $data = [];
    // Najpierw tani limit bajtów, zanim uruchomimy operacje na Unicode.
    $limits = ['name' => 200, 'surname' => 320, 'email' => 254, 'phone' => 32];

    foreach ($limits as $field => $byteLimit) {
        $raw = $input[$field] ?? '';
        if (!is_string($raw)) {
            $errors[$field] = 'Wpisz pojedynczą wartość tekstową.';
            continue;
        }
        if (strlen($raw) > $byteLimit) {
            $errors[$field] = 'Wpisana wartość jest zbyt długa.';
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

        $data[$field] = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $raw);
        $values[$field] = $data[$field];
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

    return ['values' => $values, 'errors' => $errors];
}
