<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/admin-employee-services.php';
require_once dirname(__DIR__) . '/includes/admin-availability.php';

// Drugi proces PHP do testu równoczesnych zapisów. Wyłącznie schemat własnego testu.
if (($argv[1] ?? '') === 'availability-worker') {
    $schema = $argv[2] ?? '';
    $outputFile = $argv[3] ?? '';
    if (!preg_match('/\Acuttime_http_[a-f0-9]{16}\z/', $schema)
        || !preg_match('/\Aavailability-worker-[01]\.txt\z/', basename($outputFile))
        || !preg_match('/\Acuttime-http-[a-f0-9]{16}\z/', basename(dirname($outputFile)))) { exit(1); }
    try {
        $db = new PDO(getenv('CUTTIME_TEST_DSN'), getenv('CUTTIME_TEST_USER') ?: '', getenv('CUTTIME_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec('USE ' . $schema);
        file_put_contents($outputFile, "READY\n");
        file_put_contents($outputFile, json_encode(availability_save(1, ['day_of_week' => '7', 'start_time' => '19:00', 'end_time' => '20:00'], null, $db)), FILE_APPEND);
        exit;
    } catch (Throwable $error) { echo 'FAIL ' . get_class($error); exit(1); }
}
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }

function run_employee_relations_http_tests(PDO $connection, array $sessions): void
{
    global $temporary;
    $admin = $sessions['admin'];
    $services = '/admin-employee-services.php';
    $availability = '/admin-availability.php';
    $links = static fn(): array => $connection->query('SELECT * FROM employee_services ORDER BY employee_id, service_id')->fetchAll();
    $hours = static fn(): array => $connection->query('SELECT * FROM employee_availability ORDER BY id')->fetchAll();
    $originalLinks = $links();
    $originalHours = $hours();
    $preserved = static fn(): array => [
        $connection->query('SELECT * FROM users ORDER BY id')->fetchAll(),
        $connection->query('SELECT * FROM employees ORDER BY id')->fetchAll(),
        $connection->query('SELECT * FROM services ORDER BY id')->fetchAll(),
        $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll(),
    ];
    $history = $preserved();
    $token = http_csrf(http_request($admin, $services . '?employee_id=1'));
    $selection = ['_csrf' => $token, 'service_ids' => ['1','2'], 'confirm' => '1', '_complete' => '1'];
    $valid = ['_csrf' => $token, 'day_of_week' => '6', 'start_time' => '09:00', 'end_time' => '12:00'];
    $routes = [$services . '?action=edit&employee_id=1', $availability . '?action=create&employee_id=1',
        $availability . '?action=edit&employee_id=1&id=1', $availability . '?action=delete&employee_id=1&id=1'];
    foreach (['guest' => [], 'client' => $sessions['client'], 'employee' => $sessions['employee']] as $role => $cookies) {
        foreach ($routes as $route) {
            foreach (['GET','POST'] as $method) {
                $response = http_request($cookies, $route . '&role=admin', $method, $method === 'POST' ? $selection + $valid + ['role' => 'admin'] : null);
                http_check($response['status'] === 303 && $response['body'] === ''
                    && $response['headers']['location'] === ($role === 'guest' ? '/login.php' : '/' . $role . '.php'), 'Relation authorization: ' . $role . ' ' . $method . ' ' . $route);
            }
        }
    }
    foreach ($routes as $route) {
        foreach ([null,'wrong',['array']] as $csrf) {
            $response = http_request($admin, $route, 'POST', array_replace($selection + $valid, ['_csrf' => $csrf]));
            http_check($response['status'] === 403 && http_safe($response) && $links() === $originalLinks && $hours() === $originalHours, 'Relation CSRF: ' . $route);
        }
        foreach (['PUT','DELETE'] as $method) {
            $response = http_request($admin, $route, $method, $selection + $valid);
            http_check($response['status'] === 405 && http_safe($response), 'Relation unsupported method: ' . $route);
        }
        $response = http_request($admin, $route, 'POST', $selection + $valid + ['extra' => str_repeat('x',33000)]);
        http_check($response['status'] === 413 && $links() === $originalLinks && $hours() === $originalHours, 'Relation oversized body: ' . $route);
    }
    foreach ([$services, $availability] as $base) {
        foreach (['0','01','-1','1e2','1 OR 1=1','4294967296',['nested']] as $badId) {
            $response = http_request($admin, $base . '?' . http_build_query(['employee_id' => $badId]));
            http_check($response['status'] === 400 && http_safe($response), 'Invalid relation employee ID');
        }
        $response = http_request($admin, $base . '?employee_id=4294967295');
        http_check($response['status'] === 404 && http_safe($response), 'Missing relation employee');
        foreach (['?action[]=list','?page=0','?page[]=1','?action=edit'] as $query) {
            $response = http_request($admin, $base . $query);
            http_check($response['status'] === 400 && http_safe($response), 'Invalid relation route: ' . $base . $query);
        }
        $response = http_request($admin, $base . '?employee_id=1', 'POST', $selection + $valid);
        http_check($response['status'] === 405, 'Relation list cannot mutate');
    }
    $saveServices = $services . '?action=edit&employee_id=1';
    foreach (['1', ['0'], ['-1'], ['01'], ['1 OR 1=1'], ['4294967295'], [['nested']], ['bad' => '1'], array_fill(0,501,'1')] as $ids) {
        $response = http_request($admin, $saveServices, 'POST', array_replace($selection, ['service_ids' => $ids]));
        http_check($response['status'] === 422 && http_safe($response) && $links() === $originalLinks, 'Invalid service selection rejected atomically');
    }
    foreach (['confirm','_complete'] as $field) {
        foreach ([null, '0', ['1']] as $bad) {
            $response = http_request($admin, $saveServices, 'POST', array_replace($selection, [$field => $bad]));
            http_check($response['status'] === 422 && $links() === $originalLinks, 'Selection requires confirmation and complete form: ' . $field);
        }
    }
    foreach ([['1','1','2'], ['2'], [], ['1','3','4','6']] as $ids) {
        $response = http_request($admin, $saveServices, 'POST', array_replace($selection, ['service_ids' => $ids, 'employee_id' => '2']));
        $actual = array_column(array_filter($links(), static fn(array $link): bool => (int) $link['employee_id'] === 1), 'service_id');
        $expected = array_values(array_unique(array_map('intval', $ids))); sort($expected);
        http_check($response['status'] === 303 && array_map('intval', $actual) === $expected && $preserved() === $history
            && array_values(array_filter($links(), static fn(array $link): bool => (int) $link['employee_id'] === 2)) === array_values(array_filter($originalLinks, static fn(array $link): bool => (int) $link['employee_id'] === 2)), 'Synchronize exact selection, deduplicate, clear and ignore posted employee ID');
    }
    $before = $links();
    $connection->exec("CREATE TRIGGER test_assignment_insert BEFORE INSERT ON employee_services FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected failure'");
    try {
        $response = http_request($admin, $saveServices, 'POST', $selection);
        http_check($response['status'] === 500 && http_safe($response) && $links() === $before, 'Assignment rollback restores removed links if insertion fails');
    } finally { $connection->exec('DROP TRIGGER test_assignment_insert'); }
    // Nieaktywne dane są jawnie oznaczone i nie są aktywowane przez przypisanie.
    $connection->exec('UPDATE services SET active=0 WHERE id=1');
    $connection->exec('UPDATE service_categories SET active=0 WHERE id=1');
    $connection->exec('UPDATE employees SET active=0 WHERE id=1');
    $response = http_request($admin, $services . '?employee_id=1');
    http_check(str_contains($response['body'], 'Usługa nieaktywna') && str_contains($response['body'], 'Kategoria nieaktywna') && str_contains($response['body'], 'Profil lub konto jest nieaktywne.'), 'Inactive assignment options labelled');
    $response = http_request($admin, $saveServices, 'POST', $selection);
    http_check($response['status'] === 303 && (int) employee_find(1, $connection)['active'] === 0, 'Assigning inactive service never activates employee');
    $connection->exec('UPDATE services SET active=1 WHERE id=1');
    $connection->exec('UPDATE service_categories SET active=1 WHERE id=1');
    $connection->exec('UPDATE employees SET active=1 WHERE id=1');

    $create = $availability . '?action=create&employee_id=1';
    $badHours = [[], ['day_of_week'=>'0'], ['day_of_week'=>'8'], ['day_of_week'=>'01'], ['day_of_week'=>['1']],
        ['day_of_week'=>'1 OR 1=1'], ['start_time'=>''], ['start_time'=>'9:00'], ['start_time'=>'24:00'], ['end_time'=>'24:00:00'],
        ['start_time'=>'09:60'], ['end_time'=>'12:00:60'], ['start_time'=>'-01:00'], ['start_time'=>' 09:00'], ['start_time'=>'2026-11-05T09:00'],
        ['start_time'=>['09:00']], ['start_time'=>str_repeat('1',1000)], ['start_time'=>'<script>alert(1)</script>'],
        ['start_time'=>'12:00'], ['start_time'=>'13:00'], ['start_time'=>'22:00','end_time'=>'06:00']];
    foreach ([$create, $availability . '?action=edit&employee_id=1&id=1'] as $route) {
        foreach ($badHours as $bad) {
            $post = $bad === [] ? ['_csrf'=>$token] : array_replace($valid,$bad);
            $response = http_request($admin,$route,'POST',$post);
            http_check($response['status'] === 422 && http_safe($response) && $hours() === $originalHours
                && !str_contains($response['body'],'<script>alert(1)</script>'), 'Availability invalid input: ' . $route);
        }
    }
    foreach (['edit','delete'] as $action) {
        foreach (['0','01','-1',['bad']] as $badId) {
            $response = http_request($admin,$availability . '?' . http_build_query(['action'=>$action,'employee_id'=>'1','id'=>$badId]));
            http_check($response['status'] === 400, 'Invalid availability row ID');
        }
        foreach (['4294967295','6'] as $otherId) {
            $response = http_request($admin,$availability . '?action='.$action.'&employee_id=1&id='.$otherId,'POST',$valid+['confirm'=>'1']);
            http_check($response['status'] === 404 && $hours() === $originalHours, 'Availability row must belong to selected employee');
        }
    }
    // Pełne pokrycie, częściowe nakładanie i identyczne wpisy są konfliktami.
    foreach ([['09:00','17:00'],['08:00','10:00'],['16:00','18:00'],['10:00','11:00'],['08:00','18:00']] as [$start,$end]) {
        $response = http_request($admin,$create,'POST',array_replace($valid,['day_of_week'=>'1','start_time'=>$start,'end_time'=>$end]));
        http_check($response['status'] === 409 && $hours() === $originalHours, 'Availability overlap blocked: '.$start.'-'.$end);
    }
    $response = http_request($admin,$availability.'?action=edit&employee_id=1&id=1','POST',array_replace($valid,['day_of_week'=>'1','start_time'=>'09:00','end_time'=>'17:00']));
    http_check($response['status'] === 303 && $hours() === $originalHours, 'Unchanged availability does not conflict with itself');
    foreach ([['1','08:00','09:00'],['1','17:00','18:00'],['6','09:00:01','12:00:02'],['7','00:00','00:00:01'],['7','23:59:58','23:59:59']] as [$day,$start,$end]) {
        $response = http_request($admin,$create,'POST',array_replace($valid,['day_of_week'=>$day,'start_time'=>$start,'end_time'=>$end,'employee_id'=>'2','id'=>'6']));
        http_check($response['status'] === 303 && $preserved() === $history, 'Adjacent and second precision availability accepted: '.$day.' '.$start);
    }
    $newId = (int) $connection->query('SELECT id FROM employee_availability WHERE employee_id=1 AND day_of_week=6')->fetchColumn();
    $response = http_request($admin,$availability.'?action=edit&employee_id=1&id='.$newId,'POST',array_replace($valid,['start_time'=>'13:00','end_time'=>'14:00','employee_id'=>'2','id'=>'6']));
    http_check($response['status'] === 303 && availability_find(1,$newId,$connection)['start_time'] === '13:00:00' && $preserved() === $history, 'Editing availability changes only scoped record');
    $before = $hours();
    $response = http_request($admin,$availability.'?action=edit&employee_id=1&id='.$newId,'POST',array_replace($valid,['day_of_week'=>'1']));
    http_check($response['status'] === 409 && $hours() === $before, 'Moving availability to conflicting weekday blocked');
    $delete = $availability.'?action=delete&employee_id=1&id='.$newId;
    $response = http_request($admin,$delete);
    http_check($response['status'] === 200 && str_contains($response['body'],'Potwierdzam usunięcie') && $hours() === $before, 'GET deletion only confirms');
    foreach ([null,'0',['1']] as $confirm) {
        $response = http_request($admin,$delete,'POST',['_csrf'=>$token,'confirm'=>$confirm]);
        http_check($response['status'] === 422 && $hours() === $before, 'Availability deletion needs confirmation');
    }
    $response = http_request($admin,$delete,'POST',['_csrf'=>$token,'confirm'=>'1','employee_id'=>'2','id'=>'6']);
    http_check($response['status'] === 303 && availability_find(1,$newId,$connection) === null && $preserved() === $history, 'Confirmed deletion removes availability only');

    // Dwa procesy konkurują o ten sam pusty przedział po zwolnieniu blokady rodzica.
    $schema = (string) $connection->query('SELECT DATABASE()')->fetchColumn();
    $workers = [];
    $connection->beginTransaction();
    employee_find(1,$connection,true);
    try {
        for ($i=0;$i<2;$i++) {
            $command = [PHP_BINARY];
            if (php_ini_loaded_file()) { array_push($command,'-c',php_ini_loaded_file()); }
            $outputFile = $temporary . '/availability-worker-' . $i . '.txt';
            array_push($command,__FILE__,'availability-worker',$schema,$outputFile);
            $process = proc_open($command,[0=>['pipe','r'],1=>['file',$temporary.'/worker-output.log','a'],2=>['file',$temporary.'/worker-errors.log','a']],$pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrent test'); }
            fclose($pipes[0]);
            $workers[] = ['process'=>$process,'file'=>$outputFile,'text'=>''];
        }
        $deadline = microtime(true)+5;
        do {
            foreach ($workers as &$worker) { $worker['text'] = is_file($worker['file']) ? file_get_contents($worker['file']) : ''; } unset($worker);
            $ready = count(array_filter($workers,static fn(array $worker): bool => str_contains($worker['text'],'READY')));
            if ($ready === 2) { break; }
            usleep(20000);
        } while (microtime(true)<$deadline);
        http_check($ready === 2 && !str_contains(implode('',array_column($workers,'text')),'success'), 'Concurrent writers wait for employee lock');
        $connection->commit();
        $deadline = microtime(true)+10;
        do {
            $running = false;
            foreach ($workers as &$worker) {
                $running = proc_get_status($worker['process'])['running'] || $running;
                $worker['text'] = file_get_contents($worker['file']);
            } unset($worker);
            if (!$running) { break; }
            usleep(20000);
        } while (microtime(true)<$deadline);
        http_check(!$running, 'Concurrent availability saves finish');
        $results = array_map(static fn(array $worker): ?array => json_decode(trim(str_replace('READY','',$worker['text'])),true),$workers);
        http_check(count(array_filter($results,static fn(?array $result): bool => ($result['success'] ?? false) === true)) === 1
            && count(array_filter($results,static fn(?array $result): bool => ($result['status'] ?? 0) === 409)) === 1, 'Concurrent overlap has exactly one winner');
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        foreach ($workers as $worker) {
            proc_terminate($worker['process']); proc_close($worker['process']);
        }
    }
    $payload = '<script>alert(1)</script>';
    $setName = $connection->prepare('UPDATE users SET name=:name WHERE id=2');
    $setService = $connection->prepare('UPDATE services SET name=:name WHERE id=1');
    $setName->execute(['name'=>$payload]); $setService->execute(['name'=>$payload]);
    foreach ([$services,$services.'?employee_id=1',$availability,$availability.'?employee_id=1',$availability.'?action=create&employee_id=1'] as $route) {
        $response = http_request($admin,$route);
        http_check($response['status'] === 200 && str_contains($response['body'],'&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($response['body'],$payload), 'Relation XSS escaping: '.$route);
    }
    $setName->execute(['name'=>$history[0][1]['name']]); $setService->execute(['name'=>$history[2][0]['name']]);
    $connection->exec("UPDATE users SET role='client' WHERE id=2");
    foreach ($routes as $route) {
        $response = http_request($admin,$route,'POST',$selection+$valid);
        http_check($response['status'] === 409 && http_safe($response), 'Relation wrong account role rejected');
    }
    $connection->exec("UPDATE users SET role='employee' WHERE id=2");
    // Przywrócenie własnych testowych zmian przed dalszymi testami dashboardu.
    $connection->exec('DELETE FROM employee_services');
    $insert = $connection->prepare('INSERT INTO employee_services (employee_id,service_id) VALUES (:employee_id,:service_id)');
    foreach ($originalLinks as $row) { $insert->execute($row); }
    $connection->exec('DELETE FROM employee_availability');
    $insert = $connection->prepare('INSERT INTO employee_availability (id,employee_id,day_of_week,start_time,end_time) VALUES (:id,:employee_id,:day_of_week,:start_time,:end_time)');
    foreach ($originalHours as $row) { $insert->execute($row); }
    http_check($links() === $originalLinks && $hours() === $originalHours && $preserved() === $history, 'Relations preserve original records and reservation history');
}
