<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile.php';
$user = require_roles(['client', 'employee', 'admin']);
$values = array_intersect_key($user, array_flip(['name', 'surname', 'email', 'phone']));
$values['phone'] ??= '';
$errors = [];
$editing = true;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        $errors['_form'] = 'Formularz zawiera zbyt dużo danych. Wpisz krótsze wartości.';
    } elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        $errors['_form'] = 'Formularz wygasł lub nie został poprawnie wysłany. Spróbuj ponownie.';
    } else {
        $result = update_own_profile($_POST);
        $values = $result['values'];
        $errors = $result['errors'];
        if ($result['success']) {
            flash('success', 'Zapisano dane profilu.');
            redirect('/profile.php');
        }
        http_response_code(422);
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Ta metoda żądania nie jest obsługiwana.');
}

require dirname(__DIR__) . '/includes/profile-view.php';
