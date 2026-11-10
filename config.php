<?php
// ============================================================================
// Environment configuration
// ----------------------------------------------------------------------------
// Every value below can be overridden by a real environment variable (set by
// whatever's hosting this app) without touching this file. For local dev,
// drop a `.env` file next to this one (copy `.env.example`) and it's loaded
// automatically. If you don't create one, everything falls back to the old
// hardcoded SQLite behavior — local dev with `php -S` needs zero setup, same
// as before.
// ============================================================================
loadEnvFile(__DIR__ . '/.env');

function loadEnvFile(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Never let a local .env override a real environment variable the
        // host already set — .env is a local-dev convenience, not the
        // source of truth once this is actually deployed somewhere.
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

// DB_DRIVER: 'sqlite' (default, zero setup) or 'mysql'.
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'sqlite');

// --- SQLite (local dev default) ---
// The database is just a file that gets created automatically the first time
// you connect. php -S 0.0.0.0:8000 -t Z:\Php_Files
define('DB_FILE', __DIR__ . '/syspoint_crm.sqlite');
define('SCHEMA_FILE', __DIR__ . '/schema_sqlite.sql');

// --- MySQL / MariaDB (used when DB_DRIVER=mysql) ---
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'syspoint_crm');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SCHEMA_FILE_MYSQL', __DIR__ . '/schema.sql');

// Registration is restricted to real syspoint accounts, verified by emailing a one-time
// code to the address before an account is created. Replaces the old shared invite code —
// this actually confirms the person owns that mailbox, not just that they know a shared secret.
define('ALLOWED_EMAIL_DOMAIN', getenv('ALLOWED_EMAIL_DOMAIN') ?: 'syspointinc.com');
define('OTP_LENGTH', 6);
define('OTP_TTL_MINUTES', 10);
define('OTP_RESEND_COOLDOWN_SECONDS', 60);
// A code stops working after this many wrong guesses (a 6-digit code is only
// 1,000,000 possibilities — without a cap it can be brute-forced inside its TTL).
define('OTP_MAX_ATTEMPTS', 5);

// A finished (fully approved / rejected) request disappears from the requester's
// My Requests page this many days after the final decision. It is hidden, not
// erased: the approvals record and audit log keep it.
define('MY_REQUESTS_AUTO_HIDE_DAYS', 7);

// Search limits (per person, per hour): the "Find a client" page, and the as-you-type
// duplicate check on the add-company forms.
define('FIND_CLIENT_SEARCHES_PER_HOUR', 15);
define('DUPLICATE_CHECK_PER_HOUR', 150);

// Login throttling: after LOGIN_MAX_FAILURES wrong passwords for the same email
// inside LOGIN_LOCKOUT_MINUTES, that email can't try again until the window
// passes. LOGIN_MAX_FAILURES_PER_IP is a looser cap across all emails from one address.
define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_MAX_FAILURES_PER_IP', 30);
define('LOGIN_LOCKOUT_MINUTES', 15);
// Account lock: this many wrong passwords in a row on a real account locks it until an admin
// (for plain users) or a super admin (for anyone) unlocks it in Manage users. Super admin accounts
// are the exception: nobody is above them to unlock, so they only get the timed lockout above.
define('LOGIN_ACCOUNT_LOCK_AFTER', 3);
define('LOCK_NOTE_MIN_LENGTH', 10);
define('LOCK_NOTE_MAX_LENGTH', 500);
define('LOCK_NOTE_MAX_NOTES', 5);
define('LOCK_NOTE_SEPARATOR', "\n— — —\n");

// Reverse proxy: if the VM sits behind nginx/Apache/a load balancer, PHP sees the
// proxy's address in REMOTE_ADDR, so every visitor would share one login lockout.
// List the proxy's own IP(s) here (comma-separated, CIDR allowed, e.g. "127.0.0.1,10.0.0.0/8")
// and the real visitor address is read from X-Forwarded-For — but ONLY when the request
// really came from one of these. Empty (the default) = no proxy, REMOTE_ADDR is used as-is.
define('TRUSTED_PROXIES', (string) (getenv('TRUSTED_PROXIES') ?: ''));

// Verification-code emails (registration + password reset) are throttled so the forms
// can't be used to flood someone's inbox or burn the mail account's daily send limit.
// Counted per target email and per visitor IP, over the last hour; every request counts,
// whether or not the email has an account, so the limit itself reveals nothing.
define('OTP_MAX_SENDS_PER_EMAIL_PER_HOUR', 5);
define('OTP_MAX_SENDS_PER_IP_PER_HOUR', 15);

// Sessions: a logged-in browser that does nothing for this long is logged out.
define('SESSION_IDLE_MINUTES', (int) (getenv('SESSION_IDLE_MINUTES') ?: 60));

// Everything the app stores or compares is UTC (toLocalTime() converts to +08:00
// only for display). Pinning PHP's default zone makes date()/strtotime() behave
// the same on every machine regardless of what php.ini says.
date_default_timezone_set('UTC');

// Housekeeping windows. The audit log is a rolling window (CCTV-style): today
// plus the previous 29 (per Sir Richard) local (Philippine) days, so on the midnight rollover
// the oldest day drops off. Approved-but-never-downloaded exports lose their
// stored CSV after EXPORT_CLEAR_AFTER_DAYS.
define('AUDIT_LOG_RETENTION_DAYS', 30);
define('EXPORT_CLEAR_AFTER_DAYS', 3);

// --- SMTP (Google Workspace) ---
// The fallback values below reproduce today's hardcoded credential so local
// testing keeps working untouched. Set these in `.env` (or real env vars on
// whatever host runs this) instead of relying on the fallback — the app
// password should be rotated once it's out of this file for good.
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'license.admin@syspointinc.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'ovsa ully roor kydd');
define('SMTP_FROM_EMAIL', SMTP_USER);
define('SMTP_FROM_NAME', 'Syspoint CRM');
// SMTP_SECURE: tls (STARTTLS, default for Gmail on 587), ssl (465) or none. SMTP_AUTH=0 only for a local test relay.
define('SMTP_SECURE', strtolower(getenv('SMTP_SECURE') ?: 'tls'));
define('SMTP_AUTH', getenv('SMTP_AUTH') !== '0'); // note: "0" is falsy in PHP, so no ?: shortcut here

// --- Notification emails ---
// Every in-app notification is also emailed to its recipient (and only the recipient).
// NOTIFY_EMAIL_ENABLED=0 turns it off. APP_BASE_URL (e.g. https://crm.syspointinc.com) adds an "Open" link.
// Only notifications younger than NOTIFY_EMAIL_MAX_AGE_MINUTES are emailed, so an outage or a fresh
// migration can never blast old notifications. Several waiting for one person go out as one email.
define('NOTIFY_EMAIL_ENABLED', getenv('NOTIFY_EMAIL_ENABLED') !== '0');
define('NOTIFY_EMAIL_MAX_AGE_MINUTES', 120);
define('NOTIFY_EMAIL_MAX_PER_RUN', 30);
define('APP_BASE_URL', rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/'));
require_once __DIR__ . '/email_threads.php'; // email conversations (threads) + reply-by-email

// --- Email look & signature ---
// Every email the app sends (verification code, password reset, notifications) is wrapped by
// emailLayout() below, so they share one design and one signature block at the bottom.
// EMAIL_SIGNATURE_LINES is optional extra lines under the company name (partners, address,
// phone, website...), separated by "|", e.g. "Partner A | Partner B | Tel +63 2 1234 5678".
// Leave the notice or lines empty ('') to drop them.
define('EMAIL_COMPANY_NAME', getenv('EMAIL_COMPANY_NAME') ?: 'Syspoint Inc.');
define('EMAIL_SIGNATURE_LINES', (string) (getenv('EMAIL_SIGNATURE_LINES') ?: ''));
define('EMAIL_CONFIDENTIALITY_NOTICE', getenv('EMAIL_CONFIDENTIALITY_NOTICE') !== false
    ? (string) getenv('EMAIL_CONFIDENTIALITY_NOTICE')
    : 'This email and any attachments are confidential and intended only for the person it is addressed to. If you received it by mistake, please tell the sender and delete it; do not copy, forward or use its contents. This is an automated message, please do not reply to it.');

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        if (DB_DRIVER === 'mysql') {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                    // CURRENT_TIMESTAMP / NOW() follow the *session* time zone on MySQL.
                    // Pin it to UTC so stored timestamps match SQLite's and toLocalTime()
                    // doesn't add +08:00 to a value that is already local.
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                die('Database connection failed (MySQL). Check DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS: ' . $e->getMessage());
            }

            if (!file_exists(SCHEMA_FILE_MYSQL)) {
                die('Cannot find schema.sql next to config.php. Make sure both files are in the same folder.');
            }

            // Bootstrap the schema the first time this database is used —
            // mirrors the SQLite path below, so pointing DB_DRIVER=mysql at
            // an empty database "just works" the same way an empty .sqlite
            // file does today. On a database that already has data, this is
            // a no-op check, not a re-run of the schema.
            $usersTableExists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
            )->fetchColumn() > 0;

            if (!$usersTableExists) {
                $pdo->exec(file_get_contents(SCHEMA_FILE_MYSQL));
            }
        } else {
            // ---- SQLite path (local dev default) ----
            try {
                $pdo = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } catch (PDOException $e) {
                die('Database connection failed: ' . $e->getMessage());
            }

            if (!file_exists(SCHEMA_FILE)) {
                die('Cannot find schema_sqlite.sql next to config.php. Make sure both files are in the same folder.');
            }
            $pdo->exec(file_get_contents(SCHEMA_FILE));
        }

        // The notifications table rebuilds go first: they copy an explicit column
        // list into a fresh table, so any column healMissingColumns() had just
        // added to notifications (dismissed_at) would be dropped by them.
        // These are all internally guarded to SQLite only (schema.sql already
        // has the final MySQL structure baked in, nothing to heal there) —
        // safe to call unconditionally on both drivers.
        healNotificationsTypeCheck($pdo);
        healNotificationsApprovalTypes($pdo);
        healNotificationsBranchTypes($pdo);
        healNotificationsOwnershipType($pdo);
        healNotificationsEngagementType($pdo);
        healUsersRole($pdo);
        healMissingColumns($pdo);
        ensureLoginAttemptsTable($pdo);
        ensureSearchLogTable($pdo);
        ensureEngagementsTable($pdo);
        ensureEmailThreadTables($pdo);
        ensureCompanyCategoriesTable($pdo);
        ensureOtpSendLogTable($pdo);
        registerNotificationMailer($pdo);
        healMysqlBranchOwnerTriggers($pdo);
        runHousekeeping($pdo);
    }

    return $pdo;
}

/**
 * SQLite has no ALTER TABLE for changing a CHECK constraint or a NOT NULL flag —
 * widening notifications (nullable branch_id, generic target/message columns,
 * new account_request_* types, needed so account changes can notify someone
 * without being tied to a branch) means rebuilding the whole table. Runs once:
 * if the stored table definition already has the 'message' column, it's a
 * no-op. On MySQL, edit schema.sql directly before deploying there — this
 * function only handles the local SQLite self-heal case.
 */
function healNotificationsTypeCheck(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn();
    if ($sql === false || strpos($sql, 'message') !== false) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE notifications_new (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id       INTEGER REFERENCES branches(id),
            type            TEXT NOT NULL CHECK (type IN ('three_month_warning', 'six_month_reassign', 'modification_pending', 'modification_superadmin_pending', 'account_request_pending', 'account_request_reviewed')),
            sent_to         INTEGER NOT NULL REFERENCES users(id),
            target_table    TEXT,
            target_id       INTEGER,
            message         TEXT,
            is_read         INTEGER NOT NULL DEFAULT 0,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec('INSERT INTO notifications_new (id, branch_id, type, sent_to, is_read, created_at) SELECT id, branch_id, type, sent_to, is_read, created_at FROM notifications');
    $pdo->exec('DROP TABLE notifications');
    $pdo->exec('ALTER TABLE notifications_new RENAME TO notifications');
}

/**
 * Second widening of the notifications CHECK, same reasoning and same
 * rebuild-the-table approach as healNotificationsTypeCheck above — SQLite
 * still can't ALTER a CHECK constraint. Runs once: if the stored table
 * definition already lists 'company_assignment_pending', it's a no-op. On
 * MySQL, schema.sql already has the widened ENUM — nothing to heal there.
 */
function healNotificationsApprovalTypes(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn();
    if ($sql === false || strpos($sql, 'company_assignment_pending') !== false) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE notifications_new2 (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id       INTEGER REFERENCES branches(id),
            type            TEXT NOT NULL CHECK (type IN ('three_month_warning', 'six_month_reassign', 'modification_pending', 'modification_superadmin_pending', 'account_request_pending', 'account_request_reviewed', 'company_assignment_pending', 'company_assignment_reviewed', 'export_pending', 'export_reviewed')),
            sent_to         INTEGER NOT NULL REFERENCES users(id),
            target_table    TEXT,
            target_id       INTEGER,
            message         TEXT,
            is_read         INTEGER NOT NULL DEFAULT 0,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec('INSERT INTO notifications_new2 (id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at) SELECT id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at FROM notifications');
    $pdo->exec('DROP TABLE notifications');
    $pdo->exec('ALTER TABLE notifications_new2 RENAME TO notifications');
}

/**
 * Third widening of the notifications CHECK (change-request outcomes and
 * client transfer/assignment notices). Same rebuild-the-table approach as the
 * two above, with one difference: it also carries dismissed_at across when the
 * column already exists. Without that, rebuilding would un-hide every 3/6-month
 * alert someone had deleted, and they would all reappear as new. Runs once: if
 * the stored definition already lists 'branch_transferred_out' it's a no-op.
 * On MySQL, schema.sql already has the widened ENUM.
 */
function healNotificationsBranchTypes(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn();
    if ($sql === false || strpos($sql, 'branch_transferred_out') !== false) {
        return;
    }

    $hasDismissed = false;
    foreach ($pdo->query('PRAGMA table_info(notifications)')->fetchAll() as $c) {
        if ($c['name'] === 'dismissed_at') {
            $hasDismissed = true;
            break;
        }
    }
    $columns = 'id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at'
        . ($hasDismissed ? ', dismissed_at' : '');

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "CREATE TABLE notifications_new3 (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                branch_id       INTEGER REFERENCES branches(id),
                type            TEXT NOT NULL CHECK (type IN ('three_month_warning', 'six_month_reassign', 'modification_pending', 'modification_superadmin_pending', 'account_request_pending', 'account_request_reviewed', 'company_assignment_pending', 'company_assignment_reviewed', 'export_pending', 'export_reviewed', 'modification_reviewed', 'branch_transferred_out', 'branch_transferred_in', 'branch_assigned')),
                sent_to         INTEGER NOT NULL REFERENCES users(id),
                target_table    TEXT,
                target_id       INTEGER,
                message         TEXT,
                is_read         INTEGER NOT NULL DEFAULT 0,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dismissed_at    TIMESTAMP NULL DEFAULT NULL
            )"
        );
        $pdo->exec("INSERT INTO notifications_new3 ($columns) SELECT $columns FROM notifications");
        $pdo->exec('DROP TABLE notifications');
        $pdo->exec('ALTER TABLE notifications_new3 RENAME TO notifications');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Fourth widening of the notifications CHECK, adding 'branch_needs_reassignment'
 * (see flagBranchesForReassignment above). Same rebuild-the-table approach and
 * same dismissed_at carry-across as healNotificationsBranchTypes. Runs once:
 * if the stored definition already lists 'branch_needs_reassignment', it's a
 * no-op. On MySQL, schema.sql already has the widened ENUM.
 */
function healNotificationsOwnershipType(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn();
    if ($sql === false || strpos($sql, 'branch_needs_reassignment') !== false) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "CREATE TABLE notifications_new4 (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                branch_id       INTEGER REFERENCES branches(id),
                type            TEXT NOT NULL CHECK (type IN ('three_month_warning', 'six_month_reassign', 'modification_pending', 'modification_superadmin_pending', 'account_request_pending', 'account_request_reviewed', 'company_assignment_pending', 'company_assignment_reviewed', 'export_pending', 'export_reviewed', 'modification_reviewed', 'branch_transferred_out', 'branch_transferred_in', 'branch_assigned', 'branch_needs_reassignment')),
                sent_to         INTEGER NOT NULL REFERENCES users(id),
                target_table    TEXT,
                target_id       INTEGER,
                message         TEXT,
                is_read         INTEGER NOT NULL DEFAULT 0,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dismissed_at    TIMESTAMP NULL DEFAULT NULL
            )"
        );
        $pdo->exec('INSERT INTO notifications_new4 (id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at, dismissed_at) SELECT id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at, dismissed_at FROM notifications');
        $pdo->exec('DROP TABLE notifications');
        $pdo->exec('ALTER TABLE notifications_new4 RENAME TO notifications');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Latest widening of the notifications type list, adding 'contact_changed' (admins are told when a
 * salesperson edits or removes a contact person) on top of everything before it, including
 * 'engagement_reminder'. SQLite rebuilds the table, carrying dismissed_at and emailed_at across;
 * MySQL/MariaDB just MODIFYs the ENUM. Runs once per database. (Name kept from the earlier widening.)
 */
