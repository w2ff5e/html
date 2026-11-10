<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$role = $_SESSION['role'];
if ($role !== 'admin' && $role !== 'super_admin') {
    http_response_code(403);
    die('Admins and super admins only.');
}

$pdo = getDbConnection();
$userId = $_SESSION['user_id'];
requireActivePassword($pdo, $userId);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' or 'reject'
    if (!in_array($decision, ['approve', 'reject'], true)) {
        header('Location: approvals.php');
        exit;
    }

    // Remark on the decision. Required from an admin (their reason is what the super
    // admin reads), optional-but-suggested from a super admin. Accept or reject alike.
    $remark = truncateForNotification(trim((string) ($_POST['remark'] ?? '')), 500);
    if ($role === 'admin' && $remark === '') {
        $_SESSION['approvals_error'] = 'Please add a remark explaining your decision — the super admin will see it.';
        header('Location: approvals.php');
        exit;
    }
    $remarkSuffix = $remark !== '' ? ' — Remark: "' . $remark . '"' : '';

    $reqStmt = $pdo->prepare('SELECT * FROM modification_requests WHERE id = :id');
    $reqStmt->execute(['id' => $requestId]);
    $request = $reqStmt->fetch();

    if ($request) {
        // Human-readable label reused in every audit message below — e.g.
        // "contact_email for John Smith (Acme Corp — Main Branch)" instead of a
        // bare "request #14" that just repeats the row's own id.
        $labelStmt = $pdo->prepare(
            'SELECT b.branch_name, c.name AS company_name, cp.full_name AS contact_name
             FROM branches b
             JOIN companies c ON c.id = b.company_id
             LEFT JOIN contact_persons cp ON cp.id = :cid
             WHERE b.id = :bid'
        );
        $labelStmt->execute(['bid' => $request['branch_id'], 'cid' => $request['contact_id']]);
        $labelRow = $labelStmt->fetch();
        $branchLabel = $labelRow ? ($labelRow['company_name'] . ' — ' . $labelRow['branch_name']) : ('branch #' . $request['branch_id']);
        // A reassignment moves the whole company, so name the company, never one branch.
        if ($request['field_name'] === 'assigned_user_id') {
            $transferCompany = loadCompanyForTransfer($pdo, (int) $request['branch_id']);
            if ($transferCompany !== null) {
                $branchLabel = companyTransferLabel($transferCompany);
            }
        }
        $requestLabel = $request['field_name']
            . (!empty($labelRow['contact_name']) ? ' for ' . $labelRow['contact_name'] : '')
            . " ($branchLabel)";

        if ($role === 'admin' && $request['status'] === 'pending') {
            // Status-guarded, like the super admin's update below: if a super admin
            // has already acted on this request, an admin who still had the page
            // open must not be able to drag it back to 'admin_approved'.
            $adminStatus = $decision === 'reject' ? 'admin_rejected' : 'admin_approved';
            $adminClaim = $pdo->prepare('UPDATE modification_requests SET status = :s, reviewed_by_admin = :uid, admin_remark = :remark WHERE id = :id AND status = \'pending\'');
            $adminClaim->execute(['s' => $adminStatus, 'uid' => $userId, 'remark' => $remark, 'id' => $requestId]);

            if ($adminClaim->rowCount() === 1) {
                $adminName = userFullName($pdo, $userId);
                $requesterName = userFullName($pdo, (int) $request['requested_by']);
                $summary = requestChangeSummary($pdo, $request, $branchLabel);
                $remarkNote = $remark !== '' ? ' Remark: ' . $remark : '';
                if ($decision === 'reject') {
                    $logDetails = 'Admin rejected request: ' . $requestLabel . $remarkSuffix;
                    // Tell the salesperson, and the super admins (who can still override it).
                    notifyUser($pdo, (int) $request['requested_by'], 'modification_reviewed', null, 'modification_requests', $requestId,
                        truncateForNotification('Admin ' . $adminName . ' rejected your request to ' . $summary . '.' . $remarkNote));
                    notifyRoles($pdo, ['super_admin'], 'modification_superadmin_pending', (int) $request['branch_id'], 'modification_requests', $requestId,
                        truncateForNotification('Admin ' . $adminName . ' rejected ' . $requesterName . "'s request to " . $summary . '. You can still approve it to override.' . $remarkNote));
                } else {
                    // Every change needs super admin sign-off; an admin's approval only
                    // forwards it. Nothing is applied until a super admin approves.
                    $logDetails = 'Admin approved request: ' . $requestLabel . ' — forwarded to super admin' . $remarkSuffix;
                    // Super admins don't see anything in this queue until it's admin_approved —
                    // without this they'd have no way to know a request is waiting.
                    notifyRoles($pdo, ['super_admin'], 'modification_superadmin_pending', (int) $request['branch_id'], 'modification_requests', $requestId,
                        truncateForNotification('Admin ' . $adminName . ' pre-approved ' . $requesterName . "'s request to " . $summary . '. Waiting for your approval.' . $remarkNote));
                    notifyUser($pdo, (int) $request['requested_by'], 'modification_reviewed', null, 'modification_requests', $requestId,
                        truncateForNotification('Admin ' . $adminName . ' pre-approved your request to ' . $summary . '. It now waits for a super admin.' . $remarkNote));
                }
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'edit_approval', 'modification_requests', :tid, :details)")
                    ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $logDetails]);
            }
        }

        // A super admin can act on any open request at any point: one an admin
        // approved, one an admin rejected (approve = override, reject = confirm),
        // or one no admin has looked at yet. Admin review is a recommendation the
        // super admin can see, not a gate. The status-guarded UPDATE means only one
        // decision can ever win if two people act at the same moment, so the
        // change is applied at most once.
        $superAdminActable = ['pending', 'admin_approved', 'admin_rejected'];
        if ($role === 'super_admin' && in_array($request['status'], $superAdminActable, true)) {
            $earlier = [
                'pending' => ' (admin had not reviewed it yet)',
                'admin_approved' => '',
                'admin_rejected' => ' (admin had rejected it)',
            ][$request['status']];
            $newStatus = $decision === 'reject' ? 'superadmin_rejected' : 'superadmin_approved';

            // The reassignment target is checked at approval time, not just when the
            // request was made: an approval can happen days later, by which point the
            // employee may have been deactivated. Same rule createCompanyBranch() uses.
            $blockedReason = null;
            if ($decision === 'approve' && $request['field_name'] === 'assigned_user_id') {
                $assigneeCheck = $pdo->prepare("SELECT 1 FROM users WHERE id = :id AND role = 'user' AND status = 'active'");
                $assigneeCheck->execute(['id' => (int) $request['new_value']]);
                if (!$assigneeCheck->fetchColumn()) {
                    $blockedReason = 'Could not approve: the employee this client would be reassigned to is no longer active. Reject the request and have it resubmitted with a different employee.';
                }
            }

            if ($blockedReason !== null) {
                $_SESSION['approvals_error'] = $blockedReason;
            } else {
                $pdo->beginTransaction();
                try {
                    $claim = $pdo->prepare(
                        'UPDATE modification_requests SET status = :new_status, reviewed_by_superadmin = :uid,
                                superadmin_remark = :remark, resolved_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND status = :old_status'
                    );
                    $claim->execute(['new_status' => $newStatus, 'uid' => $userId, 'remark' => $remark, 'id' => $requestId, 'old_status' => $request['status']]);
                    if ($claim->rowCount() === 1) {
                        $transfer = null;
                        if ($decision === 'reject') {
                            $logDetails = 'Super admin rejected request: ' . $requestLabel . $earlier . $remarkSuffix;
                        } else {
                            $transfer = applyChange($pdo, $request);
                            $logDetails = 'Super admin approved and applied request: ' . $requestLabel . $earlier . $remarkSuffix;
                            if ($transfer !== null) {
                                $logDetails .= ' — transferred from ' . userFullName($pdo, $transfer['old_id']) . ' to ' . userFullName($pdo, $transfer['new_id']);
                            }
                        }
                        $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'edit_approval', 'modification_requests', :tid, :details)")
                            ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $logDetails]);
                        notifyChangeOutcome($pdo, $request, $decision === 'approve', $userId, $branchLabel, $transfer, $remark);
                        // Admins hear the outcome too: the admin who reviewed it, or every admin when a
                        // super admin decided before any admin had looked.
                        $superName = userFullName($pdo, $userId);
                        $verdict = $decision === 'approve' ? 'approved' : 'rejected';
                        $adminMsg = truncateForNotification('Super admin ' . $superName . ' ' . $verdict . ' ' . userFullName($pdo, (int) $request['requested_by']) . "'s request to " . requestChangeSummary($pdo, $request, $branchLabel) . '.' . ($remark !== '' ? ' Remark: ' . $remark : ''));
                        if (!empty($request['reviewed_by_admin'])) {
                            notifyUser($pdo, (int) $request['reviewed_by_admin'], 'modification_reviewed', null, 'modification_requests', $requestId, $adminMsg);
                        } elseif ($request['status'] === 'pending') {
                            notifyRoles($pdo, ['admin'], 'modification_reviewed', null, 'modification_requests', $requestId, $adminMsg);
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        }
    }
    header('Location: approvals.php');
    exit;
}

