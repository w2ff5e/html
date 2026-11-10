<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'];
if ($role !== 'admin' && $role !== 'super_admin') {
    http_response_code(403);
    die('You do not have permission to view this page.');
}

$pdo = getDbConnection();
$userId = $_SESSION['user_id'];
requireActivePassword($pdo, $userId);
$errors = [];
// ?embed=1: shown inside the admin dashboard (no top bar, forms stay in the frame).
$embed = ($_GET['embed'] ?? '') === '1';
$embedQ = $embed ? '?embed=1' : '';
$flash = null;
$renamedId = null;
$addSuccess = false;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Shared by the super admin's direct add and by approving an admin's staged
 * request — both end up creating the same company/branch/contacts, just
 * assigned to whichever employee was chosen instead of the acting user.
 * Returns the new branch id. Throws on a duplicate branch under the company.
 */
function createCompanyBranch(PDO $pdo, array $data, int $assigneeUserId, int $actingUserId): int
{
    // Re-checked here (not just when the request was submitted) because an
    // approval can happen days later: the employee may have been deactivated
    // and the company may be gone by then.
    $assignee = $pdo->prepare("SELECT 1 FROM users WHERE id = :id AND role = 'user' AND status = 'active'");
    $assignee->execute(['id' => $assigneeUserId]);
    if (!$assignee->fetchColumn()) {
        throw new RuntimeException('The employee this was assigned to is no longer active. Reject this request and have it resubmitted with a different assignee.');
    }
    if ($data['existing_company_id'] !== '' && $data['existing_company_id'] !== null) {
        $companyCheck = $pdo->prepare("SELECT 1 FROM companies WHERE id = :id AND status = 'active'");
        $companyCheck->execute(['id' => (int) $data['existing_company_id']]);
        if (!$companyCheck->fetchColumn()) {
            throw new RuntimeException('That company no longer exists. Reject this request and have it resubmitted.');
        }
    }

    if ($data['existing_company_id'] !== '' && $data['existing_company_id'] !== null) {
        $companyId = (int) $data['existing_company_id'];
    } else {
        // Same company under another spelling ("Gardenia Corp." / "Gardenia Corporation") counts as existing.
        $existing = findCompanyNameMatches($pdo, $data['company_name'])['exact'];

        if ($existing) {
            $companyId = (int) $existing['id'];
        } else {
            $insStmt = $pdo->prepare('INSERT INTO companies (name) VALUES (:name)');
            $insStmt->execute(['name' => $data['company_name']]);
            $companyId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, target_table, target_id, details)
                 VALUES (:uid, 'create', 'companies', :tid, :details)"
            )->execute(['uid' => $actingUserId, 'tid' => $companyId, 'details' => 'Created company: ' . $data['company_name']]);
        }
    }

    // Categories are only filled in for a company that has none yet; one that already
    // has categories is never changed from the add form.
    $haveCats = fetchCompanyCategories($pdo, [$companyId]);
    $wantedCats = array_values(array_intersect(array_keys(COMPANY_CATEGORIES), (array) ($data['categories'] ?? [])));
    if (empty($haveCats[$companyId]) && !empty($wantedCats)) {
        setCompanyCategories($pdo, $companyId, $wantedCats);
    }

    $dupStmt = $pdo->prepare(
        "SELECT id FROM branches WHERE company_id = :cid AND LOWER(branch_name) = LOWER(:bname) AND status = 'active'"
    );
    $dupStmt->execute(['cid' => $companyId, 'bname' => $data['branch_name']]);
    if ($dupStmt->fetch()) {
        throw new RuntimeException('That branch already exists under this company.');
    }

    // One company, one salesperson: a new branch of an existing company can only go to
    // whoever already handles it.
    $splitMessage = companyOwnerConflictMessage($pdo, $companyId, (int) $assigneeUserId, false);
    if ($splitMessage !== null) {
        throw new RuntimeException($splitMessage);
    }

    $branchStmt = $pdo->prepare(
        'INSERT INTO branches (company_id, branch_name, address, assigned_user_id) VALUES (:cid, :bname, :address, :uid)'
    );
    $branchStmt->execute([
        'cid' => $companyId,
        'bname' => $data['branch_name'],
        'address' => $data['address'],
        'uid' => $assigneeUserId,
    ]);
    $branchId = (int) $pdo->lastInsertId();

    if (!empty($data['contacts'])) {
        $contactStmt = $pdo->prepare(
            'INSERT INTO contact_persons (branch_id, full_name, position, contact_number, contact_number_type, contact_number_2, contact_number_2_type, contact_numbers_extra, contact_emails_extra, email, created_by, sort_order)
             VALUES (:bid, :name, :position, :number, :ntype, :number2, :n2type, :extra, :eextra, :email, :uid, :so)'
        );
        $contactOrder = 0;
        foreach (orderContactRowsByRank($data['contacts']) as $c) {
            $contactStmt->execute([
                'so' => ++$contactOrder,
                'bid' => $branchId,
                'name' => $c['name'],
                'position' => $c['position'],
                'number' => $c['number'],
                'ntype' => $c['number_type'] ?? 'mobile',
                'number2' => !empty($c['number2']) ? $c['number2'] : null,
                'n2type' => $c['number2_type'] ?? 'mobile',
                'extra' => $c['extras_json'] ?? null, 'eextra' => $c['email_extras_json'] ?? null,
                'email' => $c['email'],
                'uid' => $assigneeUserId,
            ]);
        }
    }

    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, details)
         VALUES (:uid, 'create', 'branches', :tid, :details)"
    )->execute(['uid' => $actingUserId, 'tid' => $branchId, 'details' => 'Added branch: ' . $data['branch_name']]);

    // Nobody else would tell the employee: the request was made and approved by
    // admins. Skipped if the person acting is the assignee themselves.
    if ($assigneeUserId !== $actingUserId) {
        $nameStmt = $pdo->prepare('SELECT name FROM companies WHERE id = :id');
        $nameStmt->execute(['id' => $companyId]);
        $companyName = (string) $nameStmt->fetchColumn();
        notifyUser(
            $pdo, $assigneeUserId, 'branch_assigned', null, 'branches', $branchId,
            truncateForNotification('A new client was assigned to you: ' . $companyName . ' — ' . $data['branch_name'])
        );
    }

    return $branchId;
}

