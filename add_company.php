<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'user') {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'dashboard.php' : 'manage_companies.php'));
    exit;
}

$pdo = getDbConnection();
$userId = $_SESSION['user_id'];
requireActivePassword($pdo, $userId);
$errors = [];
$success = false;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Two ways in, both from elsewhere: a client's profile ("Add another branch", ?company_id=) or the All clients search
// when nothing matched ("Add a new client", ?name=). Opening this page any other way goes back to the search.
$branchOnly = null; // [id, name] when adding a branch to a company this person already handles
$prefillName = '';
$prefillSuffix = COMPANY_SUFFIX_NONE;
$similarWarning = [];
$wantedCompanyId = (int) ($_POST['existing_company_id'] ?? $_GET['company_id'] ?? 0);
if ($wantedCompanyId > 0) {
    $ownStmt = $pdo->prepare(
        "SELECT c.id, c.name FROM companies c
         WHERE c.id = :cid AND c.status = 'active'
           AND EXISTS (SELECT 1 FROM branches b WHERE b.company_id = c.id AND b.status = 'active' AND b.assigned_user_id = :uid)"
    );
    $ownStmt->execute(['cid' => $wantedCompanyId, 'uid' => $userId]);
    $row = $ownStmt->fetch();
    if ($row) {
        $branchOnly = [(int) $row['id'], (string) $row['name']];
    } else {
        $errors[] = 'You can only add a branch to a company you already handle.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $typed = trim((string) ($_GET['name'] ?? ''));
    if ($typed === '') {
        header('Location: all_clients.php');
        exit;
    }
    $parts = companyNameParts($typed);
    $prefillName = $parts['name'];
    $prefillSuffix = $parts['suffix'] !== '' ? $parts['suffix'] : COMPANY_SUFFIX_NONE;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $companyName = '';
    $existingCompanyId = $branchOnly ? (string) $branchOnly[0] : '';
    if (!$branchOnly) {
        $baseName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['company_name'] ?? '')));
        $pickedSuffix = (string) ($_POST['company_suffix'] ?? COMPANY_SUFFIX_NONE);
        if ($pickedSuffix !== COMPANY_SUFFIX_NONE && !in_array($pickedSuffix, COMPANY_SUFFIXES, true)) {
            $errors[] = 'Choose the ending from the list.';
            $pickedSuffix = COMPANY_SUFFIX_NONE;
        }
        // A typed ending ("Gardenia Corp.") is moved to the list so the stored name is always spelled one way.
        $typedParts = companyNameParts($baseName);
        if ($typedParts['suffix'] !== '') {
            if ($pickedSuffix !== COMPANY_SUFFIX_NONE && strcasecmp($pickedSuffix, $typedParts['suffix']) !== 0) {
                $errors[] = 'The name already ends in "' . $typedParts['suffix'] . '" but you picked "' . $pickedSuffix . '". Keep the name itself in the box and pick the ending from the list.';
            }
            $baseName = $typedParts['name'];
            if ($pickedSuffix === COMPANY_SUFFIX_NONE) {
                $pickedSuffix = $typedParts['suffix'];
            }
        }
        $companyName = composeCompanyName($baseName, $pickedSuffix);
        $prefillName = $baseName;
        $prefillSuffix = $pickedSuffix;
    }
    $branchName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['branch_name'] ?? '')));
    $address = trim($_POST['address'] ?? '');
    // Categories belong to the company (shared across all its branches), so they're
    // only collected when creating a new company, or when an existing company has none
    // yet — a company that already has categories is never silently changed from here
    // (changing them later goes through Manage companies, with approval for admins).
    $categories = parseCategoriesFromPost($_POST);
    $companyHasCategories = false;
    if ($existingCompanyId !== '') {
        $have = fetchCompanyCategories($pdo, [(int) $existingCompanyId]);
        $companyHasCategories = !empty($have[(int) $existingCompanyId]);
    }

    // Contacts arrive as parallel arrays — one entry per repeated contact block. Blocks
    // left completely empty are skipped; numbers are checked (Mobile = 11 digits).
    $contacts = [];
    try {
        $contacts = parseContactRows($_POST);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    if (!$branchOnly && $baseName === '') {
        $errors[] = 'Company name is required.';
    }
    // Same company under another spelling is refused; a near match is shown first and has to be confirmed.
    if (empty($errors) && !$branchOnly) {
        $matches = findCompanyNameMatches($pdo, $companyName);
        if ($matches['exact']) {
            $owners = companyOwnerNames($pdo, $matches['exact']['id']);
            $errors[] = '"' . $matches['exact']['name'] . '" is already a client' . ($owners ? ' (account manager: ' . implode(', ', $owners) . ')' : '') . '. Search for it in All clients; a company already handled by someone else can\'t be added again.';
        } elseif ($matches['similar'] && empty($_POST['confirm_similar'])) {
            foreach ($matches['similar'] as $m) {
                $m['owners'] = companyOwnerNames($pdo, $m['id']);
                $similarWarning[] = $m;
            }
            $errors[] = 'Check the similar company name' . (count($similarWarning) > 1 ? 's' : '') . ' below first.';
        }
    }
    if ($branchName === '') {
        $errors[] = 'Branch name is required.';
    }
    if (!$companyHasCategories && empty($categories)) {
        $errors[] = 'Choose at least one category for this company.';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if ($existingCompanyId !== '') {
                $companyId = (int) $existingCompanyId;
            } else {
                $insStmt = $pdo->prepare('INSERT INTO companies (name) VALUES (:name)');
                $insStmt->execute(['name' => $companyName]);
                $companyId = (int) $pdo->lastInsertId();

                $note = '';
                if (!empty($_POST['confirm_similar'])) {
                    $note = ' (confirmed as a different company from: ' . implode(', ', array_column(findCompanyNameMatches($pdo, $companyName, $companyId)['similar'], 'name')) . ')';
                }
                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, target_table, target_id, details)
                     VALUES (:uid, 'create', 'companies', :tid, :details)"
                )->execute(['uid' => $userId, 'tid' => $companyId, 'details' => 'Created company: ' . $companyName . $note]);
            }

            // Categories are only ever filled in here for a company that has none yet.
            $nowHave = fetchCompanyCategories($pdo, [$companyId]);
            if (empty($nowHave[$companyId]) && !empty($categories)) {
                setCompanyCategories($pdo, $companyId, $categories);
            }

            // Runs regardless of how $companyId was determined above (picked from search,
            // or matched by typing a name that already existed) — this used to only run
            // in the "picked from search" branch, which meant typing a company name that
            // matched an existing one case-insensitively (reusing that company) skipped
            // the branch check entirely, letting e.g. "Fairview" and "fairview" both get
            // created as separate branches under the same company.
            $dupStmt = $pdo->prepare(
                "SELECT id FROM branches WHERE company_id = :cid AND LOWER(branch_name) = LOWER(:bname) AND status = 'active'"
            );
            $dupStmt->execute(['cid' => $companyId, 'bname' => $branchName]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('That branch already exists under this company. Refresh and check the search results.');
            }

            // One company, one salesperson: a branch added to an existing company has to stay
            // with whoever already handles it.
            $splitMessage = companyOwnerConflictMessage($pdo, $companyId, $userId, true);
            if ($splitMessage !== null) {
                throw new RuntimeException($splitMessage);
            }

            $branchStmt = $pdo->prepare(
                'INSERT INTO branches (company_id, branch_name, address, assigned_user_id)
                 VALUES (:cid, :bname, :address, :uid)'
            );
            $branchStmt->execute([
                'cid' => $companyId,
                'bname' => $branchName,
                'address' => $address,
                'uid' => $userId,
            ]);
            $branchId = (int) $pdo->lastInsertId();

            if (!empty($contacts)) {
                $contactStmt = $pdo->prepare(
                    'INSERT INTO contact_persons (branch_id, full_name, position, contact_number, contact_number_type, contact_number_2, contact_number_2_type, contact_numbers_extra, contact_emails_extra, email, created_by, sort_order)
                     VALUES (:bid, :name, :position, :number, :ntype, :number2, :n2type, :extra, :eextra, :email, :uid, :so)'
                );
                $contactOrder = 0;
                foreach (orderContactRowsByRank($contacts) as $c) {
                    $contactStmt->execute([
                        'so' => ++$contactOrder,
                        'bid' => $branchId,
                        'name' => $c['name'],
                        'position' => $c['position'],
                        'number' => $c['number'],
                        'ntype' => $c['number_type'],
                        'number2' => $c['number2'] !== '' ? $c['number2'] : null,
                        'n2type' => $c['number2_type'],
                        'extra' => $c['extras_json'] ?? null, 'eextra' => $c['email_extras_json'] ?? null,
                        'email' => $c['email'],
                        'uid' => $userId,
                    ]);
                }
            }

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, target_table, target_id, details)
                 VALUES (:uid, 'create', 'branches', :tid, :details)"
            )->execute(['uid' => $userId, 'tid' => $branchId, 'details' => 'Added branch: ' . $branchName]);

            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $branchOnly ? 'Add Branch' : 'Add Client' ?> — Syspoint CRM</title>
