# AutoShop Management System

A role-based web application for managing an automobile repair workshop —
customers, vehicles, job cards, spare-parts inventory, services, invoicing,
payments, appointments, and reporting. Built with **PHP 8 + PDO** and a
clean, dependency-free UI.

The system targets the realities of a small Ghanaian garage: it runs on
inexpensive cPanel shared hosting (no Composer, Node, or build step required),
works with either **SQLite** (zero-config) or **MySQL** (production), and
prices everything in Ghana cedis (GH₵).

---

## Quick start (local, SQLite)

Requirements: PHP 8.1+ with `pdo_sqlite` and `mbstring` (bundled with most PHP installs).

```bash
# 1. Seed the database with demo accounts and sample data
php database/seed.php

# 2. Start the development server
php -S localhost:8000 server.php

# 3. Open http://localhost:8000 and sign in
```

### Demo accounts

| Role      | Username   | Password       | Can access                                   |
|-----------|------------|----------------|----------------------------------------------|
| Admin     | `admin`    | `Admin@123`    | Everything, incl. users & audit log          |
| Manager   | `manager`  | `Manager@123`  | Front-desk: customers, jobs, invoices, stock |
| Mechanic  | `mechanic` | `Mechanic@123` | Own assigned job cards + inventory            |

> Change these passwords immediately in any real deployment.

---

## Production deploy (MySQL / cPanel)

1. **Create a MySQL database** and import the schema:
   ```bash
   mysql -u YOUR_USER -p YOUR_DB < database/schema_mysql.sql
   ```
2. **Configure** `config.php` (or set environment variables):
   ```php
   'db_driver' => 'mysql',
   'mysql' => ['host'=>'localhost','name'=>'YOUR_DB','user'=>'YOUR_USER','pass'=>'YOUR_PASS', ...],
   ```
   Supported env vars: `DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS`.
3. **Point the web server's document root at the `public/` folder.**
   On shared hosting where the root is fixed (e.g. `public_html`), upload the
   project above the web root and either symlink or move the contents of
   `public/` into `public_html`, keeping `app/`, `config.php`, and `database/`
   outside the web root.
4. **Create the first admin** — either run `php database/seed.php` once against
   MySQL, or insert a user whose `password_hash` is produced by
   `password_hash('yourpass', PASSWORD_DEFAULT)`.
5. **Enable HTTPS.** The app automatically marks session cookies `Secure`
   when served over HTTPS.

The included `.htaccess` files handle front-controller routing and add
security headers under Apache. For Nginx, route all non-file requests to
`public/index.php`.

### Running on Kali / Debian / Ubuntu with Apache2

```bash
# 1. Install Apache, PHP module and the extensions the app uses
sudo apt update
sudo apt install -y apache2 php libapache2-mod-php php-sqlite3 php-mysql php-mbstring
sudo a2enmod rewrite headers

# 2. Put the project under /var/www and let Apache (www-data) own it
sudo cp -r autoshop /var/www/autoshop
sudo chown -R www-data:www-data /var/www/autoshop
sudo chmod -R 775 /var/www/autoshop/storage      # SQLite needs a writable folder

# 3. Create a virtual host whose DocumentRoot is the public/ folder
sudo tee /etc/apache2/sites-available/autoshop.conf >/dev/null <<'CONF'
<VirtualHost *:80>
    ServerName autoshop.local
    DocumentRoot /var/www/autoshop/public
    <Directory /var/www/autoshop/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/autoshop_error.log
</VirtualHost>
CONF

# 4. Enable the site + hostname and reload
sudo a2ensite autoshop.conf
echo "127.0.0.1 autoshop.local" | sudo tee -a /etc/hosts
sudo systemctl reload apache2
```

Now open **http://autoshop.local/** and sign in with `admin / Admin@123`.

The bundled `storage/autoshop.sqlite` is already seeded. To reseed, run it **as the
web user** so file ownership stays correct:
`sudo -u www-data php /var/www/autoshop/database/seed.php`.

**If you see HTTP 500**, read the exact reason with
`sudo tail -n 30 /var/log/apache2/autoshop_error.log` — the most common cause is the
`storage/` folder not being writable by `www-data` (step 2 fixes it). With
`'debug' => true` in `config.php`, the error is also printed in the browser.



The app auto-detects its base path, so it works even from a subfolder. Two ways:

**Option A — point the URL at the `public/` folder (simplest).**
Drop the project into `htdocs` and browse to the `public/` directory, e.g.
`http://localhost/AutoShop_Management_System/autoshop/public/`. The app detects
the subfolder automatically and builds all links/assets relative to it. Make sure
Apache's `mod_rewrite` is enabled (it is by default in XAMPP) and that
`AllowOverride All` is set for `htdocs` so the `.htaccess` files are honoured.

**Option B — give it its own clean URL (recommended).**
Add a VirtualHost (or an Alias) whose document root is the project's `public/`
folder, so the app runs at `http://autoshop.local/` with no subfolder. This is
closest to production.

**Option C — skip Apache for local dev.**
From the project root run `php -S localhost:8000 server.php` (XAMPP ships PHP),
then open `http://localhost:8000`.

> If you previously saw a "404 — Page not found" or an unstyled page, it was
> because the app was opened from a subfolder before base-path detection was
> added. Replace the files with this version and refresh.

---

## Security controls

| Area                | Implementation                                                                 |
|---------------------|--------------------------------------------------------------------------------|
| Password storage    | `password_hash()` (bcrypt) + `password_verify()`; auto-rehash on cost upgrade  |
| SQL injection       | PDO **prepared statements** for every query — no string-built SQL              |
| XSS                 | All output escaped via `htmlspecialchars()` (`e()` helper)                     |
| CSRF                | Per-session token required and verified on every POST                          |
| Session security    | `HttpOnly`, `SameSite=Lax`, `Secure` (on HTTPS), ID regeneration on login, 30-min idle timeout |
| Brute force         | Login lockout after 5 failed attempts per IP within 15 minutes                 |
| Access control      | Role-based middleware (`require_role`) on every protected route                |
| Data integrity      | DB **transactions** for stock decrement and payment posting                    |
| Accountability      | Tamper-evident **audit log** of logins, edits, status changes, and payments    |
| Response headers    | `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` |

---

## Project structure

```
autoshop/
├── config.php              # DB driver switch + security settings
├── server.php              # Local dev server router (production uses /public)
├── public/                 # ← web server document root
│   ├── index.php           #   front controller (routes + security headers)
│   ├── .htaccess           #   rewrite + headers
│   └── assets/             #   app.css (design system), app.js
├── app/
│   ├── db.php              # PDO layer + SQLite auto-bootstrap
│   ├── helpers.php         # escaping, CSRF, validation + UI components
│   ├── auth.php            # auth, RBAC, throttling, audit
│   ├── router.php          # tiny pattern router
│   └── controllers/        # one file per module
├── database/
│   ├── schema_mysql.sql    # production MySQL schema
│   └── seed.php            # demo accounts + sample data
└── storage/                # SQLite file lives here (auto-created)
```

## Modules

Dashboard · Job Cards (with services, parts & stock deduction, invoicing) ·
Customers · Vehicles · Inventory (spare parts with low-stock alerts) ·
Services catalogue · Invoices & Payments (cash / MoMo / card / bank) ·
Appointments · Users (admin) · Audit Log · Reports.

---

*Built as a final-year project. The PHP/PDO stack was chosen for portability on
low-cost shared hosting; the architecture (three-tier, RBAC, hashed credentials)
matches the system design documentation.*
