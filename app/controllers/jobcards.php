<?php
/** Job Cards — the core workshop workflow */

function jc_no($id): string { return 'JC-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }

/** Is the given mechanic assigned to any fault on this job card (or the legacy top-level mechanic)? */
function mechanic_on_job(int $jobId, int $mechanicId): bool
{
    return (bool) scalar(
        'SELECT 1 FROM job_faults WHERE job_card_id = ? AND mechanic_id = ?
         UNION SELECT 1 FROM job_cards WHERE id = ? AND mechanic_id = ? LIMIT 1',
        [$jobId, $mechanicId, $jobId, $mechanicId]
    );
}

/** Options for a mechanic <select>, showing each mechanic's specialization. */
function mechanic_select_options(bool $allowUnassigned = true): array
{
    $mechs = all("SELECT id, name, specialization FROM users WHERE role IN ('mechanic','manager') AND active=1 ORDER BY name");
    $opts = $allowUnassigned ? ['' => '— Unassigned —'] : [];
    foreach ($mechs as $m) {
        $label = $m['name'] . ($m['specialization'] ? ' — ' . $m['specialization'] : '');
        $opts[$m['id']] = $label;
    }
    return $opts;
}

function jobcards_index(): void
{
    require_login();
    $u = current_user();
    // Mechanics see only jobs where they're assigned to at least one fault (or the legacy mechanic_id field).
    if ($u['role'] === 'mechanic') {
        $rows = all("SELECT j.*, v.reg_number, c.name AS customer
                     FROM job_cards j JOIN vehicles v ON v.id=j.vehicle_id JOIN customers c ON c.id=v.customer_id
                     WHERE j.mechanic_id = ? OR j.id IN (SELECT job_card_id FROM job_faults WHERE mechanic_id = ?)
                     ORDER BY j.id DESC", [$u['id'], $u['id']]);
    } else {
        $rows = all("SELECT j.*, v.reg_number, c.name AS customer
                     FROM job_cards j JOIN vehicles v ON v.id=j.vehicle_id JOIN customers c ON c.id=v.customer_id
                     ORDER BY j.id DESC");
    }

    // Pull faults + mechanics for every listed job in one go, to build the Fault/Mechanic summary columns.
    $faultsByJob = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $faults = all("SELECT jf.job_card_id, jf.fault_desc, m.name AS mechanic
                        FROM job_faults jf LEFT JOIN users m ON m.id=jf.mechanic_id
                        WHERE jf.job_card_id IN ($ph) ORDER BY jf.id", $ids);
        foreach ($faults as $f) $faultsByJob[$f['job_card_id']][] = $f;
    }

    $table = data_table([
        '#'        => fn($r) => '<a class="link" href="/jobcards/' . (int)$r['id'] . '">' . jc_no($r['id']) . '</a>',
        'Customer' => fn($r) => e($r['customer']),
        'Vehicle'  => fn($r) => e($r['reg_number']),
        'Fault(s)' => function ($r) use ($faultsByJob) {
            $fl = $faultsByJob[$r['id']] ?? [];
            if (!$fl) return e(mb_strimwidth((string)$r['fault_desc'], 0, 40, '…') ?: '—');
            $first = e(mb_strimwidth((string)$fl[0]['fault_desc'], 0, 34, '…'));
            return count($fl) > 1 ? $first . ' <span class="badge badge-gray">+' . (count($fl) - 1) . '</span>' : $first;
        },
        'Mechanic(s)' => function ($r) use ($faultsByJob) {
            $fl = $faultsByJob[$r['id']] ?? [];
            $names = array_unique(array_filter(array_map(fn($f) => $f['mechanic'], $fl)));
            if (!$names) return e('—');
            return count($names) > 1 ? e(implode(', ', $names)) : e($names[array_key_first($names)]);
        },
        'Status'   => fn($r) => status_badge($r['status']),
        'Est. done'=> fn($r) => d($r['estimated_completion'] ?? null),
        'Created'  => fn($r) => d($r['created_at']),
    ], $rows, 'No job cards yet.');

    $new = ($u['role'] === 'mechanic') ? '<div></div>' : btn('+ New job card', '/jobcards/new');
    $content = '<div class="toolbar"><div></div>' . $new . '</div>' . $table;
    echo layout('Job Cards', $content, 'jobcards');
}

function jobcards_form(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $preVehicle = input('vehicle_id');

    $vehicles = all("SELECT v.id, v.reg_number, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.id DESC");
    $vopts = ['' => '— Select vehicle —'];
    foreach ($vehicles as $v) $vopts[$v['id']] = $v['reg_number'] . ' · ' . $v['customer'];

    $mopts = mechanic_select_options();

    if (!$vehicles) {
        $content = '<div class="card empty">' . icon('car') . '<p>Register a customer and vehicle first.</p>' . btn('+ New customer', '/customers/new') . '</div>';
        echo layout('New job card', $content, 'jobcards'); return;
    }

    $fields = field('Vehicle', 'vehicle_id', ['type' => 'select', 'options' => $vopts, 'value' => $preVehicle, 'required' => true])
            . field('Reported fault', 'fault_desc', ['type' => 'textarea', 'required' => true, 'placeholder' => 'Describe the customer complaint… (you can add more faults once the job card is open)'])
            . '<div class="grid-2">'
            . field('Assign mechanic', 'mechanic_id', ['type' => 'select', 'options' => $mopts])
            . field('Estimate (GH₵)', 'estimate', ['type' => 'number', 'value' => '0'])
            . '</div>'
            . field('Estimated completion date', 'estimated_completion', ['type' => 'date', 'min' => date('Y-m-d')]);
    $content = form_card('Open a new job card', '/jobcards', $fields, 'Create job card', '/jobcards');
    echo layout('New job card', $content, 'jobcards');
}

function jobcards_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['vehicle_id' => 'required', 'fault_desc' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/jobcards/new'); }
    if (input('estimated_completion') && input('estimated_completion') < date('Y-m-d')) {
        flash('error', 'Estimated completion date cannot be in the past.');
        redirect('/jobcards/new');
    }
    $mechanicId = input('mechanic_id') ? (int)input('mechanic_id') : null;
    $id = insert('job_cards', [
        'vehicle_id' => (int)input('vehicle_id'),
        'mechanic_id'=> $mechanicId,
        'created_by' => current_user()['id'],
        'fault_desc' => input('fault_desc'),
        'estimate'   => (float)input('estimate'),
        'status'     => 'open',
        'estimated_completion' => input('estimated_completion') ?: null,
    ]);
    // The first reported fault becomes the first row in job_faults, so it appears alongside any later ones.
    insert('job_faults', ['job_card_id' => $id, 'fault_desc' => input('fault_desc'), 'mechanic_id' => $mechanicId]);
    audit('create', 'job_card', $id);
    flash('success', 'Job card ' . jc_no($id) . ' created.');
    redirect('/jobcards/' . $id);
}

function jobcards_edit($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $j = load_job_or_404($id);
    $vehicles = all("SELECT v.id, v.reg_number, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.id DESC");
    $vopts = [];
    foreach ($vehicles as $v) $vopts[$v['id']] = $v['reg_number'] . ' · ' . $v['customer'];

    $fields = field('Vehicle', 'vehicle_id', ['type' => 'select', 'options' => $vopts, 'value' => $j['vehicle_id'], 'required' => true])
            . field('Job summary', 'fault_desc', ['type' => 'textarea', 'value' => $j['fault_desc'], 'required' => true])
            . '<div class="grid-2">'
            . field('Estimate (GH₵)', 'estimate', ['type' => 'number', 'value' => $j['estimate']])
            . field('Status', 'status', ['type' => 'select', 'value' => $j['status'], 'options' => [
                'open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', 'closed' => 'Closed',
              ]])
            .'</div>'
            . '<div class="grid-2">'
            . field('Estimated completion date', 'estimated_completion', ['type' => 'date', 'value' => $j['estimated_completion'], 'min' => date('Y-m-d')])
            . field('Actual completion date', 'actual_completion', ['type' => 'date', 'value' => $j['actual_completion'], 'max' => date('Y-m-d')])
            . '</div>';
    $content = form_card('Edit ' . jc_no($id), '/jobcards/' . $id, $fields, 'Save changes', '/jobcards/' . $id);
    echo layout('Edit ' . jc_no($id), $content, 'jobcards');
}

function jobcards_update($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    $errors = validate(['vehicle_id' => 'required', 'fault_desc' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect("/jobcards/{$id}/edit"); }
    if (input('estimated_completion') && input('estimated_completion') < date('Y-m-d')) {
        flash('error', 'Estimated completion date cannot be in the past.');
        redirect("/jobcards/{$id}/edit");
    }
    if (input('actual_completion') && input('actual_completion') > date('Y-m-d')) {
        flash('error', 'Actual completion date cannot be in the future.');
        redirect("/jobcards/{$id}/edit");
    }
    $status = in_array(input('status'), ['open','in_progress','completed','closed'], true) ? input('status') : 'open';
    $actual = input('actual_completion') ?: null;
    if ($status === 'completed' && !$actual) $actual = date('Y-m-d'); // auto-stamp if not supplied
    update('job_cards', [
        'vehicle_id' => (int)input('vehicle_id'),
        'fault_desc' => input('fault_desc'),
        'estimate'   => (float)input('estimate'),
        'status'     => $status,
        'estimated_completion' => input('estimated_completion') ?: null,
        'actual_completion'    => $actual,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $id]);
    audit('update', 'job_card', (int)$id);
    flash('success', 'Job card updated.');
    redirect('/jobcards/' . $id);
}

function jobcards_show($id): void
{
    require_login();
    $j = one("SELECT j.*, v.reg_number, v.make, v.model, c.name AS customer, c.phone, c.email
              FROM job_cards j JOIN vehicles v ON v.id=j.vehicle_id JOIN customers c ON c.id=v.customer_id
              WHERE j.id = ?", [$id]);
    if (!$j) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Job card not found.</p></div>'); return; }

    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) {
        require_role(['admin']); // mechanics can't view jobs they're not assigned to
    }

    $faults    = all('SELECT jf.*, m.name AS mechanic, m.specialization FROM job_faults jf LEFT JOIN users m ON m.id=jf.mechanic_id WHERE jf.job_card_id = ? ORDER BY jf.id', [$id]);
    $services  = all('SELECT js.*, s.name AS service_name FROM job_services js LEFT JOIN services s ON s.id=js.service_id WHERE js.job_card_id = ?', [$id]);
    $parts     = all('SELECT jp.*, p.name AS part_name FROM job_parts jp LEFT JOIN spare_parts p ON p.id=jp.spare_part_id WHERE jp.job_card_id = ?', [$id]);
    $invoice   = one('SELECT * FROM invoices WHERE job_card_id = ?', [$id]);
    $canManage = in_array($u['role'], ['admin','manager','receptionist'], true);

    $labourTotal = array_sum(array_map(fn($s) => (float)$s['charge'], $services));
    $partsTotal  = array_sum(array_map(fn($p) => (float)$p['unit_price'] * (int)$p['quantity'], $parts));
    $grand = $labourTotal + $partsTotal;

    // header / meta
    $meta = '<div class="card profile"><div class="profile-grid">'
        . '<div><span class="lbl">Customer</span>' . e($j['customer']) . '</div>'
        . '<div><span class="lbl">Phone</span>' . e($j['phone'] ?: '—') . '</div>'
        . '<div><span class="lbl">Email</span>' . mailto_link($j['email']) . '</div>'
        . '<div><span class="lbl">Vehicle</span>' . e($j['reg_number'] . ' · ' . trim(($j['make'] ?? '') . ' ' . ($j['model'] ?? ''))) . '</div>'
        . '<div><span class="lbl">Estimate</span>' . money($j['estimate']) . '</div>'
        . '<div><span class="lbl">Opened</span>' . dt($j['created_at']) . '</div>'
        . '<div><span class="lbl">Estimated completion</span>' . d($j['estimated_completion']) . '</div>'
        . '<div><span class="lbl">Actual completion</span>' . d($j['actual_completion']) . '</div>'
        . '</div><div class="fault"><span class="lbl">Job summary</span><p>' . nl2br(e($j['fault_desc'])) . '</p></div></div>';

    // status control
    $statusOpts = '';
    foreach (['open','in_progress','completed','closed'] as $s) {
        $sel = $j['status'] === $s ? ' selected' : '';
        $statusOpts .= "<option value=\"{$s}\"{$sel}>" . ucwords(str_replace('_',' ',$s)) . "</option>";
    }
    $statusForm = '<form method="post" action="/jobcards/' . $id . '/status" class="inline-form">' . csrf_field()
        . '<select name="status">' . $statusOpts . '</select>'
        . '<button class="btn btn-ghost">Update status</button></form>';
    $editBtn = $canManage ? btn('Edit job card', "/jobcards/{$id}/edit", 'ghost') : '';

    /* ---------- faults (multiple faults / mechanics per job) ---------- */
    $faultRows = data_table([
        'Fault'    => fn($r) => e($r['fault_desc']),
        'Mechanic' => fn($r) => $r['mechanic'] ? e($r['mechanic'] . ($r['specialization'] ? ' — ' . $r['specialization'] : '')) : '—',
        ''         => fn($r) => $canManage
            ? '<a class="link" href="/jobcards/' . $id . '/fault/' . (int)$r['id'] . '/edit">Edit</a> · '
              . '<form method="post" action="/jobcards/' . $id . '/fault/' . (int)$r['id'] . '/delete" class="inline-form">' . csrf_field()
              . '<button class="link" data-confirm="Remove this fault entry?">Delete</button></form>'
            : '',
    ], $faults, 'No faults recorded yet.');

    $faultAdd = '';
    if ($canManage) {
        $mopts = mechanic_select_options();
        $mSel = '<option value="">— Unassigned —</option>';
        foreach ($mopts as $mv => $ml) { if ($mv === '') continue; $mSel .= '<option value="' . e($mv) . '">' . e($ml) . '</option>'; }
        $faultAdd = '<div class="card fault-add-card">'
    . '<form method="post" action="/jobcards/' . $id . '/fault" class="fault-add-form">' . csrf_field()
    . '<textarea name="fault_desc" rows="3" placeholder="Describe the additional fault…" required></textarea>'
    . '<div class="fault-add-row">'
    . '<select name="mechanic_id">' . $mSel . '</select>'
    . '<button class="btn btn-primary btn-sm">+ Add fault</button>'
    . '</div>'
    . '</form></div>';
    }

    // services list + add (managers/admin/receptionist)
    $svcRows = data_table([
        'Service' => fn($r) => e($r['service_name'] ?? $r['description'] ?? '—'),
        'Charge'  => fn($r) => money($r['charge']),
        ''        => fn($r) => (!$invoice && $canManage)
            ? '<a class="link" href="/jobcards/' . $id . '/service/' . (int)$r['id'] . '/edit">Edit</a> · '
              . '<form method="post" action="/jobcards/' . $id . '/service/' . (int)$r['id'] . '/delete" class="inline-form">' . csrf_field()
              . '<button class="link" data-confirm="Remove this labour item?">Delete</button></form>'
            : '',
    ], $services, 'No labour items yet.');

    $allServices = all('SELECT id, name, labour_charge FROM services WHERE active=1 ORDER BY name');
    $svcAdd = '';
    if ($canManage && !$invoice) {
        $sopts = '<option value="">— Select service —</option>';
        foreach ($allServices as $s) $sopts .= '<option value="' . $s['id'] . '" data-charge="' . e($s['labour_charge']) . '">' . e($s['name']) . ' (' . money($s['labour_charge']) . ')</option>';
        $svcAdd = '<form method="post" action="/jobcards/' . $id . '/service" class="row-form">' . csrf_field()
            . '<select name="service_id" class="js-service">' . $sopts . '</select>'
            . '<input type="number" step="any" name="charge" placeholder="Charge" class="js-charge">'
            . '<button class="btn btn-primary btn-sm">Add</button></form>';
    }

    // parts list + add
    $partRows = data_table([
        'Part'  => fn($r) => e($r['part_name'] ?? '—'),
        'Qty'   => fn($r) => (int)$r['quantity'],
        'Unit'  => fn($r) => money($r['unit_price']),
        'Total' => fn($r) => money((float)$r['unit_price'] * (int)$r['quantity']),
        ''      => fn($r) => !$invoice
            ? '<a class="link" href="/jobcards/' . $id . '/part/' . (int)$r['id'] . '/edit">Edit</a> · '
              . '<form method="post" action="/jobcards/' . $id . '/part/' . (int)$r['id'] . '/delete" class="inline-form">' . csrf_field()
              . '<button class="link" data-confirm="Remove this part? Stock will be restored.">Delete</button></form>'
            : '',
    ], $parts, 'No parts used yet.');

    $allParts = all('SELECT id, name, quantity, unit_price FROM spare_parts ORDER BY name');
    $partAdd = '';
    if (!$invoice) {
        $popts = '<option value="">— Select part —</option>';
        foreach ($allParts as $p) {
            $dis = $p['quantity'] <= 0 ? ' disabled' : '';
            $popts .= '<option value="' . $p['id'] . '" data-price="' . e($p['unit_price']) . '"' . $dis . '>' . e($p['name']) . ' — ' . (int)$p['quantity'] . ' in stock</option>';
        }
        $partAdd = '<form method="post" action="/jobcards/' . $id . '/part" class="row-form">' . csrf_field()
            . '<select name="spare_part_id" class="js-part">' . $popts . '</select>'
            . '<input type="number" name="quantity" value="1" min="1" class="qty">'
            . '<button class="btn btn-primary btn-sm">Add part</button></form>';
    }

    // totals + invoice action
    $tax = calc_tax($grand);
    $totalsCard = '<div class="card totals">'
        . '<div class="t-row"><span>Labour</span><strong>' . money($labourTotal) . '</strong></div>'
        . '<div class="t-row"><span>Parts</span><strong>' . money($partsTotal) . '</strong></div>'
        . '<div class="t-row"><span>Subtotal</span><strong>' . money($grand) . '</strong></div>'
        . '<div class="t-row"><span>VAT (' . e($tax['vat_rate']) . '%)</span><strong>' . money($tax['vat_amount']) . '</strong></div>'
        . '<div class="t-row"><span>NHIL (' . e($tax['nhil_rate']) . '%)</span><strong>' . money($tax['nhil_amount']) . '</strong></div>'
        . '<div class="t-row"><span>GETFund (' . e($tax['getfund_rate']) . '%)</span><strong>' . money($tax['getfund_amount']) . '</strong></div>'
        . '<div class="t-row grand"><span>Total (incl. tax)</span><strong>' . money($tax['total']) . '</strong></div>';
    if ($invoice) {
        $totalsCard .= '<a class="btn btn-primary btn-block" href="/invoices/' . (int)$invoice['id'] . '">View invoice ' . e($invoice['invoice_no']) . '</a>';
    } elseif ($canManage) {
        $disabled = $grand <= 0 ? ' disabled title="Add services or parts first"' : '';
        $totalsCard .= '<form method="post" action="/jobcards/' . $id . '/invoice">' . csrf_field()
            . '<button class="btn btn-primary btn-block"' . $disabled . '>Generate invoice</button></form>';
    }
    $totalsCard .= '</div>';

    $content = '<div class="toolbar"><a class="btn btn-ghost" href="/jobcards">&larr; All job cards</a><div>' . status_badge($j['status']) . ' ' . $statusForm . ' ' . $editBtn . '</div></div>'
        . '<h2 class="detail-name">' . jc_no($j['id']) . '</h2>'
        . $meta
        . '<h3 class="section-title">Faults &amp; assigned mechanics</h3>' . $faultRows . $faultAdd
        . '<div class="cols">'
        . '<section><h3 class="section-title">Labour / services</h3>' . $svcRows . $svcAdd . '</section>'
        . '<section><h3 class="section-title">Parts used</h3>' . $partRows . $partAdd . '</section>'
        . '</div>'
        . '<div class="totals-wrap">' . $totalsCard . '</div>';
    echo layout(jc_no($j['id']), $content, 'jobcards');
}

function load_job_or_404($id): array
{
    $j = one('SELECT * FROM job_cards WHERE id = ?', [$id]);
    if (!$j) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Job card not found.</p></div>'); exit; }
    return $j;
}

function jobcards_status($id): void
{
    require_login();
    $j = load_job_or_404($id);
    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) { http_response_code(403); exit('Forbidden'); }
    $status = input('status');
    if (!in_array($status, ['open','in_progress','completed','closed'], true)) redirect('/jobcards/' . $id);
    $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($status === 'completed' && !$j['actual_completion']) $data['actual_completion'] = date('Y-m-d');
    update('job_cards', $data, 'id = :id', ['id' => $id]);
    audit('status', 'job_card', (int)$id, $status);
    flash('success', 'Status updated to ' . ucwords(str_replace('_', ' ', $status)) . '.');
    redirect('/jobcards/' . $id);
}

/* ---------- faults ---------- */

function jobcards_add_fault($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    $desc = input('fault_desc');
    if ($desc === '') { flash('error', 'Describe the fault before adding it.'); redirect('/jobcards/' . $id); }
    $mechanicId = input('mechanic_id') ? (int)input('mechanic_id') : null;
    insert('job_faults', ['job_card_id' => (int)$id, 'fault_desc' => $desc, 'mechanic_id' => $mechanicId]);
    audit('add_fault', 'job_card', (int)$id, $desc);
    flash('success', 'Fault added.');
    redirect('/jobcards/' . $id);
}

function jobcards_edit_fault($id, $faultId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    $f = one('SELECT * FROM job_faults WHERE id = ? AND job_card_id = ?', [$faultId, $id]);
    if (!$f) redirect('/jobcards/' . $id);
    $mopts = mechanic_select_options();
    $fields = field('Fault', 'fault_desc', ['type' => 'textarea', 'value' => $f['fault_desc'], 'required' => true])
            . field('Assigned mechanic', 'mechanic_id', ['type' => 'select', 'options' => $mopts, 'value' => $f['mechanic_id']]);
    $content = form_card('Edit fault', "/jobcards/{$id}/fault/{$faultId}", $fields, 'Save', "/jobcards/{$id}");
    echo layout('Edit fault', $content, 'jobcards');
}

function jobcards_update_fault($id, $faultId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    if (!one('SELECT id FROM job_faults WHERE id = ? AND job_card_id = ?', [$faultId, $id])) redirect('/jobcards/' . $id);
    $desc = input('fault_desc');
    if ($desc === '') { flash('error', 'Describe the fault before saving.'); redirect("/jobcards/{$id}/fault/{$faultId}/edit"); }
    update('job_faults', [
        'fault_desc' => $desc,
        'mechanic_id' => input('mechanic_id') ? (int)input('mechanic_id') : null,
    ], 'id = :id', ['id' => $faultId]);
    audit('update_fault', 'job_card', (int)$id);
    flash('success', 'Fault updated.');
    redirect('/jobcards/' . $id);
}

function jobcards_delete_fault($id, $faultId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    q('DELETE FROM job_faults WHERE id = ? AND job_card_id = ?', [$faultId, $id]);
    audit('delete_fault', 'job_card', (int)$id);
    flash('success', 'Fault removed.');
    redirect('/jobcards/' . $id);
}

/* ---------- services (labour) ---------- */

function jobcards_add_service($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }
    $serviceId = input('service_id') ? (int)input('service_id') : null;
    $charge = (float)input('charge');
    $name = null;
    if ($serviceId) {
        $s = one('SELECT * FROM services WHERE id = ?', [$serviceId]);
        if ($s && $charge <= 0) $charge = (float)$s['labour_charge'];
        $name = $s['name'] ?? null;
    }
    if (!$serviceId && $charge <= 0) { flash('error', 'Select a service or enter a charge.'); redirect('/jobcards/' . $id); }
    insert('job_services', ['job_card_id' => (int)$id, 'service_id' => $serviceId, 'description' => $name, 'charge' => $charge]);
    audit('add_service', 'job_card', (int)$id);
    flash('success', 'Labour item added.');
    redirect('/jobcards/' . $id);
}

function jobcards_edit_service($id, $svcId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    $s = one('SELECT * FROM job_services WHERE id = ? AND job_card_id = ?', [$svcId, $id]);
    if (!$s) redirect('/jobcards/' . $id);
    $fields = field('Description', 'description', ['value' => $s['description'] ?? '', 'required' => true])
            . field('Charge (GH₵)', 'charge', ['type' => 'number', 'value' => $s['charge'], 'required' => true]);
    $content = form_card('Edit labour item', "/jobcards/{$id}/service/{$svcId}", $fields, 'Save', "/jobcards/{$id}");
    echo layout('Edit labour item', $content, 'jobcards');
}

function jobcards_update_service($id, $svcId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }
    if (!one('SELECT id FROM job_services WHERE id = ? AND job_card_id = ?', [$svcId, $id])) redirect('/jobcards/' . $id);
    $charge = (float)input('charge');
    if ($charge < 0) { flash('error', 'Charge cannot be negative.'); redirect("/jobcards/{$id}/service/{$svcId}/edit"); }
    update('job_services', ['description' => input('description'), 'charge' => $charge], 'id = :id', ['id' => $svcId]);
    audit('update_service', 'job_card', (int)$id);
    flash('success', 'Labour item updated.');
    redirect('/jobcards/' . $id);
}

function jobcards_delete_service($id, $svcId): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }
    q('DELETE FROM job_services WHERE id = ? AND job_card_id = ?', [$svcId, $id]);
    audit('delete_service', 'job_card', (int)$id);
    flash('success', 'Labour item removed.');
    redirect('/jobcards/' . $id);
}

