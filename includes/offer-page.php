<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/offer.php';

// Sekcję ustala kontroler, nigdy parametr GET. Wszystkie trzy strony są publiczne.
$pageTitle = match ($offerSection) {
    'list' => 'Oferta usług',
    'detail' => 'Szczegóły usługi',
    'booking' => 'Wybrana usługa',
};
$pageDescription = $offerSection === 'list'
    ? 'Znajdź usługę dla siebie. Sprawdź ceny, czas trwania i wybierz swój kolejny krok.'
    : '';
$offerError = null;
$services = [];
$service = null;
$id = null;

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    $offerError = 'Ta metoda żądania nie jest obsługiwana. Wróć do oferty usług.';
} elseif ($offerSection !== 'list' && ($id = record_id($_GET[$offerSection === 'booking' ? 'service_id' : 'id'] ?? null)) === null) {
    http_response_code(400);
    $offerError = 'Nieprawidłowy adres usługi. Wybierz ją ponownie z oferty.';
} else {
    $services = offered_services($id);
    if ($offerSection !== 'list') {
        $service = $services[0] ?? null;
        if ($service === null) {
            http_response_code(404);
            $offerError = 'Ta usługa nie jest dostępna. Wybierz inną z aktualnej oferty.';
        } elseif ($offerSection === 'detail') {
            $pageTitle = $service['name'];
        }
    }
}

$user = current_user();
require __DIR__ . '/account-view.php';
