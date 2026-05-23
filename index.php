<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (is_logged_in()) {
    header('Location: settings.php');
    exit;
}

header('Location: login.php');
exit;hp');
exit;