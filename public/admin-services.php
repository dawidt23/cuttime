<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-services.php';

$adminSection = 'services';
$pageTitle = 'Usługi';
$pageDescription = 'Zarządzaj ofertą salonu, cenami i czasem trwania. Zachowuj historię wizyt dzięki dezaktywacji usług.';
$request = admin_form_request(['list', 'create', 'edit', 'delete', 'activate', 'deactivate']);
$action = $request['action'];
$method = $request['method'];
$errors = $request['errors'];
$unavailable = $errors !== [];
$values = ['name' => '', 'description' => '', 'category_id' => '', 'price' => '', 'duration' => '', 'active' => '1'];
$id = null;
$service = null;
$listing = null;
$categories = [];

if (!$unavailable && in_array($action, ['edit', 'delete', 'activate', 'deactivate'], true)) {
    $id = admin_record_id($_GET['id'] ?? null);
    if ($id === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy identyfikator usługi.';
        $unavailable = true;
    } else {
        $service = service_find($id);
        if ($service === null) {
            http_response_code(404);
            $errors['_form'] = 'Nie znaleziono usługi. Mogła zostać usunięta.';
            $unavailable = true;
        } else {
            foreach (array_keys($values) as $field) { $values[$field] = (string) ($service[$field] ?? ''); }
        }
    }
}
if (!$unavailable && $method === 'POST') {
    if (in_array($action, ['delete', 'activate', 'deactivate'], true)) {
        if (($_POST['confirm'] ?? null) !== '1') {
            $result = ['success' => false, 'status' => 422, 'errors' => ['_form' => 'Zaznacz potwierdzenie operacji.']];
        } else {
            $result = $action === 'delete' ? service_delete($id) : service_set_active($id, $action === 'activate');
        }
    } else {
        $result = service_save($_POST, $id);
        $values = $result['values'];
    }
    if ($result['success']) {
        flash('success', match ($action) {
            'create' => 'Dodano usługę.', 'edit' => 'Zapisano zmiany usługi.', 'delete' => 'Usunięto usługę.',
            'activate' => 'Aktywowano usługę.', 'deactivate' => 'Dezaktywowano usługę. Rezerwacje i przypisania pracowników zachowano.',
        });
        redirect('/admin-services.php');
    }
    $errors = $result['errors'];
    http_response_code($result['status']);
    $unavailable = $result['status'] === 404;
    if ($action === 'delete' && !$unavailable) {
        $service = service_find($id);
        $unavailable = $service === null;
    }
}
if (!$unavailable && $action === 'list') {
    $page = array_key_exists('page', $_GET) ? admin_record_id($_GET['page']) : 1;
    if ($page === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy numer strony.';
        $unavailable = true;
    } else {
        $listing = service_list($page);
    }
}
if (!$unavailable && in_array($action, ['create', 'edit'], true)) {
    $categories = service_categories();
}
if (!$unavailable) {
    $pageTitle = match ($action) {
        'create' => 'Dodaj usługę', 'edit' => 'Edytuj usługę', 'delete' => 'Usuń usługę',
        'activate' => 'Aktywuj usługę', 'deactivate' => 'Dezaktywuj usługę', default => 'Usługi',
    };
}
require dirname(__DIR__) . '/includes/account-view.php';
