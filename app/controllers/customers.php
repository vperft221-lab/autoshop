<?php
/** Customers */

function customer_name(array $c): string
{
    if (isset($c['name']) && $c['name'] !== '') return $c['name'];
    return trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
}

function customers_index(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $search = input('q');
    if ($search !== '') {
        $rows = all("SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id=c.id) vehicles
                     FROM customers c WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? ORDER BY c.first_name, c.last_name",
                    ["%$search%", "%$search%", "%$search%"]);
    } else {
        $rows = all("SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id=c.id) vehicles
                     FROM customers c ORDER BY c.id DESC");
    }
    $table = data_table([
        'Name'    => fn($r) => '<a class="link" href="/customers/' . (int)$r['id'] . '">' . e(customer_name($r)) . '</a>',
        'Phone'   => fn($r) => e($r['phone'] ?: '—'),
        'Email'   => fn($r) => mailto_link($r['email']),
        'Vehicles'=> fn($r) => badge((string)$r['vehicles'], 'blue'),
        'Added'   => fn($r) => d($r['created_at']),
        ''        => fn($r) => '<a class="link" href="/customers/' . (int)$r['id'] . '/edit">Edit</a>',
    ], $rows, 'No customers found.');

    $sv = e($search);
    $content = '<div class="toolbar">'
        . '<form class="search" method="get" action="/customers"><input type="search" name="q" value="' . $sv . '" placeholder="Search first, last name or phone…">' . '<button class="btn btn-ghost">Search</button></form>'
        . btn('+ New customer', '/customers/new')
        . '</div>' . $table;
    echo layout('Customers', $content, 'customers');
}

function customers_form($id = null): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $c = $id ? one('SELECT * FROM customers WHERE id = ?', [$id]) : [];
    if ($id && !$c) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Customer not found.</p></div>'); return; }
    $action = $id ? "/customers/{$id}" : '/customers';
    $fields = '<div class="grid-2">'
            . field('First name', 'first_name', ['value' => $c['first_name'] ?? '', 'required' => true, 'placeholder' => 'e.g. Kwame'])
            . field('Last name', 'last_name', ['value' => $c['last_name'] ?? '', 'required' => true, 'placeholder' => 'e.g. Mensah'])
            . '</div>'
            . field('Phone', 'phone', ['value' => $c['phone'] ?? '', 'placeholder' => '024 000 0000'])
            . field('Email', 'email', ['type' => 'email', 'value' => $c['email'] ?? ''])
            . field('Address', 'address', ['type' => 'textarea', 'value' => $c['address'] ?? '']);
    $content = form_card($id ? 'Edit customer' : 'New customer', $action, $fields, $id ? 'Update' : 'Create', '/customers');
    echo layout($id ? 'Edit customer' : 'New customer', $content, 'customers');
}

function customers_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['first_name' => 'required', 'last_name' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/customers/new'); }
    $first = input('first_name'); $last = input('last_name');
    $id = insert('customers', [
        'first_name' => $first, 'last_name' => $last, 'name' => trim("$first $last"),
        'phone' => input('phone'), 'email' => input('email'), 'address' => input('address'),
    ]);
    audit('create', 'customer', $id, trim("$first $last"));
    flash('success', 'Customer created.');
    redirect('/customers/' . $id);
}