function healNotificationsEngagementType(PDO $pdo): void
{
    $types = "'three_month_warning', 'six_month_reassign', 'modification_pending', 'modification_superadmin_pending', 'account_request_pending', 'account_request_reviewed', 'company_assignment_pending', 'company_assignment_reviewed', 'export_pending', 'export_reviewed', 'modification_reviewed', 'branch_transferred_out', 'branch_transferred_in', 'branch_assigned', 'branch_needs_reassignment', 'engagement_reminder', 'contact_changed'";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'type'"
        );
        $stmt->execute();
        $columnType = $stmt->fetchColumn();
        if ($columnType !== false && strpos((string) $columnType, 'contact_changed') === false) {
            $pdo->exec("ALTER TABLE notifications MODIFY type ENUM($types) NOT NULL");
        }
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn();
    if ($sql === false || strpos($sql, 'contact_changed') !== false) {
        return;
    }
    // Keep emailed_at when the table already has it, so recent notifications are not emailed a second time.
    $hasEmailed = false;
    foreach ($pdo->query('PRAGMA table_info(notifications)')->fetchAll() as $col) {
        if ($col['name'] === 'emailed_at') {
            $hasEmailed = true;
        }
    }
    $emailedDef = $hasEmailed ? ",\n                emailed_at      TIMESTAMP NULL DEFAULT NULL" : '';
    $emailedCol = $hasEmailed ? ', emailed_at' : '';

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "CREATE TABLE notifications_new6 (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                branch_id       INTEGER REFERENCES branches(id),
                type            TEXT NOT NULL CHECK (type IN ($types)),
                sent_to         INTEGER NOT NULL REFERENCES users(id),
                target_table    TEXT,
                target_id       INTEGER,
                message         TEXT,
                is_read         INTEGER NOT NULL DEFAULT 0,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dismissed_at    TIMESTAMP NULL DEFAULT NULL{$emailedDef}
            )"
        );
        $pdo->exec("INSERT INTO notifications_new6 (id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at, dismissed_at{$emailedCol}) SELECT id, branch_id, type, sent_to, target_table, target_id, message, is_read, created_at, dismissed_at{$emailedCol} FROM notifications");
        $pdo->exec('DROP TABLE notifications');
        $pdo->exec('ALTER TABLE notifications_new6 RENAME TO notifications');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * ---- Engagement ------------------------------------------------------------
 * A salesperson can log that they are still in touch with a client (email, call,
 * Viber, ...) even though nothing has been ordered. It needs no approval. The
 * latest engagement counts as activity, so it resets the 3/6-month flag exactly
 * like a new order date does.
 */
const ENGAGEMENT_TYPES = [
    'email' => 'Email',
    'call' => 'Call',
    'viber' => 'Viber',
    'meeting' => 'Meeting',
    'quotation' => 'Quotation',
    'site_visit' => 'Site visit',
    'sample_demo' => 'Sample / demo',
    'other' => 'Other',
];
const ENGAGEMENT_QUIET_DAYS = 30;

function ensureEngagementsTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS engagements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL REFERENCES branches(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            type TEXT NOT NULL,
            engaged_on DATE NOT NULL,
            engaged_time VARCHAR(5) NULL DEFAULT NULL,
            note TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_engagements_branch ON engagements (branch_id, engaged_on)');
        addEngagementTimeColumn($pdo);
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS engagements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        user_id INT NOT NULL,
        type VARCHAR(20) NOT NULL,
        engaged_on DATE NOT NULL,
        engaged_time VARCHAR(5) NULL DEFAULT NULL,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_engagements_branch (branch_id, engaged_on),
        CONSTRAINT fk_eng_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
        CONSTRAINT fk_eng_user FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB');
    addEngagementTimeColumn($pdo);
}

/**
 * The optional time of day ('HH:MM', Manila time) a salesperson can set on an engagement.
 * Done here rather than in healMissingColumns() because that runs before the engagements
 * table is guaranteed to exist. Idempotent: a no-op once the column is there.
 */
function addEngagementTimeColumn(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(engagements)')->fetchAll() as $col) {
            if ($col['name'] === 'engaged_time') {
                return;
            }
        }
    } else {
        $check = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'engagements' AND column_name = 'engaged_time'");
        if ((int) $check->fetchColumn() > 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE engagements ADD COLUMN engaged_time VARCHAR(5) NULL DEFAULT NULL');
}

/**
 * ---- Company categories ----------------------------------------------------
 * A company can carry one or more categories (replaces the old free-text company
 * description). The list lives here in code, stored by key, so adding or renaming
 * one is a one-line change and the database never holds a typo'd category.
 * A company with no rows is shown as "Uncategorized".
 */
const COMPANY_CATEGORIES = [
    'education'     => 'School / Education',
    'technology'    => 'Technology / IT',
    'bpo'           => 'BPO / Call center',
    'food_beverage' => 'Food & beverage',
    'retail'        => 'Retail / Convenience store',
    'healthcare'    => 'Healthcare / Hospital',
    'manufacturing' => 'Manufacturing / Industrial',
    'logistics'     => 'Logistics / Transport',
    'finance'       => 'Banking / Finance / Insurance',
    'hospitality'   => 'Hotel / Hospitality',
    'real_estate'   => 'Construction / Real estate',
    'government'    => 'Government / Public sector',
    'other'         => 'Other',
];

function ensureCompanyCategoriesTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS company_categories (
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            category TEXT NOT NULL,
            PRIMARY KEY (company_id, category))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_company_categories_cat ON company_categories (category)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS company_categories (
        company_id INT NOT NULL,
        category VARCHAR(40) NOT NULL,
        PRIMARY KEY (company_id, category),
        INDEX idx_company_categories_cat (category),
        CONSTRAINT fk_cc_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
}

/** Ticked category boxes from a form post, kept only if they are real keys, in list order, no duplicates. */
function parseCategoriesFromPost(array $post, string $field = 'categories'): array
{
    $posted = $post[$field] ?? [];
    if (!is_array($posted)) {
        return [];
    }
    return array_values(array_intersect(array_keys(COMPANY_CATEGORIES), array_map('strval', $posted)));
}

/** Replaces a company's categories with exactly $keys (already validated). */
function setCompanyCategories(PDO $pdo, int $companyId, array $keys): void
{
    $pdo->prepare('DELETE FROM company_categories WHERE company_id = :c')->execute(['c' => $companyId]);
    $ins = $pdo->prepare('INSERT INTO company_categories (company_id, category) VALUES (:c, :k)');
    foreach (array_values(array_intersect(array_keys(COMPANY_CATEGORIES), $keys)) as $k) {
        $ins->execute(['c' => $companyId, 'k' => $k]);
    }
}

/** company id => list of category keys (list order). Companies with none are absent from the map. */
function fetchCompanyCategories(PDO $pdo, array $companyIds): array
{
    $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
    if (!$companyIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $pdo->prepare("SELECT company_id, category FROM company_categories WHERE company_id IN ($marks)");
    $stmt->execute($companyIds);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int) $r['company_id']][] = $r['category'];
    }
    $order = array_flip(array_keys(COMPANY_CATEGORIES));
    foreach ($map as &$keys) {
        usort($keys, static fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    }
    unset($keys);
    return $map;
}

/** Category keys -> readable labels (unknown keys dropped). */
function categoryLabels(array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        if (isset(COMPANY_CATEGORIES[$k])) {
            $out[] = COMPANY_CATEGORIES[$k];
        }
    }
    return $out;
}

/**
 * The multi-select dropdown used on every form. A plain <details> menu of checkboxes,
 * so it works with no script; category_picker.js only keeps the summary text up to date.
 * Posts as categories[] (or $name[]).
 */
function categoryPickerHtml(array $selected = [], string $name = 'categories', string $id = 'category-picker'): string
{
    $h = '<details class="cat-picker" id="' . htmlspecialchars($id) . '">'
       . '<summary><span class="cat-summary">Select categories…</span></summary><div class="cat-options">';
    foreach (COMPANY_CATEGORIES as $key => $label) {
        $h .= '<label class="cat-opt"><input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($key) . '"'
            . (in_array($key, $selected, true) ? ' checked' : '') . '> ' . htmlspecialchars($label) . '</label>';
    }
    return $h . '</div></details>';
}

/**
 * ---- Verification-code send throttle ---------------------------------------
 * One row per code request (registration or reset), keyed by the target email and
 * the visitor's IP. See OTP_MAX_SENDS_* above.
 */
function ensureOtpSendLogTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS otp_send_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, ip_address TEXT NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_send_email ON otp_send_log (email, sent_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_send_ip ON otp_send_log (ip_address, sent_at)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS otp_send_log (
        id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_otp_send_email (email, sent_at), INDEX idx_otp_send_ip (ip_address, sent_at))');
}

/**
 * Records this code request and returns false (recording nothing) when the email or the
 * IP is over its hourly limit. Call before doing any work for the request.
 */
function allowOtpSend(PDO $pdo, string $email): bool
{
    $email = strtolower(trim($email));
    $ip = clientIp();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 3600);
    $pdo->prepare('DELETE FROM otp_send_log WHERE sent_at < :c')->execute(['c' => gmdate('Y-m-d H:i:s', time() - 86400)]);

    $byEmail = $pdo->prepare('SELECT COUNT(*) FROM otp_send_log WHERE email = :e AND sent_at >= :c');
    $byEmail->execute(['e' => $email, 'c' => $cutoff]);
    $byIp = $pdo->prepare('SELECT COUNT(*) FROM otp_send_log WHERE ip_address = :i AND sent_at >= :c');
    $byIp->execute(['i' => $ip, 'c' => $cutoff]);
    if ((int) $byEmail->fetchColumn() >= OTP_MAX_SENDS_PER_EMAIL_PER_HOUR || (int) $byIp->fetchColumn() >= OTP_MAX_SENDS_PER_IP_PER_HOUR) {
        return false;
    }
    $pdo->prepare('INSERT INTO otp_send_log (email, ip_address, sent_at) VALUES (:e, :i, :t)')
        ->execute(['e' => $email, 'i' => $ip, 't' => gmdate('Y-m-d H:i:s')]);
    return true;
}

