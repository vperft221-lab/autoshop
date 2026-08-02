<?php
/** Dashboard + Reports */

function dashboard(): void
{
    require_login();
    $u = current_user();

    if ($u['role'] === 'mechanic') {
        mechanic_dashboard($u);
        return;
    }

    $openJobs   = (int) scalar("SELECT COUNT(*) FROM job_cards WHERE status IN ('open','in_progress')");
    $custCount  = (int) scalar("SELECT COUNT(*) FROM customers");
    $vehCount   = (int) scalar("SELECT COUNT(*) FROM vehicles");
    $unpaid     = (float) scalar("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE status != 'paid'");

    $recent = all(
        "SELECT j.id, j.status, j.created_at, v.reg_number, c.name AS customer,
                m.name AS mechanic
         FROM job_cards j
         JOIN vehicles v ON v.id = j.vehicle_id
         JOIN customers c ON c.id = v.customer_id
         LEFT JOIN users m ON m.id = j.mechanic_id
         ORDER BY j.id DESC LIMIT 6"
    );
    $lowStock = all("SELECT name, quantity, reorder_level FROM spare_parts WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 6");

    $cards = stat_card('Open job cards', (string)$openJobs, 'wrench', 'blue')
           . stat_card('Customers', (string)$custCount, 'users', 'green')
           . stat_card('Vehicles', (string)$vehCount, 'car', 'amber')
           . stat_card('Outstanding', money($unpaid), 'receipt', 'red');

    $jobRows = data_table([
        '#'        => fn($r) => '<a class="link" href="/jobcards/' . (int)$r['id'] . '">JC-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) . '</a>',
        'Customer' => fn($r) => e($r['customer']),
        'Vehicle'  => fn($r) => e($r['reg_number']),
        'Mechanic' => fn($r) => e($r['mechanic'] ?? '—'),
        'Status'   => fn($r) => status_badge($r['status']),
        'Created'  => fn($r) => d($r['created_at']),
    ], $recent, 'No job cards yet.');

    $lowRows = $lowStock
        ? data_table([
            'Part'     => fn($r) => e($r['name']),
            'In stock' => fn($r) => '<strong>' . (int)$r['quantity'] . '</strong>',
            'Reorder ≤'=> fn($r) => (int)$r['reorder_level'],
          ], $lowStock)
        : '<div class="card ok-note">' . icon('box') . '<p>All parts are above their reorder levels.</p></div>';

    $name = e($u['name']);
    $content = <<<HTML
<div class="welcome"><h2>Hello, {$name} 👋</h2><p class="muted">Here's what's happening in the workshop today.</p></div>
<div class="stat-grid">{$cards}</div>
<div class="cols">
  <section><h3 class="section-title">Recent job cards</h3>{$jobRows}</section>
  <section><h3 class="section-title">Low-stock alerts</h3>{$lowRows}</section>
</div>
HTML;
    echo layout('Dashboard', $content, 'dashboard');
}

/**
 * Mechanic-facing dashboard: shows the signed-in mechanic's own open jobs and
 * past service history rather than shop-wide customer/vehicle/financial counts.
 * Any mechanic can also switch to view another mechanic's assigned jobs.
 */
