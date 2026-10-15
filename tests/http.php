<?php
declare(strict_types=1);

// Pełna aplikacja na osobnej bazie i lokalnym serwerze; nigdy na bazie użytkownika.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('CUTTIME_TEST_DSN');
if ($dsn === false || $dsn === '') {
    echo "SKIP HTTP: set CUTTIME_TEST_DSN, CUTTIME_TEST_USER, CUTTIME_TEST_PASSWORD.\n";
    exit;
}
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/cuttime-http-' . bin2hex(random_bytes(8));
$databaseName = 'cuttime_http_' . bin2hex(random_bytes(8));
$connection = null;
$server = null;
$created = false;
$passed = 0;
$failed = 0;

function http_check(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) { throw new RuntimeException('HTTP check: ' . $name); }
    $passed++;
    echo "PASS {$name}\n";
}

function http_request(array &$cookies, string $path, string $method = 'GET', ?array $data = null): array
{
    global $baseUrl;
    $headers = ['Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(
            static fn($key, $value) => $key . '=' . $value, array_keys($cookies), $cookies
        ));
    }
    if ($data !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    // Fallback dla PHP 8.2; bez odwołania oznaczonego jako deprecated w PHP 8.5.
    $received = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (get_defined_vars()['http_response_header'] ?? null);
    if ($body === false || !$received) { throw new RuntimeException('HTTP connection failed'); }
    preg_match('/\s(\d{3})\s/', $received[0], $status);
    $response = ['status' => (int) $status[1], 'body' => $body, 'headers' => []];
    foreach (array_slice($received, 1) as $line) {
        [$key, $value] = explode(':', $line, 2);
        $response['headers'][strtolower($key)] = trim($value);
        if (strtolower($key) === 'set-cookie') {
            [$name, $cookie] = explode('=', explode(';', trim($value))[0], 2);
            $cookies[$name] = $cookie;
        }
    }
    return $response;
}

function http_csrf(array $response): string
{
    preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $match);
    return $match[1] ?? '';
}

function http_login(array &$cookies, string $email, string $landing): void
{
    $page = http_request($cookies, '/login.php');
    $oldId = $cookies['CUTTIMESESSID'];
    $response = http_request($cookies, '/login.php', 'POST', [
        '_csrf' => http_csrf($page), 'email' => $email, 'password' => 'password',
        'id' => 1, 'role' => 'administrator', 'active' => 1,
    ]);
    http_check($response['status'] === 303 && $response['headers']['location'] === $landing
        && $cookies['CUTTIMESESSID'] !== $oldId, 'Login and session rotation: ' . $landing);
}

