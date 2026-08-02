<?php
/** Customer-facing portal */


function customer_layout(string $title, string $content): string
{
    $c = current_customer();
    $cfg = config();
    $brand = e($cfg['app_name']);
    $flash = render_flash();

    $nav = '';
    if ($c) {
        $items = [
            ['/customer',                'Dashboard',       'grid'],
            ['/customer/jobs',           'Job History',     'wrench'],
            ['/customer/invoices',       'Invoices',        'receipt'],
            ['/customer/appointments',   'My Appointments', 'calendar'],
            ['/customer/report-fault',   'Report a Fault',  'list'],
            ['/customer/messages',       'Messages',        'box'],
        ];
        $links = '';
        foreach ($items as [$path, $label, $ic]) {
            $links .= '<a href="' . e($path) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
        }
        $nav = '<nav class="nav">' . $links . '</nav>';
    }

    $initials = $c ? strtoupper(mb_substr(customer_name($c), 0, 1)) : '?';
    $logoutBtn = $c
        ? '<form method="post" action="/customer/logout" class="inline-form">' . csrf_field()
          . '<button type="submit" class="logout" title="Sign out" data-confirm="Sign out now?">⎋</button></form>'
        : '';
    $userbox = $c
        ? '<div class="userbox"><div class="avatar">' . e($initials) . '</div><div class="usermeta"><strong>' . e(customer_name($c)) . '</strong><span>Customer</span></div>' . $logoutBtn . '</div>'
        : '';

    $mark = brand_mark_html('P');
    $page = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' · ' . $brand . ' Customer Portal</title>'
        . '<link rel="stylesheet" href="/assets/app.css"><script defer src="/assets/app.js"></script>'
        . '</head><body class="customer-portal">'
        . '<input type="checkbox" id="navtoggle" hidden>'
        . '<aside class="sidebar"><div class="brand">' . $mark . '<span class="brand-text">' . $brand . '<small>Customer Portal</small></span></div>'
        . $nav . '</aside>'
        . '<div class="main"><header class="topbar">'
        . '<label for="navtoggle" class="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></label>'
        . '<h1 class="page-title">' . e($title) . '</h1>' . $userbox . '</header>'
        . '<main class="content">' . $flash . $content . '</main></div>'
        . '</body></html>';
    return base_rewrite($page);
}

function customer_login_form(): void
{
    if (current_customer()) redirect('/customer');
    $cfg = config();
    $brand = e($cfg['app_name']);
    $flash = render_flash();
    $csrf = csrf_field();
    $contact = e(input('contact'));
    $mark = brand_mark_html('P');
    echo base_rewrite(<<<HTML
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · {$brand} Customer Portal</title><link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">{$mark}<div>{$brand}<small>Customer Portal</small></div></div>
  <h1>Welcome back</h1>
  <p class="muted">Sign in to track your vehicle's service.</p>
  {$flash}
  <form method="post" action="/customer/login" novalidate>
    {$csrf}
    <div class="form-group"><label>Phone or email</label>
      <input type="text" name="contact" value="{$contact}" autofocus required></div>
    <div class="form-group"><label>Password</label>
      <div class="pwd-wrap">
        <input type="password" name="password" id="customerPassword" required autocomplete="current-password">
        <button type="button" id="toggleCustomerPwd" class="pwd-toggle" data-target="customerPassword" title="Show/hide password">Show</button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>
</div>
</body></html>
HTML);
}

function customer_login_post(): void
{
    $contact = input('contact');
    $password = (string)($_POST['password'] ?? '');
    if ($contact === '' || $password === '') { flash('error', 'Enter your phone/email and password.'); redirect('/customer/login'); }

    if (!attempt_customer_login($contact, $password)) {
        flash('error', 'Incorrect details, or portal access has not been activated for this account yet.');
        redirect('/customer/login');
    }
    $dest = $_SESSION['_customer_intended'] ?? '/customer';
    unset($_SESSION['_customer_intended']);
    redirect($dest);
}

