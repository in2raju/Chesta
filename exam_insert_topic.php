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
$editTopic = null;

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $topicId    = $_POST['topic_id'] ?? '';
    $levelId    = trim($_POST['level_id'] ?? '');
    $subjectId  = trim($_POST['subject_id'] ?? '');
    $chapterId  = trim($_POST['chapter_id'] ?? '');
    $topicName  = trim($_POST['topic_name']);

    if ($topicId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE topics 
                                   SET chapter_id = :chapter_id, name = :name, 
                                       edit_user = :user, edit_date = NOW()
                                   WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                                   AND IFNULL(authorized_status, 'N') != 'Y'");
            $stmt->execute([
                'chapter_id' => $chapterId,
                'name'       => $topicName,
                'user'       => $userId,
                'id'         => $topicId,
                'org_code'   => $orgCode,
                'br_code'    => $brCode
            ]);
            $message = "<div class='alert alert-success text-center'>Topic updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to edit topics.</div>";
        }
    } else {
        // Insert
        if ($canInsert) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM topics 
                                    WHERE name = :name AND chapter_id = :chapter_id 
                                    AND org_code = :org_code AND br_code = :br_code");
            $check->execute([
                'name'       => $topicName,
                'chapter_id' => $chapterId,
                'org_code'   => $orgCode,
                'br_code'    => $brCode
            ]);
            
            if ($check->fetchColumn() > 0) {
                $message = "<div class='alert alert-danger text-center'>Error: Topic already exists under this chapter!</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO topics (chapter_id, name, entry_user, entry_date, org_code, br_code, authorized_status) 
                                       VALUES (:chapter_id, :name, :user, NOW(), :org_code, :br_code, 'N')");
                $stmt->execute([
                    'chapter_id' => $chapterId,
                    'name'       => $topicName,
                    'user'       => $userId,
                    'org_code'   => $orgCode,
                    'br_code'    => $brCode
                ]);
                $message = "<div class='alert alert-success text-center'>Topic added successfully!</div>";
            }
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to add topics.</div>";
        }
    }
    
    // Redirect after POST to prevent resubmission
    header("Location: exam_insert_topic.php");
    exit();
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    // Only allow editing if it isn't approved yet
    $stmt = $pdo->prepare("SELECT t.*, c.subject_id, c.level_id 
                           FROM topics t 
                           JOIN chapters c ON t.chapter_id = c.id 
                           WHERE t.id = :id AND t.org_code = :org_code AND t.br_code = :br_code 
                           AND IFNULL(t.authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['edit'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    $editTopic = $stmt->fetch();
    
    if (!$editTopic) {
        $message = "<div class='alert alert-danger text-center'>Error: Record is either approved or does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("UPDATE topics SET authorized_status = 'Y', authorized_user = :user, authorized_date = NOW() 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'user'     => $userId,
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_topic.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    // Prevent deletion if authorized_status is 'Y'
    $stmt = $pdo->prepare("DELETE FROM topics 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_topic.php");
    exit();
}

// ----------------------
// Fetch all levels, subjects, and chapters
// ----------------------
$stmtLevels = $pdo->prepare("SELECT id, name FROM levels WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtLevels->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$levels = $stmtLevels->fetchAll();

$stmtSubjects = $pdo->prepare("SELECT id, name, level_id FROM subjects WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtSubjects->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$subjects = $stmtSubjects->fetchAll();

$stmtChapters = $pdo->prepare("SELECT id, name, subject_id FROM chapters WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtChapters->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$chapters = $stmtChapters->fetchAll();

// ----------------------
// Fetch Topics (with full relation names and authorized status)
// ----------------------
$stmt = $pdo->prepare("
    SELECT t.id, t.name AS topic_name, c.name AS chapter_name, 
           s.name AS subject_name, l.name AS level_name,
           c.level_id, c.subject_id, t.chapter_id,
           IFNULL(t.authorized_status, 'N') as authorized_status
    FROM topics t
    JOIN chapters c ON t.chapter_id = c.id
    JOIN subjects s ON c.subject_id = s.id
    JOIN levels l ON c.level_id = l.id
    WHERE t.org_code = :org_code AND t.br_code = :br_code
    ORDER BY t.id desc
");
$stmt->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$topics = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Topic Management</title>
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
    <h3>Topic Management</h3>
    <?= $message ?>

    <!-- Topic Form -->
    <?php if ($canInsert || $editTopic): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post" id="topicForm">
                <input type="hidden" name="topic_id" value="<?= htmlspecialchars($editTopic['id'] ?? '') ?>">

                <div class="mb-3">
                    <label>Select Level <span class="text-danger">*</span></label>
                    <select name="level_id" id="levelSelect" class="form-select" required>
                        <option value="">-- Select Level --</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= htmlspecialchars($level['id']) ?>"
                                <?= (($editTopic['level_id'] ?? '') == $level['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Select Subject <span class="text-danger">*</span></label>
                    <select name="subject_id" id="subjectSelect" class="form-select" required>
                        <option value="">-- Select Subject --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Select Chapter <span class="text-danger">*</span></label>
                    <select name="chapter_id" id="chapterSelect" class="form-select" required>
                        <option value="">-- Select Chapter --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Topic Name <span class="text-danger">*</span></label>
                    <input type="text" name="topic_name" class="form-control" required
                           value="<?= htmlspecialchars($editTopic['name'] ?? '') ?>"
                           placeholder="e.g., Introduction, Basics, Advanced Concepts">
                </div>

                <button type="submit" class="btn btn-primary"><?= $editTopic ? 'Update Topic' : 'Add Topic' ?></button>
                <?php if ($editTopic): ?>
                    <a href="exam_insert_topic.php" class="btn btn-secondary">Cancel</a>
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
                           placeholder="Search by Topic Name, Chapter, Subject, Level, Status, or ID..." 
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

    <!-- Topics Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle" id="topicsTable">
            <thead class="table-dark">
                <tr>
                    <th style="width: 50px;">SL</th>
                    <th style="width: 80px;">ID</th>
                    <th>Topic Name</th>
                    <th>Chapter</th>
                    <th>Subject</th>
                    <th>Level</th>
                    <th style="width: 280px;">Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php 
                $sl = 1;
                foreach ($topics as $topic): 
                    // Prepare search data for this row
                    $searchData = strtolower(implode(' ', [
                        $topic['topic_name'],
                        $topic['chapter_name'],
                        $topic['subject_name'],
                        $topic['level_name'],
                        $topic['authorized_status'] === 'Y' ? 'authorized' : 'pending',
                        $topic['id']
                    ]));
                ?>
                    <tr data-search="<?= htmlspecialchars($searchData) ?>">
                        <td class="text-center"><?= $sl++ ?></td>
                        <td class="topic-id" data-original="<?= htmlspecialchars($topic['id']) ?>">
                            <?= htmlspecialchars($topic['id']) ?>
                        </td>
                        <td class="topic-name" data-original="<?= htmlspecialchars($topic['topic_name']) ?>">
                            <?= htmlspecialchars($topic['topic_name']) ?>
                        </td>
                        <td class="chapter-name" data-original="<?= htmlspecialchars($topic['chapter_name']) ?>">
                            <?= htmlspecialchars($topic['chapter_name']) ?>
                        </td>
                        <td class="subject-name" data-original="<?= htmlspecialchars($topic['subject_name']) ?>">
                            <?= htmlspecialchars($topic['subject_name']) ?>
                        </td>
                        <td class="level-name" data-original="<?= htmlspecialchars($topic['level_name']) ?>">
                            <?= htmlspecialchars($topic['level_name']) ?>
                        </td>
                        <td>
                            <?php if ($topic['authorized_status'] === 'Y'): ?>
                                <span class="badge bg-success py-2 px-3">Authorized</span>
                            <?php else: ?>
                                <div class="btn-group" role="group">
                                    <?php if ($canEdit): ?>
                                        <a href="?edit=<?= urlencode($topic['id']) ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($canDelete): ?>
                                        <a href="?delete=<?= urlencode($topic['id']) ?>" class="btn btn-sm btn-danger"
                                           onclick="return confirm('Are you sure you want to delete this topic?')">Delete</a>
                                    <?php endif; ?>

                                    <a href="?approve=<?= urlencode($topic['id']) ?>" class="btn btn-sm btn-success"
                                       onclick="return confirm('Are you sure you want to approve this topic?')">Approve</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <?php if (empty($topics)): ?>
                    <tr id="emptyRow">
                        <td colspan="7" class="text-center text-muted">No topics found.<?= ($searchName ?? '') ? ' Try adjusting your search.' : '' ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
const allSubjects = <?= json_encode($subjects) ?>;
const allChapters = <?= json_encode($chapters) ?>;
const levelSelect = document.getElementById('levelSelect');
const subjectSelect = document.getElementById('subjectSelect');
const chapterSelect = document.getElementById('chapterSelect');

function populateSubjects(levelId, selectedSubjectId = null) {
    subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
    chapterSelect.innerHTML = '<option value="">-- Select Chapter --</option>';
    if (!levelId) return;
    allSubjects.forEach(sub => {
        if (sub.level_id == levelId) {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = sub.name;
            if (selectedSubjectId && sub.id == selectedSubjectId) opt.selected = true;
            subjectSelect.appendChild(opt);
        }
    });
}

function populateChapters(subjectId, selectedChapterId = null) {
    chapterSelect.innerHTML = '<option value="">-- Select Chapter --</option>';
    if (!subjectId) return;
    allChapters.forEach(ch => {
        if (ch.subject_id == subjectId) {
            const opt = document.createElement('option');
            opt.value = ch.id;
            opt.textContent = ch.name;
            if (selectedChapterId && ch.id == selectedChapterId) opt.selected = true;
            chapterSelect.appendChild(opt);
        }
    });
}

levelSelect.addEventListener('change', () => {
    populateSubjects(levelSelect.value);
});
subjectSelect.addEventListener('change', () => {
    populateChapters(subjectSelect.value);
});

<?php if ($editTopic): ?>
populateSubjects(<?= json_encode($editTopic['level_id']) ?>, <?= json_encode($editTopic['subject_id']) ?>);
populateChapters(<?= json_encode($editTopic['subject_id']) ?>, <?= json_encode($editTopic['chapter_id']) ?>);
<?php endif; ?>

// ========== GLOBAL DYNAMIC SEARCH ==========
const searchInput = document.getElementById('globalSearch');
const clearBtn = document.getElementById('clearSearch');
const searchStats = document.getElementById('searchStats');
const tableBody = document.getElementById('tableBody');
const rows = Array.from(document.querySelectorAll('#tableBody tr[data-search]'));
const rowTotal = rows.length;

// Store original cell contents for highlighting
rows.forEach(row => {
    const cells = row.querySelectorAll('.topic-name, .chapter-name, .subject-name, .level-name, .topic-id');
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
            const idCell = row.querySelector('.topic-id');
            const nameCell = row.querySelector('.topic-name');
            const chapterCell = row.querySelector('.chapter-name');
            const subjectCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (idCell && idCell.dataset.original) {
                idCell.innerHTML = highlightText(idCell.dataset.original, searchTerm);
            }
            if (nameCell && nameCell.dataset.original) {
                nameCell.innerHTML = highlightText(nameCell.dataset.original, searchTerm);
            }
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
            const idCell = row.querySelector('.topic-id');
            const nameCell = row.querySelector('.topic-name');
            const chapterCell = row.querySelector('.chapter-name');
            const subjectCell = row.querySelector('.subject-name');
            const levelCell = row.querySelector('.level-name');
            
            if (idCell && idCell.dataset.original) {
                idCell.innerHTML = idCell.dataset.original;
            }
            if (nameCell && nameCell.dataset.original) {
                nameCell.innerHTML = nameCell.dataset.original;
            }
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
        searchStats.innerHTML = `🔍 Found ${visibleCount} topic${visibleCount !== 1 ? 's' : ''} matching "${searchTerm}"`;
        searchStats.style.color = '#0d6efd';
    } else {
        searchStats.innerHTML = `📋 Showing all ${rowTotal} topic${rowTotal !== 1 ? 's' : ''}`;
        searchStats.style.color = '#6c757d';
    }
    
    // Show/hide empty state
    if (visibleCount === 0 && rowTotal > 0) {
        if (!document.getElementById('noResultRow')) {
            const noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultRow';
            noResultRow.innerHTML = '<td colspan="7" class="text-center text-muted py-4">❌ No topics match your search criteria. Please try a different keyword.</table>';
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
    searchStats.innerHTML = `📋 Showing all ${rowTotal} topic${rowTotal !== 1 ? 's' : ''}`;
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