<?php
/**
 * Email conversations (threads).
 *
 * Every notification email belongs to a thread (one per request / matter), so the salesperson,
 * admins and super admins involved see ONE conversation in their inbox instead of loose emails:
 *   - same fixed subject for the whole thread (later emails get "Re: "),
 *   - Message-ID / In-Reply-To / References headers so Gmail and Outlook stack them,
 *   - optional reply-by-email (INBOUND_REPLY_ENABLED=1): each email's Reply-To is a personal,
 *     signed address (mailbox+<thread>.u<user>.<token>@domain). A reply is read from the mailbox by
 *     inbound_mail.php and forwarded to the other people in the thread.
 */

use PHPMailer\PHPMailer\PHPMailer;

define('INBOUND_REPLY_ENABLED', getenv('INBOUND_REPLY_ENABLED') === '1');
// The mailbox replies arrive in (plus-addressing: name+tag@domain must reach the same inbox, as with Gmail / Google Workspace).
define('INBOUND_ADDRESS', (string) (getenv('INBOUND_ADDRESS') ?: SMTP_USER));

function ensureEmailThreadTables(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $auto = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB';
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_threads (
        thread_key VARCHAR(120) NOT NULL PRIMARY KEY,
        subject VARCHAR(255) NOT NULL,
        secret VARCHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_thread_users (
        thread_key VARCHAR(120) NOT NULL,
        user_id INT NOT NULL,
        PRIMARY KEY (thread_key, user_id))' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_messages (
        id ' . $auto . ',
        thread_key VARCHAR(120) NOT NULL,
        message_id VARCHAR(255) NOT NULL,
        user_id INT NULL,
        direction VARCHAR(3) NOT NULL,
        body TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (message_id))' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_inbound_status (
        id INT NOT NULL PRIMARY KEY,
        last_run_at VARCHAR(19) NOT NULL,
        last_error VARCHAR(255) NULL)' . $engine);
    if ($sqlite) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_thread ON email_messages (thread_key)');
    } else {
        try {
            $pdo->exec('CREATE INDEX idx_email_messages_thread ON email_messages (thread_key)');
        } catch (Throwable $e) {
            // already there
        }
    }
}

/** Which conversation a notification belongs to. Same request / matter => same key. */
function notificationThreadKey(array $n): string
{
    // Some notifications carry their own conversation (e.g. one per account lock).
    if (!empty($n['thread_key'])) {
        return (string) $n['thread_key'];
    }
    $table = (string) ($n['target_table'] ?? '');
    $tid = (int) ($n['target_id'] ?? 0);
    if ($table === 'modification_requests' && $tid > 0) {
        return 'req-' . $tid;
    }
    if ($table === 'approval_requests' && $tid > 0) {
        return 'apr-' . $tid;
    }
    if ($table !== '' && $tid > 0) {
        return 'm-' . preg_replace('/[^a-z0-9]+/', '', strtolower((string) $n['type'])) . '-' . $table . '-' . $tid;
    }
    if (in_array($n['type'] ?? '', ['three_month_warning', 'six_month_reassign', 'engagement_reminder'], true) && !empty($n['branch_id'])) {
        return 'flag-' . (int) $n['branch_id'];
    }
    return 'n-' . (int) $n['id'];
}

function emailThreadDomain(): string
{
    $at = strrpos(SMTP_FROM_EMAIL, '@');
    return $at === false ? 'syspoint.local' : substr(SMTP_FROM_EMAIL, $at + 1);
}

function emailThreadRootId(string $key): string
{
    return '<crm.' . $key . '@' . emailThreadDomain() . '>';
}

/**
 * The fixed subject of a conversation. It says what the matter IS (so it reads at a glance in an inbox) and
 * never changes, because Gmail stacks emails by subject. What is happening NOW (approved, rejected, ...) goes in
 * the banner at the top of each email, not here. No ticket numbers: they mean nothing to the reader.
 */
