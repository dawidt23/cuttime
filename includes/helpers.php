<?php
declare(strict_types=1);

/** Do tekstu HTML i wartości atrybutów w cudzysłowach; nie do JS/CSS/URL. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Wyłącznie lokalne ścieżki od /; domyślne 303 jest odpowiednie po POST. */
function redirect(string $path, int $status = 303): void
{
    $decoded = rawurldecode($path);
    if ($path === '' || $path[0] !== '/' || str_starts_with($decoded, '//')
        || preg_match('/[\x00-\x20\x7F\\\\]/', $path . $decoded)
        || !in_array($status, [302, 303, 307, 308], true)) {
        throw new InvalidArgumentException('Niepoprawny adres przekierowania.');
    }

    header('Location: ' . $path, true, $status);
    exit;
}

function flash(string $type, string $message): void
{
    if (!in_array($type, ['success', 'error', 'info'], true)) {
        throw new InvalidArgumentException('Niepoprawny typ komunikatu.');
    }
    start_secure_session();
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Odczyt usuwa komunikaty. Przy wyświetlaniu message użyj e(). */
function consume_flash(): array
{
    start_secure_session();
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}