function customers_update($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    if (!one('SELECT id FROM customers WHERE id = ?', [$id])) redirect('/customers');
    $errors = validate(['first_name' => 'required', 'last_name' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect("/customers/{$id}/edit"); }
    $first = input('first_name'); $last = input('last_name');
    update('customers', [
        'first_name' => $first, 'last_name' => $last, 'name' => trim("$first $last"),
        'phone' => input('phone'), 'email' => input('email'), 'address' => input('address'),
    ], 'id = :id', ['id' => $id]);
    audit('update', 'customer', (int)$id);
    flash('success', 'Customer updated.');
    redirect('/customers/' . $id);
}

function customers_show($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $c = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Customer not found.</p></div>'); return; }
    $vehicles = all('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY id DESC', [$id]);

    $pendingEstimates = all("SELECT j.id, j.fault_desc, j.estimate, j.approval_status, v.reg_number
                             FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id
                             WHERE v.customer_id = ? AND j.approval_status IN ('pending','declined')
                             ORDER BY j.id DESC", [$id]);
    $estimateNotice = '';
    if ($pendingEstimates) {
        $pendingCount  = count(array_filter($pendingEstimates, fn($j) => $j['approval_status'] === 'pending'));
        $declinedCount = count(array_filter($pendingEstimates, fn($j) => $j['approval_status'] === 'declined'));
        $parts = [];
        if ($pendingCount)  $parts[] = $pendingCount . ' estimate' . ($pendingCount > 1 ? 's' : '') . ' awaiting customer decision';
        if ($declinedCount) $parts[] = $declinedCount . ' estimate' . ($declinedCount > 1 ? 's' : '') . ' declined by customer';

        $rows = '';
        foreach ($pendingEstimates as $j) {
            $tone = $j['approval_status'] === 'declined' ? 'red' : 'amber';
            $rows .= '<div class="estimate-row">'
                . '<a class="link" href="/jobcards/' . (int)$j['id'] . '">' . jc_no($j['id']) . ' — ' . e($j['reg_number']) . '</a> '
                . badge(ucfirst($j['approval_status']), $tone)
                . ' <span class="muted small">' . e(mb_strimwidth($j['fault_desc'], 0, 50, '…')) . ' · ' . money($j['estimate']) . '</span>'
                . '</div>';
        }

        $estimateNotice = '<div class="card estimate-notice" style="margin-bottom:18px">'
            . '<div class="estimate-notice-head"><strong>' . implode(' · ', $parts) . '</strong></div>'
            . '<div class="estimate-notice-body">' . $rows . '</div>'
            . '</div>';
    }

    $vTable = data_table([
        'Reg. number' => fn($r) => '<strong>' . e($r['reg_number']) . '</strong>',
        'Make / model'=> fn($r) => e(trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—'),
        'Year'        => fn($r) => e($r['year'] ?: '—'),
        ''            => fn($r) => '<a class="link" href="/jobcards/new?vehicle_id=' . (int)$r['id'] . '">New job</a>',
    ], $vehicles, 'No vehicles registered for this customer.');

    $info = '<div class="card profile"><div class="profile-grid">'
        . '<div><span class="lbl">Phone</span>' . e($c['phone'] ?: '—') . '</div>'
        . '<div><span class="lbl">Email</span>' . mailto_link($c['email']) . '</div>'
        . '<div><span class="lbl">Address</span>' . e($c['address'] ?: '—') . '</div>'
        . '<div><span class="lbl">Customer since</span>' . d($c['created_at']) . '</div>'
        . '</div></div>';

    $smsHistory = all('SELECT * FROM sms_messages WHERE customer_id = ? ORDER BY id DESC', [$id]);
    $smsHistoryHtml = '';
    if ($smsHistory) {
        $smsRows = '';
        foreach ($smsHistory as $m) {
            $badge = $m['status'] === 'sent' ? badge('Sent', 'green') : badge('Failed', 'red');
            $smsRows .= '<div class="sms-row">'
                . '<div class="sms-row-top">' . $badge . '<span class="sms-date">' . dt($m['created_at']) . '</span></div>'
                . '<p class="sms-text">' . e($m['message']) . '</p></div>';
        }
        $count = count($smsHistory);
        $smsHistoryHtml = '<div class="card sms-card" style="margin-top:18px;max-width:620px">'
            . '<button type="button" class="sms-history-toggle" data-target="smsHistoryBody">'
            . '<span class="sms-title">Message history (' . $count . ')</span>'
            . '<span class="chevron">▾</span>'
            . '</button>'
            . '<div id="smsHistoryBody" class="sms-history-body" style="display:none">' . $smsRows . '</div>'
            . '</div>';
    }

    $smsForm = '';
    if (!empty($c['phone'])) {
        $smsForm = '<div class="card form-card" style="margin-top:18px;max-width:620px">'
            . '<h2 class="card-title">Text this customer</h2>'
            . '<form method="post" action="/customers/' . $id . '/sms">' . csrf_field()
            . field('Message', 'message', ['type' => 'textarea', 'rows' => 4, 'required' => true, 'hint' => 'Keep it under 160 characters to avoid extra SMS charges.'])
            . '<div class="form-actions"><button type="submit" class="btn btn-primary">Send SMS</button></div>'
            . '</form></div>';
    }

    $portalBtn = '<form method="post" action="/customers/' . $id . '/activate-portal" class="inline-form">' . csrf_field()
        . '<button class="btn btn-ghost" data-confirm="Send this customer their portal login details by SMS?">'
        . ($c['portal_active'] ? 'Resend portal access' : 'Activate portal access') . '</button></form>';

    $content = $estimateNotice
        . '<div class="toolbar"><a class="btn btn-ghost" href="/customers">&larr; All customers</a>'
       /** . '<div>' . btn('Edit', "/customers/{$id}/edit", 'ghost') . ' ' . btn('+ Add vehicle', '/vehicles/new?customer_id=' . $id) . ' ' . $portalBtn . '</div></div>'*/
       . '<div>' . btn('Edit', "/customers/{$id}/edit", 'ghost') . ' ' . btn('+ Add vehicle', '/vehicles/new?customer_id=' . $id) . ' ' . btn('View invoices', '/invoices?customer_id=' . $id, 'ghost') . ' ' . $portalBtn . '</div></div>'
        . '<h2 class="detail-name">' . e(customer_name($c)) . '</h2>'
        . $info
        . '<h3 class="section-title">Vehicles</h3>' . $vTable
        . $smsForm
        . $smsHistoryHtml
        . '<div class="card job-history-card" style="margin-top:18px;max-width:620px">'
        . '<h2 class="card-title">Messages</h2>'
        . '<div id="msgThread" class="msg-thread" data-poll="/customers/' . $id . '/messages/poll"></div>'
        . '<form id="msgForm" class="msg-form" data-endpoint="/customers/' . $id . '/messages/send">' . csrf_field()
        . '<textarea name="body" placeholder="Type a message to this customer…" required></textarea>'
        . '<button type="submit" class="btn btn-primary">Send</button>'
        . '</form></div>';
    echo layout(customer_name($c), $content, 'customers');
}

function customers_email($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $c = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) redirect('/customers');
    if (empty($c['email'])) { flash('error', 'This customer has no email address on file.'); redirect('/customers/' . $id); }
    $subject = input('subject');
    $message = input('message');
    if ($subject === '' || $message === '') { flash('error', 'Enter a subject and message.'); redirect('/customers/' . $id); }

    $sender = current_user();
    $ok = send_system_email($c['email'], $subject, $message, $sender['email'] ?? null);
    if ($ok) {
        audit('email', 'customer', (int)$id, $subject);
        flash('success', 'Email sent to ' . $c['email'] . '.');
    } else {
        flash('error', 'Could not send the email. The server has no mail transport configured.');
    }
    redirect('/customers/' . $id);
}

function customers_delete($id): void
{
    require_role(['admin']);
    q('DELETE FROM customers WHERE id = ?', [$id]);
    audit('delete', 'customer', (int)$id);
    flash('success', 'Customer deleted.');
    redirect('/customers');
}
function customers_sms($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $c = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) redirect('/customers');
    if (empty($c['phone'])) { flash('error', 'This customer has no phone number on file.'); redirect('/customers/' . $id); }
    $message = input('message');
    if ($message === '') { flash('error', 'Enter a message.'); redirect('/customers/' . $id); }

    $ok = send_sms($c['phone'], $message);
    insert('sms_messages', [
        'customer_id' => (int)$id,
        'sent_by'     => current_user()['id'],
        'phone'       => $c['phone'],
        'message'     => $message,
        'status'      => $ok ? 'sent' : 'failed',
    ]);
    if ($ok) {
        audit('sms', 'customer', (int)$id, $message);
        flash('success', 'SMS sent to ' . $c['phone'] . '.');
    } else {
        flash('error', 'Could not send the SMS. Check the mNotify API key in config.php.');
    }
    redirect('/customers/' . $id);
}
function customers_activate_portal($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $c = one('SELECT * FROM customers WHERE id = ?', [$id]);
    if (!$c) redirect('/customers');
    if (empty($c['phone'])) { flash('error', 'This customer needs a phone number on file to activate portal access.'); redirect('/customers/' . $id); }

    $tempPassword = generate_temp_password();
    update('customers', [
        'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
        'portal_active' => 1,
    ], 'id = :id', ['id' => $id]);

    $baseUrl = rtrim(config()['app_url'] ?? 'http://autoshop.local', '/');
    $message = "Hi " . customer_name($c) . ", you can now track your vehicle's service at Paddy's Auto Tech online. "
             . "Sign in at {$baseUrl}/customer/login with phone {$c['phone']} and password {$tempPassword}.";
    $ok = send_sms($c['phone'], $message);
    insert('sms_messages', [
        'customer_id' => (int)$id, 'sent_by' => current_user()['id'],
        'phone' => $c['phone'], 'message' => $message, 'status' => $ok ? 'sent' : 'failed',
    ]);

    audit('activate_portal', 'customer', (int)$id);
    flash($ok ? 'success' : 'error', $ok
        ? 'Portal access activated. Login details sent by SMS.'
        : 'Portal access activated, but the SMS could not be sent — the temporary password was ' . $tempPassword . '.');
    redirect('/customers/' . $id);
}
function customers_messages_poll($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    header('Content-Type: application/json');
    $since = input('since') ?: '1970-01-01 00:00:00';
    $msgs = all('SELECT id, sender_type, body, created_at FROM messages WHERE customer_id = ? AND created_at > ? ORDER BY id ASC', [$id, $since]);
    q('UPDATE messages SET read_at = NOW() WHERE customer_id = ? AND sender_type = ? AND read_at IS NULL', [$id, 'customer']);
    echo json_encode(['messages' => $msgs, 'server_time' => date('Y-m-d H:i:s')]);
    exit;
}

function customers_messages_send($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    header('Content-Type: application/json');
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') { echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']); exit; }

    insert('messages', ['customer_id' => (int)$id, 'sender_type' => 'staff', 'sender_id' => current_user()['id'], 'body' => $body]);
    audit('message_sent', 'customer', (int)$id);
    echo json_encode(['ok' => true]);
    exit;
}
