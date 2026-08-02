<?php
/** Invoices & payments */

function invoices_index(): void
{
    require_role(['admin', 'manager', 'receptionist']);

    $customerId = input('customer_id') ? (int)input('customer_id') : null;

    $sql = "SELECT i.*, c.id AS cust_id, c.name AS customer, v.reg_number
            FROM invoices i JOIN job_cards j ON j.id=i.job_card_id
            JOIN vehicles v ON v.id=j.vehicle_id JOIN customers c ON c.id=v.customer_id";
    $params = [];
    if ($customerId) {
        $sql .= " WHERE c.id = ?";
        $params[] = $customerId;
    }
    $sql .= " ORDER BY i.id DESC";
    $rows = all($sql, $params);

    $table = data_table([
        'Invoice'  => fn($r) => '<a class="link" href="/invoices/' . (int)$r['id'] . '">' . e($r['invoice_no']) . '</a>',
        'Customer' => fn($r) => '<a class="link" href="/customers/' . (int)$r['cust_id'] . '">' . e($r['customer']) . '</a>',
        'Vehicle'  => fn($r) => e($r['reg_number']),
        'Total'    => fn($r) => money($r['total']),
        'Balance'  => fn($r) => money($r['balance']),
        'Status'   => fn($r) => status_badge($r['status']),
        'Date'     => fn($r) => d($r['created_at']),
    ], $rows, $customerId ? 'This customer has no invoices yet.' : 'No invoices yet.');

    $customers = all('SELECT id, name FROM customers ORDER BY name');
    $opts = '<option value="">— All customers —</option>';
    foreach ($customers as $c) {
        $sel = $customerId === (int)$c['id'] ? ' selected' : '';
        $opts .= '<option value="' . (int)$c['id'] . '"' . $sel . '>' . e($c['name']) . '</option>';
    }
    $filterForm = '<form method="get" action="/invoices" class="toolbar">'
        . '<select name="customer_id" onchange="this.form.submit()">' . $opts . '</select>'
        . ($customerId ? ' <a class="btn btn-ghost" href="/invoices">Clear filter</a>' : '')
        . '</form>';

    echo layout('Invoices', $filterForm . $table, 'invoices');
}

