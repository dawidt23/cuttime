<?php
declare(strict_types=1);

function csrf_token(): string
{
    start_secure_session();
    if (!isset($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_is_valid(mixed $token): bool
{
    start_secure_session();

    return is_string($token) && strlen($token) === 64
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}
