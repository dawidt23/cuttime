<?php
declare(strict_types=1);

/** Sesja przechowuje tylko ID ustawione po przyszłej weryfikacji hasła. */
function current_user_id(): ?int
{
    start_secure_session();
    $id = $_SESSION['user_id'] ?? null;

    return is_int($id) && $id > 0 ? $id : null;
}

/** Odczyt aktywnego konta; nie jest implementacją logowania ani ochrony strony. */
function current_user(): ?array
{
    $id = current_user_id();
    if ($id === null) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, name, surname, email, phone, role FROM users WHERE id = :id AND active = 1'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    if ($user === false || !in_array($user['role'], ['client', 'employee', 'admin'], true)) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Przygotowanie do późniejszych kontroli ról; rola pochodzi z bazy, nie z formularza. */
function current_user_role(): ?string
{
    $user = current_user();

    return $user['role'] ?? null;
}
