<?php
declare(strict_types=1);

const BOOKING_SLOT_MINUTES = 15;
const BOOKING_BLOCKING_STATUSES = ['pending', 'confirmed', 'completed'];

function booking_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    $timezone = new DateTimeZone('Europe/Warsaw');
    return ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
}

/** Aktywni pracownicy przypisani do aktywnej usługi przez N:M employee_services. */
function booking_service_employees(int $serviceId, ?PDO $connection = null): array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT e.id, u.name, u.surname, e.description
         FROM employees e
         JOIN users u ON u.id = e.user_id
         JOIN employee_services es ON es.employee_id = e.id
         JOIN services s ON s.id = es.service_id
         JOIN service_categories c ON c.id = s.category_id
         WHERE es.service_id = :service_id AND e.active = 1 AND u.active = 1
           AND u.role = \'employee\' AND s.active = 1 AND c.active = 1
         ORDER BY u.surname, u.name, e.id'
    );
    $statement->execute(['service_id' => $serviceId]);
    return $statement->fetchAll();
}

/** Nie wystarcza ID: sprawdzamy aktualne przypisanie usługi oraz oba statusy aktywności. */
function booking_employee_for_service(int $serviceId, int $employeeId, ?PDO $connection = null): ?array
{
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT e.id, u.name, u.surname, e.description
         FROM employees e
         JOIN users u ON u.id = e.user_id
         JOIN employee_services es ON es.employee_id = e.id
         JOIN services s ON s.id = es.service_id
         JOIN service_categories c ON c.id = s.category_id
         WHERE es.service_id = :service_id AND e.id = :employee_id
           AND e.active = 1 AND u.active = 1 AND u.role = \'employee\'
           AND s.active = 1 AND c.active = 1'
    );
    $statement->execute(['service_id' => $serviceId, 'employee_id' => $employeeId]);
    $employee = $statement->fetch();
    return $employee === false ? null : $employee;
}

/** Zwraca datę lokalną albo czytelny błąd, bez automatycznego poprawiania danych. */
function booking_date(mixed $value, ?DateTimeImmutable $now = null): array
{
    if (!is_string($value) || strlen($value) !== 10 || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        return ['date' => null, 'error' => 'Wybierz poprawną datę wizyty.'];
    }
    $timezone = new DateTimeZone('Europe/Warsaw');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        return ['date' => null, 'error' => 'Wybierz poprawną datę wizyty.'];
    }
    $today = booking_now($now)->setTime(0, 0);
    if ($date < $today) {
        return ['date' => null, 'error' => 'Nie można wybrać terminu w przeszłości.'];
    }
    return ['date' => $date, 'error' => null];
}

function booking_time_minutes(string $time): int
{
    if (!preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?\z/', $time, $match)) {
        throw new InvalidArgumentException('Niepoprawna godzina.');
    }
    return ((int) $match[1]) * 60 + (int) $match[2];
}

