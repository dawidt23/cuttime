<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-employees.php';

/** Wspólny kontekst dwóch modułów; kontroler wcześniej wymaga roli admin. */
function admin_employee_context(array $actions): array
{
    $context = admin_form_request($actions) + ['employee_id' => null, 'employee' => null, 'listing' => null];
    if ($context['errors'] !== []) { return $context; }
    if (array_key_exists('employee_id', $_GET)) {
        $context['employee_id'] = admin_record_id($_GET['employee_id']);
        if ($context['employee_id'] === null) {
            http_response_code(400);
            $context['errors']['_form'] = 'Nieprawidłowy identyfikator pracownika.';
        } else {
            $context['employee'] = employee_find($context['employee_id']);
            if (($failure = employee_record_error($context['employee'])) !== null) {
                http_response_code($failure['status']);
                $context['errors'] = $failure['errors'];
            }
        }
    } elseif ($context['action'] !== 'list') {
        http_response_code(400);
        $context['errors']['_form'] = 'Najpierw wybierz pracownika.';
    } else {
        $page = admin_page_number($_GET);
        if ($page === null) {
            http_response_code(400);
            $context['errors']['_form'] = 'Nieprawidłowy numer strony.';
        } else {
            $context['listing'] = employee_list($page);
        }
    }
    return $context;
}

/** Jedna transakcja i blokada pracownika dla zmian usług i dostępności. */
function employee_relation_change(int $employeeId, callable $change, ?PDO $connection = null): array
{
    $connection ??= db();
    $connection->beginTransaction();
    try {
        // Blokada rodzica serializuje również zapisy do pustego zbioru dostępności.
        if (($failure = employee_record_error(employee_find($employeeId, $connection, true))) !== null) {
            $connection->rollBack();
            return ['success' => false] + $failure;
        }
        $result = $change($connection);
        if ($result['success']) { $connection->commit(); }
        else { $connection->rollBack(); }
        return $result;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1452, 1062, 1205, 1213], true)) {
            return ['success' => false, 'status' => 409, 'errors' => ['_form' => 'Dane zmieniły się podczas zapisu lub są teraz edytowane. Odśwież formularz i spróbuj ponownie.']];
        }
        throw $error; // Wspólna, bezpieczna obsługa błędów Etapu 2.
    }
}
