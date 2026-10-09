<?php
declare(strict_types=1);

/** Odpowiedź publiczna nie zawiera szczegółów wyjątku ani konfiguracji. */
function application_error_response(): void
{
    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }

    if (!headers_sent()) {
        header_remove('Location');
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    echo 'Wystąpił błąd aplikacji. Spróbuj ponownie później.';
}

function initialize_error_handling(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('zend.exception_ignore_args', '1');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $error): void {
        // Bez getMessage(), argumentów i śladu stosu: mogą zawierać hasła lub SQL.
        error_log(sprintf(
            '[CutTime] Unhandled %s in %s:%d',
            get_class($error),
            basename($error->getFile()),
            $error->getLine()
        ));
        application_error_response();
        exit(1);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log(sprintf('[CutTime] Fatal error type %d at line %d', $error['type'], $error['line']));
            application_error_response();
        }
    });
}
