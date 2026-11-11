<?php
declare(strict_types=1);
$bookingBase = '/booking.php?service_id=' . rawurlencode((string) $service['id']);
$bookingEmployeeBase = $bookingBase . '&employee_id=' . rawurlencode((string) ($booking['employee']['id'] ?? ''));
$bookingDateBase = $bookingEmployeeBase . '&date=' . rawurlencode($booking['date']?->format('Y-m-d') ?? '');
?>
<?php if ($booking['stage'] === 'employee'): ?>
    <h3>Wybierz pracownika</h3>
    <?php if ($booking['employees'] === []): ?>
        <p class="notice notice-info">Obecnie żaden aktywny pracownik nie wykonuje tej usługi. Wybierz inną usługę lub spróbuj później.</p>
    <?php else: ?>
        <p class="intro">Pokazujemy wyłącznie aktywnych pracowników wykonujących wybraną usługę.</p>
        <ul class="booking-employee-list">
            <?php foreach ($booking['employees'] as $employee): ?>
                <li>
                    <div><strong><?= e($employee['name'] . ' ' . $employee['surname']) ?></strong><?php if ($employee['description'] !== null && $employee['description'] !== ''): ?><small><?= e($employee['description']) ?></small><?php endif; ?></div>
                    <a class="button" href="<?= e($bookingBase . '&employee_id=' . rawurlencode((string) $employee['id'])) ?>">Wybierz <span aria-hidden="true">→</span></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php elseif ($booking['stage'] === 'date'): ?>
    <h3>Wybierz datę</h3>
    <p class="intro">Wybrany pracownik: <strong><?= e($booking['employee']['name'] . ' ' . $booking['employee']['surname']) ?></strong>.</p>
    <form class="booking-date-form" method="get" action="/booking.php">
        <input type="hidden" name="service_id" value="<?= e((string) $service['id']) ?>">
        <input type="hidden" name="employee_id" value="<?= e((string) $booking['employee']['id']) ?>">
        <label for="booking-date">Data wizyty</label>
        <input id="booking-date" name="date" type="date" min="<?= e(booking_now()->format('Y-m-d')) ?>" max="9999-12-31" required>
        <button class="button" type="submit">Pokaż dostępne godziny <span aria-hidden="true">→</span></button>
    </form>
<?php elseif ($booking['stage'] === 'time'): ?>
    <h3>Wybierz godzinę</h3>
    <p class="intro"><?= e($booking['employee']['name'] . ' ' . $booking['employee']['surname']) ?> · <?= e($booking['date']->format('d.m.Y')) ?> · usługa trwa <?= e((string) $service['duration']) ?> min.</p>
    <?php if ($booking['slots'] === []): ?>
        <p class="notice notice-info">Brak wolnych terminów w tym dniu. Wybierz inną datę.</p>
    <?php else: ?>
        <p class="hint">Godziny są wyliczane przez serwer co 15 minut. Pokazujemy tylko terminy obejmujące cały czas usługi.</p>
        <div class="booking-slot-grid" aria-label="Dostępne godziny">
            <?php foreach ($booking['slots'] as $slot): ?>
                <a class="booking-slot" href="<?= e($bookingDateBase . '&time=' . rawurlencode($slot['time'])) ?>" aria-label="Wybierz termin od <?= e($slot['time']) ?> do <?= e($slot['end_time']) ?>">
                    <strong><?= e($slot['time']) ?></strong><small>do <?= e($slot['end_time']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <a class="offer-text-link" href="<?= e($bookingEmployeeBase) ?>">Zmień datę</a>
<?php else: ?>
    <h3>Wybrany termin</h3>
    <div class="notice notice-info">
        <strong><?= e($booking['employee']['name'] . ' ' . $booking['employee']['surname']) ?></strong>
        <p><?= e($booking['date']->format('d.m.Y')) ?>, <?= e($booking['selected_slot']['time']) ?>–<?= e($booking['selected_slot']['end_time']) ?>. Rezerwacja nie została jeszcze utworzona — jej potwierdzenie i zapis będą dostępne w kolejnym kroku.</p>
    </div>
    <a class="offer-text-link" href="<?= e($bookingDateBase) ?>">Zmień godzinę</a>
<?php endif; ?>
<a class="offer-text-link" href="/service.php?id=<?= e((string) $service['id']) ?>">Wróć do szczegółów usługi</a>
