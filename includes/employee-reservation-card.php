<?php declare(strict_types=1); ?>
<article class="visit-card" data-reservation-id="<?= e((string) $visit['id']) ?>">
    <header>
        <div><p class="eyebrow">WIZYTA NR <?= e((string) $visit['id']) ?></p><h2><?= e($visit['client_name'] . ' ' . $visit['client_surname']) ?></h2></div>
        <span class="category-status reservation-status-<?= e($visit['status']) ?>"><?= e(RESERVATION_STATUSES[$visit['status']]) ?></span>
    </header>
    <dl class="visit-facts">
        <div><dt>Usługa</dt><dd><?= e($visit['service_name']) ?></dd></div>
        <div><dt>Data wizyty</dt><dd><time datetime="<?= e($visit['reservation_date']) ?>"><?= e(implode('.', array_reverse(explode('-', $visit['reservation_date'])))) ?></time></dd></div>
        <div><dt>Godziny</dt><dd><?= e($visit['start_time'] . '–' . $visit['end_time']) ?></dd></div>
    </dl>
    <?php if ($action === 'list'): ?>
        <a class="button button-secondary category-link" href="/employee.php?action=details&amp;reservation_id=<?= e((string) $visit['id']) ?>">Szczegóły i status<span class="visually-hidden"> wizyty nr <?= e((string) $visit['id']) ?></span></a>
    <?php else: ?>
        <dl class="visit-facts">
            <div><dt>Telefon klienta</dt><dd><?= e($visit['client_phone'] ?: 'Nie podano telefonu') ?></dd></div>
        </dl>
        <h3>Informacje do wizyty</h3>
        <p class="category-description"><?= e($visit['comment'] === null || trim($visit['comment']) === '' ? 'Brak dodatkowych informacji.' : $visit['comment']) ?></p>
    <?php endif; ?>
</article>