/** True if $ip equals, or falls inside, any entry of the comma-separated list ("1.2.3.4" or "10.0.0.0/8"; IPv4 and IPv6). */
function ipInList(string $ip, string $list): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $netPacked = @inet_pton($net);
        if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
            continue;
        }
        $bits = $bits === null ? strlen($packed) * 8 : max(0, min(strlen($packed) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
            continue;
        }
        if ($rem === 0 || ((ord($packed[$bytes]) ^ ord($netPacked[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * The visitor's real IP. Without TRUSTED_PROXIES this is just REMOTE_ADDR. With it, and only
 * when the connection itself came from a trusted proxy, X-Forwarded-For is read right to left
 * and the first address that is NOT a trusted proxy wins — anything a visitor puts further left
 * in that header is ignored, so it can't be used to dodge the lockout.
 */
function clientIp(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (TRUSTED_PROXIES === '' || !ipInList($remote, TRUSTED_PROXIES)) {
        return substr($remote, 0, 45);
    }
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) && !ipInList($hop, TRUSTED_PROXIES)) {
                return substr($hop, 0, 45);
            }
        }
    }
    return substr($remote, 0, 45);
}

/**
 * Small "i" badge that shows its text on hover or keyboard focus (tap on touch screens,
 * since the badge is focusable). Used to keep long help text out of the page. The CSS
 * constant goes once inside each page's <style> block.
 */
const INFO_TIP_CSS = '
    .info-tip { position: relative; display: inline-flex; vertical-align: middle; margin-left: 8px; outline: 0; }
    .info-tip .i { display: inline-block; width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid #9a9a9a; background: #fff; color: #707070; font: 700 11px/15px "DM Sans", sans-serif; font-style: normal; text-align: center; cursor: help; }
    .info-tip:hover .i, .info-tip:focus .i { border-color: #d71920; color: #d71920; }
    .info-tip .tip { display: none; position: absolute; left: -6px; top: 20px; z-index: 30; width: min(320px, 70vw); padding: 10px 12px; border-radius: 6px; background: #171717; color: #fff; font: 500 0.78rem/1.5 "DM Sans", sans-serif; letter-spacing: 0; text-transform: none; text-align: left; box-shadow: 0 10px 26px rgba(8, 8, 8, 0.25); }
    .info-tip .tip::before { content: ""; position: absolute; left: 0; right: 0; top: -10px; height: 10px; }
    .info-tip:hover .tip, .info-tip:focus .tip { display: block; }
';

function infoTip(string $text): string
{
    $t = htmlspecialchars($text);
    return '<span class="info-tip" tabindex="0" role="note" aria-label="' . $t . '"><i class="i">i</i><span class="tip">' . $t . '</span></span>';
}

/** The later of the last order date and the last engagement date (both YYYY-MM-DD), or null. */
function lastActivityDate(?string $lastOrder, ?string $lastEngagement): ?string
{
    $dates = array_filter([$lastOrder, $lastEngagement], static fn($d): bool => $d !== null && $d !== '');
    return $dates ? max($dates) : null;
}

/**
 * Status chip for a client. The 3/6-month rules run off the most recent activity
 * (an order OR a logged engagement). 'quiet' is the softer 30-day note: set when
 * there has been no activity for a month but the 3-month warning has not kicked in yet.
 */
function orderStatus(?string $lastOrderDate, ?string $lastEngagementDate = null): array
{
    if ($lastOrderDate === null) {
        return ['label' => 'No orders yet', 'class' => 'neutral', 'quiet' => false, 'activity' => $lastEngagementDate];
    }
    $activity = lastActivityDate($lastOrderDate, $lastEngagementDate);
    $days = (strtotime('now') - strtotime($activity)) / 86400;
    $months = $days / 30;
    $out = ['label' => 'Active', 'class' => 'active', 'quiet' => false, 'activity' => $activity];
    if ($months >= 6) {
        $out['label'] = 'Reassignment flag';
        $out['class'] = 'danger';
    } elseif ($months >= 3) {
        $out['label'] = '3-month warning';
        $out['class'] = 'warning';
    } elseif ($days >= ENGAGEMENT_QUIET_DAYS) {
        $out['quiet'] = true;
    }
    return $out;
}

/** Records an engagement and an audit entry. The caller has already checked the person owns the branch. */
function logEngagement(PDO $pdo, int $userId, int $branchId, string $type, string $engagedOn, string $note, ?string $engagedTime = null): void
{
    $engagedTime = ($engagedTime !== null && $engagedTime !== '') ? $engagedTime : null;
    $pdo->prepare('INSERT INTO engagements (branch_id, user_id, type, engaged_on, engaged_time, note) VALUES (:b, :u, :t, :d, :tm, :n)')
        ->execute(['b' => $branchId, 'u' => $userId, 't' => $type, 'd' => $engagedOn, 'tm' => $engagedTime, 'n' => $note !== '' ? $note : null]);
    $details = 'Logged engagement: ' . (ENGAGEMENT_TYPES[$type] ?? $type) . ' on ' . $engagedOn . ($engagedTime !== null ? ' at ' . $engagedTime : '') . ($note !== '' ? ' — ' . $note : '');
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:u, 'engagement', 'branches', :b, :d)")
        ->execute(['u' => $userId, 'b' => $branchId, 'd' => $details]);
}

/**
 * ---- Notification emails ---------------------------------------------------
 * Hooked at the end of every request (after the page has been sent where the server
 * allows it), so no insert site needs to know about email. It looks for notifications
 * that have not been emailed yet and sends each person ONE email: the notification
 * itself if there is one, a short list if several piled up. A failure is logged and
 * never reaches the page.
 */
const NOTIFICATION_EMAIL_LABELS = [
    'three_month_warning' => '3-month warning',
    'six_month_reassign' => 'Reassignment flag',
    'modification_pending' => 'Change request',
    'modification_superadmin_pending' => 'Needs your final approval',
    'account_request_pending' => 'Account request',
    'account_request_reviewed' => 'Account request reviewed',
    'company_assignment_pending' => 'Company/branch request',
    'company_assignment_reviewed' => 'Company/branch request reviewed',
    'export_pending' => 'Export request',
    'export_reviewed' => 'Export reviewed',
    'modification_reviewed' => 'Change request decided',
    'branch_transferred_out' => 'Client transferred away',
    'branch_transferred_in' => 'Client transferred to you',
    'branch_assigned' => 'New client assigned',
    'branch_needs_reassignment' => 'Needs reassignment',
    'engagement_reminder' => 'No contact in 30+ days',
    'contact_changed' => 'Contact person updated',
];

function registerNotificationMailer(PDO $pdo): void
{
    static $registered = false;
    if ($registered || !NOTIFY_EMAIL_ENABLED || PHP_SAPI === 'cli') {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () use ($pdo): void {
        try {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            sendPendingNotificationEmails($pdo);
        } catch (Throwable $e) {
            error_log('Notification email run failed: ' . $e->getMessage());
        }
    });
}

/** The detail text of a notification (no label), for the email. */
function notificationEmailDetail(array $n): string
{
    $where = $n['company_name'] !== null ? $n['company_name'] . ' — ' . $n['branch_name'] : '';
    if (!empty($n['message'])) {
        return str_replace('&rarr;', '→', (string) $n['message']);
    }
    switch ($n['type']) {
        case 'three_month_warning':
            return $where . ' has had no order or engagement for 3 months.';
        case 'six_month_reassign':
            return $where . ' has had no order or engagement for 6 months and is flagged for reassignment.';
        case 'engagement_reminder':
            return $where . ' has had no order or logged engagement for over a month.';
        default:
            return $where;
    }
}

/** Lower-case that works with or without PHP's mbstring extension (the server may not have it enabled). */
function textLower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/** Cuts $text to at most $max characters (not bytes), adding "..." when it was cut. No mbstring needed. */
function textShorten(string $text, int $max): string
{
    if (preg_match('/^.{0,' . $max . '}$/su', $text) === 1) {
        return $text;
    }
    return (preg_match('/^.{0,' . ($max - 3) . '}/su', $text, $m) === 1 ? $m[0] : substr($text, 0, $max - 3)) . '...';
}

/**
 * The subject line of a single-notification email: what happened, in a few words, e.g.
 * "Approved: Bea has been removed" or "Approved: 7-Eleven Philippines has been transferred".
 * Falls back to "<label>: <detail>" for notification types without a special rule.
 */
function notificationEmailSubject(array $n): string
{
    $detail = trim(notificationEmailDetail($n));
    $strip = static fn(string $name): string => trim(preg_replace('/\s*\(\d+ branches\)\s*$/u', '', $name));
    if (preg_match('/^Approved: (.+?) has been removed from /u', $detail, $m)) {
        return 'Approved: ' . $m[1] . ' has been removed';
    }
    if (preg_match('/^Approved: (.+?) has been reassigned/u', $detail, $m)) {
        return 'Approved: ' . $strip($m[1]) . ' has been transferred';
    }
    if (preg_match('/^Approved, but (.+?) was already assigned/u', $detail, $m)) {
        return 'Approved: ' . $strip($m[1]) . ' (no change needed)';
    }
    if (preg_match('/^Approved: your (.+?) change for /u', $detail, $m)) {
        return 'Approved: your ' . $m[1] . ' change has been applied';
    }
    if (preg_match('/^Your request to remove (.+?) from .* was rejected/u', $detail, $m)) {
        return 'Rejected: removal of ' . $m[1];
    }
    if (preg_match('/^Your request to change the (.+?) for .* was rejected/u', $detail, $m)) {
        return 'Rejected: your ' . $m[1] . ' change';
    }
    if (preg_match('/^(.+?) has been transferred to you/u', $detail, $m)) {
        return $strip($m[1]) . ' has been transferred to you';
    }
    if (preg_match('/^(.+?) has been transferred from you/u', $detail, $m)) {
        return $strip($m[1]) . ' has been transferred away from you';
    }
    if (($n['type'] ?? '') === 'contact_changed' && $detail !== '') {
        // "Sam Reyes updated the phone of Bea at Acme — Main": already a complete subject.
        $line = rtrim($detail, '.');
        return textShorten($line, 90);
    }
    $label = NOTIFICATION_EMAIL_LABELS[$n['type']] ?? 'Notification';
    if ($detail === '') {
        return $label;
    }
    $firstSentence = preg_split('/(?<=[.!?])\s/u', $detail, 2)[0];
    $subject = $label . ': ' . $firstSentence;
    return textShorten($subject, 90);
}

/** One plain sentence for a notification (label: detail), for the plain-text part. */
function notificationEmailLine(array $n): string
{
    $label = NOTIFICATION_EMAIL_LABELS[$n['type']] ?? $n['type'];
    $detail = notificationEmailDetail($n);
    return $detail !== '' ? $label . ': ' . $detail : $label;
}

/** Accent colour of a notification's label in the email: red = needs action, amber = warning, grey = soft reminder. */
function notificationEmailColor(string $type): string
{
    if (in_array($type, ['six_month_reassign', 'branch_needs_reassignment'], true)) {
        return '#d71920';
    }
    if ($type === 'three_month_warning') {
        return '#b45309';
    }
    if ($type === 'engagement_reminder') {
        return '#707070';
    }
    return '#171717';
}

/**
 * The status line shown at the very top of a conversation email: what state the matter is in right now.
 * The subject of a conversation never changes, so this is where "Approved" / "Rejected" lives.
 * Returns [word, colour].
 */
function emailStatusBanner(array $n): array
{
    $msg = textLower((string) ($n['message'] ?? ''));
    $type = (string) ($n['type'] ?? '');
    if (strpos($msg, 'reject') !== false || strpos($msg, 'declin') !== false) {
        return ['Rejected', '#b91c1c'];
    }
    if (strpos($msg, 'pre-approved') !== false) {
        return ['Pre-approved, waiting for final approval', '#b45309'];
    }
    if (strpos($msg, 'approved') !== false) {
        return ['Approved', '#15803d'];
    }
    if (in_array($type, ['modification_pending', 'account_request_pending', 'company_assignment_pending', 'export_pending'], true)) {
        return ['Waiting for review', '#b45309'];
    }
    if ($type === 'modification_superadmin_pending') {
        return ['Waiting for final approval', '#b45309'];
    }
    if (in_array($type, ['three_month_warning', 'six_month_reassign', 'engagement_reminder', 'branch_needs_reassignment'], true)) {
        return ['Action needed', '#b45309'];
    }
    return ['Update', '#171717'];
}

/** Sends the waiting emails; returns how many people were emailed. Safe to call from a cron script too. */
function sendPendingNotificationEmails(PDO $pdo): int
{
    if (!NOTIFY_EMAIL_ENABLED) {
        return 0;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - NOTIFY_EMAIL_MAX_AGE_MINUTES * 60);
    $probe = $pdo->prepare('SELECT 1 FROM notifications WHERE emailed_at IS NULL AND created_at >= :c LIMIT 1');
    $probe->execute(['c' => $cutoff]);
    if (!$probe->fetchColumn()) {
        return 0;
    }

    // One run at a time, and a short pause after a failure so a dead mail server
    // can't make every page wait on a timeout.
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syspoint_notify_mail';
    if (is_file($base . '.fail') && time() - (int) filemtime($base . '.fail') < 60) {
        return 0;
    }
    $lock = @fopen($base . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT n.id, n.type, n.message, n.sent_to, n.branch_id, n.target_table, n.target_id, n.thread_key, n.is_read, n.dismissed_at,
                u.email, u.full_name, u.status AS user_status,
                c.name AS company_name, b.branch_name
         FROM notifications n
         JOIN users u ON u.id = n.sent_to
         LEFT JOIN branches b ON b.id = n.branch_id
         LEFT JOIN companies c ON c.id = b.company_id
         WHERE n.emailed_at IS NULL AND n.created_at >= :c
         ORDER BY n.sent_to, n.id
         LIMIT 500"
    );
    $stmt->execute(['c' => $cutoff]);
    $byUser = [];
    $skip = [];
    foreach ($stmt->fetchAll() as $n) {
        // Already read / hidden / no usable recipient: nothing to email, just stop looking at it.
        if ((int) $n['is_read'] === 1 || $n['dismissed_at'] !== null || $n['user_status'] !== 'active' || !filter_var($n['email'], FILTER_VALIDATE_EMAIL)) {
            $skip[] = (int) $n['id'];
            continue;
        }
        // One email per person per conversation: notifications of the same request stack in one thread.
        $byUser[(int) $n['sent_to'] . '|' . notificationThreadKey($n)][] = $n;
    }
    $mark = $pdo->prepare('UPDATE notifications SET emailed_at = CURRENT_TIMESTAMP WHERE id = :id');
    foreach ($skip as $id) {
        $mark->execute(['id' => $id]);
    }

    $sent = 0;
    $mail = null;
    try {
        // Each SMTP send takes a few seconds, and on Windows PHP's time limit counts real
        // time, so many emails in one run used to hit "Maximum execution time of 30 seconds".
        // Every email now gets a fresh time budget. Where the page cannot be released before
        // the sending starts (php -S, CLI-server), only a few go out per page load so the
        // browser is not kept waiting; the rest follow on the next page or the scheduled task.
        $maxThisRun = (PHP_SAPI === 'cli-server') ? 5 : NOTIFY_EMAIL_MAX_PER_RUN;
        foreach ($byUser as $items) {
            if ($sent >= $maxThisRun) {
                break;
            }
            @set_time_limit(60);
            if ($mail === null) {
                $mail = createSmtpMailer();
                $mail->SMTPKeepAlive = true;
            }
            $first = $items[0];
            $count = count($items);
            $lines = array_map('notificationEmailLine', $items);
            $esc = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

            $threadKey = notificationThreadKey($first);
            $thread = emailThread($pdo, $threadKey, $first);
            $mail->clearAddresses();
            $mail->addAddress($first['email'], $first['full_name']);
            $messageId = applyThreadHeaders($pdo, $mail, $thread, (int) $first['sent_to'], null, 'n' . $first['id']);

            // One card per notification: coloured label, then the detail.
            $shownItems = array_slice($items, 0, 25);
            $rows = '';
            foreach ($shownItems as $n) {
                $label = NOTIFICATION_EMAIL_LABELS[$n['type']] ?? $n['type'];
                // With a single notification the heading already says what it is: don't repeat it as the card's label.
                $labelHtml = $count === 1 ? '' : '<div style="font-size:12px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:' . notificationEmailColor((string) $n['type']) . ';">' . $esc($label) . '</div>';
                $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;">'
                    . '<tr><td style="padding:12px 14px;background:#fafafa;border:1px solid #e4e4e4;border-left:4px solid ' . notificationEmailColor((string) $n['type']) . ';border-radius:6px;">'
                    . $labelHtml
                    . '<div style="' . ($count === 1 ? '' : 'margin-top:4px;') . 'font-size:14px;line-height:1.5;color:#171717;">' . $esc(notificationEmailDetail($n)) . '</div>'
                    . '</td></tr></table>';
            }
            if ($count > count($shownItems)) {
                $rows .= '<p style="margin:6px 0 0;font-size:13px;color:#707070;">…and ' . ($count - count($shownItems)) . ' more in the app.</p>';
            }
            $replyHint = '';
            [$statusWord, $statusColor] = emailStatusBanner(end($items));
            $banner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;"><tr><td style="padding:10px 14px;background:' . $statusColor . ';border-radius:6px;font-size:13px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:#ffffff;">Status: ' . $esc($statusWord) . '</td></tr></table>';
            $intro = $banner . '<p style="margin:0 0 16px;">Hi ' . $esc((string) $first['full_name']) . ',</p>';
            // Who else is in this conversation, so people can write to each other directly (replies to this email are not read).
            $peopleHtml = '';
            $peopleText = '';
            $others = array_values(array_filter(emailThreadPeople($pdo, $threadKey), static fn(array $p): bool => (int) $p['id'] !== (int) $first['sent_to']));
            if ($others) {
                $peopleHtml = '<p style="margin:18px 0 6px;font-size:12px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:#707070;">Others in this conversation</p>';
                $peopleText = "\n\nOthers in this conversation:";
                foreach ($others as $p) {
                    $peopleHtml .= '<div style="font-size:14px;line-height:1.7;color:#171717;">' . $esc($p['full_name']) . ' <span style="color:#707070;">(' . $esc(emailRoleLabel((string) $p['role'])) . ')</span>'
                        . (INBOUND_REPLY_ENABLED ? '' : ' &middot; <a href="mailto:' . $esc($p['email']) . '" style="color:#d71920;text-decoration:none;">' . $esc($p['email']) . '</a>') . '</div>';
                    $peopleText .= "\n- {$p['full_name']} (" . emailRoleLabel((string) $p['role']) . ')' . (INBOUND_REPLY_ENABLED ? '' : " <{$p['email']}>");
                }
                $peopleHtml .= INBOUND_REPLY_ENABLED
                    ? '<p style="margin:8px 0 0;font-size:12px;color:#707070;">Just reply to this email. Your answer goes to everyone listed above and is saved in the CRM.</p>'
                    : '<p style="margin:8px 0 0;font-size:12px;color:#707070;">To discuss this, write to them directly. Replies to this email are not read.</p>';
                if (INBOUND_REPLY_ENABLED) {
                    $peopleText .= "\n\nJust reply to this email. Your answer goes to everyone listed above and is saved in the CRM.";
                }
            }
            $heading = $count === 1 ? (NOTIFICATION_EMAIL_LABELS[$first['type']] ?? 'New notification') : $count . ' new notifications';
            $button = APP_BASE_URL !== '' ? ['Open Syspoint CRM', APP_BASE_URL . '/notifications.php'] : null;
            $mail->Body = emailLayout($heading, $intro . $rows . $peopleHtml . $replyHint, $button, $statusWord . ' — ' . $lines[0]);
            $shown = array_slice($lines, 0, 25);
            $mail->AltBody = "Hi {$first['full_name']},\n\n- " . implode("\n- ", $shown) . $peopleText
                . (APP_BASE_URL !== '' ? "\n\nOpen: " . APP_BASE_URL . '/notifications.php' : '')
                . emailPlainFooter();
            $mail->send();
            emailThreadLog($pdo, $threadKey, $messageId, (int) $first['sent_to'], 'out', $lines[0]);
            emailThreadAddParticipant($pdo, $threadKey, (int) $first['sent_to']);
            foreach ($items as $n) {
                $mark->execute(['id' => (int) $n['id']]);
            }
            $sent++;
        }
        if (is_file($base . '.fail')) {
            @unlink($base . '.fail');
        }
    } catch (Throwable $e) {
        error_log('Notification email failed: ' . $e->getMessage());
        @touch($base . '.fail');
    } finally {
        if ($mail !== null) {
            try {
                $mail->smtpClose();
            } catch (Throwable $e) {
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $sent;
}

/**
 * Notifies every active user in the given role(s). branchId is required for
 * the branch-scoped types (modification requests) and left null for
 * account-level ones, which instead pass targetTable/targetId/message so
 * notifications.php has something to show without a branch to name.
 */
function notifyRoles(PDO $pdo, array $roles, string $type, ?int $branchId = null, ?string $targetTable = null, ?int $targetId = null, ?string $message = null, ?string $threadKey = null): void
{
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND status = 'active'");
    $stmt->execute($roles);
    $recipientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($recipientIds as $recipientId) {
        notifyUser($pdo, (int) $recipientId, $type, $branchId, $targetTable, $targetId, $message, $threadKey);
    }
}

/**
 * Same as notifyRoles but for a single known recipient — used to tell an
 * admin their account request was approved/rejected, rather than broadcasting
 * to a whole role.
 */
function notifyUser(PDO $pdo, int $userId, string $type, ?int $branchId = null, ?string $targetTable = null, ?int $targetId = null, ?string $message = null, ?string $threadKey = null): void
{
    $pdo->prepare(
        'INSERT INTO notifications (branch_id, type, sent_to, target_table, target_id, message, is_read, thread_key)
         VALUES (:bid, :type, :uid, :tt, :tid, :msg, 0, :tk)'
    )->execute([
        'bid' => $branchId,
        'type' => $type,
        'uid' => $userId,
        'tt' => $targetTable,
        'tid' => $targetId,
        'msg' => $message,
        'tk' => $threadKey,
    ]);
}

/**
 * Keeps a notification message within the 255-character column, cutting on a
 * character boundary. Not mb_substr(): nothing else in this project needs the
 * mbstring extension, so this shouldn't be the thing that requires it.
 */
function userFullName(PDO $pdo, ?int $userId): string
{
    if ($userId === null) {
        return 'nobody';
    }
    $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $name = $stmt->fetchColumn();
    return $name !== false ? (string) $name : ('User #' . $userId);
}

function truncateForNotification(string $text, int $max = 255): string
{
    if (preg_match('/^.{0,' . $max . '}/su', $text, $m) === 1) {
        return $m[0];
    }
    return substr($text, 0, $max); // not valid UTF-8: fall back to bytes
}

/**
 * A company belongs to exactly ONE salesperson, whatever its number of branches, so every
 * transfer moves all of the company's active branches together. The helpers below load a
 * company from any one of its branches and validate a transfer of it.
 */
function loadCompanyForTransfer(PDO $pdo, int $branchId): ?array
{
    $head = $pdo->prepare(
        "SELECT b.company_id, c.name AS company_name
         FROM branches b JOIN companies c ON c.id = b.company_id
         WHERE b.id = :id AND b.status = 'active'"
    );
    $head->execute(['id' => $branchId]);
    $company = $head->fetch();
    if (!$company) {
        return null;
    }
    $rows = $pdo->prepare(
        "SELECT b.id, b.assigned_user_id, u.role AS owner_role
         FROM branches b LEFT JOIN users u ON u.id = b.assigned_user_id
         WHERE b.company_id = :cid AND b.status = 'active'
         ORDER BY b.id"
    );
    $rows->execute(['cid' => $company['company_id']]);
    return [
        'company_id' => (int) $company['company_id'],
        'company_name' => $company['company_name'],
        'branches' => $rows->fetchAll(),
        'clicked_branch_id' => $branchId,
    ];
}

/** "Acme Corp" for a one-branch company, "Acme Corp (4 branches)" otherwise — never a branch name. */
function companyTransferLabel(array $company): string
{
    $n = count($company['branches']);
    return $company['company_name'] . ($n > 1 ? " ({$n} branches)" : '');
}

function companyIdOfBranch(PDO $pdo, int $branchId): int
{
    $stmt = $pdo->prepare('SELECT company_id FROM branches WHERE id = :id');
    $stmt->execute(['id' => $branchId]);
    return (int) $stmt->fetchColumn();
}

/** True while a reassignment request for any branch of this company is still open. */
function openCompanyTransferRequestExists(PDO $pdo, int $companyId): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM modification_requests mr JOIN branches b ON b.id = mr.branch_id
         WHERE b.company_id = :cid AND mr.field_name = 'assigned_user_id' AND mr.status IN ('pending', 'admin_approved')"
    );
    $stmt->execute(['cid' => $companyId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Re-checks everything at the moment a transfer is requested or applied:
 *  - the company still has the active branch it was opened from
 *  - none of its branches is held by an admin or super admin (they aren't supposed to hold
 *    clients, so a transfer refuses to move anything away from one)
 *  - the new assignee is still an active employee
 *  - at least one branch would actually change hands
 * Returns ['error' => ?string, 'assignee_name', 'old_ids' (every previous owner), 'old_id'
 * (the one to name in messages), 'clicked_owner'].
 */
function checkCompanyTransfer(PDO $pdo, ?array $company, int $assigneeId): array
{
    if ($company === null) {
        return ['error' => 'That client no longer exists.'];
    }
    foreach ($company['branches'] as $b) {
        if ($b['owner_role'] !== null && $b['owner_role'] !== 'user') {
            return ['error' => 'This company is currently held by an admin or super admin and can\'t be reassigned here.'];
        }
    }
    $assigneeStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id AND role = 'user' AND status = 'active'");
    $assigneeStmt->execute(['id' => $assigneeId]);
    $assigneeName = $assigneeStmt->fetchColumn();
    if (!$assigneeName) {
        return ['error' => 'Choose an active employee to assign this to.'];
    }

    $oldIds = [];
    $needsChange = false;
    $clickedOwner = null;
    foreach ($company['branches'] as $b) {
        $owner = $b['assigned_user_id'] !== null ? (int) $b['assigned_user_id'] : null;
        if ((int) $b['id'] === $company['clicked_branch_id']) {
            $clickedOwner = $owner;
        }
        if ($owner !== $assigneeId) {
            $needsChange = true;
            if ($owner !== null) {
                $oldIds[$owner] = $owner;
            }
        }
    }
    if (!$needsChange) {
        return ['error' => 'This company is already assigned to that employee.'];
    }
    $oldIds = array_values($oldIds);
    $oldId = ($clickedOwner !== null && $clickedOwner !== $assigneeId) ? $clickedOwner : ($oldIds[0] ?? null);
    return ['error' => null, 'assignee_name' => $assigneeName, 'old_ids' => $oldIds, 'old_id' => $oldId, 'clicked_owner' => $clickedOwner];
}

/**
 * Applies a direct transfer (super admin instant path). Given any one branch, it moves the
 * WHOLE company — every active branch — to the new employee, so a company can never end up
 * split between two salespeople. Re-validated at the moment it's applied (see
 * checkCompanyTransfer). Returns ['old_id' => ?int, 'old_ids' => int[], 'new_id' => int,
 * 'branch_label' => string (the company), 'branch_count' => int, 'company_id' => int];
 * throws RuntimeException with a user-facing message otherwise.
 * Shared by manage_companies.php (reassign from the client popup) and manage_users.php.
 */
function reassignBranch(PDO $pdo, int $branchId, int $newAssigneeId): array
{
    $company = loadCompanyForTransfer($pdo, $branchId);
    $check = checkCompanyTransfer($pdo, $company, $newAssigneeId);
    if ($check['error'] !== null) {
        throw new RuntimeException($check['error']);
    }

    $pdo->prepare("UPDATE branches SET assigned_user_id = :new WHERE company_id = :cid AND status = 'active'")
        ->execute(['new' => $newAssigneeId, 'cid' => $company['company_id']]);

    return [
        'old_id' => $check['old_id'],
        'old_ids' => $check['old_ids'],
        'new_id' => $newAssigneeId,
        'branch_label' => companyTransferLabel($company),
        'branch_count' => count($company['branches']),
        'company_id' => $company['company_id'],
    ];
}

/**
 * Same transfer notices as a change-request outcome (see notifyChangeOutcome
 * in approvals.php): every previous owner hears they lost the company, the new
 * owner hears they got it. Nobody is notified about their own action, and a
 * "reassignment" to the same employee sends nothing (nothing changed).
 */
function notifyBranchTransfer(PDO $pdo, array $result, int $actingUserId): void
{
    $oldId = $result['old_id'];
    $newId = $result['new_id'];
    if ($oldId === $newId) {
        return;
    }
    $oldIds = $result['old_ids'] ?? ($oldId !== null ? [$oldId] : []);
    $label = $result['branch_label'];
    $newName = userFullName($pdo, $newId);
    foreach ($oldIds as $previousOwnerId) {
        if ($previousOwnerId !== $newId && $previousOwnerId !== $actingUserId) {
            notifyUser($pdo, $previousOwnerId, 'branch_transferred_out', null, null, null, truncateForNotification($label . ' has been transferred from you to ' . $newName . '.'));
        }
    }
    if ($newId !== $actingUserId) {
        $oldName = $oldId !== null ? userFullName($pdo, $oldId) : null;
        $msg = $label . ' has been transferred to you' . ($oldName !== null ? ' from ' . $oldName : '') . '.';
        notifyUser($pdo, $newId, 'branch_transferred_in', null, 'branches', null, truncateForNotification($msg));
    }
}

/**
 * Stages a company transfer the way an admin's request always is: re-validates
 * everything now, then files ONE assigned_user_id change request for the company
 * (attached to the branch it was opened from) so it lands on Approvals and reuses
 * its whole review/apply/notify flow. Approving it moves every branch of the company.
 * A second request for the same company is refused while one is still open.
 * Returns ['ok' => bool, 'error' => ?string] — never throws, so a bulk caller can
 * keep going after one company fails.
 */
function requestBranchReassignment(PDO $pdo, int $branchId, int $assigneeId, int $requestedByUserId): array
{
    $company = loadCompanyForTransfer($pdo, $branchId);
    $check = checkCompanyTransfer($pdo, $company, $assigneeId);
    if ($check['error'] !== null) {
        return ['ok' => false, 'error' => $check['error']];
    }
    if (openCompanyTransferRequestExists($pdo, $company['company_id'])) {
        return ['ok' => false, 'error' => 'A transfer request for this company is already waiting for approval.'];
    }

    $label = companyTransferLabel($company);
    $pdo->prepare(
        'INSERT INTO modification_requests (branch_id, contact_id, requested_by, field_name, old_value, new_value, status)
         VALUES (:bid, NULL, :uid, \'assigned_user_id\', :old, :new, \'pending\')'
    )->execute(['bid' => $branchId, 'uid' => $requestedByUserId, 'old' => $check['old_id'], 'new' => $assigneeId]);
    $requestId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'edit_request', 'modification_requests', :tid, :details)")
        ->execute(['uid' => $requestedByUserId, 'tid' => $requestId, 'details' => "Requested to transfer {$label} to {$check['assignee_name']}"]);

    return ['ok' => true, 'error' => null];
}

/**
 * Single entry point for "transfer this company" given one of its branches: immediate
 * for super admin (reassignBranch + audit + transfer notices), staged for admin
 * (requestBranchReassignment). Always returns a per-company outcome rather than
 * throwing, so a bulk caller can report "3 of 5 reassigned" instead of losing the
 * whole batch to one bad company.
 */
function applyOrRequestBranchReassignment(PDO $pdo, string $role, int $actingUserId, int $branchId, int $assigneeId): array
{
    if ($role === 'super_admin') {
        try {
            $result = reassignBranch($pdo, $branchId, $assigneeId);
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'branch_reassign', 'branches', :tid, :details)")
                ->execute(['uid' => $actingUserId, 'tid' => $branchId, 'details' => 'Reassigned ' . $result['branch_label'] . ' to ' . userFullName($pdo, $assigneeId)]);
            notifyBranchTransfer($pdo, $result, $actingUserId);
            return ['ok' => true, 'error' => null, 'staged' => false];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'staged' => false];
        }
    }

    $result = requestBranchReassignment($pdo, $branchId, $assigneeId, $actingUserId);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'staged' => true];
}

/**
 * Same as applyOrRequestBranchReassignment() but starting from a company id (the Manage
 * Users panel lists companies, not branches). Picks one active branch to attach the
 * transfer to — preferably one held by $preferOwnerId, the person whose panel it was
 * opened from — since the transfer itself always moves the whole company.
 */
function applyOrRequestCompanyReassignment(PDO $pdo, string $role, int $actingUserId, int $companyId, int $assigneeId, ?int $preferOwnerId = null): array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM branches WHERE company_id = :cid AND status = 'active'
         ORDER BY CASE WHEN assigned_user_id = :owner THEN 0 ELSE 1 END, id LIMIT 1"
    );
    $stmt->execute(['cid' => $companyId, 'owner' => $preferOwnerId ?? 0]);
    $branchId = $stmt->fetchColumn();
    if (!$branchId) {
        return ['ok' => false, 'error' => 'That company no longer has any active clients.', 'staged' => $role !== 'super_admin'];
    }
    return applyOrRequestBranchReassignment($pdo, $role, $actingUserId, (int) $branchId, $assigneeId);
}

/**
 * Guard for adding a branch to a company that already exists: a company can only ever be
 * handled by one salesperson, so a new branch may only go to whoever already holds it.
 * Returns a user-facing message when that would split the company, otherwise null.
 * $selfService is true when the salesperson is adding the branch for themselves.
 */
function companyOwnerConflictMessage(PDO $pdo, int $companyId, int $intendedOwnerId, bool $selfService): ?string
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT b.assigned_user_id AS id, u.full_name
         FROM branches b JOIN users u ON u.id = b.assigned_user_id
         WHERE b.company_id = :cid AND b.status = 'active' AND b.assigned_user_id <> :uid
         ORDER BY b.assigned_user_id LIMIT 1"
    );
    $stmt->execute(['cid' => $companyId, 'uid' => $intendedOwnerId]);
    $owner = $stmt->fetch();
    if (!$owner) {
        return null;
    }
    $nameStmt = $pdo->prepare('SELECT name FROM companies WHERE id = :id');
    $nameStmt->execute(['id' => $companyId]);
    $company = (string) $nameStmt->fetchColumn();
    return $selfService
        ? "{$company} is already handled by {$owner['full_name']}. A company can only have one salesperson, so ask an admin to transfer it to you first."
        : "{$company} is already handled by {$owner['full_name']}. A company can only have one salesperson: assign this branch to {$owner['full_name']}, or transfer the whole company first.";
}

/** Distinct companies (not branches) still assigned to a user — what Manage Users counts. */
function countActiveCompaniesForUser(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT company_id) FROM branches WHERE assigned_user_id = :uid AND status = 'active'");
    $stmt->execute(['uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Active branches still assigned to a given user, newest company name
 * first. Powers the "companies" panel on manage_users.php — shown for every
 * account so admin/super admin can see what a person holds (and, if the
 * account is deactivated, move it to someone else) without leaving the page.
 */
function getActiveBranchesForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT b.id, b.branch_name, c.name AS company_name
         FROM branches b JOIN companies c ON c.id = b.company_id
         WHERE b.assigned_user_id = :uid AND b.status = 'active'
         ORDER BY c.name, b.branch_name"
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Called right after a user is deactivated (both the super admin instant
 * path and an approved account_requests row). Deactivating doesn't touch
 * assigned_user_id — the branch trigger allows it, since the account is
 * still role='user' — so this is the only thing that surfaces the gap:
 * whoever holds admin/super admin gets a heads-up, and manage_users.php's
 * companies panel (getActiveBranchesForUser above) is where they act on it.
 * A no-op if the deactivated account had no active clients.
 */
function flagBranchesForReassignment(PDO $pdo, int $deactivatedUserId, int $actingUserId): void
{
    $branches = getActiveBranchesForUser($pdo, $deactivatedUserId);
    if (empty($branches)) {
        return;
    }

    $name = userFullName($pdo, $deactivatedUserId);
    // Counted per company: a company moves as one unit, whatever its number of branches.
    $companyNames = array_values(array_unique(array_column($branches, 'company_name')));
    $count = count($companyNames);
    $message = $count === 1
        ? "{$name}'s account was deactivated — {$companyNames[0]} needs reassignment."
        : "{$name}'s account was deactivated — {$count} companies need reassignment.";
    $message = truncateForNotification($message);

    // Every active admin AND super admin hears about it — including whoever just did
    // it, so a super admin who deactivates someone directly still gets the notice
    // (and, later, the matching email) that those clients now need a new owner.
    $recipients = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'");
    foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        notifyUser($pdo, (int) $recipientId, 'branch_needs_reassignment', null, 'users', $deactivatedUserId, $message);
    }
}

/**
 * CREATE TABLE IF NOT EXISTS (above) only helps when a whole table is missing —
 * it's a no-op on a table that already exists, even if that existing table is
 * missing a column that got added later. Add a new line here any time a column
 * gets added to an existing table — nobody's local database needs to be touched
 * by hand for it to pick it up.
 */
function healMissingColumns(PDO $pdo): void
{
    $columnMigrations = [
        ['table' => 'modification_requests', 'column' => 'contact_id', 'definition' => 'INTEGER REFERENCES contact_persons(id)'],
        ['table' => 'companies', 'column' => 'description', 'definition' => 'TEXT'],
        ['table' => 'users', 'column' => 'must_change_password', 'definition' => 'INTEGER NOT NULL DEFAULT 0'],
        ['table' => 'contact_persons', 'column' => 'created_by', 'definition' => 'INTEGER REFERENCES users(id)'],
        // Carries a branch's "last modified" across audit-log rotation — see pruneAuditLogs().
        ['table' => 'branches', 'column' => 'last_modified', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // Hidden-but-kept marker for deleted 3/6-month alerts — see deleteNotificationsForUser().
        ['table' => 'notifications', 'column' => 'dismissed_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // The requester's stated reason for the change, captured on modification_request.php
        // so an admin/super admin reviewing it (approvals.php) and the requester themselves
        // (my_requests.php) can see why it was asked for.
        ['table' => 'modification_requests', 'column' => 'reason', 'definition' => 'TEXT'],
        // Wrong-guess counter for one-time codes — see verifyRegistrationOtp() / verifyPasswordResetOtp().
        ['table' => 'otp_tokens', 'column' => 'attempts', 'definition' => 'INTEGER NOT NULL DEFAULT 0'],
        ['table' => 'registration_otp', 'column' => 'attempts', 'definition' => 'INTEGER NOT NULL DEFAULT 0'],
        // Remarks at each review stage, when the super admin's final decision landed
        // (drives the 7-day auto-hide), and the requester's manual "delete" (hide).
        // Up to two numbers per contact person, each Mobile or Telephone (contact_number is the first).
        ['table' => 'contact_persons', 'column' => 'contact_number_type', 'definition' => "VARCHAR(10) NOT NULL DEFAULT 'mobile'"],
        ['table' => 'contact_persons', 'column' => 'contact_number_2', 'definition' => 'VARCHAR(50) NULL DEFAULT NULL'],
        ['table' => 'contact_persons', 'column' => 'contact_number_2_type', 'definition' => "VARCHAR(10) NOT NULL DEFAULT 'mobile'"],
        // Third number onwards, as a JSON list of {"type":"mobile|telephone","number":"..."}.
        ['table' => 'contact_persons', 'column' => 'contact_numbers_extra', 'definition' => 'TEXT NULL'],
        ['table' => 'contact_persons', 'column' => 'contact_emails_extra', 'definition' => 'TEXT NULL'],
        // Position of the contact in its branch's list: 1 = primary, 2 = secondary, 3 = third ... 0 on older rows (they then sort by id, i.e. oldest first).
        ['table' => 'contact_persons', 'column' => 'sort_order', 'definition' => 'INTEGER NOT NULL DEFAULT 0'],
        // Set once a notification has been emailed to its recipient (see sendPendingNotificationEmails).
        ['table' => 'notifications', 'column' => 'emailed_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // Set when the requester cancels their own export request (status becomes 'rejected').
        ['table' => 'approval_requests', 'column' => 'cancelled_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        ['table' => 'modification_requests', 'column' => 'admin_remark', 'definition' => 'TEXT'],
        ['table' => 'modification_requests', 'column' => 'superadmin_remark', 'definition' => 'TEXT'],
        ['table' => 'modification_requests', 'column' => 'resolved_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        ['table' => 'modification_requests', 'column' => 'requester_hidden_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // Account lock after repeated wrong passwords — see lockAccountOnFailure() / unlockAccount().
        ['table' => 'users', 'column' => 'failed_logins', 'definition' => 'INTEGER NOT NULL DEFAULT 0'],
        ['table' => 'users', 'column' => 'locked_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // The note a locked user must send from the login page before an admin can unlock them.
        ['table' => 'users', 'column' => 'lock_note', 'definition' => 'TEXT NULL'],
        ['table' => 'users', 'column' => 'lock_note_at', 'definition' => 'TIMESTAMP NULL DEFAULT NULL'],
        // Optional fixed email conversation for a notification (e.g. one per account lock) — see notificationThreadKey().
        ['table' => 'notifications', 'column' => 'thread_key', 'definition' => 'VARCHAR(120) NULL DEFAULT NULL'],
    ];

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    foreach ($columnMigrations as $m) {
        $exists = false;

        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info({$m['table']})")->fetchAll();
            foreach ($cols as $c) {
                if ($c['name'] === $m['column']) {
                    $exists = true;
                    break;
                }
            }
        } else {
            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $check->execute(['t' => $m['table'], 'c' => $m['column']]);
            $exists = (int) $check->fetchColumn() > 0;
        }

        if (!$exists) {
            $pdo->exec("ALTER TABLE {$m['table']} ADD COLUMN {$m['column']} {$m['definition']}");
        }
    }
}

/**
 * login_attempts backs the login lockout. Created here (not only in the schema
 * files) because on MySQL schema.sql runs once, on an empty database — an
 * existing database would never pick up a table added later. Idempotent.
 */
function ensureLoginAttemptsTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                email        TEXT NOT NULL,
                ip_address   TEXT NOT NULL,
                attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts (email, attempted_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts (ip_address, attempted_at)');
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            email        VARCHAR(255) NOT NULL,
            ip_address   VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_email (email, attempted_at),
            INDEX idx_login_attempts_ip (ip_address, attempted_at)
        )'
    );
}

/**
 * schema.sql only runs on an EMPTY MySQL database, so a database that was
 * created (or migrated in) before the ownership triggers existed never gets
 * them. This creates whichever of the two is missing. MySQL/MariaDB only —
 * SQLite gets its triggers from schema_sqlite.sql, which runs on every connect.
 * Needs the TRIGGER privilege (and, if binary logging is on,
 * log_bin_trust_function_creators=1 or SUPER); failure is logged, not fatal,
 * and check_orphaned_branches.php reports whether they're present.
 */
function healMysqlBranchOwnerTriggers(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return;
    }

    $triggers = [
        'trg_branches_owner_insert' => "CREATE TRIGGER trg_branches_owner_insert
            BEFORE INSERT ON branches
            FOR EACH ROW
            BEGIN
                IF NEW.assigned_user_id IS NULL OR NOT EXISTS (
                    SELECT 1 FROM users WHERE id = NEW.assigned_user_id AND role = 'user'
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'branches.assigned_user_id must reference a user-role account';
                END IF;
            END",
        'trg_branches_owner_update' => "CREATE TRIGGER trg_branches_owner_update
            BEFORE UPDATE ON branches
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.assigned_user_id <=> OLD.assigned_user_id) AND (
                    NEW.assigned_user_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM users WHERE id = NEW.assigned_user_id AND role = 'user'
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'branches.assigned_user_id must reference a user-role account';
                END IF;
            END",
    ];

    try {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = :n'
        );
        foreach ($triggers as $name => $sql) {
            $check->execute(['n' => $name]);
            if ((int) $check->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('Could not create branch-ownership triggers: ' . $e->getMessage());
    }
}

/**
 * Login throttling. Failures are counted per email (LOGIN_MAX_FAILURES inside
 * LOGIN_LOCKOUT_MINUTES) and, more loosely, per IP address. Timestamps are
 * written from PHP in UTC so SQLite and MySQL agree. Note the trade-off: anyone
 * can lock a known email out for 15 minutes by failing on purpose — it clears
 * itself, and the alternative is unlimited password guessing.
 */
function loginClientIp(): string
{
    return clientIp();
}

function loginIsLockedOut(PDO $pdo, string $email): bool
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);

    $byEmail = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = :e AND attempted_at >= :c');
    $byEmail->execute(['e' => strtolower(substr($email, 0, 255)), 'c' => $cutoff]);
    if ((int) $byEmail->fetchColumn() >= LOGIN_MAX_FAILURES) {
        return true;
    }

    $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at >= :c');
    $byIp->execute(['ip' => loginClientIp(), 'c' => $cutoff]);
    return (int) $byIp->fetchColumn() >= LOGIN_MAX_FAILURES_PER_IP;
}

function recordLoginFailure(PDO $pdo, string $email): void
{
    $pdo->prepare('INSERT INTO login_attempts (email, ip_address, attempted_at) VALUES (:e, :ip, :t)')
        ->execute([
            'e' => strtolower(substr($email, 0, 255)),
            'ip' => loginClientIp(),
            't' => gmdate('Y-m-d H:i:s'),
        ]);

    // Keep the table small: nothing older than a day is ever consulted.
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :old')
        ->execute(['old' => gmdate('Y-m-d H:i:s', time() - 86400)]);
}

function clearLoginFailures(PDO $pdo, string $email): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE email = :e')
        ->execute(['e' => strtolower(substr($email, 0, 255))]);
}

/**
 * Counts a wrong password against a real account and locks it on the LOGIN_ACCOUNT_LOCK_AFTER-th
 * in a row. Returns true when this failure locked it. The lock stays until unlockAccount().
 * Super admin accounts are never locked this way (nobody could unlock them).
 */
function lockAccountOnFailure(PDO $pdo, array $user): bool
{
    if ($user['role'] === 'super_admin' || !empty($user['locked_at'])) {
        return false;
    }
    $id = (int) $user['id'];
    $pdo->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = :id')->execute(['id' => $id]);
    $count = $pdo->prepare('SELECT failed_logins FROM users WHERE id = :id');
    $count->execute(['id' => $id]);
    if ((int) $count->fetchColumn() < LOGIN_ACCOUNT_LOCK_AFTER) {
        return false;
    }

    $pdo->prepare('UPDATE users SET locked_at = CURRENT_TIMESTAMP, lock_note = NULL, lock_note_at = NULL WHERE id = :id AND locked_at IS NULL')->execute(['id' => $id]);
    $detail = "Account locked after " . LOGIN_ACCOUNT_LOCK_AFTER . " wrong passwords: {$user['full_name']} ({$user['email']}) — needs an unlock in Manage users";
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:u, 'account_locked', 'users', :t, :d)")
        ->execute(['u' => $id, 't' => $id, 'd' => $detail]);
    // Admins can only unlock plain users; a locked admin (or special user) is for the super admin.
    // Every email about this lock (the lock itself, each note, the unlock) shares one conversation.
    notifyRoles($pdo, $user['role'] === 'user' ? ['admin', 'super_admin'] : ['super_admin'], 'account_request_pending', null, 'users', $id, $detail, lockThreadKey($pdo, $id));
    return true;
}

/**
 * The email conversation of an account's CURRENT lock: 'lock-' + the id of its "account_locked" audit entry,
 * so every lock gets its own thread (the lock email, each note, the unlock) and a later lock starts a new one.
 * If that entry is missing (audit log rotation), a fresh one is written so the key still exists.
 */
function lockThreadKey(PDO $pdo, int $userId): string
{
    $q = $pdo->prepare("SELECT id FROM audit_logs WHERE action = 'account_locked' AND target_table = 'users' AND target_id = :u ORDER BY id DESC LIMIT 1");
    $q->execute(['u' => $userId]);
    $id = (int) $q->fetchColumn();
    if ($id <= 0) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:u, 'account_locked', 'users', :u2, 'Account lock')")
            ->execute(['u' => $userId, 'u2' => $userId]);
        $id = (int) $pdo->lastInsertId();
    }
    return 'lock-' . $id;
}

