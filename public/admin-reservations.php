<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
require_once dirname(__DIR__) . '/includes/admin-reservations.php';

$adminSection = 'reservations';
$pageTitle = 'Rezerwacje';
$pageDescription = 'Przeglądaj wizyty, łącz filtry i aktualizuj ich statusy.';
$request = admin_form_request(['list', 'details', 'status']);
$action = $request['action'];
$errors = $request['errors'];
if ($errors === [] && $action === 'details' && $request['method'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    $errors['_form'] = 'Otwórz szczegóły z listy rezerwacji. Status zmienisz za pomocą formularza.';
}
$unavailable = $errors !== [];
$filters = ['date' => '', 'status' => '', 'employee_id' => '', 'page' => 1];
$employees = [];
$listing = null;
$reservation = null;
$id = null;
$statusValue = '';
if (!$unavailable) {
    $validated = validate_reservation_filters($_GET);
    $filters = $validated['values'];
    if ($validated['errors'] !== []) {
        http_response_code(400);
        $errors = $validated['errors'];
        $unavailable = true;
    }
}
if (!$unavailable && in_array($action, ['details', 'status'], true)) {
    $id = admin_record_id($_GET['id'] ?? null);
    if ($id === null) {
        http_response_code(400);
        $errors['_form'] = 'Nieprawidłowy identyfikator rezerwacji.';
        $unavailable = true;
    } else {
        $reservation = reservation_find($id);
        if ($reservation === null) {
            http_response_code(404);
            $errors['_form'] = 'Nie znaleziono rezerwacji. Odśwież listę.';
            $unavailable = true;
        } else { $statusValue = $reservation['status']; }
    }
}
if (!$unavailable && $request['method'] === 'POST') {
    $result = reservation_change_status($id, $_POST);
    if ($result['success']) {
        flash('success', 'Zapisano status rezerwacji. Termin i pozostałe dane pozostały bez zmian.');
        redirect(reservation_url($filters, 'details', $id));
    }
    http_response_code($result['status']);
    $errors = $result['errors'];
    $reservation = reservation_find($id);
    $unavailable = $reservation === null;
    if ($unavailable) { http_response_code(404); }
    if (is_string($_POST['status'] ?? null) && isset(RESERVATION_STATUSES[$_POST['status']])) { $statusValue = $_POST['status']; }
}
if ($action === 'list') {
    $employees = reservation_employee_options();
    if (!$unavailable) {
        $listing = reservation_list($filters);
        $filters['page'] = $listing['page'];
    }
} elseif (!$unavailable) { $pageTitle = 'Szczegóły rezerwacji'; }
require dirname(__DIR__) . '/includes/account-view.php';
