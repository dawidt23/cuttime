<?php declare(strict_types=1); ?>
<section class="category-module reservation-module" aria-label="Zarządzanie rezerwacjami">
    <?php require __DIR__ . '/admin-errors-view.php'; ?>
    <?php if ($action === 'list'): ?>
        <form class="category-form reservation-filters" method="get" action="/admin-reservations.php" aria-label="Filtry rezerwacji">
            <div class="reservation-filter-grid">
                <div class="field">
                    <label for="filter-date">Data wizyty</label>
                    <input id="filter-date" name="date" type="date" min="1000-01-01" max="9999-12-31" value="<?= e($filters['date']) ?>"<?= isset($errors['date']) ? ' aria-invalid="true" aria-describedby="filter-date-error"' : '' ?>>
                    <?php if (isset($errors['date'])): ?><span class="field-error" id="filter-date-error"><?= e($errors['date']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status"<?= isset($errors['status']) ? ' aria-invalid="true" aria-describedby="filter-status-error"' : '' ?>>
                        <option value="">Wszystkie statusy</option>
                        <?php foreach (RESERVATION_STATUSES as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><span class="field-error" id="filter-status-error"><?= e($errors['status']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="filter-employee">Pracownik</label>
                    <select id="filter-employee" name="employee_id"<?= isset($errors['employee_id']) ? ' aria-invalid="true" aria-describedby="filter-employee-error"' : '' ?>>
                        <option value="">Wszyscy pracownicy</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= e((string) $employee['id']) ?>"<?= $filters['employee_id'] === (string) $employee['id'] ? ' selected' : '' ?>><?= e($employee['name'] . ' ' . $employee['surname']) ?><?= (int) $employee['active'] !== 1 || (int) $employee['account_active'] !== 1 ? ' (nieaktywny profil lub konto)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['employee_id'])): ?><span class="field-error" id="filter-employee-error"><?= e($errors['employee_id']) ?></span><?php endif; ?>
                </div>
            </div>
            <p class="hint">Filtry można łączyć. Puste pole nie ogranicza wyników. Zmiana filtrów rozpoczyna listę od pierwszej strony.</p>
            <div class="reservation-filter-actions">
                <button class="button category-link" type="submit">Zastosuj filtry</button>
                <a class="button button-secondary category-link" href="/admin-reservations.php">Wyczyść filtry</a>
            </div>
        </form>
        <?php if (!$unavailable): ?>
            <p>Liczba znalezionych rezerwacji: <strong><?= e((string) $listing['total']) ?></strong></p>
            <?php if ($listing['rows'] === []): ?>
                <p class="notice notice-info">Brak rezerwacji spełniających wybrane kryteria.</p>
            <?php else: ?>
                <p class="hint">Na wąskim ekranie przewiń tabelę w poziomie. Lista obejmuje również historię nieaktywnych kont i usług.</p>
                <div class="category-table-wrap" role="region" aria-label="Tabela rezerwacji" tabindex="0">
                    <table class="category-table reservation-table">
                        <caption>Od najpóźniejszej daty wizyty · Strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?> · do 20 wizyt na stronie.</caption>
                        <thead><tr><th scope="col">Klient</th><th scope="col">Usługa</th><th scope="col">Pracownik</th><th scope="col">Data</th><th scope="col">Godziny</th><th scope="col">Status</th><th scope="col">Akcje</th></tr></thead>
                        <tbody><?php foreach ($listing['rows'] as $row): ?>
                            <tr data-reservation-id="<?= e((string) $row['id']) ?>">
                                <th scope="row"><?= e($row['client_name'] . ' ' . $row['client_surname']) ?><small>Rezerwacja nr <?= e((string) $row['id']) ?></small></th>
                                <td><?= e($row['service_name']) ?></td>
                                <td><?= e($row['employee_name'] . ' ' . $row['employee_surname']) ?></td>
                                <td><time datetime="<?= e($row['reservation_date']) ?>"><?= e($row['reservation_date']) ?></time></td>
                                <td><?= e($row['start_time']) ?><br>– <?= e($row['end_time']) ?></td>
                                <td><span class="category-status reservation-status-<?= e($row['status']) ?>"><?= e(RESERVATION_STATUSES[$row['status']]) ?></span></td>
                                <td><div class="category-actions"><a href="<?= e(reservation_url($filters, 'details', (int) $row['id'])) ?>" aria-label="Szczegóły rezerwacji nr <?= e((string) $row['id']) ?>">Szczegóły</a></div></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
                <?php render_admin_pagination($listing, '/admin-reservations.php', 'Strony rezerwacji', $filters); ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($unavailable): ?>
        <a class="button button-secondary category-link" href="/admin-reservations.php">Wróć do listy rezerwacji</a>
    <?php else: ?>
        <h2>Rezerwacja nr <?= e((string) $reservation['id']) ?></h2>
        <dl class="profile-details">
            <div><dt>Klient</dt><dd><?= e($reservation['client_name'] . ' ' . $reservation['client_surname']) ?></dd></div>
            <div><dt>Kontakt do klienta</dt><dd><?= e($reservation['client_email']) ?><br><?= e($reservation['client_phone'] ?? 'Nie podano telefonu') ?></dd></div>
            <div><dt>Pracownik</dt><dd><?= e($reservation['employee_name'] . ' ' . $reservation['employee_surname']) ?></dd></div>
            <div><dt>Kontakt do pracownika</dt><dd><?= e($reservation['employee_email']) ?><br><?= e($reservation['employee_phone'] ?? 'Nie podano telefonu') ?></dd></div>
            <div><dt>Usługa</dt><dd><?= e($reservation['service_name']) ?></dd></div>
            <div><dt>Data wizyty</dt><dd><?= e($reservation['reservation_date']) ?></dd></div>
            <div><dt>Godziny wizyty</dt><dd><?= e($reservation['start_time'] . '–' . $reservation['end_time']) ?></dd></div>
            <div><dt>Aktualny status</dt><dd><span class="category-status reservation-status-<?= e($reservation['status']) ?>"><?= e(RESERVATION_STATUSES[$reservation['status']]) ?></span></dd></div>
            <div class="field-wide"><dt>Komentarz</dt><dd class="category-description"><?= e($reservation['comment'] === null || $reservation['comment'] === '' ? 'Brak komentarza' : $reservation['comment']) ?></dd></div>
        </dl>
        <form class="category-form" method="post" action="<?= e(reservation_url($filters, 'status', $id)) ?>">
            <h2>Zmień status</h2>
            <p>Zmiana dotyczy tylko statusu. Nie zmienia terminu, klienta, pracownika ani usługi i nie usuwa rezerwacji.</p>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="expected_status" value="<?= e($reservation['status']) ?>">
            <div class="field">
                <label for="reservation-status">Nowy status</label>
                <select id="reservation-status" name="status" required<?php if (isset($errors['status'])): ?> aria-invalid="true" aria-describedby="status-error"<?php endif; ?>>
                    <?php foreach (RESERVATION_STATUSES as $value => $label): ?><option value="<?= e($value) ?>"<?= $statusValue === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?php if (isset($errors['status'])): ?><span class="field-error" id="status-error"><?= e($errors['status']) ?></span><?php endif; ?>
            </div>
            <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam wybrany status rezerwacji.</label>
            <button class="button" type="submit">Zapisz status</button>
        </form>
        <a class="button button-secondary category-link" href="<?= e(reservation_url($filters)) ?>">Wróć do listy z filtrami</a>
    <?php endif; ?>
</section>
