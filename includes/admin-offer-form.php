<?php
declare(strict_types=1);
// Wspólne pola oferty; kontrolery ustawiają wyłącznie stały adminSection oraz sprawdzone ID.
$isService = $adminSection === 'services';
$basePath = $isService ? '/admin-services.php' : '/admin-categories.php';
$nameLimit = $isService ? 150 : 100;
$itemLabel = $isService ? 'usługi' : 'kategorii';
?>
<form class="category-form" method="post" action="<?= e($basePath) ?>?action=<?= e($action) ?><?= $id !== null ? '&amp;id=' . e((string) $id) : '' ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($isService): ?>
        <div class="field">
            <label for="category_id">Kategoria (wymagana)</label>
            <select id="category_id" name="category_id" required aria-describedby="category_id-hint<?= isset($errors['category_id']) ? ' category_id-error' : '' ?>"<?= isset($errors['category_id']) ? ' aria-invalid="true"' : '' ?>>
                <option value="">Wybierz kategorię</option>
                <?php foreach ($categories as $option): ?>
                    <option value="<?= e((string) $option['id']) ?>"<?= $values['category_id'] === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['name']) ?><?= (int) $option['active'] === 1 ? '' : ' (nieaktywna)' ?></option>
                <?php endforeach; ?>
            </select>
            <small class="hint" id="category_id-hint">Możesz przypisać usługę również do kategorii nieaktywnej. Jej status pozostanie bez zmian.</small>
            <?php if (isset($errors['category_id'])): ?><span class="field-error" id="category_id-error"><?= e($errors['category_id']) ?></span><?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="field">
        <label for="name">Nazwa <?= e($itemLabel) ?> (wymagana)</label>
        <input id="name" name="name" type="text" value="<?= e($values['name']) ?>" maxlength="<?= e((string) $nameLimit) ?>" required aria-describedby="name-hint<?= isset($errors['name']) ? ' name-error' : '' ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
        <small class="hint" id="name-hint">Od 1 do <?= e((string) $nameLimit) ?> znaków.<?= $isService ? '' : ' Nazwa musi być unikalna.' ?></small>
        <?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= e($errors['name']) ?></span><?php endif; ?>
    </div>
    <div class="field">
        <label for="description">Opis (opcjonalny)</label>
        <textarea id="description" name="description" rows="6" maxlength="2000" aria-describedby="description-hint<?= isset($errors['description']) ? ' description-error' : '' ?>"<?= isset($errors['description']) ? ' aria-invalid="true"' : '' ?>><?= e($values['description']) ?></textarea>
        <small class="hint" id="description-hint">Maksymalnie 2000 znaków. Zwykły tekst, bez formatowania HTML.</small>
        <?php if (isset($errors['description'])): ?><span class="field-error" id="description-error"><?= e($errors['description']) ?></span><?php endif; ?>
    </div>
    <?php if ($isService): ?>
        <div class="form-grid">
            <?php foreach ([
                'price' => ['Cena (zł)', 'decimal', 11, 'Od 0 do 99999999,99 zł. Maksymalnie 2 cyfry po przecinku lub kropce.'],
                'duration' => ['Czas trwania (minuty)', 'numeric', 4, 'Pełna liczba minut od 1 do 1440.'],
            ] as $field => [$label, $inputMode, $maxLength, $hint]): ?>
                <div class="field">
                    <label for="<?= e($field) ?>"><?= e($label) ?> (wymagane)</label>
                    <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="text" inputmode="<?= e($inputMode) ?>" maxlength="<?= e((string) $maxLength) ?>" value="<?= e($values[$field]) ?>" required aria-describedby="<?= e($field) ?>-hint<?= isset($errors[$field]) ? ' ' . e($field) . '-error' : '' ?>"<?= isset($errors[$field]) ? ' aria-invalid="true"' : '' ?>>
                    <small class="hint" id="<?= e($field) ?>-hint"><?= e($hint) ?></small>
                    <?php if (isset($errors[$field])): ?><span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="field">
        <label for="active">Status <?= e($itemLabel) ?></label>
        <select id="active" name="active" required aria-describedby="active-hint<?= isset($errors['active']) ? ' active-error' : '' ?>"<?= isset($errors['active']) ? ' aria-invalid="true"' : '' ?>>
            <option value="1"<?= $values['active'] === '1' ? ' selected' : '' ?>>Aktywna</option>
            <option value="0"<?= $values['active'] === '0' ? ' selected' : '' ?>>Nieaktywna</option>
        </select>
        <small class="hint" id="active-hint"><?= $isService ? 'Dezaktywacja zachowuje rezerwacje i przypisania pracowników. Zmiana czasu usługi nie przesuwa istniejących wizyt.' : 'Zmiana statusu kategorii nie zmienia statusów przypisanych usług.' ?></small>
        <?php if (isset($errors['active'])): ?><span class="field-error" id="active-error"><?= e($errors['active']) ?></span><?php endif; ?>
    </div>
    <button class="button" type="submit"><?= $action === 'create' ? ($isService ? 'Dodaj usługę' : 'Dodaj kategorię') : 'Zapisz zmiany' ?> <span aria-hidden="true">→</span></button>
    <a class="button button-secondary" href="<?= e($basePath) ?>">Anuluj i wróć do listy</a>
</form>