function mechanic_dashboard(array $u): void
{
    $mechanics = all("SELECT id, name, specialization FROM users WHERE role IN ('mechanic','manager') AND active=1 ORDER BY name");
    $validIds = array_map(fn($m) => (int)$m['id'], $mechanics);

    $viewId = input('view_mechanic') ? (int)input('view_mechanic') : (int)$u['id'];
    if (!in_array($viewId, $validIds, true)) $viewId = (int)$u['id'];
    $viewingSelf = $viewId === (int)$u['id'];
    $viewedName = $viewingSelf ? $u['name'] : (one('SELECT name FROM users WHERE id = ?', [$viewId])['name'] ?? $u['name']);

    $openCount = (int) scalar(
        "SELECT COUNT(*) FROM job_cards j WHERE j.status IN ('open','in_progress')
         AND (j.mechanic_id = ? OR j.id IN (SELECT job_card_id FROM job_faults WHERE mechanic_id = ?))",
        [$viewId, $viewId]
    );
    $historyCount = (int) scalar(
        "SELECT COUNT(*) FROM job_cards j WHERE j.status IN ('completed','closed')
         AND (j.mechanic_id = ? OR j.id IN (SELECT job_card_id FROM job_faults WHERE mechanic_id = ?))",
        [$viewId, $viewId]
    );

    $cards = stat_card($viewingSelf ? 'My open job cards' : e($viewedName) . '’s open jobs', (string)$openCount, 'wrench', 'blue')
           . stat_card($viewingSelf ? 'My past service history' : e($viewedName) . '’s past service history', (string)$historyCount, 'log', 'green');

    $jobs = all(
        "SELECT j.*, v.reg_number, c.name AS customer
         FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id JOIN customers c ON c.id = v.customer_id
         WHERE (j.mechanic_id = ? OR j.id IN (SELECT job_card_id FROM job_faults WHERE mechanic_id = ?))
         ORDER BY j.id DESC LIMIT 12",
        [$viewId, $viewId]
    );
    $jobRows = data_table([
        '#'        => fn($r) => '<a class="link" href="/jobcards/' . (int)$r['id'] . '">JC-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) . '</a>',
        'Customer' => fn($r) => e($r['customer']),
        'Vehicle'  => fn($r) => e($r['reg_number']),
        'Status'   => fn($r) => status_badge($r['status']),
        'Created'  => fn($r) => d($r['created_at']),
    ], $jobs, $viewingSelf ? 'No job cards assigned to you yet.' : 'No job cards assigned to this mechanic yet.');

    $mopts = '';
    foreach ($mechanics as $m) {
        $sel = (int)$m['id'] === $viewId ? ' selected' : '';
        $label = $m['name'] . ((int)$m['id'] === (int)$u['id'] ? ' (Me)' : '');
        $mopts .= '<option value="' . (int)$m['id'] . '"' . $sel . '>' . e($label) . '</option>';
    }
    $switcher = '<form method="get" action="/" class="toolbar">'
        . '<label class="muted small" style="margin-right:8px">Viewing jobs for</label>'
        . '<select name="view_mechanic" onchange="this.form.submit()">' . $mopts . '</select>'
        . '</form>';

    $name = e($u['name']);
    $heading = $viewingSelf ? "Hello, {$name} 👋" : 'Viewing ' . e($viewedName) . '’s jobs';
    $sub = $viewingSelf ? "Here's what's assigned to you today." : "You're viewing another mechanic's workload.";
    $sectionTitle = $viewingSelf ? 'My job cards' : e($viewedName) . '’s job cards';

    $content = <<<HTML
<div class="welcome"><h2>{$heading}</h2><p class="muted">{$sub}</p></div>
{$switcher}
<div class="stat-grid">{$cards}</div>
<h3 class="section-title">{$sectionTitle}</h3>
{$jobRows}
HTML;
    echo layout('Dashboard', $content, 'dashboard');
}

function reports(): void
{
    require_role(['admin', 'manager']);

    $byStatus = all("SELECT status, COUNT(*) c FROM job_cards GROUP BY status");
    $statusMap = [];
    foreach ($byStatus as $r) $statusMap[$r['status']] = (int)$r['c'];

    // Date queries — compatible with both MySQL and SQLite
    $driver = config()['db_driver'];
    if ($driver === 'mysql') {
        $revToday = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(paid_at) = CURDATE()");
        $revMonth = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE_FORMAT(paid_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')");
    } else {
        $revToday = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE date(paid_at) = date('now')");
        $revMonth = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE strftime('%Y-%m', paid_at) = strftime('%Y-%m','now')");
    }

    $revTotal    = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments");
    $invoiced    = (float) scalar("SELECT COALESCE(SUM(total),0) FROM invoices");
    $outstanding = (float) scalar("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE status != 'paid'");

    $topParts = all("SELECT p.name, COALESCE(SUM(jp.quantity),0) used
                     FROM job_parts jp JOIN spare_parts p ON p.id = jp.spare_part_id
                     GROUP BY p.id ORDER BY used DESC LIMIT 5");

    $cards = stat_card('Revenue today',      money($revToday),    'chart',   'green')
           . stat_card('Revenue this month', money($revMonth),    'chart',   'blue')
           . stat_card('Total invoiced',     money($invoiced),    'receipt', 'amber')
           . stat_card('Outstanding',        money($outstanding), 'receipt', 'red');

    // Simple bar chart for job status
    $maxBar = max(1, ...array_values($statusMap ?: [1]));
    $bars = '';
    foreach (['open'=>'blue','in_progress'=>'amber','completed'=>'green','closed'=>'gray'] as $s=>$tone) {
        $val = $statusMap[$s] ?? 0;
        $w = (int) round(($val / $maxBar) * 100);
        $bars .= "<div class=\"bar-row\"><span class=\"bar-lbl\">" . ucwords(str_replace('_',' ',$s)) . "</span><div class=\"bar-track\"><div class=\"bar-fill bar-{$tone}\" style=\"width:{$w}%\"></div></div><span class=\"bar-val\">{$val}</span></div>";
    }

    $partRows = data_table([
        'Part'       => fn($r) => e($r['name']),
        'Units used' => fn($r) => (int)$r['used'],
    ], $topParts, 'No parts used yet.');

    $content = <<<HTML
<div class="stat-grid">{$cards}</div>
<div class="cols">
  <section><h3 class="section-title">Job cards by status</h3><div class="card"><div class="bars">{$bars}</div></div></section>
  <section><h3 class="section-title">Most-used spare parts</h3>{$partRows}</section>
</div>
<p class="muted small">Lifetime revenue collected: <strong>{money_total}</strong></p>
HTML;
    $content = str_replace('{money_total}', money($revTotal), $content);
    echo layout('Reports', $content, 'reports');
}
