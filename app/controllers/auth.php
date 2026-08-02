<?php
/** Auth controller */

function show_login(): void
{
    if (current_user()) redirect('/');
    $cfg = config();
    $brand = e($cfg['app_name']);
    $flash = render_flash();
    $csrf = csrf_field();
    $user = e(input('username'));
    $mark = brand_mark_html('P');
    echo base_rewrite(<<<HTML
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · {$brand} Staff Portal</title><link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">{$mark}<div>{$brand}<small>Staff Portal</small></div></div>
  <h1>Sign in</h1>
  <p class="muted">Sign in to manage the workshop.</p>
  {$flash}
  <form method="post" action="/login" novalidate>
    {$csrf}
    <div class="form-group"><label>Username</label>
      <input type="text" name="username" value="{$user}" autofocus required autocomplete="username"></div>
    <div class="form-group"><label>Password</label>
      <div style="position:relative;">
        <input type="password" name="password" id="password" required autocomplete="current-password" style="padding-right:2.8rem;">
        <button type="button" id="togglePwd" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1.1rem;padding:0.2rem;line-height:1;color:#5B6675;" title="Show/hide password">👁</button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>
  <!-- <p class="auth-foot muted">Protected by hashed credentials, CSRF tokens &amp; rate-limiting.</p> -->
</div>
</body></html>
HTML);
}

function do_login(): void
{
    $username = input('username');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('error', 'Enter your username and password.');
        redirect('/login');
    }
    if (login_locked($username)) {
        flash('error', 'Too many failed attempts. Please wait a few minutes and try again.');
        redirect('/login');
    }
    if (!attempt_login($username, $password)) {
        flash('error', 'Invalid username or password.');
        redirect('/login');
    }
    $to = $_SESSION['_intended'] ?? '/';
    unset($_SESSION['_intended']);
    flash('success', 'Signed in successfully.');
    redirect($to);
}

function do_logout(): void
{
    logout();
    flash('info', 'You have been signed out.');
    redirect('/login');
}
