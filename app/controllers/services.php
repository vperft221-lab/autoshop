<?php
/** Services catalogue */

function services_index(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $rows = all('SELECT * FROM services ORDER BY name');
    $isAdmin = can('admin');
    $table = data_table([
        'Service'      => fn($r) => '<strong>' . e($r['name']) . '</strong>',
        'Labour charge'=> fn($r) => money($r['labour_charge']),
        'Status'       => fn($r) => $r['active'] ? badge('Active', 'green') : badge('Inactive', 'gray'),
        ''             => function ($r) use ($isAdmin) {
            $edit = '<a class="link" href="/services/' . (int)$r['id'] . '/edit">Edit</a>';
            if (!$isAdmin) return $edit;
            $del = '<form method="post" action="/services/' . (int)$r['id'] . '/delete" class="inline-form">' . csrf_field()
                . '<button class="link" data-confirm="Delete this service permanently?">Delete</button></form>';
            return $edit . ' · ' . $del;
        },
    ], $rows, 'No services defined yet.');
    $content = '<div class="toolbar"><div></div>' . btn('+ Add service', '/services/new') . '</div>' . $table;
    echo layout('Services', $content, 'services');
}

function services_form($id = null): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $s = $id ? one('SELECT * FROM services WHERE id = ?', [$id]) : [];
    if ($id && !$s) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Service not found.</p></div>'); return; }
    $action = $id ? "/services/{$id}" : '/services';
    $fields = field('Service name', 'name', ['value' => $s['name'] ?? '', 'required' => true, 'placeholder' => 'Engine oil change'])
            . field('Labour charge (GH₵)', 'labour_charge', ['type' => 'number', 'value' => $s['labour_charge'] ?? '0', 'required' => true])
            . field('Status', 'active', ['type' => 'select', 'options' => ['1' => 'Active', '0' => 'Inactive'], 'value' => $s['active'] ?? '1']);
    $content = form_card($id ? 'Edit service' : 'Add service', $action, $fields, $id ? 'Update' : 'Add', '/services');
    echo layout($id ? 'Edit service' : 'Add service', $content, 'services');
}

function services_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['name' => 'required', 'labour_charge' => 'numeric'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/services/new'); }
    $id = insert('services', ['name' => input('name'), 'labour_charge' => (float)input('labour_charge'), 'active' => (int)input('active', '1')]);
    audit('create', 'service', $id, input('name'));
    flash('success', 'Service added.');
    redirect('/services');
}

function services_update($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    if (!one('SELECT id FROM services WHERE id = ?', [$id])) redirect('/services');
    update('services', ['name' => input('name'), 'labour_charge' => (float)input('labour_charge'), 'active' => (int)input('active', '1')], 'id = :id', ['id' => $id]);
    audit('update', 'service', (int)$id);
    flash('success', 'Service updated.');
    redirect('/services');
}

function services_delete($id): void
{
    require_role(['admin']);
    if (!one('SELECT id FROM services WHERE id = ?', [$id])) redirect('/services');
    q('DELETE FROM services WHERE id = ?', [$id]);
    audit('delete', 'service', (int)$id);
    flash('success', 'Service deleted.');
    redirect('/services');
}
