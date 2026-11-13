<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/booking-save.php';
$schema = $argv[1] ?? '';
if (!preg_match('/\Acuttime_http_[a-f0-9]{16}\z/', $schema)) { exit(2); }
$connection = new PDO(getenv('CUTTIME_TEST_DSN'), getenv('CUTTIME_TEST_USER') ?: '', getenv('CUTTIME_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$connection->exec('USE ' . $schema);
echo "READY\n";
flush();
$input = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(booking_save((int) $argv[3], $input, $connection), JSON_THROW_ON_ERROR);
