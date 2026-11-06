<?php declare(strict_types=1); ?>
<section class="category-module" aria-label="Przypisanie usług do pracownika">
    <?php require __DIR__ . '/admin-employee-context-view.php'; ?>
    <?php if (!$unavailable && $employee !== null): ?>
        <p>Zaznacz wszystkie usługi, które mają pozostać przypisane. Odznaczenie usuwa tylko przypisanie do tego pracownika. Pusty wybór usuwa wszystkie jego przypisania.</p>
        <p class="hint">Usługi i kategorie nieaktywne są oznaczone. Możesz zachować lub przygotować ich przypisania bez zmiany aktywności. Limit jednego zestawu: 500 usług.</p>
        <?php if ($options === []): ?>
            <p class="notice notice-info">Nie ma jeszcze usług do przypisania.</p>
            <a class="button category-link" href="/admin-services.php?action=create">Dodaj usługę</a>
        <?php else: ?>
            <form method="post" action="/admin-employee-services.php?action=edit&amp;employee_id=<?= e((string) $employeeId) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <fieldset class="service-selection" aria-describedby="assignment-hint">
                    <legend>Usługi wykonywane przez pracownika</legend>
                    <?php foreach ($options as $option): ?>
                        <label class="service-choice">
                            <input type="checkbox" name="service_ids[]" value="<?= e((string) $option['id']) ?>"<?= in_array((int) $option['id'], $selected, true) ? ' checked' : '' ?>>
                            <span><strong><?= e($option['name']) ?></strong>
                                <small><?= e($option['category_name']) ?> · <?= e((string) $option['duration']) ?> min · <?= e(str_replace('.', ',', $option['price'])) ?> zł</small>
                                <?php if ((int) $option['active'] !== 1): ?><small>Usługa nieaktywna</small><?php endif; ?>
                                <?php if ((int) $option['category_active'] !== 1): ?><small>Kategoria nieaktywna</small><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p id="assignment-hint" class="hint">Zapis zastąpi bieżący zestaw przypisań wybranymi usługami. Nie zmieni istniejących rezerwacji ani samych usług.</p>
                <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam wybrany zestaw i usunięcie odznaczonych przypisań.</label>
                <button class="button category-link" type="submit">Zapisz przypisania</button>
                <!-- Znacznik po checkboxach zapobiega zapisowi formularza uciętego przez limit PHP. -->
                <input type="hidden" name="_complete" value="1">
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
