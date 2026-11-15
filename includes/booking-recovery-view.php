<?php declare(strict_types=1); ?>
<nav class="booking-recovery" aria-label="Ponowny wybór terminu">
    <a class="button" href="<?= e($bookingRetryUrl) ?>"><?= $bookingRetryUrl === '/services.php' ? 'Wróć do oferty' : 'Wróć do wyboru terminu' ?></a>
    <?php if ($bookingRetryUrl !== '/services.php'): ?><a class="button button-secondary" href="/services.php">Wybierz inną usługę</a><?php endif; ?>
</nav>
