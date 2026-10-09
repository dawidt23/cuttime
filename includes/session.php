<?php
declare(strict_types=1);

/** Wywołuj przed jakimkolwiek HTML; kolejne wywołania nie otwierają nowej sesji. */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        throw new RuntimeException('Nie można uruchomić sesji.');
    }

    // HTTPS musi być ustawione przez serwer, nie przez niezaufany nagłówek klienta.
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('CUTTIMESESSID');
    session_cache_limiter('nocache');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Nie można uruchomić sesji.');
    }
}
