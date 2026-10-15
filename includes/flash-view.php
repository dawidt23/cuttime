<?php
declare(strict_types=1);
// $messages pochodzi z consume_flash(); stałe komunikaty mogą dodać kontrolery.
foreach ($messages as $message):
    $noticeClass = match ($message['type']) {
        'error' => 'notice-error',
        'success' => 'notice-success',
        default => 'notice-info',
    };
?>
    <div class="notice <?= e($noticeClass) ?>" role="<?= $message['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($message['message']) ?></div>
<?php endforeach; ?>