/**
 * Tells the unlocked person (email is the only place they'd see it) and every other admin/super admin who
 * could have unlocked them, in the same conversation as the lock. $unlocker is the person who clicked Unlock.
 */
function notifyAccountUnlocked(PDO $pdo, array $target, array $unlocker): void
{
    $key = lockThreadKey($pdo, (int) $target['id']);
    notifyUser($pdo, (int) $target['id'], 'account_request_reviewed', null, 'users', (int) $target['id'],
        "Your account was unlocked by {$unlocker['full_name']}. You can sign in again with your password.", $key);
    $roles = $target['role'] === 'user' ? ['admin', 'super_admin'] : ['super_admin'];
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($marks) AND status = 'active' AND id <> ?");
    $stmt->execute(array_merge($roles, [(int) $unlocker['id']]));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        notifyUser($pdo, (int) $adminId, 'account_request_reviewed', null, 'users', (int) $target['id'],
            "{$unlocker['full_name']} unlocked {$target['full_name']}'s account.", $key);
    }
}

/** How many notes a locked account holds (they are stored in one column, separated by LOCK_NOTE_SEPARATOR). */
function lockNoteCount(?string $stored): int
{
    $stored = trim((string) $stored);
    return $stored === '' ? 0 : substr_count($stored, LOCK_NOTE_SEPARATOR) + 1;
}

