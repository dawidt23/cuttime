<?php
declare(strict_types=1);
// Wspólna mapa modułów. Adresy dodawaj dopiero wraz z chronionymi kontrolerami.
$adminLinks = [
    'dashboard' => ['Dashboard', '/admin.php'],
    'categories' => ['Kategorie', '/admin-categories.php'],
    'services' => ['Usługi', '/admin-services.php'],
    'employees' => ['Pracownicy', '/admin-employees.php'],
    'availability' => ['Dostępność pracowników', '/admin-availability.php'],
    'reservations' => ['Rezerwacje', '/admin-reservations.php'],
];
?>
<nav class="admin-nav" aria-label="Panel administratora">
    <ul>
        <?php foreach ($adminLinks as $section => [$label, $path]): ?>
            <li>
                <?php if ($path !== null): ?>
                    <a href="<?= e($path) ?>"<?php if (($adminSection === 'employee-services' ? 'employees' : $adminSection) === $section): ?> aria-current="page"<?php endif; ?>><?= e($label) ?></a>
                <?php else: ?>
                    <span class="admin-nav-unavailable" aria-disabled="true"><?= e($label) ?><small>Wkrótce</small></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
