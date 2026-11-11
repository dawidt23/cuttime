<?php
declare(strict_types=1);
// Dane z zapytania oferty; wspólne wyświetlanie na liście i w szczegółach.
?>
<p class="offer-description"><?= e($service['description'] !== null && trim($service['description']) !== '' ? $service['description'] : 'Szczegółowy opis tej usługi pojawi się wkrótce.') ?></p>
<dl class="offer-facts">
    <div><dt>Cena</dt><dd><?= e(str_replace('.', ',', $service['price'])) ?> zł</dd></div>
    <div><dt>Czas trwania</dt><dd><?= e((string) $service['duration']) ?> min</dd></div>
</dl>
