<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
require_once dirname(__DIR__) . '/includes/booking-save.php';

$pageTitle = 'Potwierdzenie rezerwacji';
$pageDescription = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    flash('error', 'Rezerwację zatwierdź przyciskiem w podsumowaniu terminu.');
} elseif ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(413);
    flash('error', 'Przesłano zbyt dużo danych. Wybierz termin ponownie.');
} elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
    http_response_code(403);
    flash('error', 'Formularz wygasł. Otwórz podsumowanie terminu ponownie.');
} else {
    $result = booking_save((int) $user['id'], $_POST);
    if ($result['success']) {
        flash('success', $result['message']);
        redirect('/client.php');
    }
    http_response_code($result['status']);
    flash('error', $result['message']);
}
require dirname(__DIR__) . '/includes/account-view.php';
