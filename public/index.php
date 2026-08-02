<?php
/**
 * Front controller — the single entry point.
 * Web server document root must point here (public/).
 */

require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/router.php';
require __DIR__ . '/../app/controllers/customer_portal.php';


/* ---- show readable errors during local development (config 'debug') ---- */
if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/* ---- base path: '' at web root, or e.g. /myfolder/public in a subfolder ---- */
if (PHP_SAPI === 'cli-server') {
    define('BASE', '');
} else {
    $b = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    define('BASE', ($b === '' || $b === '/' || $b === '.') ? '' : $b);
}

/* ---- security response headers ---- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'");
header('X-XSS-Protection: 0');

start_session();

/* ---- CSRF guard on every POST ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_verify();

/* ---- load controllers ---- */
foreach ([
    'auth', 'dashboard', 'customers', 'vehicles', 'jobcards',
    'inventory', 'services', 'invoices', 'appointments', 'users', 'audit',
] as $c) {
    require dirname(__DIR__) . "/app/controllers/{$c}.php";
}

$r = new Router();

/* auth */
$r->get('/login',  'show_login');
$r->post('/login', 'do_login');
$r->get('/logout', 'do_logout');

/* dashboard + reports */
$r->get('/',        'dashboard');
$r->get('/reports', 'reports');

/* customers */
$r->get('/customers',            'customers_index');
$r->get('/customers/new',        'customers_form');
$r->post('/customers',           'customers_store');
$r->get('/customers/{id}',       'customers_show');
$r->get('/customers/{id}/edit',  'customers_form');
$r->post('/customers/{id}',      'customers_update');
$r->post('/customers/{id}/delete','customers_delete');
$r->post('/customers/{id}/email', 'customers_email');
$r->post('/customers/{id}/sms', 'customers_sms');
$r->post('/customers/{id}/activate-portal', 'customers_activate_portal');
$r->get('/customer/login', 'customer_login_form');
$r->post('/customer/login', 'customer_login_post');
$r->post('/customer/logout', 'customer_logout_action');
$r->get('/customer', 'customer_dashboard');
$r->post('/customer/jobcards/{id}/decision', 'customer_job_decision');
$r->get('/customer/jobs', 'customer_jobs');
$r->get('/customer/invoices', 'customer_invoices');
$r->get('/customer/report-fault', 'customer_report_fault_form');
$r->post('/customer/report-fault', 'customer_report_fault_store');
$r->get('/customers/{id}/messages/poll', 'customers_messages_poll');
$r->post('/customers/{id}/messages/send', 'customers_messages_send');

/* customer portal */
$r->get('/customer/login', 'customer_login_form');
$r->post('/customer/login', 'customer_login_post');
$r->post('/customer/logout', 'customer_logout_action');
$r->get('/customer', 'customer_dashboard');
$r->post('/customer/jobcards/{id}/decision', 'customer_job_decision');
$r->get('/customer/appointments', 'customer_appointments');
$r->get('/customer/appointments/new', 'customer_appointment_form');
$r->post('/customer/appointments', 'customer_appointment_store');
$r->get('/customer/messages', 'customer_messages');
$r->get('/customer/messages/poll', 'customer_messages_poll');
$r->post('/customer/messages/send', 'customer_messages_send');

/* vehicles */
$r->get('/vehicles',             'vehicles_index');
$r->get('/vehicles/new',         'vehicles_form');
$r->post('/vehicles',            'vehicles_store');
$r->get('/vehicles/{id}/edit',   'vehicles_form');
$r->post('/vehicles/{id}',       'vehicles_update');
$r->post('/vehicles/{id}/delete','vehicles_delete');

/* job cards */
$r->get('/jobcards',                'jobcards_index');
$r->get('/jobcards/new',            'jobcards_form');
$r->post('/jobcards',               'jobcards_store');
$r->get('/jobcards/{id}',           'jobcards_show');
$r->get('/jobcards/{id}/edit',      'jobcards_edit');
$r->post('/jobcards/{id}',          'jobcards_update');
$r->post('/jobcards/{id}/status',   'jobcards_status');