function emailThreadSubject(PDO $pdo, string $key, array $first): string
{
    $company = (string) ($first['company_name'] ?? '');
    $branch = (string) ($first['branch_name'] ?? '');
    $topic = '';
    if (strpos($key, 'req-') === 0) {
        $look = $pdo->prepare('SELECT m.field_name, c.name AS company_name, b.branch_name, p.full_name AS contact_name
            FROM modification_requests m
            JOIN branches b ON b.id = m.branch_id
            JOIN companies c ON c.id = b.company_id
            LEFT JOIN contact_persons p ON p.id = m.contact_id
            WHERE m.id = :id');
        $look->execute(['id' => (int) substr($key, 4)]);
        $r = $look->fetch();
        if ($r) {
            $company = (string) $r['company_name'];
            $branch = (string) $r['branch_name'];
            $field = (string) $r['field_name'];
            $who = trim((string) ($r['contact_name'] ?? ''));
            if ($field === 'contact_remove') {
                $topic = 'Remove contact' . ($who !== '' ? ' ' . $who : '');
            } elseif ($field === 'add_contact') {
                $topic = 'Add contact';
            } elseif ($field === 'contact_info' || strpos($field, 'contact_') === 0) {
                $topic = 'Update contact' . ($who !== '' ? ' ' . $who : '');
            } elseif ($field === 'assigned_user_id') {
                $topic = 'Transfer company';
            } elseif ($field === 'branch_name') {
                $topic = 'Rename branch';
            } elseif ($field === 'address') {
                $topic = 'Change address';
            } else {
                $topic = 'Change request';
            }
        }
    } elseif (strpos($key, 'apr-') === 0) {
        $look = $pdo->prepare('SELECT type FROM approval_requests WHERE id = :id');
        $look->execute(['id' => (int) substr($key, 4)]);
        $type = (string) $look->fetchColumn();
        $topic = $type === 'export' ? 'Export request' : ($type === 'company_assignment' ? 'New company / branch request' : 'Request');
    } elseif (strpos($key, 'lock-') === 0) {
        $look = $pdo->prepare("SELECT u.full_name FROM audit_logs a JOIN users u ON u.id = a.target_id WHERE a.id = :id AND a.action = 'account_locked'");
        $look->execute(['id' => (int) substr($key, 5)]);
        $who = (string) $look->fetchColumn();
        $topic = 'Account locked' . ($who !== '' ? ' — ' . $who : '');
    } elseif (strpos($key, 'flag-') === 0) {
        // One conversation per branch covers the 3-month, 6-month and 30-day flags, so the subject stays neutral.
        $topic = 'Follow-up needed';
    } else {
        $topic = NOTIFICATION_EMAIL_LABELS[$first['type'] ?? ''] ?? 'Notification';
    }
    $where = $company !== '' ? ' — ' . $company . ($branch !== '' ? ', ' . $branch : '') : '';
    return textShorten($topic . $where, 120);
}

/** The fixed subject of a thread, created from its first notification. */
function emailThread(PDO $pdo, string $key, array $first): array
{
    $stmt = $pdo->prepare('SELECT * FROM email_threads WHERE thread_key = :k');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $subject = emailThreadSubject($pdo, $key, $first);
    $secret = bin2hex(random_bytes(16));
    try {
        $pdo->prepare('INSERT INTO email_threads (thread_key, subject, secret) VALUES (:k, :s, :x)')
            ->execute(['k' => $key, 's' => $subject, 'x' => $secret]);
    } catch (Throwable $e) {
        // created by someone else a moment ago: read it back below
    }
    $stmt->execute(['k' => $key]);
    return $stmt->fetch() ?: ['thread_key' => $key, 'subject' => $subject, 'secret' => $secret];
}

function emailThreadToken(array $thread, int $userId): string
{
    return substr(hash_hmac('sha256', (string) $userId, (string) $thread['secret']), 0, 10);
}

/** Personal reply address for one person in one thread. */
function emailThreadReplyAddress(array $thread, int $userId): string
{
    $at = strrpos(INBOUND_ADDRESS, '@');
    if ($at === false) {
        return '';
    }
    $tag = $thread['thread_key'] . '.u' . $userId . '.' . emailThreadToken($thread, $userId);
    return substr(INBOUND_ADDRESS, 0, $at) . '+' . $tag . substr(INBOUND_ADDRESS, $at);
}

function emailThreadHasMessages(PDO $pdo, string $key): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM email_messages WHERE thread_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    return (bool) $stmt->fetchColumn();
}

function emailThreadAddParticipant(PDO $pdo, string $key, int $userId): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM email_thread_users WHERE thread_key = :k AND user_id = :u');
    $stmt->execute(['k' => $key, 'u' => $userId]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('INSERT INTO email_thread_users (thread_key, user_id) VALUES (:k, :u)')->execute(['k' => $key, 'u' => $userId]);
    }
}

