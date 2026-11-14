<?php declare(strict_types=1); ?>
<section class="client-reservations" aria-label="Własne rezerwacje">
    <nav class="reservation-tabs" aria-label="Grupy własnych rezerwacji">
        <?php foreach (CLIENT_RESERVATION_GROUPS as $key => $label): ?>
            <a href="/client-reservations.php?group=<?= e($key) ?>"<?= $action === 'list' && $group === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($error) ?></p>
        <a class="offer-text-link" href="/client-reservations.php">Wróć do swoich rezerwacji</a>
    <?php else: ?>
        <p class="hint">Cena pochodzi z aktualnego cennika usługi. System nie przechowuje ceny z dnia rezerwacji.</p>
        <?php if ($action === 'list'): ?>
            <?php if ($group === 'history'): ?><p class="notice notice-info">Wizyty zrealizowane oraz te, których termin rozpoczęcia już minął. Sam upływ czasu nie zmienia statusu wizyty.</p><?php endif; ?>
            <p>Liczba wizyt w tej grupie: <strong><?= e((string) $listing['total']) ?></strong></p>
            <?php if ($listing['rows'] === []): ?>
                <p class="notice notice-info">Nie masz wizyt w tej grupie. <a href="/services.php">Przejdź do oferty salonu</a>.</p>
            <?php else: ?>
                <div class="visit-grid">
                    <?php foreach ($listing['rows'] as $visit): ?>
                        <?php require __DIR__ . '/client-reservation-card.php'; ?>
                    <?php endforeach; ?>
                </div>
                <?php render_admin_pagination($listing, '/client-reservations.php', 'Strony własnych rezerwacji', ['group' => $group]); ?>
            <?php endif; ?>
        <?php else: ?>
            <?php $visit = $reservation; require __DIR__ . '/client-reservation-card.php'; ?>
            <?php if (client_reservation_can_cancel($reservation)): ?>
                <form class="category-form visit-cancel" method="post" action="/client-reservations.php?action=cancel">
                    <h2>Anulowanie wizyty</h2>
                    <p>Anulujesz wizytę pokazaną powyżej. Termin stanie się dostępny dla innych klientów. Rezerwacja pozostanie w Twojej historii ze statusem „Anulowana”.</p>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
                    <label class="category-checkbox"><input type="checkbox" name="confirm" value="1" required> Potwierdzam anulowanie tej wizyty.</label>
                    <button class="button button-danger" type="submit">Anuluj tę wizytę</button>
                </form>
            <?php else: ?>
                <p class="notice notice-info">Tej wizyty nie można już anulować. Anulowanie jest dostępne tylko przed rozpoczęciem wizyty oczekującej lub potwierdzonej.</p>
            <?php endif; ?>
            <a class="offer-text-link" href="/client-reservations.php">Wróć do swoich rezerwacji</a>
        <?php endif; ?>
    <?php endif; ?>
</section>
