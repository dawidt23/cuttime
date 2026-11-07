<?php
declare(strict_types=1);
?>
<section class="category-module" aria-label="Zarządzanie pracownikami">
    <?php require __DIR__ . '/admin-errors-view.php'; ?>
    <?php if ($unavailable): ?>
        <a class="button button-secondary category-link" href="/admin-employees.php">Wróć do listy pracowników</a>
    <?php elseif ($action === 'list'): ?>
        <div class="category-toolbar">
            <p>Liczba pracowników: <strong><?= e((string) $listing['total']) ?></strong></p>
            <a class="button category-link" href="/admin-employees.php?action=create">Dodaj pracownika <span aria-hidden="true">+</span></a>
        </div>
        <p class="notice notice-info">Zamiast usuwać pracownika, dezaktywuj jego profil i konto. Historia wizyt, przypisane usługi i dostępność pozostaną zachowane.</p>
        <?php if ($listing['rows'] === []): ?>
            <p>Nie ma jeszcze pracowników. Dodaj pierwszą osobę do zespołu.</p>
        <?php else: ?>
            <p class="hint">Na wąskim ekranie przewiń tabelę w poziomie, aby zobaczyć wszystkie kolumny i akcje.</p>
            <div class="category-table-wrap" role="region" aria-label="Tabela pracowników — przewiń w poziomie na małym ekranie" tabindex="0">
                <table class="category-table employee-table">
                    <caption>Pracownicy — strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?>. Opisy skrócono do 160 znaków; pełny opis jest dostępny w edycji.</caption>
                    <thead><tr><th scope="col">Imię i nazwisko</th><th scope="col">E-mail</th><th scope="col">Telefon</th><th scope="col">Aktywność</th><th scope="col">Opis</th><th scope="col">Akcje</th></tr></thead>
                    <tbody>
                    <?php foreach ($listing['rows'] as $row): ?>
                        <tr>
                            <th scope="row"><?= e($row['name'] . ' ' . $row['surname']) ?><small>ID pracownika: <?= e((string) $row['id']) ?></small></th>
                            <td><?= e($row['email']) ?></td>
                            <td><?= e($row['phone'] ?? 'Nie podano') ?></td>
                            <td>
                                <span class="category-status<?= (int) $row['active'] === 1 ? '' : ' category-inactive' ?>">Profil: <?= (int) $row['active'] === 1 ? 'aktywny' : 'nieaktywny' ?></span>
                                <span class="category-status<?= (int) $row['account_active'] === 1 ? '' : ' category-inactive' ?>">Konto: <?= (int) $row['account_active'] === 1 ? 'aktywne' : 'nieaktywne' ?></span>
                            </td>
                            <td class="category-description"><?= e($row['description'] ?? 'Brak opisu') ?></td>
                            <td><div class="category-actions">
                                <?php if ($row['role'] !== 'employee'): ?>
                                    <small>Operacje zablokowane: konto ma inną rolę niż pracownik.</small>
                                <?php else: ?>
                                    <a href="/admin-employees.php?action=edit&amp;id=<?= e((string) $row['id']) ?>" aria-label="Edytuj pracownika <?= e($row['name'] . ' ' . $row['surname']) ?>">Edytuj</a>
                                    <a href="/admin-employee-services.php?employee_id=<?= e((string) $row['id']) ?>">Usługi</a>
                                    <a href="/admin-availability.php?employee_id=<?= e((string) $row['id']) ?>">Dostępność</a>
                                    <?php if ((int) $row['active'] !== 1 || (int) $row['account_active'] !== 1): ?>
                                        <a href="/admin-employees.php?action=activate&amp;id=<?= e((string) $row['id']) ?>">Aktywuj</a>
                                    <?php endif; ?>
                                    <?php if ((int) $row['active'] === 1 || (int) $row['account_active'] === 1): ?>
                                        <a href="/admin-employees.php?action=deactivate&amp;id=<?= e((string) $row['id']) ?>">Dezaktywuj</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_admin_pagination($listing, '/admin-employees.php', 'Strony pracowników'); ?>
        <?php endif; ?>
    <?php elseif (in_array($action, ['activate', 'deactivate'], true)): ?>
        <?php $operationLabel = $action === 'activate' ? 'Aktywuj' : 'Dezaktywuj'; ?>
        <div class="category-confirm">
            <h2><?= e($operationLabel) ?> pracownika: <?= e($employee['name'] . ' ' . $employee['surname']) ?></h2>
            <p><?= e($employee['email']) ?></p>
            <?php if ($action === 'deactivate'): ?>
                <p>Wyłączysz profil pracownika oraz możliwość logowania. Otwarte sesje utracą dostęp przy następnym żądaniu do chronionej strony.</p>
            <?php else: ?>
                <p>Włączysz profil pracownika i jego konto. Pracownik będzie mógł zalogować się dotychczasowym hasłem.</p>
            <?php endif; ?>
            <p>Rezerwacje, ich statusy i terminy, przypisane usługi oraz dostępność pozostaną zachowane. Dezaktywacja nie anuluje zaplanowanych wizyt.</p>
            <form method="post" action="/admin-employees.php?action=<?= e($action) ?>&amp;id=<?= e((string) $id) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam zmianę aktywności profilu i konta.</label>
                <button class="button<?= $action === 'deactivate' ? ' button-danger' : '' ?>" type="submit"><?= e($operationLabel) ?> pracownika</button>
            </form>
            <a class="button button-secondary" href="/admin-employees.php">Anuluj i wróć do listy</a>
        </div>
    <?php else: ?>
        <?php
        $creating = $action === 'create';
        $fields = [
            'name' => ['Imię', 'text', 50, 'Litery, spacje, apostrofy i myślniki; maks. 50 znaków.'],
            'surname' => ['Nazwisko', 'text', 80, 'Litery, spacje, apostrofy i myślniki; maks. 80 znaków.'],
            'email' => ['Adres e-mail', 'email', 254, 'Unikalny adres używany do logowania pracownika.'],
            'phone' => ['Telefon', 'tel', 32, 'Polski numer: 9 cyfr, opcjonalnie +48, spacje lub myślniki.'],
        ];
        if ($creating) {
            $fields += [
                'password' => ['Hasło', 'password', 72, 'Minimum 12 znaków, maksymalnie 72 bajty. Polskie znaki zajmują więcej bajtów.'],
                'password_confirmation' => ['Powtórz hasło', 'password', 72, 'Powtórz dokładnie to samo hasło.'],
            ];
        }
        ?>
        <p class="intro">Rola konta: <strong>Pracownik</strong>. Wszystkie pola poza opisem są wymagane.</p>
        <p><?= $creating ? 'Powstaną nowe konto i profil pracownika. Podaj adres e-mail, który nie jest jeszcze używany w systemie.' : 'Zmiana danych nie zmienia hasła ani aktywności. Aktywacją konta i profilu zarządzaj z listy pracowników.' ?></p>
        <form class="category-form" method="post" action="/admin-employees.php?action=<?= e($action) ?><?= $creating ? '' : '&amp;id=' . e((string) $id) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="form-grid">
                <?php foreach ($fields as $field => [$label, $type, $max, $hint]): ?>
                    <div class="field<?= in_array($field, ['email', 'phone'], true) ? ' field-wide' : '' ?>">
                        <label for="<?= e($field) ?>"><?= e($label) ?></label>
                        <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>" required
                            maxlength="<?= e((string) $max) ?>" autocomplete="<?= $type === 'password' ? 'new-password' : 'off' ?>"
                            <?php if ($type !== 'password'): ?>value="<?= e($values[$field]) ?>"<?php else: ?>minlength="12"<?php endif; ?>
                            <?php if ($type === 'tel'): ?>inputmode="tel"<?php endif; ?>
                            <?php if (isset($errors[$field])): ?>aria-invalid="true"<?php endif; ?>
                            aria-describedby="<?= e($field) ?>-hint<?= isset($errors[$field]) ? ' ' . e($field) . '-error' : '' ?>">
                        <small class="hint" id="<?= e($field) ?>-hint"><?= e($hint) ?></small>
                        <?php if (isset($errors[$field])): ?><span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="field">
                <label for="description">Opis pracownika (opcjonalny)</label>
                <textarea id="description" name="description" rows="5" maxlength="2000"
                    <?php if (isset($errors['description'])): ?>aria-invalid="true"<?php endif; ?>
                    aria-describedby="description-hint<?= isset($errors['description']) ? ' description-error' : '' ?>"><?= e($values['description']) ?></textarea>
                <small class="hint" id="description-hint">Do 2000 znaków. Możesz opisać doświadczenie i specjalizację.</small>
                <?php if (isset($errors['description'])): ?><span class="field-error" id="description-error"><?= e($errors['description']) ?></span><?php endif; ?>
            </div>
            <?php if ($creating): ?>
                <div class="field">
                    <label for="active">Aktywność profilu i konta</label>
                    <select id="active" name="active" required <?php if (isset($errors['active'])): ?>aria-invalid="true"<?php endif; ?>
                        aria-describedby="active-hint<?= isset($errors['active']) ? ' active-error' : '' ?>">
                        <option value="1"<?= $values['active'] === '1' ? ' selected' : '' ?>>Aktywny — może się logować</option>
                        <option value="0"<?= $values['active'] === '0' ? ' selected' : '' ?>>Nieaktywny — logowanie wyłączone</option>
                    </select>
                    <small class="hint" id="active-hint">Ten sam status otrzymają profil pracownika i konto użytkownika.</small>
                    <?php if (isset($errors['active'])): ?><span class="field-error" id="active-error"><?= e($errors['active']) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <button class="button" type="submit"><?= $creating ? 'Dodaj pracownika' : 'Zapisz zmiany' ?></button>
            <a class="button button-secondary" href="/admin-employees.php">Anuluj i wróć do listy</a>
        </form>
    <?php endif; ?>
</section>
