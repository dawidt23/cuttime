<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-employee-services.php';

$adminSection = 'employee-services';
$modulePath = '/admin-employee-services.php';
$pageTitle = 'Usługi pracownika';
$pageDescription = 'Wybierz usługi wykonywane przez pracownika. Zmiana przypisań zachowuje istniejące rezerwacje.';
$context = admin_employee_context(['list', 'edit']);
$employeeId = $context['employee_id'];
$employee = $context['employee'];
$listing = $context['listing'];
$errors = $context['errors'];
$unavailable = $errors !== [];
$options = [];
$selected = [];
if (!$unavailable && $employee !== null) {
    if ($context['method'] === 'POST') {
        $result = employee_services_sync($employeeId, $_POST);
        if ($result['success']) {
            flash('success', 'Zapisano przypisania usług. Istniejące rezerwacje pozostały bez zmian.');
            redirect($modulePath . '?employee_id=' . $employeeId);
        }
        $errors = $result['errors'];
        $selected = $result['values'];
        http_response_code($result['status']);
        $unavailable = $result['status'] === 404;
    }
    if (!$unavailable) {
        $options = employee_service_options($employeeId);
        if ($context['method'] === 'GET') {
            foreach ($options as $option) { if ((int) $option['assigned'] === 1) { $selected[] = (int) $option['id']; } }
        }
    }
}
require dirname(__DIR__) . '/includes/account-view.php';
