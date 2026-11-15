<?php
declare(strict_types=1);
?>
<section class="offer-module" aria-label="Oferta salonu">
    <?php
    $bookingStep = $offerSection === 'booking' && isset($booking)
        ? ['employee' => 2, 'date' => 3, 'time' => 4, 'confirmation' => 5][$booking['stage']] : 1;
    require __DIR__ . '/booking-progress-view.php';
    ?>
    <?php if ($offerError !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($offerError) ?></p>
        <?php if (isset($bookingRetryUrl)): ?>
            <?php require __DIR__ . '/booking-recovery-view.php'; ?>
        <?php else: ?><a class="button button-secondary offer-link" href="/services.php">Wróć do oferty</a><?php endif; ?>
    <?php elseif ($offerSection === 'list'): ?>
        <?php if ($services === []): ?>
            <p class="notice notice-info">Obecnie nie ma dostępnych usług. Zajrzyj ponownie później.</p>
        <?php else: ?>
            <div class="offer-grid">
                <?php foreach ($services as $service): ?>
                    <article class="offer-service" aria-labelledby="service-<?= e((string) $service['id']) ?>">
                        <p class="offer-category"><?= e($service['category_name']) ?></p>
                        <h2 id="service-<?= e((string) $service['id']) ?>"><a href="/service.php?id=<?= e((string) $service['id']) ?>"><?= e($service['name']) ?></a></h2>
                        <?php require __DIR__ . '/offer-service-summary.php'; ?>
                        <div class="offer-actions">
                            <a class="button button-secondary" href="/service.php?id=<?= e((string) $service['id']) ?>" aria-label="Szczegóły: <?= e($service['name']) ?>">Szczegóły</a>
                            <a class="button" href="/booking.php?service_id=<?= e((string) $service['id']) ?>" aria-label="Wybierz usługę: <?= e($service['name']) ?>">Wybierz usługę <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="offer-service offer-detail">
            <?php if ($offerSection !== 'booking' || $booking['stage'] !== 'confirmation'): ?>
            <p class="offer-category"><?= e($service['category_name']) ?></p>
            <?php if ($offerSection === 'booking'): ?><h2><?= e($service['name']) ?></h2><?php endif; ?>
            <?php require __DIR__ . '/offer-service-summary.php'; ?>
            <?php endif; ?>
            <?php if ($offerSection === 'detail'): ?>
                <a class="button offer-link" href="/booking.php?service_id=<?= e((string) $service['id']) ?>">Wybierz usługę i przejdź dalej <span aria-hidden="true">→</span></a>
            <?php else: ?>
                <?php require __DIR__ . '/booking-selection-view.php'; ?>
            <?php endif; ?>
        </div>
        <a class="button button-secondary offer-link" href="/services.php"><?= $offerSection === 'booking' ? 'Zmień usługę' : 'Wróć do oferty' ?></a>
    <?php endif; ?>
</section>
