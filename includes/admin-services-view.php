<?php
declare(strict_types=1);
?>
<section class="category-module" aria-label="Zarządzanie usługami">
    <?php if ($errors !== []): ?>
        <div class="notice notice-error" role="alert" tabindex="-1" autofocus>
            <strong>Nie udało się wykonać operacji.</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($unavailable): ?>
        <a class="button button-secondary category-link" href="/admin-services.php">Wróć do listy usług</a>
    <?php elseif ($action === 'list'): ?>
        <div class="category-toolbar">
            <p>Liczba usług: <strong><?= e((string) $listing['total']) ?></strong></p>
            <a class="button category-link" href="/admin-services.php?action=create">Dodaj usługę <span aria-hidden="true">+</span></a>
        </div>
        <?php if ($listing['rows'] === []): ?>
            <p class="notice notice-info">Nie ma jeszcze usług. Dodaj pierwszą usługę do oferty salonu.</p>
        <?php else: ?>
            <p class="hint">Na wąskim ekranie przewiń tabelę w poziomie, aby zobaczyć wszystkie kolumny i akcje.</p>
            <div class="category-table-wrap" role="region" aria-label="Tabela usług — przewiń w poziomie na małym ekranie" tabindex="0">
                <table class="category-table service-table">
                    <caption>Usługi — strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?>. Pełny opis jest dostępny w edycji.</caption>
                    <thead><tr><th scope="col">Nazwa</th><th scope="col">Kategoria</th><th scope="col">Cena</th><th scope="col">Czas</th><th scope="col">Status</th><th scope="col">Akcje</th></tr></thead>
                    <tbody>
                    <?php foreach ($listing['rows'] as $row): ?>
                        <tr>
                            <th scope="row"><?= e($row['name']) ?><small>ID: <?= e((string) $row['id']) ?></small></th>
                            <td><?= e($row['category_name']) ?><?php if ((int) $row['category_active'] !== 1): ?><small class="hint">Kategoria nieaktywna</small><?php endif; ?></td>
                            <td><?= e(str_replace('.', ',', $row['price'])) ?> zł</td>
                            <td><?= e((string) $row['duration']) ?> min</td>
                            <td><span class="category-status<?= (int) $row['active'] === 1 ? '' : ' category-inactive' ?>"><?= (int) $row['active'] === 1 ? 'Aktywna' : 'Nieaktywna' ?></span></td>
                            <td><div class="category-actions">
                                <a href="/admin-services.php?action=edit&amp;id=<?= e((string) $row['id']) ?>" aria-label="Edytuj usługę <?= e($row['name']) ?>">Edytuj</a>
                                <a href="/admin-services.php?action=<?= (int) $row['active'] === 1 ? 'deactivate' : 'activate' ?>&amp;id=<?= e((string) $row['id']) ?>"><?= (int) $row['active'] === 1 ? 'Dezaktywuj' : 'Aktywuj' ?></a>
                                <?php if ((int) $row['reservations_count'] === 0): ?>
                                    <a class="category-delete-link" href="/admin-services.php?action=delete&amp;id=<?= e((string) $row['id']) ?>" aria-label="Usuń usługę <?= e($row['name']) ?>">Usuń</a>
                                <?php else: ?>
                                    <small>Usunięcie zablokowane: rezerwacje (<?= e((string) $row['reservations_count']) ?>). Dezaktywacja zachowuje historię.</small>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($listing['pages'] > 1): ?>
                <nav class="category-pagination" aria-label="Strony usług">
                    <?php if ($listing['page'] > 1): ?><a href="/admin-services.php?page=<?= e((string) ($listing['page'] - 1)) ?>">← Poprzednia</a><?php endif; ?>
                    <span>Strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?></span>
                    <?php if ($listing['page'] < $listing['pages']): ?><a href="/admin-services.php?page=<?= e((string) ($listing['page'] + 1)) ?>">Następna →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif (in_array($action, ['delete', 'activate', 'deactivate'], true)): ?>
        <?php $operationLabel = match ($action) { 'delete' => 'Usuń', 'activate' => 'Aktywuj', 'deactivate' => 'Dezaktywuj' }; ?>
        <div class="category-confirm">
            <h2><?= e($operationLabel) ?> usługę „<?= e($service['name']) ?>”</h2>
            <?php if ($action === 'delete' && (int) $service['reservations_count'] > 0): ?>
                <p class="notice notice-info">Nie można usunąć usługi z rezerwacjami, także anulowanymi lub zakończonymi. Liczba rezerwacji: <?= e((string) $service['reservations_count']) ?>.</p>
                <?php if ((int) $service['active'] === 1): ?>
                    <a class="button" href="/admin-services.php?action=deactivate&amp;id=<?= e((string) $id) ?>">Przejdź do dezaktywacji</a>
                <?php else: ?>
                    <p>Usługa jest już nieaktywna. Historia rezerwacji pozostaje zachowana.</p>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($action === 'delete'): ?>
                    <p>Usunięcie jest trwałe. Znikną również przypisania tej usługi do pracowników: <strong><?= e((string) $service['employees_count']) ?></strong>. Konta i profile pracowników pozostaną bez zmian.</p>
                <?php else: ?>
                    <p>Zmieni się wyłącznie aktywność usługi. Rezerwacje, ich terminy i przypisania pracowników pozostaną zachowane.</p>
                <?php endif; ?>
                <form method="post" action="/admin-services.php?action=<?= e($action) ?>&amp;id=<?= e((string) $id) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam: <?= e($operationLabel) ?> usługę.</label>
                    <button class="button<?= $action === 'delete' ? ' button-danger' : '' ?>" type="submit"><?= e($operationLabel) ?> usługę</button>
                </form>
            <?php endif; ?>
            <a class="button button-secondary" href="/admin-services.php">Anuluj i wróć do listy</a>
        </div>
    <?php elseif ($categories === []): ?>
        <p class="notice notice-info">Najpierw dodaj kategorię. Każda usługa musi być przypisana do istniejącej kategorii.</p>
        <a class="button category-link" href="/admin-categories.php?action=create">Dodaj kategorię</a>
    <?php else: ?>
        <?php require __DIR__ . '/admin-offer-form.php'; ?>
    <?php endif; ?>
</section>