function emailThreadLog(PDO $pdo, string $key, string $messageId, ?int $userId, string $direction, string $body): bool
{
    try {
        $pdo->prepare('INSERT INTO email_messages (thread_key, message_id, user_id, direction, body) VALUES (:k, :m, :u, :d, :b)')
            ->execute(['k' => $key, 'm' => $messageId, 'u' => $userId, 'd' => $direction, 'b' => $body]);
        return true;
    } catch (Throwable $e) {
        return false; // message id already stored
    }
}

/** Everyone involved in a conversation (name, role, email), found from the notifications and the request itself. */
function emailThreadPeople(PDO $pdo, string $key): array
{
    $ids = [];
    $collect = static function (string $sql, array $args) use ($pdo, &$ids): void {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        foreach ($s->fetchAll(PDO::FETCH_NUM) as $row) {
            foreach ($row as $v) {
                if ($v !== null && (int) $v > 0) {
                    $ids[(int) $v] = true;
                }
            }
        }
    };
    if (strpos($key, 'req-') === 0) {
        $id = (int) substr($key, 4);
        $collect('SELECT requested_by, reviewed_by_admin, reviewed_by_superadmin FROM modification_requests WHERE id = :i', ['i' => $id]);
        $collect("SELECT sent_to FROM notifications WHERE target_table = 'modification_requests' AND target_id = :i", ['i' => $id]);
    } elseif (strpos($key, 'apr-') === 0) {
        $id = (int) substr($key, 4);
        $collect("SELECT sent_to FROM notifications WHERE target_table = 'approval_requests' AND target_id = :i", ['i' => $id]);
        try {
            $collect('SELECT requested_by FROM approval_requests WHERE id = :i', ['i' => $id]);
        } catch (Throwable $e) {
        }
    }
    // Notifications that carry their own conversation (e.g. one per account lock).
    $collect('SELECT sent_to FROM notifications WHERE thread_key = :k', ['k' => $key]);
    $collect('SELECT user_id FROM email_thread_users WHERE thread_key = :k', ['k' => $key]);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', array_keys($ids)));
    return $pdo->query("SELECT id, full_name, email, role FROM users WHERE id IN ($in) AND status = 'active' ORDER BY CASE role WHEN 'user' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, full_name")->fetchAll();
}

/** Replies people sent by email, saved in the CRM, for several conversations at once: [thread_key => [reply, ...]]. */
function emailThreadRepliesFor(PDO $pdo, array $keys): array
{
    $keys = array_values(array_unique(array_filter($keys, 'is_string')));
    if (!$keys) {
        return [];
    }
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT m.thread_key, m.body, m.created_at, u.full_name, u.role
            FROM email_messages m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.direction = 'in' AND m.thread_key IN ($in) ORDER BY m.id");
        $stmt->execute($keys);
    } catch (Throwable $e) {
        return []; // tables not created yet: nothing to show
    }
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['thread_key']][] = $row;
    }
    return $out;
}

/** The "Conversation" block shown under a request / flag: every email reply, oldest first. Empty string when there are none. */
function renderEmailConversation(array $replies, bool $collapsible = false): string
{
    if (!$replies) {
        return '';
    }
    $total = count($replies);
    $collapse = $collapsible && $total > 1;
    $html = '<div class="email-convo" style="margin-top:8px;padding:8px 10px;background:#fafafa;border:1px solid #e4e4e4;border-radius:6px;">'
        . '<div style="font-size:11px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:#707070;margin-bottom:4px;">Conversation (replies sent by email)</div>';
    foreach ($replies as $i => $r) {
        $who = (string) ($r['full_name'] ?? 'Someone') . ' (' . emailRoleLabel((string) ($r['role'] ?? '')) . ')';
        // "Show less" keeps only the newest reply visible; the older ones carry class convo-old and are toggled together.
        $old = $collapse && $i < $total - 1;
        $html .= '<div' . ($old ? ' class="convo-old" hidden' : '') . ' style="margin:6px 0;font-size:13px;line-height:1.45;color:#171717;"><b>' . htmlspecialchars($who) . '</b> '
            . '<span style="color:#707070;font-size:12px;">' . htmlspecialchars(toLocalTime((string) $r['created_at'])) . '</span>'
            . '<div style="white-space:pre-wrap;">' . htmlspecialchars(inboundStripQuoted((string) $r['body'])) . '</div></div>';
    }
    if ($collapse) {
        $html .= '<button type="button" class="convo-toggle" data-more="Show more (' . ($total - 1) . ' earlier)" data-less="Show less" '
            . 'onclick="var c=this.closest(\'.email-convo\'),h=this.dataset.open!==\'1\';c.querySelectorAll(\'.convo-old\').forEach(function(e){e.hidden=!h});this.dataset.open=h?\'1\':\'0\';this.textContent=h?this.dataset.less:this.dataset.more;" '
            . 'style="border:0;background:none;padding:0;color:#d71920;font-size:12px;font-weight:700;text-decoration:underline;cursor:pointer;">Show more (' . ($total - 1) . ' earlier)</button>';
    }
    return $html . '</div>';
}