$r->post('/jobcards/{id}/fault',                 'jobcards_add_fault');
$r->get('/jobcards/{id}/fault/{fid}/edit',       'jobcards_edit_fault');
$r->post('/jobcards/{id}/fault/{fid}',           'jobcards_update_fault');
$r->post('/jobcards/{id}/fault/{fid}/delete',    'jobcards_delete_fault');

$r->post('/jobcards/{id}/service',               'jobcards_add_service');
$r->get('/jobcards/{id}/service/{sid}/edit',     'jobcards_edit_service');
$r->post('/jobcards/{id}/service/{sid}',         'jobcards_update_service');
$r->post('/jobcards/{id}/service/{sid}/delete',  'jobcards_delete_service');

$r->post('/jobcards/{id}/part',                  'jobcards_add_part');
$r->get('/jobcards/{id}/part/{pid}/edit',        'jobcards_edit_part');
$r->post('/jobcards/{id}/part/{pid}',            'jobcards_update_part');
$r->post('/jobcards/{id}/part/{pid}/delete',     'jobcards_delete_part');

$r->post('/jobcards/{id}/invoice',  'jobcards_invoice');

/* inventory (spare parts) */
$r->get('/inventory',             'inventory_index');
$r->get('/inventory/new',         'inventory_form');
$r->post('/inventory',            'inventory_store');
$r->get('/inventory/{id}/edit',   'inventory_form');
$r->post('/inventory/{id}',       'inventory_update');

/* services */
$r->get('/services',           'services_index');
$r->get('/services/new',       'services_form');
$r->post('/services',          'services_store');
$r->get('/services/{id}/edit', 'services_form');
$r->post('/services/{id}',     'services_update');
$r->post('/services/{id}/delete', 'services_delete');

/* invoices + payments */
$r->get('/invoices',             'invoices_index');
$r->get('/invoices/{id}',        'invoices_show');
$r->post('/invoices/{id}/pay',   'invoices_pay');

/* appointments */
$r->get('/appointments',      'appointments_index');
$r->get('/appointments/new',  'appointments_form');
$r->post('/appointments',     'appointments_store');
$r->post('/appointments/{id}/status', 'appointments_status');
$r->post('/appointments/{id}/approve', 'appointments_approve');
$r->post('/appointments/{id}/decline', 'appointments_decline');

/* users (admin) */
$r->get('/users',            'users_index');
$r->get('/users/new',        'users_form');
$r->post('/users',           'users_store');
$r->get('/users/{id}/edit',  'users_form');
$r->post('/users/{id}',      'users_update');
$r->post('/users/{id}/toggle','users_toggle');

/* audit */
$r->get('/audit', 'audit_index');

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $ex) {
    http_response_code(500);
    if (config('debug')) {
        $msg = htmlspecialchars($ex->getMessage(), ENT_QUOTES);
        $where = htmlspecialchars($ex->getFile() . ':' . $ex->getLine(), ENT_QUOTES);
        $hint = '';
        if (stripos($ex->getMessage(), 'could not find driver') !== false) {
            $hint = '<p><strong>Likely fix:</strong> enable the SQLite (or MySQL) PDO driver. '
                  . 'In your <code>php.ini</code> uncomment <code>extension=pdo_sqlite</code> '
                  . '(and <code>extension=mbstring</code>), then restart the server.</p>';
        } elseif (stripos($ex->getMessage(), 'unable to open database') !== false || stripos($ex->getMessage(), 'readonly') !== false) {
            $hint = '<p><strong>Likely fix:</strong> the <code>storage/</code> folder must be writable so the SQLite file can be created/updated.</p>';
        }
        echo "<div style=\"font-family:system-ui;max-width:760px;margin:60px auto;padding:24px;border:1px solid #f3c8bf;background:#fde8e3;border-radius:12px;color:#7a1f10\">"
           . "<h2 style=\"margin:0 0 8px\">Application error</h2>"
           . "<p style=\"font-size:15px\"><code>{$msg}</code></p>{$hint}"
           . "<p style=\"color:#9a5b4f;font-size:13px\">at {$where}</p>"
           . "<p style=\"color:#9a5b4f;font-size:13px\">(This detailed message shows because <code>'debug' =&gt; true</code> in <code>config.php</code>. Set it to <code>false</code> for production.)</p></div>";
    } else {
        echo 'Something went wrong. Please try again later.';
    }
}