function booking_time_label(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function booking_slot_start(DateTimeImmutable $date, int $minutes): DateTimeImmutable
{
    return $date->setTime(intdiv($minutes, 60), $minutes % 60);
}

/** Przedziały półotwarte: [10:00, 11:00) nie koliduje z [11:00, 12:00). */
function booking_intervals_overlap(int $newStart, int $newEnd, int $existingStart, int $existingEnd): bool
{
    return $newStart < $existingEnd && $newEnd > $existingStart;
}

/** Pojedyncze źródło blokujących statusów; anulowana wizyta nie zajmuje terminu. */
function booking_blocking_reservations(int $employeeId, DateTimeImmutable $date, ?PDO $connection = null): array
{
    $connection ??= db();
    $placeholders = [];
    $parameters = ['employee_id' => $employeeId, 'date' => $date->format('Y-m-d')];
    foreach (BOOKING_BLOCKING_STATUSES as $index => $status) {
        $name = 'status' . $index;
        $placeholders[] = ':' . $name;
        $parameters[$name] = $status;
    }
    $statement = $connection->prepare(
        'SELECT start_time, end_time FROM reservations
         WHERE employee_id = :employee_id AND reservation_date = :date
           AND status IN (' . implode(', ', $placeholders) . ')'
    );
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/**
 * Serwer wyznacza wyłącznie pełne, wolne przedziały w 15-minutowej siatce.
 * Dopuszcza stykające się wizyty: start < istniejący koniec && koniec > istniejący start.
 */
function booking_available_slots(array $service, array $employee, DateTimeImmutable $date, ?PDO $connection = null, ?DateTimeImmutable $now = null): array
{
    $duration = filter_var($service['duration'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
    $employeeId = filter_var($employee['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($duration === false || $employeeId === false) {
        throw new InvalidArgumentException('Niepoprawne dane terminu.');
    }
    $connection ??= db();
    $statement = $connection->prepare(
        'SELECT start_time, end_time FROM employee_availability
         WHERE employee_id = :employee_id AND day_of_week = :day_of_week
         ORDER BY start_time, end_time, id'
    );
    $statement->execute(['employee_id' => $employeeId, 'day_of_week' => (int) $date->format('N')]);
    $availability = $statement->fetchAll();
    $reservations = booking_blocking_reservations($employeeId, $date, $connection);
    $now = booking_now($now);
    $slots = [];

    foreach ($availability as $window) {
        $start = booking_time_minutes($window['start_time']);
        $end = booking_time_minutes($window['end_time']);
        $candidate = (int) (ceil($start / BOOKING_SLOT_MINUTES) * BOOKING_SLOT_MINUTES);
        while ($candidate + $duration <= $end) {
            $candidateEnd = $candidate + $duration;
            $candidateStartDateTime = booking_slot_start($date, $candidate);
            $blocked = $candidateStartDateTime <= $now;
            foreach ($reservations as $reservation) {
                $reservationStart = booking_time_minutes($reservation['start_time']);
                $reservationEnd = booking_time_minutes($reservation['end_time']);
                if (booking_intervals_overlap($candidate, $candidateEnd, $reservationStart, $reservationEnd)) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $slots[$candidate] = ['time' => booking_time_label($candidate), 'end_time' => booking_time_label($candidateEnd)];
            }
            $candidate += BOOKING_SLOT_MINUTES;
        }
    }
    ksort($slots, SORT_NUMERIC);
    return array_values($slots);
}

function booking_selected_time(mixed $value): ?string
{
    return is_string($value) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value) ? $value : null;
}

/** Stan kolejnego kroku bez zapisu danych i bez zaufania do parametrów URL. */
function booking_selection(array $query, ?PDO $connection = null, ?DateTimeImmutable $now = null): array
{
    $result = ['status' => 200, 'error' => null, 'stage' => 'employee', 'service' => null,
        'employees' => [], 'employee' => null, 'date' => null, 'slots' => [], 'selected_slot' => null];
    $serviceId = record_id($query['service_id'] ?? null);
    if ($serviceId === null) {
        return array_replace($result, ['status' => 400, 'error' => 'Nieprawidłowy adres usługi. Wybierz ją ponownie z oferty.']);
    }
    $service = offered_services($serviceId, $connection)[0] ?? null;
    if ($service === null) {
        return array_replace($result, ['status' => 404, 'error' => 'Ta usługa nie jest dostępna. Wybierz inną z aktualnej oferty.']);
    }
    $result['service'] = $service;
    $result['employees'] = booking_service_employees($serviceId, $connection);
    if (!array_key_exists('employee_id', $query)) {
        return $result;
    }
    $employeeId = record_id($query['employee_id']);
    if ($employeeId === null) {
        return array_replace($result, ['status' => 400, 'error' => 'Wybierz poprawnego pracownika.']);
    }
    $employee = booking_employee_for_service($serviceId, $employeeId, $connection);
    if ($employee === null) {
        return array_replace($result, ['status' => 404, 'error' => 'Wybrany pracownik nie wykonuje tej dostępnej usługi. Wybierz go ponownie.']);
    }
    $result['employee'] = $employee;
    $result['stage'] = 'date';
    if (!array_key_exists('date', $query)) {
        return $result;
    }
    $validatedDate = booking_date($query['date'], $now);
    if ($validatedDate['error'] !== null) {
        return array_replace($result, ['status' => 422, 'error' => $validatedDate['error']]);
    }
    $result['date'] = $validatedDate['date'];
    $result['slots'] = booking_available_slots($service, $employee, $result['date'], $connection, $now);
    $result['stage'] = 'time';
    if (!array_key_exists('time', $query)) {
        return $result;
    }
    $time = booking_selected_time($query['time']);
    if ($time === null) {
        return array_replace($result, ['status' => 400, 'error' => 'Wybierz poprawną godzinę.']);
    }
    foreach ($result['slots'] as $slot) {
        if ($slot['time'] === $time) {
            $result['selected_slot'] = $slot;
            $result['stage'] = 'confirmation';
            return $result;
        }
    }
    return array_replace($result, ['status' => 422, 'error' => 'Ten termin nie jest już dostępny. Wybierz inną godzinę.']);
}
