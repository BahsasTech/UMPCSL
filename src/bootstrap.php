<?php
declare(strict_types=1);

/**
 * Application bootstrap: configuration, session, database and helpers.
 * Every public page requires this file.
 */

const APP_ROOT = __DIR__ . '/..';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Africa/Lagos');

if (!file_exists(APP_ROOT . '/config/config.php')) {
    http_response_code(500);
    exit('Missing config/config.php. Copy config/config.example.php and update your database credentials.');
}

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/members.php';
require_once APP_ROOT . '/src/savings.php';
require_once APP_ROOT . '/src/loans.php';
require_once APP_ROOT . '/src/payments.php';
require_once APP_ROOT . '/src/reports.php';
require_once APP_ROOT . '/src/notifications.php';
require_once APP_ROOT . '/src/accounting.php';
require_once APP_ROOT . '/src/store.php';
require_once APP_ROOT . '/src/import.php';
require_once APP_ROOT . '/src/gateway.php';

date_default_timezone_set((string) config('app.timezone', 'Africa/Lagos'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('umpcsl_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
