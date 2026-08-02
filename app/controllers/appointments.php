<?php
/** Appointments */

function appointments_index(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $rows = all("SELECT a.*, c.name AS customer, v.reg_number
                 FROM appointments a LEFT JOIN customers c ON c.id=a.customer_id
                 LEFT JOIN vehicles v ON v.id=a.vehicle_id ORDER BY a.scheduled_at DESC");

    $pending = array_values(array_filter($rows, fn($r) => $r['status'] === 'pending'));
    $pendingHtml = '';
    if ($pending) {
        $cfg = config('appointments') ?? ['max_per_slot' => 2, 'slot_window_minutes' => 60];
        $cards = '';
        foreach ($pending as $p) {
            $windowStart = date('Y-m-d H:i:s', strtotime($p['scheduled_at']) - $cfg['slot_window_minutes'] * 60);
            $windowEnd   = date('Y-m-d H:i:s', strtotime($p['scheduled_at']) + $cfg['slot_window_minutes'] * 60);
            $nearby = (int) scalar("SELECT COUNT(*) FROM appointments
                        WHERE status = 'scheduled' AND id != ? AND scheduled_at BETWEEN ? AND ?",
                        [$p['id'], $windowStart, $windowEnd]);
            $cards .= '<div class="card job-history-card" style="margin-bottom:14px">'
                . '<div class="jh-head"><span class="jh-title">' . dt($p['scheduled_at']) . '</span>' . status_badge($p['status']) . '</div>'
                . '<p class="muted small" style="margin:0 0 4px">' . e($p['customer'] ?: 'Unregistered customer')
                . ($p['reg_number'] ? ' — ' . e($p['reg_number']) : '') . '</p>'
                . ($p['note'] ? '<p style="margin:0 0 8px">' . e($p['note']) . '</p>' : '')
                . ($nearby > 0 ? '<p class="small" style="color:#b45309;margin:0 0 8px">⚠ ' . $nearby . ' other confirmed appointment(s) within ' . $cfg['slot_window_minutes'] . ' min of this slot.</p>' : '')
                . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
                . '<form method="post" action="/appointments/' . (int)$p['id'] . '/approve" class="inline-form">' . csrf_field()
                . '<button class="btn btn-primary btn-sm">Approve</button></form>'
                . '<form method="post" action="/appointments/' . (int)$p['id'] . '/decline" class="inline-form" style="display:flex;gap:6px;align-items:center">' . csrf_field()
                . '<input type="text" name="decline_reason" placeholder="Reason (required)" required style="padding:7px 10px;border:1px solid #d8dee9;border-radius:8px;font-size:13px;width:320px">'
                . '<button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#f3c9c9">Decline</button></form>'
                . '</div></div>';
        }
        $pendingHtml = '<h3 style="margin:0 0 12px">Pending Requests (' . count($pending) . ')</h3>' . $cards . '<h3 style="margin:24px 0 12px">All Appointments</h3>';
    }

    $table = data_table([
        'When'     => fn($r) => '<strong>' . dt($r['scheduled_at']) . '</strong>',
        'Customer' => fn($r) => e($r['customer'] ?: '—'),
        'Vehicle'  => fn($r) => e($r['reg_number'] ?: '—'),
        'Note'     => fn($r) => e(mb_strimwidth((string)$r['note'], 0, 40, '…') ?: '—'),
        'Status'   => fn($r) => status_badge($r['status']),
        ''         => function ($r) {
                          $cfg = config('appointments') ?? [];
                          $hours = $cfg['mark_done_after_hours'] ?? 2;
                          if ($r['status'] === 'scheduled') {
                              $eligibleAt = strtotime($r['scheduled_at']) + $hours * 3600;
                              if (time() >= $eligibleAt) {
                                  return '<form method="post" action="/appointments/' . (int)$r['id'] . '/status" class="inline-form">' . csrf_field()
                                       . '<input type="hidden" name="status" value="completed"><button class="link">Mark done</button></form>';
                              }
                              return '<span class="muted small">Available ' . dt(date('Y-m-d H:i:s', $eligibleAt)) . '</span>';
                          }
                          if ($r['status'] === 'declined' && $r['decline_reason']) {
                              return '<span class="muted small">' . e($r['decline_reason']) . '</span>';
                          }
                          return '';
                      },
    ], $rows, 'No appointments booked.');
    $content = '<div class="toolbar"><div></div>' . btn('+ Book appointment', '/appointments/new') . '</div>' . $pendingHtml . $table;
    echo layout('Appointments', $content, 'appointments');
}

function appointments_form(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $customers = all('SELECT id, name FROM customers ORDER BY name');
    $copts = ['' => '— Select customer —'];
    foreach ($customers as $c) $copts[$c['id']] = $c['name'];
    $vehicles = all("SELECT v.id, v.reg_number, v.customer_id, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.id DESC");
    $vopts = ['' => '— Select customer first —'];
    // All vehicles, grouped by customer, are embedded as JSON so app.js can filter the
    // vehicle dropdown down to only the vehicles registered to the selected customer.
    $byCustomer = [];
    foreach ($vehicles as $v) $byCustomer[$v['customer_id']][] = ['id' => $v['id'], 'label' => $v['reg_number']];
    $vehicleDataJson = e(json_encode($byCustomer, JSON_UNESCAPED_UNICODE));

    $fields = '<div id="apptVehicleData" data-vehicles="' . $vehicleDataJson . '"></div>'
            . field('Customer', 'customer_id', ['type' => 'select', 'options' => $copts])
            . str_replace('<select name="vehicle_id"', '<select name="vehicle_id" class="js-appt-vehicle" disabled', field('Vehicle', 'vehicle_id', ['type' => 'select', 'options' => $vopts]))
            . field('Date & time', 'scheduled_at', ['type' => 'datetime-local', 'required' => true, 'min' => date('Y-m-d\TH:i')])
            . field('Note', 'note', ['type' => 'textarea', 'placeholder' => 'Service required…']);
    // Give the customer <select> the class app.js listens on.
    $fields = str_replace('<select name="customer_id"', '<select name="customer_id" class="js-appt-customer"', $fields);
    $content = form_card('Book appointment', '/appointments', $fields, 'Book', '/appointments');
    echo layout('Book appointment', $content, 'appointments');
}

function appointments_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['scheduled_at' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/appointments/new'); }
    $when = str_replace('T', ' ', input('scheduled_at')) . ':00';
    if (strtotime($when) < strtotime(date('Y-m-d H:i:00'))) {
        flash('error', 'Appointments cannot be booked in the past.');
        redirect('/appointments/new');
    }
    $id = insert('appointments', [
        'customer_id' => input('customer_id') ? (int)input('customer_id') : null,
        'vehicle_id'  => input('vehicle_id') ? (int)input('vehicle_id') : null,
        'scheduled_at'=> $when, 'note' => input('note'), 'status' => 'scheduled',
    ]);
    audit('create', 'appointment', $id);
    flash('success', 'Appointment booked.');
    redirect('/appointments');
}

function appointments_status($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $status = in_array(input('status'), ['scheduled','completed','cancelled'], true) ? input('status') : 'completed';

    if ($status === 'completed') {
        $a = one('SELECT scheduled_at FROM appointments WHERE id = ?', [$id]);
        $cfg = config('appointments') ?? [];
        $hours = $cfg['mark_done_after_hours'] ?? 2;
        if ($a && strtotime($a['scheduled_at']) + $hours * 3600 > time()) {
            flash('error', "This appointment can't be marked done yet — please wait until {$hours} hours after the scheduled time.");
            redirect('/appointments');
        }
    }

    update('appointments', ['status' => $status], 'id = :id', ['id' => $id]);
    audit('status', 'appointment', (int)$id, $status);
    flash('success', 'Appointment updated.');
    redirect('/appointments');
}

function appointments_approve($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $a = one("SELECT a.*, c.name AS customer_name, c.phone AS customer_phone
              FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id
              WHERE a.id = ?", [$id]);
    if (!$a) { flash('error', 'Appointment not found.'); redirect('/appointments'); }

    update('appointments', ['status' => 'scheduled'], 'id = :id', ['id' => $id]);
    audit('approve', 'appointment', (int)$id);

    if (!empty($a['customer_phone'])) {
        $message = "Hi " . ($a['customer_name'] ?: 'there') . ", your appointment on " . dt($a['scheduled_at'])
                  . " at Paddy's Auto Tech has been CONFIRMED. See you then!";
        $ok = send_sms($a['customer_phone'], $message);
        insert('sms_messages', [
            'customer_id' => $a['customer_id'], 'sent_by' => current_user()['id'],
            'phone' => $a['customer_phone'], 'message' => $message, 'status' => $ok ? 'sent' : 'failed',
        ]);
    }

    flash('success', 'Appointment approved and confirmed.');
    redirect('/appointments');
}

function appointments_decline($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $reason = trim((string) input('decline_reason'));
    if ($reason === '') { flash('error', 'Enter a reason for declining.'); redirect('/appointments'); }

    $a = one("SELECT a.*, c.name AS customer_name, c.phone AS customer_phone
              FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id
              WHERE a.id = ?", [$id]);
    if (!$a) { flash('error', 'Appointment not found.'); redirect('/appointments'); }

    update('appointments', ['status' => 'declined', 'decline_reason' => $reason], 'id = :id', ['id' => $id]);
    audit('decline', 'appointment', (int)$id, $reason);

    if (!empty($a['customer_phone'])) {
        $message = "Hi " . ($a['customer_name'] ?: 'there') . ", your appointment request for " . dt($a['scheduled_at'])
                  . " at Paddy's Auto Tech could not be confirmed. Reason: {$reason}. Please book another time.";
        $ok = send_sms($a['customer_phone'], $message);
        insert('sms_messages', [
            'customer_id' => $a['customer_id'], 'sent_by' => current_user()['id'],
            'phone' => $a['customer_phone'], 'message' => $message, 'status' => $ok ? 'sent' : 'failed',
        ]);
    }

    flash('success', 'Appointment declined.');
    redirect('/appointments');
}