/* ---------- parts ---------- */

function jobcards_add_part($id): void
{
    require_login();
    $j = load_job_or_404($id);
    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) { http_response_code(403); exit('Forbidden'); }
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }

    $partId = (int)input('spare_part_id');
    $qty = max(1, (int)input('quantity'));
    $part = one('SELECT * FROM spare_parts WHERE id = ?', [$partId]);
    if (!$part) { flash('error', 'Select a valid part.'); redirect('/jobcards/' . $id); }
    if ((int)$part['quantity'] < $qty) { flash('error', 'Not enough stock for ' . $part['name'] . ' (only ' . (int)$part['quantity'] . ' left).'); redirect('/jobcards/' . $id); }

    // transaction: record usage + decrement stock atomically
    db()->beginTransaction();
    try {
        insert('job_parts', ['job_card_id' => (int)$id, 'spare_part_id' => $partId, 'quantity' => $qty, 'unit_price' => (float)$part['unit_price']]);
        q('UPDATE spare_parts SET quantity = quantity - ? WHERE id = ?', [$qty, $partId]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('error', 'Could not add part. Please retry.');
        redirect('/jobcards/' . $id);
    }
    audit('add_part', 'job_card', (int)$id, $part['name'] . ' x' . $qty);
    flash('success', 'Part added and stock updated.');
    redirect('/jobcards/' . $id);
}

