<?php
/**
 * AutoShop Management System — Configuration
 * --------------------------------------------------------------
 * Switch DB_DRIVER to 'mysql' for production (see database/schema_mysql.sql).
 * For an instant local run, the default 'sqlite' driver auto-creates the
 * database file under /storage and needs zero setup.
 */

// Load .env into environment variables if present
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}


return [
    'app_name'   => "Paddy's Auto Tech",
    'app_tag'    => 'Management System',

    // Show detailed errors in the browser during local development.
    // Set to false in production.
    'debug'      => false,

    // 'sqlite' (zero-config, default) or 'mysql' (production)
    'db_driver'  => getenv('DB_DRIVER') ?: 'mysql',

    // SQLite settings
    'sqlite_path' => __DIR__ . '/storage/autoshop.sqlite',

    // MySQL settings (used when db_driver = mysql)
    'mysql' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'autoshop',
        'user'    => getenv('DB_USER') ?: 'autoshop_user',
        'pass'    => getenv('DB_PASS') ?: 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    // Ghana VAT — Value Added Tax Act, 2025 (Act 1151), effective 1 January 2026.
    // VAT, NHIL and GETFund are each charged on the same taxable value (labour + parts),
    // for a combined effective rate of 20%. Update here if GRA revises the rates.
    'tax' => [
        'vat_rate'     => 15.0,
        'nhil_rate'    => 2.5,
        'getfund_rate' => 2.5,
    ],

  // Appointment booking rules
    'appointments' => [
        'max_per_slot'          => 2,
        'slot_window_minutes'   => 60,
        'mark_done_after_hours' => 2,   // staff can't mark an appointment done until this many hours after its scheduled time
    ],

// mNotify SMS (Ghana)
    'mnotify' => [
        'api_key'   => getenv('MNOTIFY_API_KEY') ?: 'CHANGE_ME',
        'sender_id' => getenv('MNOTIFY_SENDER_ID') ?: 'Paddys Auto',
    ],
    // Outgoing email (uses PHP's built-in mail(); configure sendmail/SMTP in php.ini on the server)
    'mail_from' => getenv('MAIL_FROM') ?: '0322080317@htu.edu.gh',

    // Security
    'session_name'      => 'autoshop_sid',
    'session_idle'      => 60 * 30,   // 30 min idle timeout
    'login_max_tries'   => 5,         // lock after N failed attempts
    'login_lock_window' => 60 * 15,   // ...within 15 minutes
    'password_min'      => 8,

    // Portal / SMS links
    'app_url' => getenv('APP_URL') ?: 'http://192.168.100.21',
];