function http_user(int $id): array
{
    global $connection;
    $statement = $connection->prepare('SELECT * FROM users WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch();
}

function http_safe(array $response): bool
{
    return !preg_match('/PDOException|SQLSTATE|Stack trace|Fatal error|C:\\\\|\$2y\$/i', $response['body']);
}

function http_copy_directory(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    foreach (new DirectoryIterator($source) as $file) {
        if ($file->isDot()) { continue; }
        if ($file->isDir()) { http_copy_directory($file->getPathname(), $destination . '/' . $file->getFilename()); }
        else { copy($file->getPathname(), $destination . '/' . $file->getFilename()); }
    }
}

try {
    // Nazwa schematu jest generowana lokalnie, a nie pobierana z żądania.
    if (!str_starts_with($dsn, 'mysql:')
        || !preg_match('/(?:^mysql:|;)host=([a-zA-Z0-9.:-]+)(?:;|$)/', $dsn, $host)) {
        throw new RuntimeException('Use a MySQL test DSN with host');
    }
    preg_match('/;port=(\d+)(?:;|$)/', $dsn, $port);
    $configuration = ['host' => $host[1], 'port' => (int) ($port[1] ?? 3306), 'name' => $databaseName,
        'user' => getenv('CUTTIME_TEST_USER') ?: '', 'password' => getenv('CUTTIME_TEST_PASSWORD') ?: ''];
    $connection = new PDO($dsn, $configuration['user'], $configuration['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("CREATE DATABASE $databaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $sql = file_get_contents($root . '/database/database.sql');
    $connection->exec(str_replace(['CREATE DATABASE IF NOT EXISTS cuttime', 'USE cuttime;'],
        ['CREATE DATABASE IF NOT EXISTS ' . $databaseName, 'USE ' . $databaseName . ';'], $sql));
    mkdir($temporary, 0700);
    http_copy_directory($root . '/includes', $temporary . '/includes');
    http_copy_directory($root . '/public', $temporary . '/public');
    mkdir($temporary . '/config', 0700);
    mkdir($temporary . '/sessions', 0700);
    copy($root . '/config/database.php', $temporary . '/config/database.php');
    file_put_contents($temporary . '/config/database.local.php', '<?php return ' . var_export($configuration, true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($socket === false) { throw new RuntimeException('Cannot reserve a test port'); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) { array_push($command, '-c', php_ini_loaded_file()); }
    array_push($command, '-d', 'display_errors=0', '-d', 'display_startup_errors=0',
        '-d', 'opcache.enable=0', '-d', 'session.save_path="' . str_replace('\\', '/', $temporary) . '/sessions"',
        '-d', 'error_log="' . str_replace('\\', '/', $temporary) . '/errors.log"', '-S', $address, '-t', $temporary . '/public');
    $server = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a'],
    ], $pipes);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start test PHP'); }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $ready = @stream_socket_client('tcp://' . $address, $errorNumber, $errorText, 0.1);
        if ($ready) { fclose($ready); break; }
        usleep(100000);
    }

    $protected = ['/client.php' => 'client', '/employee.php' => 'employee', '/admin.php' => 'admin',
        '/account.php' => null, '/profile.php' => null, '/profile-edit.php' => null];
    $guest = [];
    foreach ($protected as $path => $role) {
        foreach (['GET', 'POST'] as $method) {
            $response = http_request($guest, $path . '?id=1&role=admin', $method, $method === 'POST' ? ['id' => 1, 'role' => 'admin'] : null);
            http_check($response['status'] === 303 && $response['headers']['location'] === '/login.php'
                && $response['body'] === '', 'Guest blocked: ' . $method . ' ' . $path);
        }
    }
    $sessions = [];
    foreach (['client' => 'maria.zielinska@cuttime.test', 'employee' => 'anna.kowalska@cuttime.test', 'admin' => 'admin@cuttime.test'] as $role => $email) {
        $sessions[$role] = [];
        http_login($sessions[$role], $email, '/' . $role . '.php');
        // Regresja: cudzy flash sukcesu nie może zastępować formularza rejestracji.
        $page = http_request($sessions[$role], '/register.php');
        http_check(str_contains($page['body'], 'name="password_confirmation"')
            && !str_contains($page['body'], 'Twoje dane zostały zapisane.'), 'Unrelated success does not imply registration');
        foreach ($protected as $path => $allowed) {
            $response = http_request($sessions[$role], $path . '?id=3&role=admin');
            if ($allowed !== null && $allowed !== $role) {
                http_check($response['status'] === 303 && $response['headers']['location'] === '/' . $role . '.php'
                    && $response['body'] === '', $role . ' denied ' . $path);
            } else {
                http_check($response['status'] === 200 && http_safe($response) && str_contains($response['body'], $email)
                    && str_contains($response['headers']['cache-control'], 'no-store')
                    && str_contains($response['headers']['content-security-policy'], "form-action 'self'")
                    && str_contains($response['body'], 'action="/logout.php"'), $role . ' own data and protection: ' . $path);
            }
        }
    }

    $valid = ['name' => 'Łukasz', 'surname' => 'O’Connor-Kowalski', 'email' => 'new@example.test',
        'phone' => '+48 500-100-200', 'password' => 'Bezpieczne hasło 2026', 'password_confirmation' => 'Bezpieczne hasło 2026'];
    $invalid = [
        'empty' => array_fill_keys(array_keys($valid), ''),
        'whitespace' => array_fill_keys(array_keys($valid), " \u{00A0}\u{2003}"),
        'bad email' => ['email' => 'not-email'],
        'duplicate email' => ['email' => 'ADMIN@CUTTIME.TEST'],
        'long name' => ['name' => str_repeat('a', 201)],
        'long email' => ['email' => str_repeat('a', 255)],
        'HTML' => ['name' => '<b>Anna</b>'],
        'JavaScript' => ['surname' => '<script>alert(1)</script>'],
        'attribute injection' => ['email' => '" autofocus onfocus="alert(1)'],
        'array' => ['phone' => ['unexpected']],
        'NUL' => ['name' => "Anna\0"],
    ];
    foreach (['/register.php', '/profile-edit.php'] as $path) {
        $cookies = $path === '/register.php' ? [] : $sessions['client'];
        $token = http_csrf(http_request($cookies, $path));
        $before = $connection->query('SELECT * FROM users ORDER BY id')->fetchAll();
        $cases = $invalid;
        if ($path === '/register.php') { $cases['password mismatch'] = ['password_confirmation' => 'Inne hasło 2026']; }
        foreach ($cases as $name => $bad) {
            $response = http_request($cookies, $path, 'POST', array_replace($valid, $bad, ['_csrf' => $token]));
            http_check($response['status'] === 422 && http_safe($response)
                && !str_contains($response['body'], '<script>alert(1)</script>')
                && !str_contains($response['body'], 'value="Bezpieczne hasło 2026"')
                && $before === $connection->query('SELECT * FROM users ORDER BY id')->fetchAll(),
                'No writes, safe validation: ' . $path . ' ' . $name);
        }
        foreach ([null, 'invalid', ['array']] as $badToken) {
            $response = http_request($cookies, $path, 'POST', array_replace($valid, ['_csrf' => $badToken]));
            http_check($response['status'] === 403, 'CSRF rejected: ' . $path);
        }
        $response = http_request($cookies, $path, 'POST', array_replace($valid, ['name' => str_repeat('x', 20000), '_csrf' => $token]));
        http_check($response['status'] === 413 && strlen($response['body']) < 20000, 'Body size limit: ' . $path);
    }
    $cookies = [];
    $token = http_csrf(http_request($cookies, '/login.php'));
    foreach ([[], ['email' => 'invalid', 'password' => 'password'], ['email' => 'unknown@example.test', 'password' => 'password'],
        ['email' => 'admin@cuttime.test', 'password' => 'wrong']] as $bad) {
        $response = http_request($cookies, '/login.php', 'POST', $bad + ['_csrf' => $token]);
        http_check($response['status'] === 422 && http_safe($response), 'Invalid login blocked');
    }
    $connection->exec('UPDATE users SET active = 0 WHERE id = 3');
    $response = http_request($cookies, '/login.php', 'POST', ['email' => 'piotr.wisniewski@cuttime.test', 'password' => 'password', '_csrf' => $token]);
    http_check($response['status'] === 422 && str_contains($response['body'], 'Nieprawidłowy adres e-mail lub hasło.'), 'Inactive login uses generic error');
    $connection->exec('UPDATE users SET active = 1 WHERE id = 3');

    $cookies = [];
    $token = http_csrf(http_request($cookies, '/register.php'));
    $response = http_request($cookies, '/register.php', 'POST', $valid + ['_csrf' => $token, 'role' => 'administrator', 'active' => 0, 'id' => 1]);
    http_check($response['status'] === 303 && $response['headers']['location'] === '/login.php', 'Registration redirects to login');
    $new = $connection->query('SELECT * FROM users ORDER BY id DESC LIMIT 1')->fetch();
    http_check($new['role'] === 'client' && (int) $new['active'] === 1 && password_verify($valid['password'], $new['password']), 'Registration hashes password and ignores forged role/active');
    $response = http_request($cookies, '/login.php');
    http_check(str_contains($response['body'], 'Konto zostało utworzone.') && !str_contains(http_request($cookies, '/login.php')['body'], 'Konto zostało utworzone.'), 'Registration success is one-time');

    $cookies = $sessions['client'];
    $before = http_user(4);
    $other = http_user(1);
    $token = http_csrf(http_request($cookies, '/profile-edit.php'));
    $response = http_request($cookies, '/profile-edit.php?id=1', 'POST', array_replace($valid, [
        'email' => $before['email'], '_csrf' => $token, 'id' => 1, 'user_id' => 1, 'role' => 'administrator', 'active' => 1,
    ]));
    $after = http_user(4);
    http_check($response['status'] === 303 && $response['headers']['location'] === '/profile.php' && $after['name'] === $valid['name']
        && $after['email'] === $before['email'] && http_user(1) === $other, 'Own email accepted; forged ID cannot edit another account');
    foreach (['id', 'role', 'active', 'password', 'created_at'] as $field) {
        http_check($before[$field] === $after[$field], 'Profile protected field: ' . $field);
    }
    $statement = $connection->prepare('UPDATE users SET name = :name WHERE id = :id');
    $statement->execute(['name' => '<script>alert(1)</script>', 'id' => 4]);
    foreach (['/client.php', '/account.php', '/profile.php', '/profile-edit.php'] as $path) {
        $response = http_request($cookies, $path);
        http_check(str_contains($response['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
            && !str_contains($response['body'], '<script>alert(1)</script>'), 'Stored XSS escaped: ' . $path);
    }
    $statement->execute(['name' => $after['name'], 'id' => 4]);
    foreach ($sessions as $role => $cookies) {
        $page = http_request($cookies, '/profile.php');
        $oldCookies = $cookies;
        $response = http_request($cookies, '/logout.php', 'POST', ['_csrf' => http_csrf($page)]);
        http_check($response['status'] === 303 && str_contains($response['headers']['set-cookie'], 'Max-Age=0'), 'Logout clears cookie: ' . $role);
        foreach ($protected as $path => $allowed) {
            $response = http_request($oldCookies, $path);
            http_check($response['status'] === 303 && $response['headers']['location'] === '/login.php'
                && $response['body'] === '', 'Old session blocked: ' . $role . ' ' . $path);
        }
    }
    // Zniknięcie konfiguracji w kopii aplikacji wymusza błąd bez zmiany prawdziwych ustawień.
    $cookies = [];
    http_login($cookies, 'admin@cuttime.test', '/admin.php');
    unlink($temporary . '/config/database.local.php');
    foreach ($protected as $path => $allowed) {
        $response = http_request($cookies, $path);
        http_check($response['status'] === 500 && http_safe($response)
            && $response['body'] === 'Wystąpił błąd aplikacji. Spróbuj ponownie później.', 'Safe configuration failure: ' . $path);
    }
} catch (Throwable $error) {
    $failed++;
    // Szczegóły tylko własnych asercji; wyjątek PDO może zawierać sekrety.
    echo 'FAIL ' . ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'HTTP check:')
        ? $error->getMessage() : get_class($error)) . "\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created && $connection !== null && preg_match('/\Acuttime_http_[a-f0-9]{16}\z/', $databaseName)) {
        $connection->exec('DROP DATABASE ' . $databaseName); // Wyłącznie własny, losowy schemat tego uruchomienia.
    }
    if (is_dir($temporary)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
}
echo "HTTP results: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
