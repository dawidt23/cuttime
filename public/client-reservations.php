<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
require_once dirname(__DIR__) . '/includes/client-reservations.php';

$clientSection = 'reservations';
$pageTitle = 'Moje rezerwacje';
$pageDescription = 'Sprawdź swoje wizyty lub anuluj przyszły termin.';
$action = $_GET['action'] ?? 'list';
$group = $_GET['group'] ?? 'upcoming';
$page = isset($_GET['page']) ? record_id($_GET['page']) : 1;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$listing = null;
$reservation = null;
$error = null;
$status = 200;
if (!is_string($action) || !in_array($action, ['list', 'details', 'cancel'], true)
    || !is_string($group) || !isset(CLIENT_RESERVATION_GROUPS[$group]) || $page === null) {
    $status = 400;
    $error = 'Nieprawidłowy adres. Wróć do listy swoich rezerwacji.';
} elseif (($action === 'cancel' && $method !== 'POST') || ($action !== 'cancel' && $method !== 'GET')) {
    $status = 405;
    header('Allow: ' . ($action === 'cancel' ? 'POST' : 'GET'));
    $error = 'Otwórz szczegóły wizyty i użyj formularza potwierdzenia anulowania.';
} elseif ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    $status = 413;
    $error = 'Przesłano zbyt dużo danych. Otwórz formularz ponownie.';
} elseif ($method === 'POST' && !csrf_is_valid($_POST['_csrf'] ?? null)) {
    $status = 403;
    $error = 'Formularz wygasł. Otwórz szczegóły wizyty ponownie.';
} elseif ($action !== 'list') {
    $id = record_id($action === 'cancel' ? ($_POST['reservation_id'] ?? null) : ($_GET['reservation_id'] ?? null));
    if ($id === null) {
        $status = 400;
        $error = 'Nieprawidłowy numer rezerwacji.';
    } elseif ($action === 'cancel') {
        if (($_POST['confirm'] ?? null) !== '1') {
            $status = 422;
            $error = 'Zaznacz potwierdzenie, aby anulować wizytę.';
        } else {
            $result = client_reservation_cancel((int) $user['id'], $id);
            if ($result['success']) {
                flash('success', $result['message']);
                redirect('/client-reservations.php?group=cancelled');
            }
            $status = $result['status'];
            $error = $result['message'];
        }
    } else {
        $reservation = client_reservation_find((int) $user['id'], $id);
        if ($reservation === null) {
            $status = 404;
            $error = 'Nie znaleziono rezerwacji na Twoim koncie.';
        } else { $pageTitle = 'Szczegóły Twojej wizyty'; }
    }
} else {
    $listing = client_reservations_list((int) $user['id'], $group, $page);
}
http_response_code($status);
require dirname(__DIR__) . '/includes/account-view.php';
