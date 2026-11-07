<?php
declare(strict_types=1);
// Dołączany wyłącznie ze wspólnego layoutu po kontroli administratora.
?>
<section class="category-module" aria-label="Zarządzanie kategoriami">
    <?php require __DIR__ . '/admin-errors-view.php'; ?>
    <?php if ($unavailable): ?>
        <a class="button button-secondary category-link" href="/admin-categories.php">Wróć do listy kategorii</a>
    <?php elseif ($action === 'list'): ?>
        <div class="category-toolbar">
            <p>Liczba kategorii: <strong><?= e((string) $listing['total']) ?></strong></p>
            <a class="button category-link" href="/admin-categories.php?action=create">Dodaj kategorię <span aria-hidden="true">+</span></a>
        </div>
        <?php if ($listing['rows'] === []): ?>
            <p class="notice notice-info">Nie ma jeszcze kategorii. Dodaj pierwszą, aby uporządkować ofertę salonu.</p>
        <?php else: ?>
            <p class="hint">Na wąskim ekranie przewiń tabelę w poziomie, aby zobaczyć wszystkie kolumny i akcje.</p>
            <div class="category-table-wrap" role="region" aria-label="Tabela kategorii — przewiń w poziomie na małym ekranie" tabindex="0">
                <table class="category-table">
                    <caption>Kategorie usług — strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?>. Opisy skrócono do 160 znaków; pełny opis jest dostępny w edycji.</caption>
                    <thead><tr><th scope="col">Nazwa</th><th scope="col">Opis</th><th scope="col">Status</th><th scope="col">Usługi</th><th scope="col">Akcje</th></tr></thead>
                    <tbody>
                    <?php foreach ($listing['rows'] as $row): ?>
                        <tr>
                            <th scope="row"><?= e($row['name']) ?><small>ID: <?= e((string) $row['id']) ?></small></th>
                            <td class="category-description"><?= e($row['description'] === null || $row['description'] === '' ? 'Brak opisu' : $row['description']) ?></td>
                            <td><span class="category-status<?= (int) $row['active'] === 1 ? '' : ' category-inactive' ?>"><?= (int) $row['active'] === 1 ? 'Aktywna' : 'Nieaktywna' ?></span></td>
                            <td><?= e((string) $row['services_count']) ?></td>
                            <td><div class="category-actions">
                                <a href="/admin-categories.php?action=edit&amp;id=<?= e((string) $row['id']) ?>" aria-label="Edytuj kategorię <?= e($row['name']) ?>">Edytuj</a>
                                <?php if ((int) $row['services_count'] === 0): ?>
                                    <a class="category-delete-link" href="/admin-categories.php?action=delete&amp;id=<?= e((string) $row['id']) ?>" aria-label="Usuń kategorię <?= e($row['name']) ?>">Usuń</a>
                                <?php else: ?>
                                    <small>Usunięcie zablokowane: przypisane usługi.</small>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_admin_pagination($listing, '/admin-categories.php', 'Strony kategorii'); ?>
        <?php endif; ?>
    <?php elseif ($action === 'delete'): ?>
        <div class="category-confirm">
            <h2>Czy usunąć kategorię „<?= e($category['name']) ?>”?</h2>
            <p>Usunięcie jest trwałe. Możesz zrezygnować i wrócić do listy.</p>
            <?php if ((int) $category['services_count'] > 0): ?>
                <p class="notice notice-info">Nie można usunąć tej kategorii. Liczba przypisanych usług: <?= e((string) $category['services_count']) ?>. Blokada obejmuje również usługi nieaktywne.</p>
            <?php else: ?>
                <form method="post" action="/admin-categories.php?action=delete&amp;id=<?= e((string) $id) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam usunięcie tej kategorii.</label>
                    <button class="button button-danger" type="submit">Usuń kategorię</button>
                </form>
            <?php endif; ?>
            <a class="button button-secondary" href="/admin-categories.php">Anuluj i wróć do listy</a>
        </div>
    <?php else: ?>
        <?php require __DIR__ . '/admin-offer-form.php'; ?>
    <?php endif; ?>
</section>
