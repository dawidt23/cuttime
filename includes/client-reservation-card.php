<?php declare(strict_types=1); ?>
<article class="visit-card" data-reservation-id="<?= e((string) $visit['id']) ?>">
    <header>
        <div><p class="eyebrow">WIZYTA NR <?= e((string) $visit['id']) ?></p><h2><?= e($visit['service_name']) ?></h2></div>
        <span class="category-status reservation-status-<?= e($visit['status']) ?>"><?= e(RESERVATION_STATUSES[$visit['status']]) ?></span>
    </header>
    <dl class="visit-facts">
        <div><dt>Pracownik</dt><dd><?= e($visit['employee_name'] . ' ' . $visit['employee_surname']) ?></dd></div>
        <div><dt>Data wizyty</dt><dd><time datetime="<?= e($visit['reservation_date']) ?>"><?= e(implode('.', array_reverse(explode('-', $visit['reservation_date'])))) ?></time></dd></div>
        <div><dt>Godziny</dt><dd><?= e($visit['start_time'] . '–' . $visit['end_time']) ?></dd></div>
        <div><dt>Aktualna cena usługi</dt><dd><?= e(str_replace('.', ',', $visit['price'])) ?> zł</dd></div>
    </dl>
    <?php if ($action === 'list'): ?>
        <a class="button button-secondary category-link" href="/client-reservations.php?action=details&amp;reservation_id=<?= e((string) $visit['id']) ?>">Szczegóły wizyty<span class="visually-hidden"> nr <?= e((string) $visit['id']) ?></span></a>
    <?php endif; ?>
</article>