/** Parses the repeated contact_* POST arrays (shared with add_company.php) — throws RuntimeException on a bad row. */
function parseContactsFromPost(): array
{
    return parseContactRows($_POST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $formAction = $_POST['form_action'] ?? 'rename';

    // ---- Rename a company: immediate for super admin, staged for admin ----
    if (empty($errors) && $formAction === 'rename') {
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $newName = trim($_POST['new_name'] ?? '');

        if ($newName === '') {
            $errors[] = 'New name cannot be empty.';
        } else {
            $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = :id AND status = 'active'");
            $stmt->execute(['id' => $companyId]);
            $company = $stmt->fetch();

            if (!$company) {
                $errors[] = 'Company not found.';
            } elseif (strcasecmp($company['name'], $newName) === 0) {
                $renamedId = $companyId;
            } else {
                if (findCompanyNameMatches($pdo, $newName, $companyId)['exact']) {
                    $errors[] = 'Another company already has that name. Consider merging branches under the existing one instead.';
                } elseif ($role === 'super_admin') {
                    $oldName = $company['name'];
                    $pdo->prepare('UPDATE companies SET name = :name WHERE id = :id')->execute(['name' => $newName, 'id' => $companyId]);
                    $pdo->prepare(
                        "INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'update', 'companies', :tid, :details)"
                    )->execute(['uid' => $userId, 'tid' => $companyId, 'details' => "Renamed company from '{$oldName}' to '{$newName}'"]);
                    $renamedId = $companyId;
                } else {
                    $payload = ['action' => 'rename', 'company_id' => $companyId, 'old_name' => $company['name'], 'new_name' => $newName];
                    $pdo->prepare("INSERT INTO approval_requests (type, requested_by, payload, status) VALUES ('company_assignment', :uid, :payload, 'pending')")
                        ->execute(['uid' => $userId, 'payload' => json_encode($payload)]);
                    $requestId = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_rename_request', 'approval_requests', :tid, :details)")
                        ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => "Requested to rename '{$company['name']}' to '{$newName}'"]);
                    notifyRoles($pdo, ['super_admin'], 'company_assignment_pending', null, 'approval_requests', $requestId, "Rename request from you: '{$company['name']}' → '{$newName}', needs approval.");
                    $flash = 'Rename sent — waiting on super admin approval.';
                }
            }
        }
    }

    // ---- Change a company's categories: immediate for super admin, staged for admin ----
    if (empty($errors) && $formAction === 'set_categories') {
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $newCats = parseCategoriesFromPost($_POST);
        $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $companyId]);
        $company = $stmt->fetch();
        $oldCats = fetchCompanyCategories($pdo, [$companyId])[$companyId] ?? [];

        if (!$company) {
            $errors[] = 'Company not found.';
        } elseif (empty($newCats)) {
            $errors[] = 'Choose at least one category.';
        } elseif ($newCats === $oldCats) {
            $renamedId = $companyId;
        } else {
            $oldText = implode(', ', categoryLabels($oldCats)) ?: 'Uncategorized';
            $newText = implode(', ', categoryLabels($newCats));
            if ($role === 'super_admin') {
                $pdo->beginTransaction();
                try {
                    setCompanyCategories($pdo, $companyId, $newCats);
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'update', 'companies', :tid, :details)")
                        ->execute(['uid' => $userId, 'tid' => $companyId, 'details' => "Changed categories of '{$company['name']}' from [{$oldText}] to [{$newText}]"]);
                    $pdo->commit();
                    $renamedId = $companyId;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Category change failed: ' . $e->getMessage());
                    $errors[] = 'Could not save the categories. Please try again.';
                }
            } else {
                $payload = ['action' => 'categories', 'company_id' => $companyId, 'company_name' => $company['name'], 'old_categories' => $oldCats, 'new_categories' => $newCats];
                $pdo->prepare("INSERT INTO approval_requests (type, requested_by, payload, status) VALUES ('company_assignment', :uid, :payload, 'pending')")
                    ->execute(['uid' => $userId, 'payload' => json_encode($payload)]);
                $requestId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_rename_request', 'approval_requests', :tid, :details)")
                    ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => "Requested category change for '{$company['name']}': [{$oldText}] to [{$newText}]"]);
                notifyRoles($pdo, ['super_admin'], 'company_assignment_pending', null, 'approval_requests', $requestId, "Category change request from you: '{$company['name']}' → {$newText}, needs approval.");
                $flash = 'Category change sent — waiting on super admin approval.';
            }
        }
    }

    // ---- Add a company/branch: immediate for super admin, staged for admin ----
    if (empty($errors) && $formAction === 'add') {
        $assigneeId = (int) ($_POST['assignee_user_id'] ?? 0);
        $data = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'existing_company_id' => $_POST['existing_company_id'] ?? '',
            'branch_name' => trim($_POST['branch_name'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'categories' => parseCategoriesFromPost($_POST),
        ];

        try {
            $data['contacts'] = parseContactsFromPost();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (empty($errors) && $data['company_name'] === '') {
            $errors[] = 'Company name is required.';
        }
        if (empty($errors) && $data['branch_name'] === '') {
            $errors[] = 'Branch name is required.';
        }
        // A new name is stored in the one standard form (ending in full, one space); a near match must be confirmed.
        if (empty($errors) && ($data['existing_company_id'] === '' || $data['existing_company_id'] === null)) {
            $np = companyNameParts($data['company_name']);
            $data['company_name'] = composeCompanyName($np['name'], $np['suffix']);
            $nm = findCompanyNameMatches($pdo, $data['company_name']);
            if (!$nm['exact'] && $nm['similar'] && empty($_POST['confirm_similar'])) {
                $errors[] = 'Similar to an existing company: ' . implode(', ', array_column($nm['similar'], 'name')) . '. If this is a typo, pick the existing company from the list or fix the name; if it really is different, tick "It really is a different company" and save again.';
            }
        }
        if (empty($errors)) {
            $companyHasCategories = false;
            if ($data['existing_company_id'] !== '') {
                $have = fetchCompanyCategories($pdo, [(int) $data['existing_company_id']]);
                $companyHasCategories = !empty($have[(int) $data['existing_company_id']]);
            }
            if (!$companyHasCategories && empty($data['categories'])) {
                $errors[] = 'Choose at least one category for this company.';
            }
        }
        if (empty($errors)) {
            $assigneeStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id AND role = 'user' AND status = 'active'");
            $assigneeStmt->execute(['id' => $assigneeId]);
            $assigneeName = $assigneeStmt->fetchColumn();
            if (!$assigneeName) {
                $errors[] = 'Choose an active employee to assign this to.';
            }
        }

        if (empty($errors)) {
            if ($role === 'super_admin') {
                $pdo->beginTransaction();
                try {
                    createCompanyBranch($pdo, $data, $assigneeId, $userId);
                    $pdo->commit();
                    $addSuccess = true;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = $e->getMessage();
                }
            } else {
                $data['assignee_user_id'] = $assigneeId;
                $data['assignee_name'] = $assigneeName;
                $data['action'] = 'add';
                $pdo->prepare("INSERT INTO approval_requests (type, requested_by, payload, status) VALUES ('company_assignment', :uid, :payload, 'pending')")
                    ->execute(['uid' => $userId, 'payload' => json_encode($data)]);
                $requestId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_assignment_request', 'approval_requests', :tid, :details)")
                    ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => "Requested to add {$data['branch_name']} for {$assigneeName}"]);
                notifyRoles($pdo, ['super_admin'], 'company_assignment_pending', null, 'approval_requests', $requestId, "New company/branch request from you, for {$assigneeName}, needs approval.");
                $flash = 'Request sent — waiting on super admin approval.';
            }
        }
    }

    // ---- Reassign an existing branch to a different employee: immediate for
    // super admin, staged for admin (same pattern as Add above). The actual
    // work is shared with manage_users.php's companies panel — see
    // applyOrRequestBranchReassignment() in config.php. ----
    if (empty($errors) && $formAction === 'reassign') {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $assigneeId = (int) ($_POST['assignee_user_id'] ?? 0);
        $outcome = applyOrRequestBranchReassignment($pdo, $role, $userId, $branchId, $assigneeId);
        if ($outcome['ok']) {
            $flash = $outcome['staged'] ? 'Request sent — check the Approvals page.' : 'Reassigned.';
        } else {
            $errors[] = $outcome['error'];
        }
    }

    // ---- Super admin: approve/reject a staged company-assignment request ----
    if (empty($errors) && in_array($formAction, ['approve', 'reject'], true) && $role === 'super_admin') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM approval_requests WHERE id = :id AND type = 'company_assignment' AND status = 'pending'");
        $stmt->execute(['id' => $requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $errors[] = 'That request is no longer pending.';
        } else {
            $data = json_decode($req['payload'], true) ?: [];
            $action = $data['action'] ?? 'add';

            if ($action === 'categories') {
                $newCats = array_values(array_intersect(array_keys(COMPANY_CATEGORIES), (array) ($data['new_categories'] ?? [])));
                $cname = $data['company_name'] ?? 'company';
                $newText = implode(', ', categoryLabels($newCats));
                if ($_POST['form_action'] === 'approve') {
                    $stmt2 = $pdo->prepare("SELECT 1 FROM companies WHERE id = :id AND status = 'active'");
                    $stmt2->execute(['id' => (int) ($data['company_id'] ?? 0)]);
                    if (!$stmt2->fetchColumn() || empty($newCats)) {
                        $errors[] = 'That company no longer exists, or the request has no categories — reject it.';
                    } else {
                        $detail = "Approved: categories of '{$cname}' set to {$newText}";
                        $pdo->beginTransaction();
                        try {
                            setCompanyCategories($pdo, (int) $data['company_id'], $newCats);
                            $pdo->prepare("UPDATE approval_requests SET status = 'approved', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                                ->execute(['uid' => $userId, 'id' => $requestId]);
                            notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', null, 'companies', (int) $data['company_id'], $detail);
                            $pdo->commit();
                            $flash = 'Approved.';
                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            error_log('Category approval failed: ' . $e->getMessage());
                            $errors[] = 'Could not apply the change, so nothing was changed. Please try again.';
                        }
                    }
                } else {
                    $pdo->prepare("UPDATE approval_requests SET status = 'rejected', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                        ->execute(['uid' => $userId, 'id' => $requestId]);
                    $detail = "Rejected request to change categories of '{$cname}' to {$newText}";
                    notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', null, 'approval_requests', $requestId, $detail);
                    $flash = 'Rejected.';
                }
                if (empty($errors)) {
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_rename_review', 'approval_requests', :tid, :details)")
                        ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $detail]);
                }
            } elseif ($action === 'rename') {
                if ($_POST['form_action'] === 'approve') {
                    $stmt2 = $pdo->prepare("SELECT name FROM companies WHERE id = :id AND status = 'active'");
                    $stmt2->execute(['id' => $data['company_id']]);
                    $current = $stmt2->fetchColumn();
                    $dupHit = findCompanyNameMatches($pdo, (string) $data['new_name'], (int) $data['company_id'])['exact'];

                    if ($current === false) {
                        $errors[] = 'That company no longer exists.';
                    } elseif ($dupHit) {
                        $errors[] = 'Another company already has that name now — reject this and ask them to resubmit.';
                    } else {
                        $detail = "Approved: renamed '{$data['old_name']}' to '{$data['new_name']}'";
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare('UPDATE companies SET name = :name WHERE id = :id')
                                ->execute(['name' => $data['new_name'], 'id' => $data['company_id']]);
                            $pdo->prepare("UPDATE approval_requests SET status = 'approved', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                                ->execute(['uid' => $userId, 'id' => $requestId]);
                            notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', null, 'companies', (int) $data['company_id'], $detail);
                            $pdo->commit();
                            $flash = 'Approved.';
                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            error_log('Rename approval failed: ' . $e->getMessage());
                            $errors[] = 'Could not apply the rename, so nothing was changed. Please try again.';
                        }
                    }
                } else {
                    $pdo->prepare("UPDATE approval_requests SET status = 'rejected', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                        ->execute(['uid' => $userId, 'id' => $requestId]);
                    $detail = "Rejected request to rename '{$data['old_name']}' to '{$data['new_name']}'";
                    notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', null, 'approval_requests', $requestId, $detail);
                    $flash = 'Rejected.';
                }
                if (empty($errors)) {
                    $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_rename_review', 'approval_requests', :tid, :details)")
                        ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $detail]);
                }
            } elseif ($_POST['form_action'] === 'approve') {
                $pdo->beginTransaction();
                try {
                    $branchId = createCompanyBranch($pdo, $data, (int) $data['assignee_user_id'], $userId);
                    $pdo->prepare("UPDATE approval_requests SET status = 'approved', result = :result, resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                        ->execute(['result' => (string) $branchId, 'uid' => $userId, 'id' => $requestId]);
                    $pdo->commit();
                    $detail = "Approved: added {$data['branch_name']} for {$data['assignee_name']}";
                    notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', $branchId, 'branches', $branchId, $detail);
                    $flash = 'Approved.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = $e->getMessage();
                }
            } else {
                $pdo->prepare("UPDATE approval_requests SET status = 'rejected', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute(['uid' => $userId, 'id' => $requestId]);
                $detail = "Rejected request to add {$data['branch_name']} for " . ($data['assignee_name'] ?? 'employee');
                notifyUser($pdo, (int) $req['requested_by'], 'company_assignment_reviewed', null, 'approval_requests', $requestId, $detail);
                $flash = 'Rejected.';
            }
            if (!in_array($action, ['rename', 'categories'], true) && empty($errors)) {
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'company_assignment_review', 'approval_requests', :tid, :details)")
                    ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $detail]);
            }
        }
    }
}

