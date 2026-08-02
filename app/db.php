<?php
/**
 * Database layer — PDO with prepared statements only.
 * Every query in the app flows through here, so user input is never
 * concatenated into SQL (SQL-injection safe by construction).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = config();
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($cfg['db_driver'] === 'mysql') {
        $m = $cfg['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['name']};charset={$m['charset']}";
        $pdo = new PDO($dsn, $m['user'], $m['pass'], $opts);
    } else {
        $path = $cfg['sqlite_path'];
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $fresh = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($fresh) sqlite_bootstrap($pdo);
    }
    return $pdo;
}

/**
 * Ensures the invoices table has the VAT/NHIL/GETFund columns this app writes
 * to, adding them automatically if they're missing. This means invoice
 * generation keeps working even if database/migrate_*.sql was never run by
 * hand against the live database — the very first invoice generated after
 * deploying new code will self-heal the schema instead of failing.
 */
function ensure_invoice_tax_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo = db();
    $driver = config('db_driver');
    try {
        if ($driver === 'sqlite') {
            $existing = array_column($pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        } else {
            $existing = array_column($pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }
    } catch (Throwable $e) {
        return; // if we can't even check, let the real error surface at insert time
    }

    $needed = [
        'subtotal'       => 'DECIMAL(10,2)',
        'vat_rate'       => 'DECIMAL(5,2)',
        'nhil_rate'      => 'DECIMAL(5,2)',
        'getfund_rate'   => 'DECIMAL(5,2)',
        'vat_amount'     => 'DECIMAL(10,2)',
        'nhil_amount'    => 'DECIMAL(10,2)',
        'getfund_amount' => 'DECIMAL(10,2)',
    ];

    foreach ($needed as $col => $type) {
        if (in_array($col, $existing, true)) continue;
        $sqlType = $driver === 'sqlite' ? 'REAL' : $type;
        try {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN {$col} {$sqlType} NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            // Another request may have added it concurrently, or the column
            // already exists under a slightly different check — safe to ignore.
        }
    }
}

/** Run a prepared statement and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Fetch a single row (or null). */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Scalar value from first column of first row. */
function scalar(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Insert helper returning the new id. */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph   = array_map(fn($c) => ':' . $c, $cols);
    $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    q($sql, $data);
    return (int) db()->lastInsertId();
}

/** Update helper. */
function update(string $table, array $data, string $where, array $whereParams = []): void
{
    $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
    q("UPDATE {$table} SET {$set} WHERE {$where}", array_merge($data, $whereParams));
}

/** Create the full schema on a fresh SQLite database. */
function sqlite_bootstrap(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        email TEXT,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'mechanic',      -- admin | manager | receptionist | mechanic
        specialization TEXT,                        -- e.g. "Engine & transmission" (mechanics only)
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        name TEXT NOT NULL,                          -- kept in sync = "first_name last_name" for easy joins/search
        phone TEXT,
        email TEXT,
        password_hash TEXT,
        portal_active INTEGER NOT NULL DEFAULT 0,
        address TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
        reg_number TEXT NOT NULL UNIQUE,
        make TEXT, model TEXT, year INTEGER, color TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        labour_charge REAL NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE spare_parts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        sku TEXT,
        quantity INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 5,
        unit_price REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE job_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
        mechanic_id INTEGER REFERENCES users(id),
        created_by INTEGER REFERENCES users(id),
        fault_desc TEXT,
        diagnosis TEXT,
        estimate REAL NOT NULL DEFAULT 0,
        approved INTEGER NOT NULL DEFAULT 0,
        approval_status TEXT NOT NULL DEFAULT 'pending', -- pending | approved | declined
        status TEXT NOT NULL DEFAULT 'open',         -- open | in_progress | completed | closed
        estimated_completion TEXT,                   -- planned completion date
        actual_completion TEXT,                      -- actual completion date (set when job is completed)
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE job_faults (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_card_id INTEGER NOT NULL REFERENCES job_cards(id) ON DELETE CASCADE,
        fault_desc TEXT NOT NULL,
        mechanic_id INTEGER REFERENCES users(id),    -- mechanic assigned to this specific fault
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE job_services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_card_id INTEGER NOT NULL REFERENCES job_cards(id) ON DELETE CASCADE,
        service_id INTEGER REFERENCES services(id),
        description TEXT,
        charge REAL NOT NULL DEFAULT 0
    );
    CREATE TABLE job_parts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_card_id INTEGER NOT NULL REFERENCES job_cards(id) ON DELETE CASCADE,
        spare_part_id INTEGER REFERENCES spare_parts(id),
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0
    );
    CREATE TABLE appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
        vehicle_id INTEGER REFERENCES vehicles(id) ON DELETE SET NULL,
        scheduled_at TEXT NOT NULL,
        note TEXT,
        decline_reason TEXT,
        status TEXT NOT NULL DEFAULT 'scheduled',     -- pending | scheduled | completed | cancelled | declined
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_card_id INTEGER NOT NULL REFERENCES job_cards(id) ON DELETE CASCADE,
        invoice_no TEXT NOT NULL UNIQUE,
        labour_total REAL NOT NULL DEFAULT 0,
        parts_total REAL NOT NULL DEFAULT 0,
        subtotal REAL NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 0,
        nhil_rate REAL NOT NULL DEFAULT 0,
        getfund_rate REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        nhil_amount REAL NOT NULL DEFAULT 0,
        getfund_amount REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        balance REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'unpaid',         -- unpaid | partial | paid
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        amount REAL NOT NULL,
        method TEXT NOT NULL DEFAULT 'cash',           -- cash | momo | card | bank
        received_by INTEGER REFERENCES users(id),
        paid_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES users(id),
        action TEXT NOT NULL,
        entity TEXT,
        entity_id INTEGER,
        details TEXT,
        ip TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        ip TEXT,
        success INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE sms_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
        sent_by INTEGER REFERENCES users(id),
        phone TEXT NOT NULL,
        message TEXT NOT NULL,
        status TEXT NOT NULL,                        -- sent | failed
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
        sender_type TEXT NOT NULL,                   -- customer | staff
        sender_id INTEGER,
        body TEXT NOT NULL,
        read_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    SQL);
}
    
