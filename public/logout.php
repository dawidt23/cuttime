<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Aby się wylogować, użyj przycisku „Wyloguj się” na stronie konta.');
}

if (!csrf_is_valid($_POST['_csrf'] ?? null)) {
    http_response_code(403);
    exit('Formularz wygasł lub nie został poprawnie wysłany. Wróć na stronę konta i spróbuj ponownie.');
}

// Wylogowanie nie wymaga bazy: działa również podczas awarii połączenia PDO.
log_out();
redirect('/login.php?logged_out=1');
