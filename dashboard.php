<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
/////==============================================
/////                   DASHBOARD
/////==============================================
$pdo = getDbConnection();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
requireActivePassword($pdo, $userId); // also sends the special user to export.php
generateOrderNotifications($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Inline "update last order date" action — moved here from the old standalone
// order_dates.php page so it lives inside the client-details modal instead of
// its own nav item. Only the owning user can do this; admin/super_admin are
// supervision-only and own no branches of their own to update.
$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_order_date') {
    if ($role !== 'user' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $flashMessage = 'Could not update — please try again.';
    } else {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? '';
        $check = $pdo->prepare('SELECT id FROM branches WHERE id = :id AND assigned_user_id = :uid');
        $check->execute(['id' => $branchId, 'uid' => $userId]);

        if ($check->fetch() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            $existing = $pdo->prepare('SELECT id FROM order_dates WHERE branch_id = :bid');
            $existing->execute(['bid' => $branchId]);
            if ($existing->fetch()) {
                $pdo->prepare('UPDATE order_dates SET last_order_date = :d, updated_at = CURRENT_TIMESTAMP WHERE branch_id = :bid')
                    ->execute(['d' => $orderDate, 'bid' => $branchId]);
            } else {
                $pdo->prepare('INSERT INTO order_dates (branch_id, last_order_date) VALUES (:bid, :d)')
                    ->execute(['bid' => $branchId, 'd' => $orderDate]);
            }
            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, target_table, target_id, details)
                 VALUES (:uid, 'edit', 'order_dates', :tid, :details)"
            )->execute(['uid' => $userId, 'tid' => $branchId, 'details' => 'Updated last order date to ' . $orderDate]);
            // Redirect so a page refresh doesn't resubmit the form.
            header('Location: dashboard.php?updated=1');
            exit;
        }
        $flashMessage = 'Could not update — check the date and try again.';
    }
}
// Log an engagement (email / call / Viber / ...): proof the client is still being worked
// even with no new order. Owner only, no approval, and it resets the 3/6-month flag.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_engagement') {
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $type = (string) ($_POST['engagement_type'] ?? '');
    $engagedOn = (string) ($_POST['engaged_on'] ?? '');
    $engagedTime = trim((string) ($_POST['engaged_time'] ?? ''));
    $note = trim((string) ($_POST['engagement_note'] ?? ''));
    $today = toLocalTime(gmdate('Y-m-d H:i:s'), 'Y-m-d');
    $nowTime = toLocalTime(gmdate('Y-m-d H:i:s'), 'H:i');
    $check = $pdo->prepare("SELECT id FROM branches WHERE id = :id AND assigned_user_id = :uid AND status = 'active'");
    $check->execute(['id' => $branchId, 'uid' => $userId]);
    if ($role !== 'user' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $flashMessage = 'Could not log the engagement — please try again.';
    } elseif (!$check->fetch() || !isset(ENGAGEMENT_TYPES[$type])) {
        $flashMessage = 'Could not log the engagement — choose a type and try again.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $engagedOn) || $engagedOn > $today || $engagedOn < gmdate('Y-m-d', strtotime('-1 year'))) {
        $flashMessage = 'The engagement date can be today or up to a year back, not in the future.';
    } elseif ($engagedTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $engagedTime)) {
        $flashMessage = 'Enter the time as hours and minutes, or leave it blank.';
    } elseif ($engagedTime !== '' && $engagedOn === $today && $engagedTime > $nowTime) {
        $flashMessage = 'The engagement time can\'t be later than the current time today.';
    } elseif (strlen($note) > 500) {
        $flashMessage = 'Keep the remark under 500 characters.';
    } else {
        logEngagement($pdo, $userId, $branchId, $type, $engagedOn, $note, $engagedTime);
        // Reopen the same client's popup so several engagements can be entered in a row.
        header('Location: dashboard.php?engaged=1&open=' . $branchId);
        exit;
    }
}
// Move a contact person up or down in its client's primary / secondary / third order. Owner only, no approval.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_contact') {
    $moveBranchId = (int) ($_POST['branch_id'] ?? 0);
    $moveContactId = (int) ($_POST['contact_id'] ?? 0);
    $direction = ($_POST['direction'] ?? '') === 'up' ? -1 : (($_POST['direction'] ?? '') === 'down' ? 1 : 0);
    $ownsIt = $pdo->prepare("SELECT 1 FROM branches WHERE id = :id AND assigned_user_id = :uid AND status = 'active'");
    $ownsIt->execute(['id' => $moveBranchId, 'uid' => $userId]);
    if ($role !== 'user' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '') || !$ownsIt->fetchColumn() || $direction === 0) {
        $flashMessage = 'Could not reorder the contacts — please try again.';
    } else {
        $orderStmt = $pdo->prepare("SELECT id, full_name FROM contact_persons WHERE branch_id = :b AND status = 'active' ORDER BY sort_order, id");
        $orderStmt->execute(['b' => $moveBranchId]);
        $ordered = $orderStmt->fetchAll();
        $position = null;
        foreach ($ordered as $i => $row) {
            if ((int) $row['id'] === $moveContactId) {
                $position = $i;
            }
        }
        $target = $position === null ? null : $position + $direction;
        if ($position !== null && $target >= 0 && $target < count($ordered)) {
            moveContactToRank($pdo, $moveBranchId, $moveContactId, $target + 1);
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'contact_reorder', 'contact_persons', :tid, :details)")
                ->execute(['uid' => $userId, 'tid' => $moveContactId, 'details' => 'Moved contact ' . $ordered[$position]['full_name'] . ' to ' . contactRankLabel($target + 1)]);
        }
        header('Location: dashboard.php?moved=1&open=' . $moveBranchId);
        exit;
    }
}
if (($_GET['engaged'] ?? '') === '1') {
    $flashMessage = 'Engagement logged. The 3/6-month timer now counts from that date.';
}
if (($_GET['updated'] ?? '') === '1') {
    $flashMessage = 'Order date updated. The 3/6-month timer resets from this new date.';
}

// Branch data: users see only their own assigned branches. Admin/super_admin
// have no clients of their own (supervision-only), so they see every active
// branch org-wide instead, with the current owner's name for context.
if ($role === 'user') {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.branch_name, b.address, b.created_at, b.status AS branch_status, c.name AS company_name, c.id AS company_id, o.last_order_date,
                (SELECT MAX(e.engaged_on) FROM engagements e WHERE e.branch_id = b.id) AS last_engagement_date,
                NULL AS owner_name, b.assigned_user_id AS owner_id,
                COALESCE(
                    (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.target_table = 'branches' AND a.target_id = b.id),
                    b.last_modified, b.created_at
                ) AS last_modified
         FROM branches b
         JOIN companies c ON c.id = b.company_id
         LEFT JOIN order_dates o ON o.branch_id = b.id
         WHERE b.assigned_user_id = :user_id AND b.status = 'active'
         ORDER BY c.name, b.branch_name"
    );
    $stmt->execute(['user_id' => $userId]);
} else {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.branch_name, b.address, b.created_at, b.status AS branch_status, c.name AS company_name, c.id AS company_id, o.last_order_date,
                (SELECT MAX(e.engaged_on) FROM engagements e WHERE e.branch_id = b.id) AS last_engagement_date,
                u.full_name AS owner_name, u.id AS owner_id, u.role AS owner_role,
                COALESCE(
                    (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.target_table = 'branches' AND a.target_id = b.id),
                    b.last_modified, b.created_at
                ) AS last_modified
         FROM branches b
         JOIN companies c ON c.id = b.company_id
         LEFT JOIN order_dates o ON o.branch_id = b.id
         LEFT JOIN users u ON u.id = b.assigned_user_id
         " . ($role === 'admin' ? '' : "WHERE b.status = 'active'") . "
         ORDER BY c.name, b.branch_name"
    );
    $stmt->execute();
}
$branches = $stmt->fetchAll();

// Super admin only: pick one salesperson to see that person's whole dashboard (?sales=ID).
// Everything below (stat cards, list, details) is then built from just their clients.
$salesOptions = [];
$salesFilter = null;
if ($role === 'super_admin') {
    $salesOptions = $pdo->query(
        "SELECT u.id, u.full_name, u.status,
                (SELECT COUNT(*) FROM branches b WHERE b.assigned_user_id = u.id AND b.status = 'active') AS n
         FROM users u
         WHERE u.role = 'user'
           AND (u.status = 'active' OR EXISTS (SELECT 1 FROM branches b WHERE b.assigned_user_id = u.id AND b.status = 'active'))
         ORDER BY u.full_name"
    )->fetchAll();
    $wantedSales = (int) ($_GET['sales'] ?? 0);
    foreach ($salesOptions as $opt) {
        if ((int) $opt['id'] === $wantedSales) {
            $salesFilter = $opt;
        }
    }
    if ($salesFilter !== null) {
        $branches = array_values(array_filter($branches, static fn($b) => (int) ($b['owner_id'] ?? 0) === $wantedSales));
    }
}

// Categories per company (a company can have several); none = "Uncategorized".
$categoryMap = fetchCompanyCategories($pdo, array_column($branches, 'company_id'));