// Applies the actual field change to the branches table once fully approved.
// Returns ['old_id' => ?int, 'new_id' => int] when the change moved a client
// between employees (the owner is read from the branch itself, not from the
// request, since the request's old_value can be stale), otherwise null.
function applyChange(PDO $pdo, array $request): ?array
{
    // Removing a contact person: soft delete. The row stays (status 'inactive') so audit
    // entries and past requests that point at it still make sense; every screen and the
    // export only list active contacts, so it disappears from the client.
    if ($request['field_name'] === 'contact_remove') {
        if (!empty($request['contact_id'])) {
            $pdo->prepare("UPDATE contact_persons SET status = 'inactive' WHERE id = :cid AND branch_id = :bid")
                ->execute(['cid' => $request['contact_id'], 'bid' => $request['branch_id']]);
        }
        return null;
    }

    // Reassigning moves the whole company, not just the branch the request hangs off.
    if ($request['field_name'] === 'assigned_user_id') {
        return applyCompanyTransfer($pdo, $request);
    }

    $branchFields = ['branch_name' => 'branch_name', 'address' => 'address', 'assigned_user_id' => 'assigned_user_id'];
    $contactFields = ['contact_full_name' => 'full_name', 'contact_position' => 'position', 'contact_number' => 'contact_number', 'contact_number_type' => 'contact_number_type', 'contact_number_2' => 'contact_number_2', 'contact_number_2_type' => 'contact_number_2_type', 'contact_numbers_extra' => 'contact_numbers_extra', 'contact_email' => 'email', 'contact_emails_extra' => 'contact_emails_extra'];

    if (array_key_exists($request['field_name'], $branchFields)) {
        $column = $branchFields[$request['field_name']];
        $transfer = null;
        if ($request['field_name'] === 'assigned_user_id') {
            $currentStmt = $pdo->prepare('SELECT assigned_user_id FROM branches WHERE id = :bid');
            $currentStmt->execute(['bid' => $request['branch_id']]);
            $current = $currentStmt->fetchColumn();
            $transfer = [
                'old_id' => ($current === false || $current === null) ? null : (int) $current,
                'new_id' => (int) $request['new_value'],
            ];
        }
        $pdo->prepare("UPDATE branches SET {$column} = :val WHERE id = :bid")
            ->execute(['val' => $request['new_value'], 'bid' => $request['branch_id']]);
        return $transfer;
    } elseif (array_key_exists($request['field_name'], $contactFields) && !empty($request['contact_id'])) {
        $column = $contactFields[$request['field_name']];
        $pdo->prepare("UPDATE contact_persons SET {$column} = :val WHERE id = :cid")
            ->execute(['val' => $request['new_value'], 'cid' => $request['contact_id']]);
    }
    return null;
}

