<?php
declare(strict_types=1);
?>
<section class="offer-module" aria-label="Oferta salonu">
    <?php if ($offerError !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($offerError) ?></p>
        <a class="button button-secondary offer-link" href="/services.php">Wróć do oferty</a>
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
        <?php if ($offerSection === 'booking'): ?>
            <ol class="booking-steps" aria-label="Kolejność rezerwacji">
                <li aria-current="step"><span>1</span> Usługa</li>
                <li><span>2</span> Pracownik</li>
                <li><span>3</span> Data</li>
                <li><span>4</span> Godzina</li>
                <li><span>5</span> Potwierdzenie</li>
            </ol>
        <?php endif; ?>
        <div class="offer-service offer-detail">
            <p class="offer-category"><?= e($service['category_name']) ?></p>
            <?php if ($offerSection === 'booking'): ?><h2><?= e($service['name']) ?></h2><?php endif; ?>
            <?php require __DIR__ . '/offer-service-summary.php'; ?>
            <?php if ($offerSection === 'detail'): ?>
                <a class="button offer-link" href="/booking.php?service_id=<?= e((string) $service['id']) ?>">Wybierz usługę i przejdź dalej <span aria-hidden="true">→</span></a>
            <?php else: ?>
                <div class="notice notice-info">
                    <strong>Kolejny krok: wybór pracownika</strong>
                    <p>Wybrano usługę. Wybór pracownika i terminu będzie dostępny wkrótce. Nie utworzono jeszcze rezerwacji.</p>
                </div>
                <a class="offer-text-link" href="/service.php?id=<?= e((string) $service['id']) ?>">Wróć do szczegółów usługi</a>
            <?php endif; ?>
        </div>
        <a class="button button-secondary offer-link" href="/services.php"><?= $offerSection === 'booking' ? 'Zmień usługę' : 'Wróć do oferty' ?></a>
    <?php endif; ?>
</section>