/** Called by inbound_mail.php after every run (UTC time, plus the error if the mailbox could not be read). */
function inboundRecordRun(PDO $pdo, ?string $error): void
{
    $now = gmdate('Y-m-d H:i:s');
    $err = $error !== null ? textShorten($error, 250) : null;
    $exists = $pdo->query('SELECT 1 FROM email_inbound_status WHERE id = 1')->fetchColumn();
    if ($exists) {
        $pdo->prepare('UPDATE email_inbound_status SET last_run_at = :t, last_error = :e WHERE id = 1')->execute(['t' => $now, 'e' => $err]);
    } else {
        $pdo->prepare('INSERT INTO email_inbound_status (id, last_run_at, last_error) VALUES (1, :t, :e)')->execute(['t' => $now, 'e' => $err]);
    }
}

/**
 * A red notice for admins when reply-by-email is switched on but the mail reader is not running or cannot read the
 * mailbox. Without it, replies would silently go nowhere. Empty string when all is well (or for non-admins).
 */
function inboundHealthBanner(PDO $pdo, string $role): string
{
    if (!INBOUND_REPLY_ENABLED || !in_array($role, ['admin', 'super_admin'], true)) {
        return '';
    }
    $problem = '';
    try {
        $row = $pdo->query('SELECT last_run_at, last_error FROM email_inbound_status WHERE id = 1')->fetch();
    } catch (Throwable $e) {
        return '';
    }
    if (!$row) {
        $problem = 'The mail reader has never run, so replies sent by email are not being delivered. Check that the scheduled task for inbound_mail.php is set up.';
    } elseif (!empty($row['last_error'])) {
        $problem = 'The mail reader could not read the mailbox, so replies sent by email are not being delivered: ' . $row['last_error'];
    } elseif (time() - (int) strtotime($row['last_run_at'] . ' UTC') > 600) {
        $problem = 'The mail reader has not run since ' . toLocalTime($row['last_run_at']) . ', so replies sent by email are not being delivered. Check the scheduled task for inbound_mail.php.';
    }
    if ($problem === '') {
        return '';
    }
    return '<div role="alert" style="margin:0 0 16px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #b91c1c;border-radius:6px;font-size:14px;color:#7f1d1d;"><b>Email replies are down.</b> '
        . htmlspecialchars($problem) . '</div>';
}

