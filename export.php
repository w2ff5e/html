<?php
require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'];
if (!in_array($role, ['admin', 'super_admin', 'special_user'], true)) {
    http_response_code(403);
    die('You do not have permission to view this page.');
}

$pdo = getDbConnection();
$userId = $_SESSION['user_id'];
requireActivePassword($pdo, $userId);
$errors = [];
$flash = null;
// Admins and the special user (board member) both ask; a super admin approves.
$isRequester = in_array($role, ['admin', 'special_user'], true);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// EXPORT_CLEAR_AFTER_DAYS lives in config.php: the cleanup runs on every request there, not just here.
const EXPORT_COOLDOWN_SECONDS = 60;
const EXPORT_NOTE_MIN_LENGTH = 10;
const EXPORT_NOTE_MAX_LENGTH = 500;

/**
 * The client export, shared by the instant super admin path and the one a super
 * admin's approval generates for an admin's request, so both always produce the
 * same file. One row per contact person: a branch with several contacts repeats
 * on several rows (keeps the sheet filterable and sortable) and a branch with
 * none still gets one row with the contact cells blank. Active branches and
 * active contacts only. Contacts are included even though all_clients.php hides
 * them from admins, because an admin's export only exists once a super admin
 * has approved it.
 */
const EXPORT_COLUMNS = [
    // [header, row key, Excel column width, type]
    ['Company', 'company_name', 28, 'text'],
    ['Branch', 'branch_name', 26, 'text'],
    ['Address', 'address', 40, 'text'],
    ['Assigned to', 'assigned_to', 22, 'text'],
    ['Last order date', 'last_order_date', 16, 'date'],
    ['Contact person', 'contact_name', 24, 'text'],
    ['Position', 'contact_position', 22, 'text'],
    ['Phone', 'contact_number', 20, 'text'],
    ['Email', 'contact_email', 32, 'text'],
    ['Categories', 'company_categories', 36, 'text'],
];

/**
 * Branches ADDED between two dates (Manila calendar days, both inclusive) — null/'' means no limit on that side.
 * created_at is stored in UTC, so a Manila day is turned into the UTC moment it starts and ends.
 */
function exportRangeBounds(?string $from, ?string $to): array
{
    $lo = $from ? gmdate('Y-m-d H:i:s', strtotime($from . ' 00:00:00 UTC') - 8 * 3600) : null;
    $hi = $to ? gmdate('Y-m-d H:i:s', strtotime($to . ' 00:00:00 UTC') + 16 * 3600) : null; // next day 00:00 Manila, exclusive
    return [$lo, $hi];
}

/** "2026-11-01" -> "Nov 1, 2026" */
function exportDateLabel(string $ymd): string
{
    return date('M j, Y', strtotime($ymd . ' 00:00:00 UTC'));
}

/** The plain-words range, or 'All clients, whenever they were added'. */
function exportRangeText(?string $from, ?string $to): string
{
    if (!$from && !$to) {
        return 'All clients, whenever they were added';
    }
    if ($from && $to) {
        return 'Clients added ' . ($from === $to ? 'on ' . exportDateLabel($from) : exportDateLabel($from) . ' to ' . exportDateLabel($to));
    }
    return $from ? 'Clients added from ' . exportDateLabel($from) . ' onward' : 'Clients added up to ' . exportDateLabel($to);
}

/** Reads the calendar fields; returns [from, to, errorOrNull]. Empty = no limit. */
function postedExportRange(array $src): array
{
    $out = [];
    foreach (['export_from', 'export_to'] as $k) {
        $v = trim((string) ($src[$k] ?? ''));
        if ($v !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false && date('Y-m-d', strtotime($v)) === $v)) {
            return [null, null, 'Pick the dates from the calendar.'];
        }
        $out[] = $v === '' ? null : $v;
    }
    if ($out[0] && $out[1] && $out[0] > $out[1]) {
        return [null, null, 'The "from" date must not be after the "to" date.'];
    }
    return [$out[0], $out[1], null];
}

