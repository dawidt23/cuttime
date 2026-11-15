<?php
declare(strict_types=1);
$bookingSteps = ['Usługa', 'Pracownik', 'Data', 'Godzina', 'Podsumowanie', 'Potwierdzenie'];
?>
<p class="hint">Krok <?= e((string) $bookingStep) ?> z 6: <?= e($bookingSteps[$bookingStep - 1]) ?></p>
<ol class="booking-steps" aria-label="Kolejność rezerwacji">
    <?php foreach ($bookingSteps as $index => $label): ?>
        <li<?= $index + 1 === $bookingStep ? ' aria-current="step"' : '' ?><?= $index + 1 < $bookingStep ? ' class="booking-step-done"' : '' ?>>
            <span aria-hidden="true"><?= e((string) ($index + 1)) ?></span> <?= e($label) ?><?php if ($index + 1 < $bookingStep): ?><span class="visually-hidden"> — wybrano</span><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