// An admin gets the numbers but not the list: the CEO does not want anyone below
// super admin able to browse every company/branch name (the names alone are enough
// to go looking for the contact people). Admins still reach individual clients
// through Manage users (per-person client panels, used for reassignment), the
// Approvals queue, and the search-only "Find a client" page. The counts below are
// computed from the full list first, then the list itself is dropped before anything
// is rendered, so nothing about it reaches the browser.
$adminSeesNoList = ($role === 'admin');

// Summary counts for the stat cards, computed from real data.
// Every client falls in exactly one bucket, so the five status cards always add up to Total clients:
// active (in contact), quiet (active but no contact for 30+ days), warning, danger, neutral (no orders yet).
$bucketOf = static fn(array $st): string => ($st['class'] === 'active' && $st['quiet']) ? 'quiet' : $st['class'];
$statusCounts = ['active' => 0, 'quiet' => 0, 'warning' => 0, 'danger' => 0, 'neutral' => 0];
$totalClients = 0;
foreach ($branches as $b) {
    if (($b['branch_status'] ?? 'active') !== 'active') {
        continue; // an admin's list also carries inactive branches; the stat cards count active clients only
    }
    $statusCounts[$bucketOf(orderStatus($b['last_order_date'], $b['last_engagement_date']))]++;
    $totalClients++;
}

// Super admin: clients added during one Monday-to-Sunday week (Manila time), newest first, with who
// added each one (from the audit log; older rows with no entry fall back to the current owner).
$weekAdds = null;
$addedThisWeekIds = [];
if ($role === 'super_admin') {
    $tzManila = new DateTimeZone('+08:00');
    $todayManila = new DateTimeImmutable('today', $tzManila);
    $thisMonday = $todayManila->modify('-' . ((int) $todayManila->format('N') - 1) . ' days');
    $weekStart = $thisMonday;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['week'] ?? '')) === 1) {
        try {
            $picked = new DateTimeImmutable($_GET['week'], $tzManila);
            $weekStart = $picked->modify('-' . ((int) $picked->format('N') - 1) . ' days');
        } catch (Exception $e) {
            $weekStart = $thisMonday;
        }
    }
    if ($weekStart > $thisMonday) {
        $weekStart = $thisMonday;
    }
    $weekEnd = $weekStart->modify('+7 days');
    $utcZone = new DateTimeZone('UTC');
    $addsSql = "SELECT b.id, b.branch_name, b.created_at, c.name AS company_name, o.full_name AS owner_name, b.assigned_user_id,
                       (SELECT u2.full_name FROM audit_logs a JOIN users u2 ON u2.id = a.user_id
                        WHERE a.action = 'create' AND a.target_table = 'branches' AND a.target_id = b.id ORDER BY a.id LIMIT 1) AS adder_name
                FROM branches b
                JOIN companies c ON c.id = b.company_id
                LEFT JOIN users o ON o.id = b.assigned_user_id
                WHERE b.status = 'active' AND b.created_at >= :f AND b.created_at < :t"
        . ($salesFilter !== null ? ' AND b.assigned_user_id = :sid' : '')
        . ' ORDER BY b.created_at DESC, b.id DESC';
    $addsParams = ['f' => $weekStart->setTimezone($utcZone)->format('Y-m-d H:i:s'), 't' => $weekEnd->setTimezone($utcZone)->format('Y-m-d H:i:s')];
    if ($salesFilter !== null) {
        $addsParams['sid'] = (int) $salesFilter['id'];
    }
    $addsStmt = $pdo->prepare($addsSql);
    $addsStmt->execute($addsParams);
    $weekRows = $addsStmt->fetchAll();
    $perAdder = [];
    foreach ($weekRows as $r) {
        $addedThisWeekIds[(int) $r['id']] = true;
        $who = $r['adder_name'] ?: ($r['owner_name'] ?: 'Unknown');
        $perAdder[$who] = ($perAdder[$who] ?? 0) + 1;
    }
    arsort($perAdder);
    $weekAdds = [
        'rows' => $weekRows,
        'perAdder' => $perAdder,
        'start' => $weekStart,
        'end' => $weekEnd->modify('-1 day'),
        'isCurrent' => $weekStart == $thisMonday,
        'prev' => $weekStart->modify('-7 days')->format('Y-m-d'),
        'next' => $weekStart < $thisMonday ? $weekStart->modify('+7 days')->format('Y-m-d') : null,
    ];
}
$dashUrl = static function (array $params): string {
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return 'dashboard.php' . ($params ? '?' . http_build_query($params) : '');
};

// Contact persons for each branch, so the "view details" popup can show real
// people instead of the fixed two-contact-slot shape the design mockup assumed.
// One query for all branches at once rather than one query per row.
//
// Visibility rule: a contact's details are visible to whoever CURRENTLY owns the
// branch (assigned_user_id) and to a super admin. It does not matter who typed the
// contact in: when an admin or super admin creates a company for an employee, or a
// branch is reassigned, the owner must still see its contacts. (This used to key on
// contact_persons.created_by, which hid every contact from the employee it had been
// assigned to.) Anyone else sees a count of hidden contacts, not the contacts.
$contactsByBranch = [];
$hiddenContactCount = [];
if ($branches) {
    $branchIds = array_column($branches, 'id');
    $ownerByBranch = array_column($branches, 'owner_id', 'id');
    $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
    $contactStmt = $pdo->prepare(
        "SELECT id, branch_id, full_name, position, contact_number, contact_number_type, contact_number_2, contact_number_2_type, contact_numbers_extra, contact_emails_extra, email
         FROM contact_persons
         WHERE status = 'active' AND branch_id IN ($placeholders)
         ORDER BY branch_id, sort_order, id"
    );
    $contactStmt->execute($branchIds);
    foreach ($contactStmt->fetchAll() as $c) {
        $branchOwnerId = $ownerByBranch[$c['branch_id']] ?? null;
        $canView = $role === 'super_admin'
            || ($branchOwnerId !== null && (int) $branchOwnerId === (int) $userId);
        if (!$canView) {
            $hiddenContactCount[$c['branch_id']] = ($hiddenContactCount[$c['branch_id']] ?? 0) + 1;
            continue;
        }
        $contactsByBranch[$c['branch_id']][] = [
            'id' => (int) $c['id'],
            'name' => $c['full_name'],
            'position' => $c['position'] ?: '',
            'phones' => array_values(array_filter(explode(' · ', formatContactNumbers($c)), static fn($x) => $x !== '')),
            'email' => $c['email'] ?: '',
            'emails' => allContactEmails($c),
        ];
    }
}

// Recent engagements per branch (newest first, five each) for the popup.
$engagementsByBranch = [];
if ($branches) {
    $branchIds = array_column($branches, 'id');
    $marks = implode(',', array_fill(0, count($branchIds), '?'));
    $engStmt = $pdo->prepare(
        "SELECT e.branch_id, e.type, e.engaged_on, e.engaged_time, e.note, u.full_name
         FROM engagements e JOIN users u ON u.id = e.user_id
         WHERE e.branch_id IN ($marks)
         ORDER BY e.engaged_on DESC, e.engaged_time DESC, e.id DESC"
    );
    $engStmt->execute($branchIds);
    foreach ($engStmt->fetchAll() as $e) {
        if (count($engagementsByBranch[$e['branch_id']] ?? []) < 5) {
            $engagementsByBranch[$e['branch_id']][] = [
                'type' => ENGAGEMENT_TYPES[$e['type']] ?? $e['type'],
                'date' => $e['engaged_on'],
                'time' => !empty($e['engaged_time']) ? date('g:i A', strtotime($e['engaged_time'])) : '',
                'note' => $e['note'] ?: '',
                'by' => $e['full_name'],
            ];
        }
    }
}

// Same branch data, reshaped for the client-details popup (real fields only —
// no fabricated "orders count", since it doesn't exist in the schema).
$branchDetails = [];
foreach ($branches as $b) {
    $status = orderStatus($b['last_order_date'], $b['last_engagement_date']);
    $branchDetails[$b['id']] = [
        'company' => $b['company_name'],
        'companyId' => (int) $b['company_id'],
        'categories' => categoryLabels($categoryMap[(int) $b['company_id']] ?? []),
        'branch' => $b['branch_name'],
        'address' => $b['address'] ?: 'Not on file',
        'lastOrder' => $b['last_order_date'] ?: 'No orders yet',
        'lastOrderRaw' => $b['last_order_date'] ?: '',
        'status' => $status['label'],
        'statusClass' => $status['class'],
        'quiet' => $status['quiet'],
        'lastEngagement' => $b['last_engagement_date'] ?: '',
        'engagements' => $engagementsByBranch[$b['id']] ?? [],
        'contacts' => $contactsByBranch[$b['id']] ?? [],
        'hiddenContacts' => $hiddenContactCount[$b['id']] ?? 0,
        'canAddContact' => $role === 'user',
        'owner' => $b['owner_name'] ?? null,
        'ownerId' => isset($b['owner_id']) && $b['owner_id'] !== null ? (int) $b['owner_id'] : null,
        // Reassign (below) only makes sense when the current owner is nobody or a
        // regular user — admin/super admin are never supposed to hold clients, so a
        // branch that somehow ended up on one isn't offered for reassignment here.
        'reassignable' => !isset($b['owner_role']) || $b['owner_role'] === null || $b['owner_role'] === 'user',
    ];
}

