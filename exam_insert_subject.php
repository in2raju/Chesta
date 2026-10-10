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
$editSubject = null;

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $subjectId   = $_POST['subject_id'] ?? '';
    $levelId     = trim($_POST['level_id'] ?? '');
    $subjectName = trim($_POST['subject_name']);

    if ($subjectId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE subjects 
                                   SET level_id = :level_id, name = :name, edit_user = :user, edit_date = NOW()
                                   WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                                   AND IFNULL(authorized_status, 'N') != 'Y'");
            $stmt->execute([
                'level_id' => $levelId,
                'name'     => $subjectName,
                'user'     => $userId,
                'id'       => $subjectId,
                'org_code' => $orgCode,
                'br_code'  => $brCode
            ]);
            $message = "<div class='alert alert-success text-center'>Subject updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to edit subjects.</div>";
        }
    } else {
        // Insert
        if ($canInsert) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM subjects 
                                    WHERE name = :name AND level_id = :level_id 
                                    AND org_code = :org_code AND br_code = :br_code");
            $check->execute([
                'name'     => $subjectName,
                'level_id' => $levelId,
                'org_code' => $orgCode,
                'br_code'  => $brCode
            ]);
            
            if ($check->fetchColumn() > 0) {
                $message = "<div class='alert alert-danger text-center'>Error: Subject already exists in this level!</div>";
            } else {
                // Let database auto-generate the ID
                $stmt = $pdo->prepare("INSERT INTO subjects (level_id, name, entry_user, entry_date, org_code, br_code, authorized_status) 
                                       VALUES (:level_id, :name, :user, NOW(), :org_code, :br_code, 'N')");
                $stmt->execute([
                    'level_id' => $levelId,
                    'name'     => $subjectName,
                    'user'     => $userId,
                    'org_code' => $orgCode,
                    'br_code'  => $brCode
                ]);
                $message = "<div class='alert alert-success text-center'>Subject added successfully!</div>";
            }
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to add subjects.</div>";
        }
    }
    
    // Redirect after POST to prevent resubmission
    header("Location: exam_insert_subject.php");
    exit();
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    // Only allow editing if it isn't approved yet
    $stmt = $pdo->prepare("SELECT * FROM subjects 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['edit'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    $editSubject = $stmt->fetch();
    
    if (!$editSubject) {
        $message = "<div class='alert alert-danger text-center'>Error: Record is either approved or does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("UPDATE subjects SET authorized_status = 'Y', authorized_user = :user, authorized_date = NOW() 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'user'     => $userId,
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_subject.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    // Prevent deletion if authorized_status is 'Y'
    $stmt = $pdo->prepare("DELETE FROM subjects 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_subject.php");
    exit();
}

// ----------------------
// Fetch All Levels for Dropdown
// ----------------------
$stmtLevels = $pdo->prepare("SELECT id, name FROM levels WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtLevels->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$levels = $stmtLevels->fetchAll();

// ----------------------
// Fetch All Subjects with IFNULL to handle NULL authorized_status
// ----------------------
$stmt = $pdo->prepare("
    SELECT s.*, l.name AS level_name, IFNULL(s.authorized_status, 'N') as authorized_status 
    FROM subjects s
    LEFT JOIN levels l ON s.level_id = l.id
    WHERE s.org_code = :org_code AND s.br_code = :br_code 
    ORDER BY s.id DESC
");
$stmt->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$subjects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subject Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .btn-group {
            display: flex;
            gap: 5px;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Subject Management</h3>
    <?= $message ?>

    <?php if ($canInsert || $editSubject): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="subject_id" value="<?= htmlspecialchars($editSubject['id'] ?? '') ?>">

                <div class="mb-3">
                    <label>Select Level <span class="text-danger">*</span></label>
                    <select name="level_id" class="form-select" required>
                        <option value="">-- Select Level --</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= htmlspecialchars($level['id']) ?>"
                                <?= (($editSubject['level_id'] ?? '') == $level['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Subject Name <span class="text-danger">*</span></label>
                    <input type="text" name="subject_name" class="form-control" required
                           value="<?= htmlspecialchars($editSubject['name'] ?? '') ?>"
                           placeholder="e.g., Mathematics, Physics, English">
                </div>

                <button type="submit" class="btn btn-primary"><?= $editSubject ? 'Update Subject' : 'Add Subject' ?></button>
                <?php if ($editSubject): ?>
                    <a href="exam_insert_subject.php" class="btn btn-secondary">Cancel</a>
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
                           placeholder="Search by Subject Name, Level, Status, or ID..." 
                           autocomplete="off">
                    <div class="search-stats" id="searchStats"></div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" id="clearSearch" class="btn btn-outline-secondary">
                        ✖ Clear Search
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle" id="subjectsTable">
            <thead class="table-dark">
                <tr>
                    <th style="width: 50px;">SL</th>
                    <th style="width: 100px;">ID</th>
                    <th>Subject Name</th>
                    <th>Level</th>
                    <th style="width: 280px;">Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php 
                $sl = 1;
                foreach ($subjects as $subject): 
                    // Prepare search data for this row
                    $searchData = strtolower(implode(' ', [
                        $subject['name'],
                        $subject['level_name'] ?? '',
                        $subject['authorized_status'] === 'Y' ? 'authorized' : 'pending',
                        $subject['id']
                    ]));
                ?>
                    <tr data-search="<?= htmlspecialchars($searchData) ?>">
                        <td class="text-center"><?= $sl++ ?></td>
                        <td class="subject-id" data-original="<?= htmlspecialchars($subject['id']) ?>">
                            <?= htmlspecialchars($subject['id']) ?>
                        </td>
                        <td class="subject-name" data-original="<?= htmlspecialchars($subject['name']) ?>">
                            <?= htmlspecialchars($subject['name']) ?>
                        </td>
                        <td class="level-name" data-original="<?= htmlspecialchars($subject['level_name'] ?? '-') ?>">
                            <?= htmlspecialchars($subject['level_name'] ?? '-') ?>
                        </td>
                        <td>
                            <?php if ($subject['authorized_status'] === 'Y'): ?>
                                <span class="badge bg-success py-2 px-3">Authorized</span>
                            <?php else: ?>
                                <div class="btn-group" role="group">
                                    <?php if ($canEdit): ?>
                                        <a href="?edit=<?= urlencode($subject['id']) ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($canDelete): ?>
                                        <a href="?delete=<?= urlencode($subject['id']) ?>" class="btn btn-sm btn-danger"
                                           onclick="return confirm('Are you sure you want to delete this subject?')">Delete</a>
                                    <?php endif; ?>

                                    <a href="?approve=<?= urlencode($subject['id']) ?>" class="btn btn-sm btn-success"
                                       onclick="return confirm('Are you sure you want to approve this subject?')">Approve</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <?php if (empty($subjects)): ?>
                    <tr id="emptyRow">
                        <td colspan="5" class="text-center text-muted">No subjects found.</td>
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
const rowTotal = rows.length;

// Store original cell contents for highlighting
rows.forEach(row => {
    const cells = row.querySelectorAll('.subject-name, .level-name, .subject-id');
    cells.forEach(cell => {
        cell.dataset.original = cell.innerHTML;
    });
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
            // Highlight matching text in cells
            const idCell = row.querySelector('.subject-id');
            const nameCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (idCell && idCell.dataset.original) {
                idCell.innerHTML = highlightText(idCell.dataset.original, searchTerm);
            }
            if (nameCell && nameCell.dataset.original) {
                nameCell.innerHTML = highlightText(nameCell.dataset.original, searchTerm);
            }
            if (levelCell && levelCell.dataset.original) {
                levelCell.innerHTML = highlightText(levelCell.dataset.original, searchTerm);
            }
        } else {
            // Restore original content for hidden rows
            const idCell = row.querySelector('.subject-id');
            const nameCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (idCell && idCell.dataset.original) {
                idCell.innerHTML = idCell.dataset.original;
            }
            if (nameCell && nameCell.dataset.original) {
                nameCell.innerHTML = nameCell.dataset.original;
            }
            if (levelCell && levelCell.dataset.original) {
                levelCell.innerHTML = levelCell.dataset.original;
            }
        }
    });
    
    // Update search stats
    if (searchTerm) {
        searchStats.innerHTML = `🔍 Found ${visibleCount} subject${visibleCount !== 1 ? 's' : ''} matching "${searchTerm}"`;
        searchStats.style.color = '#0d6efd';
    } else {
        searchStats.innerHTML = `📋 Showing all ${rowTotal} subject${rowTotal !== 1 ? 's' : ''}`;
        searchStats.style.color = '#6c757d';
    }
    
    // Show/hide empty state
    if (visibleCount === 0 && rowTotal > 0) {
        if (!document.getElementById('noResultRow')) {
            const noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultRow';
            noResultRow.innerHTML = '<td colspan="5" class="text-center text-muted py-4">❌ No subjects match your search criteria. Please try a different keyword.</td>';
            tableBody.appendChild(noResultRow);
        }
    } else {
        const noResultRow = document.getElementById('noResultRow');
        if (noResultRow) noResultRow.remove();
    }
}

// Debounce function for better performance
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

// Initial search stats display
if (rowTotal > 0) {
    searchStats.innerHTML = `📋 Showing all ${rowTotal} subject${rowTotal !== 1 ? 's' : ''}`;
}

// Flash message auto-hide
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