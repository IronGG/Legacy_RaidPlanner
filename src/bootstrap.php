<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['config'] = require APP_ROOT . '/config.php';

require __DIR__ . '/helpers.php';
require __DIR__ . '/lists.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/rooms.php';
require __DIR__ . '/options.php';
require __DIR__ . '/aion.php';
require __DIR__ . '/view.php';
require __DIR__ . '/room_view.php';

date_default_timezone_set(cfg('timezone'));
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    if (!defined('NO_SESSION')) {
        start_session();
        // Toute requête POST doit porter le jeton CSRF de la session.
        if (is_post()) {
            csrf_check();
        }
    }
}