// Only admin/super_admin see the Reassign control, so only fetch this for them.
$employees = ($role === 'admin' || $role === 'super_admin') ? activeEmployees($pdo) : [];

// Unread notification count for the bell icon.
$notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE sent_to = :user_id AND is_read = 0');
$notifStmt->execute(['user_id' => $userId]);
$unreadCount = (int) $notifStmt->fetchColumn();

// Initials for the profile avatar, e.g. "Mark Red" -> "MR"
$fullName = $_SESSION['full_name'];
$nameParts = preg_split('/\s+/', trim($fullName));
$initials = strtoupper(substr($nameParts[0] ?? '', 0, 1) . substr($nameParts[count($nameParts) - 1] ?? '', 0, 1));

// Account-manager avatars (admin and super admin lists): initials in a round badge. The colour is dealt out by
// position among the managers actually shown, so two managers never share one (until there are more managers than
// colours); initials can still match (two "RND"s), so the full name always sits beside or under it too.
$avatarPalette = ['#d71920', '#1d6fb8', '#1a7a30', '#8a4fc7', '#c2680a', '#0e8a8a', '#b3226b', '#5b6770', '#7a5c00', '#3f51b5', '#2e7d32', '#6d4c41'];
$avatarColorOf = [];
if ($role !== 'user') {
    $names = [];
    foreach ($branches as $b) {
        if (!empty($b['owner_id'])) {
            $names[(int) $b['owner_id']] = (string) $b['owner_name'];
        }
    }
    asort($names);
    $i = 0;
    foreach ($names as $uid => $nm) {
        $avatarColorOf[$uid] = $avatarPalette[$i++ % count($avatarPalette)];
    }
}
$managerBadge = static function (?string $name, ?int $uid, bool $small = false) use ($avatarColorOf): string {
    if ($name === null || $name === '') {
        return '<span class="mgr"><span class="mgr-av none">?</span>Unassigned</span>';
    }
    $parts = preg_split('/\s+/', trim($name));
    $ini = strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : ''));
    $bg = $avatarColorOf[(int) $uid] ?? '#5b6770';
    $av = '<span class="mgr-av' . ($small ? ' sm' : '') . '" style="background:' . $bg . '" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($ini) . '</span>';
    return $small ? $av : '<span class="mgr">' . $av . htmlspecialchars($name) . '</span>';
};

// Sidebar menu. href = null means "not built yet, show as disabled."

if ($role === 'user') {
    $menu[] = ['label' => 'Clients', 'icon' => '📋', 'href' => 'dashboard.php'];
    $menu[] = ['label' => 'All clients', 'icon' => '🗂️', 'href' => 'all_clients.php'];
}

