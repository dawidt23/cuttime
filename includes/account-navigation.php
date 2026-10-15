<?php
declare(strict_types=1);
// Wspólna nawigacja chronionych widoków; $user pochodzi z kontroli dostępu PHP.
$accountLinks = [
    role_home($user['role']) => 'Moja część serwisu',
    '/account.php' => 'Moje konto',
    '/profile.php' => 'Mój profil',
];
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav class="account-nav" aria-label="Nawigacja konta">
    <?php foreach ($accountLinks as $path => $label): ?>
        <a href="<?= e($path) ?>"<?php if ($currentPage === basename($path)): ?> aria-current="page"<?php endif; ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<form class="logout-form" action="/logout.php" method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="button button-secondary" type="submit">Wyloguj się <span aria-hidden="true">→</span></button>
</form>