/**
 * Adds a note from a locked user and tells whoever can unlock them. Notes are added, never replaced,
 * up to LOCK_NOTE_MAX_NOTES per lock, so one stranger's junk note can't stop the real owner from
 * explaining. Returns false when the account isn't locked or is already at the limit.
 */
function saveLockNote(PDO $pdo, int $userId, string $note): bool
{
    $cur = $pdo->prepare('SELECT full_name, email, role, locked_at, lock_note FROM users WHERE id = :id');
    $cur->execute(['id' => $userId]);
    $user = $cur->fetch();
    if (!$user || empty($user['locked_at']) || lockNoteCount($user['lock_note']) >= LOCK_NOTE_MAX_NOTES) {
        return false;
    }
    $combined = trim((string) $user['lock_note']) === '' ? $note : trim((string) $user['lock_note']) . LOCK_NOTE_SEPARATOR . $note;
    $pdo->prepare('UPDATE users SET lock_note = :n, lock_note_at = CURRENT_TIMESTAMP WHERE id = :id AND locked_at IS NOT NULL')
        ->execute(['n' => $combined, 'id' => $userId]);

    $detail = "{$user['full_name']} sent a note about their locked account: " . $note;
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:u, 'account_lock_note', 'users', :t, :d)")
        ->execute(['u' => $userId, 't' => $userId, 'd' => $detail]);
    notifyRoles($pdo, $user['role'] === 'user' ? ['admin', 'super_admin'] : ['super_admin'], 'account_request_pending', null, 'users', $userId, truncateForNotification($detail), lockThreadKey($pdo, $userId));
    return true;
}

function clearAccountFailures(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET failed_logins = 0 WHERE id = :id AND failed_logins <> 0')->execute(['id' => $userId]);
}

function unlockAccount(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET locked_at = NULL, lock_note = NULL, lock_note_at = NULL, failed_logins = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $userId]);
    $pdo->prepare('DELETE FROM login_attempts WHERE email = (SELECT LOWER(email) FROM users WHERE id = :id)')->execute(['id' => $userId]);
}

/**
 * ---- Contact phone numbers -------------------------------------------------
 * A contact has up to two numbers, each typed Mobile or Telephone. Only Mobile
 * is exactly 11 digits and Telephone is 9 to 10 digits (area code included), in both
 * cases not counting dashes or spaces.
 */
function phoneDigitsOnly(string $number): string
{
    return preg_replace('/[\s\-]+/', '', $number);
}

/** Returns an error message, or null when the number is acceptable. Empty is acceptable. */
function validateContactNumber(string $number, string $type): ?string
{
    $clean = phoneDigitsOnly(trim($number));
    if ($clean === '') {
        return null;
    }
    if ($type === 'mobile') {
        return preg_match('/^\d{11}$/', $clean) ? null : 'Mobile numbers must be exactly 11 digits (dashes and spaces are not counted).';
    }
    if ($type === 'telephone') {
        $digitCount = preg_match_all('/\d/', $clean);
        if (!preg_match('/^[\d()+.]+$/', $clean)) {
            return 'Telephone numbers can only contain digits, spaces, dashes, brackets and +.';
        }
        return ($digitCount >= 9 && $digitCount <= 10)
            ? null : 'Telephone numbers must be 9 to 10 digits (dashes and spaces are not counted).';
    }
    return 'Choose Mobile or Telephone for each number.';
}

