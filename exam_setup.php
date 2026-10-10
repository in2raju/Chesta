<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user      = $_SESSION['user'];
$userId    = $user['user_id'];
$userType  = $user['user_type_id'];
$brCode    = $user['br_code'] ?? null;
$orgCode   = $user['org_code'] ?? null;

// Fetch levels for initial dropdown
$levelsStmt = $pdo->prepare("SELECT id, name FROM levels WHERE (BR_CODE = ? OR BR_CODE IS NULL) AND (ORG_CODE = ? OR ORG_CODE IS NULL) ORDER BY name ASC");
$levelsStmt->execute([$brCode, $orgCode]);
$levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Existing Exams for LOV Modal - Matched to your specific columns
$examsLOV = $pdo->prepare("SELECT exam_id, title, level_id, exam_date FROM exams_info WHERE (br_code=? OR br_code IS NULL) AND (org_code=? OR org_code IS NULL) ORDER BY created_date DESC");
$examsLOV->execute([$brCode, $orgCode]);
$examList = $examsLOV->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX requests for dropdowns and questions
if (isset($_GET['fetch']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $type = $_GET['fetch'];
    $id   = $_GET['id'];

    if ($type === 'subject') {
        $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE level_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $stmt->execute([$id, $brCode, $orgCode]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }
    // ... [Rest of your AJAX chapter/topic/question logic remains same] ...
    if ($type === 'chapter') {
        $stmt = $pdo->prepare("SELECT id, name FROM chapters WHERE subject_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $stmt->execute([$id, $brCode, $orgCode]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }
    if ($type === 'topic') {
        $stmt = $pdo->prepare("SELECT id, name FROM topics WHERE chapter_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $stmt->execute([$id, $brCode, $orgCode]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }
    if ($type === 'question') {
        $stmt = $pdo->prepare("SELECT id, question FROM questions WHERE topic_id = :topic_id AND (BR_CODE = :br OR BR_CODE IS NULL OR BR_CODE = '') AND (ORG_CODE = :org OR ORG_CODE IS NULL OR ORG_CODE = '') AND (AUTHORIZED_STATUS = 'Y' OR AUTHORIZED_STATUS IS NULL) ORDER BY id DESC");
        $stmt->execute([':topic_id' => $id, ':br' => $brCode, ':org' => $orgCode]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }
}

// Handle POST: save exam
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $title = trim($_POST['title'] ?? '');
    $selectedQuestions = $_POST['question_list'] ?? [];

    if ($title === '') $errors[] = "Exam title is required.";
    if (empty($selectedQuestions)) $errors[] = "Please add at least one question.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO exams_info (title, created_by, created_date, br_code, org_code, entry_user, entry_date) VALUES (:title, :created_by, NOW(), :br_code, :org_code, :entry_user, CURDATE())");
            $stmt->execute([':title'=>$title, ':created_by'=>$userId, ':br_code'=>$brCode, ':org_code'=>$orgCode, ':entry_user'=>$userId]);

            $examId = $pdo->lastInsertId();
            $stmtIns = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, br_code, org_code, entry_user, entry_date) VALUES (:exam_id, :question_id, :br, :org, :user, CURDATE())");
            foreach ($selectedQuestions as $qid) {
                $stmtIns->execute([':exam_id' => $examId, ':question_id' => (int)$qid, ':br' => $brCode, ':org' => $orgCode, ':user' => $userId]);
            }
            $pdo->commit();
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Exam</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .select2-container--bootstrap5 .select2-selection { height: calc(2.25rem + 2px); }
        #examTable tbody tr:hover { background-color: #f1f4f9; cursor: pointer; }
        .modal-header { border-bottom: none; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Create Exam</h3>

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">Exam saved successfully! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form id="examForm" method="post" class="row g-3">
                <input type="hidden" name="action" value="save">

                <div class="col-12">
                    <label class="form-label fw-bold">Exam Title <span class="text-danger">*</span></label>
                    <div class="input-group shadow-sm">
                        <input type="text" name="title" id="title" class="form-control" required placeholder="Type exam name...">
                        <button class="btn btn-dark" type="button" data-bs-toggle="modal" data-bs-target="#examLovModal">
                            <i class="bi bi-search"></i> Select Existing
                        </button>
                    </div>
                </div>

                <!-- Dropdowns -->
                <div class="col-md-3">
                    <label class="form-label">Level</label>
                    <select id="level" name="level" class="form-select select2">
                        <option value="">Select Level</option>
                        <?php foreach ($levels as $lv): ?>
                            <option value="<?= $lv['id'] ?>"><?= htmlspecialchars($lv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Subject</label><select id="subject" class="form-select select2"></select></div>
                <div class="col-md-3"><label class="form-label">Chapter</label><select id="chapter" class="form-select select2"></select></div>
                <div class="col-md-3"><label class="form-label">Topic</label><select id="topic" class="form-select select2"></select></div>

                <div class="col-md-9">
                    <label class="form-label">Search Questions</label>
                    <select id="question" class="form-select select2"><option value="">Select Question</option></select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" id="addQuestionBtn" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add Question</button>
                </div>

                <div class="col-12 mt-4">
                    <h6>Selected Questions: <span id="questionCount" class="badge bg-primary">0</span></h6>
                    <ul id="selectedQuestions" class="list-group list-group-flush border rounded"></ul>
                </div>

                <div id="hiddenInputs"></div>
                <div class="col-12 text-end border-top pt-3">
                    <button type="submit" class="btn btn-primary px-5">Save Exam Record</button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- LOV Modal -->
<div class="modal fade" id="examLovModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title">Search Existing Exams</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table id="examTable" class="table table-sm table-hover w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Exam ID</th>
                            <th>Title</th>
                            <th>Level ID</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examList as $ex): ?>
                        <tr onclick="selectExam('<?= htmlspecialchars($ex['title'], ENT_QUOTES) ?>', '<?= $ex['level_id'] ?>')">
                            <td class="text-muted small"><?= htmlspecialchars($ex['exam_id']) ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($ex['title']) ?></td>
                            <td><?= htmlspecialchars($ex['level_id']) ?></td>
                            <td class="small"><?= htmlspecialchars($ex['exam_date'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(function(){
    $('.select2').select2({ width: '100%' });

    // Initialize LOV Table
    var examTable = $('#examTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 20],
        autoWidth: false
    });

    // Fix for Modal DataTables Alignment
    $('#examLovModal').on('shown.bs.modal', function () {
        examTable.columns.adjust().draw();
    });

    // Selection Logic
    window.selectExam = function(title, levelId) {
        $('#title').val(title);
        if(levelId) {
            $('#level').val(levelId).trigger('change');
        }
        bootstrap.Modal.getInstance(document.getElementById('examLovModal')).hide();
    };

    // Cascade Dropdowns
    function load(target, type, id){
        if(!id){ $(target).empty().append('<option value="">Select</option>').trigger('change'); return; }
        $.get(window.location.pathname, { fetch: type, id: id }, function(data){
            $(target).empty().append('<option value="">Select</option>');
            data.forEach(row => {
                let text = (type === 'question') ? row.question : row.name;
                $(target).append(new Option(text, row.id));
            });
            $(target).trigger('change');
        }, 'json');
    }

    $('#level').on('change', function(){ load('#subject','subject', this.value); });
    $('#subject').on('change', function(){ load('#chapter','chapter', this.value); });
    $('#chapter').on('change', function(){ load('#topic','topic', this.value); });
    $('#topic').on('change', function(){ load('#question','question', this.value); });

    // Question Adding Logic
    let added = new Set();
    $('#addQuestionBtn').on('click', function(){
        const qid = $('#question').val();
        const qtext = $('#question option:selected').text();
        if(!qid || added.has(qid)) return;
        
        added.add(qid);
        $('#questionCount').text(added.size);

        const $li = $('<li class="list-group-item d-flex justify-content-between align-items-center"></li>')
                    .text(qtext)
                    .append($('<button type="button" class="btn btn-sm text-danger"><i class="bi bi-trash"></i></button>').click(function(){
                        added.delete(qid);
                        $li.remove();
                        $('#hidden-'+qid).remove();
                        $('#questionCount').text(added.size);
                    }));
        
        $('#selectedQuestions').append($li);
        $('#hiddenInputs').append(`<input type="hidden" name="question_list[]" id="hidden-${qid}" value="${qid}">`);
    });
});
</script>
</body>
</html>