// Moves every active branch of the request's company to the requested employee, so a
// company is never split between salespeople. Like the rest of applyChange it reads the
// current owners from the branches themselves (the request's old_value can be stale).
// Returns the transfer summary notifyChangeOutcome() uses; old_id equals new_id when
// nothing needed to change.
function applyCompanyTransfer(PDO $pdo, array $request): array
{
    $newId = (int) $request['new_value'];
    $requestBranchId = (int) $request['branch_id'];
    $companyId = companyIdOfBranch($pdo, $requestBranchId);

    $rowsStmt = $pdo->prepare("SELECT id, assigned_user_id FROM branches WHERE company_id = :cid AND (status = 'active' OR id = :bid) ORDER BY id");
    $rowsStmt->execute(['cid' => $companyId, 'bid' => $requestBranchId]);
    $rows = $rowsStmt->fetchAll();

    $oldIds = [];
    $changed = false;
    $requestBranchOwner = null;
    foreach ($rows as $row) {
        $owner = $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null;
        if ((int) $row['id'] === $requestBranchId) {
            $requestBranchOwner = $owner;
        }
        if ($owner !== $newId) {
            $changed = true;
            if ($owner !== null) {
                $oldIds[$owner] = $owner;
            }
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE branches SET assigned_user_id = :val WHERE company_id = :cid AND (status = 'active' OR id = :bid)")
            ->execute(['val' => $newId, 'cid' => $companyId, 'bid' => $requestBranchId]);
    }
    $oldIds = array_values($oldIds);
    if (!$changed) {
        $oldId = $newId;
    } else {
        $oldId = ($requestBranchOwner !== null && $requestBranchOwner !== $newId) ? $requestBranchOwner : ($oldIds[0] ?? null);
    }
    return ['old_id' => $oldId, 'new_id' => $newId, 'old_ids' => $oldIds, 'branch_count' => count($rows)];
}

// Same wording as the My requests page, lower-cased for use inside a sentence.
/** Short phrase for a notice: "reassign Acme — Main to Bea Cruz", "change the address of Acme — Main". */
function requestChangeSummary(PDO $pdo, array $request, string $branchLabel): string
{
    if ($request['field_name'] === 'assigned_user_id') {
        return 'reassign ' . $branchLabel . ' to ' . userFullName($pdo, (int) $request['new_value']);
    }
    if ($request['field_name'] === 'contact_remove') {
        return 'remove ' . ($request['old_value'] ?: 'a contact') . ' from ' . $branchLabel;
    }
    return 'change the ' . changeFieldLabel($request['field_name']) . ' of ' . $branchLabel;
}

function changeFieldLabel(string $fieldName): string
{
    return [
        'branch_name' => 'branch name',
        'address' => 'address',
        'assigned_user_id' => 'assigned rep',
        'contact_full_name' => "contact's name",
        'contact_position' => "contact's position",
        'contact_number' => "contact's phone",
        'contact_number_type' => "contact's phone type",
        'contact_number_2' => "contact's second phone",
        'contact_number_2_type' => "contact's second phone type",
        'contact_numbers_extra' => "contact's additional phone numbers",
        'contact_email' => "contact's email",
        'contact_emails_extra' => "contact's additional emails",
        'contact_remove' => 'contact removal',
    ][$fieldName] ?? $fieldName;
}

/**
 * Tells people what a super admin's final decision did. Only the final
 * decision notifies the requester (an admin forwarding or rejecting it does
 * not), and nobody is notified about their own action.
 *
 *  - The requester always hears the outcome.
 *  - When a client changed hands, the previous and the new owner are told too,
 *    unless they are the requester (whose notice already says who it moved
 *    from/to) or the person who just did it.
 */
function notifyChangeOutcome(PDO $pdo, array $request, bool $approved, int $actingUserId, string $branchLabel, ?array $transfer, string $remark = ''): void
{
    $requesterId = (int) $request['requested_by'];
    $requestId = (int) $request['id'];
    $branchId = (int) $request['branch_id'];
    $label = changeFieldLabel($request['field_name']);
    $oldId = $transfer['old_id'] ?? null;
    $newId = $transfer['new_id'] ?? null;
    $oldName = $transfer !== null ? userFullName($pdo, $oldId) : '';
    $newName = $transfer !== null ? userFullName($pdo, $newId) : '';
    $cut = static fn(string $m): string => truncateForNotification($m);

    if ($requesterId !== $actingUserId) {
        if (!$approved) {
            $message = $request['field_name'] === 'contact_remove'
                ? 'Your request to remove ' . ($request['old_value'] ?: 'a contact') . ' from ' . $branchLabel . ' was rejected by a super admin.'
                : 'Your request to change the ' . $label . ' for ' . $branchLabel . ' was rejected by a super admin.';
        } elseif ($transfer !== null && $oldId === $newId) {
            $message = 'Approved, but ' . $branchLabel . ' was already assigned to ' . $newName . ', so nothing changed.';
        } elseif ($transfer !== null) {
            $message = 'Approved: ' . $branchLabel . ' has been reassigned' . ($oldId !== null ? ' from ' . $oldName : '') . ' to ' . $newName . '.';
        } elseif ($request['field_name'] === 'contact_remove') {
            $message = 'Approved: ' . ($request['old_value'] ?: 'the contact') . ' has been removed from ' . $branchLabel . '.';
        } else {
            $message = 'Approved: your ' . $label . ' change for ' . $branchLabel . ' has been applied.';
        }
        if ($remark !== '') {
            $message .= ' Remark: ' . $remark;
        }
        notifyUser($pdo, $requesterId, 'modification_reviewed', null, 'modification_requests', $requestId, $cut($message));
    }

    if ($approved && $transfer !== null && $oldId !== $newId) {
        // Every previous owner of the company hears it (there can be more than one if the
        // company was split before this fix); the requester and the actor already know.
        foreach (($transfer['old_ids'] ?? ($oldId !== null ? [$oldId] : [])) as $previousOwnerId) {
            if ($previousOwnerId !== $newId && $previousOwnerId !== $requesterId && $previousOwnerId !== $actingUserId) {
                notifyUser($pdo, $previousOwnerId, 'branch_transferred_out', null, null, null, $cut($branchLabel . ' has been transferred from you to ' . $newName . '.'));
            }
        }
        if ($newId !== null && $newId !== $requesterId && $newId !== $actingUserId) {
            notifyUser($pdo, $newId, 'branch_transferred_in', null, 'branches', $branchId, $cut($branchLabel . ' has been transferred to you' . ($oldId !== null ? ' from ' . $oldName : '') . '.'));
        }
    }
}

// Queue: admins see what's waiting for their review ('pending'). Super admins see
// every open request, with the admin's decision shown on each row: not reviewed
// yet ('pending'), approved by an admin, or rejected by an admin. Rejected ones
// stay until a super admin acts on them (approve to override, reject to close).
$statusesToShow = $role === 'admin' ? ['pending'] : ['pending', 'admin_approved', 'admin_rejected'];
$stmt = $pdo->prepare(
    'SELECT mr.*, c.name AS company_name, b.branch_name, u.full_name AS requested_by_name,
            (SELECT COUNT(*) FROM branches b2 WHERE b2.company_id = b.company_id AND b2.status = \'active\') AS company_branch_count,
            cp.full_name AS contact_full_name_current, ra.full_name AS admin_name
     FROM modification_requests mr
     JOIN branches b ON b.id = mr.branch_id
     JOIN companies c ON c.id = b.company_id
     JOIN users u ON u.id = mr.requested_by
     LEFT JOIN contact_persons cp ON cp.id = mr.contact_id
     LEFT JOIN users ra ON ra.id = mr.reviewed_by_admin
     WHERE mr.status IN (' . implode(', ', array_fill(0, count($statusesToShow), '?')) . ')
     ORDER BY CASE WHEN mr.status = \'admin_rejected\' THEN 1 ELSE 0 END, mr.created_at ASC'
);
$stmt->execute($statusesToShow);
$queue = $stmt->fetchAll();

// For reassignment requests, old_value/new_value are raw user IDs — resolve them to
// names in one batch query so the approver sees who, not a number they'd have to look up.
$userIdsToResolve = [];
foreach ($queue as $r) {
    if ($r['field_name'] === 'assigned_user_id') {
        if ($r['old_value'] !== null && $r['old_value'] !== '') { $userIdsToResolve[] = $r['old_value']; }
        if ($r['new_value'] !== null && $r['new_value'] !== '') { $userIdsToResolve[] = $r['new_value']; }
    }
}
$userNames = [];
if (!empty($userIdsToResolve)) {
    // Unique first: the placeholder count must match the bound values, or the page crashes as soon as
    // two requests mention the same employee.
    $userIdsToResolve = array_values(array_unique($userIdsToResolve));
    $placeholders = implode(',', array_fill(0, count($userIdsToResolve), '?'));
    $nameStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
    $nameStmt->execute(array_values(array_unique($userIdsToResolve)));
    foreach ($nameStmt->fetchAll() as $u) {
        $userNames[$u['id']] = $u['full_name'];
    }
}

// Renders old_value/new_value for display: resolves to a name for reassignment
// requests, falls back to "User #<id>" if the account was later removed, otherwise
// shows the raw value as-is (branch name, address, etc).
function displayChangeValue(string $fieldName, ?string $value, array $userNames): string
{
    if ($value === null || $value === '') {
        return '(empty)';
    }
    if ($fieldName === 'assigned_user_id') {
        return $userNames[$value] ?? ('User #' . $value);
    }
    if ($fieldName === 'contact_numbers_extra') {
        return describeExtraNumbers($value) ?: '(none)';
    }
    if ($fieldName === 'contact_emails_extra') {
        return describeExtraEmails($value) ?: '(none)';
    }
    if ($fieldName === 'contact_remove' && $value === 'remove') {
        return 'Remove this contact';
    }
    return $value;
}

$pageTitle = $role === 'admin' ? 'Pending Approvals' : 'Final Approvals (Super Admin)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvals — Syspoint CRM</title>
<link rel="icon" type="image/png" href="Syspoint image circle.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --black:#080808; --red:#d71920; --red-dark:#b51218; --ink:#171717; --muted:#707070; --line:#e4e4e4; --canvas:#f7f7f7; --white:#fff; }
    * { box-sizing: border-box; }
    body { margin:0; color:var(--ink); background:var(--canvas); font-family:"DM Sans", sans-serif; }
    a { text-decoration: none; color: var(--white); }
    button { cursor: pointer; }
    .topbar { display:flex; align-items:center; justify-content:space-between; padding:18px clamp(20px,4vw,54px); border-bottom:1px solid var(--line); background:var(--ink); }
    .crumb { color:var(--muted); font-size:0.82rem; }
    .crumb strong { color:var(--white); }
    .content { max-width: 1320px; margin:0 auto; padding:20px clamp(20px,4vw,54px) 60px; }
    h1 { margin:0 0 6px; padding-top: 10px; padding-bottom: 15px; font-family:"Space Grotesk", sans-serif; font-size:clamp(1.5rem,3vw,2rem); }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid transparent; border-radius: 5px; padding: 11px 15px; color: var(--white); background: var(--red); font-size: 0.82rem; font-weight: 700; transition: background 150ms ease, transform 150ms ease; }
    .button:hover { background: var(--red-dark); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(215, 25, 32, 0.18); }
    .card { border: 1px solid var(--line); border-radius: 6px; background: var(--white); padding: 12px clamp(1px, 2vw, 10px); border-color: darkgray; overflow-x: auto; }
    table { width:100%; border-collapse:collapse; }
    th { padding:10px 12px; background:#fbfbfb; color:#858585; font-size:0.68rem; font-weight:700; text-transform:uppercase; text-align:left; }
    td { padding:12px; border-top:1px solid #eee; font-size:0.85rem; vertical-align:top; }
    td:first-child { font-weight:700; }
    table { table-layout: fixed; }
    td, th { overflow-wrap: anywhere; }
    .col-request { width: 15%; } .col-client { width: 22%; } .col-change { width: 27%; } .col-review { width: 14%; } .col-decision { width: 22%; }
    .admin-remark { display:block; margin-top:6px; color:var(--ink); font-size:0.76rem; font-weight:400; }
    .decision-form textarea { display:block; width:100%; min-height:54px; margin:0 0 6px; padding:7px 9px; border:1px solid #cfcfcf; border-radius:4px; font:inherit; font-size:0.78rem; resize:vertical; }
    .decision-form .btns { display:flex; gap:6px; flex-wrap:wrap; }
    .decision-form button { margin:0; flex:1 1 auto; white-space:nowrap; }
    button { padding: 6px 12px; cursor: pointer; margin: 0 4px 3px 0; border-radius: 4px; border: none; color: #fff; font-size: 0.8rem; font-weight: 700; }
    .approve { background: #1a7a30; }
    .approve:hover { background: #145f25; }
    .reject { background: #a32b26; }
    .reject:hover { background: #86231f; }
    tr.admin-approved td { background:#f1faf3; }
    tr.admin-approved td:first-child { box-shadow: inset 4px 0 0 #1a7a30; }
    tr.admin-rejected td { background:#fff3f2; }
    tr.admin-rejected td:first-child { box-shadow: inset 4px 0 0 #a32b26; }
    .review-badge { display:inline-block; padding:2px 9px; border-radius:10px; font-size:0.7rem; font-weight:700; white-space:nowrap; }
    .review-badge.approved { background:#dcf3e2; color:#1a7a30; }
    .review-badge.rejected { background:#fbdedc; color:#a32b26; }
    .review-badge.waiting { background:#eeeeee; color:#707070; }
    .review-by { display:block; margin-top:3px; color:var(--muted); font-size:0.72rem; font-weight:400; }
    .legend { display:flex; flex-wrap:wrap; gap:8px 16px; margin:-6px 0 18px; font-size:0.78rem; color:var(--muted); align-items:center; }
    .tag { font-size: 11px; background: #fdf1d6; color: #93650b; padding: 2px 6px; border-radius: 4px; }
    .reason-note { display:block; margin-top:5px; color:var(--muted); font-size:0.76rem; font-style:italic; }
    .notice-error { margin:0 0 18px; padding:12px 14px; border-radius:6px; font-size:0.82rem; color:#a32b26; background:#fff0f1; }
    .empty-state { border: 1px dashed #cfcfcf; padding: 44px 20px; color: var(--muted); background: var(--white); text-align: center; border-radius: 6px; }
    tr.highlight { animation: flash-highlight 2.2s ease-out; }
    @keyframes flash-highlight { 0%, 35% { background: #fdf1d6; } 100% { background: transparent; } }
    @media (max-width: 650px) {
        .topbar { padding: 16px 20px; }
        .content { padding: 20px 16px 40px; }
        th, td { padding: 10px 12px; }
    }
</style>
</head>
<body>

<header class="topbar">
    <div class="crumb">Workspace / <strong><?= htmlspecialchars($pageTitle) ?></strong></div>
    <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
</header>

<div class="content">
<h1><?= htmlspecialchars($pageTitle) ?></h1>
    <?= inboundHealthBanner($pdo, (string) $role) ?>
<?php if (!empty($_SESSION['approvals_error'])): ?>
    <p class="notice-error"><?= htmlspecialchars($_SESSION['approvals_error']) ?></p>
    <?php unset($_SESSION['approvals_error']); ?>
<?php endif; ?>
<p style="margin:0 0 18px; color:var(--muted, #707070); font-size:0.88rem;">
    <?= $role === 'admin'
        ? 'Approving a request sends it to a super admin for final approval. Nothing is changed until they approve it.'
        : 'You can approve or reject any open request, whether or not an admin has reviewed it. Approving applies the change immediately.' ?>
</p>
<?php if ($role === 'super_admin'): ?>
    <div class="legend">
        <span><span class="review-badge approved">Admin approved</span></span>
        <span><span class="review-badge rejected">Admin rejected</span> stays here until you approve it anyway or reject it to close it</span>
        <span><span class="review-badge waiting">Not reviewed</span></span>
    </div>
<?php endif; ?>

<?php if (empty($queue)): ?>
    <div class="empty-state">Nothing waiting for your review right now.</div>
<?php else: ?>
    <div class="card">
    <table>
        <colgroup>
            <col class="col-request"><col class="col-client"><col class="col-change">
            <?php if ($role === 'super_admin'): ?><col class="col-review"><?php endif; ?>
            <col class="col-decision">
        </colgroup>
        <tbody>
        <tr><th>Requested by</th><th>Client</th><th>Change</th><?php if ($role === 'super_admin'): ?><th>Admin review</th><?php endif; ?><th>Your decision</th></tr>
        <?php $convoByKey = emailThreadRepliesFor($pdo, array_map(static fn($q) => 'req-' . (int) $q['id'], $queue)); ?>
        <?php foreach ($queue as $r): ?>
            <?php
            $rowClass = '';
            if ($role === 'super_admin') {
                $rowClass = $r['status'] === 'admin_approved' ? 'admin-approved' : ($r['status'] === 'admin_rejected' ? 'admin-rejected' : '');
            }
            $overriding = $role === 'super_admin' && $r['status'] === 'admin_rejected';
            ?>
            <tr id="request-<?= (int) $r['id'] ?>"<?= $rowClass !== '' ? ' class="' . $rowClass . '"' : '' ?>>
                <td><?= htmlspecialchars($r['requested_by_name']) ?>
                    <span class="review-by"><?= htmlspecialchars(toLocalTime($r['created_at'], 'M j, Y \a\t g:i A')) ?></span>
                </td>
                <td><?php if ($r['field_name'] === 'assigned_user_id'): ?>
                        <?= htmlspecialchars($r['company_name']) ?>
                        <br><span class="tag">whole company<?= (int) $r['company_branch_count'] > 1 ? ' · ' . (int) $r['company_branch_count'] . ' branches' : '' ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($r['company_name'] . ' — ' . $r['branch_name']) ?>
                    <?php endif; ?>
                    <?php if (!empty($r['contact_id'])): ?>
                        <br><span class="tag">contact: <?= htmlspecialchars($r['contact_full_name_current'] ?? ('#' . $r['contact_id'])) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="review-by" style="margin:0 0 3px;"><?= htmlspecialchars(changeFieldLabel($r['field_name'])) ?></span>
                    <?= htmlspecialchars(displayChangeValue($r['field_name'], $r['old_value'], $userNames)) ?> &rarr; <strong><?= htmlspecialchars(displayChangeValue($r['field_name'], $r['new_value'], $userNames)) ?></strong>
                    <?php if (!empty($r['reason'])): ?>
                        <span class="reason-note">Reason: "<?= htmlspecialchars($r['reason']) ?>"</span>
                    <?php endif; ?>
                    <?= renderEmailConversation($convoByKey['req-' . (int) $r['id']] ?? [], true) ?>
                </td>
                <?php if ($role === 'super_admin'): ?>
                    <td>
                        <?php if ($r['status'] === 'admin_approved'): ?>
                            <span class="review-badge approved">Admin approved</span>
                        <?php elseif ($r['status'] === 'admin_rejected'): ?>
                            <span class="review-badge rejected">Admin rejected</span>
                        <?php else: ?>
                            <span class="review-badge waiting">Not reviewed</span>
                        <?php endif; ?>
                        <?php if (!empty($r['admin_name'])): ?><span class="review-by">by <?= htmlspecialchars($r['admin_name']) ?></span><?php endif; ?>
                        <?php if (!empty($r['admin_remark'])): ?><span class="admin-remark">"<?= htmlspecialchars($r['admin_remark']) ?>"</span><?php endif; ?>
                    </td>
                <?php endif; ?>
                <td>
                    <form method="POST" action="approvals.php" class="decision-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                        <textarea name="remark" maxlength="500" <?= $role === 'admin' ? 'required placeholder="Remark (required)"' : 'placeholder="Remark (suggested)"' ?>></textarea>
                        <div class="btns">
                            <button type="submit" name="decision" value="approve" class="approve"><?= $overriding ? 'Approve anyway' : ($role === 'admin' ? 'Pre-approve' : 'Approve') ?></button>
                            <button type="submit" name="decision" value="reject" class="reject"><?= $overriding ? 'Confirm reject' : 'Reject' ?></button>
                        </div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<script>
    var params = new URLSearchParams(window.location.search);
    var highlightId = params.get('highlight');
    if (highlightId) {
        var row = document.getElementById('request-' + highlightId);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('highlight');
        }
    }
</script>
</body>
</html>