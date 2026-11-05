<?php
declare(strict_types=1);

// Wspólna walidacja formularzy administracyjnych.
function admin_record_id(mixed $value): ?int
{
    if (!is_string($value) || strlen($value) > 10 || !preg_match('/\A[1-9][0-9]*\z/', $value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    return $id === false ? null : $id;
}

function validate_offer_details(array $input, int $nameLimit, string $entityName): array
{
    $values = ['name' => '', 'description' => '', 'active' => '1'];
    $errors = [];
    foreach (['name' => $nameLimit, 'description' => 2000] as $field => $limit) {
        $validated = validate_admin_text($input[$field] ?? '', $limit, $field === 'description');
        if ($validated['error'] !== null) {
            $errors[$field] = $validated['error'];
            continue;
        }
        $text = $validated['value'];
        $values[$field] = $text;
        if ($field === 'name' && $text === '') {
            $errors[$field] = "Podaj nazwę {$entityName}.";
        }
    }
    if (!isset($input['active']) || !in_array($input['active'], ['0', '1'], true)) {
        $errors['active'] = "Wybierz poprawny status {$entityName}.";
    } else {
        $values['active'] = $input['active'];
    }
    return ['values' => $values, 'errors' => $errors];
}

/** Teksty oferty i opis pracownika: ta sama walidacja, bez zmiany reguł nazw. */
function validate_admin_text(mixed $raw, int $limit, bool $multiline = false): array
{
    $result = ['value' => '', 'error' => null];
    if (!is_string($raw)) {
        $result['error'] = 'Wpisz pojedynczą wartość tekstową.';
    } elseif (strlen($raw) > $limit * 4) {
        $result['error'] = "Wpisz maksymalnie {$limit} znaków.";
    } elseif (preg_match('//u', $raw) !== 1) {
        $result['error'] = 'Wpisz poprawny tekst.';
    } else {
        $checked = $multiline ? str_replace(["\r", "\n", "\t"], '', $raw) : $raw;
        if (preg_match('/\p{C}/u', $checked) === 1) {
            $result['error'] = 'Usuń niewidoczne lub sterujące znaki.';
        } else {
            $text = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $raw);
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            if (preg_match_all('/./us', $text) > $limit) {
                $result['error'] = "Wpisz maksymalnie {$limit} znaków.";
            } else {
                $result['value'] = $text;
            }
        }
    }
    return $result;
}


/** Po require_role('admin'); używa dotychczasowego CSRF, bez drugiego mechanizmu. */
function admin_form_request(array $allowedActions): array
{
    $action = $_GET['action'] ?? 'list';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $errors = [];
    if (!is_string($action) || !in_array($action, $allowedActions, true)) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy adres operacji. Wróć do listy.';
    } elseif (!in_array($method, $action === 'list' ? ['GET'] : ['GET', 'POST'], true)) {
        header('Allow: ' . ($action === 'list' ? 'GET' : 'GET, POST'));
        http_response_code(405);
        $errors['_form'] = 'Ta metoda żądania nie jest obsługiwana.';
    } elseif ($method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
            http_response_code(413);
            $errors['_form'] = 'Formularz zawiera zbyt dużo danych. Wpisz krótsze wartości.';
        } elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
            http_response_code(403);
            $errors['_form'] = 'Formularz wygasł lub nie został poprawnie wysłany. Otwórz go ponownie.';
        }
    }
    return ['action' => is_string($action) ? $action : '', 'method' => $method, 'errors' => $errors];
}