function fetchClientExportRows(PDO $pdo, ?string $from = null, ?string $to = null): array
{
    [$lo, $hi] = exportRangeBounds($from, $to);
    $where = '';
    $params = [];
    if ($lo) {
        $where .= ' AND b.created_at >= :lo';
        $params['lo'] = $lo;
    }
    if ($hi) {
        $where .= ' AND b.created_at < :hi';
        $params['hi'] = $hi;
    }
    $stmt = $pdo->prepare(
        "SELECT b.id AS branch_id, c.name AS company_name, c.id AS company_id,
                b.branch_name, b.address, u.full_name AS assigned_to, o.last_order_date,
                cp.full_name AS contact_name, cp.position AS contact_position,
                cp.contact_number, cp.contact_number_type, cp.contact_number_2, cp.contact_number_2_type, cp.contact_numbers_extra, cp.contact_emails_extra,
                cp.email AS contact_email
         FROM branches b
         JOIN companies c ON c.id = b.company_id
         LEFT JOIN users u ON u.id = b.assigned_user_id
         LEFT JOIN order_dates o ON o.branch_id = b.id
         LEFT JOIN contact_persons cp ON cp.branch_id = b.id AND cp.status = 'active'
         WHERE b.status = 'active'" . $where . "
         ORDER BY c.name, b.branch_name, b.id, cp.sort_order, cp.id"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $catMap = fetchCompanyCategories($pdo, array_column($rows, 'company_id'));
    foreach ($rows as &$r) {
        // Company categories, readable, in one cell ("Uncategorized" when none are set).
        $r['company_categories'] = implode(', ', categoryLabels($catMap[(int) $r['company_id']] ?? [])) ?: 'Uncategorized';
        // Both numbers, each labelled Mobile or Telephone, in the one Phone column.
        $r['contact_number'] = formatContactNumbers([
            'contact_number' => $r['contact_number'], 'contact_number_type' => $r['contact_number_type'],
            'contact_number_2' => $r['contact_number_2'], 'contact_number_2_type' => $r['contact_number_2_type'],
            'contact_numbers_extra' => $r['contact_numbers_extra'],
        ]);
        // Primary email plus any additional ones, all in the one Email column.
        $r['contact_email'] = implode(', ', allContactEmails(['email' => $r['contact_email'], 'contact_emails_extra' => $r['contact_emails_extra']]));
    }
    unset($r);
    return $rows;
}

/** Picks the requested columns (in sheet order) from the ticked keys; unknown keys are ignored. */
function selectExportColumns(array $keys): array
{
    // Requests filed before categories replaced the description column still say 'company_description'.
    $keys = array_map(static fn($k) => $k === 'company_description' ? 'company_categories' : $k, $keys);
    return array_values(array_filter(EXPORT_COLUMNS, static fn(array $c): bool => in_array($c[1], $keys, true)));
}

/** The ticked boxes from a form post, validated against the real column list. */
function postedExportFields(): array
{
    $posted = $_POST['fields'] ?? [];
    return array_values(array_intersect(array_column(EXPORT_COLUMNS, 1), is_array($posted) ? $posted : []));
}

/**
 * Keeps only the ticked columns. When no contact column is ticked there is no
 * reason to repeat a branch once per contact person, so identical rows collapse:
 * ticking only "Company" gives one line per company name, "Company + Contact person"
 * gives each company/contact pair once, and so on.
 */
function projectExportRows(array $rows, array $columns): array
{
    $keys = array_column($columns, 1);
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($keys as $k) {
            $line[$k] = $row[$k] ?? '';
        }
        $sig = json_encode($line);
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        // Internal only (not a column): which company/branch the line belongs to, for the alternating shading.
        $line['_grp'] = ($row['company_name'] ?? '') . '|' . ($row['branch_id'] ?? '');
        $out[] = $line;
    }
    return $out;
}

/**
 * Builds the export and returns it in the form that gets stored/streamed:
 * "xlsx:" + base64 of a real Excel file, or plain CSV text if PHP's zip
 * extension isn't enabled (an .xlsx file is a zip, so there is no way to make
 * one without it). The page tells the person which one they'll get.
 */
function buildClientsExport(PDO $pdo, array $fieldKeys, ?string $from = null, ?string $to = null): string
{
    $columns = selectExportColumns($fieldKeys);
    $raw = fetchClientExportRows($pdo, $from, $to);
    $stats = [
        'range' => exportRangeText($from, $to),
        'branches' => count(array_unique(array_column($raw, 'branch_id'))),
        'contacts' => count(array_filter(array_column($raw, 'contact_name'), static fn($v): bool => $v !== null)),
    ];
    $rows = projectExportRows($raw, $columns);
    if (class_exists('ZipArchive')) {
        try {
            return 'xlsx:' . base64_encode(buildClientsXlsx($rows, $columns, $stats));
        } catch (Throwable $e) {
            error_log('XLSX export failed, falling back to CSV: ' . $e->getMessage());
        }
    }
    return buildClientsCsv($rows, $columns);
}