/** Where a conversation stands right now: [word, colour]. Used in the banner of relayed replies. */
function emailThreadStatus(PDO $pdo, string $key): array
{
    try {
        if (strpos($key, 'req-') === 0) {
            $q = $pdo->prepare('SELECT status FROM modification_requests WHERE id = :i');
            $q->execute(['i' => (int) substr($key, 4)]);
            $map = [
                'pending' => ['Waiting for review', '#b45309'],
                'admin_approved' => ['Pre-approved, waiting for final approval', '#b45309'],
                'admin_rejected' => ['Rejected by admin', '#b91c1c'],
                'superadmin_approved' => ['Approved', '#15803d'],
                'superadmin_rejected' => ['Rejected', '#b91c1c'],
            ];
            return $map[(string) $q->fetchColumn()] ?? ['Open', '#171717'];
        }
        if (strpos($key, 'apr-') === 0) {
            $q = $pdo->prepare('SELECT status FROM approval_requests WHERE id = :i');
            $q->execute(['i' => (int) substr($key, 4)]);
            $map = ['pending' => ['Waiting for review', '#b45309'], 'approved' => ['Approved', '#15803d'], 'rejected' => ['Rejected', '#b91c1c']];
            return $map[(string) $q->fetchColumn()] ?? ['Open', '#171717'];
        }
        if (strpos($key, 'lock-') === 0) {
            $q = $pdo->prepare("SELECT u.locked_at, (SELECT COUNT(*) FROM audit_logs a2 WHERE a2.action = 'account_unlocked' AND a2.target_id = u.id AND a2.id > a.id) AS unlocked
                FROM audit_logs a JOIN users u ON u.id = a.target_id WHERE a.id = :i");
            $q->execute(['i' => (int) substr($key, 5)]);
            $r = $q->fetch();
            if ($r) {
                return ((int) $r['unlocked'] > 0 || empty($r['locked_at'])) ? ['Unlocked', '#15803d'] : ['Locked, waiting for an admin', '#b91c1c'];
            }
        }
    } catch (Throwable $e) {
    }
    return strpos($key, 'flag-') === 0 ? ['Action needed', '#b45309'] : ['Open', '#171717'];
}

function emailRoleLabel(string $role): string
{
    return ['super_admin' => 'Super admin', 'admin' => 'Admin', 'user' => 'Sales'][$role] ?? 'Staff';
}

/** Sets threading headers / subject / reply address on a mailer for one recipient. */
function applyThreadHeaders(PDO $pdo, PHPMailer $mail, array $thread, int $userId, ?string $inReplyTo, string $uniq): string
{
    $key = (string) $thread['thread_key'];
    $root = emailThreadRootId($key);
    $messageId = '<crm.' . $key . '.' . $uniq . '.' . bin2hex(random_bytes(4)) . '@' . emailThreadDomain() . '>';
    $mail->MessageID = $messageId;
    $mail->clearCustomHeaders();
    $mail->clearReplyTos();
    $isReply = emailThreadHasMessages($pdo, $key);
    $mail->Subject = ($isReply ? 'Re: ' : '') . $thread['subject'];
    $mail->addCustomHeader('In-Reply-To', $inReplyTo ?? $root);
    $mail->addCustomHeader('References', $inReplyTo !== null && $inReplyTo !== $root ? $root . ' ' . $inReplyTo : $root);
    if (INBOUND_REPLY_ENABLED) {
        $reply = emailThreadReplyAddress($thread, $userId);
        if ($reply !== '') {
            $mail->addReplyTo($reply, SMTP_FROM_NAME . ' thread');
        }
    }
    return $messageId;
}

// ---------------------------------------------------------------- inbound replies

/** Plain text of a raw email: first text/plain part (or tags stripped from html), decoded. */
function inboundMimeBody(string $raw): array
{
    [$headerText, $body] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');
    $headers = inboundParseHeaders($headerText);
    return [$headers, inboundPartText($headers, $body)];
}

function inboundParseHeaders(string $text): array
{
    $text = preg_replace("/\r?\n[ \t]+/", ' ', $text);
    $out = [];
    foreach (preg_split("/\r?\n/", $text) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        $out[$k] = isset($out[$k]) ? $out[$k] . "\n" . trim($v) : trim($v);
    }
    return $out;
}

function inboundPartText(array $headers, string $body): string
{
    $ctype = $headers['content-type'] ?? 'text/plain';
    if (stripos($ctype, 'multipart/') === 0 && preg_match('/boundary="?([^";]+)"?/i', $ctype, $m)) {
        $plain = null;
        $html = null;
        foreach (explode('--' . $m[1], $body) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || strpos($part, '--') === 0) {
                continue;
            }
            [$ph, $pb] = array_pad(preg_split("/\r?\n\r?\n/", $part, 2), 2, '');
            $h = inboundParseHeaders($ph);
            $t = strtolower($h['content-type'] ?? 'text/plain');
            if (strpos($t, 'multipart/') === 0) {
                $text = inboundPartText($h, $pb);
                $plain = $plain ?? $text;
            } elseif (strpos($t, 'text/plain') === 0) {
                $plain = $plain ?? inboundPartText($h, $pb);
            } elseif (strpos($t, 'text/html') === 0) {
                $html = $html ?? inboundPartText($h, $pb);
            }
        }
        return $plain ?? ($html ?? '');
    }
    $enc = strtolower($headers['content-transfer-encoding'] ?? '');
    if ($enc === 'base64') {
        $body = (string) base64_decode($body);
    } elseif ($enc === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    if (stripos($ctype, 'text/html') === 0) {
        $body = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $body)), ENT_QUOTES, 'UTF-8');
    }
    return $body;
}