/**
 * Reads the repeated contact_* POST arrays used by both the add-company page and the
 * admin add-company form into clean rows. Throws RuntimeException with a message the
 * page can show. Blocks left completely empty are skipped.
 */
function parseContactRows(array $post): array
{
    $names = $post['contact_name'] ?? [];
    $rows = [];
    for ($i = 0; $i < count($names); $i++) {
        $row = [
            'name' => trim((string) ($names[$i] ?? '')),
            'position' => trim((string) ($post['contact_position'][$i] ?? '')),
            'number' => trim((string) ($post['contact_number'][$i] ?? '')),
            'number_type' => (string) ($post['contact_number_type'][$i] ?? 'mobile'),
            'number2' => trim((string) ($post['contact_number_2'][$i] ?? '')),
            'number2_type' => (string) ($post['contact_number_2_type'][$i] ?? 'mobile'),
            'email' => trim((string) ($post['contact_email'][$i] ?? '')),
            'extras_raw' => (string) ($post['contact_extra'][$i] ?? ''),
            'email_extras_raw' => (string) ($post['contact_email_extra'][$i] ?? ''),
            'rank' => (int) ($post['contact_rank'][$i] ?? 0),
            'index' => $i,
        ];
        $extras = parseExtraNumbers($row['extras_raw']);
        $emailExtras = parseExtraEmails($row['email_extras_raw']);
        if ($row['name'] === '' && $row['position'] === '' && $row['number'] === '' && $row['number2'] === '' && $row['email'] === '' && !$extras['list'] && !$emailExtras['list']) {
            continue;
        }
        if ($row['name'] === '') {
            throw new RuntimeException('Each contact needs at least a name — remove any empty contact blocks.');
        }
        foreach ([['number', 'number_type'], ['number2', 'number2_type']] as [$numKey, $typeKey]) {
            if (!in_array($row[$typeKey], ['mobile', 'telephone'], true)) {
                $row[$typeKey] = 'mobile';
            }
            $err = validateContactNumber($row[$numKey], $row[$typeKey]);
            if ($err !== null) {
                throw new RuntimeException($row['name'] . ': ' . $err);
            }
        }
        if ($extras['error'] !== null) {
            throw new RuntimeException($row['name'] . ': ' . $extras['error']);
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($row['name'] . ': enter a valid email address, or leave it blank.');
        }
        if ($emailExtras['error'] !== null) {
            throw new RuntimeException($row['name'] . ': ' . $emailExtras['error']);
        }
        unset($row['extras_raw'], $row['email_extras_raw']);
        $row['extras'] = $extras['list'];
        $row['extras_json'] = $extras['list'] ? json_encode($extras['list'], JSON_UNESCAPED_UNICODE) : null;
        $row['email_extras_json'] = $emailExtras['list'] ? json_encode($emailExtras['list'], JSON_UNESCAPED_UNICODE) : null;
        $rows[] = $row;
    }
    return $rows;
}

/** Primary for the first contact, Secondary for every other one (position $rank, 1-based). */
function contactRankLabel(int $rank): string
{
    if ($rank === 1) { return 'Primary'; }
    return 'Secondary';
}

/**
 * The order contacts are inserted in for a brand-new branch: by the rank the person picked
 * (blank = the position in the form), ties keeping the form order. Returns the rows reordered.
 */
function orderContactRowsByRank(array $rows): array
{
    foreach ($rows as $i => &$r) {
        $r['_pos'] = $i;
        $r['_rank'] = ($r['rank'] ?? 0) > 0 ? (int) $r['rank'] : $i + 1;
    }
    unset($r);
    usort($rows, static fn($a, $b) => [$a['_rank'], $a['_pos']] <=> [$b['_rank'], $b['_pos']]);
    return $rows;
}

/**
 * Puts one contact at position $rank (1 = primary) in its branch's list and renumbers the whole
 * list 1..n, so the order is always gap-free. A rank past the end means "last".
 */
function moveContactToRank(PDO $pdo, int $branchId, int $contactId, int $rank): void
{
    $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE branch_id = :b AND status = 'active' ORDER BY sort_order, id");
    $stmt->execute(['b' => $branchId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids = array_values(array_filter($ids, static fn($id) => $id !== $contactId));
    $index = max(0, min(count($ids), $rank - 1));
    array_splice($ids, $index, 0, [$contactId]);
    $upd = $pdo->prepare('UPDATE contact_persons SET sort_order = :o WHERE id = :id');
    foreach ($ids as $i => $id) {
        $upd->execute(['o' => $i + 1, 'id' => $id]);
    }
}

/** Renumbers a branch's active contacts 1..n in their current order (after one is removed). */
function renumberContacts(PDO $pdo, int $branchId): void
{
    $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE branch_id = :b AND status = 'active' ORDER BY sort_order, id");
    $stmt->execute(['b' => $branchId]);
    $upd = $pdo->prepare('UPDATE contact_persons SET sort_order = :o WHERE id = :id');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
        $upd->execute(['o' => $i + 1, 'id' => (int) $id]);
    }
}

/** Tells every active admin that a salesperson changed or removed a contact person (no approval needed, but they should know). */
function notifyContactChange(PDO $pdo, int $branchId, string $message): void
{
    notifyRoles($pdo, ['admin'], 'contact_changed', $branchId, 'branches', $branchId, truncateForNotification($message));
}

const MAX_EXTRA_NUMBERS = 20;

/**
 * Reads the JSON list of numbers beyond the first two. Returns ['list' => [...], 'error' => ?string];
 * each entry is validated like any other number. An empty/blank string is simply "none".
 */
function parseExtraNumbers(?string $json): array
{
    $json = trim((string) $json);
    if ($json === '' || $json === '[]') {
        return ['list' => [], 'error' => null];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return ['list' => [], 'error' => 'The extra phone numbers could not be read. Please re-enter them.'];
    }
    $list = [];
    foreach ($decoded as $item) {
        $number = trim((string) ($item['number'] ?? ''));
        $type = (string) ($item['type'] ?? 'mobile');
        if (!in_array($type, ['mobile', 'telephone'], true)) {
            $type = 'mobile';
        }
        if ($number === '') {
            continue;
        }
        $err = validateContactNumber($number, $type);
        if ($err !== null) {
            return ['list' => [], 'error' => $err];
        }
        $list[] = ['type' => $type, 'number' => $number];
    }
    if (count($list) > MAX_EXTRA_NUMBERS) {
        return ['list' => [], 'error' => 'That is too many phone numbers for one person (limit ' . (MAX_EXTRA_NUMBERS + 2) . ').'];
    }
    return ['list' => $list, 'error' => null];
}

const MAX_EXTRA_EMAILS = 20;

/**
 * Reads the JSON list of emails beyond the first one (a JSON array of strings).
 * Returns ['list' => [...], 'error' => ?string]. Blank entries are dropped, repeats
 * (case-insensitive) are collapsed, and each address must be a valid email.
 */
function parseExtraEmails(?string $json): array
{
    $json = trim((string) $json);
    if ($json === '' || $json === '[]') {
        return ['list' => [], 'error' => null];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return ['list' => [], 'error' => 'The extra emails could not be read. Please re-enter them.'];
    }
    $list = [];
    $seen = [];
    foreach ($decoded as $item) {
        $email = trim((string) $item);
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['list' => [], 'error' => '"' . $email . '" is not a valid email address.'];
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $list[] = $email;
    }
    if (count($list) > MAX_EXTRA_EMAILS) {
        return ['list' => [], 'error' => 'That is too many emails for one person (limit ' . (MAX_EXTRA_EMAILS + 1) . ').'];
    }
    return ['list' => $list, 'error' => null];
}

/** Extra emails as stored (JSON text) -> "a@x.com · b@y.com", empty string when none. */
function describeExtraEmails(?string $json): string
{
    return implode(' · ', parseExtraEmails($json)['list']);
}

/** Every email of a contact, primary first, as a flat list for display/export. */
function allContactEmails(array $contact): array
{
    $out = [];
    if (!empty($contact['email'])) {
        $out[] = $contact['email'];
    }
    foreach (parseExtraEmails($contact['contact_emails_extra'] ?? null)['list'] as $e) {
        $out[] = $e;
    }
    return $out;
}

/** Extra numbers as stored (JSON text) -> "0917… (Mobile) · 02-… (Telephone)", empty string when none. */
function describeExtraNumbers(?string $json): string
{
    $parts = [];
    foreach (parseExtraNumbers($json)['list'] as $n) {
        $parts[] = $n['number'] . ' (' . ($n['type'] === 'telephone' ? 'Telephone' : 'Mobile') . ')';
    }
    return implode(' · ', $parts);
}

/** "09171234520 (Mobile) · 02-8123-4567 (Telephone)" for display. */
function formatContactNumbers(array $contact): string
{
    $parts = [];
    if (!empty($contact['contact_number'])) {
        $parts[] = $contact['contact_number'] . ' (' . (($contact['contact_number_type'] ?? 'mobile') === 'telephone' ? 'Telephone' : 'Mobile') . ')';
    }
    if (!empty($contact['contact_number_2'])) {
        $parts[] = $contact['contact_number_2'] . ' (' . (($contact['contact_number_2_type'] ?? 'mobile') === 'telephone' ? 'Telephone' : 'Mobile') . ')';
    }
    $extra = describeExtraNumbers($contact['contact_numbers_extra'] ?? null);
    if ($extra !== '') {
        $parts[] = $extra;
    }
    return implode(' · ', $parts);
}

/**
 * ---- Search throttling -----------------------------------------------------
 * "Find a client" is limited to FIND_CLIENT_SEARCHES_PER_HOUR per person, and the
 * duplicate-check box on the add-company forms (which fires as you type) to a much
 * looser cap, so neither can be used to walk the whole client list.
 */
function ensureSearchLogTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS search_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
            searched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_search_log_user ON search_log (user_id, kind, searched_at)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS search_log (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, kind VARCHAR(20) NOT NULL,
        searched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_search_log_user (user_id, kind, searched_at))');
}

/** Records one search and returns false (without recording) if the person is over the limit. */
function allowSearch(PDO $pdo, int $userId, string $kind, int $maxPerHour): bool
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - 3600);
    $count = $pdo->prepare('SELECT COUNT(*) FROM search_log WHERE user_id = :u AND kind = :k AND searched_at >= :c');
    $count->execute(['u' => $userId, 'k' => $kind, 'c' => $cutoff]);
    if ((int) $count->fetchColumn() >= $maxPerHour) {
        return false;
    }
    $pdo->prepare('INSERT INTO search_log (user_id, kind, searched_at) VALUES (:u, :k, :t)')
        ->execute(['u' => $userId, 'k' => $kind, 't' => gmdate('Y-m-d H:i:s')]);
    $pdo->prepare('DELETE FROM search_log WHERE searched_at < :old')->execute(['old' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    return true;
}

/**
 * Adds 'special_user' to the users.role list on databases created before it
 * existed. MySQL: widen the ENUM. SQLite can't alter a CHECK constraint, so the
 * table is rebuilt: copy under a temp name, drop, rename (the standard "12-step"
 * procedure). Foreign keys are switched off for the swap because other tables
 * reference users; legacy_alter_table keeps the branch-ownership triggers, which
 * mention users by name, from tripping the rename. A no-op once done.
 */
function healUsersRole(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $type = $pdo->query(
            "SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
        )->fetchColumn();
        if ($type !== false && strpos((string) $type, 'special_user') === false) {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'super_admin', 'special_user') NOT NULL DEFAULT 'user'");
        }
        return;
    }

    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($sql === false || strpos($sql, 'special_user') !== false) {
        return;
    }
    $newSql = str_replace("'super_admin')", "'super_admin', 'special_user')", $sql, $count);
    if ($count !== 1) {
        error_log('healUsersRole: could not find the role CHECK in the users table definition; skipped.');
        return;
    }
    $newSql = preg_replace('/^CREATE TABLE\s+["`]?users["`]?/i', 'CREATE TABLE users_new', $newSql, 1);

    $indexes = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'users' AND sql IS NOT NULL")
        ->fetchAll(PDO::FETCH_COLUMN);

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('PRAGMA legacy_alter_table = ON');
    try {
        $pdo->beginTransaction();
        $pdo->exec($newSql);
        $pdo->exec('INSERT INTO users_new SELECT * FROM users');
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
        foreach ($indexes as $indexSql) {
            $pdo->exec($indexSql);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('healUsersRole failed: ' . $e->getMessage());
    } finally {
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/**
 * Lazy cleanup that has to happen no matter which page someone lands on (there
 * is no cron in this environment). Each task checks with a cheap read first and
 * only takes a write lock when there is actually something to clean, so the
 * common case costs one indexed SELECT. A failure here must never take a page
 * down, so it's logged and swallowed.
 */
function runHousekeeping(PDO $pdo): void
{
    try {
        migrateStaleReassignRequests($pdo);
        pruneAuditLogs($pdo);
        pruneExpiredExports($pdo);
        pruneResolvedNotifications($pdo);
    } catch (Throwable $e) {
        error_log('Housekeeping failed: ' . $e->getMessage());
    }
}

/**
 * One-time cleanup: an earlier version staged an admin's client reassignment
 * as an approval_requests row (type = company_assignment, payload.action =
 * reassign), shown in its own queue on Manage Companies. That was replaced by
 * filing an ordinary assigned_user_id change request instead, so it shows on
 * Approvals like every other change request. Anything left over from before
 * that switch is migrated here and closed out, so it stops appearing on
 * Manage Companies. Runs every request; a no-op once none are left.
 */
function migrateStaleReassignRequests(PDO $pdo): void
{
    $rows = $pdo->query("SELECT id, requested_by, payload FROM approval_requests WHERE type = 'company_assignment' AND status = 'pending'")->fetchAll();
    foreach ($rows as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload) || ($payload['action'] ?? '') !== 'reassign') {
            continue; // an 'add' request — not this cleanup's concern
        }
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $assigneeId = (int) ($payload['assignee_user_id'] ?? 0);
        if ($branchId === 0 || $assigneeId === 0) {
            continue; // malformed payload — nothing sensible to migrate
        }

        $ownerStmt = $pdo->prepare('SELECT assigned_user_id FROM branches WHERE id = :id');
        $ownerStmt->execute(['id' => $branchId]);
        $ownerRaw = $ownerStmt->fetchColumn();
        $oldOwnerId = ($ownerRaw === false || $ownerRaw === null) ? null : (int) $ownerRaw;

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO modification_requests (branch_id, contact_id, requested_by, field_name, old_value, new_value, status)
                 VALUES (:bid, NULL, :uid, 'assigned_user_id', :old, :new, 'pending')"
            )->execute(['bid' => $branchId, 'uid' => (int) $row['requested_by'], 'old' => $oldOwnerId, 'new' => $assigneeId]);
            $pdo->prepare("UPDATE approval_requests SET status = 'rejected', resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $row['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('migrateStaleReassignRequests failed for approval_requests #' . $row['id'] . ': ' . $e->getMessage());
        }
    }
}

/**
 * Rolling audit-log window. Keeps today plus the previous
 * (AUDIT_LOG_RETENTION_DAYS - 1) local days; anything older is deleted, so each
 * local midnight the oldest day falls off.
 *
 * dashboard.php derives a branch's "last modified" from its audit rows, so
 * before deleting, each branch's newest about-to-be-deleted audit timestamp is
 * rolled up into branches.last_modified. Every surviving audit row is newer
 * than every deleted one, so COALESCE(MAX(audit), last_modified, created_at)
 * stays correct forever without touching any of the code that writes audit rows.
 */
