<?php declare(strict_types=1); ?>
<?php if ($errors !== []): ?>
    <div class="notice notice-error" role="alert" tabindex="-1" autofocus>
        <strong>Nie udało się wykonać operacji.</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