function jobcards_edit_part($id, $partRowId): void
{
    require_login();
    $j = load_job_or_404($id);
    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) { http_response_code(403); exit('Forbidden'); }
    $row = one('SELECT jp.*, p.name AS part_name, p.quantity AS stock FROM job_parts jp JOIN spare_parts p ON p.id=jp.spare_part_id WHERE jp.id = ? AND jp.job_card_id = ?', [$partRowId, $id]);
    if (!$row) redirect('/jobcards/' . $id);
    $available = (int)$row['stock'] + (int)$row['quantity']; // stock as if this usage were released
    $fields = '<p class="muted small">' . e($row['part_name']) . ' — up to ' . $available . ' available (including the ' . (int)$row['quantity'] . ' already on this job).</p>'
            . field('Quantity', 'quantity', ['type' => 'number', 'value' => $row['quantity'], 'required' => true])
            . field('Unit price (GH₵)', 'unit_price', ['type' => 'number', 'value' => $row['unit_price'], 'required' => true]);
    $content = form_card('Edit part usage', "/jobcards/{$id}/part/{$partRowId}", $fields, 'Save', "/jobcards/{$id}");
    echo layout('Edit part usage', $content, 'jobcards');
}

function jobcards_update_part($id, $partRowId): void
{
    require_login();
    $j = load_job_or_404($id);
    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) { http_response_code(403); exit('Forbidden'); }
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }
    $row = one('SELECT jp.*, p.quantity AS stock FROM job_parts jp JOIN spare_parts p ON p.id=jp.spare_part_id WHERE jp.id = ? AND jp.job_card_id = ?', [$partRowId, $id]);
    if (!$row) redirect('/jobcards/' . $id);

    $newQty = max(1, (int)input('quantity'));
    $newPrice = (float)input('unit_price');
    $available = (int)$row['stock'] + (int)$row['quantity'];
    if ($newQty > $available) { flash('error', 'Only ' . $available . ' in stock for that part.'); redirect("/jobcards/{$id}/part/{$partRowId}/edit"); }

    db()->beginTransaction();
    try {
        $delta = $newQty - (int)$row['quantity']; // positive = more stock consumed
        q('UPDATE spare_parts SET quantity = quantity - ? WHERE id = ?', [$delta, $row['spare_part_id']]);
        update('job_parts', ['quantity' => $newQty, 'unit_price' => $newPrice], 'id = :id', ['id' => $partRowId]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('error', 'Could not update part. Please retry.');
        redirect('/jobcards/' . $id);
    }
    audit('update_part', 'job_card', (int)$id);
    flash('success', 'Part usage updated.');
    redirect('/jobcards/' . $id);
}