function pruneAuditLogs(PDO $pdo): void
{
    $cutoff = new DateTime('today', new DateTimeZone('+08:00'));
    $cutoff->modify('-' . (AUDIT_LOG_RETENTION_DAYS - 1) . ' days');
    $cutoff->setTimezone(new DateTimeZone('UTC'));
    $cutoffUtc = $cutoff->format('Y-m-d H:i:s');

    $probe = $pdo->prepare('SELECT 1 FROM audit_logs WHERE created_at < :cutoff LIMIT 1');
    $probe->execute(['cutoff' => $cutoffUtc]);
    if (!$probe->fetchColumn()) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $expiring = $pdo->prepare(
            "SELECT target_id, MAX(created_at) AS newest FROM audit_logs
             WHERE target_table = 'branches' AND target_id IS NOT NULL AND created_at < :cutoff
             GROUP BY target_id"
        );
        $expiring->execute(['cutoff' => $cutoffUtc]);
        $roll = $pdo->prepare(
            'UPDATE branches SET last_modified = :newest
             WHERE id = :id AND (last_modified IS NULL OR last_modified < :newest_again)'
        );
        foreach ($expiring->fetchAll() as $row) {
            $roll->execute(['newest' => $row['newest'], 'newest_again' => $row['newest'], 'id' => $row['target_id']]);
        }

        $pdo->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff')->execute(['cutoff' => $cutoffUtc]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// The 3/6-month alerts have no request behind them, and generateOrderNotifications()
// uses the *existence* of the row as its "already sent for this order date" marker.
// Hard-deleting one would make it reappear as a brand-new unread alert on the next
// page load, so those are hidden with dismissed_at instead.
const THRESHOLD_NOTIFICATION_TYPES_SQL = "'three_month_warning', 'six_month_reassign', 'engagement_reminder'";

/**
 * Manual delete, always scoped to the signed-in person's own notifications.
 * Pass specific ids, or $allRead = true for "delete all read". Request-related
 * notifications are removed outright; 3/6-month alerts are hidden (see above)
 * and are cleaned up for real by pruneResolvedNotifications() once they're stale.
 */
function deleteNotificationsForUser(PDO $pdo, int $userId, array $ids = [], bool $allRead = false): void
{
    $where = 'sent_to = :uid';
    $params = ['uid' => $userId];
    if ($allRead) {
        $where .= ' AND is_read = 1';
    } else {
        if (empty($ids)) {
            return;
        }
        $marks = [];
        foreach (array_values($ids) as $i => $id) {
            $marks[] = ':id' . $i;
            $params['id' . $i] = (int) $id;
        }
        $where .= ' AND id IN (' . implode(', ', $marks) . ')';
    }
    $threshold = THRESHOLD_NOTIFICATION_TYPES_SQL;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE notifications SET dismissed_at = CURRENT_TIMESTAMP, is_read = 1
                       WHERE $where AND type IN ($threshold) AND dismissed_at IS NULL")->execute($params);
        $pdo->prepare("DELETE FROM notifications WHERE $where AND type NOT IN ($threshold)")->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Automatic cleanup: a notification is deleted once it has been READ and the
 * thing it was about is COMPLETED. Each rule below is that pair of conditions.
 * Anything unread, or read-but-still-open, is left alone. Notifications with no
 * way to tell (older rows saved without a target) are only removed manually.
 *
 *  - "...reviewed" notices are already the outcome of a finished request, so
 *    reading them completes them. The plain informational ones (a client was
 *    transferred to/from you, a new client was assigned to you) work the same way.
 *  - "...pending" notices complete when their request is no longer pending
 *    (approved or rejected). Account requests notify with the *target user's* id
 *    rather than the request's, so they're matched through account_requests.
 *  - 3/6-month alerts complete when the client's order date has since been
 *    updated, or the branch is gone/inactive. They also clear hidden (manually
 *    deleted) markers once the order date has moved on, when the marker is no
 *    longer needed to stop generateOrderNotifications() re-sending them.
 *
 * Each rule probes with a SELECT first so the usual page load takes no write lock.
 */
function pruneResolvedNotifications(PDO $pdo): void
{
    $threshold = THRESHOLD_NOTIFICATION_TYPES_SQL;
    $rules = [
        "is_read = 1 AND type IN ('account_request_reviewed', 'company_assignment_reviewed', 'export_reviewed',
                             'modification_reviewed', 'branch_transferred_out', 'branch_transferred_in', 'branch_assigned')",

        "is_read = 1 AND type IN ('company_assignment_pending', 'export_pending')
         AND target_table = 'approval_requests' AND target_id IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM approval_requests r
                         WHERE r.id = notifications.target_id AND r.status = 'pending')",

        "is_read = 1 AND type = 'account_request_pending'
         AND target_table = 'users' AND target_id IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM account_requests r
                         WHERE r.target_user_id = notifications.target_id AND r.status = 'pending')",

        "is_read = 1 AND type = 'modification_pending'
         AND target_table = 'modification_requests' AND target_id IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM modification_requests m
                         WHERE m.id = notifications.target_id AND m.status = 'pending')",

        "is_read = 1 AND type = 'modification_superadmin_pending'
         AND target_table = 'modification_requests' AND target_id IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM modification_requests m
                         WHERE m.id = notifications.target_id AND m.status IN ('pending', 'admin_approved'))",

        "is_read = 1 AND type = 'branch_needs_reassignment'
         AND target_table = 'users' AND target_id IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM branches b
                         JOIN users u ON u.id = b.assigned_user_id
                         WHERE b.assigned_user_id = notifications.target_id
                           AND b.status = 'active' AND u.status = 'inactive')",

        "type IN ($threshold) AND (is_read = 1 OR dismissed_at IS NOT NULL)
         AND (NOT EXISTS (SELECT 1 FROM branches b
                          WHERE b.id = notifications.branch_id AND b.status = 'active')
              OR EXISTS (SELECT 1 FROM order_dates o
                         WHERE o.branch_id = notifications.branch_id
                           AND o.updated_at > notifications.created_at)
              OR EXISTS (SELECT 1 FROM engagements e
                         WHERE e.branch_id = notifications.branch_id
                           AND e.created_at > notifications.created_at))",
    ];

    foreach ($rules as $where) {
        $probe = $pdo->query("SELECT 1 FROM notifications WHERE $where LIMIT 1");
        if ($probe->fetchColumn()) {
            $pdo->exec("DELETE FROM notifications WHERE $where");
        }
    }
}

/**
 * An approved export nobody downloaded within EXPORT_CLEAR_AFTER_DAYS has its
 * stored CSV cleared (the request row stays for the audit trail). Lives here,
 * not in export.php, so the customer data can't sit in the database just
 * because nobody happened to open the export page.
 */
function pruneExpiredExports(PDO $pdo): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - EXPORT_CLEAR_AFTER_DAYS * 86400);
    $where = "type = 'export' AND status = 'approved' AND result IS NOT NULL
              AND downloaded_at IS NULL AND resolved_at <= :cutoff";

    $probe = $pdo->prepare("SELECT 1 FROM approval_requests WHERE $where LIMIT 1");
    $probe->execute(['cutoff' => $cutoff]);
    if ($probe->fetchColumn()) {
        $pdo->prepare("UPDATE approval_requests SET result = NULL WHERE $where")->execute(['cutoff' => $cutoff]);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    // HTTPS is detected directly, or from X-Forwarded-Proto when the request came through a
    // trusted proxy that terminates TLS. SESSION_COOKIE_SECURE=1/0 forces it either way.
    $httpsNow = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (TRUSTED_PROXIES !== '' && ipInList((string) ($_SERVER['REMOTE_ADDR'] ?? ''), TRUSTED_PROXIES)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $secureEnv = getenv('SESSION_COOKIE_SECURE');
    $cookieSecure = $secureEnv === false || $secureEnv === '' ? $httpsNow : $secureEnv !== '0';

    ini_set('session.use_strict_mode', '1');   // never accept a session id the server didn't issue
    ini_set('session.use_only_cookies', '1');  // no ?PHPSESSID= in URLs
    ini_set('session.gc_maxlifetime', (string) (SESSION_IDLE_MINUTES * 60));
    session_set_cookie_params([
        'lifetime' => 0,            // gone when the browser closes
        'path'     => '/',
        'secure'   => $cookieSecure,
        'httponly' => true,         // not readable from page scripts
        'samesite' => 'Lax',        // not sent on cross-site POSTs (a second layer on top of the CSRF tokens)
    ]);
    session_start();

    // Idle timeout. Clearing the session is enough: every page and JSON endpoint already
    // treats "no user_id" as logged out. The notice shows once on the login page.
    if (!empty($_SESSION['user_id'])) {
        $idleLimit = SESSION_IDLE_MINUTES * 60;
        if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $idleLimit) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['login_toast'] = 'You were logged out after being inactive. Please log in again.';
        } else {
            $_SESSION['last_activity'] = time();
        }
    }
}

/**
 * A forced password reset takes effect immediately, even for a session that
 * was already open when it happened — so every page behind a login check
 * re-checks this, not just dashboard.php and the login form (which only
 * catch it at the moment of landing there or logging in). Call this right
 * after the "must be logged in" check on any page. change_password.php
 * itself must never call this, or nobody could reach it to clear the flag.
 */
function requireActivePassword(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT must_change_password FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    if ((int) $stmt->fetchColumn() === 1) {
        header('Location: change_password.php');
        exit;
    }
    restrictSpecialUser();
}

/**
 * The special user (the board member) can do exactly one job: request and download
 * client exports. Every page that calls requireActivePassword() therefore bounces
 * them to export.php, apart from the few they legitimately need. change_password.php
 * never reaches this (it is the page requireActivePassword sends people TO).
 */
function restrictSpecialUser(): void
{
    if (($_SESSION['role'] ?? '') !== 'special_user') {
        return;
    }
    $allowed = ['export.php', 'notifications.php', 'change_password.php', 'logout.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $allowed, true)) {
        header('Location: export.php');
        exit;
    }
}

function generateOrderNotifications(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT b.id AS branch_id, b.assigned_user_id, o.last_order_date, o.updated_at AS order_updated_at,
                (SELECT MAX(e.engaged_on) FROM engagements e WHERE e.branch_id = b.id) AS last_engagement_date,
                (SELECT MAX(e.created_at) FROM engagements e WHERE e.branch_id = b.id) AS engagement_logged_at
         FROM branches b
         JOIN order_dates o ON o.branch_id = b.id
         WHERE b.status = 'active' AND b.assigned_user_id IS NOT NULL"
    )->fetchAll();

    if (empty($rows)) {
        return;
    }

    $adminIds = $pdo->query(
        "SELECT id FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $existsStmt = $pdo->prepare(
        'SELECT id FROM notifications
         WHERE branch_id = :bid AND type = :type AND sent_to = :uid AND created_at >= :since
         LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO notifications (branch_id, type, sent_to, is_read) VALUES (:bid, :type, :uid, 0)'
    );

    foreach ($rows as $r) {
        // Activity = the later of the last order and the last logged engagement.
        $activity = lastActivityDate($r['last_order_date'], $r['last_engagement_date']);
        $days = (strtotime('now') - strtotime($activity)) / 86400;
        $months = $days / 30;

        if ($months >= 6) {
            $type = 'six_month_reassign';
        } elseif ($months >= 3) {
            $type = 'three_month_warning';
        } elseif ($days >= ENGAGEMENT_QUIET_DAYS) {
            $type = 'engagement_reminder';
        } else {
            continue;
        }

        $recipients = array_unique(array_merge([$r['assigned_user_id']], $adminIds));
        // An alert counts as "already sent" only if it was created after the latest
        // order update or engagement, so new activity lets the next lapse alert again.
        $since = max($r['order_updated_at'] ?? '1970-01-01 00:00:00', $r['engagement_logged_at'] ?? '1970-01-01 00:00:00');

        foreach ($recipients as $recipientId) {
            if (!$recipientId) {
                continue;
            }
            $existsStmt->execute(['bid' => $r['branch_id'], 'type' => $type, 'uid' => $recipientId, 'since' => $since]);
            if ($existsStmt->fetch()) {
                continue;
            }
            $insertStmt->execute(['bid' => $r['branch_id'], 'type' => $type, 'uid' => $recipientId]);
        }
    }
}

/**
 * Same hard rule register.php enforces client-side (8-20 chars, letters/digits
 * only, at least one uppercase) — re-checked here server-side for any page that
 * sets a password outside registration. Returns an error message, or null if
 * the password passes.
 */
/**
 * Every timestamp is stored as UTC (SQLite/MySQL CURRENT_TIMESTAMP). Pages
 * that display one to a person need to convert it — this is that one place,
 * so the offset only has to be right in a single spot. +08:00 matches the
 * company's timezone (Philippines, no DST, so a fixed offset is fine).
 */
function toLocalTime(?string $utcTimestamp, string $format = 'Y-m-d g:i:s A'): string
{
    if ($utcTimestamp === null || $utcTimestamp === '') {
        return '';
    }
    $date = new DateTime($utcTimestamp, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('+08:00'));
    return $date->format($format);
}

function validatePasswordRules(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 20) {
        return 'Password must be at most 20 characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9]+$/', $password)) {
        return 'Password can only contain letters and numbers.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    return null;
}

function isFirstEverUser(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

/**
 * Role redesign: exactly one super_admin and one admin account, assigned by
 * registration order rather than promoted later (promotion/demotion is gone).
 * 1st registrant -> super_admin, 2nd -> admin, 3rd -> special_user (the board member
 * who can only request exports), everyone after -> user.
 * Registration order is a known soft spot (whoever signs up 1st/2nd on a fresh
 * database gets those roles) — acceptable for now since the account is being
 * handed to specific people right away, but worth locking down harder later
 * (e.g. restricting registration per WBS task #4) if that stops being true.
 */
function nextAutoRole(PDO $pdo): string
{
    // Decided by which seats are actually taken, not by how many accounts
    // exist: counting all users would hand a seat to the wrong person if a row
    // were ever deleted. A deactivated admin/super admin still holds their seat.
    $taken = $pdo->query("SELECT role FROM users WHERE role IN ('super_admin', 'admin', 'special_user') GROUP BY role")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['super_admin', 'admin', 'special_user'] as $seat) {
        if (!in_array($seat, $taken, true)) {
            return $seat;
        }
    }
    return 'user';
}

/**
 * Active plain 'user' accounts, for the assignee picker on the admin/super
 * admin company-add form — admin/super admin don't own clients themselves,
 * so a new branch always needs to be handed to one of these.
 */
/**
 * ---- Company names: suffixes, normalising and near-duplicate detection -------------------------------------------
 * A company is stored as "<name> <suffix>" with exactly one space (or just "<name>" for N/A). Abbreviations are
 * expanded to the full word so everyone types the same thing: Corp / Corp. -> Corporation, Inc -> Incorporated, ...
 * Two names are the SAME company when their normalised name and suffix match ("Gardenia Corp." = "Gardenia
 * Corporation"). They are only SIMILAR (warn, let the user confirm) when the name is a typo or sounds-alike
 * ("Gardania Corporation"), or only the suffix differs. A shared start ("San Miguel Brewery" / "San Miguel Foods")
 * is a family, not a duplicate, and is never flagged.
 */
const COMPANY_SUFFIXES = ['Corporation', 'Incorporated', 'Company', 'Limited', 'Limited Liability Company', 'Enterprises', 'Trading', 'Industries', 'Holdings', 'Cooperative'];
const COMPANY_SUFFIX_NONE = 'N/A';
/** What typed abbreviations expand to (lower case, no punctuation). */
const COMPANY_SUFFIX_ALIASES = [
    'corp' => 'corporation', 'corporation' => 'corporation', 'inc' => 'incorporated', 'incorporated' => 'incorporated',
    'co' => 'company', 'company' => 'company', 'ltd' => 'limited', 'limited' => 'limited', 'llc' => 'limited liability company',
    'enterprise' => 'enterprises', 'enterprises' => 'enterprises', 'ent' => 'enterprises', 'trading' => 'trading',
    'industries' => 'industries', 'industry' => 'industries', 'holdings' => 'holdings', 'cooperative' => 'cooperative', 'coop' => 'cooperative',
];