/** The new text only: drops quoted history ("> ...", "On ... wrote:", "-----Original Message-----"). */
function inboundStripQuoted(string $text): string
{
    $lines = preg_split("/\r?\n/", str_replace("\r\n", "\n", $text));
    $keep = [];
    foreach ($lines as $i => $line) {
        // Mail clients wrap the "On <date>, <name> <address> wrote:" line over two or three lines: look ahead and join them.
        if (preg_match('/^\s*On\s/i', $line)) {
            $joined = trim($line);
            for ($k = 1; $k <= 2 && isset($lines[$i + $k]); $k++) {
                $joined .= ' ' . trim($lines[$i + $k]);
                if (preg_match('/^On\s.{5,300}\swrote:?$/i', $joined)) {
                    break 2;
                }
            }
        }
        if (preg_match('/^\s*(On .{5,200} wrote:|-{2,}\s*Original Message\s*-{2,}|From:\s.+|_{5,})\s*$/i', $line) || preg_match('/^On .{5,200}$/', $line) && count($keep) > 0 && preg_match('/wrote:?$/', $line)) {
            break;
        }
        if (preg_match('/^\s*>/', $line)) {
            continue;
        }
        if (preg_match('/^--\s?$/', $line)) {
            break; // signature delimiter: everything after it is the sender's signature
        }
        $keep[] = $line;
    }
    // Mobile / webmail footers that clients add under the message.
    while ($keep && preg_match('/^\s*(Sent from my .{2,40}|Sent from Mail for Windows.*|Get Outlook for .{2,20}|Sent via .{2,30})\s*$|^\s*$/i', end($keep))) {
        array_pop($keep);
    }
    return trim(implode("\n", $keep));
}

function inboundDecodeHeader(string $v): string
{
    return function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode($v, 0, 'UTF-8') : $v;
}

/**
 * Tells the owner of a reply address that their reply was not passed on, instead of dropping it silently.
 * Goes only to the email registered on that CRM account (never to the From address of the unknown sender),
 * and is marked Auto-Submitted so autoresponders do not answer it.
 */
function inboundNotifyIgnored(array $thread, array $user, string $why): void
{
    if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $mail = null;
    try {
        $mail = createSmtpMailer();
        $mail->addAddress($user['email'], $user['full_name']);
        $mail->Subject = 'Your reply was not sent: ' . $thread['subject'];
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $esc = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $mail->Body = emailLayout('Your reply was not sent',
            '<p style="margin:0 0 14px;">Hi ' . $esc($user['full_name']) . ',</p><p style="margin:0 0 14px;">Your reply in &ldquo;' . $esc($thread['subject']) . '&rdquo; was not passed on to the others because ' . $esc($why) . '.</p>'
            . '<p style="margin:0;">Please reply again from the email address on your CRM account, with your message above any quoted text.</p>',
            APP_BASE_URL !== '' ? ['Open Syspoint CRM', APP_BASE_URL . '/notifications.php'] : null, 'Your reply was not sent');
        $mail->AltBody = "Hi {$user['full_name']},\n\nYour reply in \"{$thread['subject']}\" was not passed on because {$why}.\nPlease reply again from the email address on your CRM account, with your message above any quoted text." . emailPlainFooter();
        $mail->send();
    } catch (Throwable $e) {
        error_log('Ignored-reply notice failed: ' . $e->getMessage());
    } finally {
        if ($mail !== null) {
            try { $mail->smtpClose(); } catch (Throwable $e) {}
        }
    }
}

/**
 * Handles one raw inbound email. Returns ['ok'=>bool, 'status'=>string]. Replies are accepted only when the
 * personal address token is valid, the sender is that same person's email, and the person is active.
 */
