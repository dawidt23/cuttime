<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-categories.php';

$adminSection = 'categories';
$pageTitle = 'Kategorie usług';
$pageDescription = 'Uporządkuj ofertę salonu. Usunięcie jest możliwe tylko dla kategorii bez usług.';
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$errors = [];
$values = ['name' => '', 'description' => '', 'active' => '1'];
$category = null;
$id = null;
$unavailable = false;
$listing = null;

if (!is_string($action) || !in_array($action, ['list', 'create', 'edit', 'delete'], true)) {
    http_response_code(400);
    $errors['_form'] = 'Nieprawidłowy adres operacji. Wróć do listy kategorii.';
    $unavailable = true;
} elseif (!in_array($method, $action === 'list' ? ['GET'] : ['GET', 'POST'], true)) {
    header('Allow: ' . ($action === 'list' ? 'GET' : 'GET, POST'));
    http_response_code(405);
    $errors['_form'] = 'Ta metoda żądania nie jest obsługiwana.';
    $unavailable = true;
}

if (!$unavailable && $method === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
        http_response_code(413);
        $errors['_form'] = 'Formularz zawiera zbyt dużo danych. Wpisz krótsze wartości.';
        $unavailable = true;
    } elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        $errors['_form'] = 'Formularz wygasł lub nie został poprawnie wysłany. Otwórz go ponownie.';
        $unavailable = true;
    }
}

if (!$unavailable && in_array($action, ['edit', 'delete'], true)) {
    $id = category_id($_GET['id'] ?? null);
    if ($id === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy identyfikator kategorii.';
        $unavailable = true;
    } else {
        $category = category_find($id);
        if ($category === null) {
            http_response_code(404);
            $errors['_form'] = 'Nie znaleziono kategorii. Mogła zostać usunięta.';
            $unavailable = true;
        } else {
            $values = ['name' => $category['name'], 'description' => $category['description'] ?? '', 'active' => (string) $category['active']];
        }
    }
}

if (!$unavailable && $method === 'POST') {
    if ($action === 'delete') {
        if (($_POST['confirm'] ?? null) !== '1') {
            $result = ['success' => false, 'status' => 422, 'errors' => ['_form' => 'Zaznacz potwierdzenie usunięcia kategorii.']];
        } else {
            $result = category_delete($id);
        }
    } else {
        // ID pochodzi tylko ze zweryfikowanego URL; dodatkowe pola POST nie zmieniają celu ani listy kolumn.
        $result = category_save($_POST, $id);
        $values = $result['values'];
    }
    if ($result['success']) {
        flash('success', match ($action) {
            'create' => 'Dodano kategorię.',
            'edit' => 'Zapisano zmiany kategorii.',
            'delete' => 'Usunięto kategorię.',
        });
        redirect('/admin-categories.php');
    }
    $errors = $result['errors'];
    http_response_code($result['status']);
    $unavailable = $result['status'] === 404;
    if ($action === 'delete' && !$unavailable) {
        $category = category_find($id); // Odśwież zależności po odmowie usunięcia.
        $unavailable = $category === null;
    }
}

if (!$unavailable && $action === 'list') {
    $page = array_key_exists('page', $_GET) ? category_id($_GET['page']) : 1;
    if ($page === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy numer strony.';
        $unavailable = true;
    } else {
        $listing = category_list($page);
    }
}

if (!$unavailable) {
    $pageTitle = match ($action) {
        'create' => 'Dodaj kategorię',
        'edit' => 'Edytuj kategorię',
        'delete' => 'Usuń kategorię',
        default => 'Kategorie usług',
    };
}
require dirname(__DIR__) . '/includes/account-view.php';
