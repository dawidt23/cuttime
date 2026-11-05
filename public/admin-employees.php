<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-employees.php';

$adminSection = 'employees';
$pageTitle = 'Pracownicy';
$pageDescription = 'Zarządzaj danymi i aktywnością zespołu. Dezaktywacja zachowuje historię wizyt.';
$request = admin_form_request(['list', 'create', 'edit', 'activate', 'deactivate']);
$action = $request['action'];
$method = $request['method'];
$errors = $request['errors'];
$unavailable = $errors !== [];
$values = ['name' => '', 'surname' => '', 'email' => '', 'phone' => '', 'description' => '', 'active' => '1'];
$id = null;
$employee = null;
$listing = null;

if (!$unavailable && in_array($action, ['edit', 'activate', 'deactivate'], true)) {
    $id = admin_record_id($_GET['id'] ?? null);
    if ($id === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy identyfikator pracownika.';
        $unavailable = true;
    } else {
        $employee = employee_find($id);
        $failure = employee_record_error($employee);
        if ($failure !== null) {
            http_response_code($failure['status']);
            $errors = $failure['errors'];
            $unavailable = true;
        } else {
            foreach (array_keys($values) as $field) { $values[$field] = (string) ($employee[$field] ?? ''); }
        }
    }
}
if (!$unavailable && $method === 'POST') {
    if (in_array($action, ['activate', 'deactivate'], true)) {
        $result = ($_POST['confirm'] ?? null) !== '1'
            ? ['success' => false, 'status' => 422, 'errors' => ['_form' => 'Zaznacz potwierdzenie operacji.']]
            : employee_set_active($id, $action === 'activate');
    } else {
        $result = employee_save($_POST, $id);
        $values = $result['values'];
    }
    if ($result['success']) {
        flash('success', match ($action) {
            'create' => 'Dodano pracownika i jego konto.', 'edit' => 'Zapisano dane pracownika.',
            'activate' => 'Aktywowano profil i konto pracownika.',
            'deactivate' => 'Dezaktywowano profil i konto pracownika. Historia wizyt, usługi i dostępność zostały zachowane.',
        });
        redirect('/admin-employees.php');
    }
    $errors = $result['errors'];
    http_response_code($result['status']);
    $unavailable = in_array($result['status'], [404, 409], true);
}
if (!$unavailable && $action === 'list') {
    $page = array_key_exists('page', $_GET) ? admin_record_id($_GET['page']) : 1;
    if ($page === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy numer strony.';
        $unavailable = true;
    } else {
        $listing = employee_list($page);
    }
}
if (!$unavailable) {
    $pageTitle = match ($action) {
        'create' => 'Dodaj pracownika', 'edit' => 'Edytuj pracownika',
        'activate' => 'Aktywuj pracownika', 'deactivate' => 'Dezaktywuj pracownika', default => 'Pracownicy',
    };
}
require dirname(__DIR__) . '/includes/account-view.php';
