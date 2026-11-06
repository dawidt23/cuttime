<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-availability.php';

$adminSection = 'availability';
$modulePath = '/admin-availability.php';
$pageTitle = 'Dostępność pracowników';
$pageDescription = 'Ustal tygodniowe godziny pracy. Zmiana dostępności nie anuluje ani nie przesuwa istniejących wizyt.';
$context = admin_employee_context(['list', 'create', 'edit', 'delete']);
$employeeId = $context['employee_id'];
$employee = $context['employee'];
$listing = $context['listing'];
$errors = $context['errors'];
$unavailable = $errors !== [];
$action = $context['action'];
$values = ['day_of_week' => '1', 'start_time' => '', 'end_time' => ''];
$id = null;
$availability = null;
$rows = [];
if (!$unavailable && in_array($action, ['edit', 'delete'], true)) {
    $id = admin_record_id($_GET['id'] ?? null);
    if ($id === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy identyfikator dostępności.';
        $unavailable = true;
    } else {
        $availability = availability_find($employeeId, $id);
        if ($availability === null) {
            http_response_code(404);
            $errors = availability_missing()['errors'];
            $unavailable = true;
        } else {
            foreach (array_keys($values) as $field) { $values[$field] = (string) $availability[$field]; }
        }
    }
}
if (!$unavailable && $context['method'] === 'POST') {
    if ($action === 'delete') {
        $result = ($_POST['confirm'] ?? null) !== '1'
            ? ['success' => false, 'status' => 422, 'errors' => ['_form' => 'Zaznacz potwierdzenie usunięcia.']]
            : availability_delete($employeeId, $id);
    } else {
        $result = availability_save($employeeId, $_POST, $id);
        $values = $result['values'];
    }
    if ($result['success']) {
        flash('success', $action === 'delete' ? 'Usunięto wpis dostępności. Istniejące rezerwacje zachowano.' : 'Zapisano dostępność pracownika.');
        redirect($modulePath . '?employee_id=' . $employeeId);
    }
    $errors = $result['errors'];
    http_response_code($result['status']);
    $unavailable = $result['status'] === 404;
}
if (!$unavailable && $employee !== null && $action === 'list') { $rows = availability_list($employeeId); }
require dirname(__DIR__) . '/includes/account-view.php';
