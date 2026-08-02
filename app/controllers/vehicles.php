<?php
/** Vehicles */

function vehicles_index(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $rows = all("SELECT v.*, c.name AS customer FROM vehicles v JOIN customers c ON c.id = v.customer_id ORDER BY v.id DESC");
    $table = data_table([
        'Reg. number' => fn($r) => '<strong>' . e($r['reg_number']) . '</strong>',
        'Customer'    => fn($r) => '<a class="link" href="/customers/' . (int)$r['customer_id'] . '">' . e($r['customer']) . '</a>',
        'Make / model'=> fn($r) => e(trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—'),
        'Year'        => fn($r) => e($r['year'] ?: '—'),
        'Colour'      => fn($r) => e($r['color'] ?: '—'),
        ''            => fn($r) => '<a class="link" href="/vehicles/' . (int)$r['id'] . '/edit">Edit</a>',
    ], $rows, 'No vehicles registered.');
    $content = '<div class="toolbar"><div></div>' . btn('+ Register vehicle', '/vehicles/new') . '</div>' . $table;
    echo layout('Vehicles', $content, 'vehicles');
}

function vehicles_form($id = null): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $v = $id ? one('SELECT * FROM vehicles WHERE id = ?', [$id]) : [];
    if ($id && !$v) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>Vehicle not found.</p></div>'); return; }
    $preCustomer = $v['customer_id'] ?? input('customer_id');

    $customers = all('SELECT id, name FROM customers ORDER BY name');
    $opts = ['' => '— Select customer —'];
    foreach ($customers as $c) $opts[$c['id']] = $c['name'];

    $curMake = $v['make'] ?? '';
    $makes = popular_car_makes();
    $makeOpts = ['' => '— Select make —'];
    foreach ($makes as $mk) $makeOpts[$mk] = $mk;
    // Preserve an existing make even if it isn't in the popular-makes list (e.g. legacy data).
    if ($curMake !== '' && !in_array($curMake, $makes, true)) $makeOpts[$curMake] = $curMake;
    $makeOpts['__other__'] = 'Other (type below)';

    $action = $id ? "/vehicles/{$id}" : '/vehicles';
    $fields = field('Customer', 'customer_id', ['type' => 'select', 'options' => $opts, 'value' => $preCustomer, 'required' => true])
            . field('Registration number', 'reg_number', ['value' => $v['reg_number'] ?? '', 'required' => true, 'placeholder' => 'GR-1234-24'])
            . '<div class="grid-2">'
            . field('Make', 'make', ['type' => 'select', 'options' => $makeOpts, 'value' => $curMake])
            . field('Model', 'model', ['value' => $v['model'] ?? '', 'placeholder' => 'Corolla'])
            . '</div>'
            . '<div class="form-group js-other-make" style="display:none"><label>Other make</label><input type="text" name="make_other" placeholder="Type the vehicle make"></div>'
            . '<div class="grid-2">'
            . field('Year', 'year', ['type' => 'number', 'step' => '1', 'value' => $v['year'] ?? ''])
            . field('Colour', 'color', ['value' => $v['color'] ?? ''])
            . '</div>';
    $content = form_card($id ? 'Edit vehicle' : 'Register vehicle', $action, $fields, $id ? 'Update' : 'Register', '/vehicles');
    echo layout($id ? 'Edit vehicle' : 'Register vehicle', $content, 'vehicles');
}

/** Resolve the submitted make, honouring the "Other" free-text fallback. */
function vehicle_make_input(): string
{
    $make = input('make');
    if ($make === '__other__') return input('make_other');
    return $make;
}

/** Whether another vehicle already uses this registration number (case-insensitive). */
function reg_number_taken(string $reg, ?int $excludeId = null): bool
{
    $reg = strtoupper(trim($reg));
    if ($reg === '') return false;
    if ($excludeId) {
        return (bool) scalar('SELECT 1 FROM vehicles WHERE UPPER(reg_number) = ? AND id != ?', [$reg, $excludeId]);
    }
    return (bool) scalar('SELECT 1 FROM vehicles WHERE UPPER(reg_number) = ?', [$reg]);
}

function vehicles_store(): void
{
    require_role(['admin', 'manager', 'receptionist']);
    $errors = validate(['customer_id' => 'required', 'reg_number' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/vehicles/new'); }
    $reg = strtoupper(trim(input('reg_number')));
    if (reg_number_taken($reg)) {
        flash('error', 'A vehicle with registration number "' . $reg . '" is already registered to a customer.');
        redirect('/vehicles/new?customer_id=' . (int)input('customer_id'));
    }
    try {
        $id = insert('vehicles', [
            'customer_id' => (int)input('customer_id'), 'reg_number' => $reg,
            'make' => vehicle_make_input(), 'model' => input('model'),
            'year' => input('year') ?: null, 'color' => input('color'),
        ]);
    } catch (Throwable $e) {
        flash('error', 'A vehicle with registration number "' . $reg . '" is already registered to a customer.');
        redirect('/vehicles/new?customer_id=' . (int)input('customer_id'));
    }
    audit('create', 'vehicle', $id, $reg);
    flash('success', 'Vehicle registered.');
    redirect('/customers/' . (int)input('customer_id'));
}

function vehicles_update($id): void
{
    require_role(['admin', 'manager', 'receptionist']);
    if (!one('SELECT id FROM vehicles WHERE id = ?', [$id])) redirect('/vehicles');
    $errors = validate(['customer_id' => 'required', 'reg_number' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect("/vehicles/{$id}/edit"); }
    $reg = strtoupper(trim(input('reg_number')));
    if (reg_number_taken($reg, (int)$id)) {
        flash('error', 'A vehicle with registration number "' . $reg . '" is already registered to a customer.');
        redirect("/vehicles/{$id}/edit");
    }
    try {
        update('vehicles', [
            'customer_id' => (int)input('customer_id'), 'reg_number' => $reg,
            'make' => vehicle_make_input(), 'model' => input('model'),
            'year' => input('year') ?: null, 'color' => input('color'),
        ], 'id = :id', ['id' => $id]);
    } catch (Throwable $e) {
        flash('error', 'A vehicle with registration number "' . $reg . '" is already registered to a customer.');
        redirect("/vehicles/{$id}/edit");
    }
    audit('update', 'vehicle', (int)$id);
    flash('success', 'Vehicle updated.');
    redirect('/vehicles');
}

function vehicles_delete($id): void
{
    require_role(['admin']);
    q('DELETE FROM vehicles WHERE id = ?', [$id]);
    audit('delete', 'vehicle', (int)$id);
    flash('success', 'Vehicle deleted.');
    redirect('/vehicles');
}
