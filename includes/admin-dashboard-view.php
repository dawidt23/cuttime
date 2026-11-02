<?php
declare(strict_types=1);
// Dane przygotowuje kontroler po sprawdzeniu uprawnień, poza widokiem.
$statistics = [
    'today_reservations' => ['Rezerwacje na dzisiaj', 'Wszystkie statusy, także anulowane.'],
    'pending_reservations' => ['Oczekujące rezerwacje', 'Do potwierdzenia, ze wszystkich dat.'],
    'reservations' => ['Wszystkie rezerwacje', 'Cała historia, wszystkie statusy.'],
    'clients' => ['Klienci', 'Wszystkie konta klientów, także nieaktywne.'],
    'employees' => ['Pracownicy', 'Profile pracowników, także nieaktywne.'],
    'services' => ['Usługi', 'Cała oferta, także usługi nieaktywne.'],
];
?>
<section class="dashboard" aria-labelledby="dashboard-summary">
    <div class="dashboard-heading">
        <div>
            <h2 id="dashboard-summary">Salon w liczbach</h2>
            <p>Aktualne podsumowanie Twojego salonu.</p>
        </div>
        <p class="dashboard-date">Dzisiaj: <time datetime="<?= e($dashboard['date']) ?>"><?= e($dashboard['date_label']) ?></time><small>Czas w Polsce</small></p>
    </div>
    <dl class="dashboard-grid">
        <?php foreach ($statistics as $key => [$label, $description]): ?>
            <div class="stat-card<?= $key === 'today_reservations' ? ' stat-card-today' : '' ?>">
                <dt><?= e($label) ?></dt>
                <dd class="stat-value" data-stat="<?= e($key) ?>"><?= e(number_format($dashboard['counts'][$key], 0, ',', ' ')) ?></dd>
                <dd class="stat-description"><?= e($description) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <p class="dashboard-note">Podsumowanie odświeża się przy każdym otwarciu strony. Pozostałe moduły panelu będą udostępniane w kolejnych krokach.</p>
</section>
