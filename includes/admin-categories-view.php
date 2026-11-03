<?php
declare(strict_types=1);
// Dołączany wyłącznie ze wspólnego layoutu po kontroli administratora.
?>
<section class="category-module" aria-label="Zarządzanie kategoriami">
    <?php if ($errors !== []): ?>
        <div class="notice notice-error" role="alert" tabindex="-1" autofocus>
            <strong>Nie udało się wykonać operacji.</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
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
            <?php if ($listing['pages'] > 1): ?>
                <nav class="category-pagination" aria-label="Strony kategorii">
                    <?php if ($listing['page'] > 1): ?><a href="/admin-categories.php?page=<?= e((string) ($listing['page'] - 1)) ?>">← Poprzednia</a><?php endif; ?>
                    <span>Strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?></span>
                    <?php if ($listing['page'] < $listing['pages']): ?><a href="/admin-categories.php?page=<?= e((string) ($listing['page'] + 1)) ?>">Następna →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
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
        <form class="category-form" method="post" action="/admin-categories.php?action=<?= e($action) ?><?= $id !== null ? '&amp;id=' . e((string) $id) : '' ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label for="name">Nazwa kategorii (wymagana)</label>
                <input id="name" name="name" type="text" value="<?= e($values['name']) ?>" maxlength="100" required aria-describedby="name-hint<?= isset($errors['name']) ? ' name-error' : '' ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
                <small class="hint" id="name-hint">Od 1 do 100 znaków. Nazwa musi być unikalna.</small>
                <?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>
            <div class="field">
                <label for="description">Opis (opcjonalny)</label>
                <textarea id="description" name="description" rows="6" maxlength="2000" aria-describedby="description-hint<?= isset($errors['description']) ? ' description-error' : '' ?>"<?= isset($errors['description']) ? ' aria-invalid="true"' : '' ?>><?= e($values['description']) ?></textarea>
                <small class="hint" id="description-hint">Maksymalnie 2000 znaków. Zwykły tekst, bez formatowania HTML.</small>
                <?php if (isset($errors['description'])): ?><span class="field-error" id="description-error"><?= e($errors['description']) ?></span><?php endif; ?>
            </div>
            <div class="field">
                <label for="active">Status kategorii</label>
                <select id="active" name="active" required aria-describedby="active-hint<?= isset($errors['active']) ? ' active-error' : '' ?>"<?= isset($errors['active']) ? ' aria-invalid="true"' : '' ?>>
                    <option value="1"<?= $values['active'] === '1' ? ' selected' : '' ?>>Aktywna</option>
                    <option value="0"<?= $values['active'] === '0' ? ' selected' : '' ?>>Nieaktywna</option>
                </select>
                <small class="hint" id="active-hint">Zmiana statusu kategorii nie zmienia statusów przypisanych usług.</small>
                <?php if (isset($errors['active'])): ?><span class="field-error" id="active-error"><?= e($errors['active']) ?></span><?php endif; ?>
            </div>
            <button class="button" type="submit"><?= $action === 'create' ? 'Dodaj kategorię' : 'Zapisz zmiany' ?> <span aria-hidden="true">→</span></button>
            <a class="button button-secondary" href="/admin-categories.php">Anuluj i wróć do listy</a>
        </form>
    <?php endif; ?>
</section>