/** Lower-cases, turns & into "and", drops punctuation and collapses spaces: "Gardenia  Corp." -> ["gardenia","corp"]. */
function companyNameTokens(string $name): array
{
    $n = mb_strtolower(str_replace('&', ' and ', $name), 'UTF-8');
    $n = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n);
    return preg_split('/\s+/u', trim((string) $n), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Splits a typed company name into its name and its (full-word) suffix:
 * "Gardenia Corp." -> ['name' => 'Gardenia', 'suffix' => 'Corporation'].  The name keeps the typed capitals.
 * 'suffix' is '' when the name has none. Never strips the last remaining word ("Trading" alone stays a name).
 */
function companyNameParts(string $typed): array
{
    $words = preg_split('/\s+/u', trim(str_replace(['&'], [' & '], $typed)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $suffixWords = [];
    while (count($words) > 1 && count($suffixWords) < 3) {
        $last = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) end($words)), 'UTF-8');
        if ($last === '' || !isset(COMPANY_SUFFIX_ALIASES[$last])) {
            break;
        }
        array_unshift($suffixWords, COMPANY_SUFFIX_ALIASES[$last]);
        array_pop($words);
    }
    // Trailing punctuation left on the name ("Gardenia,") is dropped.
    $name = rtrim(implode(' ', $words), " ,.;-");
    $suffix = implode(' ', array_values(array_unique($suffixWords)));
    return ['name' => $name, 'suffix' => $suffix === '' ? '' : ucwords($suffix)];
}

/** The one stored form of a name + suffix: single spaces, suffix in full, nothing for N/A or an empty suffix. */
function composeCompanyName(string $name, string $suffix): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $suffix = trim($suffix);
    return ($suffix === '' || strcasecmp($suffix, COMPANY_SUFFIX_NONE) === 0) ? $name : $name . ' ' . $suffix;
}

/** Comparison keys for a full stored/typed name: 'base' (name without suffix, letters and digits only) and 'suffix'. */
function companyNameKeys(string $fullName): array
{
    $p = companyNameParts($fullName);
    $base = implode('', companyNameTokens($p['name']));
    return ['base' => $base, 'suffix' => mb_strtolower($p['suffix'], 'UTF-8'), 'full' => $base . '|' . mb_strtolower($p['suffix'], 'UTF-8')];
}

/** Active companies as id, name, owner names — what the duplicate checks compare against. */
function activeCompanyNames(PDO $pdo): array
{
    return $pdo->query("SELECT id, name FROM companies WHERE status = 'active'")->fetchAll();
}

/** Owner names of a company's active branches (for the "already handled by" wording). */
function companyOwnerNames(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.full_name FROM branches b JOIN users u ON u.id = b.assigned_user_id
         WHERE b.company_id = :c AND b.status = 'active' ORDER BY u.full_name"
    );
    $stmt->execute(['c' => $companyId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Looks for the same company ('exact': same name and suffix once normalised) and for similar ones ('similar').
 * $excludeId skips the company being renamed. Similar = same name with another suffix, a typo of at most 1 letter
 * (names up to 8 letters) or 2 letters (longer), or the same sound (metaphone) for names of 5+ letters.
 * Returns ['exact' => ['id','name']|null, 'similar' => [['id','name','why'], ...] (at most 5)].
 */
function findCompanyNameMatches(PDO $pdo, string $fullName, ?int $excludeId = null): array
{
    $mine = companyNameKeys($fullName);
    $out = ['exact' => null, 'similar' => []];
    if ($mine['base'] === '') {
        return $out;
    }
    $myMeta = strlen($mine['base']) >= 5 ? metaphone($mine['base']) : '';
    foreach (activeCompanyNames($pdo) as $c) {
        if ($excludeId !== null && (int) $c['id'] === $excludeId) {
            continue;
        }
        $theirs = companyNameKeys((string) $c['name']);
        if ($theirs['base'] === '') {
            continue;
        }
        if ($theirs['full'] === $mine['full']) {
            $out['exact'] = ['id' => (int) $c['id'], 'name' => (string) $c['name']];
            continue;
        }
        $why = null;
        if ($theirs['base'] === $mine['base']) {
            $why = 'same name, different ending';
        } else {
            $len = max(strlen($mine['base']), strlen($theirs['base']));
            $limit = $len <= 4 ? 0 : ($len <= 8 ? 1 : 2);
            if ($limit > 0 && abs(strlen($mine['base']) - strlen($theirs['base'])) <= $limit
                && levenshtein(substr($mine['base'], 0, 250), substr($theirs['base'], 0, 250)) <= $limit) {
                $why = 'spelled almost the same';
            } elseif ($myMeta !== '' && strlen($theirs['base']) >= 5 && metaphone($theirs['base']) === $myMeta) {
                $why = 'sounds the same';
            }
        }
        if ($why !== null) {
            $out['similar'][] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'why' => $why];
        }
    }
    $out['similar'] = array_slice($out['similar'], 0, 5);
    return $out;
}

function activeEmployees(PDO $pdo): array
{
    return $pdo->query("SELECT id, full_name FROM users WHERE role = 'user' AND status = 'active' ORDER BY full_name")->fetchAll();
}

function isSyspointEmail(string $email): bool
{
    $domain = '@' . ALLOWED_EMAIL_DOMAIN;
    return strlen($email) > strlen($domain)
        && strtolower(substr($email, -strlen($domain))) === strtolower($domain);
}

function requestRegistrationOtp(PDO $pdo, string $email): array
{
    if (!isSyspointEmail($email)) {
        return ['ok' => false, 'error' => 'Only @' . ALLOWED_EMAIL_DOMAIN . ' addresses can register.'];
    }

    if (!allowOtpSend($pdo, $email)) {
        return ['ok' => false, 'error' => 'Too many code requests. Please try again in an hour.'];
    }

    $last = $pdo->prepare(
        'SELECT created_at FROM registration_otp WHERE email = :email ORDER BY created_at DESC LIMIT 1'
    );
    $last->execute(['email' => $email]);
    $lastRow = $last->fetch();
    if ($lastRow) {
        $secondsSince = time() - strtotime($lastRow['created_at']);
        if ($secondsSince < OTP_RESEND_COOLDOWN_SECONDS) {
            $wait = OTP_RESEND_COOLDOWN_SECONDS - $secondsSince;
            return ['ok' => false, 'error' => "Please wait {$wait}s before requesting another code."];
        }
    }

    $code = str_pad((string) random_int(0, (10 ** OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60);

    $pdo->prepare(
        'INSERT INTO registration_otp (email, otp_code, expires_at) VALUES (:email, :code, :exp)'
    )->execute(['email' => $email, 'code' => $code, 'exp' => $expiresAt]);

    $sent = sendRegistrationOtpEmail($email, $code);
    if (!$sent['ok']) {
        return ['ok' => false, 'error' => 'Could not send email: ' . $sent['error']];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * ---- Email design ----------------------------------------------------------
 * One layout for every email the app sends. Table-based with inline styles because mail apps
 * (Outlook especially) ignore most modern CSS. Colours follow the app: black header, red accent.
 */
function emailEsc(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

/** The signature block lines: company name, optional extra lines, as an array. */
function emailSignatureLines(): array
{
    $lines = [EMAIL_COMPANY_NAME];
    foreach (explode('|', EMAIL_SIGNATURE_LINES) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $lines[] = $l;
        }
    }
    return array_values(array_filter($lines, static fn($l) => $l !== ''));
}

/** Plain-text twin of the signature, appended to every AltBody. */
function emailPlainFooter(): string
{
    $out = "\n\n--\n" . implode("\n", emailSignatureLines());
    if (EMAIL_CONFIDENTIALITY_NOTICE !== '') {
        $out .= "\n\n" . EMAIL_CONFIDENTIALITY_NOTICE;
    }
    return $out . "\n";
}

/** Big verification-code box used by the two OTP emails. */
function emailCodeBox(string $code): string
{
    return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:26px auto;"><tr>'
        . '<td style="padding:16px 30px;background:#f7f7f7;border:1px solid #e4e4e4;border-left:4px solid #d71920;border-radius:6px;'
        . 'font-family:\'Courier New\',Courier,monospace;font-size:32px;font-weight:bold;letter-spacing:8px;color:#080808;text-align:center;">'
        . emailEsc($code) . '</td></tr></table>';
}

/**
 * Wraps $bodyHtml (already-escaped HTML) in the standard email. $button is [label, url] or null.
 * $preheader is the grey preview line some inboxes show next to the subject.
 */
function emailLayout(string $heading, string $bodyHtml, ?array $button = null, string $preheader = ''): string
{
    $btn = '';
    if ($button !== null && $button[1] !== '') {
        $btn = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 4px;"><tr>'
            . '<td style="background:#d71920;border-radius:6px;">'
            . '<a href="' . emailEsc($button[1]) . '" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">' . emailEsc($button[0]) . '</a></td></tr></table>';
    }

    $sig = '';
    foreach (emailSignatureLines() as $i => $line) {
        $sig .= $i === 0
            ? '<div style="font-size:14px;font-weight:bold;color:#171717;">' . emailEsc($line) . '</div>'
            : '<div style="font-size:12px;line-height:1.6;color:#707070;">' . emailEsc($line) . '</div>';
    }
    $notice = EMAIL_CONFIDENTIALITY_NOTICE !== ''
        ? '<div style="margin-top:14px;padding-top:14px;border-top:1px solid #e4e4e4;font-size:11px;line-height:1.6;color:#8a8a8a;">'
            . '<strong style="color:#707070;">Confidentiality notice.</strong> ' . emailEsc(EMAIL_CONFIDENTIALITY_NOTICE) . '</div>'
        : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . emailEsc($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f4f4;">' . emailEsc($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e4e4e4;">'
        // header
        . '<tr><td style="background:#080808;padding:22px 30px;border-bottom:4px solid #d71920;">'
        . '<span style="font-size:22px;font-weight:bold;letter-spacing:.5px;color:#ffffff;">Syspoint</span>'
        . '<span style="font-size:22px;font-weight:normal;color:#6b6b6b;"> &nbsp;|&nbsp; </span>'
        . '<span style="font-size:22px;font-weight:bold;letter-spacing:.5px;color:#d71920;">CRM</span></td></tr>'
        // body
        . '<tr><td style="padding:32px 30px 28px;font-size:15px;line-height:1.65;color:#444444;">'
        . '<h2 style="margin:0 0 16px;font-size:20px;color:#171717;">' . emailEsc($heading) . '</h2>'
        . $bodyHtml . $btn . '</td></tr>'
        // signature + notice
        . '<tr><td style="padding:22px 30px 26px;background:#fafafa;border-top:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif;">'
        . $sig . $notice . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** A configured SMTP mailer, shared by every email the app sends. Throws PHPMailer exceptions on send. */
function createSmtpMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->SMTPAuth = SMTP_AUTH;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : (SMTP_SECURE === 'none' ? '' : PHPMailer::ENCRYPTION_STARTTLS);
    $mail->SMTPAutoTLS = SMTP_SECURE !== 'none';
    $mail->Timeout = 10;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->isHTML(true);
    return $mail;
}

/** Sends one OTP email (registration or password reset) in the standard design. */
function sendOtpEmail(string $toEmail, string $code, string $subject, string $heading, string $intro): array
{
    $mail = null;
    try {
        $mail = createSmtpMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $body = '<p style="margin:0 0 12px;">Hello,</p><p style="margin:0;">' . $intro . '</p>'
            . emailCodeBox($code)
            . '<p style="margin:0 0 12px;text-align:center;font-size:14px;">This code expires in <strong>' . OTP_TTL_MINUTES . ' minutes</strong>.</p>'
            . '<p style="margin:0;font-size:13px;color:#707070;">If you did not request this, you can safely ignore this email. Never share this code with anyone.</p>';
        $mail->Body = emailLayout($heading, $body, null, 'Your code is ' . $code);
        $mail->AltBody = "Hello,\n\n" . strip_tags($intro) . "\n\nYour code: {$code}\nIt expires in " . OTP_TTL_MINUTES
            . " minutes.\n\nIf you did not request this, you can safely ignore this email. Never share this code with anyone."
            . emailPlainFooter();
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'error' => $mail !== null && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
    }
}

function sendRegistrationOtpEmail(string $toEmail, string $code): array
{
    return sendOtpEmail(
        $toEmail,
        $code,
        'Your Syspoint CRM verification code',
        'Verify your account',
        'Thank you for registering with <strong>Syspoint CRM</strong>. Use the verification code below to complete your registration.'
    );
}

function verifyRegistrationOtp(PDO $pdo, string $email, string $code): bool
{
    // Only the newest unused, unexpired code for this email counts. Comparing
    // in PHP (not in the WHERE clause) is what lets wrong guesses be counted.
    $stmt = $pdo->prepare(
        'SELECT id, otp_code, attempts FROM registration_otp
         WHERE email = :email AND used_at IS NULL AND expires_at >= :now
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['email' => $email, 'now' => date('Y-m-d H:i:s')]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return false;
    }

    if (!hash_equals((string) $row['otp_code'], $code)) {
        $pdo->prepare('UPDATE registration_otp SET attempts = attempts + 1 WHERE id = :id')
            ->execute(['id' => $row['id']]);
        return false;
    }

    $pdo->prepare('UPDATE registration_otp SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $row['id']]);

    return true;
}

function requestPasswordResetOtp(PDO $pdo, string $email): array
{
    $generic = ['ok' => true, 'error' => null];

    // Counted before the account lookup, so an unknown email is throttled exactly like a known
    // one and the limit can't be used to tell them apart. Over the limit the person still gets
    // the same generic "if that email exists…" answer, and nothing is sent.
    if (!allowOtpSend($pdo, $email)) {
        return $generic;
    }

    $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND status = 'active' AND locked_at IS NULL");
    $userStmt->execute(['email' => $email]);
    $user = $userStmt->fetch();
    if (!$user) {
        // Unknown, inactive or locked accounts all get the same generic answer and no code.
        return $generic;
    }
    $userId = $user['id'];

    $last = $pdo->prepare('SELECT created_at FROM otp_tokens WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1');
    $last->execute(['uid' => $userId]);
    $lastRow = $last->fetch();
    if ($lastRow) {
        $secondsSince = time() - strtotime($lastRow['created_at']);
        if ($secondsSince < OTP_RESEND_COOLDOWN_SECONDS) {
            $wait = OTP_RESEND_COOLDOWN_SECONDS - $secondsSince;
            return ['ok' => false, 'error' => "Please wait {$wait}s before requesting another code."];
        }
    }

    $code = str_pad((string) random_int(0, (10 ** OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60);

    $pdo->prepare('INSERT INTO otp_tokens (user_id, otp_code, expires_at) VALUES (:uid, :code, :exp)')
        ->execute(['uid' => $userId, 'code' => $code, 'exp' => $expiresAt]);

    // Deliberately ignores the result and still returns the generic ok:true below, so the
    // response never reveals whether the address has an account or whether mail went out.
    $sent = sendOtpEmail(
        $email,
        $code,
        'Your Syspoint CRM password reset code',
        'Reset your password',
        'We received a request to reset the password on your <strong>Syspoint CRM</strong> account. Enter the code below to choose a new one.'
    );
    if (!$sent['ok']) {
        error_log('Password reset email failed: ' . $sent['error']);
    }

    return $generic;
}

function verifyPasswordResetOtp(PDO $pdo, string $email, string $code): int|false
{
    $stmt = $pdo->prepare(
        "SELECT t.id AS token_id, t.user_id, t.otp_code, t.attempts
         FROM otp_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE u.email = :email AND u.locked_at IS NULL AND t.used_at IS NULL AND t.expires_at >= :now
         ORDER BY t.created_at DESC, t.id DESC LIMIT 1"
    );
    $stmt->execute(['email' => $email, 'now' => date('Y-m-d H:i:s')]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return false;
    }

    if (!hash_equals((string) $row['otp_code'], $code)) {
        $pdo->prepare('UPDATE otp_tokens SET attempts = attempts + 1 WHERE id = :id')
            ->execute(['id' => $row['token_id']]);
        return false;
    }

    $pdo->prepare('UPDATE otp_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $row['token_id']]);

    return (int) $row['user_id'];
}