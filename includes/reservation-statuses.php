<?php
declare(strict_types=1);

// Wspólne wartości zgodne z reservations.status w database/database.sql.
const RESERVATION_STATUSES = [
    'pending' => 'Oczekująca', 'confirmed' => 'Potwierdzona',
    'completed' => 'Zrealizowana', 'cancelled' => 'Anulowana',
];
const BOOKING_BLOCKING_STATUSES = ['pending', 'confirmed', 'completed'];
const RESERVATION_CANCELLABLE_STATUSES = ['pending', 'confirmed'];
// Pracownik nie przywraca anulowanej wizyty ani nie cofa realizacji.
const EMPLOYEE_RESERVATION_TRANSITIONS = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['completed', 'cancelled'],
    'completed' => [], 'cancelled' => [],
];