function customer_logout_action(): void
{
    customer_logout();
    flash('success', 'You have been signed out.');
    redirect('/customer/login');
}

function customer_dashboard(): void
{
    customer_require_login();
    $c = current_customer();

    $pending = all("SELECT j.id, j.fault_desc, j.estimate, j.approval_status, v.reg_number
                     FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id
                     WHERE v.customer_id = ? AND j.approval_status IN ('pending','declined')
                     ORDER BY j.id DESC", [$c['id']]);

    $estimatesHtml = '';
    if ($pending) {
        $rows = '';
        foreach ($pending as $j) {
            $tone = $j['approval_status'] === 'declined' ? 'red' : 'amber';
            $actions = $j['approval_status'] === 'pending'
                ? '<div class="est-actions">'
                  . '<form method="post" action="/customer/jobcards/' . (int)$j['id'] . '/decision" class="inline-form">' . csrf_field()
                  . '<input type="hidden" name="decision" value="approved">'
                  . '<button class="btn btn-primary btn-sm">Approve</button></form>'
                  . '<form method="post" action="/customer/jobcards/' . (int)$j['id'] . '/decision" class="inline-form">' . csrf_field()
                  . '<input type="hidden" name="decision" value="declined">'
                  . '<button class="btn btn-ghost btn-sm" data-confirm="Decline this estimate?">Decline</button></form>'
                  . '</div>'
                : '<span class="muted small">You declined this estimate.</span>';
            $rows .= '<div class="estimate-row">'
                . '<strong>' . e($j['reg_number']) . '</strong> ' . badge(ucfirst($j['approval_status']), $tone)
                . '<p class="muted small" style="margin:4px 0">' . e($j['fault_desc']) . ' — ' . money($j['estimate']) . '</p>'
                . $actions . '</div>';
        }
        $estimatesHtml = '<div class="card estimate-notice" style="margin-bottom:18px">'
            . '<div class="estimate-notice-head"><strong>' . count($pending) . ' estimate' . (count($pending) > 1 ? 's' : '') . ' need your attention</strong></div>'
            . '<div class="estimate-notice-body">' . $rows . '</div></div>';
    }

    $content = $estimatesHtml
        . '<div class="card job-history-card"><h2 class="card-title">Welcome, ' . e(customer_name($c)) . '</h2>'
        . '<p>Job history, invoices, fault reporting, and appointment booking are coming very soon.</p></div>';
    echo customer_layout('Dashboard', $content);
}

function customer_job_decision($id): void
{
    customer_require_login();
    $c = current_customer();
    $decision = input('decision');
    if (!in_array($decision, ['approved', 'declined'], true)) redirect('/customer');

    // Security check: this job card must belong to a vehicle owned by the logged-in customer.
    $job = one("SELECT j.* FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id
                WHERE j.id = ? AND v.customer_id = ?", [$id, $c['id']]);
    if (!$job) { flash('error', 'That job card could not be found.'); redirect('/customer'); }
    if ($job['approval_status'] !== 'pending') { redirect('/customer'); }

    update('job_cards', [
        'approval_status' => $decision,
        'approved'        => $decision === 'approved' ? 1 : 0,
    ], 'id = :id', ['id' => $id]);

    audit($decision === 'approved' ? 'customer_approve' : 'customer_decline', 'job_card', (int)$id);
    flash('success', $decision === 'approved' ? 'Estimate approved. The shop has been notified.' : 'Estimate declined.');
    redirect('/customer');
}

function customer_jobs(): void
{
    customer_require_login();
    $c = current_customer();

    $jobs = all("SELECT j.*, v.reg_number, v.make, v.model
                 FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id
                 WHERE v.customer_id = ? ORDER BY j.id DESC", [$c['id']]);

    $rows = '';
    if (!$jobs) {
        $rows = '<div class="card empty"><p>No job history yet.</p></div>';
    } else {
        foreach ($jobs as $j) {
            $faults = all('SELECT jf.fault_desc, m.name AS mechanic, m.specialization
                            FROM job_faults jf LEFT JOIN users m ON m.id = jf.mechanic_id
                            WHERE jf.job_card_id = ?', [$j['id']]);
            $faultList = '';
            foreach ($faults as $f) {
                $mech = $f['mechanic']
                    ? e($f['mechanic']) . ($f['specialization'] ? ' — ' . e($f['specialization']) : '')
                    : 'Not yet assigned';
                $faultList .= '<div class="jh-fault-row">'
                    . '<span class="jh-fault-desc">' . e($f['fault_desc']) . '</span>'
                    . '<span class="jh-mechanic">' . $mech . '</span>'
                    . '</div>';
            }
            $rows .= '<div class="card job-history-card">'
                . '<div class="jh-head">'
                . '<span class="jh-title">' . jc_no($j['id']) . ' — ' . e($j['reg_number']) . ' <span class="jh-model">(' . e(trim($j['make'] . ' ' . $j['model'])) . ')</span></span>'
                . status_badge($j['status'])
                . '</div>'
                . '<div class="jh-faults">' . $faultList . '</div>'
                . '<div class="jh-meta">'
                . '<div><span class="lbl">Opened</span>' . d($j['created_at']) . '</div>'
                . '<div><span class="lbl">Est. completion</span>' . d($j['estimated_completion']) . '</div>'
                . '<div><span class="lbl">Actual completion</span>' . d($j['actual_completion']) . '</div>'
                . '</div>'
                . '</div>';
        }
    }
    echo customer_layout('Job History', '<h2 class="detail-name">Job History</h2>' . $rows);
}

function customer_invoices(): void
{
    customer_require_login();
    $c = current_customer();

    $invoices = all("SELECT i.*, v.reg_number
                      FROM invoices i JOIN job_cards j ON j.id = i.job_card_id
                      JOIN vehicles v ON v.id = j.vehicle_id
                      WHERE v.customer_id = ? ORDER BY i.id DESC", [$c['id']]);

    $table = data_table([
        'Invoice'  => fn($r) => e($r['invoice_no']),
        'Vehicle'  => fn($r) => e($r['reg_number']),
        'Total'    => fn($r) => money($r['total']),
        'Balance'  => fn($r) => money($r['balance']),
        'Status'   => fn($r) => status_badge($r['status']),
        'Date'     => fn($r) => d($r['created_at']),
    ], $invoices, 'No invoices yet.');

    echo customer_layout('Invoices', '<h2 class="detail-name">Invoices</h2>' . $table);
}

function customer_report_fault_form(): void
{
    customer_require_login();
    $c = current_customer();
    $vehicles = all('SELECT id, reg_number, make, model FROM vehicles WHERE customer_id = ? ORDER BY id DESC', [$c['id']]);
    if (!$vehicles) {
        echo customer_layout('Report a Fault', '<div class="card empty"><p>No vehicles are registered to your account yet. Please contact the shop.</p></div>');
        return;
    }
    $vopts = [];
    foreach ($vehicles as $v) $vopts[$v['id']] = $v['reg_number'] . ' — ' . trim($v['make'] . ' ' . $v['model']);

    $fields = field('Vehicle', 'vehicle_id', ['type' => 'select', 'options' => $vopts, 'required' => true])
            . field('Describe the fault', 'fault_desc', ['type' => 'textarea', 'required' => true, 'placeholder' => 'e.g. Engine makes a knocking noise when accelerating']);
    $content = '<h2 class="detail-name">Report a Fault</h2>'
        . form_card('Tell us what\'s wrong', '/customer/report-fault', $fields, 'Submit report');
    echo customer_layout('Report a Fault', $content);
}

function customer_report_fault_store(): void
{
    customer_require_login();
    $c = current_customer();
    $vehicleId = (int)input('vehicle_id');
    $faultDesc = input('fault_desc');

    // Security: the vehicle must actually belong to this customer.
    $vehicle = one('SELECT * FROM vehicles WHERE id = ? AND customer_id = ?', [$vehicleId, $c['id']]);
    if (!$vehicle) { flash('error', 'Select one of your registered vehicles.'); redirect('/customer/report-fault'); }
    if ($faultDesc === '') { flash('error', 'Describe the fault before submitting.'); redirect('/customer/report-fault'); }

    $jobId = insert('job_cards', [
        'vehicle_id' => $vehicleId,
        'created_by' => null,
        'fault_desc' => $faultDesc,
        'estimate'   => 0,
        'status'     => 'open',
        'approval_status' => 'pending',
    ]);
    insert('job_faults', ['job_card_id' => $jobId, 'fault_desc' => $faultDesc]);

    audit('customer_report_fault', 'job_card', $jobId);
    flash('success', 'Fault reported. The shop will review it and prepare an estimate.');
    redirect('/customer/jobs');
}
function customer_appointments(): void
{
    customer_require_login();
    $c = current_customer();

    $appts = all("SELECT a.*, v.reg_number FROM appointments a
                  LEFT JOIN vehicles v ON v.id = a.vehicle_id
                  WHERE a.customer_id = ? ORDER BY a.scheduled_at DESC", [$c['id']]);

    $rows = '';
    if (!$appts) {
        $rows = '<div class="card empty"><p>No appointments booked yet.</p></div>';
    } else {
        foreach ($appts as $a) {
            $rows .= '<div class="card job-history-card" style="margin-bottom:14px">'
                . '<div class="jh-head">'
                . '<span class="jh-title">' . dt($a['scheduled_at']) . '</span>'
                . status_badge($a['status'])
                . '</div>'
                . '<p class="muted small" style="margin:0 0 4px">' . ($a['reg_number'] ? 'Vehicle: ' . e($a['reg_number']) : 'No vehicle specified') . '</p>'
                . ($a['note'] ? '<p style="margin:0 0 4px">' . e($a['note']) . '</p>' : '')
                . ($a['status'] === 'declined' && $a['decline_reason'] ? '<p style="margin:6px 0 0;color:#b91c1c;font-size:13px"><strong>Reason:</strong> ' . e($a['decline_reason']) . '</p>' : '')
                . '</div>';
        }
    }
    $bookBtn = '<div style="margin-bottom:16px">' . btn('+ Book an appointment', '/customer/appointments/new') . '</div>';
    echo customer_layout('My Appointments', '<h2 class="detail-name">My Appointments</h2>' . $bookBtn . $rows);
}

function customer_appointment_form(): void
{
    customer_require_login();
    $c = current_customer();
    $vehicles = all('SELECT id, reg_number, make, model FROM vehicles WHERE customer_id = ? ORDER BY id DESC', [$c['id']]);
    $vopts = ['' => '— Not sure / no specific vehicle —'];
    foreach ($vehicles as $v) $vopts[$v['id']] = $v['reg_number'] . ' — ' . trim($v['make'] . ' ' . $v['model']);

    $fields = field('Vehicle', 'vehicle_id', ['type' => 'select', 'options' => $vopts])
            . field('Preferred date & time', 'scheduled_at', ['type' => 'datetime-local', 'required' => true, 'min' => date('Y-m-d\TH:i')])
            . field('Note', 'note', ['type' => 'textarea', 'placeholder' => 'What do you need done?']);
    $content = '<h2 class="detail-name">Book an Appointment</h2>'
        . form_card('New appointment request', '/customer/appointments', $fields, 'Book appointment', '/customer/appointments');
    echo customer_layout('Book Appointment', $content);
}

function customer_appointment_store(): void
{
    customer_require_login();
    $c = current_customer();

    $vehicleId = input('vehicle_id') ? (int)input('vehicle_id') : null;
    if ($vehicleId) {
        // Security: the vehicle must actually belong to this customer.
        $owned = one('SELECT id FROM vehicles WHERE id = ? AND customer_id = ?', [$vehicleId, $c['id']]);
        if (!$owned) { flash('error', 'Select one of your own registered vehicles.'); redirect('/customer/appointments/new'); }
    }

    $scheduled = input('scheduled_at');
    if ($scheduled === '') { flash('error', 'Choose a date and time.'); redirect('/customer/appointments/new'); }
    $when = str_replace('T', ' ', $scheduled) . ':00';
    if (strtotime($when) < strtotime(date('Y-m-d H:i:00'))) {
        flash('error', 'Appointments cannot be booked in the past.');
        redirect('/customer/appointments/new');
    }

    // Slot-limit check: reject if this time window is already fully booked
    // (pending requests count too, so the shop isn't oversold while requests await approval).
    $cfg = config('appointments') ?? ['max_per_slot' => 2, 'slot_window_minutes' => 60];
    $windowStart = date('Y-m-d H:i:s', strtotime($when) - $cfg['slot_window_minutes'] * 60);
    $windowEnd   = date('Y-m-d H:i:s', strtotime($when) + $cfg['slot_window_minutes'] * 60);
    $taken = (int) scalar("SELECT COUNT(*) FROM appointments
                            WHERE status IN ('pending','scheduled')
                            AND scheduled_at BETWEEN ? AND ?", [$windowStart, $windowEnd]);
    if ($taken >= $cfg['max_per_slot']) {
        flash('error', 'That time is fully booked. Please choose another time.');
        redirect('/customer/appointments/new');
    }

    $id = insert('appointments', [
        'customer_id' => $c['id'],
        'vehicle_id'  => $vehicleId,
        'scheduled_at'=> $when,
        'note'        => input('note'),
        'status'      => 'pending',
    ]);
    audit('customer_book_appointment', 'appointment', $id);
    flash('success', 'Appointment requested. The shop will review and confirm it shortly.');
    redirect('/customer/appointments');
}

function customer_messages(): void
{
    customer_require_login();
    $c = current_customer();

    // Mark staff messages as read the moment the customer opens this page.
    q('UPDATE messages SET read_at = NOW() WHERE customer_id = ? AND sender_type = ? AND read_at IS NULL', [$c['id'], 'staff']);

    $content = '<h2 class="detail-name">Messages</h2>'
        . '<div class="card job-history-card">'
        . '<div id="msgThread" class="msg-thread" data-customer="' . (int)$c['id'] . '" data-poll="/customer/messages/poll"></div>'
        . '<form id="msgForm" class="msg-form" data-endpoint="/customer/messages/send">' . csrf_field()
        . '<textarea name="body" placeholder="Type a message to the shop…" required></textarea>'
        . '<button type="submit" class="btn btn-primary">Send</button>'
        . '</form></div>';
    echo customer_layout('Messages', $content);
}

function customer_messages_poll(): void
{
    customer_require_login();
    header('Content-Type: application/json');
    $c = current_customer();
    $since = input('since') ?: '1970-01-01 00:00:00';
    $msgs = all('SELECT id, sender_type, body, created_at FROM messages WHERE customer_id = ? AND created_at > ? ORDER BY id ASC', [$c['id'], $since]);
    echo json_encode(['messages' => $msgs, 'server_time' => date('Y-m-d H:i:s')]);
    exit;
}

function customer_messages_send(): void
{
    customer_require_login();
    header('Content-Type: application/json');
    $c = current_customer();
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') { echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']); exit; }

    insert('messages', ['customer_id' => $c['id'], 'sender_type' => 'customer', 'sender_id' => null, 'body' => $body]);
    audit('message_sent', 'customer', $c['id']);
    echo json_encode(['ok' => true]);
    exit;
}
