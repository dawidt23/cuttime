<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('employee');
require_once dirname(__DIR__) . '/includes/employee-reservations.php';
$employeeSection = 'reservations';
$pageTitle = 'Mój terminarz';
$pageDescription = 'Przeglądaj przydzielone Ci wizyty i aktualizuj ich status.';
$action = $_GET['action'] ?? 'list';
$group = $_GET['group'] ?? 'today';
$page = array_key_exists('page', $_GET) ? record_id($_GET['page']) : 1;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$listing = null;
$reservation = null;
$error = null;
$status = 200;
if (!is_string($action) || !in_array($action, ['list', 'details', 'status'], true)
    || !is_string($group) || !isset(EMPLOYEE_RESERVATION_GROUPS[$group]) || $page === null) {
    $status = 400;
    $error = 'Nieprawidłowy adres. Otwórz swój terminarz ponownie.';
} elseif (($action === 'status' && $method !== 'POST') || ($action !== 'status' && $method !== 'GET')) {
    header('Allow: ' . ($action === 'status' ? 'POST' : 'GET'));
    $status = 405;
    $error = 'Status zmienisz przez formularz w szczegółach wizyty.';
} elseif ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    $status = 413;
    $error = 'Przesłano zbyt dużo danych. Otwórz formularz ponownie.';
} elseif ($method === 'POST' && !csrf_is_valid($_POST['_csrf'] ?? null)) {
    $status = 403;
    $error = 'Formularz wygasł. Otwórz szczegóły wizyty ponownie.';
} elseif (employee_reservation_profile((int) $user['id']) === null) {
    $status = 403;
    $error = 'Nie masz aktywnego profilu pracownika. Skontaktuj się z administratorem.';
} elseif ($action === 'list') {
    $listing = employee_reservations_list((int) $user['id'], $group, $page);
} else {
    $id = record_id($action === 'status' ? ($_POST['reservation_id'] ?? null) : ($_GET['reservation_id'] ?? null));
    if ($id === null) {
        $status = 400;
        $error = 'Nieprawidłowy numer wizyty.';
    } elseif ($action === 'status') {
        $result = employee_reservation_change_status((int) $user['id'], $id, $_POST);
        if ($result['success']) {
            flash('success', $result['message']);
            redirect('/employee.php?action=details&reservation_id=' . $id);
        }
        $status = $result['status'];
        $error = $result['message'];
    } else {
        $reservation = employee_reservation_find((int) $user['id'], $id);
        if ($reservation === null) {
            $status = 404;
            $error = 'Nie znaleziono wizyty w Twoim terminarzu.';
        } else { $pageTitle = 'Szczegóły obsługiwanej wizyty'; }
    }
}
http_response_code($status);
require dirname(__DIR__) . '/includes/account-view.php';