<link rel="icon" type="image/png" href="Syspoint image circle.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --black:#080808; --red:#d71920; --red-dark:#b51218; --ink:#171717; --muted:#707070; --line:#e4e4e4; --canvas:#f7f7f7; --white:#fff; }
    * { box-sizing: border-box; }
    body { margin:0; color:var(--ink); background:var(--canvas); font-family:"DM Sans", sans-serif; }
    .topbar { display:flex; align-items:center; justify-content:space-between; padding:18px clamp(20px,4vw,54px); border-bottom:1px solid var(--line); background:var(--ink); }
    .crumb { color:var(--muted); font-size:0.82rem; }
    .crumb strong { color:var(--white); }
    .content { max-width:640px; margin:0 auto; padding:30px clamp(20px,4vw,54px) 60px; }
    h1 { margin:0 0 6px; font-family:"Space Grotesk", sans-serif; font-size:clamp(1.5rem,3vw,2rem); }
    .content > p.sub { margin:8px 0 24px; color:var(--muted); font-size:0.88rem; }

    a { text-decoration: none; color: var(--white); }
    button { cursor: pointer; }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid transparent; border-radius: 5px; padding: 11px 15px; color: var(--white); background: var(--red); font-size: 0.82rem; font-weight: 700; transition: background 150ms ease, transform 150ms ease; }
    .button:hover { background: var(--red-dark); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(215, 25, 32, 0.18); }

    .content > p.sub { margin:0 0 24px; color:var(--muted); font-size:0.88rem; }
    .errors { margin:0 0 18px; padding:12px 14px; border-radius:6px; color:#a32b26; background:#fff0f1; font-size:0.82rem; list-style:none; }
    .success-box { padding:16px 18px; border-radius:6px; color:#1a7a30; background:#ede5e5; font-size:0.9rem; }
    .success-box a { font-weight:700; color: #171717; }
    .card { border:1px solid var(--line); border-radius:6px; background:var(--white); padding:24px clamp(18px,4vw,30px); border-color:darkgray; }
    label { display:block; margin-top:16px; margin-bottom:6px; font-size:0.82rem; font-weight:700; }
    input, textarea { width:100%; border:1px solid var(--line); border-radius:6px; padding:11px 12px; font-size:0.86rem; color:var(--ink); background:#fbfbfb; outline:0; font-family:inherit; }
    textarea { min-height:90px; resize:vertical; }
    input:focus, textarea:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(215,25,32,0.12); }
    #existing-categories { background:#fbfbfb; border:1px solid var(--line); border-radius:6px; padding:11px 12px; margin-top:8px; font-size:0.84rem; color:var(--muted); display:none; }
    .section-label { margin:26px 0 4px; font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; }
    .contact-block { position:relative; border:1px dashed var(--line); border-radius:6px; padding:14px 16px 16px; margin-top:12px; }
    .contact-block .remove-contact { position:absolute; top:10px; right:12px; border:0; background:none; color:var(--muted); font-size:0.78rem; font-weight:700; cursor:pointer; }
    .contact-block .remove-contact:hover { color:var(--red); }
    .add-contact-btn { margin-top:12px; border:1px dashed var(--red); border-radius:6px; padding:9px 14px; width:100%; color:var(--red); background:#fff0f1; font-size:0.82rem; font-weight:700; cursor:pointer; }
    .add-contact-btn:hover { background:#ffe2e3; }
    .button-submit { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1px solid transparent; border-radius:5px; padding:12px 16px; width:100%; margin-top:24px; color:var(--white); background:var(--red); font-size:0.86rem; font-weight:700; cursor:pointer; transition:background 150ms ease; }
    .button:hover { background:var(--red-dark); }
    #search-results { border:1px solid var(--line); border-radius:6px; margin-top:8px; display:none; overflow:hidden; }
    .company-match { padding:12px 14px; border-bottom:1px solid #eee; }
    .company-match:last-child { border-bottom:none; }
    .company-match h4 { margin:0 0 6px; font-size:0.88rem; }
    .branch-row { display:flex; justify-content:space-between; font-size:0.78rem; padding:4px 0; color:#555; }
    .owner { color:var(--red); font-weight:600; }
    .use-company-btn { font-size:0.76rem; margin-top:6px; cursor:pointer; color:#0f6e56; text-decoration:underline; background:none; border:none; padding:0; font-weight:700; }
    .name-row { display:flex; gap:10px; } .name-row input { flex:1; } .name-row select { width:auto; min-width:190px; padding:11px 12px; border:1px solid var(--line); border-radius:6px; font:inherit; font-size:0.86rem; background:#fbfbfb; }
    .name-preview { margin:6px 0 0; font-size:0.8rem; color:var(--muted); }
    .similar-box { margin-top:12px; padding:12px 14px; border:1px solid #f0d28a; border-radius:6px; background:#fff8e6; font-size:0.84rem; }
    .similar-box ul { margin:8px 0; padding-left:18px; } .similar-box .why { color:var(--muted); font-size:0.78rem; } .similar-box p { margin:6px 0; }
    .confirm-line { display:flex; align-items:center; gap:8px; margin:8px 0 0; font-weight:700; }
    .confirm-line input { width:auto; }
    #selected-banner { background:#eef6f3; border:1px solid #cde7dc; border-radius:6px; padding:10px 12px; margin-top:8px; font-size:0.8rem; display:none; }
</style>
</head>
<body>
<header class="topbar">
    <div class="crumb">Workspace / <strong><?= $branchOnly ? 'Add branch' : 'Add client' ?></strong></div>
   <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
</header>
<div class="content">
    <h1><?= $branchOnly ? 'Add a branch to ' . htmlspecialchars($branchOnly[1]) : 'Add a new client' ?></h1>
    <p class="sub"><?= $branchOnly ? 'The branch is added under this company and stays with you.' : 'You searched for this name and nothing matched. Check the spelling, pick the ending from the list, then fill in the first branch.' ?></p>
 
    <?php if ($success): ?>
        <div class="success-box">Added successfully. <a href="dashboard.php">Back to Clients</a> or <a href="all_clients.php">search for another client</a>.</div>
    <?php else: ?>
 
        <?php if (!empty($errors)): ?>
            <ul class="errors"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
 
        <div class="card">
        <form method="POST" action="add_company.php" id="add-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php if ($branchOnly): ?>
                <input type="hidden" name="existing_company_id" value="<?= (int) $branchOnly[0] ?>">
                <div id="selected-banner" style="display:block;">Adding a new branch under: <strong><?= htmlspecialchars($branchOnly[1]) ?></strong></div>
            <?php else: ?>
                <label for="company_name">Company name</label>
                <div class="name-row">
                    <input type="text" id="company_name" name="company_name" autocomplete="off" required value="<?= htmlspecialchars($prefillName) ?>">
                    <select name="company_suffix" id="company_suffix" aria-label="Ending of the company name">
                        <?php foreach (array_merge(COMPANY_SUFFIXES, [COMPANY_SUFFIX_NONE]) as $sf): ?>
                            <option value="<?= htmlspecialchars($sf) ?>"<?= $sf === $prefillSuffix ? ' selected' : '' ?>><?= htmlspecialchars($sf) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="name-preview">Saved as: <strong id="name-preview"></strong></p>
                <?php if ($similarWarning): ?>
                    <div class="similar-box">
                        <strong>Similar to a company that already exists:</strong>
                        <ul>
                            <?php foreach ($similarWarning as $m): ?>
                                <li><?= htmlspecialchars($m['name']) ?> <span class="why">(<?= htmlspecialchars($m['why']) ?><?= $m['owners'] ? '; account manager: ' . htmlspecialchars(implode(', ', $m['owners'])) : '' ?>)</span></li>
                            <?php endforeach; ?>
                        </ul>
                        <p>If this is a typo, fix the name above. If it really is a different company, tick the box and save again.</p>
                        <label class="confirm-line"><input type="checkbox" name="confirm_similar" value="1"> Yes, this is a different company</label>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
                $needCategories = true;
                if ($branchOnly) {
                    $have = fetchCompanyCategories($pdo, [(int) $branchOnly[0]]);
                    $needCategories = empty($have[(int) $branchOnly[0]]);
                    if (!$needCategories) {
                        echo '<div id="existing-categories" style="display:block;">Category: ' . htmlspecialchars(implode(', ', categoryLabels($have[(int) $branchOnly[0]]))) . '</div>';
                    }
                }
            ?>
            <?php if ($needCategories): ?>
            <div id="category-group">
                <label>Category <span style="font-weight:500;color:var(--muted);">(pick one or more)</span></label>
                <?= categoryPickerHtml(parseCategoriesFromPost($_POST)) ?>
            </div>
            <?php endif; ?>
 
            <label for="branch_name">Branch name</label>
            <input type="text" id="branch_name" name="branch_name" required
                   placeholder="e.g. Fairview, or same as company name if it has no branches"   
                   value="<?= htmlspecialchars($_POST['branch_name'] ?? '') ?>">
 
            <label for="address">Address</label>
            <input type="text" id="address" name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
 
            <div class="section-label">Contact persons</div>
            <div id="contact-list"></div>
            <button type="button" class="add-contact-btn" id="add-contact-btn">+ Add another contact</button>
 
            <button class="button-submit" type="submit">Save</button>
        </form>
        </div>
 
        <script src="contact_phone.js"></script>
        <script src="category_picker.js"></script>
        <script>
        // Live "Saved as" preview: name + exactly one space + ending (nothing added for N/A).
        (function () {
            const nameInput = document.getElementById('company_name');
            const suffixSelect = document.getElementById('company_suffix');
            const preview = document.getElementById('name-preview');
            if (!nameInput || !preview) return;
            function refresh() {
                const base = nameInput.value.replace(/\s+/g, ' ').trim();
                const sf = suffixSelect.value === 'N/A' ? '' : suffixSelect.value;
                preview.textContent = base ? (sf ? base + ' ' + sf : base) : '';
            }
            nameInput.addEventListener('input', refresh);
            suffixSelect.addEventListener('change', refresh);
            refresh();
        })();

        // --- Repeatable contact-person blocks ---
        const contactList = document.getElementById('contact-list');
        const addContactBtn = document.getElementById('add-contact-btn');
        let contactCount = 0;
 
        function addContactBlock() {
            contactCount++;
            const isFirst = contactCount === 1;
            const block = document.createElement('div');
            block.className = 'contact-block';
            block.innerHTML = `
                ${isFirst ? '' : '<button type="button" class="remove-contact">Remove</button>'}
                <label>Full name</label>
                <input type="text" name="contact_name[]">
                <label>Position</label>
                <input type="text" name="contact_position[]">
                ${rankFieldHtml(contactCount, 3)}
                ${phoneFieldsHtml()}
                ${emailFieldsHtml()}
            `;
            initPhoneFields(block);
            initEmailFields(block);
            if (!isFirst) {
                block.querySelector('.remove-contact').addEventListener('click', () => block.remove());
            }
            contactList.appendChild(block);
        }
 
        addContactBtn.addEventListener('click', addContactBlock);
        addContactBlock(); // start with one contact block, always present
        </script>
    <?php endif; ?>
</div>
</body>
</html>