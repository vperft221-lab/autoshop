<?php
/** Inventory — spare parts */

function inventory_index(): void
{
    require_login();
    $rows = all('SELECT * FROM spare_parts ORDER BY name');
    $table = data_table([
        'Part'      => fn($r) => '<strong>' . e($r['name']) . '</strong>',
        'SKU'       => fn($r) => e($r['sku'] ?: '—'),
        'In stock'  => fn($r) => ((int)$r['quantity'] <= (int)$r['reorder_level'])
                          ? '<span class="stock-low">' . (int)$r['quantity'] . '</span>'
                          : (string)(int)$r['quantity'],
        'Reorder ≤' => fn($r) => (int)$r['reorder_level'],
        'Unit price'=> fn($r) => money($r['unit_price']),
        'Status'    => fn($r) => ((int)$r['quantity'] <= (int)$r['reorder_level']) ? badge('Low stock', 'red') : badge('OK', 'green'),
        ''          => fn($r) => can('mechanic') ? '' : '<a class="link" href="/inventory/' . (int)$r['id'] . '/edit">Edit</a>',
    ], $rows, 'No spare parts yet.');
    $add = can('mechanic') ? '<div></div>' : btn('+ Add part', '/inventory/new');
    $content = '<div class="toolbar"><div></div>' . $add . '</div>' . $table;
    echo layout('Inventory', $content, 'inventory');
}

function inventory_form($id = null): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $p = $id ? one('SELECT * FROM spare_parts WHERE id = ?', [$id]) : [];
    if ($id && !$p) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Part not found.</p></div>'); return; }
    $action = $id ? "/inventory/{$id}" : '/inventory';
    $fields = field('Part name', 'name', ['value' => $p['name'] ?? '', 'required' => true, 'placeholder' => 'Brake pad set'])
            . field('SKU / code', 'sku', ['value' => $p['sku'] ?? ''])
            . '<div class="grid-2">'
            . field('Quantity in stock', 'quantity', ['type' => 'number', 'step' => '1', 'value' => $p['quantity'] ?? '0', 'required' => true])
            . field('Reorder level', 'reorder_level', ['type' => 'number', 'step' => '1', 'value' => $p['reorder_level'] ?? '5'])
            . '</div>'
            . field('Unit price (GH₵)', 'unit_price', ['type' => 'number', 'value' => $p['unit_price'] ?? '0', 'required' => true]);
    $content = form_card($id ? 'Edit part' : 'Add spare part', $action, $fields, $id ? 'Update' : 'Add', '/inventory');
    echo layout($id ? 'Edit part' : 'Add part', $content, 'inventory');
}

function inventory_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['name' => 'required', 'quantity' => 'numeric', 'unit_price' => 'numeric'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/inventory/new'); }
    $id = insert('spare_parts', [
        'name' => input('name'), 'sku' => input('sku'),
        'quantity' => (int)input('quantity'), 'reorder_level' => (int)input('reorder_level', '5'),
        'unit_price' => (float)input('unit_price'),
    ]);
    audit('create', 'spare_part', $id, input('name'));
    flash('success', 'Spare part added.');
    redirect('/inventory');
}

function inventory_update($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    if (!one('SELECT id FROM spare_parts WHERE id = ?', [$id])) redirect('/inventory');
    update('spare_parts', [
        'name' => input('name'), 'sku' => input('sku'),
        'quantity' => (int)input('quantity'), 'reorder_level' => (int)input('reorder_level', '5'),
        'unit_price' => (float)input('unit_price'),
    ], 'id = :id', ['id' => $id]);
    audit('update', 'spare_part', (int)$id);
    flash('success', 'Spare part updated.');
    redirect('/inventory');
}
