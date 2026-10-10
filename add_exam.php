<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$user      = $_SESSION['user'];
$userId    = $user['user_id'] ?? $user['id'] ?? '';
$userType  = $user['user_type_id'] ?? '';
$orgCode   = $user['org_code'] ?? '';
$brCode    = $user['br_code'] ?? '';
$canInsert  = $_SESSION['user']['can_insert']  ?? 0;
$canEdit    = $_SESSION['user']['can_edit']    ?? 0;
$canDelete  = $_SESSION['user']['can_delete']  ?? 0;
$canApprove = $_SESSION['user']['can_approve'] ?? 0;

$message = "";
$editRefExam = null;

// =====================================================
// EXPORT: Excel (.xls) or CSV
// =====================================================
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv'], true)) {
    $stmt = $pdo->prepare("
        SELECT 
            referance_exam_id,
            r_exam_name,
            r_exam_taker,
            r_exam_date,
            r_exam_year,
            entry_user,
            entry_date,
            IFNULL(authorized_status, 'N') AS authorized_status
        FROM referance_exams
        WHERE org_code = :org_code AND br_code = :br_code
        ORDER BY referance_exam_id DESC
    ");
    $stmt->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $filename = 'reference_exams_' . date('Y-m-d_H-i') . ($_GET['export'] === 'csv' ? '.csv' : '.xls');

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'referance_exam_id',
            'r_exam_name',
            'r_exam_taker',
            'r_exam_date',
            'r_exam_year',
            'entry_user',
            'entry_date',
            'authorized_status'
        ]);

        foreach ($rows as $r) {
            fputcsv($output, [
                $r['referance_exam_id'],
                $r['r_exam_name'],
                $r['r_exam_taker'] ?? '',
                !empty($r['r_exam_date']) ? date('d-m-Y', strtotime($r['r_exam_date'])) : '',
                $r['r_exam_year'] ?? '',
                $r['entry_user'] ?? '',
                !empty($r['entry_date']) ? date('d-m-Y', strtotime($r['entry_date'])) : '',
                $r['authorized_status'] === 'Y' ? 'Authorized' : 'Pending'
            ]);
        }
        fclose($output);
        exit();
    } else {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF";
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="UTF-8"></head><body>';
        echo '<table border="1" cellpadding="6" cellspacing="0">';
        echo '<thead><tr style="background:#00693e;color:#fff;font-weight:bold;">';
        echo '<th>referance_exam_id</th>';
        echo '<th>r_exam_name</th>';
        echo '<th>r_exam_taker</th>';
        echo '<th>r_exam_date</th>';
        echo '<th>r_exam_year</th>';
        echo '<th>entry_user</th>';
        echo '<th>entry_date</th>';
        echo '<th>authorized_status</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $statusText = $r['authorized_status'] === 'Y' ? 'Authorized' : 'Pending';
            $statusColor = $r['authorized_status'] === 'Y' ? '#e4f1e9' : '#fff8e1';

            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['referance_exam_id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['r_exam_name']) . '</td>';
            echo '<td>' . htmlspecialchars($r['r_exam_taker'] ?? '') . '</td>';
            echo '<td>' . (!empty($r['r_exam_date']) ? date('d-m-Y', strtotime($r['r_exam_date'])) : '') . '</td>';
            echo '<td>' . htmlspecialchars($r['r_exam_year'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['entry_user'] ?? '') . '</td>';
            echo '<td>' . (!empty($r['entry_date']) ? date('d-m-Y', strtotime($r['entry_date'])) : '') . '</td>';
            echo '<td style="background:' . $statusColor . ';">' . $statusText . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
        exit();
    }
}

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $refId      = $_POST['referance_exam_id'] ?? '';
    $examName   = trim($_POST['r_exam_name'] ?? '');
    $examTaker  = trim($_POST['r_exam_taker'] ?? '');
    $examDate   = trim($_POST['r_exam_date'] ?? '');
    $examYear   = trim($_POST['r_exam_year'] ?? '');
    $authStatus = trim($_POST['authorized_status'] ?? 'N');

    if ($examName === '') {
        $message = "<div class='alert alert-danger text-center'>Error: Exam name is required.</div>";
    } else {
        if ($refId) {
            if ($canEdit) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM referance_exams 
                                        WHERE r_exam_name = :name AND referance_exam_id != :id");
                $check->execute(['name' => $examName, 'id' => $refId]);

                if ($check->fetchColumn() > 0) {
                    $message = "<div class='alert alert-danger text-center'>Error: Exam name already exists!</div>";
                } else {
                    $stmt = $pdo->prepare("UPDATE referance_exams 
                                           SET r_exam_name   = :name,
                                               r_exam_taker  = :taker,
                                               r_exam_date   = :edate,
                                               r_exam_year   = :eyear,
                                               authorized_status = :auth,
                                               entry_user = :user,
                                               entry_date = CURDATE()
                                           WHERE referance_exam_id = :id AND org_code = :org_code AND br_code = :br_code");
                    $stmt->execute([
                        'name'     => $examName,
                        'taker'    => $examTaker ?: null,
                        'edate'    => $examDate  ?: null,
                        'eyear'    => $examYear  ?: null,
                        'auth'     => $authStatus ?: 'N',
                        'user'     => $userId,
                        'id'       => $refId,
                        'org_code' => $orgCode,
                        'br_code'  => $brCode
                    ]);
                    $message = "<div class='alert alert-success text-center'>Reference Exam updated successfully!</div>";
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>You do not have permission to edit.</div>";
            }
        } else {
            if ($canInsert) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM referance_exams 
                                        WHERE r_exam_name = :name 
                                        AND org_code = :org_code AND br_code = :br_code");
                $check->execute([
                    'name'     => $examName,
                    'org_code' => $orgCode,
                    'br_code'  => $brCode
                ]);

                if ($check->fetchColumn() > 0) {
                    $message = "<div class='alert alert-danger text-center'>Error: Exam already exists!</div>";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO referance_exams 
                        (r_exam_name, r_exam_taker, r_exam_date, r_exam_year,
                         entry_user, entry_date, org_code, br_code, authorized_status)
                        VALUES 
                        (:name, :taker, :edate, :eyear,
                         :user, CURDATE(), :org_code, :br_code, :auth)");
                    $stmt->execute([
                        'name'     => $examName,
                        'taker'    => $examTaker ?: null,
                        'edate'    => $examDate  ?: null,
                        'eyear'    => $examYear  ?: null,
                        'user'     => $userId,
                        'org_code' => $orgCode,
                        'br_code'  => $brCode,
                        'auth'     => $authStatus ?: 'N'
                    ]);
                    $message = "<div class='alert alert-success text-center'>Reference Exam added successfully!</div>";
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>You do not have permission to add.</div>";
            }
        }
    }

    header("Location: add_exam.php");
    exit();
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM referance_exams 
                           WHERE referance_exam_id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'id'       => $_GET['edit'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    $editRefExam = $stmt->fetch();

    if (!$editRefExam) {
        $message = "<div class='alert alert-danger text-center'>Error: Record does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve']) && $canApprove) {
    $stmt = $pdo->prepare("UPDATE referance_exams 
                           SET authorized_status = 'Y'
                           WHERE referance_exam_id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: add_exam.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("DELETE FROM referance_exams 
                           WHERE referance_exam_id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: add_exam.php");
    exit();
}

// ----------------------
// Fetch All Reference Exams
// ----------------------
$stmt = $pdo->prepare("
    SELECT * FROM referance_exams
    WHERE org_code = :org_code AND br_code = :br_code
    ORDER BY referance_exam_id DESC
");
$stmt->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$refExams = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reference Exam Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .search-highlight {
            background-color: #fff3cd;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
        }
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
        .search-stats {
            font-size: 14px;
            color: #6c757d;
            margin-top: 8px;
        }
        .export-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13.5px;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: transform .15s, box-shadow .2s, background .2s;
            border: 0;
            cursor: pointer;
        }
        .export-btn:hover {
            transform: translateY(-2px);
        }
        .export-excel {
            background: #1d6f42;
            color: #fff;
        }
        .export-excel:hover {
            background: #155d36;
            box-shadow: 0 8px 18px -6px #1d6f42;
            color: #fff;
        }
        .export-csv {
            background: #0d6efd;
            color: #fff;
        }
        .export-csv:hover {
            background: #0b5ed7;
            box-shadow: 0 8px 18px -6px #0d6efd;
            color: #fff;
        }
        .export-print {
            background: #6c757d;
            color: #fff;
        }
        .export-print:hover {
            background: #5a6268;
            box-shadow: 0 8px 18px -6px #6c757d;
            color: #fff;
        }
        .export-count {
            font-size: 13px;
            color: #6c757d;
            margin-left: auto;
        }
        .col-ref-id {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #00693e;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Reference Exam Management</h3>
    <?= $message ?>

    <?php if ($canInsert || $editRefExam): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post" id="refExamForm">
                <input type="hidden" name="referance_exam_id" value="<?= htmlspecialchars($editRefExam['referance_exam_id'] ?? '') ?>">

                <div class="mb-3">
                    <label>Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="r_exam_name" class="form-control" required
                           value="<?= htmlspecialchars($editRefExam['r_exam_name'] ?? '') ?>"
                           placeholder="e.g., 41th BCS, 45th BCS">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Exam Taker</label>
                        <input type="text" name="r_exam_taker" class="form-control"
                               value="<?= htmlspecialchars($editRefExam['r_exam_taker'] ?? '') ?>"
                               placeholder="e.g., BPSC, Bank, Ministry">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Exam Date</label>
                        <input type="date" name="r_exam_date" class="form-control"
                               value="<?= htmlspecialchars($editRefExam['r_exam_date'] ?? '') ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Exam Year</label>
                        <input type="number" name="r_exam_year" class="form-control"
                               min="1900" max="2100"
                               value="<?= htmlspecialchars($editRefExam['r_exam_year'] ?? '') ?>"
                               placeholder="e.g., 2024">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Authorized Status <span class="text-danger">*</span></label>
                        <select name="authorized_status" class="form-select" required>
                            <option value="N" <?= (($editRefExam['authorized_status'] ?? 'N') === 'N') ? 'selected' : '' ?>>
                                Not Authorized
                            </option>
                            <option value="Y" <?= (($editRefExam['authorized_status'] ?? '') === 'Y') ? 'selected' : '' ?>>
                                Authorized
                            </option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?= $editRefExam ? 'Update Reference Exam' : 'Add Reference Exam' ?>
                </button>
                <?php if ($editRefExam): ?>
                    <a href="add_exam.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Global Dynamic Search -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <label class="form-label fw-bold">🔍 Global Search</label>
                    <input type="text" id="globalSearch" class="form-control form-control-lg" 
                           placeholder="Search by referance_exam_id, Exam Name, Taker, Year, Status..." 
                           autocomplete="off">
                    <div class="search-stats" id="searchStats"></div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" id="clearSearch" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Clear Search
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Toolbar -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <div class="export-bar">
                <strong style="color:#00693e;">
                    <i class="bi bi-download"></i> Export Data:
                </strong>

                <a href="?export=excel" class="export-btn export-excel">
                    <i class="bi bi-file-earmark-excel-fill"></i> Download Excel
                </a>

                <a href="?export=csv" class="export-btn export-csv">
                    <i class="bi bi-filetype-csv"></i> Download CSV
                </a>

                <button type="button" class="export-btn export-print" onclick="window.print();">
                    <i class="bi bi-printer-fill"></i> Print
                </button>

                <span class="export-count">
                    <i class="bi bi-database"></i>
                    <?= count($refExams) ?> record<?= count($refExams) !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle" id="refExamsTable">
            <thead class="table-dark">
                <tr>
                    <th style="width: 50px;">SL</th>
                    <th style="width: 130px;">referance_exam_id</th>
                    <th>Exam Name</th>
                    <th>Exam Taker</th>
                    <th>Date</th>
                    <th style="width: 80px;">Year</th>
                    <th style="width: 130px;">Status</th>
                    <th style="width: 260px;">Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php 
                $sl = 1;
                foreach ($refExams as $ref): 
                    $searchData = strtolower(implode(' ', [
                        $ref['referance_exam_id'],
                        $ref['r_exam_name'] ?? '',
                        $ref['r_exam_taker'] ?? '',
                        $ref['r_exam_year'] ?? '',
                        !empty($ref['r_exam_date']) ? date('d M Y', strtotime($ref['r_exam_date'])) : '',
                        $ref['authorized_status'] === 'Y' ? 'authorized' : 'pending'
                    ]));
                ?>
                    <tr data-search="<?= htmlspecialchars($searchData) ?>">
                        <td><?= $sl++ ?></td>
                        <td class="exam-id col-ref-id" data-original="<?= htmlspecialchars($ref['referance_exam_id']) ?>">
                            <?= htmlspecialchars($ref['referance_exam_id']) ?>
                        </td>
                        <td class="exam-name" data-original="<?= htmlspecialchars($ref['r_exam_name'] ?? '') ?>">
                            <?= htmlspecialchars($ref['r_exam_name'] ?? '-') ?>
                        </td>
                        <td class="exam-taker" data-original="<?= htmlspecialchars($ref['r_exam_taker'] ?? '-') ?>">
                            <?= htmlspecialchars($ref['r_exam_taker'] ?? '-') ?>
                        </td>
                        <td class="exam-date" data-original="<?= !empty($ref['r_exam_date']) ? htmlspecialchars(date('d M Y', strtotime($ref['r_exam_date']))) : '-' ?>">
                            <?= !empty($ref['r_exam_date']) ? htmlspecialchars(date('d M Y', strtotime($ref['r_exam_date']))) : '-' ?>
                        </td>
                        <td class="exam-year" data-original="<?= htmlspecialchars($ref['r_exam_year'] ?? '-') ?>">
                            <?= htmlspecialchars($ref['r_exam_year'] ?? '-') ?>
                        </td>
                        <td>
                            <?php if (($ref['authorized_status'] ?? '') === 'Y'): ?>
                                <span class="badge bg-success py-2 px-3">Authorized</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark py-2 px-3">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($ref['authorized_status'] ?? '') === 'Y'): ?>
                                <span class="text-muted small">Approved — locked</span>
                            <?php else: ?>
                                <div class="btn-group" role="group">
                                    <?php if ($canEdit): ?>
                                        <a href="?edit=<?= urlencode($ref['referance_exam_id']) ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <a href="?delete=<?= urlencode($ref['referance_exam_id']) ?>" class="btn btn-sm btn-danger"
                                           onclick="return confirm('Are you sure you want to delete this reference exam?')">Delete</a>
                                    <?php endif; ?>
                                    <?php if ($canApprove): ?>
                                        <a href="?approve=<?= urlencode($ref['referance_exam_id']) ?>" class="btn btn-sm btn-success"
                                           onclick="return confirm('Are you sure you want to approve this reference exam?')">Approve</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($refExams)): ?>
                    <tr id="emptyRow">
                        <td colspan="8" class="text-center text-muted">No reference exams found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
// ========== GLOBAL DYNAMIC SEARCH ==========
const searchInput = document.getElementById('globalSearch');
const clearBtn = document.getElementById('clearSearch');
const searchStats = document.getElementById('searchStats');
const tableBody = document.getElementById('tableBody');
const rows = Array.from(document.querySelectorAll('#tableBody tr[data-search]'));
const emptyRow = document.getElementById('emptyRow');
const rowTotal = rows.length;

rows.forEach(row => {
    const cells = row.querySelectorAll('.exam-id, .exam-name, .exam-taker, .exam-date, .exam-year');
    cells.forEach(cell => { cell.dataset.original = cell.innerHTML; });
});

function escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightText(text, searchTerm) {
    if (!searchTerm) return text;
    const regex = new RegExp(`(${escapeRegex(searchTerm)})`, 'gi');
    return text.replace(regex, '<span class="search-highlight">$1</span>');
}

function performSearch() {
    const searchTerm = searchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    rows.forEach(row => {
        const searchData = row.dataset.search;
        const matches = !searchTerm || searchData.includes(searchTerm);
        row.style.display = matches ? '' : 'none';

        if (matches) {
            visibleCount++;
            const idCell = row.querySelector('.exam-id');
            const nameCell = row.querySelector('.exam-name');
            const takerCell = row.querySelector('.exam-taker');
            const dateCell = row.querySelector('.exam-date');
            const yearCell = row.querySelector('.exam-year');
            if (idCell && idCell.dataset.original) idCell.innerHTML = highlightText(idCell.dataset.original, searchTerm);
            if (nameCell && nameCell.dataset.original) nameCell.innerHTML = highlightText(nameCell.dataset.original, searchTerm);
            if (takerCell && takerCell.dataset.original) takerCell.innerHTML = highlightText(takerCell.dataset.original, searchTerm);
            if (dateCell && dateCell.dataset.original) dateCell.innerHTML = highlightText(dateCell.dataset.original, searchTerm);
            if (yearCell && yearCell.dataset.original) yearCell.innerHTML = highlightText(yearCell.dataset.original, searchTerm);
        } else {
            const idCell = row.querySelector('.exam-id');
            const nameCell = row.querySelector('.exam-name');
            const takerCell = row.querySelector('.exam-taker');
            const dateCell = row.querySelector('.exam-date');
            const yearCell = row.querySelector('.exam-year');
            if (idCell && idCell.dataset.original) idCell.innerHTML = idCell.dataset.original;
            if (nameCell && nameCell.dataset.original) nameCell.innerHTML = nameCell.dataset.original;
            if (takerCell && takerCell.dataset.original) takerCell.innerHTML = takerCell.dataset.original;
            if (dateCell && dateCell.dataset.original) dateCell.innerHTML = dateCell.dataset.original;
            if (yearCell && yearCell.dataset.original) yearCell.innerHTML = yearCell.dataset.original;
        }
    });

    if (searchTerm) {
        searchStats.innerHTML = `<i class="bi bi-info-circle"></i> Found ${visibleCount} exam${visibleCount !== 1 ? 's' : ''} matching "${searchTerm}"`;
        searchStats.style.color = '#0d6efd';
    } else {
        searchStats.innerHTML = `<i class="bi bi-search"></i> Showing all ${rowTotal} exam${rowTotal !== 1 ? 's' : ''}`;
        searchStats.style.color = '#6c757d';
    }

    if (visibleCount === 0 && rowTotal > 0) {
        if (!document.getElementById('noResultRow')) {
            const noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultRow';
            noResultRow.innerHTML = '<td colspan="8" class="text-center text-muted py-4">No reference exams match your search criteria.</td>';
            tableBody.appendChild(noResultRow);
        }
    } else {
        const noResultRow = document.getElementById('noResultRow');
        if (noResultRow) noResultRow.remove();
    }
}

let debounceTimer;
function debouncedSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(performSearch, 300);
}

searchInput.addEventListener('input', debouncedSearch);

function clearSearch() {
    searchInput.value = '';
    performSearch();
    searchInput.focus();
}

clearBtn.addEventListener('click', clearSearch);

if (rowTotal > 0) {
    searchStats.innerHTML = `<i class="bi bi-search"></i> Showing all ${rowTotal} exam${rowTotal !== 1 ? 's' : ''}`;
}

const flashMsg = document.querySelector('.alert');
if (flashMsg) {
    setTimeout(() => {
        flashMsg.style.transition = 'opacity 0.5s';
        flashMsg.style.opacity = '0';
        setTimeout(() => flashMsg.remove(), 500);
    }, 3000);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>