if ($role === 'admin' || $role === 'super_admin') {
    $menu[] = ['label' => $role === 'admin' ? 'Overview' : 'All clients', 'icon' => '📋', 'href' => 'dashboard.php'];
    if ($role === 'super_admin') {
        $menu[] = ['label' => 'Companies', 'icon' => '🏷️', 'href' => 'manage_companies.php'];
    }
    $menu[] = ['label' => 'Approvals queue', 'icon' => '✅', 'href' => 'approvals.php'];
    $menu[] = ['label' => 'Audit log', 'icon' => '📖', 'href' => 'audit_log.php'];
    $menu[] = ['label' => 'Manage users', 'icon' => '👥', 'href' => 'manage_users.php'];
    $menu[] = ['label' => 'Export data', 'icon' => '⬇️', 'href' => 'export.php'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Syspoint CRM</title>
<link rel="icon" type="image/png" href="Syspoint image circle.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --black: #080808;
        --red: #d71920;
        --red-dark: #b51218;
        --red-light: #fff0f1;
        --ink: #171717;
        --muted: #707070;
        --line: #e4e4e4;
        --canvas: #f7f7f7;
        --white: #ffffff;
    }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: linear-gradient(135deg, #f7f7f7 0%, #f1f3f4 100%); font-family: "DM Sans", sans-serif; }
    button, input, select, textarea { font: inherit; }
    button { cursor: pointer; }
    a { text-decoration: none; }

    .shell { display: grid; grid-template-columns: 248px minmax(0, 1fr); min-height: 100vh; }

    /* Sidebar */
    .sidebar { display: flex; flex-direction: column; padding: 28px 18px; color: var(--white); background: var(--black); }
    .brand { display: flex; align-items: center; gap: 10px; margin: 0 14px 40px; color: var(--white); font-family: "Space Grotesk", sans-serif; font-size: 1.16rem; font-weight: 700; }
    .nav-label { margin: 0 14px 12px; color: #878787; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; }
    .nav { display: grid; gap: 5px; }
    .nav-link, .nav-disabled {
        display: flex; align-items: center; gap: 13px; width: 100%; border: 0; border-radius: 6px;
        padding: 12px 14px; color: #a8a8a8; background: transparent; text-align: left; font-size: 0.88rem; font-weight: 600;
    }
    .nav-link:hover, .nav-link.active { color: var(--white); background: #252525; }
    .nav-link.active { box-shadow: inset 3px 0 0 var(--red); }
    .nav-disabled { color: #5a5a5a; cursor: default; }
    .nav-disabled .tag { margin-left: auto; font-size: 0.62rem; color: #6a6a6a; }
    .nav-icon { width: 18px; text-align: center; font-size: 1.02rem; }
    .sidebar-footer { margin-top: auto; border-top: 1px solid #282828; padding: 22px 14px 0; color: #888888; font-size: 0.78rem; line-height: 1.6; }
    .sidebar-footer strong { display: block; color: var(--white); font-size: 0.84rem; text-transform: capitalize; }

    /* Topbar */
    .main { min-width: 0; }
    .topbar { position: sticky; top: 0; z-index: 3; display: flex; align-items: center; justify-content: space-between; gap: 20px; height: 76px; padding: 0 clamp(22px, 4vw, 54px); border-bottom: 1px solid var(--line); background: rgba(255, 255, 255, 0.94); box-shadow: 0 4px 18px rgba(8, 8, 8, 0.04); backdrop-filter: blur(10px); }
    .crumb { color: var(--muted); font-size: 0.82rem; }
    .crumb strong { color: var(--ink); }
    .profile { display: flex; align-items: center; gap: 14px; }
    .profile-copy { text-align: right; font-size: 0.78rem; line-height: 1.35; }
    .profile-copy strong { display: block; font-size: 0.84rem; }
    .avatar { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; color: var(--white); background: var(--red); font-size: 0.76rem; font-weight: 700; flex-shrink: 0; }

    .notification-wrap { position: relative; }
    .notification-button { display: grid; place-items: center; width: 34px; height: 34px; border: 1px solid var(--line); border-radius: 50%; color: var(--ink); background: var(--white); font-size: 0.95rem; }
    .notification-button:hover { border-color: var(--red); color: var(--red); }
    .notification-badge { position: absolute; top: -5px; right: -4px; display: grid; place-items: center; min-width: 16px; height: 16px; border: 2px solid var(--white); border-radius: 50%; color: var(--white); background: var(--red); font-size: 0.58rem; font-weight: 700; }
    .notification-panel { position: absolute; top: 44px; right: 0; z-index: 4; width: 240px; border: 1px solid var(--line); border-radius: 6px; padding: 15px; background: var(--white); box-shadow: 0 10px 28px rgba(8, 8, 8, 0.13); }
    .notification-panel[hidden] { display: none; }
    .notification-panel strong { display: block; font-size: 0.82rem; }
    .notification-panel p { margin: 8px 0 13px; color: var(--muted); font-size: 0.75rem; line-height: 1.5; }
    .notification-panel a { color: var(--red); font-size: 0.75rem; font-weight: 700; }
    .logout-link { border: 0; color: var(--red); background: transparent; font-size: 0.78rem; font-weight: 700; }

    .content { max-width: 1440px; margin: 0 auto; padding: 32px clamp(22px, 4vw, 54px) 60px; }
    .heading { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 26px; flex-wrap: wrap; }
    h1, h2 { margin: 0; font-family: "Space Grotesk", sans-serif; }
    h1 { font-size: clamp(1.6rem, 3vw, 2.2rem); }
    .heading p { margin: 8px 0 0; color: var(--muted); font-size: 0.88rem; }

    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid transparent; border-radius: 5px; padding: 10px 15px; color: var(--white); background: var(--red); font-size: 0.82rem; font-weight: 700; transition: background 150ms ease, transform 150ms ease; }
    .button:hover { background: var(--red-dark); transform: translateY(-1px); }

    /* Stats */
    .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 15px; margin-bottom: 30px; }
    .stat { display: flex; flex-direction: column; justify-content: space-between; }
    .stat-btn { font: inherit; text-align: left; cursor: pointer; width: 100%; color: inherit; }
    .stat-btn:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
    .stat-btn[aria-pressed="true"] { border-color: var(--black); box-shadow: 0 0 0 1px var(--black), 0 9px 22px rgba(8, 8, 8, 0.08); }
    .stats + .filter-note, .filter-note { margin: -14px 0 22px; color: var(--muted); font-size: 0.82rem; }
    .link-button { border: 0; padding: 0; background: none; color: var(--red); font: inherit; font-weight: 700; cursor: pointer; }
    .sales-picker { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .sales-picker label { color: var(--muted); font-size: 0.78rem; font-weight: 700; }
    .sales-picker select { min-width: 220px; border: 1px solid var(--line); border-radius: 5px; padding: 10px 12px; font: inherit; font-size: 0.84rem; background: var(--white); }
    .sales-clear { color: var(--red); font-size: 0.8rem; font-weight: 700; }
    .week-card { margin-bottom: 24px; }
    .week-nav { flex-wrap: wrap; }
    .button.ghost { color: var(--ink); background: var(--white); border-color: var(--line); }
    .button.ghost:hover { background: #f1f1f1; box-shadow: none; }
    .week-people { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px 20px 14px; }
    .week-chip { border: 1px solid var(--line); border-radius: 999px; padding: 5px 11px; background: #fbfbfb; font-size: 0.78rem; }
    .week-empty { margin: 0; padding: 4px 20px 22px; color: var(--muted); font-size: 0.85rem; }
    .stat { min-height: 113px; border: 1px solid var(--line); border-radius: 6px; padding: 19px 20px; background: var(--white); box-shadow: 0 5px 18px rgba(8, 8, 8, 0.035); transition: border-color 150ms ease, transform 150ms ease, box-shadow 150ms ease; }
    .stat:hover { border-color: #cfcfcf; transform: translateY(-2px); box-shadow: 0 9px 22px rgba(8, 8, 8, 0.08); }
    .stat.accent { border-top: 3px solid var(--red); padding-top: 17px; }
    .stat-label { color: var(--muted); font-size: 0.75rem; font-weight: 700; }
    .stat strong { display: block; margin-top: 12px; color: var(--black); font-family: "Space Grotesk", sans-serif; font-size: 1.9rem; }

    /* Table */
    .table-card { border: 1px solid var(--line); border-radius: 6px; background: var(--white); overflow: hidden; box-shadow: 0 8px 24px rgba(8, 8, 8, 0.045); }
    .table-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 17px 20px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
    .table-toolbar h2 { font-size: 1.05rem; }
    .table-toolbar p { margin: 4px 0 0; color: var(--muted); font-size: 0.76rem; }
    .toolbar-actions { display: flex; gap: 9px; }
    .search { border: 1px solid var(--line); border-radius: 5px; color: var(--ink); background: var(--white); outline: 0; width: 200px; padding: 9px 12px; font-size: 0.8rem; }
    .search:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.12); }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; min-width: 640px; border-collapse: collapse; }
    th { padding: 12px 20px; color: #858585; background: #fbfbfb; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.06em; text-align: left; text-transform: uppercase; }
    .sort-btn { display: inline-flex; align-items: center; gap: 5px; border: 0; background: none; padding: 0; color: inherit; font: inherit; text-transform: inherit; letter-spacing: inherit; cursor: pointer; }
    .sort-btn:hover { color: var(--ink); }
    .sort-btn .sort-arrow { font-size: 0.7rem; }
    tbody tr { transition: background 150ms ease; }
    td { padding: 15px 20px; border-top: 1px solid #eeeeee; color: #4f4f4f; font-size: 0.82rem; }
    td:first-child { color: var(--ink); font-weight: 700; }
    .empty-row { padding: 40px 20px; color: var(--muted); text-align: center; }

    .mgr { display: inline-flex; align-items: center; gap: 8px; }
    .mgr-av { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; color: #fff; font-size: 0.64rem; font-weight: 700; letter-spacing: .2px; flex-shrink: 0; }
    .mgr-av.sm { width: 22px; height: 22px; font-size: 0.58rem; margin-right: 8px; vertical-align: middle; }
    .mgr-av.none { background: #b5b5b5; }
    .mgr-av.sm + .client-name { display: inline; vertical-align: middle; }
    .state-check { display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; white-space: nowrap; cursor: pointer; }
    .inactive-tag { display: inline-block; margin-left: 8px; padding: 1px 8px; border-radius: 10px; background: #eee; color: #666; font-size: 0.7rem; font-weight: 700; }
    .add-branch-row { margin: 10px 0 0; }
    .client-row { cursor: pointer; }
    .client-row:hover { background: #fff8f8; }
    .client-row:focus { outline: 2px solid var(--red); outline-offset: -2px; }
    .client-name { display: block; }
    .client-hint { display: block; margin-top: 3px; color: var(--muted); font-size: 0.68rem; font-weight: 400; opacity: 0; transition: opacity 150ms ease; }
    .client-row:hover .client-hint, .client-row:focus .client-hint { opacity: 1; }

    .status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; font-weight: 700; }
    .status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
    .status.active { color: #1a7a30; }
    .status.warning { color: #93650b; }
    .status.danger { color: #a32b26; }
    .status.neutral { color: #707070; }
    .action-link { position: relative; z-index: 1; border: 0; color: var(--red); background: transparent; font-size: 0.78rem; font-weight: 700; }

    .flash-message { margin: 0 0 20px; border: 1px solid var(--line); border-radius: 6px; padding: 12px 16px; color: var(--ink); background: #eef6f3; font-size: 0.85rem; }

    .empty-state { border: 1px dashed #cfcfcf; padding: 54px 20px; color: var(--muted); background: var(--white); text-align: center; border-radius: 6px; }
    .empty-state strong { display: block; margin-bottom: 7px; color: var(--ink); font-family: "Space Grotesk", sans-serif; font-size: 1.05rem; }
    .empty-state a { color: var(--red); font-weight: 700; }

    /* Client details modal */
    .modal-backdrop { position: fixed; inset: 0; z-index: 5; display: grid; place-items: center; padding: 20px; background: rgba(0, 0, 0, 0.62); }
    .modal-backdrop[hidden] { display: none; }
    .modal { width: min(100%, 560px); max-height: 88vh; overflow-y: auto; -webkit-overflow-scrolling: touch; border-radius: 7px; padding: 28px; background: var(--white); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25); }
    .modal-head { display: flex; align-items: start; justify-content: space-between; gap: 15px; margin-bottom: 20px; }
    .modal h2 { font-size: 1.3rem; }
    .modal-kicker { margin: 0 0 6px; color: var(--red); font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
    .close-button { border: 0; color: var(--muted); background: transparent; font-size: 1.4rem; line-height: 1; }
    .client-details > p { margin: 0 0 20px; color: var(--muted); font-size: 0.85rem; line-height: 1.6; }
    .client-details dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0 0 22px; }
    .client-details dl div { padding: 12px 14px; border: 1px solid var(--line); border-radius: 5px; background: #fbfbfb; }
    .client-details dt { color: var(--muted); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
    .client-details dd { margin: 5px 0 0; color: var(--ink); font-size: 0.84rem; font-weight: 600; }

    .order-update-form { margin: 0 0 22px; border: 1px solid var(--line); border-radius: 6px; padding: 14px; background: #fbfbfb; }
    .order-update-form label { display: block; margin-bottom: 8px; color: var(--muted); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .order-update-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .order-update-row input[type=date] { flex: 1; min-width: 140px; border: 1px solid var(--line); border-radius: 5px; padding: 8px 10px; font-size: 0.82rem; }
    .order-update-row select { flex: 1; min-width: 140px; max-width: 100%; border: 1px solid var(--line); border-radius: 5px; padding: 8px 10px; font-size: 0.82rem; background: var(--white); color: inherit; font-family: inherit; }
    .order-update-row button { border: 1px solid transparent; border-radius: 5px; padding: 9px 14px; color: var(--white); background: var(--red); font-size: 0.8rem; font-weight: 700; white-space: nowrap; cursor: pointer; }
    .order-update-row button:hover { background: var(--red-dark); }
    .quiet-note { display: block; margin-top: 3px; color: #707070; font-size: 0.68rem; font-weight: 600; }
    .engage-form { margin: 0 0 22px; border: 1px solid var(--line); border-radius: 6px; padding: 14px; background: #fbfbfb; }
    .engage-form label { display: block; margin-bottom: 8px; color: var(--muted); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .engage-form textarea { width: 100%; margin-top: 8px; border: 1px solid var(--line); border-radius: 5px; padding: 8px 10px; font-size: 0.82rem; font-family: inherit; min-height: 54px; resize: vertical; }
    .engage-form .order-update-row input[type=date] { flex: 0 1 150px; }
    .engage-form .order-update-row input[type=time] { flex: 0 1 120px; min-width: 110px; border: 1px solid var(--line); border-radius: 5px; padding: 8px 10px; font-size: 0.82rem; font-family: inherit; }
    .engage-list { list-style: none; margin: 6px 0 18px; padding: 0; }
    .engage-list li { padding: 8px 0; border-top: 1px solid #eee; font-size: 0.82rem; }
    .engage-list li:first-child { border-top: 0; }
    .engage-list .meta { display: block; color: var(--muted); font-size: 0.72rem; margin-top: 2px; }
    .hint { margin: 8px 0 0; color: var(--muted); font-size: 0.76rem; font-style: italic; }
    .contact-list { display: grid; gap: 9px; margin: 0; padding: 0; list-style: none; }
    .contact-list li { border: 1px solid var(--line); border-radius: 5px; padding: 11px 13px; font-size: 0.8rem; }
    .contact-list .contact-name { font-weight: 700; }
    .contact-list .contact-meta { display: block; margin-top: 3px; color: var(--muted); font-size: 0.75rem; }
    .no-contacts { color: var(--muted); font-size: 0.82rem; }
<?= INFO_TIP_CSS ?>
    .cat-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 18px; }
    .cat-chip { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 0.74rem; font-weight: 700; color: #444; background: #eef0f2; }
    .cat-chip.none { color: #93650b; background: #fdf1d6; }
    .cat-cell { display: flex; flex-wrap: wrap; gap: 4px; }
    .cat-cell .cat-chip { font-size: 0.68rem; padding: 2px 8px; }
    .category-filter { border: 1px solid var(--line); border-radius: 5px; color: var(--ink); background: var(--white); padding: 9px 10px; font-size: 0.8rem; font-family: inherit; max-width: 210px; }
    .contact-list li { display: block; }
    .contact-list .contact-name { display: block; font-weight: 700; margin-bottom: 4px; }
    .rank-chip { display: inline-block; margin-left: 8px; padding: 1px 8px; border-radius: 9px; font-size: 0.66rem; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; color: #444; background: #eef0f2; vertical-align: middle; }
    .rank-chip.primary { color: #fff; background: #d71920; }
    .contact-move { display: inline-flex; gap: 2px; margin-left: 8px; vertical-align: middle; }
    .contact-move button { margin: 0; padding: 0 6px; min-width: 0; border: 1px solid #d6d6d6; border-radius: 4px; background: #fff; color: #444; font-size: 0.7rem; line-height: 18px; cursor: pointer; }
    .contact-move button:hover { background: #f2f2f2; }
    .contact-list .contact-row { display: flex; gap: 10px; margin-top: 4px; font-size: 0.78rem; }
    .contact-list .contact-label { flex: 0 0 62px; color: var(--muted); font-size: 0.66rem; font-weight: 700; text-transform: uppercase; padding-top: 2px; }
    .contact-list .contact-value { min-width: 0; overflow-wrap: anywhere; }
    .contact-list .contact-value span { display: block; }

    .hidden-contacts-note { margin: 10px 0 0; color: var(--muted); font-size: 0.76rem; font-style: italic; }

    @media (max-width: 900px) {
        .shell { grid-template-columns: 205px minmax(0, 1fr); }
        .stats { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 650px) {
        .shell { display: block; }
        .sidebar { padding: 15px 16px; }
        .nav-label, .sidebar-footer { display: none; }
        .nav { display: flex; gap: 5px; overflow-x: auto; }
        .nav-link, .nav-disabled { width: auto; white-space: nowrap; }
        .topbar { height: 62px; padding: 0 18px; }
        .profile-copy { display: none; }
        .content { padding: 24px 16px 40px; }
        .heading { flex-direction: column; align-items: start; }
        .stats { gap: 10px; grid-template-columns: repeat(2, 1fr); }
        .table-toolbar { flex-direction: column; align-items: stretch; }
        .search { width: 100%; }
        .client-details dl { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<div class="shell">

    <aside class="sidebar">
        <div class="brand">Syspoint inc.</div>
        <p class="nav-label">Workspace</p>
        <nav class="nav" aria-label="Main navigation">
            <?php foreach ($menu as $item): ?>
                <?php if ($item['href']): ?>
                    <a class="nav-link<?= $item['href'] === 'dashboard.php' ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                        <span class="nav-icon"><?= $item['icon'] ?></span><?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php else: ?>
                    <div class="nav-disabled">
                        <span class="nav-icon"><?= $item['icon'] ?></span><?= htmlspecialchars($item['label']) ?>
                        <span class="tag">soon</span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer"><strong><?= htmlspecialchars(str_replace('_', ' ', $role)) ?></strong>Manage your clients.</div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="crumb">Workspace / <strong>Clients</strong></div>
            <div class="profile">
                 <div class="profile-copy">
                    <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong>
                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?>
                </div>
                <div class="notification-wrap">
                    <button class="notification-button" id="notification-button" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                        🔔
                        <?php if ($unreadCount > 0): ?><span class="notification-badge" id="notification-badge"><?= $unreadCount ?></span><?php endif; ?>
                    </button>
                    <div class="notification-panel" id="notification-panel" hidden>
                        <strong><?= $unreadCount > 0 ? $unreadCount . ' unread notification' . ($unreadCount === 1 ? '' : 's') : 'No new notifications' ?></strong>
                        <p><?= $unreadCount > 0 ? 'Order-tracking alerts and requests waiting for your review.' : "You're all caught up." ?></p>
                        <a href="notifications.php">View all →</a>
                    </div>
                </div>

                <a class="logout-link" href="logout.php">Log out</a>
            </div>
        </header>

        <div class="content">
            <?php if ($flashMessage): ?><p class="flash-message"><?= htmlspecialchars($flashMessage) ?></p><?php endif; ?>
            <div class="heading">
                <div>
                    <h1><?= $role === 'user' ? 'Clients' : ($adminSeesNoList ? 'Overview' : ($salesFilter ? htmlspecialchars($salesFilter['full_name']) . "'s Clients" : 'All Clients')) ?></h1>
                    <p><?= $role === 'user'
                        ? 'Companies and branches currently assigned to you. Click a row to view details.'
                        : ($adminSeesNoList ? 'Client totals across the organization. Click a card to list just those clients.' : ($salesFilter
                            ? 'Every active company and branch held by ' . htmlspecialchars($salesFilter['full_name']) . '. Click a card to list just those clients.'
                            : 'Every active company and branch across the organization. Click a card to list just those clients, or a row to view details.')) ?></p>
                </div>
                <?php if ($role === 'super_admin'): ?>
                    <form method="GET" action="dashboard.php" class="sales-picker">
                        <label for="sales-select">Salesperson</label>
                        <select id="sales-select" name="sales" onchange="this.form.submit()">
                            <option value="">All salespeople</option>
                            <?php foreach ($salesOptions as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>"<?= $salesFilter && (int) $salesFilter['id'] === (int) $opt['id'] ? ' selected' : '' ?>><?= htmlspecialchars($opt['full_name']) ?> (<?= (int) $opt['n'] ?>)<?= $opt['status'] === 'inactive' ? ' — inactive' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($salesFilter): ?><a class="sales-clear" href="<?= htmlspecialchars($dashUrl([])) ?>">Show everyone</a><?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php
                $statCards = [
                    ['bucket' => 'active',  'label' => 'Active, in contact',        'tip' => 'Ordered or was engaged within 3 months, and in contact within the last 30 days.'],
                    ['bucket' => 'quiet',   'label' => 'Active, no contact 30+ days', 'tip' => 'Still within 3 months, but no order or engagement for 30 days or more.'],
                    ['bucket' => 'warning', 'label' => '3-month warning',           'tip' => 'No order or engagement for 3 months or more.'],
                    ['bucket' => 'neutral', 'label' => 'No orders yet',             'tip' => 'Added, but no order date recorded.'],
                    ['bucket' => 'danger',  'label' => 'Reassignment flag',         'tip' => 'No order or engagement for 6 months or more.'],
                ];
                $clickableStats = !empty($branches);
            ?>
            <div class="stats">
                <?php if ($clickableStats): ?>
                    <button type="button" class="stat accent stat-btn" data-bucket="" aria-pressed="true"><span class="stat-label">Total clients</span><strong><?= $totalClients ?></strong></button>
                    <?php foreach ($statCards as $sc): ?>
                        <button type="button" class="stat stat-btn" data-bucket="<?= $sc['bucket'] ?>" aria-pressed="false" title="<?= htmlspecialchars($sc['tip']) ?>"><span class="stat-label"><?= htmlspecialchars($sc['label']) ?></span><strong><?= $statusCounts[$sc['bucket']] ?></strong></button>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stat accent"><span class="stat-label">Total clients</span><strong><?= $totalClients ?></strong></div>
                    <?php foreach ($statCards as $sc): ?>
                        <div class="stat" title="<?= htmlspecialchars($sc['tip']) ?>"><span class="stat-label"><?= htmlspecialchars($sc['label']) ?></span><strong><?= $statusCounts[$sc['bucket']] ?></strong></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($clickableStats): ?><p class="filter-note" id="filter-note" hidden></p><?php endif; ?>

            <?php if ($weekAdds !== null): ?>
                <?php
                    $weekLabel = $weekAdds['start']->format('M j') . ' – ' . $weekAdds['end']->format('M j, Y');
                    $addedCount = count($weekAdds['rows']);
                ?>
                <div class="table-card week-card">
                    <div class="table-toolbar">
                        <div>
                            <h2>Recently added clients<?= $salesFilter ? ' — ' . htmlspecialchars($salesFilter['full_name']) : '' ?></h2>
                            <p><?= $weekAdds['isCurrent'] ? 'This week:' : 'Week of' ?> <?= htmlspecialchars($weekLabel) ?> (Mon–Sun). <strong><?= $addedCount ?></strong> added.</p>
                        </div>
                        <div class="toolbar-actions week-nav">
                            <a class="button ghost" href="<?= htmlspecialchars($dashUrl(['sales' => $salesFilter['id'] ?? null, 'week' => $weekAdds['prev']])) ?>">&larr; Previous week</a>
                            <?php if ($weekAdds['next'] !== null): ?>
                                <a class="button ghost" href="<?= htmlspecialchars($dashUrl(['sales' => $salesFilter['id'] ?? null, 'week' => $weekAdds['next']])) ?>">Next week &rarr;</a>
                            <?php endif; ?>
                            <?php if (!$weekAdds['isCurrent']): ?>
                                <a class="button ghost" href="<?= htmlspecialchars($dashUrl(['sales' => $salesFilter['id'] ?? null])) ?>">This week</a>
                            <?php endif; ?>
                            <?php if ($clickableStats && $addedCount > 0): ?>
                                <button type="button" class="button" id="show-added-btn">Show in list</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($addedCount === 0): ?>
                        <p class="week-empty">No clients were added that week.</p>
                    <?php else: ?>
                        <div class="week-people">
                            <?php foreach ($weekAdds['perAdder'] as $who => $n): ?>
                                <span class="week-chip"><?= htmlspecialchars($who) ?> <strong><?= (int) $n ?></strong></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Company</th><th>Branch</th><th>Added on</th><th>Added by</th><th>Account manager</th></tr></thead>
                                <tbody>
                                    <?php foreach ($weekAdds['rows'] as $r): ?>
                                        <tr>
                                            <td><span class="client-name"><?= htmlspecialchars($r['company_name']) ?></span></td>
                                            <td><?= htmlspecialchars($r['branch_name']) ?></td>
                                            <td><?= htmlspecialchars(toLocalTime($r['created_at'], 'D, M j · g:i A')) ?></td>
                                            <td><?= htmlspecialchars($r['adder_name'] ?: ($r['owner_name'] ?: '—')) ?></td>
                                            <td><?= htmlspecialchars($r['owner_name'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($branches)): ?>
                <div class="empty-state">
                    <?php if ($role === 'user'): ?>
                        <strong>No clients assigned to you yet</strong>
                        Search for the company in <a href="all_clients.php">All clients</a> first. If it isn't there, you can add it from the search result — it'll show up here.
                    <?php else: ?>
                        <strong><?= $salesFilter ? htmlspecialchars($salesFilter['full_name']) . ' has no active clients' : 'No active clients in the system yet' ?></strong>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <div class="table-toolbar">
                        <div>
                            <h2>Company list</h2>
                            <p><?= $adminSeesNoList ? 'Every company and branch. Active and Inactive can\'t be ticked together; Reassignment flag narrows either one.' : 'View and manage your assigned company clients.' ?></p>
                        </div>
                        <div class="toolbar-actions">
                            <?php if ($role === 'super_admin'): ?><a class="button" href="engagements.php" style="white-space:nowrap;">Engagements</a><?php endif; ?>
                            <?php if ($adminSeesNoList): ?>
                                <label class="state-check"><input type="checkbox" id="f-active" checked> Active</label>
                                <label class="state-check"><input type="checkbox" id="f-inactive"> Inactive</label>
                                <label class="state-check"><input type="checkbox" id="f-reassign"> Reassignment flag</label>
                            <?php endif; ?>
                            <select class="category-filter" id="category-filter" aria-label="Filter by category">
                                <option value="">All categories</option>
                                <?php foreach (COMPANY_CATEGORIES as $ckey => $clabel): ?>
                                    <option value="<?= htmlspecialchars($ckey) ?>"><?= htmlspecialchars($clabel) ?></option>
                                <?php endforeach; ?>
                                <option value="_none">Uncategorized</option>
                            </select>
                            <input class="search" id="search" type="search" placeholder="Search companies or categories..." aria-label="Search companies">
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th><button class="sort-btn" data-sort="name" data-type="text">Company name <span class="sort-arrow"></span></button></th>
                                    <th>Branch</th>
                                    <?php if ($adminSeesNoList): ?><th>Address</th><?php endif; ?>
                                    <th>Category</th>
                                    <?php if ($adminSeesNoList): ?>
                                        <th><button class="sort-btn" data-sort="order" data-type="date">Last order <span class="sort-arrow"></span></button></th>
                                        <th>Assigned to</th>
                                    <?php else: ?>
                                    <?php if ($role !== 'user'): ?><th>Account Manager</th><?php endif; ?>
                                    <th><button class="sort-btn" data-sort="order" data-type="date">Last order <span class="sort-arrow"></span></button></th>
                                    <th><button class="sort-btn" data-sort="status" data-type="rank">Status <span class="sort-arrow"></span></button></th>
                                    <th><button class="sort-btn" data-sort="modified" data-type="date">Modified <span class="sort-arrow"></span></button></th>
                                    <?php endif; ?>
                                    <?php if ($role === 'user'): ?><th>Action</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="client-rows">
                                <?php foreach ($branches as $branch): ?>
                                    <?php
                                        $status = orderStatus($branch['last_order_date'], $branch['last_engagement_date']);
                                        $statusRank = ['active' => 0, 'warning' => 1, 'danger' => 2, 'neutral' => 3][$status['class']];
                                        $rowBucket = $bucketOf($status);
                                    ?>
                                    <tr class="<?= $adminSeesNoList ? 'admin-row' : 'client-row' ?>"<?= $adminSeesNoList ? '' : ' tabindex="0"' ?> data-id="<?= (int) $branch['id'] ?>" data-state="<?= htmlspecialchars($branch['branch_status'] ?? 'active') ?>"
                                        data-name="<?= htmlspecialchars(strtolower($branch['company_name'] . ' ' . $branch['branch_name'])) ?>"
                                        data-order="<?= htmlspecialchars($branch['last_order_date'] ?? '') ?>"
                                        data-status="<?= $statusRank ?>"
                                        data-bucket="<?= $rowBucket ?>"
                                        data-added="<?= isset($addedThisWeekIds[(int) $branch['id']]) ? '1' : '0' ?>"
                                        data-cats="|<?= htmlspecialchars(implode('|', $categoryMap[(int) $branch['company_id']] ?? ['_none'])) ?>|"
                                        data-modified="<?= htmlspecialchars($branch['last_modified'] ?? '') ?>">
                                        <td>
                                            <?php if ($adminSeesNoList): ?><?= $managerBadge($branch['owner_name'] ?? null, isset($branch['owner_id']) ? (int) $branch['owner_id'] : null, true) ?><?php endif; ?>
                                            <span class="client-name"><?= htmlspecialchars($branch['company_name']) ?></span>
                                            <?php if ($adminSeesNoList): ?><?= ($branch['branch_status'] ?? 'active') === 'inactive' ? '<span class="inactive-tag">Inactive</span>' : '' ?><?php else: ?><span class="client-hint">View details</span><?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($branch['branch_name']) ?></td>
                                        <?php if ($adminSeesNoList): ?><td><?= htmlspecialchars($branch['address'] ?: '—') ?></td><?php endif; ?>
                                        <td><div class="cat-cell"><?php $rowCats = categoryLabels($categoryMap[(int) $branch['company_id']] ?? []); if ($rowCats): foreach ($rowCats as $rl): ?><span class="cat-chip"><?= htmlspecialchars($rl) ?></span><?php endforeach; else: ?><span class="cat-chip none">Uncategorized</span><?php endif; ?></div></td>
                                        <?php if ($adminSeesNoList): ?>
                                        <td><?= $branch['last_order_date'] ? htmlspecialchars($branch['last_order_date']) : '—' ?></td>
                                        <td><?= $managerBadge($branch['owner_name'] ?? null, isset($branch['owner_id']) ? (int) $branch['owner_id'] : null) ?></td>
                                        <?php else: ?>
                                        <?php if ($role === 'super_admin'): ?><td><?= $managerBadge($branch['owner_name'] ?? null, isset($branch['owner_id']) ? (int) $branch['owner_id'] : null) ?></td><?php elseif ($role !== 'user'): ?><td><?= htmlspecialchars($branch['owner_name'] ?? '—') ?></td><?php endif; ?>
                                        <td><?= $branch['last_order_date'] ? htmlspecialchars($branch['last_order_date']) : '—' ?></td>
                                        <td><span class="status <?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span><?php if ($status['quiet']): ?><span class="quiet-note">No contact in 30+ days</span><?php endif; ?></td>
                                        <td><?= $branch['last_modified'] ? htmlspecialchars(toLocalTime($branch['last_modified'])) : '—' ?></td>
                                        <?php endif; ?>
                                        <?php if ($role === 'user'): ?><td><a class="action-link" href="modification_request.php?branch_id=<?= (int) $branch['id'] ?>">Request change</a></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($adminSeesNoList): ?>
                <div class="table-card" style="padding:22px;">
                    <h2 style="margin:0 0 10px; display:flex; align-items:center;">Manage companies<?= infoTip("Rename a company, change its categories, or add a new company or branch and assign it to a salesperson. To reassign a salesperson's clients, open Manage users in the sidebar.") ?></h2>
                    <iframe id="manage-companies-frame" src="manage_companies.php?embed=1" title="Manage companies" style="width:100%; height:420px; border:0; display:block;"></iframe>
                </div>
                <script>
                    window.addEventListener('message', function (e) {
                        if (e.origin !== location.origin || !e.data || e.data.type !== 'mc-height') { return; }
                        document.getElementById('manage-companies-frame').style.height = Math.max(200, e.data.h) + 'px';
                    });
                </script>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal-backdrop" id="client-details-modal" hidden>
    <div class="modal details-modal" role="dialog" aria-modal="true" aria-labelledby="client-details-title">
        <div class="modal-head">
            <div>
                <p class="modal-kicker">Client profile</p>
                <h2 id="client-details-title"></h2>
            </div>
            <button class="close-button" type="button" id="close-details" aria-label="Close">×</button>
        </div>
        <div class="client-details">
            <div class="cat-chips" id="client-details-categories"></div>
            <dl>
                <div><dt>Branch</dt><dd id="client-details-branch"></dd></div>
                <div><dt>Address</dt><dd id="client-details-address"></dd></div>
                <div id="owner-row" hidden><dt>Account manager</dt><dd id="client-details-owner"></dd></div>
                <div><dt>Last order</dt><dd id="client-details-order"></dd></div>
                <div><dt>Status</dt><dd id="client-details-status"></dd></div>
                <div><dt>Last engagement</dt><dd id="client-details-engagement"></dd></div>
            </dl>

            <?php if ($role === 'user'): ?>
            <form method="POST" action="dashboard.php" class="order-update-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_order_date">
                <input type="hidden" name="branch_id" id="order-update-branch-id" value="">
                <label for="order-update-date">Update last order date</label>
                <div class="order-update-row">
                    <input type="date" name="order_date" id="order-update-date" required>
                    <button type="submit">Save</button>
                    <a class="button" id="modal-request-change" href="#">Request a change</a>
                </div>
            </form>

            <p class="add-branch-row"><a class="button ghost" id="modal-add-branch" href="#">+ Add another branch of this company</a></p>

            <form method="POST" action="dashboard.php" class="engage-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="log_engagement">
                <input type="hidden" name="branch_id" id="engage-branch-id" value="">
                <label for="engage-type">Log an engagement — still in touch, no order yet</label>
                <div class="order-update-row">
                    <select name="engagement_type" id="engage-type" required>
                        <option value="">Type…</option>
                        <?php foreach (ENGAGEMENT_TYPES as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="engaged_on" id="engage-date" required>
                    <input type="time" name="engaged_time" id="engage-time" aria-label="Time of engagement (optional)" title="Time of engagement (optional)">
                    <button type="submit">Log it</button>
                </div>
                <textarea name="engagement_note" maxlength="500" placeholder="Remark (optional) — who you spoke to, what was discussed…"></textarea>
                <p class="hint" id="engage-saved" hidden>Logged. You can add another below, or close this window.</p>
                <p class="hint">Date and time default to now. Change them if you're entering this later, or clear the time to log the date only.</p>
            </form>
            <?php endif; ?>

            <?php if ($role === 'admin' || $role === 'super_admin'): ?>
            <form method="POST" action="manage_companies.php" class="order-update-row" id="reassign-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="form_action" value="reassign">
                <input type="hidden" name="branch_id" id="reassign-branch-id" value="">
                <select name="assignee_user_id" id="reassign-assignee" required>
                    <option value="">Reassign whole company to…</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" data-emp-id="<?= (int) $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"><?= $role === 'super_admin' ? 'Reassign company' : 'Request transfer' ?></button>
                <span class="hint" style="flex-basis:100%;margin:0;">Moves the company and all of its branches to one salesperson.</span>
            </form>
            <p class="hint" id="reassign-note" hidden>Currently held by an admin or super admin — can't be reassigned here.</p>
            <?php endif; ?>

            <p class="modal-kicker">Recent engagements</p>
            <ul class="engage-list" id="client-details-engagements"></ul>

            <p class="modal-kicker">Contact persons</p>
            <ul class="contact-list" id="client-details-contacts"></ul>
            <p class="hidden-contacts-note" id="hidden-contacts-note" hidden></p>
        </div>
    </div>
</div>

<script src="suggest.js"></script>
<script>
    // Real per-branch data rendered server-side, keyed by branch id — used only
    // to populate the details popup on click, not to drive the table itself.
    const branchDetails = <?= json_encode($branchDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const escHtml = (t) => String(t).replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const rankLabel = (n) => n === 1 ? 'Primary' : 'Secondary';
    // One contact in the popup: name, its rank (Primary, or Secondary for everyone after) and, for the salesperson who owns
    // the client, up/down buttons that reorder the list. `movable` is false everywhere else.
    const contactHtml = (c, i, total, branchId, movable) => `<li>
        <span class="contact-name">${escHtml(c.name)}<span class="rank-chip${i === 0 ? ' primary' : ''}">${rankLabel(i + 1)}</span>${movable && total > 1 ? `<form method="POST" action="dashboard.php" class="contact-move"><input type="hidden" name="csrf_token" value="${escHtml(typeof csrfToken !== 'undefined' ? csrfToken : '')}"><input type="hidden" name="action" value="move_contact"><input type="hidden" name="branch_id" value="${escHtml(branchId)}"><input type="hidden" name="contact_id" value="${escHtml(c.id)}">${i > 0 ? '<button type="submit" name="direction" value="up" title="Move up (more important)" aria-label="Move up">&#9650;</button>' : ''}${i < total - 1 ? '<button type="submit" name="direction" value="down" title="Move down" aria-label="Move down">&#9660;</button>' : ''}</form>` : ''}</span>
        <div class="contact-row"><span class="contact-label">Position</span><span class="contact-value">${c.position ? escHtml(c.position) : '—'}</span></div>
        <div class="contact-row"><span class="contact-label">Number</span><span class="contact-value">${(c.phones && c.phones.length) ? c.phones.map((p) => '<span>' + escHtml(p) + '</span>').join('') : '—'}</span></div>
        <div class="contact-row"><span class="contact-label">Email</span><span class="contact-value">${(c.emails && c.emails.length) ? c.emails.map((e) => '<span>' + escHtml(e) + '</span>').join('') : '—'}</span></div>
    </li>`;
    const renderCategories = (labels) => {
        const box = document.getElementById('client-details-categories');
        box.innerHTML = (labels && labels.length)
            ? labels.map((l) => '<span class="cat-chip">' + escHtml(l) + '</span>').join('')
            : '<span class="cat-chip none">Uncategorized</span>';
    };

    // Client-side search + category filter over the already-rendered, real table rows.
    const searchInput = document.getElementById('search');
    const categoryFilter = document.getElementById('category-filter');
    // Super admin's clickable stat cards (and "Show in list" on the weekly panel) add one more filter:
    // a status bucket, or "added in the week shown".
    let bucketFilter = '';
    let addedFilter = false;
    function applyRowFilters() {
        const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const cat = categoryFilter ? categoryFilter.value : '';
        let shown = 0;
        document.querySelectorAll('#client-rows tr').forEach((row) => {
            const textOk = row.textContent.toLowerCase().includes(term);
            const catOk = !cat || (row.dataset.cats || '').includes('|' + cat + '|');
            const bucketOk = !bucketFilter || row.dataset.bucket === bucketFilter;
            const addedOk = !addedFilter || row.dataset.added === '1';
            const fa = document.getElementById('f-active'), fi = document.getElementById('f-inactive'), fr = document.getElementById('f-reassign');
            let stateOk = true, reassignOk = true;
            if (fa && fi) {
                stateOk = (!fa.checked && !fi.checked) || (fa.checked && row.dataset.state === 'active') || (fi.checked && row.dataset.state === 'inactive');
            }
            if (fr && fr.checked) reassignOk = row.dataset.bucket === 'danger';
            const ok = textOk && catOk && bucketOk && addedOk && stateOk && reassignOk;
            row.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        const note = document.getElementById('filter-note');
        if (note) {
            const btn = bucketFilter ? document.querySelector('.stat-btn[data-bucket="' + bucketFilter + '"] .stat-label') : null;
            const what = addedFilter ? 'clients added in the week shown above' : (btn ? btn.textContent : '');
            note.hidden = !what;
            note.innerHTML = '';
            if (what) {
                note.appendChild(document.createTextNode('Showing ' + shown + ' \u2014 ' + what + '. '));
                const clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'link-button';
                clear.textContent = 'Show all clients';
                clear.addEventListener('click', () => setBucket('', false));
                note.appendChild(clear);
            }
        }
    }
    function setBucket(bucket, added) {
        bucketFilter = bucket;
        addedFilter = !!added;
        document.querySelectorAll('.stat-btn').forEach((b) => {
            b.setAttribute('aria-pressed', (!added && b.dataset.bucket === bucket) ? 'true' : 'false');
        });
        applyRowFilters();
    }
    document.querySelectorAll('.stat-btn').forEach((b) => {
        b.addEventListener('click', () => {
            const same = b.dataset.bucket !== '' && bucketFilter === b.dataset.bucket && !addedFilter;
            setBucket(same ? '' : b.dataset.bucket, false);
            const list = document.getElementById('client-rows');
            if (list && (bucketFilter || addedFilter)) list.closest('.table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    const showAdded = document.getElementById('show-added-btn');
    if (showAdded) showAdded.addEventListener('click', () => {
        setBucket('', true);
        document.getElementById('client-rows').closest('.table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    if (searchInput) searchInput.addEventListener('input', applyRowFilters);
    // Admin list: Active and Inactive exclude each other (ticking one unticks the other, both off = everything).
    (function () {
        const fa = document.getElementById('f-active'), fi = document.getElementById('f-inactive'), fr = document.getElementById('f-reassign');
        if (!fa) return;
        fa.addEventListener('change', () => { if (fa.checked) fi.checked = false; applyRowFilters(); });
        fi.addEventListener('change', () => { if (fi.checked) fa.checked = false; applyRowFilters(); });
        fr.addEventListener('change', applyRowFilters);
        applyRowFilters();
    })();
    // Company names as suggestions while typing; Enter takes the top one.
    if (searchInput && typeof attachSuggest === 'function') {
        attachSuggest(searchInput, function () { return Object.keys(branchDetails).map(function (k) { return branchDetails[k].company; }); });
    }
    if (categoryFilter) categoryFilter.addEventListener('change', applyRowFilters);

    // Sortable columns — click a header to sort, click again to reverse.
    let currentSort = { key: null, dir: 1 };
    document.querySelectorAll('.sort-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.sort;
            const type = btn.dataset.type;
            currentSort.dir = (currentSort.key === key) ? currentSort.dir * -1 : 1;
            currentSort.key = key;

            document.querySelectorAll('.sort-arrow').forEach((a) => a.textContent = '');
            btn.querySelector('.sort-arrow').textContent = currentSort.dir === 1 ? '\u25B2' : '\u25BC';

            const attr = { name: 'data-name', order: 'data-order', status: 'data-status', modified: 'data-modified' }[key];
            const tbody = document.getElementById('client-rows');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                let va = a.getAttribute(attr) || '';
                let vb = b.getAttribute(attr) || '';
                if (type === 'rank') { va = Number(va); vb = Number(vb); }
                if (type === 'date') {
                    if (va === '' && vb === '') return 0;
                    if (va === '') return 1;
                    if (vb === '') return -1;
                }
                if (va < vb) return -1 * currentSort.dir;
                if (va > vb) return 1 * currentSort.dir;
                return 0;
            });

            rows.forEach((row) => tbody.appendChild(row));
        });
    });

    // Row click / keyboard -> open the client-details modal, populated from
    // the real branchDetails map above. Clicking the "Request change" link
    // inside a row still navigates normally and does not open the modal.
    const detailsModal = document.getElementById('client-details-modal');
    function openClientDetails(id) {
        const info = branchDetails[id];
        if (!info) return;
        document.getElementById('client-details-title').textContent = info.company;
        renderCategories(info.categories);
        document.getElementById('client-details-branch').textContent = info.branch;
        document.getElementById('client-details-address').textContent = info.address;
        document.getElementById('client-details-order').textContent = info.lastOrder;
        document.getElementById('client-details-status').textContent = info.status + (info.quiet ? ' — no contact in 30+ days' : '');
        document.getElementById('client-details-engagement').textContent = info.lastEngagement || 'None logged';
        const engList = document.getElementById('client-details-engagements');
        const esc = escHtml;
        engList.innerHTML = info.engagements.length
            ? info.engagements.map((e) => `<li><strong>${esc(e.type)}</strong> — ${esc(e.date)}${e.time ? ' · ' + esc(e.time) : ''}${e.note ? ': ' + esc(e.note) : ''}<span class="meta">by ${esc(e.by)}</span></li>`).join('')
            : '<li class="no-contacts">No engagements logged yet.</li>';
        const engageBranch = document.getElementById('engage-branch-id');
        if (engageBranch) {
            engageBranch.value = id;
            document.getElementById('engage-type').value = '';
            const engageDate = document.getElementById('engage-date');
            const engageTime = document.getElementById('engage-time');
            const todayManila = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
            const nowManila = new Date().toLocaleTimeString('en-GB', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
            engageDate.value = todayManila;
            engageDate.max = todayManila;
            // Time of day is optional: prefilled with now, but the salesperson can change it
            // (entering the day's engagements at the end of the day) or clear it.
            engageTime.value = nowManila;
            // Only today has a "not later than now" limit; a past date can take any time.
            const limitTime = () => { if (engageDate.value === todayManila) { engageTime.max = nowManila; } else { engageTime.removeAttribute('max'); } };
            limitTime();
            engageDate.onchange = limitTime;
            const savedNote = document.getElementById('engage-saved');
            if (savedNote) savedNote.hidden = true;
        }

        const ownerRow = document.getElementById('owner-row');
        if (ownerRow) {
            if (info.owner) {
                document.getElementById('client-details-owner').textContent = info.owner;
                ownerRow.hidden = false;
            } else {
                ownerRow.hidden = true;
            }
        }

        const branchIdField = document.getElementById('order-update-branch-id');
        if (branchIdField) branchIdField.value = id;

        const reassignForm = document.getElementById('reassign-form');
        const reassignNote = document.getElementById('reassign-note');
        if (reassignForm) {
            const reassignBranchId = document.getElementById('reassign-branch-id');
            if (reassignBranchId) reassignBranchId.value = id;
            reassignForm.hidden = !info.reassignable;
            if (reassignNote) reassignNote.hidden = info.reassignable;

            const reassignSelect = document.getElementById('reassign-assignee');
            if (reassignSelect) {
                reassignSelect.value = '';
                Array.from(reassignSelect.options).forEach((opt) => {
                    const isCurrentOwner = opt.dataset.empId && Number(opt.dataset.empId) === Number(info.ownerId);
                    opt.hidden = isCurrentOwner;
                    opt.disabled = isCurrentOwner;
                });
            }
        }
        const dateField = document.getElementById('order-update-date');
        if (dateField) dateField.value = info.lastOrderRaw || new Date().toISOString().slice(0, 10);
        const addBranchLink = document.getElementById('modal-add-branch');
        if (addBranchLink) addBranchLink.href = 'add_company.php?company_id=' + encodeURIComponent(info.companyId);
        const requestLink = document.getElementById('modal-request-change');
        if (requestLink) requestLink.href = 'modification_request.php?branch_id=' + id;

        const list = document.getElementById('client-details-contacts');
        if (info.contacts.length) {
            list.innerHTML = info.contacts.map((c, i) => contactHtml(c, i, info.contacts.length, id, info.canAddContact)).join('')
                + (info.canAddContact ? '<li class="no-contacts"><a class="action-link" href="modification_request.php?branch_id=' + encodeURIComponent(id) + '&add_contact=1">+ Add another contact person</a></li>' : '');
        } else if (info.hiddenContacts > 0) {
            list.innerHTML = '';
        } else {
            list.innerHTML = '<li class="no-contacts">No contact person on file yet.'
                + (info.canAddContact ? ' <a class="action-link" href="modification_request.php?branch_id=' + encodeURIComponent(id) + '&add_contact=1">Add one</a>' : '')
                + '</li>';
        }

        const hiddenNote = document.getElementById('hidden-contacts-note');
        if (info.hiddenContacts > 0) {
            const n = info.hiddenContacts;
            hiddenNote.textContent = n + ' contact' + (n === 1 ? '' : 's') + ' on file — visible only to the account manager of this client or a super admin.';
            hiddenNote.hidden = false;
        } else {
            hiddenNote.hidden = true;
        }
        detailsModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    document.querySelectorAll('.client-row').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a')) return;
            openClientDetails(row.dataset.id);
        });
        row.addEventListener('keydown', (event) => {
            if (event.target !== row) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openClientDetails(row.dataset.id);
            }
        });
    });
    // After logging an engagement the page reloads with ?open=<branch id>: reopen that client's
    // popup (with its fresh engagement list) so the next one can be entered straight away.
    (function () {
        const params = new URLSearchParams(window.location.search);
        const reopenId = params.get('open');
        if (reopenId && (params.get('engaged') === '1' || params.get('moved') === '1') && branchDetails[reopenId]) {
            openClientDetails(reopenId);
            const savedNote = document.getElementById('engage-saved');
            if (savedNote) savedNote.hidden = false;
            if (window.history && history.replaceState) history.replaceState(null, '', 'dashboard.php');
        }
    })();
    document.getElementById('close-details').addEventListener('click', () => { detailsModal.hidden = true; document.body.style.overflow = ''; });
    detailsModal.addEventListener('click', (event) => { if (event.target === detailsModal) { detailsModal.hidden = true; document.body.style.overflow = ''; } });

    // Notification bell dropdown — count-based only (real unread count from the
    // server); full notification list still lives on notifications.php.
    const notifButton = document.getElementById('notification-button');
    const notifPanel = document.getElementById('notification-panel');
    if (notifButton && notifPanel) {
        notifButton.addEventListener('click', () => {
            const isHidden = notifPanel.hidden;
            notifPanel.hidden = !isHidden;
            notifButton.setAttribute('aria-expanded', String(isHidden));
        });
        document.addEventListener('click', (event) => {
            if (!notifButton.contains(event.target) && !notifPanel.contains(event.target)) {
                notifPanel.hidden = true;
                notifButton.setAttribute('aria-expanded', 'false');
            }
        });
    }
</script>
</body>
</html>