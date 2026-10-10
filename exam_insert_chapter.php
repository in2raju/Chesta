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
$editChapter = null;

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $chapterId   = $_POST['chapter_id'] ?? '';
    $levelId     = trim($_POST['level_id'] ?? '');
    $subjectId   = trim($_POST['subject_id'] ?? '');
    $chapterName = trim($_POST['chapter_name']);

    if ($chapterId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE chapters 
                                   SET level_id = :level_id, subject_id = :subject_id, name = :name, 
                                       edit_user = :user, edit_date = NOW()
                                   WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                                   AND IFNULL(authorized_status, 'N') != 'Y'");
            $stmt->execute([
                'level_id'   => $levelId,
                'subject_id' => $subjectId,
                'name'       => $chapterName,
                'user'       => $userId,
                'id'         => $chapterId,
                'org_code'   => $orgCode,
                'br_code'    => $brCode
            ]);
            $message = "<div class='alert alert-success text-center'>Chapter updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to edit chapters.</div>";
        }
    } else {
        // Insert
        if ($canInsert) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM chapters 
                                    WHERE name = :name AND subject_id = :subject_id 
                                    AND org_code = :org_code AND br_code = :br_code");
            $check->execute([
                'name'       => $chapterName,
                'subject_id' => $subjectId,
                'org_code'   => $orgCode,
                'br_code'    => $brCode
            ]);
            
            if ($check->fetchColumn() > 0) {
                $message = "<div class='alert alert-danger text-center'>Error: Chapter already exists under this subject!</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO chapters (level_id, subject_id, name, entry_user, entry_date, org_code, br_code, authorized_status) 
                                       VALUES (:level_id, :subject_id, :name, :user, NOW(), :org_code, :br_code, 'N')");
                $stmt->execute([
                    'level_id'   => $levelId,
                    'subject_id' => $subjectId,
                    'name'       => $chapterName,
                    'user'       => $userId,
                    'org_code'   => $orgCode,
                    'br_code'    => $brCode
                ]);
                $message = "<div class='alert alert-success text-center'>Chapter added successfully!</div>";
            }
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to add chapters.</div>";
        }
    }
    
    // Redirect after POST to prevent resubmission
    header("Location: exam_insert_chapter.php");
    exit();
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    // Only allow editing if it isn't approved yet
    $stmt = $pdo->prepare("SELECT * FROM chapters 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['edit'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    $editChapter = $stmt->fetch();
    
    if (!$editChapter) {
        $message = "<div class='alert alert-danger text-center'>Error: Record is either approved or does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("UPDATE chapters SET authorized_status = 'Y', authorized_user = :user, authorized_date = NOW() 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'user'     => $userId,
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_chapter.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    // Prevent deletion if authorized_status is 'Y'
    $stmt = $pdo->prepare("DELETE FROM chapters 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_chapter.php");
    exit();
}

// ----------------------
// Fetch All Levels
// ----------------------
$stmtLevels = $pdo->prepare("SELECT id, name FROM levels WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtLevels->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$levels = $stmtLevels->fetchAll();

// ----------------------
// Fetch All Subjects
// ----------------------
$stmtSubjects = $pdo->prepare("SELECT id, name, level_id FROM subjects WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtSubjects->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$subjects = $stmtSubjects->fetchAll();

// ----------------------
// Fetch All Chapters with IFNULL to handle NULL authorized_status
// ----------------------
$stmt = $pdo->prepare("
    SELECT c.*, l.name AS level_name, s.name AS subject_name, 
           IFNULL(c.authorized_status, 'N') as authorized_status 
    FROM chapters c
    LEFT JOIN levels l ON c.level_id = l.id
    LEFT JOIN subjects s ON c.subject_id = s.id
    WHERE c.org_code = :org_code AND c.br_code = :br_code 
    ORDER BY c.id DESC
");
$stmt->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$chapters = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chapter Management</title>
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
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Chapter Management</h3>
    <?= $message ?>

    <?php if ($canInsert || $editChapter): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post" id="chapterForm">
                <input type="hidden" name="chapter_id" value="<?= htmlspecialchars($editChapter['id'] ?? '') ?>">

                <div class="mb-3">
                    <label>Select Level <span class="text-danger">*</span></label>
                    <select name="level_id" id="levelSelect" class="form-select" required>
                        <option value="">-- Select Level --</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= htmlspecialchars($level['id']) ?>"
                                <?= (($editChapter['level_id'] ?? '') == $level['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Select Subject <span class="text-danger">*</span></label>
                    <select name="subject_id" id="subjectSelect" class="form-select" required>
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= htmlspecialchars($subject['id']) ?>"
                                data-level="<?= $subject['level_id'] ?>"
                                <?= (($editChapter['subject_id'] ?? '') == $subject['id']) ? 'selected' : '' ?>
                                style="<?= ($editChapter['level_id'] ?? '') && $subject['level_id'] != ($editChapter['level_id'] ?? '') ? 'display:none;' : '' ?>">
                                <?= htmlspecialchars($subject['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Chapter Name <span class="text-danger">*</span></label>
                    <input type="text" name="chapter_name" class="form-control" required
                           value="<?= htmlspecialchars($editChapter['name'] ?? '') ?>"
                           placeholder="e.g., Introduction, Basics, Advanced Topics">
                </div>

                <button type="submit" class="btn btn-primary"><?= $editChapter ? 'Update Chapter' : 'Add Chapter' ?></button>
                <?php if ($editChapter): ?>
                    <a href="exam_insert_chapter.php" class="btn btn-secondary">Cancel</a>
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
                           placeholder="Search by Chapter Name, Subject, Level, Status, or ID..." 
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

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle" id="chaptersTable">
            <thead class="table-dark">
                <tr>
                    <th style="width: 50px;">SL</th>
                    <th>Chapter Name</th>
                    <th>Subject</th>
                    <th>Level</th>
                    <th style="width: 280px;">Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php 
                $sl = 1;
                foreach ($chapters as $chapter): 
                    // Prepare search data for this row
                    $searchData = strtolower(implode(' ', [
                        $chapter['name'],
                        $chapter['subject_name'] ?? '',
                        $chapter['level_name'] ?? '',
                        $chapter['authorized_status'] === 'Y' ? 'authorized' : 'pending',
                        $chapter['id']
                    ]));
                ?>
                    <tr data-search="<?= htmlspecialchars($searchData) ?>">
                        <td><?= $sl++ ?></td>
                        <td class="chapter-name" data-original="<?= htmlspecialchars($chapter['name']) ?>">
                            <?= htmlspecialchars($chapter['name']) ?>
                        </td>
                        <td class="subject-name" data-original="<?= htmlspecialchars($chapter['subject_name'] ?? '-') ?>">
                            <?= htmlspecialchars($chapter['subject_name'] ?? '-') ?>
                        </td>
                        <td class="level-name" data-original="<?= htmlspecialchars($chapter['level_name'] ?? '-') ?>">
                            <?= htmlspecialchars($chapter['level_name'] ?? '-') ?>
                        </td>
                        <td>
                            <?php if ($chapter['authorized_status'] === 'Y'): ?>
                                <span class="badge bg-success py-2 px-3">Authorized</span>
                            <?php else: ?>
                                <div class="btn-group" role="group">
                                    <?php if ($canEdit): ?>
                                        <a href="?edit=<?= urlencode($chapter['id']) ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($canDelete): ?>
                                        <a href="?delete=<?= urlencode($chapter['id']) ?>" class="btn btn-sm btn-danger"
                                           onclick="return confirm('Are you sure you want to delete this chapter?')">Delete</a>
                                    <?php endif; ?>

                                    <a href="?approve=<?= urlencode($chapter['id']) ?>" class="btn btn-sm btn-success"
                                       onclick="return confirm('Are you sure you want to approve this chapter?')">Approve</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <?php if (empty($chapters)): ?>
                    <tr id="emptyRow">
                        <td colspan="5" class="text-center text-muted">No chapters found.<?= ($searchName ?? '') ? ' Try adjusting your search.' : '' ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
// Dynamic subject filtering based on selected level
const levelSelect = document.getElementById('levelSelect');
const subjectSelect = document.getElementById('subjectSelect');

function filterSubjects() {
    const selectedLevel = levelSelect.value;
    const options = subjectSelect.options;
    
    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        const levelId = option.getAttribute('data-level');
        
        if (option.value === '') {
            option.style.display = 'show';
        } else if (selectedLevel && levelId !== selectedLevel) {
            option.style.display = 'none';
        } else {
            option.style.display = 'show';
        }
    }
    
    if (subjectSelect.options[subjectSelect.selectedIndex] && 
        subjectSelect.options[subjectSelect.selectedIndex].style.display === 'none') {
        subjectSelect.value = '';
    }
}

levelSelect.addEventListener('change', filterSubjects);

<?php if (!$editChapter): ?>
    filterSubjects();
<?php endif; ?>

// ========== GLOBAL DYNAMIC SEARCH ==========
const searchInput = document.getElementById('globalSearch');
const clearBtn = document.getElementById('clearSearch');
const searchStats = document.getElementById('searchStats');
const tableBody = document.getElementById('tableBody');
const rows = Array.from(document.querySelectorAll('#tableBody tr[data-search]'));
const emptyRow = document.getElementById('emptyRow');
const rowTotal = rows.length;

// Store original cell contents for highlighting
rows.forEach(row => {
    const cells = row.querySelectorAll('.chapter-name, .subject-name, .level-name');
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
            const chapterCell = row.querySelector('.chapter-name');
            const subjectCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (chapterCell && chapterCell.dataset.original) {
                chapterCell.innerHTML = highlightText(chapterCell.dataset.original, searchTerm);
            }
            if (subjectCell && subjectCell.dataset.original) {
                subjectCell.innerHTML = highlightText(subjectCell.dataset.original, searchTerm);
            }
            if (levelCell && levelCell.dataset.original) {
                levelCell.innerHTML = highlightText(levelCell.dataset.original, searchTerm);
            }
        } else {
            // Restore original content for hidden rows
            const chapterCell = row.querySelector('.chapter-name');
            const subjectCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (chapterCell && chapterCell.dataset.original) {
                chapterCell.innerHTML = chapterCell.dataset.original;
            }
            if (subjectCell && subjectCell.dataset.original) {
                subjectCell.innerHTML = subjectCell.dataset.original;
            }
            if (levelCell && levelCell.dataset.original) {
                levelCell.innerHTML = levelCell.dataset.original;
            }
        }
    });
    
    // Update search stats
    if (searchTerm) {
        searchStats.innerHTML = `<i class="bi bi-info-circle"></i> Found ${visibleCount} chapter${visibleCount !== 1 ? 's' : ''} matching "${searchTerm}"`;
        searchStats.style.color = '#0d6efd';
    } else {
        searchStats.innerHTML = `<i class="bi bi-search"></i> Showing all ${rowTotal} chapter${rowTotal !== 1 ? 's' : ''}`;
        searchStats.style.color = '#6c757d';
    }
    
    // Show/hide empty state
    if (visibleCount === 0 && rowTotal > 0) {
        if (!document.getElementById('noResultRow')) {
            const noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultRow';
            noResultRow.innerHTML = '<td colspan="5" class="text-center text-muted py-4">No chapters match your search criteria. Please try a different keyword.</td>';
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
    searchStats.innerHTML = `<i class="bi bi-search"></i> Showing all ${rowTotal} chapter${rowTotal !== 1 ? 's' : ''}`;
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