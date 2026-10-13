<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_roles(['client', 'employee', 'admin']);
$pageTitle = 'Twoje konto';
$pageDescription = 'Twoje konto jest aktywne. Możesz przejść do swojej części serwisu lub się wylogować.';
require dirname(__DIR__) . '/includes/account-view.php';