function jobcards_delete_part($id, $partRowId): void
{
    require_login();
    $j = load_job_or_404($id);
    $u = current_user();
    if ($u['role'] === 'mechanic' && !mechanic_on_job((int)$id, (int)$u['id'])) { http_response_code(403); exit('Forbidden'); }
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) { flash('error', 'Invoice already generated; job is locked.'); redirect('/jobcards/' . $id); }
    $row = one('SELECT * FROM job_parts WHERE id = ? AND job_card_id = ?', [$partRowId, $id]);
    if (!$row) redirect('/jobcards/' . $id);

    db()->beginTransaction();
    try {
        q('UPDATE spare_parts SET quantity = quantity + ? WHERE id = ?', [(int)$row['quantity'], $row['spare_part_id']]);
        q('DELETE FROM job_parts WHERE id = ?', [$partRowId]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        flash('error', 'Could not remove part. Please retry.');
        redirect('/jobcards/' . $id);
    }
    audit('delete_part', 'job_card', (int)$id);
    flash('success', 'Part removed and stock restored.');
    redirect('/jobcards/' . $id);
}

function jobcards_invoice($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    load_job_or_404($id);
    if (one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])) redirect('/invoices/' . one('SELECT id FROM invoices WHERE job_card_id = ?', [$id])['id']);

    $labour = (float) scalar('SELECT COALESCE(SUM(charge),0) FROM job_services WHERE job_card_id = ?', [$id]);
    $partsT = (float) scalar('SELECT COALESCE(SUM(unit_price*quantity),0) FROM job_parts WHERE job_card_id = ?', [$id]);
    $subtotal = $labour + $partsT;
    if ($subtotal <= 0) { flash('error', 'Add at least one service or part before invoicing.'); redirect('/jobcards/' . $id); }
    $tax = calc_tax($subtotal);

    ensure_invoice_tax_columns();

    $no = 'INV-' . date('Ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    try {
        $invId = insert('invoices', [
            'job_card_id' => (int)$id, 'invoice_no' => $no,
            'labour_total' => $labour, 'parts_total' => $partsT,
            'subtotal' => $subtotal,
            'vat_rate' => $tax['vat_rate'], 'nhil_rate' => $tax['nhil_rate'], 'getfund_rate' => $tax['getfund_rate'],
            'vat_amount' => $tax['vat_amount'], 'nhil_amount' => $tax['nhil_amount'], 'getfund_amount' => $tax['getfund_amount'],
            'total' => $tax['total'], 'balance' => $tax['total'], 'status' => 'unpaid',
        ]);
    } catch (Throwable $ex) {
        audit('invoice_failed', 'job_card', (int)$id, get_class($ex) . ': ' . $ex->getMessage());
        flash('error', 'Could not generate the invoice (' . $ex->getMessage() . '). This has been logged — please try again, and contact support if it keeps happening.');
        redirect('/jobcards/' . $id);
    }
    update('job_cards', ['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    audit('invoice', 'invoice', $invId, $no);
    flash('success', 'Invoice ' . $no . ' generated.');
    redirect('/invoices/' . $invId);
}
