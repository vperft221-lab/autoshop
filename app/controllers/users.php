<?php
/** User management (admin only) */

function role_badge_tone(string $role): string
{
    return match ($role) {
        'admin' => 'purple',
        'manager' => 'blue',
        'receptionist' => 'amber',
        default => 'gray',
    };
}

function users_index(): void
{
    require_role(['admin']);
    $rows = all('SELECT * FROM users ORDER BY id');
    $table = data_table([
        'Name'    => fn($r) => '<strong>' . e($r['name']) . '</strong>',
        'Username'=> fn($r) => e($r['username']),
        'Role'    => fn($r) => badge(ucfirst($r['role']), role_badge_tone($r['role'])),
        'Specialization' => fn($r) => e($r['specialization'] ?: '—'),
        'Status'  => fn($r) => $r['active'] ? badge('Active', 'green') : badge('Disabled', 'red'),
        'Added'   => fn($r) => d($r['created_at']),
        ''        => function ($r) {
            $edit = '<a class="link" href="/users/' . (int)$r['id'] . '/edit">Edit</a>';
            $self = (int)$r['id'] === (int)current_user()['id'];
            if ($self) return $edit;
            $label = $r['active'] ? 'Disable' : 'Enable';
            $toggle = '<form method="post" action="/users/' . (int)$r['id'] . '/toggle" class="inline-form">' . csrf_field()
                . '<button class="link" data-confirm="' . e($label) . ' this user?">' . $label . '</button></form>';
            return $edit . ' · ' . $toggle;
        },
    ], $rows, 'No users.');
    $content = '<div class="toolbar"><div></div>' . btn('+ Add user', '/users/new') . '</div>' . $table;
    echo layout('Users', $content, 'users');
}

function users_form($id = null): void
{
    require_role(['admin']);
    $usr = $id ? one('SELECT * FROM users WHERE id = ?', [$id]) : [];
    if ($id && !$usr) { http_response_code(404); echo layout('Not found', '<div class="card empty"><p>User not found.</p></div>'); return; }
    $action = $id ? "/users/{$id}" : '/users';
    $roleOpts = ['mechanic' => 'Mechanic', 'receptionist' => 'Receptionist', 'manager' => 'Manager', 'admin' => 'Administrator'];
    $pwHint = $id ? 'Leave blank to keep the current password.' : 'Minimum ' . config('password_min') . ' characters.';
    $fields = '<div class="grid-2">'
            . field('Full name', 'name', ['value' => $usr['name'] ?? '', 'required' => true])
            . field('Username', 'username', ['value' => $usr['username'] ?? '', 'required' => true, 'hint' => 'Used to sign in'])
            . '</div>'
            . field('Email', 'email', ['type' => 'email', 'value' => $usr['email'] ?? ''])
            . '<div class="grid-2">'
            . field('Role', 'role', ['type' => 'select', 'options' => $roleOpts, 'value' => $usr['role'] ?? 'mechanic'])
            . field($id ? 'New password' : 'Password', 'password', ['type' => 'password', 'required' => !$id, 'hint' => $pwHint])
            . '</div>'
            . field('Specialization', 'specialization', ['value' => $usr['specialization'] ?? '', 'placeholder' => 'e.g. Engine & transmission (mechanics only)', 'hint' => 'Shown when assigning this mechanic to a fault on a job card.']);
    $content = form_card($id ? 'Edit user' : 'Add user', $action, $fields, $id ? 'Update' : 'Create user', '/users');
    echo layout($id ? 'Edit user' : 'Add user', $content, 'users');
}

function users_store(): void
{
    require_role(['admin']);
    $errors = validate(['name' => 'required', 'username' => 'required', 'password' => 'required|min:' . config('password_min')], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect('/users/new'); }
    if (one('SELECT id FROM users WHERE username = ?', [input('username')])) { flash('error', 'That username is already taken.'); redirect('/users/new'); }
    $role = in_array(input('role'), ['admin','manager','receptionist','mechanic'], true) ? input('role') : 'mechanic';
    $id = insert('users', [
        'name' => input('name'), 'username' => input('username'), 'email' => input('email'),
        'password_hash' => hash_password((string)$_POST['password']), 'role' => $role,
        'specialization' => input('specialization') ?: null, 'active' => 1,
    ]);
    audit('create', 'user', $id, input('username'));
    flash('success', 'User created.');
    redirect('/users');
}

function users_update($id): void
{
    require_role(['admin']);
    $usr = one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$usr) redirect('/users');
    $errors = validate(['name' => 'required', 'username' => 'required'], $_POST);
    if ($errors) { flash('error', reset($errors)); redirect("/users/{$id}/edit"); }
    $dupe = one('SELECT id FROM users WHERE username = ? AND id != ?', [input('username'), $id]);
    if ($dupe) { flash('error', 'That username is already taken.'); redirect("/users/{$id}/edit"); }

    $role = in_array(input('role'), ['admin','manager','receptionist','mechanic'], true) ? input('role') : $usr['role'];
    // Prevent an admin from demoting themselves and losing access.
    if ((int)$id === (int)current_user()['id'] && $role !== 'admin') $role = 'admin';

    $data = [
        'name' => input('name'), 'username' => input('username'), 'email' => input('email'), 'role' => $role,
        'specialization' => input('specialization') ?: null,
    ];
    $pw = (string)($_POST['password'] ?? '');
    if ($pw !== '') {
        if (mb_strlen($pw) < config('password_min')) { flash('error', 'Password too short.'); redirect("/users/{$id}/edit"); }
        $data['password_hash'] = hash_password($pw);
    }
    update('users', $data, 'id = :id', ['id' => $id]);
    audit('update', 'user', (int)$id);
    flash('success', 'User updated.');
    redirect('/users');
}

function users_toggle($id): void
{
    require_role(['admin']);
    if ((int)$id === (int)current_user()['id']) { flash('error', 'You cannot disable your own account.'); redirect('/users'); }
    $usr = one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$usr) redirect('/users');
    update('users', ['active' => $usr['active'] ? 0 : 1], 'id = :id', ['id' => $id]);
    audit($usr['active'] ? 'disable' : 'enable', 'user', (int)$id);
    flash('success', 'User ' . ($usr['active'] ? 'disabled' : 'enabled') . '.');
    redirect('/users');
}
