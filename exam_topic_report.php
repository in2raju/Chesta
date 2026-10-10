<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user     = $_SESSION['user'];
$orgCode  = $user['org_code'] ?? '';
$brCode   = $user['br_code'] ?? '';

// ----------------------
// Filter Parameters
// ----------------------
$filterLevel   = $_GET['level_id']   ?? '';
$filterSubject = $_GET['subject_id'] ?? '';
$filterChapter = $_GET['chapter_id'] ?? '';

// ----------------------
// Build Query with Filters
// ----------------------
$sql = "SELECT l.id AS level_id, l.name AS level_name,
               s.id AS subject_id, s.name AS subject_name,
               c.id AS chapter_id, c.name AS chapter_name,
               t.id AS topic_id, t.name AS topic_name
        FROM topics t
        LEFT JOIN chapters c ON t.chapter_id = c.id
        LEFT JOIN subjects s ON c.subject_id = s.id
        LEFT JOIN levels l ON c.level_id = l.id
        WHERE t.org_code = :org_code AND t.br_code = :br_code";

$params = [
    'org_code' => $orgCode,
    'br_code'  => $brCode
];

if ($filterLevel !== '') {
    $sql .= " AND l.id = :level_id";
    $params['level_id'] = $filterLevel;
}
if ($filterSubject !== '') {
    $sql .= " AND s.id = :subject_id";
    $params['subject_id'] = $filterSubject;
}
if ($filterChapter !== '') {
    $sql .= " AND c.id = :chapter_id";
    $params['chapter_id'] = $filterChapter;
}

$sql .= " GROUP BY s.id, s.name, l.id, l.name, c.id, c.name, t.id, t.name
          ORDER BY s.id ASC, l.id ASC, c.id ASC, t.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reportData = $stmt->fetchAll();

