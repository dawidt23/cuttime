<?php declare(strict_types=1); ?>
<?php if ($errors !== []): ?>
    <div class="notice notice-error" role="alert" tabindex="-1" autofocus>
        <strong>Nie udało się wykonać operacji.</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($unavailable): ?>
    <a class="button button-secondary category-link" href="<?= e($modulePath) ?>">Wróć do wyboru pracownika</a>
<?php elseif ($employee === null): ?>
    <h2>Wybierz pracownika</h2>
    <?php if ($listing['rows'] === []): ?>
        <p class="notice notice-info">Nie ma jeszcze pracowników.</p>
        <a class="button category-link" href="/admin-employees.php?action=create">Dodaj pracownika</a>
    <?php else: ?>
        <ul class="employee-picker">
            <?php foreach ($listing['rows'] as $row): ?>
                <li>
                    <div><strong><?= e($row['name'] . ' ' . $row['surname']) ?></strong><small><?= e($row['email']) ?></small>
                        <?php if ((int) $row['active'] !== 1 || (int) $row['account_active'] !== 1): ?><small>Profil lub konto nieaktywne</small><?php endif; ?>
                    </div>
                    <?php if ($row['role'] === 'employee'): ?>
                        <a class="button button-secondary category-link" href="<?= e($modulePath) ?>?employee_id=<?= e((string) $row['id']) ?>">Wybierz<span class="visually-hidden"> pracownika <?= e($row['name'] . ' ' . $row['surname']) ?></span></a>
                    <?php else: ?><span>Operacje zablokowane: konto ma inną rolę.</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($listing['pages'] > 1): ?>
            <nav class="category-pagination" aria-label="Strony pracowników">
                <?php if ($listing['page'] > 1): ?><a href="<?= e($modulePath) ?>?page=<?= e((string) ($listing['page'] - 1)) ?>">← Poprzednia</a><?php endif; ?>
                <span>Strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?></span>
                <?php if ($listing['page'] < $listing['pages']): ?><a href="<?= e($modulePath) ?>?page=<?= e((string) ($listing['page'] + 1)) ?>">Następna →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <div class="employee-context">
        <h2><?= e($employee['name'] . ' ' . $employee['surname']) ?></h2>
        <p><?= e($employee['email']) ?></p>
        <?php if ((int) $employee['active'] !== 1 || (int) $employee['account_active'] !== 1): ?>
            <p class="notice notice-info">Profil lub konto jest nieaktywne. Możesz przygotować usługi i godziny pracy; zapis nie aktywuje pracownika.</p>
        <?php endif; ?>
        <nav class="category-actions" aria-label="Dane pracownika">
            <a href="/admin-employee-services.php?employee_id=<?= e((string) $employeeId) ?>"<?= $adminSection === 'employee-services' ? ' aria-current="page"' : '' ?>>Usługi pracownika</a>
            <a href="/admin-availability.php?employee_id=<?= e((string) $employeeId) ?>"<?= $adminSection === 'availability' ? ' aria-current="page"' : '' ?>>Dostępność</a>
            <a href="<?= e($modulePath) ?>">Zmień pracownika</a>
        </nav>
    </div>
<?php endif; ?>
