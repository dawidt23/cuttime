<?php declare(strict_types=1); ?>
<section class="category-module" aria-label="Tygodniowa dostępność pracownika">
    <?php require __DIR__ . '/admin-employee-context-view.php'; ?>
    <?php if (!$unavailable && $employee !== null): ?>
        <?php $listPath = '/admin-availability.php?employee_id=' . $employeeId; ?>
        <?php if ($action === 'list'): ?>
            <div class="category-toolbar">
                <p>Powtarzalny plan tygodnia · Liczba przedziałów: <strong><?= e((string) count($rows)) ?></strong></p>
                <a class="button category-link" href="<?= e($listPath) ?>&amp;action=create">Dodaj godziny <span aria-hidden="true">+</span></a>
            </div>
            <?php if ($rows === []): ?><p class="notice notice-info">Nie dodano jeszcze dostępności tego pracownika.</p>
            <?php else: ?>
                <p class="hint">Na małym ekranie tabelę możesz przewijać w poziomie.</p>
                <div class="category-table-wrap" role="region" aria-label="Tabela dostępności" tabindex="0">
                    <table class="category-table availability-table">
                        <caption>Godziny pracy w każdym tygodniu. Zmiany nie przesuwają ani nie anulują już zapisanych wizyt.</caption>
                        <thead><tr><th scope="col">Dzień tygodnia</th><th scope="col">Początek</th><th scope="col">Koniec</th><th scope="col">Akcje</th></tr></thead>
                        <tbody><?php foreach ($rows as $row): ?>
                            <tr><th scope="row"><?= e(AVAILABILITY_DAYS[(int) $row['day_of_week']]) ?></th><td><?= e($row['start_time']) ?></td><td><?= e($row['end_time']) ?></td>
                                <td><div class="category-actions">
                                    <a href="<?= e($listPath) ?>&amp;action=edit&amp;id=<?= e((string) $row['id']) ?>">Edytuj</a>
                                    <a class="category-delete-link" href="<?= e($listPath) ?>&amp;action=delete&amp;id=<?= e((string) $row['id']) ?>">Usuń</a>
                                </div></td></tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php elseif ($action === 'delete'): ?>
            <div class="category-confirm">
                <h2>Usuń przedział dostępności</h2>
                <p><strong><?= e(AVAILABILITY_DAYS[(int) $availability['day_of_week']]) ?>: <?= e($availability['start_time']) ?>–<?= e($availability['end_time']) ?></strong></p>
                <p>Ten przedział zniknie z planu tygodnia. Istniejące rezerwacje i ich terminy pozostaną bez zmian.</p>
                <form method="post" action="<?= e($listPath) ?>&amp;action=delete&amp;id=<?= e((string) $id) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam usunięcie tego przedziału.</label>
                    <button class="button button-danger" type="submit">Usuń dostępność</button>
                </form>
                <a class="button button-secondary" href="<?= e($listPath) ?>">Anuluj i wróć do godzin pracy</a>
            </div>
        <?php else: ?>
            <h2><?= $action === 'create' ? 'Dodaj godziny pracy' : 'Edytuj godziny pracy' ?></h2>
            <p>Wszystkie pola są wymagane. Przedział musi mieścić się w jednym dniu. Stykające się przedziały są dozwolone; nakładające się godziny zostaną odrzucone.</p>
            <form class="category-form" method="post" action="<?= e($listPath) ?>&amp;action=<?= e($action) ?><?= $id === null ? '' : '&amp;id=' . e((string) $id) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <div class="field">
                    <label for="day_of_week">Dzień tygodnia</label>
                    <select id="day_of_week" name="day_of_week" required<?php if (isset($errors['day_of_week'])): ?> aria-invalid="true" aria-describedby="day_of_week-error"<?php endif; ?>>
                        <option value="">Wybierz dzień</option>
                        <?php foreach (AVAILABILITY_DAYS as $day => $label): ?><option value="<?= e((string) $day) ?>"<?= $values['day_of_week'] === (string) $day ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['day_of_week'])): ?><span class="field-error" id="day_of_week-error"><?= e($errors['day_of_week']) ?></span><?php endif; ?>
                </div>
                <div class="form-grid">
                    <?php foreach (['start_time' => 'Godzina początku', 'end_time' => 'Godzina końca'] as $field => $label): ?>
                        <div class="field">
                            <label for="<?= e($field) ?>"><?= e($label) ?></label>
                            <input type="time" step="1" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($values[$field]) ?>" required
                                <?php if (isset($errors[$field])): ?>aria-invalid="true" aria-describedby="<?= e($field) ?>-error"<?php endif; ?>>
                            <?php if (isset($errors[$field])): ?><span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="hint">Godziny lokalne salonu. Możesz podać również sekundy; np. 09:00:00–17:00:00. Edycja nie zmienia istniejących rezerwacji.</p>
                <button class="button" type="submit">Zapisz dostępność</button>
                <a class="button button-secondary" href="<?= e($listPath) ?>">Anuluj i wróć do godzin pracy</a>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
