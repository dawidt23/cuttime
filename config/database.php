<?php
declare(strict_types=1);

/** Jedno połączenie na żądanie; wywołuj po załadowaniu includes/bootstrap.php. */
function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $file = __DIR__ . '/database.local.php';
    if (!is_file($file)) {
        throw new RuntimeException('Brak lokalnej konfiguracji bazy.');
    }

    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('Niepoprawna konfiguracja bazy.');
    }

    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('Niepoprawna konfiguracja bazy.');
        }
    }

    // Elementy DSN nie mogą wstrzykiwać dodatkowych parametrów połączenia.
    if (!preg_match('/\A[a-zA-Z0-9.:-]+\z/', $config['host'])
        || !preg_match('/\A[a-zA-Z0-9_]+\z/', $config['name'])
        || trim($config['user']) === ''
        || !isset($config['port']) || !is_int($config['port'])
        || $config['port'] < 1 || $config['port'] > 65535) {
        throw new RuntimeException('Niepoprawna konfiguracja bazy.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['name']
    );

    // Nie przechwytujemy wyjątku do wyświetlenia: obsłuży go wspólny handler.
    $connection = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);

    return $connection;
}
