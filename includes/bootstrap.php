<?php
declare(strict_types=1);

// Każda przyszła strona PHP ładuje ten plik przez require_once przed HTML.
// Ustawienia w php.ini są również konieczne dla błędów sprzed uruchomienia PHP.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/errors.php';
initialize_error_handling();

if (PHP_SAPI !== 'cli') {
    ob_start();
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';

start_secure_session();