// ----------------------
// Fetch Filter Dropdown Data
// ----------------------
$stmtLevels = $pdo->prepare("SELECT id, name FROM levels WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtLevels->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
$levels = $stmtLevels->fetchAll();

$stmtSubjects = $pdo->prepare("SELECT id, name, level_id FROM subjects WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtSubjects->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
$subjects = $stmtSubjects->fetchAll();

$stmtChapters = $pdo->prepare("SELECT id, name, subject_id FROM chapters WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtChapters->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
$chapters = $stmtChapters->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Topic Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .search-highlight { background-color: #fff3cd; padding: 2px 4px; border-radius: 3px; font-weight: bold; }
        .table-responsive { max-height: 550px; overflow-y: auto; }
        .search-stats { font-size: 14px; color: #6c757d; margin-top: 8px; }
        .filter-card { background: #f8f9fa; border-left: 4px solid #0d6efd; }
        .id-badge { font-family: monospace; font-size: 12px; color: #6c757d; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h3>📊 Topic Report</h3>
        <div>
            <button onclick="exportExcel()" class="btn btn-success">📥 Excel</button>
            <button onclick="exportPDF()" class="btn btn-danger">📄 PDF</button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card mb-4 shadow-sm filter-card no-print">
        <div class="card-body">
            <h6 class="fw-bold mb-3">🔎 Filter Options</h6>
            <form method="get" id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Level</label>
                    <select name="level_id" id="levelSelect" class="form-select">
                        <option value="">-- All Levels --</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= htmlspecialchars($level['id']) ?>"
                                <?= $filterLevel == $level['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Subject</label>
                    <select name="subject_id" id="subjectSelect" class="form-select">
                        <option value="">-- All Subjects --</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Chapter</label>
                    <select name="chapter_id" id="chapterSelect" class="form-select">
                        <option value="">-- All Chapters --</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                    <a href="exam_topic_report.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Dynamic Search -->
    <div class="card mb-3 shadow-sm no-print">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <label class="form-label fw-bold">🔍 Search in Report</label>
                    <input type="text" id="globalSearch" class="form-control form-control-lg"
                           placeholder="Search by Topic, Chapter, Subject, Level, or ID..."
                           autocomplete="off">
                    <div class="search-stats" id="searchStats"></div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" id="clearSearch" class="btn btn-outline-secondary">✖ Clear Search</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover align-middle" id="reportTable">
            <thead class="table-dark">
                <tr>
                    <th style="width:50px;">SL</th>
                    <th style="width:70px;">Level ID</th>
                    <th>Level</th>
                    <th style="width:70px;">Subject ID</th>
                    <th>Subject</th>
                    <th style="width:70px;">Chapter ID</th>
                    <th>Chapter</th>
                    <th style="width:70px;">Topic ID</th>
                    <th>Topic</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (!empty($reportData)): ?>
                    <?php $sl = 1; foreach ($reportData as $row):
                        $searchData = strtolower(implode(' ', [
                            $row['level_id'], $row['level_name'],
                            $row['subject_id'], $row['subject_name'],
                            $row['chapter_id'], $row['chapter_name'],
                            $row['topic_id'], $row['topic_name']
                        ]));
                    ?>
                        <tr data-search="<?= htmlspecialchars($searchData) ?>">
                            <td class="text-center"><?= $sl++ ?></td>
                            <td class="level-id text-center id-badge" data-original="<?= htmlspecialchars($row['level_id']) ?>"><?= htmlspecialchars($row['level_id']) ?></td>
                            <td class="level-name" data-original="<?= htmlspecialchars($row['level_name']) ?>"><?= htmlspecialchars($row['level_name']) ?></td>
                            <td class="subject-id text-center id-badge" data-original="<?= htmlspecialchars($row['subject_id']) ?>"><?= htmlspecialchars($row['subject_id']) ?></td>
                            <td class="subject-name" data-original="<?= htmlspecialchars($row['subject_name']) ?>"><?= htmlspecialchars($row['subject_name']) ?></td>
                            <td class="chapter-id text-center id-badge" data-original="<?= htmlspecialchars($row['chapter_id']) ?>"><?= htmlspecialchars($row['chapter_id']) ?></td>
                            <td class="chapter-name" data-original="<?= htmlspecialchars($row['chapter_name']) ?>"><?= htmlspecialchars($row['chapter_name']) ?></td>
                            <td class="topic-id text-center id-badge" data-original="<?= htmlspecialchars($row['topic_id']) ?>"><?= htmlspecialchars($row['topic_id']) ?></td>
                            <td class="topic-name" data-original="<?= htmlspecialchars($row['topic_name']) ?>"><?= htmlspecialchars($row['topic_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr id="emptyRow">
                        <td colspan="9" class="text-center text-muted py-4">No records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>

<!-- jsPDF & SheetJS Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
const allSubjects = <?= json_encode($subjects) ?>;
const allChapters = <?= json_encode($chapters) ?>;
const levelSelect = document.getElementById('levelSelect');
const subjectSelect = document.getElementById('subjectSelect');
const chapterSelect = document.getElementById('chapterSelect');

const selectedSubject = <?= json_encode($filterSubject) ?>;
const selectedChapter = <?= json_encode($filterChapter) ?>;

function populateSubjects(levelId, selected = null) {
    subjectSelect.innerHTML = '<option value="">-- All Subjects --</option>';
    chapterSelect.innerHTML = '<option value="">-- All Chapters --</option>';
    if (!levelId) {
        allSubjects.forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id; opt.textContent = sub.name;
            if (selected && sub.id == selected) opt.selected = true;
            subjectSelect.appendChild(opt);
        });
        return;
    }
    allSubjects.forEach(sub => {
        if (sub.level_id == levelId) {
            const opt = document.createElement('option');
            opt.value = sub.id; opt.textContent = sub.name;
            if (selected && sub.id == selected) opt.selected = true;
            subjectSelect.appendChild(opt);
        }
    });
}

function populateChapters(subjectId, selected = null) {
    chapterSelect.innerHTML = '<option value="">-- All Chapters --</option>';
    if (!subjectId) {
        allChapters.forEach(ch => {
            const opt = document.createElement('option');
            opt.value = ch.id; opt.textContent = ch.name;
            if (selected && ch.id == selected) opt.selected = true;
            chapterSelect.appendChild(opt);
        });
        return;
    }
    allChapters.forEach(ch => {
        if (ch.subject_id == subjectId) {
            const opt = document.createElement('option');
            opt.value = ch.id; opt.textContent = ch.name;
            if (selected && ch.id == selected) opt.selected = true;
            chapterSelect.appendChild(opt);
        }
    });
}

levelSelect.addEventListener('change', () => populateSubjects(levelSelect.value));
subjectSelect.addEventListener('change', () => populateChapters(subjectSelect.value));

populateSubjects(levelSelect.value, selectedSubject);
populateChapters(subjectSelect.value, selectedChapter);

// ================ SEARCH ================
const searchInput = document.getElementById('globalSearch');
const clearBtn = document.getElementById('clearSearch');
const searchStats = document.getElementById('searchStats');
const tableBody = document.getElementById('tableBody');
const rows = Array.from(document.querySelectorAll('#tableBody tr[data-search]'));
const rowTotal = rows.length;

const searchableCells = ['level-id','level-name','subject-id','subject-name','chapter-id','chapter-name','topic-id','topic-name'];

function escapeRegex(str) { return str.replace(/[.*+?$^{}()|[\]\\]/g, '\\$&'); }
function highlightText(text, term) {
    if (!term) return text;
    return text.replace(new RegExp(`(${escapeRegex(term)})`, 'gi'),
        '<span class="search-highlight">$1</span>');
}

function performSearch() {
    const term = searchInput.value.trim().toLowerCase();
    let visible = 0;
    rows.forEach(row => {
        const match = !term || row.dataset.search.includes(term);
        row.style.display = match ? '' : 'none';
        if (match) {
            visible++;
            searchableCells.forEach(cls => {
                const cell = row.querySelector('.' + cls);
                if (cell && cell.dataset.original) {
                    cell.innerHTML = highlightText(cell.dataset.original, term);
                }
            });
        } else {
            searchableCells.forEach(cls => {
                const cell = row.querySelector('.' + cls);
                if (cell && cell.dataset.original) cell.innerHTML = cell.dataset.original;
            });
        }
    });

    if (term) {
        searchStats.innerHTML = `🔍 Found ${visible} record${visible !== 1 ? 's' : ''} matching "${term}"`;
        searchStats.style.color = '#0d6efd';
    } else {
        searchStats.innerHTML = `📋 Showing all ${rowTotal} record${rowTotal !== 1 ? 's' : ''}`;
        searchStats.style.color = '#6c757d';
    }
}

let debounceTimer;
searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(performSearch, 300);
});
clearBtn.addEventListener('click', () => {
    searchInput.value = ''; performSearch(); searchInput.focus();
});
if (rowTotal > 0) searchStats.innerHTML = `📋 Showing all ${rowTotal} record${rowTotal !== 1 ? 's' : ''}`;

// ================ EXPORT EXCEL ================
function exportExcel() {
    const table = document.getElementById('reportTable');
    const wb = XLSX.utils.table_to_book(table, { sheet: "Topic Report" });
    XLSX.writeFile(wb, `Topic_Report_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// ================ EXPORT PDF ================
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });

    doc.setFontSize(16);
    doc.text('Topic Report', 40, 40);

    // Build visible rows only (respecting search filter)
    const visibleRows = rows.filter(r => r.style.display !== 'none');
    const bodyData = visibleRows.map((row, i) => {
        const get = (cls) => {
            const cell = row.querySelector('.' + cls);
            return cell?.dataset.original || cell?.innerText || '';
        };
        return [
            i + 1,
            get('level-id'),
            get('level-name'),
            get('subject-id'),
            get('subject-name'),
            get('chapter-id'),
            get('chapter-name'),
            get('topic-id'),
            get('topic-name')
        ];
    });

    doc.autoTable({
        head: [['SL', 'Level ID', 'Level', 'Subject ID', 'Subject', 'Chapter ID', 'Chapter', 'Topic ID', 'Topic']],
        body: bodyData,
        startY: 60,
        styles: { fontSize: 9, cellPadding: 3 },
        headStyles: { fillColor: [33, 37, 41], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 245, 245] }
    });

    doc.save(`Topic_Report_${new Date().toISOString().slice(0,10)}.pdf`);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>