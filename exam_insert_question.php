<?php
/**
 * Question Management — with Explanation Image support.
 */

const REFERENCE_EXAM_TABLE = 'referance_exams';

session_start();
require 'db.php';

/**
 * Google Drive "share" links (drive.google.com/file/d/.../view) don't render directly
 * in an <img> tag. This converts a Drive share link into one that actually displays.
 */
function to_viewable_image_url(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (preg_match('~drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
        return 'https://drive.google.com/uc?export=view&id=' . $m[1];
    }
    if (preg_match('~drive\.google\.com/open\?id=([a-zA-Z0-9_-]+)~', $url, $m)) {
        return 'https://drive.google.com/uc?export=view&id=' . $m[1];
    }
    return $url;
}

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info & permissions
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
$editQuestion = null;

// ----------------------
// Handle Insert / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $topic_id = $_POST['topic_id'];
    $referance_exam_id = !empty($_POST['referance_exam_id']) ? (int)$_POST['referance_exam_id'] : null;
    $question = $_POST['question'];
    $image_url = trim($_POST['image_url'] ?? '') ?: null;
    $option_a = $_POST['option_a'];
    $option_b = $_POST['option_b'];
    $option_c = $_POST['option_c'];
    $option_d = $_POST['option_d'];
    $image_url_option_a = trim($_POST['image_url_option_a'] ?? '') ?: null;
    $image_url_option_b = trim($_POST['image_url_option_b'] ?? '') ?: null;
    $image_url_option_c = trim($_POST['image_url_option_c'] ?? '') ?: null;
    $image_url_option_d = trim($_POST['image_url_option_d'] ?? '') ?: null;
    $correct_option = $_POST['correct_option'];
    $explanation = $_POST['explanation'] ?? '';
    $explanation_image_url = trim($_POST['explanation_image_url'] ?? '') ?: null;   // ← NEW
    $exam_reference = $_POST['exam_reference'] ?? '';
    $exam_year = $_POST['exam_year'] ?? null;
    $exam_date = $_POST['exam_date'] ?? null;
    $exam_taker = $_POST['exam_taker'] ?? '';
    $remarks = $_POST['remarks'] ?? '';

    if ($id) {
        // ---- Update ----
        if ($canEdit) {
            try {
                $stmt = $pdo->prepare("UPDATE questions 
                                       SET topic_id=?, referance_exam_id=?, question=?, image_url=?, 
                                           option_a=?, image_url_option_a=?, 
                                           option_b=?, image_url_option_b=?, 
                                           option_c=?, image_url_option_c=?, 
                                           option_d=?, image_url_option_d=?, 
                                           correct_option=?, explanation=?, explanation_image_url=?, exam_reference=?, 
                                           exam_year=?, exam_date=?, exam_taker=?, remarks=?,
                                           edit_user=?, edit_date=NOW() 
                                       WHERE id=? AND org_code=? AND br_code=? 
                                       AND IFNULL(authorized_status, 'N') != 'Y'");
                $stmt->execute([
                    $topic_id, $referance_exam_id, $question, $image_url,
                    $option_a, $image_url_option_a,
                    $option_b, $image_url_option_b,
                    $option_c, $image_url_option_c,
                    $option_d, $image_url_option_d,
                    $correct_option, $explanation, $explanation_image_url, $exam_reference,
                    $exam_year, $exam_date, $exam_taker, $remarks,
                    $userId, $id, $orgCode, $brCode
                ]);
                $message = "<div class='alert alert-success d-flex align-items-center gap-2'><i class=\"bi bi-check-circle-fill\"></i> Question updated successfully!</div>";
            } catch (Throwable $e) {
                $message = "<div class='alert alert-danger'><i class=\"bi bi-exclamation-triangle-fill\"></i> Couldn't save: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $message = "<div class='alert alert-danger d-flex align-items-center gap-2'><i class=\"bi bi-x-circle-fill\"></i> You do not have permission to edit questions.</div>";
        }
    } else {
        // ---- Insert ----
        if ($canInsert) {
            try {
                $stmt = $pdo->prepare("INSERT INTO questions 
                    (topic_id, referance_exam_id, question, image_url, 
                     option_a, image_url_option_a, 
                     option_b, image_url_option_b, 
                     option_c, image_url_option_c, 
                     option_d, image_url_option_d, 
                     correct_option, explanation, explanation_image_url, exam_reference, exam_year, exam_date, exam_taker, remarks,
                     entry_user, br_code, org_code, entry_date, authorized_status) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'N')");
                $stmt->execute([
                    $topic_id, $referance_exam_id, $question, $image_url,
                    $option_a, $image_url_option_a,
                    $option_b, $image_url_option_b,
                    $option_c, $image_url_option_c,
                    $option_d, $image_url_option_d,
                    $correct_option, $explanation, $explanation_image_url, $exam_reference,
                    $exam_year, $exam_date, $exam_taker, $remarks,
                    $userId, $brCode, $orgCode
                ]);
                $message = "<div class='alert alert-success d-flex align-items-center gap-2'><i class=\"bi bi-check-circle-fill\"></i> Question added successfully!</div>";
            } catch (Throwable $e) {
                $message = "<div class='alert alert-danger'><i class=\"bi bi-exclamation-triangle-fill\"></i> Couldn't save: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $message = "<div class='alert alert-danger d-flex align-items-center gap-2'><i class=\"bi bi-x-circle-fill\"></i> You do not have permission to add questions.</div>";
        }
    }

    if (strpos($message, 'alert-danger') === false) {
        header("Location: exam_insert_question.php");
        exit();
    }
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               t.id AS topic_id, t.chapter_id, 
               c.subject_id, 
               s.level_id
        FROM questions q
        JOIN topics t ON q.topic_id = t.id
        JOIN chapters c ON t.chapter_id = c.id
        JOIN subjects s ON c.subject_id = s.id
        WHERE q.id = ? AND q.org_code = ? AND q.br_code = ?
        AND IFNULL(q.authorized_status, 'N') != 'Y'
    ");
    $stmt->execute([$_GET['edit'], $orgCode, $brCode]);
    $editQuestion = $stmt->fetch();

    if (!$editQuestion) {
        $message = "<div class='alert alert-danger d-flex align-items-center gap-2'><i class=\"bi bi-exclamation-triangle-fill\"></i> Error: Record is either approved or does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("UPDATE questions SET authorized_status = 'Y', authorized_user = :user, authorized_date = NOW() 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'user'     => $userId,
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_question.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("DELETE FROM questions 
                           WHERE id = :id AND org_code = :org_code AND br_code = :br_code 
                           AND IFNULL(authorized_status, 'N') != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_question.php");
    exit();
}

// ----------------------
// Fetch levels, subjects, chapters, topics
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

$stmtTopics = $pdo->prepare("SELECT id, name, chapter_id FROM topics WHERE org_code = :org_code AND br_code = :br_code ORDER BY name ASC");
$stmtTopics->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
$topics = $stmtTopics->fetchAll();

// Reference exam dropdown
$referenceExams = [];
$schemaWarning = '';
try {
    $stmtRefExams = $pdo->prepare("SELECT referance_exam_id, r_exam_name, r_exam_taker, r_exam_date, r_exam_year 
                                    FROM " . REFERENCE_EXAM_TABLE . " 
                                    WHERE (BR_CODE = :br_code OR BR_CODE IS NULL) AND (ORG_CODE = :org_code OR ORG_CODE IS NULL) 
                                    ORDER BY r_exam_name ASC");
    $stmtRefExams->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
    $referenceExams = $stmtRefExams->fetchAll();
} catch (Throwable $e) {
    $schemaWarning = "Reference Exam feature is disabled: the `" . REFERENCE_EXAM_TABLE . "` table isn't set up yet (" . $e->getMessage() . ").";
}

// ----------------------
// Fetch FIRST PAGE of questions
// ----------------------
const PAGE_SIZE = 25;
$usingLegacyQuery = false;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE org_code = :org_code AND br_code = :br_code");
$countStmt->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
$totalQuestions = (int)$countStmt->fetchColumn();

try {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               t.name AS topic_name, 
               c.name AS chapter_name, 
               s.name AS subject_name, 
               l.name AS level_name,
               re.r_exam_name AS reference_exam_name,
               IFNULL(q.authorized_status, 'N') as authorized_status
        FROM questions q
        JOIN topics t ON q.topic_id = t.id
        JOIN chapters c ON t.chapter_id = c.id
        JOIN subjects s ON c.subject_id = s.id
        JOIN levels l ON s.level_id = l.id
        LEFT JOIN " . REFERENCE_EXAM_TABLE . " re ON re.referance_exam_id = q.referance_exam_id
        WHERE q.org_code = :org_code AND q.br_code = :br_code
        ORDER BY q.id DESC
        LIMIT " . PAGE_SIZE . "
    ");
    $stmt->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
    $questions = $stmt->fetchAll();
} catch (Throwable $e) {
    $usingLegacyQuery = true;
    $schemaWarning = "The `" . REFERENCE_EXAM_TABLE . "` table doesn't exist yet (" . $e->getMessage() . "). Showing questions without the Reference Exam name until it's created.";
    $stmt = $pdo->prepare("
        SELECT q.*, 
               t.name AS topic_name, 
               c.name AS chapter_name, 
               s.name AS subject_name, 
               l.name AS level_name,
               IFNULL(q.authorized_status, 'N') as authorized_status
        FROM questions q
        JOIN topics t ON q.topic_id = t.id
        JOIN chapters c ON t.chapter_id = c.id
        JOIN subjects s ON c.subject_id = s.id
        JOIN levels l ON s.level_id = l.id
        WHERE q.org_code = :org_code AND q.br_code = :br_code
        ORDER BY q.id DESC
        LIMIT " . PAGE_SIZE . "
    ");
    $stmt->execute(['org_code' => $orgCode, 'br_code' => $brCode]);
    $questions = $stmt->fetchAll();
}
$totalPages = max(1, (int)ceil($totalQuestions / PAGE_SIZE));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --brand:#4f46e5;
            --brand-dark:#4338ca;
            --brand-soft:#eef2ff;
            --surface:#ffffff;
            --bg:#f4f6fb;
            --border:#e5e7eb;
            --text:#1f2430;
            --muted:#6b7280;
            --radius:14px;
        }
        body{
            font-family:'Inter',system-ui,-apple-system,sans-serif;
            background:var(--bg);
            color:var(--text);
        }
        .page-head{
            background:linear-gradient(135deg,var(--brand) 0%,var(--brand-dark) 100%);
            color:#fff;
            border-radius:var(--radius);
            padding:1.75rem 2rem;
            margin-bottom:1.5rem;
            box-shadow:0 10px 25px -10px rgba(79,70,229,.5);
        }
        .page-head h3{ margin:0; font-weight:700; }
        .page-head p{ margin:.25rem 0 0; opacity:.85; font-size:.9rem; }

        .card-modern{
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:0 1px 3px rgba(0,0,0,.04);
        }
        .card-modern .card-header{
            background:transparent;
            border-bottom:1px solid var(--border);
            font-weight:600;
            padding:1rem 1.25rem;
            display:flex;
            align-items:center;
            gap:.5rem;
        }
        .card-modern .card-body{ padding:1.25rem; }

        .form-label{ font-weight:500; font-size:.85rem; color:var(--muted); }
        .form-control, .form-select{ border-radius:10px; border-color:var(--border); }
        .form-control:focus, .form-select:focus{ border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-soft); }
        .select2-container--bootstrap-5 .select2-selection{ min-height:calc(2.25rem + 2px); border-radius:10px; }

        .btn-brand{
            background-color:var(--brand) !important;
            border-color:var(--brand) !important;
            color:#fff !important;
            font-weight:600;
        }
        .btn-brand:hover{ background-color:var(--brand-dark) !important; border-color:var(--brand-dark) !important; }
        .btn-outline-brand{ color:var(--brand) !important; border-color:var(--brand) !important; }
        .btn-outline-brand:hover{ background:var(--brand) !important; color:#fff !important; }

        .section-label{
            font-weight:700;
            font-size:.8rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            color:var(--brand-dark);
            margin-bottom:.75rem;
            display:flex;
            align-items:center;
            gap:.4rem;
        }

        .option-row{
            display:flex;
            align-items:center;
            gap:.65rem;
            background:#fafbfd;
            border:1px solid var(--border);
            border-radius:10px;
            padding:.5rem .75rem;
            margin-bottom:.6rem;
            transition:border-color .15s ease, background .15s ease;
            flex-wrap:wrap;
        }
        .option-row:has(input[type="radio"]:checked){
            border-color:#22c55e;
            background:#f0fdf4;
        }
        .option-row .opt-letter{
            flex:0 0 30px;
            height:30px;
            border-radius:50%;
            background:var(--brand-soft);
            color:var(--brand-dark);
            font-weight:700;
            font-size:.85rem;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .option-row:has(input[type="radio"]:checked) .opt-letter{
            background:#22c55e;
            color:#fff;
        }
        .option-row .option-text-input{
            flex:1;
            min-width:150px;
            border:none;
            background:transparent;
            padding:.25rem 0;
        }
        .option-row .option-text-input:focus{ outline:none; box-shadow:none; }
        .option-image-wrapper{
            display:inline-flex;
            align-items:center;
            gap:4px;
            flex:0 1 auto;
        }
        .option-image-input{
            width:140px !important;
            min-width:100px;
            font-size:0.8rem;
            padding:.2rem .5rem;
            border-radius:6px;
            border:1px solid var(--border);
            background:transparent;
        }
        .option-image-input:focus{
            border-color:var(--brand);
            outline:none;
            box-shadow:0 0 0 2px var(--brand-soft);
        }
        .option-image-preview{
            display:inline-block;
            margin-left:2px;
        }
        .option-image-preview img{
            max-height:32px;
            max-width:32px;
            border-radius:4px;
            border:1px solid var(--border);
            object-fit:cover;
            cursor:pointer;
            transition:max-height .3s ease, max-width .3s ease;
        }
        .option-image-preview img:hover{
            max-height:120px !important;
            max-width:160px !important;
            z-index:10;
            position:relative;
            box-shadow:0 4px 12px rgba(0,0,0,0.15);
        }
        .correct-radio-wrap{
            display:flex;
            align-items:center;
            gap:.3rem;
            font-size:.75rem;
            color:var(--muted);
            flex:0 0 auto;
            cursor:pointer;
        }
        .correct-radio-wrap input{ width:1.05rem; height:1.05rem; cursor:pointer; }

        .toggle-extra{
            background:none;
            border:1px dashed var(--border);
            border-radius:10px;
            color:var(--brand);
            font-size:.85rem;
            font-weight:600;
            padding:.5rem .9rem;
            width:100%;
            text-align:left;
        }
        .toggle-extra:hover{ background:var(--brand-soft); }

        .char-count{ font-size:.72rem; color:var(--muted); text-align:right; margin-top:.2rem; }

        .search-box{ position:relative; }
        .search-box i{ position:absolute; left:.9rem; top:50%; transform:translateY(-50%); color:var(--muted); }
        .search-box input{ padding-left:2.3rem; border-radius:10px; }
        .search-highlight{ background:#fef08a; padding:1px 3px; border-radius:3px; font-weight:600; }
        .search-stats{ font-size:.8rem; color:var(--muted); margin-top:.5rem; }

        .table-modern thead th{
            background:#f8f9fc;
            color:var(--muted);
            font-size:.72rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            font-weight:700;
            border-bottom:2px solid var(--border);
            white-space:nowrap;
        }
        .table-modern tbody td{ vertical-align:middle; font-size:.88rem; }
        .table-responsive-modern{ max-height:600px; overflow-y:auto; border:1px solid var(--border); border-radius:var(--radius); position:relative; }

        .classify-badges .badge{ font-weight:500; font-size:.7rem; }
        .options-mini{ font-size:.78rem; line-height:1.5; }
        .options-mini .correct-opt{ color:#166534; font-weight:700; }
        .options-mini .option-with-img{ display:flex; align-items:center; gap:4px; }
        .options-mini .option-with-img img{ max-height:20px; max-width:20px; border-radius:3px; border:1px solid var(--border); }

        .status-badge-Y{ background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .status-badge-N{ background:#fffbeb; color:#92400e; border:1px solid #fde68a; }

        .empty-state{ text-align:center; color:var(--muted); padding:2.5rem 1rem; font-size:.9rem; }
        .empty-state i{ font-size:1.8rem; display:block; margin-bottom:.5rem; opacity:.5; }

        #modalDetailBody dt{ font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); margin-top:.75rem; }
        #modalDetailBody dd{ font-size:.92rem; margin-bottom:0; }
        #modalDetailBody .option-detail{ display:flex; align-items:center; gap:10px; padding:4px 0; }
        #modalDetailBody .option-detail img{ max-height:40px; max-width:40px; border-radius:4px; border:1px solid var(--border); object-fit:cover; }

        .table-loading-overlay{
            position:absolute; inset:0; background:rgba(255,255,255,.65);
            display:none; align-items:center; justify-content:center; z-index:5;
        }
        .table-loading-overlay.show{ display:flex; }

        .pagination-bar{
            display:flex; align-items:center; justify-content:space-between;
            padding:.75rem 1.25rem; border-top:1px solid var(--border); font-size:.85rem; color:var(--muted);
            flex-wrap:wrap; gap:.5rem;
        }
        .pagination-bar .btn{ padding:.25rem .65rem; font-size:.8rem; }

        .explanation-image-wrap{
            display:flex;
            gap:.5rem;
            align-items:flex-start;
            flex-wrap:wrap;
        }
        .explanation-image-wrap .input-col{
            flex:1;
            min-width:280px;
        }

        @media (max-width:768px){
            .option-row{ flex-wrap:wrap; }
            .option-image-input{ width:100% !important; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">

    <div class="page-head">
        <h3><i class="bi bi-patch-question me-2"></i>Question Management</h3>
        <p>Add, edit, and approve exam questions for your question bank.</p>
    </div>

    <?= $message ?>

    <?php if ($schemaWarning): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-tools mt-1"></i>
            <div>
                <?= htmlspecialchars($schemaWarning) ?>
                <div class="mt-1"><small>Run the <code>ALTER TABLE</code> / <code>CREATE TABLE</code> statements noted in a comment at the very top of this PHP file, then refresh.</small></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Question Form -->
    <?php if ($canInsert || $editQuestion): ?>
    <div class="card-modern mb-4">
        <div class="card-header">
            <i class="bi bi-<?= $editQuestion ? 'pencil-square' : 'plus-circle' ?> text-primary"></i>
            <?= $editQuestion ? 'Edit Question' : 'Add New Question' ?>
        </div>
        <div class="card-body">
            <form method="post" id="questionForm">
                <input type="hidden" name="id" value="<?= htmlspecialchars($editQuestion['id'] ?? '') ?>">

                <div class="section-label"><i class="bi bi-diagram-3"></i> Classification</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Level <span class="text-danger">*</span></label>
                        <select id="levelSelect" class="form-select select2" required>
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                        <select id="subjectSelect" class="form-select select2" required>
                            <option value="">Select Subject</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Chapter <span class="text-danger">*</span></label>
                        <select id="chapterSelect" class="form-select select2" required>
                            <option value="">Select Chapter</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Topic <span class="text-danger">*</span></label>
                        <select name="topic_id" id="topicSelect" class="form-select select2" required>
                            <option value="">Select Topic</option>
                        </select>
                    </div>
                </div>

                <div class="section-label"><i class="bi bi-chat-square-text"></i> Question</div>
                <div class="mb-4">
                    <textarea name="question" id="questionText" class="form-control" rows="3" required maxlength="2000" placeholder="Type the question here..."><?= htmlspecialchars($editQuestion['question'] ?? '') ?></textarea>
                    <div class="char-count"><span id="questionCharCount">0</span> / 2000</div>
                </div>

                <div class="section-label"><i class="bi bi-image"></i> Question Image <span class="text-muted fw-normal text-lowercase">(optional)</span></div>
                <div class="mb-4">
                    <div class="d-flex gap-2 align-items-start flex-wrap">
                        <div class="flex-grow-1" style="min-width:280px;">
                            <input type="url" name="image_url" id="imageUrlInput" class="form-control"
                                   placeholder="Paste the image's Google Drive share link here"
                                   value="<?= htmlspecialchars($editQuestion['image_url'] ?? '') ?>"
                                   <?= $usingLegacyQuery ? 'disabled' : '' ?>>
                            <?php if ($usingLegacyQuery): ?>
                                <small class="text-danger d-block mt-1"><i class="bi bi-exclamation-triangle"></i> Disabled until the <code>image_url</code> column is added — see the warning above.</small>
                            <?php else: ?>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle"></i>
                                Upload the image to
                                <a href="https://drive.google.com/drive/folders/1OJiMdCfs5ZrdpDpnMFU3l59At8H5rxXt?usp=sharing" target="_blank" rel="noopener">this Drive folder</a>,
                                right-click it → <strong>Share → Copy link</strong> (make sure it's set to "Anyone with the link"), then paste that link here.
                            </small>
                            <?php endif; ?>
                        </div>
                        <div id="imagePreviewWrap" style="display:none;">
                            <img id="imagePreview" src="" alt="Preview" style="max-height:110px; max-width:180px; border-radius:8px; border:1px solid var(--border); object-fit:cover;">
                        </div>
                    </div>
                </div>

                <div class="section-label"><i class="bi bi-list-check"></i> Options &amp; Correct Answer</div>
                <div class="mb-4">
                    <?php foreach (['A','B','C','D'] as $o):
                        $val = $editQuestion['option_' . strtolower($o)] ?? '';
                        $imgVal = $editQuestion['image_url_option_' . strtolower($o)] ?? '';
                        $isCorrect = isset($editQuestion['correct_option']) && $editQuestion['correct_option'] === $o;
                        $optKey = strtolower($o);
                    ?>
                    <div class="option-row">
                        <div class="opt-letter"><?= $o ?></div>
                        <input type="text" name="option_<?= $optKey ?>" class="option-text-input form-control" required
                               value="<?= htmlspecialchars($val) ?>" placeholder="Option <?= $o ?> text">
                        <div class="option-image-wrapper">
                            <input type="url" name="image_url_option_<?= $optKey ?>" 
                                   class="option-image-input" 
                                   placeholder="Option <?= $o ?> image URL"
                                   value="<?= htmlspecialchars($imgVal) ?>"
                                   <?= $usingLegacyQuery ? 'disabled' : '' ?>>
                            <span class="option-image-preview" style="display:<?= !empty($imgVal) ? 'inline-block' : 'none' ?>;">
                                <img src="" alt="Preview" onerror="this.parentElement.style.display='none'">
                            </span>
                        </div>
                        <label class="correct-radio-wrap">
                            <input type="radio" name="correct_option" value="<?= $o ?>" <?= $isCorrect ? 'checked' : '' ?> required>
                            Correct
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <small class="text-muted"><i class="bi bi-info-circle"></i> Fill in all four options, mark the correct one, and optionally add images for each option.</small>
                </div>

                <!-- ⬇️⬇️⬇️ EXPLANATION SECTION WITH IMAGE ⬇️⬇️⬇️ -->
                <div class="section-label"><i class="bi bi-lightbulb"></i> Explanation <span class="text-muted fw-normal text-lowercase">(optional)</span></div>
                <div class="mb-4">
                    <textarea name="explanation" class="form-control mb-3" rows="2" placeholder="Why is the correct answer right? (shown to students after they answer)"><?= htmlspecialchars($editQuestion['explanation'] ?? '') ?></textarea>

                    <label class="form-label"><i class="bi bi-image"></i> Explanation Image <span class="text-muted fw-normal text-lowercase">(optional)</span></label>
                    <div class="explanation-image-wrap">
                        <div class="input-col">
                            <input type="url" name="explanation_image_url" id="explanationImageUrlInput" class="form-control"
                                   placeholder="Paste the explanation image's Google Drive share link here"
                                   value="<?= htmlspecialchars($editQuestion['explanation_image_url'] ?? '') ?>"
                                   <?= $usingLegacyQuery ? 'disabled' : '' ?>>
                            <?php if (!$usingLegacyQuery): ?>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle"></i>
                                Upload the image to
                                <a href="https://drive.google.com/drive/folders/1OJiMdCfs5ZrdpDpnMFU3l59At8H5rxXt?usp=sharing" target="_blank" rel="noopener">this Drive folder</a>,
                                then paste the "Anyone with the link" share URL here.
                            </small>
                            <?php endif; ?>
                        </div>
                        <div id="explanationImagePreviewWrap" style="display:none;">
                            <img id="explanationImagePreview" src="" alt="Explanation preview"
                                 style="max-height:110px; max-width:180px; border-radius:8px; border:1px solid var(--border); object-fit:cover;">
                        </div>
                    </div>
                </div>
                <!-- ⬆️⬆️⬆️ END EXPLANATION SECTION ⬆️⬆️⬆️ -->

                <button type="button" class="toggle-extra mb-4" data-bs-toggle="collapse" data-bs-target="#extraFields">
                    <i class="bi bi-plus-lg"></i> Add reference exam &amp; source details — optional
                </button>

                <div class="collapse <?= !empty($editQuestion['referance_exam_id']) || !empty($editQuestion['exam_reference']) || !empty($editQuestion['exam_year']) || !empty($editQuestion['exam_date']) || !empty($editQuestion['exam_taker']) || !empty($editQuestion['remarks']) ? 'show' : '' ?>" id="extraFields">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Reference Exam</label>
                            <select name="referance_exam_id" id="referenceExamSelect" class="form-select select2" <?= $usingLegacyQuery ? 'disabled' : '' ?>>
                                <option value="">-- None --</option>
                                <?php foreach ($referenceExams as $re): ?>
                                    <option value="<?= $re['referance_exam_id'] ?>"
                                        data-taker="<?= htmlspecialchars($re['r_exam_taker'] ?? '') ?>"
                                        data-date="<?= htmlspecialchars($re['r_exam_date'] ?? '') ?>"
                                        data-year="<?= htmlspecialchars($re['r_exam_year'] ?? '') ?>"
                                        <?= (!empty($editQuestion['referance_exam_id']) && (int)$editQuestion['referance_exam_id'] === (int)$re['referance_exam_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($re['r_exam_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><i class="bi bi-info-circle"></i> Picking one can auto-fill taker/date/year below.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Exam Reference (free text)</label>
                            <input type="text" name="exam_reference" class="form-control" placeholder="e.g. 45th BCS" value="<?= htmlspecialchars($editQuestion['exam_reference'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Exam Year</label>
                            <input type="number" name="exam_year" id="examYearInput" class="form-control" placeholder="e.g. 2024" value="<?= htmlspecialchars($editQuestion['exam_year'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Exam Date</label>
                            <input type="date" name="exam_date" id="examDateInput" class="form-control" value="<?= htmlspecialchars($editQuestion['exam_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Exam Taker</label>
                            <input type="text" name="exam_taker" id="examTakerInput" class="form-control" placeholder="Exam conducted by" value="<?= htmlspecialchars($editQuestion['exam_taker'] ?? '') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Additional notes" value="<?= htmlspecialchars($editQuestion['remarks'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-brand px-4">
                        <i class="bi bi-check2-circle"></i> <?= $editQuestion ? 'Update Question' : 'Add Question' ?>
                    </button>
                    <?php if ($editQuestion): ?>
                        <a href="exam_insert_question.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Global Dynamic Search -->
    <div class="card-modern mb-3">
        <div class="card-body">
            <div class="row align-items-end g-2">
                <div class="col-md-9">
                    <label class="form-label"><i class="bi bi-search"></i> Global Search</label>
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="globalSearch" class="form-control form-control-lg"
                               placeholder="Search by question, topic, chapter, subject, level, exam ref, or ID..."
                               autocomplete="off">
                    </div>
                    <div class="search-stats" id="searchStats"></div>
                </div>
                <div class="col-md-3">
                    <button type="button" id="clearSearch" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Questions Table -->
    <div class="card-modern">
        <div class="card-header justify-content-between">
            <span><i class="bi bi-list-ul text-primary"></i> Question Bank</span>
            <span class="badge bg-light text-dark border" id="tableCountBadge"><?= (int)$totalQuestions ?> total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive-modern">
                <div class="table-loading-overlay" id="tableLoading">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>
                <table class="table table-modern table-hover align-middle mb-0" id="questionsTable">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th style="min-width:260px;">Question</th>
                            <th style="min-width:180px;">Classification</th>
                            <th style="min-width:200px;">Options</th>
                            <th>Status</th>
                            <th class="text-end" style="width:260px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php
                        function h($v) { return htmlspecialchars((string)$v); }
                        function renderOptWithImage($optVal, $imgVal, $isCorrect, $o) {
                            $html = '<div class="option-with-img">';
                            $html .= $isCorrect ? '<i class="bi bi-check-circle-fill" style="color:#166534;"></i>' : h($o) . ')';
                            $html .= ' ' . h(substr($optVal, 0, 30)) . (strlen($optVal) > 30 ? '…' : '');
                            if (!empty($imgVal)) {
                                $viewable = to_viewable_image_url($imgVal);
                                if ($viewable) {
                                    $html .= ' <img src="' . h($viewable) . '" alt="opt img" onerror="this.style.display=\'none\'">';
                                }
                            }
                            $html .= '</div>';
                            return $html;
                        }
                        foreach ($questions as $q): ?>
                            <tr data-id="<?= h($q['id']) ?>">
                                <td class="text-center text-muted">#<?= h($q['id']) ?></td>
                                <td class="question-text">
                                    <?php if (!empty($q['image_url'])): ?>
                                        <i class="bi bi-image text-primary" title="Has an attached image"></i>
                                    <?php endif; ?>
                                    <?php if (!empty($q['explanation_image_url'])): ?>
                                        <i class="bi bi-chat-square-image text-warning" title="Explanation has an image"></i>
                                    <?php endif; ?>
                                    <?= h(substr($q['question'], 0, 90)) . (strlen($q['question']) > 90 ? '…' : '') ?>
                                </td>
                                <td class="classify-badges">
                                    <div class="level-name">
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($q['level_name']) ?></span>
                                        <?php if (!empty($q['reference_exam_name'])): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-bookmark"></i> <?= h($q['reference_exam_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-light text-dark border"><?= h($q['subject_name']) ?></span>
                                        <span class="badge bg-light text-dark border"><?= h($q['chapter_name']) ?></span>
                                        <span class="badge bg-light text-dark border"><?= h($q['topic_name']) ?></span>
                                    </div>
                                </td>
                                <td class="options-mini">
                                    <?php foreach (['A','B','C','D'] as $o):
                                        $optKey = strtolower($o);
                                        $optVal = $q['option_' . $optKey] ?? '';
                                        $imgVal = $q['image_url_option_' . $optKey] ?? '';
                                        $isCorrect = $q['correct_option'] === $o;
                                        echo renderOptWithImage($optVal, $imgVal, $isCorrect, $o);
                                    endforeach; ?>
                                </td>
                                <td>
                                    <?php if ($q['authorized_status'] === 'Y'): ?>
                                        <span class="badge status-badge-Y"><i class="bi bi-shield-check"></i> Authorized</span>
                                    <?php else: ?>
                                        <span class="badge status-badge-N"><i class="bi bi-hourglass-split"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary view-detail-btn" title="View full details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($q['authorized_status'] !== 'Y'): ?>
                                            <?php if ($canEdit): ?>
                                                <a href="?edit=<?= urlencode($q['id']) ?>" class="btn btn-sm btn-outline-brand" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                                <a href="?delete=<?= urlencode($q['id']) ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this question?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canApprove): ?>
                                                <a href="?approve=<?= urlencode($q['id']) ?>" class="btn btn-sm btn-outline-success" title="Approve"
                                                   onclick="return confirm('Are you sure you want to approve this question?')">
                                                    <i class="bi bi-check-lg"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($questions)): ?>
                            <tr id="emptyRow">
                                <td colspan="6">
                                    <div class="empty-state"><i class="bi bi-inbox"></i>No questions found.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-bar">
                <span id="pageInfo">Page 1 of <?= (int)$totalPages ?></span>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-outline-secondary" id="prevPageBtn" disabled><i class="bi bi-chevron-left"></i> Prev</button>
                    <button type="button" class="btn btn-outline-secondary" id="nextPageBtn" <?= $totalPages <= 1 ? 'disabled' : '' ?>>Next <i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-text me-2"></i>Question Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetailBody">
                <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Loading...</div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
    $('.select2').select2({ width: '100%', theme: 'bootstrap-5' });

    // ------ Dynamic Cascading Selects ------
    const allSubjects = <?= json_encode($subjects) ?>;
    const allChapters = <?= json_encode($chapters) ?>;
    const allTopics = <?= json_encode($topics) ?>;

    const $level = $('#levelSelect');
    const $subject = $('#subjectSelect');
    const $chapter = $('#chapterSelect');
    const $topic = $('#topicSelect');

    function esc(s){ return $('<div>').text(s ?? '').html(); }

    function populateSubjects(levelId, selected){
        const opts = ['<option value="">Select Subject</option>'];
        allSubjects.filter(s => s.level_id == levelId).forEach(s => {
            opts.push(`<option value="${s.id}" ${String(selected) === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`);
        });
        $subject.html(opts.join(''));
        $subject.trigger('change.select2');
        populateChapters($subject.val(), null);
    }
    function populateChapters(subjectId, selected){
        const opts = ['<option value="">Select Chapter</option>'];
        allChapters.filter(c => c.subject_id == subjectId).forEach(c => {
            opts.push(`<option value="${c.id}" ${String(selected) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`);
        });
        $chapter.html(opts.join(''));
        $chapter.trigger('change.select2');
        populateTopics($chapter.val(), null);
    }
    function populateTopics(chapterId, selected){
        const opts = ['<option value="">Select Topic</option>'];
        allTopics.filter(t => t.chapter_id == chapterId).forEach(t => {
            opts.push(`<option value="${t.id}" ${String(selected) === String(t.id) ? 'selected' : ''}>${esc(t.name)}</option>`);
        });
        $topic.html(opts.join(''));
        $topic.trigger('change.select2');
    }

    $level.on('change', function(){ populateSubjects(this.value, null); });
    $subject.on('change', function(){ populateChapters(this.value, null); });
    $chapter.on('change', function(){ populateTopics(this.value, null); });

    <?php if ($editQuestion): ?>
    $level.val(<?= json_encode((string)$editQuestion['level_id']) ?>).trigger('change.select2');
    populateSubjects(<?= json_encode($editQuestion['level_id']) ?>, <?= json_encode($editQuestion['subject_id']) ?>);
    $subject.val(<?= json_encode((string)$editQuestion['subject_id']) ?>).trigger('change.select2');
    populateChapters(<?= json_encode($editQuestion['subject_id']) ?>, <?= json_encode($editQuestion['chapter_id'] ?? null) ?>);
    $chapter.val(<?= json_encode((string)($editQuestion['chapter_id'] ?? '')) ?>).trigger('change.select2');
    populateTopics(<?= json_encode($editQuestion['chapter_id'] ?? null) ?>, <?= json_encode($editQuestion['topic_id']) ?>);
    $topic.val(<?= json_encode((string)$editQuestion['topic_id']) ?>).trigger('change.select2');
    <?php endif; ?>

    // ------ Question textarea character counter ------
    const $qText = $('#questionText');
    const $qCount = $('#questionCharCount');
    function updateCharCount(){ $qCount.text($qText.val().length); }
    $qText.on('input', updateCharCount);
    updateCharCount();

    // ------ Image URL converter + live preview ------
    function toViewableImageUrl(url){
        url = (url || '').trim();
        if(!url) return '';
        let m = url.match(/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/);
        if(m) return 'https://drive.google.com/uc?export=view&id=' + m[1];
        m = url.match(/drive\.google\.com\/open\?id=([a-zA-Z0-9_-]+)/);
        if(m) return 'https://drive.google.com/uc?export=view&id=' + m[1];
        return url;
    }

    // Question image preview
    const $imageUrlInput = $('#imageUrlInput');
    const $imagePreview = $('#imagePreview');
    const $imagePreviewWrap = $('#imagePreviewWrap');
    function updateImagePreview(){
        const raw = $imageUrlInput.val().trim();
        if(!raw){ $imagePreviewWrap.hide(); return; }
        $imagePreview.attr('src', toViewableImageUrl(raw));
        $imagePreviewWrap.show();
    }
    $imagePreview.on('error', function(){ $imagePreviewWrap.hide(); });
    $imageUrlInput.on('input', updateImagePreview);
    updateImagePreview();

    // ⬇️ Explanation image preview (NEW) ⬇️
    const $explanationImageUrlInput = $('#explanationImageUrlInput');
    const $explanationImagePreview = $('#explanationImagePreview');
    const $explanationImagePreviewWrap = $('#explanationImagePreviewWrap');
    function updateExplanationImagePreview(){
        const raw = $explanationImageUrlInput.val().trim();
        if(!raw){ $explanationImagePreviewWrap.hide(); return; }
        $explanationImagePreview.attr('src', toViewableImageUrl(raw));
        $explanationImagePreviewWrap.show();
    }
    $explanationImagePreview.on('error', function(){ $explanationImagePreviewWrap.hide(); });
    $explanationImageUrlInput.on('input', updateExplanationImagePreview);
    updateExplanationImagePreview();
    // ⬆️ END explanation preview ⬆️

    // Option image preview
    function updateOptionImagePreview(inputElement){
        const raw = $(inputElement).val().trim();
        const $wrapper = $(inputElement).closest('.option-image-wrapper');
        const $preview = $wrapper.find('.option-image-preview');
        const $img = $preview.find('img');

        if(!raw){ $preview.hide(); return; }

        const viewableUrl = toViewableImageUrl(raw);
        $img.attr('src', viewableUrl);
        $preview.show();
    }

    $(document).on('input', '.option-image-input', function(){
        updateOptionImagePreview(this);
    });

    $('.option-image-input').each(function(){
        updateOptionImagePreview(this);
    });

    // Reference Exam auto-fill
    $('#referenceExamSelect').on('change', function(){
        const $opt = $(this).find('option:selected');
        if(!$opt.val()) return;
        const taker = $opt.data('taker');
        const date  = $opt.data('date');
        const year  = $opt.data('year');
        if(taker) $('#examTakerInput').val(taker);
        if(date)  $('#examDateInput').val(date);
        if(year)  $('#examYearInput').val(year);
    });

    // ========== SERVER-SIDE PAGINATED / SEARCHED TABLE ==========
    const $tableBody   = $('#tableBody');
    const $searchInput = $('#globalSearch');
    const $searchStats = $('#searchStats');
    const $loading      = $('#tableLoading');
    const $pageInfo      = $('#pageInfo');
    const $prevBtn       = $('#prevPageBtn');
    const $nextBtn       = $('#nextPageBtn');
    const $countBadge    = $('#tableCountBadge');

    const PAGE_SIZE = <?= (int)PAGE_SIZE ?>;
    const CAN_EDIT     = <?= $canEdit ? 'true' : 'false' ?>;
    const CAN_DELETE   = <?= $canDelete ? 'true' : 'false' ?>;
    const CAN_APPROVE  = <?= $canApprove ? 'true' : 'false' ?>;

    let currentPage = 1;
    let currentQuery = '';
    let currentTotalPages = <?= (int)$totalPages ?>;
    let requestSeq = 0;

    function highlight(text, term){
        if(!term) return esc(text);
        const escaped = esc(text);
        const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return escaped.replace(re, '<span class="search-highlight">$1</span>');
    }

    function renderRow(q, term){
        const isImg = q.image_url ? '<i class="bi bi-image text-primary" title="Has an attached image"></i> ' : '';
        const isExplImg = q.explanation_image_url ? '<i class="bi bi-chat-square-image text-warning" title="Explanation has an image"></i> ' : '';
        let questionShort = (q.question || '').slice(0, 90) + ((q.question || '').length > 90 ? '…' : '');

        let optsHtml = '';
        ['A','B','C','D'].forEach(o => {
            const val = q['option_' + o.toLowerCase()] || '';
            const imgVal = q['image_url_option_' + o.toLowerCase()] || '';
            const isCorrect = q.correct_option === o;
            const shortVal = val.slice(0, 30) + (val.length > 30 ? '…' : '');

            let imgHtml = '';
            if(imgVal){
                const viewable = toViewableImageUrl(imgVal);
                if(viewable){
                    imgHtml = ` <img src="${esc(viewable)}" style="max-height:20px;max-width:20px;border-radius:3px;border:1px solid var(--border);" onerror="this.style.display='none'">`;
                }
            }

            optsHtml += `<div class="${isCorrect ? 'correct-opt' : ''} option-with-img">
                ${isCorrect ? '<i class="bi bi-check-circle-fill"></i>' : o + ')'} 
                ${esc(shortVal)}${imgHtml}
            </div>`;
        });

        const refBadge = q.reference_exam_name
            ? `<span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-bookmark"></i> ${esc(q.reference_exam_name)}</span>`
            : '';

        const statusHtml = q.authorized_status === 'Y'
            ? '<span class="badge status-badge-Y"><i class="bi bi-shield-check"></i> Authorized</span>'
            : '<span class="badge status-badge-N"><i class="bi bi-hourglass-split"></i> Pending</span>';

        let actions = `<button type="button" class="btn btn-sm btn-outline-secondary view-detail-btn" title="View full details"><i class="bi bi-eye"></i></button>`;
        if (q.authorized_status !== 'Y') {
            if (CAN_EDIT) actions += ` <a href="?edit=${encodeURIComponent(q.id)}" class="btn btn-sm btn-outline-brand" title="Edit"><i class="bi bi-pencil"></i></a>`;
            if (CAN_DELETE) actions += ` <a href="?delete=${encodeURIComponent(q.id)}" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this question?')"><i class="bi bi-trash"></i></a>`;
            if (CAN_APPROVE) actions += ` <a href="?approve=${encodeURIComponent(q.id)}" class="btn btn-sm btn-outline-success" title="Approve" onclick="return confirm('Are you sure you want to approve this question?')"><i class="bi bi-check-lg"></i></a>`;
        }

        return `<tr data-id="${q.id}">
            <td class="text-center text-muted">#${q.id}</td>
            <td class="question-text">${isImg}${isExplImg}${highlight(questionShort, term)}</td>
            <td class="classify-badges">
                <div class="level-name">
                    <span class="badge bg-secondary-subtle text-secondary-emphasis">${esc(q.level_name)}</span>
                    ${refBadge}
                </div>
                <div class="mt-1">
                    <span class="badge bg-light text-dark border">${esc(q.subject_name)}</span>
                    <span class="badge bg-light text-dark border">${esc(q.chapter_name)}</span>
                    <span class="badge bg-light text-dark border">${esc(q.topic_name)}</span>
                </div>
            </td>
            <td class="options-mini">${optsHtml}</td>
            <td>${statusHtml}</td>
            <td class="text-end"><div class="btn-group" role="group">${actions}</div></td>
        </tr>`;
    }

    function loadPage(page, query){
        const seq = ++requestSeq;
        $loading.addClass('show');

        $.getJSON('questions_ajax.php', { action: 'list', page: page, page_size: PAGE_SIZE, q: query })
            .done(function(res){
                if (seq !== requestSeq) return;
                currentPage = res.page;
                currentTotalPages = res.total_pages;

                if (!res.rows.length) {
                    $tableBody.html(`<tr><td colspan="6"><div class="empty-state"><i class="bi bi-emoji-frown"></i>No questions found.</div></td></tr>`);
                } else {
                    $tableBody.html(res.rows.map(r => renderRow(r, query)).join(''));
                }

                $countBadge.text(res.total + ' total');
                $pageInfo.text('Page ' + res.page + ' of ' + res.total_pages + ' (' + res.total + ' result' + (res.total !== 1 ? 's' : '') + ')');
                $prevBtn.prop('disabled', res.page <= 1);
                $nextBtn.prop('disabled', res.page >= res.total_pages);

                if (query) {
                    $searchStats.html(`<i class="bi bi-search"></i> Found ${res.total} question${res.total !== 1 ? 's' : ''} matching "${esc(query)}"`).css('color', 'var(--brand)');
                } else {
                    $searchStats.html(`<i class="bi bi-list-ul"></i> Showing ${res.total} question${res.total !== 1 ? 's' : ''}`).css('color', 'var(--muted)');
                }
            })
            .fail(function(){
                if (seq !== requestSeq) return;
                $tableBody.html(`<tr><td colspan="6"><div class="empty-state text-danger"><i class="bi bi-exclamation-triangle"></i>Couldn't load questions. Please retry.</div></td></tr>`);
            })
            .always(function(){
                if (seq === requestSeq) $loading.removeClass('show');
            });
    }

    let debounceTimer;
    $searchInput.on('input', function(){
        clearTimeout(debounceTimer);
        const val = $(this).val().trim();
        debounceTimer = setTimeout(function(){
            currentQuery = val;
            loadPage(1, currentQuery);
        }, 300);
    });

    $('#clearSearch').on('click', function(){
        $searchInput.val('');
        currentQuery = '';
        loadPage(1, '');
        $searchInput.focus();
    });

    $prevBtn.on('click', function(){ if (currentPage > 1) loadPage(currentPage - 1, currentQuery); });
    $nextBtn.on('click', function(){ if (currentPage < currentTotalPages) loadPage(currentPage + 1, currentQuery); });

    // ------ Detail modal ------
    $('#questionsTable').on('click', '.view-detail-btn', function(){
        const id = $(this).closest('tr').data('id');
        if(!id) return;

        $('#modalDetailBody').html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Loading...</div>');
        const modal = new bootstrap.Modal(document.getElementById('detailModal'));
        modal.show();

        $.getJSON('questions_ajax.php', { action: 'detail', id: id })
            .done(function(data){
                const opts = ['A','B','C','D'].map(o => {
                    const key = o.toLowerCase();
                    const val = data['option_' + key] || '';
                    const imgVal = data['image_url_option_' + key] || '';
                    const isCorrect = data.correct_option === o;

                    let imgHtml = '';
                    if(imgVal){
                        const viewable = toViewableImageUrl(imgVal);
                        if(viewable){
                            imgHtml = ` <img src="${esc(viewable)}" style="max-height:40px; max-width:40px; border-radius:4px; border:1px solid var(--border); object-fit:cover;" onerror="this.style.display='none'">`;
                        }
                    }

                    return `<div class="option-detail ${isCorrect ? 'text-success fw-bold' : ''}">
                                ${isCorrect ? '<i class="bi bi-check-circle-fill"></i>' : o + ')'} 
                                ${esc(val)}
                                ${imgHtml}
                            </div>`;
                }).join('');

                const statusBadge = data.authorized_status === 'Y'
                    ? '<span class="badge status-badge-Y"><i class="bi bi-shield-check"></i> Authorized</span>'
                    : '<span class="badge status-badge-N"><i class="bi bi-hourglass-split"></i> Pending</span>';

                const escOrDash = (v) => esc(v || '-');

                const imageHtml = data.image_url
                    ? `<dt>Image</dt><dd><img src="${esc(toViewableImageUrl(data.image_url))}" style="max-width:100%; max-height:280px; border-radius:8px; border:1px solid var(--border);" onerror="this.parentElement.innerHTML+='<div class=\\'text-danger small mt-1\\'>Image failed to load.</div>'"></dd>`
                    : '';

                // ⬇️ Explanation image in modal ⬇️
                const explanationImageHtml = data.explanation_image_url
                    ? `<dt>Explanation Image</dt><dd><img src="${esc(toViewableImageUrl(data.explanation_image_url))}" style="max-width:100%; max-height:280px; border-radius:8px; border:1px solid var(--border);" onerror="this.parentElement.innerHTML+='<div class=\\'text-danger small mt-1\\'>Explanation image failed to load.</div>'"></dd>`
                    : '';

                $('#modalDetailBody').html(`
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted">#${escOrDash(data.id)}</span>
                        ${statusBadge}
                    </div>
                    <dl class="mb-0">
                        <dt>Classification</dt>
                        <dd>${escOrDash(data.level_name)} &rsaquo; ${escOrDash(data.subject_name)} &rsaquo; ${escOrDash(data.chapter_name)} &rsaquo; ${escOrDash(data.topic_name)}</dd>

                        <dt>Question</dt>
                        <dd>${escOrDash(data.question)}</dd>

                        ${imageHtml}

                        <dt>Options</dt>
                        <dd>${opts}</dd>

                        <dt>Explanation</dt>
                        <dd>${escOrDash(data.explanation)}</dd>

                        ${explanationImageHtml}

                        <dt>Reference Exam</dt>
                        <dd>${escOrDash(data.reference_exam_name)}</dd>

                        <dt>Exam Reference (free text)</dt>
                        <dd>${escOrDash(data.exam_reference)}</dd>

                        <dt>Exam Year</dt>
                        <dd>${escOrDash(data.exam_year)}</dd>

                        <dt>Exam Date</dt>
                        <dd>${escOrDash(data.exam_date)}</dd>

                        <dt>Exam Taker</dt>
                        <dd>${escOrDash(data.exam_taker)}</dd>

                        <dt>Remarks</dt>
                        <dd>${escOrDash(data.remarks)}</dd>
                    </dl>
                `);
            })
            .fail(function(){
                $('#modalDetailBody').html('<div class="text-danger py-3"><i class="bi bi-exclamation-triangle"></i> Couldn\'t load this question.</div>');
            });
    });

    // Flash message auto-hide
    const $flash = $('.alert').first();
    if($flash.length){
        setTimeout(function(){
            $flash.css('transition', 'opacity .5s').css('opacity', 0);
            setTimeout(function(){ $flash.remove(); }, 500);
        }, 3000);
    }
});
</script>
</body>
</html>