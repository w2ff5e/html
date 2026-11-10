<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDbConnection();
$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
requireActivePassword($pdo, $userId);

// Only a super admin may browse the whole client list. Everyone else gets a
// search-only lookup that returns company NAMES and nothing else: the CEO's
// concern is that a full list of company/branch names is enough for anyone to
// go and find the contact people themselves. The filtering happens here on the
// server — hiding columns in the page would still leave the data in the HTML.
if ($role === 'admin') {
    header('Location: dashboard.php');
    exit;
}
if ($role !== 'super_admin') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $tooShort = $q !== '' && strlen($q) < 3;
    $names = [];
    $didYouMean = [];
    $rateLimited = false;
    if ($q !== '' && !$tooShort && !allowSearch($pdo, $userId, 'find', FIND_CLIENT_SEARCHES_PER_HOUR)) {
        $rateLimited = true;
    } elseif ($q !== '' && !$tooShort) {
        // "Gardenia Corp." still finds "Gardenia Corporation": the ending is dropped from what is searched for,
        // so Corp / Inc / Co / Ltd typed in any form (or not at all) all land on the same companies.
        $needle = companyNameParts($q)['name'];
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle !== '' ? $needle : $q) . '%';
        $nameStmt = $pdo->prepare(
            "SELECT DISTINCT c.name
             FROM companies c
             JOIN branches b ON b.company_id = c.id AND b.status = 'active'
             WHERE c.status = 'active' AND c.name LIKE :t ESCAPE '!'
             ORDER BY c.name
             LIMIT 15"
        );
        $nameStmt->execute(['t' => $like]);
        $names = $nameStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) {
            // Nothing contains the text: offer names that are spelled or sound almost the same (names only).
            foreach (findCompanyNameMatches($pdo, $q)['similar'] as $m) {
                $didYouMean[] = $m['name'];
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Find a client — Syspoint CRM</title>
<link rel="icon" type="image/png" href="Syspoint image circle.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --black:#080808; --red:#d71920; --red-dark:#b51218; --ink:#171717; --muted:#707070; --line:#e4e4e4; --canvas:#f7f7f7; --white:#fff; }
    * { box-sizing: border-box; }
    body { margin:0; color:var(--ink); background:var(--canvas); font-family:"DM Sans", sans-serif; }
    a { text-decoration:none; color:var(--white); }
    .button { display:inline-flex; align-items:center; justify-content:center; border:1px solid transparent; border-radius:5px; padding:11px 15px; background:var(--red); font-size:0.82rem; font-weight:700; }
    .topbar { display:flex; align-items:center; justify-content:space-between; padding:18px clamp(20px,4vw,54px); border-bottom:1px solid var(--line); background:var(--ink); }
    .crumb { color:var(--muted); font-size:0.82rem; } .crumb strong { color:var(--white); }
    .content { max-width:720px; margin:0 auto; padding:30px clamp(20px,4vw,54px) 60px; }
    h1 { margin:0; font-family:"Space Grotesk", sans-serif; font-size:clamp(1.5rem,3vw,2rem); }
    p.sub { margin:8px 0 22px; color:var(--muted); font-size:0.88rem; }
    form.search { display:flex; gap:10px; margin-bottom:22px; }
    form.search input { flex:1; padding:12px 14px; border:1px solid #cfcfcf; border-radius:5px; font:inherit; font-size:0.92rem; }
    form.search button { padding:0 20px; border:0; border-radius:5px; background:var(--red); color:#fff; font-weight:700; cursor:pointer; }
    .result-card { border:1px solid var(--line); border-radius:6px; background:var(--white); }
    .result-card div { padding:14px 18px; border-top:1px solid #eee; font-weight:700; }
    .result-card div:first-child { border-top:0; }
    .list-label { margin:0 0 8px; font-size:0.74rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
    .add-prompt { margin-top:14px; padding:16px 18px; border:1px solid var(--line); border-radius:6px; background:var(--white); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; font-weight:700; }
    .add-prompt a { color:#fff; background:var(--red); border-radius:5px; padding:10px 14px; font-size:0.82rem; }
    .dym { margin-top:14px; font-size:0.86rem; } .dym ul { margin:6px 0 0; padding-left:18px; }
    .note { color:var(--muted); font-size:0.85rem; padding:18px; border:1px dashed #cfcfcf; border-radius:6px; background:var(--white); text-align:center; }
</style>
</head>
<body>
<header class="topbar">
    <div class="crumb">Workspace / <strong>Find a client</strong></div>
    <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
</header>
<div class="content">
    <h1>Find a client</h1>
    <p class="sub">Type at least 3 letters of a company name to check whether it is already a client. Only names are shown, and searches are limited to <?= (int) FIND_CLIENT_SEARCHES_PER_HOUR ?> per hour.</p>
    <form class="search" method="GET" action="all_clients.php">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Company name..." minlength="3" autofocus aria-label="Company name">
        <button type="submit">Search</button>
    </form>
    <?php if ($q === ''): ?>
        <div class="note">Search for a company to get started.</div>
    <?php elseif ($rateLimited): ?>
        <div class="note">You've used all <?= (int) FIND_CLIENT_SEARCHES_PER_HOUR ?> searches for this hour. Please try again a little later.</div>
    <?php elseif ($tooShort): ?>
        <div class="note">Please type at least 3 letters.</div>
    <?php elseif (empty($names)): ?>
        <div class="note">No client matches "<?= htmlspecialchars($q) ?>".</div>
        <?php if ($didYouMean): ?>
            <div class="dym"><strong>Did you mean one of these existing accounts?</strong>
                <ul><?php foreach ($didYouMean as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($role === 'user'): ?>
            <div class="add-prompt"><span>No client matches "<?= htmlspecialchars($q) ?>". Add a new client?</span>
                <a href="add_company.php?name=<?= urlencode($q) ?>">Add a new client</a></div>
        <?php endif; ?>
    <?php else: ?>
        <p class="list-label">List of existing accounts</p>
        <div class="result-card">
            <?php foreach ($names as $n): ?><div><?= htmlspecialchars($n) ?></div><?php endforeach; ?>
        </div>
        <?php if (count($names) === 15): ?><p class="sub">Showing the first 15 matches — type more of the name to narrow it down.</p><?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

// Org-wide, read-only client browser for every role. Unlike the dashboard
// (which shows "my clients" for a user and "all clients" for admin/super_admin
// separately), this page always shows every active branch, regardless of role.
// It exists so a regular user can see the full company landscape, not just
// their own assigned clients — for context, not for action (no edit/request
// links live here; that stays on the dashboard).
$stmt = $pdo->query(
    "SELECT b.id, b.branch_name, b.address, c.name AS company_name, c.id AS company_id,
            o.last_order_date, u.full_name AS owner_name, u.id AS owner_id,
            (SELECT MAX(e.engaged_on) FROM engagements e WHERE e.branch_id = b.id) AS last_engagement_date
     FROM branches b
     JOIN companies c ON c.id = b.company_id
     LEFT JOIN order_dates o ON o.branch_id = b.id
     LEFT JOIN users u ON u.id = b.assigned_user_id
     WHERE b.status = 'active'
     ORDER BY c.name, b.branch_name"
);
$branches = $stmt->fetchAll();
$categoryMap = fetchCompanyCategories($pdo, array_column($branches, 'company_id'));

$statusCounts = ['active' => 0, 'warning' => 0, 'danger' => 0, 'neutral' => 0];
foreach ($branches as $b) {
    $statusCounts[orderStatus($b['last_order_date'], $b['last_engagement_date'])['class']]++;
}
$totalClients = count($branches);

// Contact persons per branch. Visibility is gated on branch OWNERSHIP (same rule as
// the dashboard's client-details modal): the current owner
// of the branch (assigned_user_id) or a super admin can see contacts on
// this page; everyone else (other employees, and admin, who never owns a
// branch) sees a hidden-count only, same wording style as the dashboard.
// A branch with no owner (unassigned) is super-admin-only here, since
// nobody currently qualifies as "the owner".
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
            || ($branchOwnerId !== null && (int) $branchOwnerId === $userId);
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

$branchDetails = [];
foreach ($branches as $b) {
    $status = orderStatus($b['last_order_date'], $b['last_engagement_date']);
    $branchDetails[$b['id']] = [
        'company' => $b['company_name'],
        'categories' => categoryLabels($categoryMap[(int) $b['company_id']] ?? []),
        'branch' => $b['branch_name'],
        'address' => $b['address'] ?: 'Not on file',
        'lastOrder' => $b['last_order_date'] ?: 'No orders yet',
        'status' => $status['label'] . ($status['quiet'] ? ' — no contact in 30+ days' : ''),
        'lastEngagement' => $b['last_engagement_date'] ?: 'None logged',
        'owner' => $b['owner_name'] ?? 'Unassigned',
        'contacts' => $contactsByBranch[$b['id']] ?? [],
        'hiddenContacts' => $hiddenContactCount[$b['id']] ?? 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Clients — Syspoint CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --black:#080808; --red:#d71920; --red-dark:#b51218; --ink:#171717; --muted:#707070; --line:#e4e4e4; --canvas:#f7f7f7; --white:#fff; }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: var(--canvas); font-family: "DM Sans", sans-serif; }
    a { text-decoration: none; color: white; }
    button { cursor: pointer; }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid transparent; border-radius: 5px; padding: 11px 15px; color: var(--white); background: var(--red); font-size: 0.82rem; font-weight: 700; transition: background 150ms ease, transform 150ms ease; }
    .button:hover { background: var(--red-dark); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(215, 25, 32, 0.18); }
    .topbar { display:flex; align-items:center; justify-content:space-between; padding: 18px clamp(20px,4vw,54px); border-bottom:1px solid var(--line); background:var(--ink); }
    .crumb { color: var(--muted); font-size:0.82rem; }
    .crumb strong { color: var(--white); }
    .content { max-width: 1280px; margin: 0 auto; padding: 30px clamp(20px,4vw,54px) 60px; }
    h1 { margin:0; font-family:"Space Grotesk", sans-serif; font-size: clamp(1.5rem,3vw,2rem); }
    .content > p.sub { margin: 8px 0 26px; color: var(--muted); font-size: 0.88rem; }
    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px; }
    .stat { min-height: 113px; border: 1px solid var(--line); border-radius: 6px; padding: 19px 20px; background: var(--white); box-shadow: 0 5px 18px rgba(8, 8, 8, 0.035); transition: border-color 150ms ease, transform 150ms ease, box-shadow 150ms ease; }
    .stat:hover { border-color: #cfcfcf; transform: translateY(-2px); box-shadow: 0 9px 22px rgba(8, 8, 8, 0.08); }
    .stat.accent { border-top: 3px solid var(--red); padding-top: 17px; }
    .stat-label { color: var(--muted); font-size: 0.75rem; font-weight: 700; }
    .stat strong { display: block; margin-top: 12px; color: var(--black); font-family: "Space Grotesk", sans-serif; font-size: 1.9rem; }
    .table-card { border:1px solid var(--line); border-radius:6px; background:var(--white); overflow:hidden; box-shadow: 0 8px 24px rgba(8, 8, 8, 0.045); }
    .table-toolbar { padding:16px 20px; border-bottom:1px solid var(--line); display:flex; gap:9px; flex-wrap:wrap; }
    .sort-btn { display: inline-flex; align-items: center; gap: 5px; border: 0; background: none; padding: 0; color: inherit; font: inherit; text-transform: inherit; letter-spacing: inherit; cursor: pointer; }
    .sort-btn:hover { color: var(--ink); }
    .sort-btn .sort-arrow { font-size: 0.7rem; }
    .search { border:1px solid var(--line); border-radius:5px; padding:9px 12px; width:240px; font-size:0.8rem; }
    .search:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.12); outline: 0; }
    table { width:100%; border-collapse:collapse; min-width: 720px; }
    th { padding:12px 20px; background:#fbfbfb; color:#858585; font-size:0.68rem; font-weight:700; text-transform:uppercase; text-align:left; }
    td { padding:14px 20px; border-top:1px solid #eee; color:#4f4f4f; font-size:0.82rem; }
    td:first-child { color: var(--ink); font-weight:700; }
    .status { font-weight:700; font-size:0.76rem; }
    .status.active { color:#1a7a30; }
    .status.warning { color:#93650b; }
    .status.danger { color:#a32b26; }
    .status.neutral { color:#707070; }
    .table-wrap { overflow-x:auto; }
    tr.highlight { animation: flash-highlight 2.2s ease-out; }
    @keyframes flash-highlight { 0%, 35% { background: #fdf1d6; } 100% { background: transparent; } }
    .client-row { cursor: pointer; }
    .client-row:hover { background: #fff8f8; }
    .client-row:focus { outline: 2px solid var(--red); outline-offset: -2px; }
    .client-name { display: block; }
    .client-hint { display: block; margin-top: 2px; color: var(--red); font-size: 0.68rem; font-weight: 700; opacity: 0; transition: opacity 120ms ease; }
    .client-row:hover .client-hint, .client-row:focus .client-hint { opacity: 1; }

    .modal-backdrop { position: fixed; inset: 0; z-index: 5; display: grid; place-items: center; padding: 20px; background: rgba(0, 0, 0, 0.62); }
    .modal-backdrop[hidden] { display: none; }
    .modal { width: min(100%, 560px); max-height: 88vh; overflow-y: auto; -webkit-overflow-scrolling: touch; border-radius: 7px; padding: 28px; background: var(--white); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25); }
    .modal-head { display: flex; align-items: start; justify-content: space-between; gap: 15px; margin-bottom: 20px; }
    .modal h2 { font-size: 1.3rem; margin: 0; }
    .modal-kicker { margin: 0 0 6px; color: var(--red); font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
    .close-button { border: 0; color: var(--muted); background: transparent; font-size: 1.4rem; line-height: 1; }
    .client-details > p { margin: 0 0 20px; color: var(--muted); font-size: 0.85rem; line-height: 1.6; }
    .client-details dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0 0 22px; }
    .client-details dl div { padding: 12px 14px; border: 1px solid var(--line); border-radius: 5px; background: #fbfbfb; }
    .client-details dt { color: var(--muted); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
    .client-details dd { margin: 5px 0 0; color: var(--ink); font-size: 0.84rem; font-weight: 600; }
    .contact-list { display: grid; gap: 9px; margin: 0; padding: 0; list-style: none; }
    .contact-list li { border: 1px solid var(--line); border-radius: 5px; padding: 11px 13px; font-size: 0.8rem; }
    .contact-list .contact-name { font-weight: 700; }
    .contact-list .contact-meta { display: block; margin-top: 3px; color: var(--muted); font-size: 0.75rem; }
    .no-contacts { color: var(--muted); font-size: 0.82rem; }
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
    @media (max-width: 640px) {
        .client-details dl { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<header class="topbar">
    <div class="crumb">Workspace / <strong>All clients</strong></div>
    <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
</header>
<div class="content">
    <h1>All clients</h1>
    <p class="sub">Every active client across the whole team. Click a row to view details — this page is read-only.</p>

    <div class="stats">
        <div class="stat accent"><span class="stat-label">Total clients</span><strong><?= $totalClients ?></strong></div>
        <div class="stat"><span class="stat-label">Active Clients</span><strong><?= $statusCounts['active'] ?></strong></div>
        <div class="stat"><span class="stat-label">3-month warning</span><strong><?= $statusCounts['warning'] ?></strong></div>
        <div class="stat"><span class="stat-label">Reassignment flag</span><strong><?= $statusCounts['danger'] ?></strong></div>
    </div>

    <div class="table-card">
        <div class="table-toolbar">
            <a class="button" href="engagements.php" style="white-space:nowrap;">Engagements</a>
            <select class="category-filter" id="category-filter" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach (COMPANY_CATEGORIES as $ckey => $clabel): ?>
                    <option value="<?= htmlspecialchars($ckey) ?>"><?= htmlspecialchars($clabel) ?></option>
                <?php endforeach; ?>
                <option value="_none">Uncategorized</option>
            </select>
            <input class="search" id="search" type="search" placeholder="Search company, branch, account manager, or category..." aria-label="Search">
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr>
                <th><button class="sort-btn" data-sort="name" data-type="text">Company <span class="sort-arrow"></span></button></th>
                <th><button class="sort-btn" data-sort="branch" data-type="text">Branch <span class="sort-arrow"></span></button></th>
                <th>Category</th>
                <th><button class="sort-btn" data-sort="owner" data-type="text">Account Manager <span class="sort-arrow"></span></button></th>
                <th><button class="sort-btn" data-sort="order" data-type="date">Last order <span class="sort-arrow"></span></button></th>
                <th><button class="sort-btn" data-sort="status" data-type="rank">Status <span class="sort-arrow"></span></button></th>
            </tr></thead>
            <tbody id="rows">
                <?php if (empty($branches)): ?>
                    <tr><td colspan="6" style="text-align:center; color:var(--muted); padding:40px;">No active clients in the system yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($branches as $b): ?>
                        <?php
                            $status = orderStatus($b['last_order_date'], $b['last_engagement_date']);
                            $statusRank = ['active' => 0, 'warning' => 1, 'danger' => 2, 'neutral' => 3][$status['class']];
                        ?>
                        <tr class="client-row" tabindex="0" data-id="<?= (int) $b['id'] ?>"
                            data-name="<?= htmlspecialchars(strtolower($b['company_name'])) ?>"
                            data-branch="<?= htmlspecialchars(strtolower($b['branch_name'])) ?>"
                            data-owner="<?= htmlspecialchars(strtolower($b['owner_name'] ?? '')) ?>"
                            data-order="<?= htmlspecialchars($b['last_order_date'] ?? '') ?>"
                            data-status="<?= $statusRank ?>"
                            data-cats="|<?= htmlspecialchars(implode('|', $categoryMap[(int) $b['company_id']] ?? ['_none'])) ?>|">
                            <td>
                                <span class="client-name"><?= htmlspecialchars($b['company_name']) ?></span>
                                <span class="client-hint">View details</span>
                            </td>
                            <td><?= htmlspecialchars($b['branch_name']) ?></td>
                            <td><div class="cat-cell"><?php $rowCats = categoryLabels($categoryMap[(int) $b['company_id']] ?? []); if ($rowCats): foreach ($rowCats as $rl): ?><span class="cat-chip"><?= htmlspecialchars($rl) ?></span><?php endforeach; else: ?><span class="cat-chip none">Uncategorized</span><?php endif; ?></div></td>
                            <td><?= htmlspecialchars($b['owner_name'] ?? 'Unassigned') ?></td>
                            <td><?= $b['last_order_date'] ? htmlspecialchars($b['last_order_date']) : '—' ?></td>
                            <td><span class="status <?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span><?php if ($status['quiet']): ?><span style="display:block;margin-top:3px;color:#707070;font-size:0.68rem;font-weight:600;">No contact in 30+ days</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
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
                <div><dt>Account Manager</dt><dd id="client-details-owner"></dd></div>
                <div><dt>Last order</dt><dd id="client-details-order"></dd></div>
                <div><dt>Status</dt><dd id="client-details-status"></dd></div>
                <div><dt>Last engagement</dt><dd id="client-details-engagement"></dd></div>
            </dl>

            <p class="modal-kicker">Contact persons</p>
            <ul class="contact-list" id="client-details-contacts"></ul>
            <p class="hidden-contacts-note" id="hidden-contacts-note" hidden></p>
        </div>
    </div>
</div>

<script src="suggest.js"></script>
<script>
    const branchDetails = <?= json_encode($branchDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

    const searchInput = document.getElementById('search');
    const categoryFilter = document.getElementById('category-filter');
    function applyRowFilters() {
        const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const cat = categoryFilter ? categoryFilter.value : '';
        document.querySelectorAll('#rows tr').forEach((row) => {
            const textOk = row.textContent.toLowerCase().includes(term);
            const catOk = !cat || (row.dataset.cats || '').includes('|' + cat + '|');
            row.style.display = (textOk && catOk) ? '' : 'none';
        });
    }
    if (searchInput) searchInput.addEventListener('input', applyRowFilters);
    // Company names as suggestions while typing; Enter takes the top one.
    if (searchInput && typeof attachSuggest === 'function') {
        attachSuggest(searchInput, function () { return Object.keys(branchDetails).map(function (k) { return branchDetails[k].company; }); });
    }
    if (categoryFilter) categoryFilter.addEventListener('change', applyRowFilters);

    // Sortable columns — click a header to sort, click again to reverse.
    // Same mechanism as the dashboard's client table.
    let currentSort = { key: null, dir: 1 };
    document.querySelectorAll('.sort-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.sort;
            const type = btn.dataset.type;
            currentSort.dir = (currentSort.key === key) ? currentSort.dir * -1 : 1;
            currentSort.key = key;

            document.querySelectorAll('.sort-arrow').forEach((a) => a.textContent = '');
            btn.querySelector('.sort-arrow').textContent = currentSort.dir === 1 ? '\u25B2' : '\u25BC';

            const attr = { name: 'data-name', branch: 'data-branch', owner: 'data-owner', order: 'data-order', status: 'data-status' }[key];
            const tbody = document.getElementById('rows');
            const rows = Array.from(tbody.querySelectorAll('tr.client-row'));

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

    const detailsModal = document.getElementById('client-details-modal');
    function openClientDetails(id) {
        const info = branchDetails[id];
        if (!info) return;
        document.getElementById('client-details-title').textContent = info.company;
        renderCategories(info.categories);
        document.getElementById('client-details-branch').textContent = info.branch;
        document.getElementById('client-details-address').textContent = info.address;
        document.getElementById('client-details-owner').textContent = info.owner;
        document.getElementById('client-details-order').textContent = info.lastOrder;
        document.getElementById('client-details-status').textContent = info.status;
        document.getElementById('client-details-engagement').textContent = info.lastEngagement;

        const list = document.getElementById('client-details-contacts');
        if (info.contacts.length) {
            list.innerHTML = info.contacts.map((c, i) => contactHtml(c, i, info.contacts.length, id, false)).join('');
        } else if (info.hiddenContacts > 0) {
            list.innerHTML = '';
        } else {
            list.innerHTML = '<li class="no-contacts">No contact person on file yet.</li>';
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
        row.addEventListener('click', () => openClientDetails(row.dataset.id));
        row.addEventListener('keydown', (event) => {
            if (event.target !== row) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openClientDetails(row.dataset.id);
            }
        });
    });
    document.getElementById('close-details').addEventListener('click', () => { detailsModal.hidden = true; document.body.style.overflow = ''; });
    detailsModal.addEventListener('click', (event) => { if (event.target === detailsModal) { detailsModal.hidden = true; document.body.style.overflow = ''; } });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !detailsModal.hidden) {
            detailsModal.hidden = true;
            document.body.style.overflow = '';
        }
    });

    // Notification click-through: a link like all_clients.php?highlight=<branch_id>
    // scrolls to and briefly flashes that row, same convention used on
    // approvals.php and manage_users.php.
    var highlightId = new URLSearchParams(window.location.search).get('highlight');
    if (highlightId) {
        var highlightRow = document.querySelector('.client-row[data-id="' + highlightId + '"]');
        if (highlightRow) {
            highlightRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            highlightRow.classList.add('highlight');
        }
    }
</script>
</body>
</html>