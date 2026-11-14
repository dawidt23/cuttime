<?php declare(strict_types=1); ?>
<section aria-label="Wizyty przypisane do pracownika">
    <nav class="reservation-tabs" aria-label="Grupy wizyt pracownika">
        <?php foreach (EMPLOYEE_RESERVATION_GROUPS as $key => $label): ?>
            <a href="/employee.php?group=<?= e($key) ?>"<?= $action === 'list' && $group === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($error) ?></p>
        <a class="offer-text-link" href="/employee.php">Wróć do swojego terminarza</a>
    <?php elseif ($action === 'list'): ?>
        <p class="hint"><?php if ($group === 'today'): ?>Wszystkie dzisiejsze wizyty, również zrealizowane i anulowane.<?php elseif ($group === 'future'): ?>Oczekujące i potwierdzone wizyty od jutra. Dzisiejsze terminy znajdziesz w zakładce Dzisiaj.<?php else: ?>Wizyty z poprzednich dni oraz wszystkie zrealizowane i anulowane. Upływ czasu nie zmienia automatycznie statusu.<?php endif; ?></p>
        <p>Liczba wizyt: <strong><?= e((string) $listing['total']) ?></strong></p>
        <?php if ($listing['rows'] === []): ?>
            <p class="notice notice-info">Nie masz wizyt w tej grupie.</p>
        <?php else: ?>
            <div class="visit-grid">
                <?php foreach ($listing['rows'] as $visit): ?>
                    <?php require __DIR__ . '/employee-reservation-card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php render_admin_pagination($listing, '/employee.php', 'Strony wizyt pracownika', ['group' => $group]); ?>
        <?php endif; ?>
    <?php else: ?>
        <?php $visit = $reservation; require __DIR__ . '/employee-reservation-card.php'; ?>
        <?php $options = employee_reservation_status_options($reservation); ?>
        <?php if ($options === []): ?>
            <p class="notice notice-info">To status końcowy. Nie można go zmienić w panelu pracownika.</p>
        <?php else: ?>
            <form class="category-form" action="/employee.php?action=status" method="post">
                <h2>Zmień status wizyty</h2>
                <p class="hint">Zrealizowanie jest dostępne po planowanym końcu wizyty. Anulowanie zwalnia termin i pozostawia wizytę w historii; pracownik nie może cofnąć anulowania.</p>
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
                <input type="hidden" name="expected_status" value="<?= e($reservation['status']) ?>">
                <div class="field">
                    <label for="visit-status">Nowy status</label>
                    <select id="visit-status" name="status" required>
                        <option value="">Wybierz status</option>
                        <?php foreach ($options as $value): ?><option value="<?= e($value) ?>"><?= e(RESERVATION_STATUSES[$value]) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam zmianę statusu tej wizyty, w tym anulowanie, jeśli je wybrano.</label>
                <button class="button" type="submit">Zapisz status wizyty</button>
            </form>
        <?php endif; ?>
        <a class="offer-text-link" href="/employee.php">Wróć do swojego terminarza</a>
    <?php endif; ?>
</section>