function processInboundEmail(PDO $pdo, string $raw): array
{
    [$h, $text] = inboundMimeBody($raw);
    // Out-of-office and other automatic answers must never be relayed (two autoresponders would otherwise loop forever).
    $auto = strtolower(($h['auto-submitted'] ?? '') . ' ' . ($h['precedence'] ?? '') . ' ' . ($h['x-autoreply'] ?? '') . ' ' . ($h['x-autorespond'] ?? '') . ' ' . ($h['x-auto-response-suppress'] ?? ''));
    if (trim($h['auto-submitted'] ?? 'no') !== 'no' && trim($h['auto-submitted'] ?? '') !== ''
        || preg_match('/bulk|junk|auto_reply|auto-reply|autoreply/', $auto)
        || preg_match('/^(mailer-daemon|postmaster)@/i', trim($h['from'] ?? '', " <>"))) {
        return ['ok' => false, 'status' => 'automatic reply ignored'];
    }
    $recipients = ($h['to'] ?? '') . ' ' . ($h['delivered-to'] ?? '') . ' ' . ($h['x-original-to'] ?? '') . ' ' . ($h['cc'] ?? '');
    if (!preg_match('/\+([a-z0-9-]+)\.u(\d+)\.([a-f0-9]{10})@/i', $recipients, $m)) {
        return ['ok' => false, 'status' => 'not a thread reply'];
    }
    [$key, $userId, $token] = [strtolower($m[1]), (int) $m[2], strtolower($m[3])];
    $stmt = $pdo->prepare('SELECT * FROM email_threads WHERE thread_key = :k');
    $stmt->execute(['k' => $key]);
    $thread = $stmt->fetch();
    if (!$thread || !hash_equals(emailThreadToken($thread, $userId), $token)) {
        return ['ok' => false, 'status' => 'bad token'];
    }
    $u = $pdo->prepare("SELECT id, full_name, email, role FROM users WHERE id = :id AND status = 'active'");
    $u->execute(['id' => $userId]);
    $user = $u->fetch();
    if (!$user) {
        return ['ok' => false, 'status' => 'user inactive'];
    }
    if (!preg_match('/[\w.+-]+@[\w.-]+/', $h['from'] ?? '', $fm) || strcasecmp($fm[0], $user['email']) !== 0) {
        inboundNotifyIgnored($thread, $user, 'it was sent from an address that is not the one registered on your CRM account');
        return ['ok' => false, 'status' => 'sender does not match'];
    }
    $messageId = trim($h['message-id'] ?? '');
    if ($messageId === '') {
        $messageId = '<in.' . sha1($raw) . '@inbound>';
    }
    $reply = textShorten(inboundStripQuoted($text), 4000);
    if ($reply === '') {
        inboundNotifyIgnored($thread, $user, 'it had no text of its own (only quoted text or an attachment)');
        return ['ok' => false, 'status' => 'empty reply'];
    }
    if (!emailThreadLog($pdo, $key, $messageId, $userId, 'in', $reply)) {
        return ['ok' => false, 'status' => 'duplicate'];
    }
    emailThreadAddParticipant($pdo, $key, $userId);
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'thread_reply', 'email_threads', NULL, :d)")
        ->execute(['uid' => $userId, 'd' => 'Replied by email in "' . $thread['subject'] . '"']);

    // Everyone in the conversation, not only the people who happened to be emailed in it already: the requester,
    // the reviewers and anyone notified about it (the same list the "In this conversation" line shows).
    $people = array_values(array_filter(emailThreadPeople($pdo, $key), static fn(array $o): bool => (int) $o['id'] !== $userId));
    $sent = 0;
    if ($people) {
        $mail = createSmtpMailer();
        $mail->setFrom(SMTP_FROM_EMAIL, $user['full_name'] . ' (' . emailRoleLabel((string) $user['role']) . ') via ' . SMTP_FROM_NAME);
        try {
            foreach ($people as $person) {
                if (!filter_var($person['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $mail->clearAddresses();
                $mail->addAddress($person['email'], $person['full_name']);
                $mid = applyThreadHeaders($pdo, $mail, $thread, (int) $person['id'], $messageId, 'r' . $userId);
                $esc = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
                [$statusWord, $statusColor] = emailThreadStatus($pdo, $key);
                $banner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;"><tr><td style="padding:10px 14px;background:' . $statusColor . ';border-radius:6px;font-size:13px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:#ffffff;">Status: ' . $esc($statusWord) . '</td></tr></table>';
                $when = toLocalTime(gmdate('Y-m-d H:i:s'), 'M j, g:i A');
                $card = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:12px 14px;background:#fafafa;border:1px solid #e4e4e4;border-left:4px solid #171717;border-radius:6px;">'
                    . '<div style="font-size:12px;font-weight:bold;color:#171717;">' . $esc($user['full_name']) . ' <span style="font-weight:normal;color:#707070;">(' . $esc(emailRoleLabel((string) $user['role'])) . ') &middot; ' . $esc($when) . '</span></div>'
                    . '<div style="margin-top:6px;font-size:14px;line-height:1.5;color:#171717;white-space:pre-wrap;">' . $esc($reply) . '</div></td></tr></table>';
                $others = array_values(array_filter($people, static fn(array $o): bool => (int) $o['id'] !== (int) $person['id']));
                $others[] = ['full_name' => $user['full_name'], 'role' => $user['role']];
                $peopleHtml = '<p style="margin:18px 0 0;font-size:12px;color:#707070;">In this conversation: ' . $esc(implode(', ', array_map(static fn(array $o): string => $o['full_name'] . ' (' . emailRoleLabel((string) $o['role']) . ')', $others))) . '.</p>';
                $mail->Body = emailLayout($thread['subject'], $banner . '<p style="margin:0 0 14px;">Hi ' . $esc($person['full_name']) . ',</p>' . $card . $peopleHtml
                    . (INBOUND_REPLY_ENABLED ? '<p style="margin:10px 0 0;font-size:12px;color:#707070;">Just reply to this email to answer everyone. Your answer is saved in the CRM.</p>' : ''),
                    APP_BASE_URL !== '' ? ['Open Syspoint CRM', APP_BASE_URL . '/notifications.php'] : null, $user['full_name'] . ': ' . $reply);
                $mail->AltBody = $thread['subject'] . "\nStatus: {$statusWord}\n\nHi {$person['full_name']},\n\n{$user['full_name']} (" . emailRoleLabel((string) $user['role']) . ") replied:\n\n{$reply}" . emailPlainFooter();
                $mail->send();
                emailThreadLog($pdo, $key, $mid, (int) $person['id'], 'out', $reply);
                emailThreadAddParticipant($pdo, $key, (int) $person['id']); // their reply to this email is then accepted and relayed back
                $sent++;
            }
        } finally {
            try { $mail->smtpClose(); } catch (Throwable $e) {}
        }
    }
    return ['ok' => true, 'status' => 'forwarded to ' . $sent . ' people'];
}

// ---------------------------------------------------------------- minimal IMAP reader

/**
 * Unread messages that were sent to one of our reply addresses (name+tag@domain), as raw emails.
 * Other unread mail is left alone (not downloaded, not marked read). Plain IMAP over a socket, no PHP imap extension needed.
 * One round trip fetches the addressing headers of all recent unread mail; only matching messages are downloaded in full.
 */
function imapFetchUnread(string $host, int $port, string $user, string $pass, bool $ssl, int $max = 25, string $addressLocal = ''): array
{
    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15);
    if (!$fp) {
        throw new RuntimeException("IMAP connect failed: $es");
    }
    stream_set_timeout($fp, 20);
    fgets($fp);
    $n = 0;
    $cmd = static function (string $c) use ($fp, &$n): array {
        $tag = 'A' . (++$n);
        fwrite($fp, $tag . ' ' . $c . "\r\n");
        $lines = [];
        $literals = [];
        while (($line = fgets($fp)) !== false) {
            if (strpos($line, $tag . ' ') === 0) {
                return [strpos($line, $tag . ' OK') === 0, $lines, $literals];
            }
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $size = (int) $m[1];
                $data = '';
                while (strlen($data) < $size) {
                    $chunk = fread($fp, $size - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }
                $literals[] = $data;
                if (preg_match('/^\* (\d+) FETCH/', $line, $fm)) {
                    $lines[] = '* FETCHLITERAL ' . $fm[1];
                    continue;
                }
            }
            $lines[] = $line;
        }
        return [false, $lines, $literals];
    };
    $q = static fn(string $s): string => '"' . addcslashes($s, '"\\') . '"';
    [$ok] = $cmd('LOGIN ' . $q($user) . ' ' . $q($pass));
    if (!$ok) {
        fclose($fp);
        throw new RuntimeException('IMAP login failed');
    }
    $cmd('SELECT INBOX');
    [, $lines] = $cmd('SEARCH UNSEEN');
    $ids = [];
    foreach ($lines as $l) {
        if (preg_match('/^\* SEARCH ?(.*)$/', trim($l), $m) && $m[1] !== '') {
            $ids = array_merge($ids, preg_split('/\s+/', trim($m[1])));
        }
    }
    $ids = array_slice(array_map('intval', $ids), -200); // the most recent 200 unread
    $wanted = [];
    if ($ids) {
        [, $hl, $lits] = $cmd('FETCH ' . implode(',', $ids) . ' (BODY.PEEK[HEADER.FIELDS (TO CC DELIVERED-TO X-ORIGINAL-TO)])');
        $k = 0;
        foreach ($hl as $l) {
            if (preg_match('/^\* FETCHLITERAL (\d+)/', $l, $m)) {
                $head = $lits[$k++] ?? '';
                if ($addressLocal === '' || stripos($head, $addressLocal . '+') !== false) {
                    $wanted[] = (int) $m[1];
                }
            }
        }
    }
    $mails = [];
    foreach (array_slice($wanted, 0, $max) as $id) {
        [$okf, , $lits] = $cmd('FETCH ' . $id . ' (BODY.PEEK[])');
        if ($okf && !empty($lits[0])) {
            $mails[$id] = $lits[0];
            $cmd('STORE ' . $id . ' +FLAGS (\\Seen)');
        }
    }
    $cmd('LOGOUT');
    fclose($fp);
    return $mails;
}