$companies = $pdo->query(
    "SELECT c.id, c.name, COUNT(b.id) AS branch_count
     FROM companies c
     LEFT JOIN branches b ON b.company_id = c.id AND b.status = 'active'
     WHERE c.status = 'active'
     GROUP BY c.id
     ORDER BY c.name"
)->fetchAll();

$companyCats = fetchCompanyCategories($pdo, array_column($companies, 'id'));

$employees = activeEmployees($pdo);

$myPending = [];
if ($role === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM approval_requests WHERE type = 'company_assignment' AND requested_by = :uid AND status = 'pending' ORDER BY created_at DESC");
    $stmt->execute(['uid' => $userId]);
    $myPending = $stmt->fetchAll();
}

$pendingQueue = [];
if ($role === 'super_admin') {
    $stmt = $pdo->prepare(
        "SELECT ar.*, u.full_name AS requester_name FROM approval_requests ar
         JOIN users u ON u.id = ar.requested_by
         WHERE ar.type = 'company_assignment' AND ar.status = 'pending'
         ORDER BY ar.created_at ASC"
    );
    $stmt->execute();
    $pendingQueue = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Companies — Syspoint CRM</title>
<link rel="icon" type="image/png" href="Syspoint image circle.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --black:#080808; --red:#d71920; --red-dark:#b51218; --ink:#171717; --muted:#707070; --line:#e4e4e4; --canvas:#f7f7f7; --white:#fff; }
    * { box-sizing: border-box; }
    body { margin:0; color:var(--ink); background:var(--canvas); font-family:"DM Sans", sans-serif; }
    a { text-decoration:none; color:white; }
    button { cursor:pointer; font-family:inherit; }
    .topbar { display:flex; align-items:center; justify-content:space-between; padding:18px clamp(20px,4vw,54px); border-bottom:1px solid var(--line); background:var(--ink); }
    .crumb { color:var(--muted); font-size:0.82rem; }
    .crumb strong { color:var(--white); }
    .content { max-width:900px; margin:0 auto; padding:30px clamp(20px,4vw,54px) 60px; }
    .heading-row { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:6px; }
    h1 { margin:0; font-family:"Space Grotesk", sans-serif; font-size:clamp(1.5rem,3vw,2rem); }
    .content > p.sub { margin:8px 0 24px; color:var(--muted); font-size:0.88rem; }
    .errors, .flash { margin:0 0 18px; padding:12px 14px; border-radius:6px; font-size:0.82rem; list-style:none; }
    .errors { color:#a32b26; background:#fff0f1; }
    .flash { color:#1a7a30; background:#e3f5e6; }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid transparent; border-radius: 5px; padding: 11px 15px; color: var(--white); background: var(--red); font-size: 0.82rem; font-weight: 700; }
    .button:hover { background: var(--red-dark); }
    .table-card, .card { border:1px solid var(--line); border-radius:6px; background:var(--white); overflow:visible; box-shadow: 0 8px 24px rgba(8, 8, 8, 0.045); margin-bottom:22px; }
    .table-card th:first-child { border-top-left-radius:5px; }
    .table-card th:last-child { border-top-right-radius:5px; }
    .card { overflow:visible; padding:20px clamp(16px,4vw,26px); }
    table { width:100%; border-collapse:collapse; }
    th { padding:12px 20px; background:#fbfbfb; color:#858585; font-size:0.68rem; font-weight:700; text-transform:uppercase; text-align:left; }
    td { padding:12px 20px; border-top:1px solid #eee; font-size:0.85rem; vertical-align:middle; }
    td:first-child { font-weight:700; }
    .branch-count { color:var(--muted); font-size:0.78rem; }
    .kebab-cell { text-align:right; width:60px; position:relative; }
    .kebab { position:relative; display:inline-block; }
    .kebab-trigger { width:30px; height:30px; border:0; background:none; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-weight:900; color:var(--muted); }
    .kebab-trigger:hover, .kebab.open .kebab-trigger { background:#f2f2f2; }
    .kebab .menu-panel { position:absolute; right:0; top:38px; z-index:5; background:var(--white); border:1px solid var(--line); border-radius:6px; box-shadow:0 10px 26px rgba(8,8,8,0.12); padding:14px; width:270px; text-align:left; }
    .menu-panel[hidden] { display:none; }
    .menu-panel label { display:block; font-size:0.74rem; font-weight:700; color:var(--muted); margin-bottom:6px; }
    .menu-panel input[type=text] { width:100%; border:1px solid var(--line); border-radius:5px; padding:7px 9px; font-size:0.82rem; margin-bottom:8px; }
    .menu-panel button { border:0; border-radius:5px; padding:7px 12px; color:#fff; background:var(--red); font-size:0.78rem; font-weight:700; width:100%; }
    .menu-panel .menu-divider { border:0; border-top:1px solid var(--line); margin:12px 0; }
    .menu-panel .cat-picker { margin-bottom:8px; }
    .cat-chips { display:flex; flex-wrap:wrap; gap:5px; }
    .cat-chip { display:inline-block; padding:2px 9px; border-radius:10px; font-size:0.72rem; font-weight:600; color:#444; background:#eef0f2; }
    .cat-chip.none { color:#93650b; background:#fdf1d6; }
    .menu-panel .approval-note { margin:0 0 8px; font-size:0.72rem; color:var(--muted); }
    #add-form-card.pulse { animation: card-pulse 900ms ease; }
    @keyframes card-pulse { 0% { box-shadow: 0 0 0 4px rgba(215,25,32,0.25); } 100% { box-shadow: 0 8px 24px rgba(8,8,8,0.045); } }
    .saved-tag { display:block; margin-top:6px; color:#1a7a30; font-size:0.74rem; font-weight:700; }
    label { display:block; margin-top:14px; margin-bottom:6px; font-size:0.82rem; font-weight:700; }
    input, textarea, select { width:100%; border:1px solid var(--line); border-radius:6px; padding:10px 12px; font-size:0.86rem; color:var(--ink); background:#fbfbfb; outline:0; font-family:inherit; }
    textarea { min-height:80px; resize:vertical; }
    #existing-categories { background:#fbfbfb; border:1px solid var(--line); border-radius:6px; padding:11px 12px; margin-top:8px; font-size:0.84rem; color:var(--muted); display:none; }
    .section-label { margin:22px 0 4px; font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; }
    .contact-block { position:relative; border:1px dashed var(--line); border-radius:6px; padding:14px 16px 16px; margin-top:12px; }
    .contact-block .remove-contact { position:absolute; top:10px; right:12px; border:0; background:none; color:var(--muted); font-size:0.78rem; font-weight:700; }
    .add-contact-btn { margin-top:12px; border:1px dashed var(--red); border-radius:6px; padding:9px 14px; width:100%; color:var(--red); background:#fff0f1; font-size:0.82rem; font-weight:700; }
    .button-submit { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1px solid transparent; border-radius:5px; padding:12px 16px; width:100%; margin-top:20px; color:var(--white); background:var(--red); font-size:0.86rem; font-weight:700; }
    #search-results { border:1px solid var(--line); border-radius:6px; margin-top:8px; display:none; overflow:hidden; }
    .company-match { padding:12px 14px; border-bottom:1px solid #eee; }
    .company-match:last-child { border-bottom:none; }
    .company-match h4 { margin:0 0 6px; font-size:0.88rem; }
    .branch-row { display:flex; justify-content:space-between; font-size:0.78rem; padding:4px 0; color:#555; }
    .owner { color:var(--red); font-weight:600; }
    .use-company-btn { font-size:0.76rem; margin-top:6px; cursor:pointer; color:#0f6e56; text-decoration:underline; background:none; border:none; padding:0; font-weight:700; }
    #selected-banner { background:#eef6f3; border:1px solid #cde7dc; border-radius:6px; padding:10px 12px; margin-top:8px; font-size:0.8rem; display:none; }
    .queue-item { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 0; border-top:1px solid #eee; }
    .queue-item:first-child { border-top:0; }
    .queue-item .who { font-size:0.85rem; }
    .queue-item .who span { display:block; color:var(--muted); font-size:0.74rem; margin-top:2px; }
    .queue-actions { display:flex; gap:8px; }
    .approve-btn, .reject-btn { border:0; border-radius:5px; padding:7px 12px; font-size:0.78rem; font-weight:700; color:#fff; }
    .approve-btn { background:#1a7a30; }
    .reject-btn { background:#a32b26; }
    .status-tag { display:inline-block; padding:2px 9px; border-radius:10px; font-size:0.72rem; font-weight:700; color:#93650b; background:#fdf1d6; }
<?= INFO_TIP_CSS ?>
    .heading-row h1 { display:flex; align-items:center; }
    body.embed { background:transparent; }
    body.embed .content { max-width:none; padding:0 0 18px; }
    body.embed .heading-row h1 { font-size:1.15rem; }
</style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">
<?php if (!$embed): ?>
<header class="topbar">
    <div class="crumb">Workspace / <strong>Companies</strong></div>
    <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
</header>
<?php endif; ?>
<div class="content">
    <div class="heading-row">
        <h1>Companies<?= infoTip('Add a company/branch and hand it to an employee, or use the ⋮ menu on a row to rename a company or change its categories.' . ($role === 'admin' ? ' Everything here needs super admin approval before it takes effect.' : '')) ?></h1>
        <button type="button" class="button" id="add-toggle-btn">+ Add company / branch</button>
    </div>

    <?php if (!empty($errors)): ?>
        <ul class="errors"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($flash): ?><p class="flash"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
    <?php if ($addSuccess): ?><p class="flash">Added successfully.</p><?php endif; ?>

    <div class="card" id="add-form-card" hidden>
        <p class="sub" style="margin-top:0;">Search first — if the company already exists, you'll be adding a new branch under it instead of creating a duplicate.<?= $role === 'admin' ? ' Submitting needs super admin approval before it takes effect.' : '' ?></p>
        <form method="POST" action="manage_companies.php<?= $embedQ ?>" id="add-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="form_action" value="add">
            <input type="hidden" name="existing_company_id" id="existing_company_id" value="">

            <label for="company_name">Company name</label>
            <input type="text" id="company_name" name="company_name" autocomplete="off" required>
            <label class="confirm-line" style="font-weight:500;font-size:0.78rem;margin-top:6px;"><input type="checkbox" name="confirm_similar" value="1" style="width:auto;"> It really is a different company (only tick this if a similar-name warning appears)</label>
            <div id="search-results"></div>
            <div id="selected-banner"></div>

            <div id="category-group">
                <label>Category <span style="font-weight:500;color:var(--muted);">(pick one or more)</span></label>
                <?= categoryPickerHtml([], 'categories', 'add-category-picker') ?>
            </div>
            <div id="existing-categories"></div>

            <label for="branch_name">Branch name</label>
            <input type="text" id="branch_name" name="branch_name" required
                   placeholder="e.g. Fairview, or same as company name if it has no branches">

            <label for="address">Address</label>
            <input type="text" id="address" name="address">

            <label for="assignee_user_id">Assign to employee</label>
            <select id="assignee_user_id" name="assignee_user_id" required>
                <option value="">Select an employee…</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="section-label">Contact persons</div>
            <div id="contact-list"></div>
            <button type="button" class="add-contact-btn" id="add-contact-btn">+ Add another contact</button>

            <button class="button-submit" type="submit"><?= $role === 'admin' ? 'Send for approval' : 'Save' ?></button>
        </form>
    </div>

    <?php if ($role === 'admin' && !empty($myPending)): ?>
        <div class="card">
            <p><strong>Your pending requests</strong></p>
            <?php foreach ($myPending as $p): $pd = json_decode($p['payload'], true) ?: []; ?>
                <div class="queue-item">
                    <?php if (($pd['action'] ?? 'add') === 'categories'): ?>
                        <div class="who">Categories of '<?= htmlspecialchars($pd['company_name'] ?? '') ?>' &rarr; <?= htmlspecialchars(implode(', ', categoryLabels((array) ($pd['new_categories'] ?? [])))) ?><span>Requested <?= htmlspecialchars(toLocalTime($p['created_at'])) ?></span></div>
                    <?php elseif (($pd['action'] ?? 'add') === 'rename'): ?>
                        <div class="who">Rename '<?= htmlspecialchars($pd['old_name'] ?? '') ?>' &rarr; '<?= htmlspecialchars($pd['new_name'] ?? '') ?>'<span>Requested <?= htmlspecialchars(toLocalTime($p['created_at'])) ?></span></div>
                    <?php else: ?>
                        <div class="who"><?= htmlspecialchars($pd['branch_name'] ?? '') ?> for <?= htmlspecialchars($pd['assignee_name'] ?? '') ?><span>Requested <?= htmlspecialchars(toLocalTime($p['created_at'])) ?></span></div>
                    <?php endif; ?>
                    <span class="status-tag">Waiting for approval</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($role === 'super_admin'): ?>
        <div class="card">
            <p><strong>Pending company/branch requests</strong></p>
            <?php if (empty($pendingQueue)): ?>
                <p style="color:var(--muted); margin:0;">Nothing waiting right now.</p>
            <?php else: ?>
                <?php foreach ($pendingQueue as $q): $qd = json_decode($q['payload'], true) ?: []; ?>
                    <div class="queue-item">
                        <?php if (($qd['action'] ?? 'add') === 'categories'): ?>
                            <div class="who">Categories of '<?= htmlspecialchars($qd['company_name'] ?? '') ?>': <?= htmlspecialchars(implode(', ', categoryLabels((array) ($qd['old_categories'] ?? []))) ?: 'Uncategorized') ?> &rarr; <?= htmlspecialchars(implode(', ', categoryLabels((array) ($qd['new_categories'] ?? [])))) ?><span>requested by <?= htmlspecialchars($q['requester_name']) ?></span></div>
                        <?php elseif (($qd['action'] ?? 'add') === 'rename'): ?>
                            <div class="who">Rename '<?= htmlspecialchars($qd['old_name'] ?? '') ?>' &rarr; '<?= htmlspecialchars($qd['new_name'] ?? '') ?>'<span>requested by <?= htmlspecialchars($q['requester_name']) ?></span></div>
                        <?php else: ?>
                            <div class="who"><?= htmlspecialchars($qd['company_name'] ?? '') ?> — <?= htmlspecialchars($qd['branch_name'] ?? '') ?><span>for <?= htmlspecialchars($qd['assignee_name'] ?? '') ?>, requested by <?= htmlspecialchars($q['requester_name']) ?></span></div>
                        <?php endif; ?>
                        <div class="queue-actions">
                            <form method="POST" action="manage_companies.php<?= $embedQ ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="form_action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $q['id'] ?>"><button class="approve-btn" type="submit">Approve</button></form>
                            <form method="POST" action="manage_companies.php<?= $embedQ ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="form_action" value="reject"><input type="hidden" name="request_id" value="<?= (int) $q['id'] ?>"><button class="reject-btn" type="submit">Reject</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead><tr><th>Company</th><th>Category</th><th>Branches</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr><td colspan="4" style="text-align:center; color:var(--muted); padding:30px;">No companies yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($companies as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td style="font-weight:400;">
                                <?php $cl = categoryLabels($companyCats[(int) $c['id']] ?? []); ?>
                                <div class="cat-chips"><?php if ($cl): foreach ($cl as $lbl): ?><span class="cat-chip"><?= htmlspecialchars($lbl) ?></span><?php endforeach; else: ?><span class="cat-chip none">Uncategorized</span><?php endif; ?></div>
                            </td>
                            <td class="branch-count"><?= (int) $c['branch_count'] ?> branch<?= $c['branch_count'] == 1 ? '' : 'es' ?></td>
                            <td class="kebab-cell">
                                <div class="kebab">
                                    <button type="button" class="kebab-trigger">⋮</button>
                                    <div class="menu-panel" hidden>
                                        <form method="POST" action="manage_companies.php<?= $embedQ ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="form_action" value="rename">
                                            <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                                            <label>Rename</label>
                                            <?php if ($role === 'admin'): ?><p class="approval-note">Needs super admin approval.</p><?php endif; ?>
                                            <input type="text" name="new_name" value="<?= htmlspecialchars($c['name']) ?>" required>
                                            <button type="submit"><?= $role === 'admin' ? 'Send for approval' : 'Save' ?></button>
                                            <?php if ($renamedId === $c['id']): ?><span class="saved-tag">Saved</span><?php endif; ?>
                                        </form>
                                        <hr class="menu-divider">
                                        <form method="POST" action="manage_companies.php<?= $embedQ ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="form_action" value="set_categories">
                                            <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                                            <label>Categories</label>
                                            <?php if ($role === 'admin'): ?><p class="approval-note">Needs super admin approval.</p><?php endif; ?>
                                            <?= categoryPickerHtml($companyCats[(int) $c['id']] ?? [], 'categories', 'cat-picker-' . (int) $c['id']) ?>
                                            <button type="submit"><?= $role === 'admin' ? 'Send for approval' : 'Save' ?></button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="contact_phone.js"></script>
<script src="category_picker.js"></script>
<?php if ($embed): ?>
<script>
// Tell the dashboard how tall this panel really is so it can size the frame (no inner
// scrollbar, no empty space below). Measured from the content itself, not the frame, so the
// frame can shrink as well as grow; an open row menu counts too since it hangs below the row.
(function () {
    var content = document.querySelector('.content');
    function send() {
        var h = content.getBoundingClientRect().bottom + window.scrollY;
        document.querySelectorAll('.menu-panel:not([hidden])').forEach(function (m) {
            h = Math.max(h, m.getBoundingClientRect().bottom + window.scrollY);
        });
        parent.postMessage({ type: 'mc-height', h: Math.ceil(h) + 8 }, location.origin);
    }
    new ResizeObserver(send).observe(document.body);
    document.addEventListener('click', function () { setTimeout(send, 0); });
    window.addEventListener('load', send);
})();
</script>
<?php endif; ?>
<script>
const addToggleBtn = document.getElementById('add-toggle-btn');
const addFormCard = document.getElementById('add-form-card');
if (new URLSearchParams(window.location.search).get('add') === '1') {
    document.addEventListener('DOMContentLoaded', function () { addToggleBtn.click(); });
}
addToggleBtn.addEventListener('click', function () {
    const opening = addFormCard.hidden;
    addFormCard.hidden = !opening;
    addToggleBtn.textContent = opening ? 'Close form' : '+ Add company / branch';
    if (opening) {
        addFormCard.classList.remove('pulse');
        void addFormCard.offsetWidth; // restart animation if reopened
        addFormCard.classList.add('pulse');
        addFormCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

function closeAllKebabs() {
    document.querySelectorAll('.kebab').forEach(function (k) {
        k.classList.remove('open');
        k.querySelector('.menu-panel').hidden = true;
    });
}
document.querySelectorAll('.kebab-trigger').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const kebab = btn.closest('.kebab');
        const panel = kebab.querySelector('.menu-panel');
        const wasOpen = kebab.classList.contains('open');
        closeAllKebabs();
        if (!wasOpen) {
            kebab.classList.add('open');
            panel.hidden = false;
            const input = panel.querySelector('input[type=text]');
            if (input) { input.focus(); input.select(); }
        }
    });
});
document.querySelectorAll('.menu-panel').forEach(function (panel) {
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
});
document.addEventListener('click', closeAllKebabs);
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllKebabs();
});

const nameInput = document.getElementById('company_name');
const resultsBox = document.getElementById('search-results');
const existingIdField = document.getElementById('existing_company_id');
const selectedBanner = document.getElementById('selected-banner');
const categoryGroup = document.getElementById('category-group');
const existingCategoriesBox = document.getElementById('existing-categories');
let debounceTimer;

nameInput.addEventListener('input', () => {
    existingIdField.value = '';
    selectedBanner.style.display = 'none';
    categoryGroup.style.display = 'block';
    existingCategoriesBox.style.display = 'none';
    clearTimeout(debounceTimer);
    const term = nameInput.value.trim();
    if (term.length < 3) { resultsBox.style.display = 'none'; return; }
    debounceTimer = setTimeout(() => runSearch(term), 300);
});

async function runSearch(term) {
    const res = await fetch('search_companies.php?q=' + encodeURIComponent(term));
    const companies = await res.json();
    if (companies.length === 0) { resultsBox.style.display = 'none'; return; }
    resultsBox.innerHTML = companies.map(c => {
        const branchRows = c.branches.map(b =>
            `<div class="branch-row"><span>${escapeHtml(b.branch_name)}</span><span class="owner">${b.owner_name ? 'Account Manager: ' + escapeHtml(b.owner_name) : ''}</span></div>`
        ).join('') || '<div class="branch-row"><span>No branches yet</span></div>';
        return `<div class="company-match">
            <h4>${escapeHtml(c.company_name)}</h4>
            ${branchRows}
            <button type="button" class="use-company-btn" onclick="selectCompany(${c.company_id}, '${escapeJs(c.company_name)}', '${escapeJs((c.category_labels || []).join(', '))}')">
                Use this company &rarr; add a new branch under it
            </button>
        </div>`;
    }).join('');
    resultsBox.style.display = 'block';
}

function selectCompany(id, name, categories) {
    existingIdField.value = id;
    nameInput.value = name;
    resultsBox.style.display = 'none';
    selectedBanner.style.display = 'block';
    selectedBanner.textContent = 'Adding a new branch under existing company: ' + name;
    // Existing categories show read-only; a company with none yet keeps the picker.
    if (categories) {
        categoryGroup.style.display = 'none';
        categoryGroup.querySelectorAll('input[type=checkbox]').forEach(function (b) {
            if (b.checked) { b.checked = false; b.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        existingCategoriesBox.style.display = 'block';
        existingCategoriesBox.textContent = 'Category: ' + categories;
    } else {
        categoryGroup.style.display = 'block';
        existingCategoriesBox.style.display = 'block';
        existingCategoriesBox.textContent = 'This company has no category yet — please pick one below.';
    }
}

function escapeHtml(s) { return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function escapeJs(s) { return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r?\n/g, '\\n'); }

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
        <label>Full name</label><input type="text" name="contact_name[]">
        <label>Position</label><input type="text" name="contact_position[]">
        ${rankFieldHtml(contactCount, 3)}
        ${phoneFieldsHtml()}
        ${emailFieldsHtml()}
    `;
    initPhoneFields(block);
    initEmailFields(block);
    if (!isFirst) block.querySelector('.remove-contact').addEventListener('click', () => block.remove());
    contactList.appendChild(block);
}
addContactBtn.addEventListener('click', addContactBlock);
addContactBlock();
</script>
</body>
</html>