<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('http_check')) { exit("Uruchom php tests/http.php.\n"); }
require_once dirname(__DIR__) . '/includes/admin-reservations.php';

function reservation_response_ids(array $response): array
{
    preg_match_all('/data-reservation-id="([0-9]+)"/', $response['body'], $matches);
    return array_map('intval', $matches[1]);
}

function run_reservation_http_tests(PDO $connection, array $sessions): void
{
    $base = '/admin-reservations.php';
    $admin = $sessions['admin'];
    $snapshot = static fn(): array => $connection->query('SELECT * FROM reservations ORDER BY id')->fetchAll();
    $original = $snapshot();
    $token = http_csrf(http_request($admin, $base . '?action=details&id=1'));
    $valid = ['_csrf' => $token, 'status' => 'confirmed', 'expected_status' => 'completed', 'confirm' => '1'];
    $statusPath = $base . '?action=status&id=1';
    foreach (['guest' => [], 'client' => $sessions['client'], 'employee' => $sessions['employee']] as $role => $cookies) {
        foreach (['list','details','status'] as $action) {
            foreach (['GET','POST'] as $method) {
                $response = http_request($cookies,$base.'?action='.$action.'&id=1&role=admin',$method,$method === 'POST' ? $valid+['role'=>'admin'] : null);
                http_check($response['status'] === 303 && $response['body'] === ''
                    && $response['headers']['location'] === ($role === 'guest' ? '/login.php' : '/'.$role.'.php'), 'Reservation authorization: '.$role.' '.$action.' '.$method);
            }
        }
    }
    foreach ([null,'invalid',['array']] as $bad) {
        $response = http_request($admin,$statusPath,'POST',array_replace($valid,['_csrf'=>$bad]));
        http_check($response['status'] === 403 && http_safe($response) && $snapshot() === $original, 'Reservation CSRF rejected');
    }
    foreach (['list','details','status'] as $action) {
        $response = http_request($admin,$base.'?action='.$action.'&id=1','DELETE',$valid);
        http_check($response['status'] === 405 && $snapshot() === $original, 'Unsupported reservation method: '.$action);
        $response = http_request($admin,$base.'?action='.$action.'&id=1');
        http_check($response['status'] === 200 && $snapshot() === $original, 'Reservation GET never changes data: '.$action);
    }
    foreach (['list','details'] as $action) {
        $response = http_request($admin,$base.'?action='.$action.'&id=1','POST',$valid);
        http_check($response['status'] === 405 && $snapshot() === $original, 'Read-only reservation action: '.$action);
    }
    foreach (['create','delete',['details']] as $action) {
        $response = http_request($admin,$base.'?'.http_build_query(['action'=>$action,'id'=>'1']),'POST',$valid);
        http_check($response['status'] === 400 && $snapshot() === $original, 'No reservation creation or deletion');
    }
    foreach (['details','status'] as $action) {
        foreach (['','0','-1','01','1e2','1 OR 1=1','4294967296',['1']] as $bad) {
            $response = http_request($admin,$base.'?'.http_build_query(['action'=>$action,'id'=>$bad]));
            http_check($response['status'] === 400 && http_safe($response), 'Invalid reservation ID: '.$action);
        }
        $response = http_request($admin,$base.'?action='.$action.'&id=4294967295','POST',$valid);
        http_check($response['status'] === ($action === 'details' ? 405 : 404) && $snapshot() === $original, 'Missing reservation safe: '.$action);
    }
    $response = http_request($admin,$base.'?action=details&id=4294967295');
    http_check($response['status'] === 404, 'Missing reservation details');

    $invalidFilters = [
        ['date'=>'2026-02-29'], ['date'=>'2026-04-31'], ['date'=>'2026-00-01'], ['date'=>'2026-01-00'], ['date'=>'2026-13-01'],
        ['date'=>'2026-9-22'], ['date'=>'0000-01-01'], ['date'=>'0999-12-31'], ['date'=>'10000-01-01'], ['date'=>' 2026-09-22'],
        ['date'=>'2026-09-22 00:00'], ['date'=>['2026-09-22']], ['date'=>'<script>alert(1)</script>'], ['date'=>str_repeat('9',1000)],
        ['status'=>'confirmed OR 1=1'], ['status'=>'oczekująca'], ['status'=>'CONFIRMED'], ['status'=>['confirmed']], ['status'=>' '],
        ['employee_id'=>'0'], ['employee_id'=>'01'], ['employee_id'=>'1 OR 1=1'], ['employee_id'=>'4294967295'], ['employee_id'=>'4294967296'], ['employee_id'=>['1']],
        ['page'=>'0'], ['page'=>'01'], ['page'=>'-1'], ['page'=>''], ['page'=>'1e2'], ['page'=>['1']], ['page'=>str_repeat('9',1000)],
    ];
    foreach ($invalidFilters as $bad) {
        foreach (['list','status'] as $action) {
            $response = http_request($admin,$base.'?'.http_build_query($bad+['action'=>$action,'id'=>'1']),$action === 'status' ? 'POST' : 'GET',$action === 'status' ? $valid : null);
            http_check($response['status'] === 400 && http_safe($response) && !str_contains($response['body'],'<script>alert(1)</script>') && $snapshot() === $original, 'Invalid filter prevents query/status change: '.array_key_first($bad).' '.$action);
        }
    }
    foreach (['1000-01-01','2024-02-29','9999-12-31'] as $date) {
        $response = http_request($admin,$base.'?date='.$date);
        http_check($response['status'] === 200 && reservation_response_ids($response) === [], 'Valid date boundary/leap day: '.$date);
    }
    foreach ([
        [[],[8,7,6,5,4,3,2,1]],
        [['date'=>'2026-09-22'],[5,4,3]],
        [['status'=>'pending'],[6,5]],
        [['status'=>'confirmed'],[8,4,3]],
        [['status'=>'completed'],[2,1]],
        [['status'=>'cancelled'],[7]],
        [['employee_id'=>'1'],[7,5,3,1]],
        [['date'=>'2026-09-22','employee_id'=>'1'],[5,3]],
        [['date'=>'2026-09-22','status'=>'confirmed'],[4,3]],
        [['status'=>'confirmed','employee_id'=>'1'],[3]],
        [['date'=>'2026-09-22','status'=>'confirmed','employee_id'=>'1'],[3]],
        [['date'=>'2026-09-22','status'=>'cancelled','employee_id'=>'1'],[]],
        [['date'=>'','status'=>'','employee_id'=>''],[8,7,6,5,4,3,2,1]],
    ] as [$query,$ids]) {
        $response = http_request($admin,$base.'?'.http_build_query($query));
        http_check($response['status'] === 200 && reservation_response_ids($response) === $ids, 'Combined reservation filters: '.http_build_query($query));
    }
    $response = http_request($admin,$base.'?action=details&id=3');
    foreach (['Maria Zielińska','Anna Kowalska','Koloryzacja','2026-09-22','09:00:00','11:00:00','Koloryzacja w naturalnym odcieniu.'] as $expected) {
        http_check(str_contains($response['body'],$expected), 'Reservation details show real joined data: '.$expected);
    }
    // Nieaktywne dane nadal są widoczne w historii i filtrze.
    $connection->exec('UPDATE users SET active=0 WHERE id IN (2,4)');
    $connection->exec('UPDATE employees SET active=0 WHERE id=1');
    $connection->exec('UPDATE services SET active=0 WHERE id=1');
    $response = http_request($admin,$base.'?employee_id=1');
    http_check($response['status'] === 200 && reservation_response_ids($response) === [7,5,3,1] && str_contains($response['body'],'nieaktywny profil lub konto'), 'Inactive accounts/services preserve reservation visibility');
    $response = http_request($admin,$base.'?action=details&id=1');
    http_check($response['status'] === 200 && str_contains($response['body'],'Strzyżenie damskie'), 'Inactive reservation details remain accessible');
    $connection->exec('UPDATE users SET active=1 WHERE id IN (2,4)');
    $connection->exec('UPDATE employees SET active=1 WHERE id=1');
    $connection->exec('UPDATE services SET active=1 WHERE id=1');

    foreach (['status','expected_status'] as $field) {
        foreach ([null,'','pending ',['confirmed'],'admin',str_repeat('x',1000),'<script>alert(1)</script>'] as $bad) {
            $response = http_request($admin,$statusPath,'POST',array_replace($valid,[$field=>$bad]));
            http_check($response['status'] === 422 && http_safe($response) && !str_contains($response['body'],'<script>alert(1)</script>') && $snapshot() === $original, 'Invalid reservation status: '.$field);
        }
    }
    foreach ([null,'0',['1']] as $bad) {
        $response = http_request($admin,$statusPath,'POST',array_replace($valid,['confirm'=>$bad]));
        http_check($response['status'] === 422 && $snapshot() === $original, 'Status change requires confirmation');
    }
    $response = http_request($admin,$statusPath,'POST',$valid+['extra'=>str_repeat('x',33000)]);
    http_check($response['status'] === 413 && $snapshot() === $original, 'Oversized status body rejected');
    $expected = $original;
    $current = 'completed';
    foreach (['pending','confirmed','cancelled','completed','completed'] as $status) {
        $post = array_replace($valid,['status'=>$status,'expected_status'=>$current,'id'=>'2','user_id'=>'1','employee_id'=>'2','service_id'=>'2',
            'reservation_date'=>'2030-01-01','start_time'=>'00:00','end_time'=>'01:00','comment'=>'replacement','role'=>'client','active'=>'0']);
        $response = http_request($admin,$statusPath,'POST',$post);
        $expected[0]['status'] = $status;
        http_check($response['status'] === 303 && $snapshot() === $expected && $response['headers']['location'] === $base.'?action=details&id=1', 'Only status changes to whitelisted value: '.$status);
        $current = $status;
    }
    // Dwa formularze otwarte przy tym samym statusie: drugi nie nadpisuje pierwszego.
    http_request($admin,$statusPath,'POST',$valid);
    $response = http_request($admin,$statusPath,'POST',array_replace($valid,['status'=>'cancelled']));
    http_check($response['status'] === 409 && reservation_find(1,$connection)['status'] === 'confirmed'
        && str_contains($response['body'],'name="expected_status" value="confirmed"'), 'Stale reservation form rejected and refreshed');
    $response = http_request($admin,$statusPath,'POST',array_replace($valid,['status'=>'completed','expected_status'=>'confirmed']));
    http_check($response['status'] === 303 && $snapshot() === $original, 'Refreshed status form succeeds');
    $connection->exec("CREATE TRIGGER test_reservation_update BEFORE UPDATE ON reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected failure'");
    try {
        $response = http_request($admin,$statusPath,'POST',$valid);
        http_check($response['status'] === 500 && http_safe($response) && $snapshot() === $original, 'Reservation database failure does not leak details or change data');
    } finally { $connection->exec('DROP TRIGGER test_reservation_update'); }

    // Duży zbiór: stronicowanie obejmuje wyniki po wszystkich trzech filtrach.
    $insert = $connection->prepare("INSERT INTO reservations (user_id,employee_id,service_id,reservation_date,start_time,end_time,status) VALUES (4,1,1,'2026-11-06','09:00','10:00','pending')");
    $added = [];
    for ($i=0;$i<25;$i++) { $insert->execute(); $added[] = (int) $connection->lastInsertId(); }
    $ordered = array_reverse($added);
    $query = 'date=2026-11-06&status=pending&employee_id=1';
    foreach ([1=>array_slice($ordered,0,20),2=>array_slice($ordered,20),999=>array_slice($ordered,20)] as $page=>$ids) {
        $response = http_request($admin,$base.'?'.$query.'&page='.$page);
        http_check($response['status'] === 200 && reservation_response_ids($response) === $ids && str_contains($response['body'],'Strony rezerwacji'), 'Filtered reservation pagination and stable order: '.$page);
    }
    $response = http_request($admin,$base.'?'.$query);
    http_check(str_contains($response['body'],'href="'.$base.'?date=2026-11-06&amp;status=pending&amp;employee_id=1&amp;page=2"'), 'Pagination preserves all filters');
    $detailPath = $base.'?'.$query.'&page=2&action=details&id='.$added[0];
    $response = http_request($admin,$detailPath);
    http_check(str_contains($response['body'],'href="'.$base.'?date=2026-11-06&amp;status=pending&amp;employee_id=1&amp;page=2"'), 'Details preserve return filters and page');
    $response = http_request($admin,str_replace('action=details','action=status',$detailPath),'POST',array_replace($valid,['expected_status'=>'pending']));
    http_check($response['status'] === 303 && $response['headers']['location'] === $detailPath, 'Status redirect preserves list context even when new status no longer matches filter');
    $delete = $connection->prepare('DELETE FROM reservations WHERE id=:id');
    foreach ($added as $id) { $delete->execute(['id'=>$id]); }

    // Wszystkie dane HTML pochodzące z bazy muszą być escapowane.
    $payload = '<script>alert(1)</script> " &';
    $client = http_user(4); $staff = http_user(2);
    $originalService = $connection->query('SELECT name FROM services WHERE id=1')->fetchColumn();
    $updateUser = $connection->prepare('UPDATE users SET name=:name,surname=:surname,email=:email,phone=:phone WHERE id=:id');
    foreach ([4,2] as $id) { $updateUser->execute(['name'=>$payload,'surname'=>$payload,'email'=>$payload.$id,'phone'=>'<b>phone</b>','id'=>$id]); }
    $updateService = $connection->prepare('UPDATE services SET name=:name WHERE id=1'); $updateService->execute(['name'=>$payload]);
    $comment = $connection->prepare('UPDATE reservations SET comment=:comment WHERE id=1'); $comment->execute(['comment'=>$payload]);
    foreach (['','?action=details&id=1'] as $queryString) {
        $response = http_request($admin,$base.$queryString);
        http_check($response['status'] === 200 && str_contains($response['body'],'&lt;script&gt;alert(1)&lt;/script&gt;')
            && !str_contains($response['body'],'<script>alert(1)</script>') && http_safe($response), 'Reservation stored XSS escaped: '.$queryString);
    }
    foreach ([$client,$staff] as $user) { $updateUser->execute(array_intersect_key($user,array_flip(['name','surname','email','phone','id']))); }
    $updateService->execute(['name'=>$originalService]); $comment->execute(['comment'=>$original[0]['comment']]);
    $connection->exec('RENAME TABLE reservations TO reservations_unavailable');
    try {
        foreach (['','?action=details&id=1'] as $queryString) {
            $response = http_request($admin,$base.$queryString);
            http_check($response['status'] === 500 && http_safe($response) && $response['body'] === 'Wystąpił błąd aplikacji. Spróbuj ponownie później.', 'Reservation read failure is safe');
        }
    } finally { $connection->exec('RENAME TABLE reservations_unavailable TO reservations'); }
    http_check($snapshot() === $original, 'Reservation module tests preserve original history');
    $sql = file_get_contents(dirname(__DIR__).'/database/database.sql');
    preg_match('/status ENUM\((.*?)\)/s',$sql,$match);
    preg_match_all("/'([^']+)'/",$match[1],$values);
    http_check(array_keys(RESERVATION_STATUSES) === $values[1], 'Status allowlist matches actual SQL ENUM');
}