/** Stops a spreadsheet program treating user-typed text as a formula (CSV only; in the .xlsx everything is stored as plain text). */
function csvSafe(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && preg_match('/^(?:[=@\t\r]|[+-][^0-9\s(.,-])/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function buildClientsCsv(array $rows, array $columns = EXPORT_COLUMNS): string
{
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads accents/UTF-8 correctly
    // Explicit separator/enclosure/escape so PHP 8.4 doesn't warn about the default escape character.
    fputcsv($out, array_column($columns, 0), ',', '"', '');
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as [, $key]) {
            $line[] = csvSafe($row[$key] ?? '');
        }
        fputcsv($out, $line, ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

function xlsxEscape(?string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xlsxCell(string $ref, ?string $value, int $style): string
{
    $value = (string) $value;
    if ($value === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>'; // still styled, so banding and borders run unbroken
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEscape($value) . '</t></is></c>';
}

/** A real Excel date (so it sorts and filters as one), not text that looks like one. */
function xlsxDateCell(string $ref, ?string $value, int $dateStyle, int $textStyle): string
{
    if ($value === null || $value === '') {
        return xlsxCell($ref, '', $textStyle);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return xlsxCell($ref, $value, $textStyle);
    }
    $utc = new DateTimeZone('UTC');
    $serial = (int) (new DateTime('1899-12-30', $utc))->diff(new DateTime($value, $utc))->days;
    return '<c r="' . $ref . '" s="' . $dateStyle . '"><v>' . $serial . '</v></c>';
}

/**
 * Hand-built .xlsx (no library in this project, and none needed): a styled
 * "Clients" sheet and a small "About" sheet. Style indexes used below:
 * 1 header, 2/3 text (plain/shaded), 4/5 date (plain/shaded), 6 title, 7 label,
 * 8 muted note, 9 wrapped value. Shading alternates per branch, not per row, so
 * a branch's contact rows read as one group.
 */
function buildClientsXlsx(array $rows, array $columns = EXPORT_COLUMNS, array $stats = []): string
{
    $colCount = count($columns);
    $lastCol = chr(ord('A') + $colCount - 1);

    $sheetRows = '<row r="1" ht="26" customHeight="1">';
    foreach ($columns as $i => $col) {
        $sheetRows .= xlsxCell(chr(ord('A') + $i) . '1', $col[0], 1);
    }
    $sheetRows .= '</row>';

    $rowNum = 1;
    $shaded = 1;
    $lastGrp = null;
    $branchCount = (int) ($stats['branches'] ?? 0);
    $contactCount = (int) ($stats['contacts'] ?? 0);
    foreach ($rows as $row) {
        $grp = $row['_grp'] ?? null;
        if ($grp !== $lastGrp) {
            $lastGrp = $grp;
            $shaded = 1 - $shaded;
        }
        $rowNum++;
        $textStyle = $shaded ? 3 : 2;
        $dateStyle = $shaded ? 5 : 4;
        $sheetRows .= '<row r="' . $rowNum . '">';
        foreach ($columns as $i => [, $key, , $type]) {
            $ref = chr(ord('A') + $i) . $rowNum;
            $sheetRows .= $type === 'date'
                ? xlsxDateCell($ref, $row[$key] ?? null, $dateStyle, $textStyle)
                : xlsxCell($ref, $row[$key] ?? null, $textStyle);
        }
        $sheetRows .= '</row>';
    }

    $cols = '';
    foreach ($columns as $i => $col) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $col[2] . '" customWidth="1"/>';
    }
    $range = 'A1:' . $lastCol . $rowNum;

    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    $sheet1 = $xml . '<worksheet ' . $ns . '>'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="' . $range . '"/>'
        . '<sheetViews><sheetView showGridLines="0" tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="' . $range . '"/>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '<ignoredErrors><ignoredError sqref="' . $range . '" numberStoredAsText="1"/></ignoredErrors>'
        . '</worksheet>';

    $about = [
        [1, 'Syspoint CRM — client export', null, 6],
        [3, 'Generated', toLocalTime(gmdate('Y-m-d H:i:s'), 'Y-m-d g:i A') . ' (UTC+08:00)', 7],
        [4, 'Branches', (string) $branchCount, 7],
        [5, 'Contact persons', (string) $contactCount, 7],
        [7, 'Covers', (string) ($stats['range'] ?? 'All clients, whenever they were added'), 7],
        [6, 'Layout', 'One row per contact person. A branch with several contacts repeats on several rows, so you can filter or sort by Company or Branch. Shading alternates per branch.', 7],
        [8, 'Confidential — for internal Syspoint use only.', null, 8],
    ];
    $aboutRows = '';
    foreach ($about as [$r, $a, $b, $labelStyle]) {
        $aboutRows .= '<row r="' . $r . '">' . xlsxCell('A' . $r, $a, $labelStyle);
        if ($b !== null) {
            $aboutRows .= xlsxCell('B' . $r, $b, 9);
        }
        $aboutRows .= '</row>';
    }
    $sheet2 = $xml . '<worksheet ' . $ns . '>'
        . '<dimension ref="A1:B8"/>'
        . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols><col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="80" customWidth="1"/></cols>'
        . '<sheetData>' . $aboutRows . '</sheetData>'
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';

    $font = static fn(string $extra, string $rgb, int $size = 10): string =>
        '<font>' . $extra . '<sz val="' . $size . '"/><color rgb="' . $rgb . '"/><name val="Arial"/></font>';
    $border = static fn(string $style, string $rgb): string =>
        '<border><left/><right/><top/><bottom style="' . $style . '"><color rgb="' . $rgb . '"/></bottom><diagonal/></border>';
    $xf = static fn(int $numFmt, int $font, int $fill, int $border, string $align): string =>
        '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
        . ' applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $align . '</xf>';
    $top = '<alignment vertical="top" wrapText="1"/>';
    $topLeft = '<alignment horizontal="left" vertical="top"/>';

    $styles = $xml . '<styleSheet ' . $ns . '>'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
        . '<fonts count="5">'
        . $font('', 'FF171717') . $font('<b/>', 'FFFFFFFF') . $font('<b/>', 'FF171717', 16)
        . $font('<b/>', 'FF171717') . $font('<i/>', 'FF707070', 9)
        . '</fonts>'
        . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF171717"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F3F3"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . $border('thin', 'FFE4E4E4') . $border('medium', 'FFD71920') . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . $xf(0, 0, 0, 0, '')                                                              // 0 default
        . $xf(0, 1, 2, 2, '<alignment horizontal="left" vertical="center" wrapText="1"/>') // 1 header
        . $xf(0, 0, 0, 1, $top)                                                            // 2 text
        . $xf(0, 0, 3, 1, $top)                                                            // 3 text, shaded
        . $xf(164, 0, 0, 1, $topLeft)                                                      // 4 date
        . $xf(164, 0, 3, 1, $topLeft)                                                      // 5 date, shaded
        . $xf(0, 2, 0, 0, '')                                                              // 6 title
        . $xf(0, 3, 0, 0, '<alignment vertical="top"/>')                                   // 7 label
        . $xf(0, 4, 0, 0, '')                                                              // 8 muted note
        . $xf(0, 0, 0, 0, $top)                                                            // 9 wrapped value
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $workbook = $xml . '<workbook ' . $ns . ' xmlns:r="' . $rel . '">'
        . '<bookViews><workbookView activeTab="0"/></bookViews>'
        . '<sheets><sheet name="Clients" sheetId="1" r:id="rId1"/><sheet name="About" sheetId="2" r:id="rId2"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Clients!$A$1:$' . $lastCol . '$' . $rowNum . '</definedName></definedNames>'
        . '</workbook>';

    $pkgRel = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $files = [
        '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => $xml . '<Relationships xmlns="' . $pkgRel . '">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="' . $pkgRel . '">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet1,
        'xl/worksheets/sheet2.xml' => $sheet2,
    ];

    // ZipArchive can only write to a file, so build in a temp file and read it back.
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create the temporary export file.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish the export file.');
        }
        $binary = file_get_contents($tmp);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('The export file came out empty.');
        }
        return $binary;
    } finally {
        @unlink($tmp);
    }
}

/** Sends a stored export (see buildClientsExport) to the browser as a download. */
function streamExport(string $stored): void
{
    $date = toLocalTime(gmdate('Y-m-d H:i:s'), 'Y-m-d');
    header('Cache-Control: no-store');
    if (str_starts_with($stored, 'xlsx:')) {
        $binary = base64_decode(substr($stored, 5), true);
        if ($binary === false) {
            http_response_code(500);
            die('The stored export is damaged. Please request a new one.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="syspoint_clients_' . $date . '.xlsx"');
        header('Content-Length: ' . strlen($binary));
        echo $binary;
        return;
    }
    // An export approved before this change (or built without the zip extension) is plain CSV.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="syspoint_clients_' . $date . '.csv"');
    echo $stored;
}

// Approved exports nobody downloaded are cleared after a few days by pruneExpiredExports()
// in config.php (runs on every request via runHousekeeping()).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $formAction = $_POST['form_action'] ?? '';

    // ---- Admin / special user: request an export of the ticked columns ----
    if (empty($errors) && $formAction === 'request' && $isRequester) {
        $fields = postedExportFields();
        [$rFrom, $rTo, $rangeErr] = postedExportRange($_POST);
        $note = trim((string) ($_POST['export_note'] ?? ''));
        $openStmt = $pdo->prepare(
            "SELECT id FROM approval_requests
             WHERE type = 'export' AND requested_by = :uid
               AND (status = 'pending' OR (status = 'approved' AND downloaded_at IS NULL AND result IS NOT NULL))"
        );
        $openStmt->execute(['uid' => $userId]);
        if (empty($fields)) {
            $errors[] = 'Tick at least one thing to export.';
        } elseif ($rangeErr) {
            $errors[] = $rangeErr;
        } elseif (mb_strlen($note) < EXPORT_NOTE_MIN_LENGTH) {
            $errors[] = 'Add a note on why you need this export (at least ' . EXPORT_NOTE_MIN_LENGTH . ' characters) so the super admin knows what it is for.';
        } elseif (mb_strlen($note) > EXPORT_NOTE_MAX_LENGTH) {
            $errors[] = 'Please keep the note under ' . EXPORT_NOTE_MAX_LENGTH . ' characters.';
        } elseif (exportMatchCount($pdo, $rFrom, $rTo) === 0) {
            $errors[] = 'No active clients were added in that date range, so there would be nothing to export.';
        } elseif ($openStmt->fetch()) {
            $errors[] = 'You already have an export pending or waiting to be downloaded. Cancel it first if you want to change what is in it.';
        } else {
            // A cancelled request doesn't count towards the cooldown — the usual reason to
            // cancel is to ask again with different boxes ticked.
            $cooldownStmt = $pdo->prepare(
                "SELECT resolved_at FROM approval_requests
                 WHERE type = 'export' AND requested_by = :uid AND resolved_at IS NOT NULL AND cancelled_at IS NULL
                 ORDER BY resolved_at DESC LIMIT 1"
            );
            $cooldownStmt->execute(['uid' => $userId]);
            $lastResolved = $cooldownStmt->fetchColumn();
            if ($lastResolved && (time() - strtotime($lastResolved)) < EXPORT_COOLDOWN_SECONDS) {
                $errors[] = 'Please wait a bit before requesting another export.';
            } else {
                $pdo->prepare("INSERT INTO approval_requests (type, requested_by, payload, status) VALUES ('export', :uid, :payload, 'pending')")
                    ->execute(['uid' => $userId, 'payload' => json_encode(['fields' => $fields, 'note' => $note, 'from' => $rFrom, 'to' => $rTo])]);
                $requestId = (int) $pdo->lastInsertId();
                $labels = implode(', ', array_column(selectExportColumns($fields), 0));
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'export_request', 'approval_requests', :tid, :details)")
                    ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => 'Requested a client export: ' . $labels . ' | ' . exportRangeText($rFrom, $rTo) . ' | Note: ' . $note]);
                notifyRoles($pdo, ['super_admin'], 'export_pending', null, 'approval_requests', $requestId, truncateForNotification('An export request from ' . userFullName($pdo, $userId) . ' is waiting for your approval (' . $labels . '; ' . exportRangeText($rFrom, $rTo) . ').'));
                $flash = 'Export requested — you\'ll be notified once it\'s approved.';
            }
        }
    }

    // ---- Admin / special user: cancel their own request (waiting for approval, or approved but not yet downloaded) ----
    if (empty($errors) && $formAction === 'cancel' && $isRequester) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $cancel = $pdo->prepare(
            "UPDATE approval_requests
             SET status = 'rejected', result = NULL, resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP, cancelled_at = CURRENT_TIMESTAMP
             WHERE id = :id AND requested_by = :uid2 AND type = 'export'
               AND (status = 'pending' OR (status = 'approved' AND downloaded_at IS NULL))"
        );
        $cancel->execute(['uid' => $userId, 'uid2' => $userId, 'id' => $requestId]);
        if ($cancel->rowCount() === 1) {
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'export_cancel', 'approval_requests', :tid, 'Cancelled their own export request')")
                ->execute(['uid' => $userId, 'tid' => $requestId]);
            notifyRoles($pdo, ['super_admin'], 'export_reviewed', null, 'approval_requests', $requestId, truncateForNotification(userFullName($pdo, $userId) . ' cancelled their export request.'));
            $flash = 'Export request cancelled.';
        } else {
            $errors[] = 'That request can no longer be cancelled (it may already have been downloaded or decided).';
        }
    }

    // ---- Admin / special user: download their approved, not-yet-downloaded export ----
    if (empty($errors) && $formAction === 'download' && $isRequester) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT * FROM approval_requests
             WHERE id = :id AND requested_by = :uid AND type = 'export' AND status = 'approved'
               AND downloaded_at IS NULL AND result IS NOT NULL"
        );
        $stmt->execute(['id' => $requestId, 'uid' => $userId]);
        $req = $stmt->fetch();

        if (!$req) {
            $errors[] = 'That export is no longer available to download.';
        } else {
            $pdo->prepare('UPDATE approval_requests SET downloaded_at = CURRENT_TIMESTAMP, result = NULL WHERE id = :id')
                ->execute(['id' => $requestId]);
            $dlPayload = json_decode((string) $req['payload'], true) ?: [];
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'export', 'approval_requests', :tid, :details)")
                ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => 'Downloaded approved export | ' . exportRangeText($dlPayload['from'] ?? null, $dlPayload['to'] ?? null)]);
            streamExport($req['result']);
            exit;
        }
    }

    // ---- Super admin: instant direct export of the ticked columns ----
    if (empty($errors) && $formAction === 'direct' && $role === 'super_admin') {
        $fields = postedExportFields();
        [$rFrom, $rTo, $rangeErr] = postedExportRange($_POST);
        if (empty($fields)) {
            $errors[] = 'Tick at least one thing to export.';
        } elseif ($rangeErr) {
            $errors[] = $rangeErr;
        } elseif (exportMatchCount($pdo, $rFrom, $rTo) === 0) {
            $errors[] = 'No active clients were added in that date range, so there would be nothing to export.';
        } else {
            $stored = buildClientsExport($pdo, $fields, $rFrom, $rTo);
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, details) VALUES (:uid, 'export', 'branches', :details)")
                ->execute(['uid' => $userId, 'details' => 'Exported clients: ' . implode(', ', array_column(selectExportColumns($fields), 0)) . ' | ' . exportRangeText($rFrom, $rTo)]);
            streamExport($stored);
            exit;
        }
    }

    // ---- Super admin: approve / reject a pending export request ----
    if (empty($errors) && in_array($formAction, ['approve', 'reject'], true) && $role === 'super_admin') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM approval_requests WHERE id = :id AND type = 'export' AND status = 'pending'");
        $stmt->execute(['id' => $requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $errors[] = 'That request is no longer pending (it may have been cancelled).';
        } else {
            if ($formAction === 'approve') {
                // Requests made before column-picking existed have no payload: they get every column.
                $payload = json_decode((string) $req['payload'], true);
                $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : array_column(EXPORT_COLUMNS, 1);
                $stored = buildClientsExport($pdo, $fields, $payload['from'] ?? null, $payload['to'] ?? null);
                $pdo->prepare("UPDATE approval_requests SET status = 'approved', result = :result, resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'")
                    ->execute(['result' => $stored, 'uid' => $userId, 'id' => $requestId]);
                $detail = 'Approved export request — ready to download';
            } else {
                $pdo->prepare("UPDATE approval_requests SET status = 'rejected', resolved_by = :uid, resolved_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'")
                    ->execute(['uid' => $userId, 'id' => $requestId]);
                $detail = 'Rejected export request';
            }
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, details) VALUES (:uid, 'export_review', 'approval_requests', :tid, :details)")
                ->execute(['uid' => $userId, 'tid' => $requestId, 'details' => $detail]);
            notifyUser($pdo, (int) $req['requested_by'], 'export_reviewed', null, 'approval_requests', $requestId, $detail);
            $flash = 'Done.';
        }
    }
}