function invoices_show($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $inv = one("SELECT i.*, j.id AS job_id, c.name AS customer, c.phone, c.email, c.address, v.reg_number, v.make, v.model
                FROM invoices i JOIN job_cards j ON j.id=i.job_card_id
                JOIN vehicles v ON v.id=j.vehicle_id JOIN customers c ON c.id=v.customer_id WHERE i.id = ?", [$id]);
    if (!$inv) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Invoice not found.</p></div>'); return; }

    $services = all('SELECT js.*, s.name AS service_name FROM job_services js LEFT JOIN services s ON s.id=js.service_id WHERE js.job_card_id = ?', [$inv['job_id']]);
    $parts    = all('SELECT jp.*, p.name AS part_name FROM job_parts jp LEFT JOIN spare_parts p ON p.id=jp.spare_part_id WHERE jp.job_card_id = ?', [$inv['job_id']]);
    $payments = all('SELECT pm.*, u.name AS cashier FROM payments pm LEFT JOIN users u ON u.id=pm.received_by WHERE pm.invoice_id = ? ORDER BY pm.id', [$id]);

    $lines = '';
    foreach ($services as $s) $lines .= '<tr><td>' . e($s['service_name'] ?? $s['description'] ?? 'Labour') . '</td><td class="r">1</td><td class="r">' . money($s['charge']) . '</td><td class="r">' . money($s['charge']) . '</td></tr>';
    foreach ($parts as $p) { $lt = (float)$p['unit_price'] * (int)$p['quantity']; $lines .= '<tr><td>' . e($p['part_name']) . ' <small class="muted">(part)</small></td><td class="r">' . (int)$p['quantity'] . '</td><td class="r">' . money($p['unit_price']) . '</td><td class="r">' . money($lt) . '</td></tr>'; }

    $payRows = '';
    foreach ($payments as $pm) $payRows .= '<div class="t-row"><span>' . d($pm['paid_at']) . ' · ' . e(ucfirst($pm['method'])) . ' <small class="muted">' . e($pm['cashier'] ?? '') . '</small></span><strong>' . money($pm['amount']) . '</strong></div>';

    $payForm = '';
    if ($inv['status'] !== 'paid') {
        $payForm = '<form method="post" action="/invoices/' . $id . '/pay" class="pay-form">' . csrf_field()
            . '<div class="grid-2">'
            . field('Amount (GH₵)', 'amount', ['type' => 'number', 'value' => $inv['balance'], 'required' => true])
            . field('Method', 'method', ['type' => 'select', 'options' => ['cash'=>'Cash','momo'=>'Mobile Money','card'=>'Card','bank'=>'Bank transfer']])
            . '</div><button class="btn btn-primary btn-block">Record payment</button></form>';
    } else {
        $payForm = '<div class="paid-stamp">✓ Fully paid</div>';
    }

    $brand = e(config('app_name'));
    $content = '<div class="toolbar"><a class="btn btn-ghost" href="/invoices">&larr; All invoices</a>'
        . '<button class="btn btn-ghost js-print">🖨 Print</button></div>'
        . '<div class="invoice-sheet card">'
        . '<div class="inv-head"><div><div class="inv-brand">' . $brand . '</div><div class="muted">Auto Repair &amp; Service</div></div>'
        . '<div class="inv-no"><div class="inv-title">INVOICE</div><div>' . e($inv['invoice_no']) . '</div><div class="muted">' . d($inv['created_at']) . '</div></div></div>'
        . '<div class="inv-parties"><div><span class="lbl">Bill to</span><strong>' . e($inv['customer']) . '</strong><br>' . e($inv['phone'] ?: '') . '<br>' . e($inv['address'] ?: '') . '</div>'
        . '<div><span class="lbl">Vehicle</span><strong>' . e($inv['reg_number']) . '</strong><br>' . e(trim(($inv['make'] ?? '') . ' ' . ($inv['model'] ?? ''))) . '</div></div>'
        . '<table class="table inv-table"><thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit</th><th class="r">Amount</th></tr></thead><tbody>' . $lines . '</tbody></table>'
        . '<div class="inv-totals">'
        . '<div class="t-row"><span>Labour</span><strong>' . money($inv['labour_total']) . '</strong></div>'
        . '<div class="t-row"><span>Parts</span><strong>' . money($inv['parts_total']) . '</strong></div>'
        . '<div class="t-row"><span>Subtotal</span><strong>' . money($inv['subtotal'] ?? ($inv['labour_total'] + $inv['parts_total'])) . '</strong></div>'
        . '<div class="t-row"><span>VAT (' . e($inv['vat_rate'] ?? 0) . '%)</span><strong>' . money($inv['vat_amount'] ?? 0) . '</strong></div>'
        . '<div class="t-row"><span>NHIL (' . e($inv['nhil_rate'] ?? 0) . '%)</span><strong>' . money($inv['nhil_amount'] ?? 0) . '</strong></div>'
        . '<div class="t-row"><span>GETFund (' . e($inv['getfund_rate'] ?? 0) . '%)</span><strong>' . money($inv['getfund_amount'] ?? 0) . '</strong></div>'
        . '<div class="t-row grand"><span>Total (incl. tax)</span><strong>' . money($inv['total']) . '</strong></div>'
        . '<div class="t-row"><span>Paid</span><strong>' . money($inv['total'] - $inv['balance']) . '</strong></div>'
        . '<div class="t-row grand"><span>Balance</span><strong>' . money($inv['balance']) . '</strong></div>'
        . '</div></div>'
        . '<div class="cols"><section><h3 class="section-title">Record payment</h3><div class="card">' . $payForm . '</div></section>'
        . '<section><h3 class="section-title">Payment history</h3><div class="card totals">' . ($payRows ?: '<p class="muted">No payments recorded.</p>') . '</div></section></div>';
    echo layout('Invoice ' . $inv['invoice_no'], $content, 'invoices');
}

function invoices_pay($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $inv = one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) redirect('/invoices');
    $amount = round((float)input('amount'), 2);
    if ($amount <= 0) { flash('error', 'Enter a valid amount.'); redirect('/invoices/' . $id); }
    if ($amount > (float)$inv['balance'] + 0.001) { flash('error', 'Amount exceeds the outstanding balance.'); redirect('/invoices/' . $id); }
    $method = in_array(input('method'), ['cash','momo','card','bank'], true) ? input('method') : 'cash';

    db()->beginTransaction();
    try {
        insert('payments', ['invoice_id' => (int)$id, 'amount' => $amount, 'method' => $method, 'received_by' => current_user()['id']]);
        $balance = round((float)$inv['balance'] - $amount, 2);
        $status = $balance <= 0.001 ? 'paid' : 'partial';
        update('invoices', ['balance' => $balance, 'status' => $status], 'id = :id', ['id' => $id]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', 'Payment failed, please retry.');
        redirect('/invoices/' . $id);
    }
    audit('payment', 'invoice', (int)$id, money($amount) . ' (' . $method . ')');
    flash('success', 'Payment of ' . money($amount) . ' recorded.');
    redirect('/invoices/' . $id);
}