/** "Company, Contact person, Email" — what a stored request asked for (all columns for old requests with no payload). */
function exportFieldSummary(?string $payloadJson): string
{
    $payload = json_decode((string) $payloadJson, true);
    $keys = is_array($payload['fields'] ?? null) ? $payload['fields'] : array_column(EXPORT_COLUMNS, 1);
    return implode(', ', array_column(selectExportColumns($keys), 0));
}

/** Request details for the page: the date range and the requester's note (old requests have neither). */
function exportRequestExtras(?string $payloadJson): array
{
    $p = json_decode((string) $payloadJson, true) ?: [];
    return ['range' => exportRangeText($p['from'] ?? null, $p['to'] ?? null), 'note' => trim((string) ($p['note'] ?? ''))];
}

/** How many active branches fall in the range (an empty file is never produced). */
function exportMatchCount(PDO $pdo, ?string $from, ?string $to): int
{
    [$lo, $hi] = exportRangeBounds($from, $to);
    $sql = "SELECT COUNT(*) FROM branches WHERE status = 'active'" . ($lo ? ' AND created_at >= :lo' : '') . ($hi ? ' AND created_at < :hi' : '');
    $params = array_filter(['lo' => $lo, 'hi' => $hi]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** This person's most recent export (any kind), so the calendar can say when they last took one. */
function lastExportFor(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT created_at, details FROM audit_logs WHERE user_id = :u AND action = 'export' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['u' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $local = toLocalTime((string) $row['created_at'], 'Y-m-d');
    return ['date' => $local, 'when' => toLocalTime((string) $row['created_at'], 'M j, Y g:i A'), 'covered' => trim((string) (explode(' | ', (string) $row['details'])[1] ?? ''))];
}

/** The calendar: pick the days to export, plus a reminder of the last export so the same rows are not taken twice. */
function renderRangePicker(?array $last, string $id): void
{
    ?>
    <div class="range" id="<?= htmlspecialchars($id) ?>">
        <p class="range-title"><strong>Which clients?</strong> Pick the dates they were added, or leave both empty for everyone.</p>
        <?php if ($last): ?>
            <p class="range-last">Your last export was on <strong><?= htmlspecialchars($last['when']) ?></strong><?= $last['covered'] !== '' ? ' (' . htmlspecialchars($last['covered']) . ')' : '' ?>.
                <button type="button" class="link-btn range-since" data-date="<?= htmlspecialchars($last['date']) ?>">Only clients added since then</button></p>
        <?php else: ?>
            <p class="range-last">You haven't exported before.</p>
        <?php endif; ?>
        <div class="range-row">
            <label>From <input type="date" name="export_from" class="range-from"></label>
            <label>To <input type="date" name="export_to" class="range-to"></label>
            <button type="button" class="link-btn range-clear">Clear dates</button>
        </div>
    </div>
    <?php
}

/**
 * The column checkboxes. Everything starts UNticked, and "Select all" ticks the lot;
 * unticking any single box unticks "Select all" again (and ticking every box by hand
 * ticks it). $id keeps the ids unique if the picker appears twice on a page.
 */
function renderFieldPicker(string $id): void
{
    ?>
    <div class="picker" id="<?= htmlspecialchars($id) ?>">
        <label class="pick pick-all"><input type="checkbox" class="pick-all-box"> <strong>Select all</strong></label>
        <div class="pick-grid">
            <?php foreach (EXPORT_COLUMNS as [$label, $key]): ?>
                <label class="pick"><input type="checkbox" name="fields[]" value="<?= htmlspecialchars($key) ?>" class="pick-box"> <?= htmlspecialchars($label) ?></label>
            <?php endforeach; ?>
        </div>
        <p class="pick-hint">Tick what should be in the file. With no contact columns ticked, each row appears once instead of once per contact person.</p>
    </div>
    <?php
}

// An .xlsx is a zip file, so without PHP's zip extension the export falls back to CSV.
$excelAvailable = class_exists('ZipArchive');
$downloadLabel = $excelAvailable ? 'Download Excel file' : 'Download CSV';

$myRequest = null;
if ($isRequester) {
    $stmt = $pdo->prepare(
        "SELECT * FROM approval_requests WHERE type = 'export' AND requested_by = :uid
         AND (status = 'pending' OR (status = 'approved' AND downloaded_at IS NULL AND result IS NOT NULL))
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute(['uid' => $userId]);
    $myRequest = $stmt->fetch() ?: null;
}

$lastExport = lastExportFor($pdo, (int) $userId);
$pendingQueue = [];
if ($role === 'super_admin') {
    $stmt = $pdo->prepare(
        "SELECT ar.*, u.full_name AS requester_name FROM approval_requests ar
         JOIN users u ON u.id = ar.requested_by
         WHERE ar.type = 'export' AND ar.status = 'pending'
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
<title>Export data — Syspoint CRM</title>
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
    .content { max-width:640px; margin:0 auto; padding:30px clamp(20px,4vw,54px) 60px; }
    h1 { margin:0 0 6px; font-family:"Space Grotesk", sans-serif; font-size:clamp(1.5rem,3vw,2rem); }
    .sub { margin:0 0 24px; color:var(--muted); font-size:0.88rem; }
    .errors, .flash { margin:0 0 18px; padding:12px 14px; border-radius:6px; font-size:0.82rem; list-style:none; }
    .errors { color:#a32b26; background:#fff0f1; }
    .flash { color:#1a7a30; background:#e3f5e6; }
    .button { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1px solid transparent; border-radius:5px; padding:11px 15px; color:var(--white); background:var(--red); font-size:0.82rem; font-weight:700; }
    .button:hover { background:var(--red-dark); }
    .card { border:1px solid var(--line); border-radius:6px; background:var(--white); padding:22px clamp(18px,4vw,28px); margin-bottom:20px; }
    .card p { margin:0 0 14px; font-size:0.86rem; color:#444; }
    .queue-item { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 0; border-top:1px solid #eee; }
    .queue-item:first-child { border-top:0; }
    .queue-item .who { font-size:0.85rem; }
    .queue-item .who span { display:block; color:var(--muted); font-size:0.74rem; margin-top:2px; }
    .queue-actions { display:flex; gap:8px; }
    .approve-btn, .reject-btn { border:0; border-radius:5px; padding:7px 12px; font-size:0.78rem; font-weight:700; color:#fff; }
    .approve-btn { background:#1a7a30; }
    .reject-btn { background:#a32b26; }
    .status-tag { display:inline-block; padding:2px 9px; border-radius:10px; font-size:0.72rem; font-weight:700; margin-bottom:10px; }
    .status-tag.pending { color:#93650b; background:#fdf1d6; }
    .status-tag.ready { color:#1a7a30; background:#e3f5e6; }
    .picker { margin:0 0 16px; }
    .pick { display:flex; align-items:center; gap:8px; font-size:0.86rem; padding:5px 0; cursor:pointer; }
    .pick-all { padding-bottom:8px; margin-bottom:6px; border-bottom:1px solid var(--line); }
    .pick-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 18px; }
    .pick-hint { margin:8px 0 0 !important; color:var(--muted) !important; font-size:0.76rem !important; }
    .cancel-btn { border:1px solid #cfcfcf; border-radius:5px; padding:10px 14px; background:var(--white); color:#a32b26; font-size:0.8rem; font-weight:700; }
    .cancel-btn:hover { background:#fdeceb; }
    .row-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .range { margin:0 0 16px; padding:12px 14px; border:1px solid var(--line); border-radius:6px; background:#fafafa; }
    .range p { margin:0 0 8px !important; font-size:0.82rem !important; }
    .range-last { color:var(--muted) !important; }
    .range-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; font-size:0.82rem; }
    .range-row input { font:inherit; padding:6px 8px; border:1px solid #cfcfcf; border-radius:5px; }
    .link-btn { border:0; background:none; padding:0; color:var(--red); font-size:0.8rem; font-weight:700; text-decoration:underline; }
    .note-label { display:block; margin:0 0 6px; font-size:0.84rem; }
    textarea#export_note { width:100%; margin:0 0 14px; padding:9px 10px; border:1px solid #cfcfcf; border-radius:5px; font:inherit; font-size:0.86rem; resize:vertical; }
    @media (max-width:520px) { .pick-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<header class="topbar">
    <div class="crumb">Workspace / <strong>Export data</strong></div>
    <?php if ($role === 'special_user'): ?>
        <div style="display:flex; gap:10px;"><div class="button"><a href="notifications.php">Notifications</a></div><div class="button"><a href="logout.php">Log out</a></div></div>
    <?php else: ?>
        <div class="button"><a href="dashboard.php">Back to dashboard</a></div>
    <?php endif; ?>
</header>
<div class="content">
    <h1>Export data</h1>
    <p class="sub"><?= $role === 'super_admin' ? 'Downloads instantly — you don\'t need anyone\'s approval.' : 'Your export needs a super admin\'s approval before it\'s ready to download. You can cancel it any time before you download it.' ?></p>

    <?php if (!empty($errors)): ?>
        <ul class="errors"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($flash): ?><p class="flash"><?= htmlspecialchars($flash) ?></p><?php endif; ?>

    <?php if ($role === 'super_admin'): ?>
        <div class="card">
            <p>Choose what to include from the active clients, then download it straight away.</p>
            <?php if (!$excelAvailable): ?><p style="color:#93650b;">PHP's zip extension isn't enabled on this server, so this downloads as a plain CSV instead of a styled Excel file.</p><?php endif; ?>
            <form method="POST" action="export.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="form_action" value="direct">
                <?php renderFieldPicker('pick-direct'); ?>
                <?php renderRangePicker($lastExport, 'range-direct'); ?>
                <button class="button" type="submit"><?= htmlspecialchars($downloadLabel) ?></button>
            </form>
        </div>

        <div class="card">
            <p><strong>Pending export requests</strong></p>
            <?php if (empty($pendingQueue)): ?>
                <p style="color:var(--muted);">Nothing waiting right now.</p>
            <?php else: ?>
                <?php foreach ($pendingQueue as $q): ?>
                    <div class="queue-item">
                        <div class="who"><?= htmlspecialchars($q['requester_name']) ?><span>Requested <?= htmlspecialchars(toLocalTime($q['created_at'])) ?></span><span>Wants: <?= htmlspecialchars(exportFieldSummary($q['payload'])) ?></span><?php $qx = exportRequestExtras($q['payload']); ?><span>Covers: <?= htmlspecialchars($qx['range']) ?></span><?php if ($qx['note'] !== ''): ?><span>Note: <?= htmlspecialchars($qx['note']) ?></span><?php endif; ?></div>
                        <div class="queue-actions">
                            <form method="POST" action="export.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="form_action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $q['id'] ?>"><button class="approve-btn" type="submit">Approve</button></form>
                            <form method="POST" action="export.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="form_action" value="reject"><input type="hidden" name="request_id" value="<?= (int) $q['id'] ?>"><button class="reject-btn" type="submit">Reject</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <?php if ($myRequest && $myRequest['status'] === 'pending'): ?>
                <span class="status-tag pending">Waiting for approval</span>
                <p>Requested <?= htmlspecialchars(toLocalTime($myRequest['created_at'])) ?>. You'll get a notification once a super admin reviews it.</p>
                <p><strong>Includes:</strong> <?= htmlspecialchars(exportFieldSummary($myRequest['payload'])) ?></p>
                <p><strong>Covers:</strong> <?= htmlspecialchars(exportRequestExtras($myRequest['payload'])['range']) ?></p>
                <form method="POST" action="export.php" onsubmit="return confirm('Cancel this export request?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="form_action" value="cancel">
                    <input type="hidden" name="request_id" value="<?= (int) $myRequest['id'] ?>">
                    <button class="cancel-btn" type="submit">Cancel request</button>
                </form>
            <?php elseif ($myRequest && $myRequest['status'] === 'approved'): ?>
                <span class="status-tag ready">Ready to download</span>
                <p>Approved <?= htmlspecialchars(toLocalTime($myRequest['resolved_at'])) ?>. This file can only be downloaded once.</p>
                <p><strong>Includes:</strong> <?= htmlspecialchars(exportFieldSummary($myRequest['payload'])) ?></p>
                <p><strong>Covers:</strong> <?= htmlspecialchars(exportRequestExtras($myRequest['payload'])['range']) ?></p>
                <div class="row-actions">
                    <form method="POST" action="export.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="form_action" value="download">
                        <input type="hidden" name="request_id" value="<?= (int) $myRequest['id'] ?>">
                        <button class="button" type="submit"><?= htmlspecialchars($downloadLabel) ?></button>
                    </form>
                    <form method="POST" action="export.php" onsubmit="return confirm('Cancel this approved export? The file will be discarded.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="form_action" value="cancel">
                        <input type="hidden" name="request_id" value="<?= (int) $myRequest['id'] ?>">
                        <button class="cancel-btn" type="submit">Cancel</button>
                    </form>
                </div>
            <?php else: ?>
                <p>Request an <?= $excelAvailable ? 'Excel' : 'CSV' ?> export of active clients. Choose what to include; a super admin needs to approve it before it's ready.</p>
                <form method="POST" action="export.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="form_action" value="request">
                    <?php renderFieldPicker('pick-request'); ?>
                    <?php renderRangePicker($lastExport, 'range-request'); ?>
                    <label class="note-label" for="export_note"><strong>Why do you need this export?</strong> (required)</label>
                    <textarea id="export_note" name="export_note" rows="3" required minlength="<?= EXPORT_NOTE_MIN_LENGTH ?>" maxlength="<?= EXPORT_NOTE_MAX_LENGTH ?>" placeholder="e.g. Email blast to Gardenia branches for the December promo"></textarea>
                    <button class="button" type="submit">Request export</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    // "Select all" mirrors the individual boxes: ticking it ticks everything, unticking any one
    // box unticks it, and ticking every box by hand ticks it.
    document.querySelectorAll('.picker').forEach(function (picker) {
        var all = picker.querySelector('.pick-all-box');
        var boxes = Array.prototype.slice.call(picker.querySelectorAll('.pick-box'));
        all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); });
        boxes.forEach(function (b) {
            b.addEventListener('change', function () { all.checked = boxes.every(function (x) { return x.checked; }); });
        });
    });
    document.querySelectorAll('.range').forEach(function (box) {
        var from = box.querySelector('.range-from'), to = box.querySelector('.range-to');
        var today = new Date(Date.now() + 8 * 3600 * 1000).toISOString().slice(0, 10); // Manila date
        from.max = to.max = today;
        var since = box.querySelector('.range-since');
        if (since) { since.addEventListener('click', function () { from.value = since.dataset.date; to.value = today; }); }
        box.querySelector('.range-clear').addEventListener('click', function () { from.value = ''; to.value = ''; });
        from.addEventListener('change', function () { if (to.value && from.value > to.value) { to.value = from.value; } });
    });
</script>
</body>
</html>