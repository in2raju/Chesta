<?php
session_start();
// Buffer output so that any stray PHP notice/warning from db.php (or elsewhere)
// can never leak into an AJAX response and corrupt the JSON the dropdowns rely on.
ob_start();
require 'db.php'; // Ensure this file initializes $pdo

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user      = $_SESSION['user'];
$userId    = $user['user_id'] ?? $user['ENTRY_USER'] ?? 'System';
$userType  = $user['user_type_id'];
$brCode    = $user['br_code'] ?? null;
$orgCode   = $user['org_code'] ?? null;

// Give slow / broad queries more headroom (some hosts default to 30s).
@set_time_limit(60);

// A normal try/catch CANNOT catch a fatal error like "Maximum execution time exceeded"
// or "Allowed memory size exhausted" — PHP just kills the script mid-output. That produces
// exactly "HTTP 200 with an empty/broken body," which looks identical to a JSON encoding
// bug from the browser's side but isn't one. This shutdown handler is the only way to catch
// that case and still hand back a readable JSON error instead of silence.
register_shutdown_function(function () {
    if (!isset($_GET['fetch'])) return; // only relevant for the AJAX endpoints
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return; // normal completion, nothing to do
    }
    if (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) { header('Content-Type: application/json'); }
    echo json_encode([
        'ok'    => false,
        'error' => 'Fatal: ' . $error['message'] . ' (' . basename($error['file']) . ':' . $error['line'] . ')',
    ]);
});

// Something in this hosting environment (a widget, badge, or analytics snippet — not part of
// this app) appends stray HTML to every PHP response via its own shutdown hook, even after
// exit(). That silently corrupts JSON responses. Registering THIS shutdown function wins the
// "last word": PHP runs shutdown callbacks in registration order, and since this one is
// registered right when we're about to emit JSON (later than anything hooked in earlier via
// db.php / an auto_prepend file / etc.), it runs last — wipes every output buffer and
// re-prints only the exact JSON we intended, discarding whatever got tacked on after it.
function emit_json_and_exit($data) {
    $json = safe_json_encode($data);
    register_shutdown_function(function () use ($json) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo $json;
    });
    exit();
}

// safe_json_encode() silently returns false (empty response body) the moment it hits a byte
// sequence that isn't valid UTF-8 — very common with Bangla/mixed-encoding data that was
// stored with the wrong DB/connection charset. This wrapper substitutes invalid bytes
// instead of failing outright, and keeps non-ASCII characters (Bangla, etc.) readable
// instead of escaping them to \uXXXX.
function safe_json_encode($data) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) { // PHP 7.2+
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $out = json_encode($data, $flags);
    if ($out === false) {
        // Last-resort fallback so the client still gets valid JSON instead of an empty body.
        return json_encode(['ok' => false, 'error' => 'json_encode failed: ' . json_last_error_msg()]);
    }
    return $out;
}

// Fetch levels for initial dropdown
$levelsStmt = $pdo->prepare("SELECT id, name FROM levels WHERE (BR_CODE = ? OR BR_CODE IS NULL) AND (ORG_CODE = ? OR ORG_CODE IS NULL) ORDER BY name ASC");
$levelsStmt->execute([$brCode, $orgCode]);
$levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Exam Types
$examTypesStmt = $pdo->prepare("SELECT id, type_name AS name FROM exam_types ORDER BY type_name ASC");
$examTypesStmt->execute();
$examTypes = $examTypesStmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- Handle AJAX requests for dropdowns and questions ----------
if (isset($_GET['fetch'])) {
    // Wipe anything accidentally echoed/warned so far, then guarantee clean JSON from here on.
    ob_clean();
    header('Content-Type: application/json');
    $type = $_GET['fetch'];
    $id   = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;

    // Small helper so every branch below fails loudly (as JSON) instead of silently
    // returning nothing, which is what made empty chapter/topic lists indistinguishable
    // from "there's genuinely no data" in the UI.
    $respond = function($stmt, $params) {
        try {
            $stmt->execute($params);
            emit_json_and_exit(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            http_response_code(500);
            emit_json_and_exit(['ok' => false, 'error' => $e->getMessage()]);
        }
    };

    if ($type === 'subject') {
        if (!$id) { emit_json_and_exit(['ok' => true, 'rows' => []]); }
        $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE level_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $respond($stmt, [$id, $brCode, $orgCode]);
    }
    if ($type === 'chapter') {
        if (!$id) { emit_json_and_exit(['ok' => true, 'rows' => []]); }
        $stmt = $pdo->prepare("SELECT id, name FROM chapters WHERE subject_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $respond($stmt, [$id, $brCode, $orgCode]);
    }
    if ($type === 'topic') {
        if (!$id) { emit_json_and_exit(['ok' => true, 'rows' => []]); }
        $stmt = $pdo->prepare("SELECT id, name FROM topics WHERE chapter_id=? AND (BR_CODE=? OR BR_CODE IS NULL) AND (ORG_CODE=? OR ORG_CODE IS NULL) ORDER BY name");
        $respond($stmt, [$id, $brCode, $orgCode]);
    }

    // Unified question search: works with any combination of level/subject/chapter/topic,
    // plus an optional free-text keyword. This is what powers the fast question picker.
    if ($type === 'question') {
        $levelId   = !empty($_GET['level_id'])   ? (int)$_GET['level_id']   : null;
        $subjectId = !empty($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
        $chapterId = !empty($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : null;
        $topicId   = !empty($_GET['topic_id'])   ? (int)$_GET['topic_id']   : null;
        $keyword   = trim($_GET['q'] ?? '');
        $pageSize  = 200;
        $offset    = max(0, (int)($_GET['offset'] ?? 0));

        // Only join as far up the hierarchy as the active filter actually needs.
        $joins = "";
        if ($topicId) {
            $joins = "";
        } elseif ($chapterId) {
            $joins = "LEFT JOIN topics t ON t.id = q.topic_id";
        } elseif ($subjectId) {
            $joins = "LEFT JOIN topics t ON t.id = q.topic_id
                      LEFT JOIN chapters c ON c.id = t.chapter_id";
        } elseif ($levelId) {
            $joins = "LEFT JOIN topics t ON t.id = q.topic_id
                      LEFT JOIN chapters c ON c.id = t.chapter_id
                      LEFT JOIN subjects s ON s.id = c.subject_id";
        }

        $baseSql = "FROM questions q
                $joins
                WHERE (q.BR_CODE = :br OR q.BR_CODE IS NULL OR q.BR_CODE = '')
                  AND (q.ORG_CODE = :org OR q.ORG_CODE IS NULL OR q.ORG_CODE = '')
                  AND (q.AUTHORIZED_STATUS = 'Y' OR q.AUTHORIZED_STATUS = 'N')";
        $params = [':br' => $brCode, ':org' => $orgCode];

        if ($topicId) {
            $baseSql .= " AND q.topic_id = :topic_id";
            $params[':topic_id'] = $topicId;
        } elseif ($chapterId) {
            $baseSql .= " AND t.chapter_id = :chapter_id";
            $params[':chapter_id'] = $chapterId;
        } elseif ($subjectId) {
            $baseSql .= " AND c.subject_id = :subject_id";
            $params[':subject_id'] = $subjectId;
        } elseif ($levelId) {
            $baseSql .= " AND s.level_id = :level_id";
            $params[':level_id'] = $levelId;
        }

        if ($keyword !== '') {
            $baseSql .= " AND q.question LIKE :kw";
            $params[':kw'] = '%' . $keyword . '%';
        }

        try {
            // Total count first, so the UI can say "showing 200 of 743" instead of
            // silently truncating at the page size with no indication more exist.
            $countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseSql);
            $countStmt->execute($params);
            $totalMatching = (int)$countStmt->fetchColumn();

            // The display query still needs the full join chain for the *_name columns,
            // regardless of which joins were needed for filtering above.
            $displaySql = "SELECT q.id, q.question, q.exam_reference, q.remarks,
                           t.name AS topic_name, c.name AS chapter_name,
                           s.name AS subject_name, l.name AS level_name
                    FROM questions q
                    LEFT JOIN topics t   ON t.id = q.topic_id
                    LEFT JOIN chapters c ON c.id = t.chapter_id
                    LEFT JOIN subjects s ON s.id = c.subject_id
                    LEFT JOIN levels l   ON l.id = s.level_id
                    WHERE (q.BR_CODE = :br OR q.BR_CODE IS NULL OR q.BR_CODE = '')
                      AND (q.ORG_CODE = :org OR q.ORG_CODE IS NULL OR q.ORG_CODE = '')
                      AND (q.AUTHORIZED_STATUS = 'Y' OR q.AUTHORIZED_STATUS = 'N')";
            if ($topicId) {
                $displaySql .= " AND q.topic_id = :topic_id";
            } elseif ($chapterId) {
                $displaySql .= " AND t.chapter_id = :chapter_id";
            } elseif ($subjectId) {
                $displaySql .= " AND c.subject_id = :subject_id";
            } elseif ($levelId) {
                $displaySql .= " AND s.level_id = :level_id";
            }
            if ($keyword !== '') {
                $displaySql .= " AND q.question LIKE :kw";
            }
            $displaySql .= " ORDER BY q.id DESC LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($displaySql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            http_response_code(500);
            emit_json_and_exit(['ok' => false, 'error' => $e->getMessage()]);
        }

        $result = [
            'rows'     => $rows,
            'total'    => $totalMatching,
            'offset'   => $offset,
            'has_more' => ($offset + count($rows)) < $totalMatching,
        ];

        // If nothing came back, find out WHY
        if (count($rows) === 0) {
            $diagSql = "SELECT
                    COUNT(*) AS total_unscoped,
                    SUM(CASE WHEN (q.BR_CODE = :br OR q.BR_CODE IS NULL OR q.BR_CODE = '')
                              AND (q.ORG_CODE = :org OR q.ORG_CODE IS NULL OR q.ORG_CODE = '')
                             THEN 1 ELSE 0 END) AS matches_br_org,
                    SUM(CASE WHEN q.AUTHORIZED_STATUS IN ('Y','N') THEN 1 ELSE 0 END) AS matches_status
                FROM questions q
                LEFT JOIN topics t   ON t.id = q.topic_id
                LEFT JOIN chapters c ON c.id = t.chapter_id
                LEFT JOIN subjects s ON s.id = c.subject_id
                LEFT JOIN levels l   ON l.id = s.level_id
                WHERE 1=1";
            $diagParams = [':br' => $brCode, ':org' => $orgCode];

            if ($topicId) {
                $diagSql .= " AND q.topic_id = :topic_id";
                $diagParams[':topic_id'] = $topicId;
            } elseif ($chapterId) {
                $diagSql .= " AND t.chapter_id = :chapter_id";
                $diagParams[':chapter_id'] = $chapterId;
            } elseif ($subjectId) {
                $diagSql .= " AND c.subject_id = :subject_id";
                $diagParams[':subject_id'] = $subjectId;
            } elseif ($levelId) {
                $diagSql .= " AND s.level_id = :level_id";
                $diagParams[':level_id'] = $levelId;
            }

            try {
                $diagStmt = $pdo->prepare($diagSql);
                $diagStmt->execute($diagParams);
                $diag = $diagStmt->fetch(PDO::FETCH_ASSOC);
                if ($diag && (int)$diag['total_unscoped'] > 0) {
                    $result['diagnostic'] = [
                        'total_unscoped'  => (int)$diag['total_unscoped'],
                        'matches_br_org'  => (int)$diag['matches_br_org'],
                        'matches_status'  => (int)$diag['matches_status'],
                    ];
                }
            } catch (Throwable $e) {
                // Diagnostics are best-effort only
            }
        }

        emit_json_and_exit($result);
    }

    // Fetch a single exam + its questions (used to hydrate the edit form)
    if ($type === 'exam') {
        $examId = $_GET['exam_id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM exams_info WHERE exam_id = ? AND (br_code = ? OR br_code IS NULL) AND (org_code = ? OR org_code IS NULL)");
        $stmt->execute([$examId, $brCode, $orgCode]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exam) { emit_json_and_exit(['error' => 'not_found']); }

        $qStmt = $pdo->prepare("SELECT q.id, q.question, q.exam_reference, q.remarks FROM exam_questions eq
                                 JOIN questions q ON q.id = eq.question_id
                                 WHERE eq.exam_id = ? ORDER BY eq.id ASC");
        $qStmt->execute([$examId]);
        $exam['questions'] = $qStmt->fetchAll(PDO::FETCH_ASSOC);
        emit_json_and_exit($exam);
    }

    emit_json_and_exit([]);
}

// ---------- Handle POST: save / update / delete / toggle-publish exam ----------
$errors = [];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $delExamId = $_POST['exam_id'] ?? '';
    if ($delExamId !== '') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM exam_questions WHERE exam_id = ?")->execute([$delExamId]);
            $pdo->prepare("DELETE FROM exams_info WHERE exam_id = ?")->execute([$delExamId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1#exam-list");
    exit();
}

// Toggle an exam between Published (AUTHORIZED_STATUS = 'Y') and Unpublished ('N').
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_status') {
    $toggleExamId = $_POST['exam_id'] ?? '';
    $isAjax = !empty($_POST['ajax']);
    $newStatus = null;
    $ok = false;
    $errorMsg = '';

    if ($toggleExamId !== '') {
        try {
            $stmt = $pdo->prepare("SELECT AUTHORIZED_STATUS FROM exams_info WHERE exam_id = ? AND (br_code = ? OR br_code IS NULL) AND (org_code = ? OR org_code IS NULL)");
            $stmt->execute([$toggleExamId, $brCode, $orgCode]);
            $currentStatus = $stmt->fetchColumn();

            if ($currentStatus !== false) {
                $newStatus = ($currentStatus === 'Y') ? 'N' : 'Y';

                $upd = $pdo->prepare("UPDATE exams_info SET
                        AUTHORIZED_STATUS = :status,
                        AUTHORIZED_USER   = :auth_user,
                        AUTHORIZED_DATE   = CURDATE()
                    WHERE exam_id = :exam_id
                      AND (br_code = :br_code OR br_code IS NULL)
                      AND (org_code = :org_code OR org_code IS NULL)");
                $upd->execute([
                    ':status'    => $newStatus,
                    ':auth_user' => $userId,
                    ':exam_id'   => $toggleExamId,
                    ':br_code'   => $brCode,
                    ':org_code'  => $orgCode,
                ]);
                $ok = true;
            } else {
                $errorMsg = 'Exam not found or not accessible.';
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
        }
    } else {
        $errorMsg = 'Missing exam id.';
    }

    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        emit_json_and_exit([
            'ok'     => $ok,
            'status' => $newStatus,
            'error'  => $errorMsg,
        ]);
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?status_updated=1#list-pane");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'update'], true)) {
    $isUpdate          = ($action === 'update');
    $examIdPost        = trim($_POST['exam_id'] ?? '');
    $title             = trim($_POST['title'] ?? '');
    $remarks           = trim($_POST['remarks'] ?? '');                // ← NEW
    $examTypeId        = !empty($_POST['exam_type_id']) ? (int)$_POST['exam_type_id'] : null;
    $levelId           = !empty($_POST['level']) ? (int)$_POST['level'] : null;
    $subjectId         = !empty($_POST['subject']) ? (int)$_POST['subject'] : null;
    $chapterId         = !empty($_POST['chapter']) ? (int)$_POST['chapter'] : null;
    $topicId           = !empty($_POST['topic']) ? (int)$_POST['topic'] : null;
    $selectedQuestions = $_POST['question_list'] ?? [];

    // Cap remarks to a sane length (matches your DB column size)
    if (mb_strlen($remarks) > 1000) {
        $remarks = mb_substr($remarks, 0, 1000);
    }

    if ($title === '') $errors[] = "Exam title is required.";
    if (!$examTypeId) $errors[] = "Exam type is required.";
    if (!$levelId) $errors[] = "Level selection is required.";
    if (!is_array($selectedQuestions) || count($selectedQuestions) === 0) $errors[] = "Please add at least one question.";
    if ($isUpdate && $examIdPost === '') $errors[] = "Missing exam reference for update.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($isUpdate) {
                $examId = $examIdPost;

                $stmt = $pdo->prepare("UPDATE exams_info SET
                        exam_type_id = :exam_type_id,
                        level_id     = :level_id,
                        subject_id   = :subject_id,
                        chapter_id   = :chapter_id,
                        topic_id     = :topic_id,
                        title        = :title,
                        remarks      = :remarks
                    WHERE exam_id = :exam_id
                      AND (br_code = :br_code OR br_code IS NULL)
                      AND (org_code = :org_code OR org_code IS NULL)");

                $stmt->execute([
                    ':exam_type_id' => $examTypeId,
                    ':level_id'     => $levelId,
                    ':subject_id'   => $subjectId,
                    ':chapter_id'   => $chapterId,
                    ':topic_id'     => $topicId,
                    ':title'        => $title,
                    ':remarks'      => ($remarks !== '' ? $remarks : null),
                    ':exam_id'      => $examId,
                    ':br_code'      => $brCode,
                    ':org_code'     => $orgCode,
                ]);

                // Replace question list wholesale (simplest, safest way to keep it in sync)
                $pdo->prepare("DELETE FROM exam_questions WHERE exam_id = ?")->execute([$examId]);

            } else {
                $examId = 'EXAM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();

                $stmt = $pdo->prepare("INSERT INTO exams_info (
                    exam_id, exam_type_id, level_id, subject_id, chapter_id, topic_id,
                    title, remarks, created_date, br_code, org_code,
                    ENTRY_DATE, ENTRY_USER, AUTHORIZED_STATUS
                ) VALUES (
                    :exam_id, :exam_type_id, :level_id, :subject_id, :chapter_id, :topic_id,
                    :title, :remarks, NOW(), :br_code, :org_code,
                    CURDATE(), :entry_user, 'N'
                )");

                $stmt->execute([
                    ':exam_id'      => $examId,
                    ':exam_type_id' => $examTypeId,
                    ':level_id'     => $levelId,
                    ':subject_id'   => $subjectId,
                    ':chapter_id'   => $chapterId,
                    ':topic_id'     => $topicId,
                    ':title'        => $title,
                    ':remarks'      => ($remarks !== '' ? $remarks : null),
                    ':br_code'      => $brCode,
                    ':org_code'     => $orgCode,
                    ':entry_user'   => $userId
                ]);
            }

            $stmtIns = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, br_code, org_code, entry_user, entry_date)
                                      VALUES (:exam_id, :question_id, :br, :org, :user, CURDATE())");
            foreach ($selectedQuestions as $qid) {
                $qid = (int)$qid;
                if ($qid <= 0) continue;
                $stmtIns->execute([
                    ':exam_id'     => $examId,
                    ':question_id' => $qid,
                    ':br'          => $brCode,
                    ':org'         => $orgCode,
                    ':user'        => $userId
                ]);
            }

            $pdo->commit();

            $flag = $isUpdate ? 'updated=1' : 'success=1';
            header("Location: " . $_SERVER['PHP_SELF'] . "?" . $flag);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database operation failed: " . $e->getMessage();
        }
    }
}

// ---------- Editing an existing exam? Preload its data ----------
$editExamId = $_GET['edit'] ?? null;
$editData   = null;
$editQuestions = [];
if ($editExamId) {
    $stmt = $pdo->prepare("SELECT * FROM exams_info WHERE exam_id = ? AND (br_code = ? OR br_code IS NULL) AND (org_code = ? OR org_code IS NULL)");
    $stmt->execute([$editExamId, $brCode, $orgCode]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editData) {
        $qStmt = $pdo->prepare("SELECT q.id, q.question, q.exam_reference, q.remarks FROM exam_questions eq
                                 JOIN questions q ON q.id = eq.question_id
                                 WHERE eq.exam_id = ? ORDER BY eq.id ASC");
        $qStmt->execute([$editExamId]);
        $editQuestions = $qStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ---------- Exam list ----------
$examListStmt = $pdo->prepare("SELECT e.exam_id, e.title, e.remarks, e.created_date, e.AUTHORIZED_STATUS,
        et.type_name AS exam_type_name, l.name AS level_name,
        (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.exam_id) AS q_count
    FROM exams_info e
    LEFT JOIN exam_types et ON et.id = e.exam_type_id
    LEFT JOIN levels l ON l.id = e.level_id
    WHERE (e.br_code = ? OR e.br_code IS NULL) AND (e.org_code = ? OR e.org_code IS NULL)
    ORDER BY e.created_date DESC, e.exam_id DESC");
$examListStmt->execute([$brCode, $orgCode]);
$examList = $examListStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exam</title>
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

        .nav-tabs .nav-link{ font-weight:600; color:var(--muted); border:none; border-bottom:3px solid transparent; }
        .nav-tabs .nav-link.active{ color:var(--brand-dark); border-bottom-color:var(--brand); background:transparent; }
        .nav-tabs{ border-bottom:1px solid var(--border); margin-bottom:1.25rem; }

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
        .form-control, .form-select{
            border-radius:10px;
            border-color:var(--border);
        }
        .form-control:focus, .form-select:focus{
            border-color:var(--brand);
            box-shadow:0 0 0 3px var(--brand-soft);
        }
        textarea.form-control{ resize:vertical; min-height:60px; }

        .select2-container--bootstrap-5 .select2-selection{ min-height:calc(2.25rem + 2px); border-radius:10px; }

        .search-box{ position:relative; }
        .search-box i{
            position:absolute; left:.85rem; top:50%; transform:translateY(-50%);
            color:var(--muted);
        }
        .search-box input{ padding-left:2.25rem; border-radius:10px; }

        .question-pool{
            max-height:420px;
            overflow-y:auto;
            border:1px solid var(--border);
            border-radius:10px;
            background:#fafbfd;
        }
        .question-row{
            display:flex;
            gap:.75rem;
            align-items:flex-start;
            padding:.75rem .9rem;
            border-bottom:1px solid var(--border);
            cursor:pointer;
            transition:background .12s ease;
        }
        .question-row:last-child{ border-bottom:none; }
        .question-row:hover{ background:var(--brand-soft); }
        .question-row.is-added{ background:#f0fdf4; }
        .question-row input[type="checkbox"]{ margin-top:.2rem; width:1.05rem; height:1.05rem; cursor:pointer; }
        .question-row .q-text{ font-size:.92rem; line-height:1.4; }
        .question-row .q-meta{ font-size:.72rem; color:var(--muted); margin-top:.15rem; }
        .question-row .q-reference{ font-size:.75rem; color:var(--brand); font-weight:500; }
        .question-row .q-remarks{ font-size:.72rem; color:var(--muted); font-style:italic; }
        .question-row .badge-added{ font-size:.65rem; }

        .empty-state{ text-align:center; color:var(--muted); padding:2.5rem 1rem; font-size:.9rem; }
        .empty-state i{ font-size:1.8rem; display:block; margin-bottom:.5rem; opacity:.5; }

        .selected-panel{
            max-height:420px;
            overflow-y:auto;
        }
        .selected-item{
            display:flex;
            align-items:flex-start;
            gap:.6rem;
            padding:.65rem .5rem;
            border-bottom:1px solid var(--border);
        }
        .selected-item:last-child{ border-bottom:none; }
        .selected-item .idx{
            flex:0 0 22px;
            font-size:.75rem;
            color:var(--muted);
            font-weight:600;
            margin-top:.15rem;
        }
        .selected-item .txt{ font-size:.86rem; line-height:1.35; flex:1; }
        .selected-item .txt .ref{
            font-size:.7rem;
            color:var(--brand);
            font-weight:500;
            display:block;
            margin-top:.1rem;
        }
        .selected-item .txt .rmk{
            font-size:.7rem;
            color:var(--muted);
            font-style:italic;
            display:block;
        }
        .selected-item button{
            border:none; background:transparent; color:#dc2626;
            padding:0 .25rem; line-height:1; font-size:1rem;
        }
        .selected-item button:hover{ color:#991b1b; }

        .count-pill{
            background:var(--brand-soft);
            color:var(--brand-dark);
            font-weight:700;
            border-radius:999px;
            padding:.2rem .65rem;
            font-size:.8rem;
        }

        .btn-brand{
            background-color:var(--brand) !important;
            border-color:var(--brand) !important;
            color:#fff !important;
            font-weight:600;
        }
        .btn-brand:hover{ background-color:var(--brand-dark) !important; border-color:var(--brand-dark) !important; }
        .btn-brand:disabled{ background-color:#a5a6f6 !important; border-color:#a5a6f6 !important; opacity:1 !important; color:#fff !important; }
        .btn-outline-brand{
            color:var(--brand) !important;
            border-color:var(--brand) !important;
        }
        .btn-outline-brand:hover{ background:var(--brand) !important; color:#fff !important; }

        .btn-publish-toggle{ font-weight:600; min-width:108px; }
        .btn-publish-toggle.is-published{
            background-color:#ecfdf5 !important;
            border-color:#a7f3d0 !important;
            color:#166534 !important;
        }
        .btn-publish-toggle.is-published:hover{
            background-color:#166534 !important;
            border-color:#166534 !important;
            color:#fff !important;
        }
        .btn-publish-toggle.is-unpublished{
            background-color:#fffbeb !important;
            border-color:#fde68a !important;
            color:#92400e !important;
        }
        .btn-publish-toggle.is-unpublished:hover{
            background-color:#92400e !important;
            border-color:#92400e !important;
            color:#fff !important;
        }

        .save-bar{
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:1rem 1.25rem;
            margin-top:1.25rem;
            display:flex;
            justify-content:flex-end;
            align-items:center;
            gap:.75rem;
            position:sticky;
            bottom:1rem;
            z-index:5;
            box-shadow:0 10px 25px -10px rgba(0,0,0,.15);
        }

        .spinner-mini{ width:1rem; height:1rem; }

        .status-badge-Y{ background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .status-badge-N{ background:#fffbeb; color:#92400e; border:1px solid #fde68a; }

        .edit-banner{
            background:#fff7ed;
            border:1px solid #fed7aa;
            color:#9a3412;
            border-radius:10px;
            padding:.65rem 1rem;
            font-size:.85rem;
            display:flex;
            align-items:center;
            gap:.5rem;
            margin-bottom:1rem;
        }

        .exam-remarks{
            font-size:.72rem;
            color:var(--muted);
            font-style:italic;
            margin-top:.15rem;
            display:flex;
            align-items:flex-start;
            gap:.25rem;
        }
        .exam-remarks i{ margin-top:.15rem; opacity:.7; flex-shrink:0; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">

    <div class="page-head">
        <h3><i class="bi bi-journal-plus me-2"></i>Exams</h3>
        <p>Create or edit an exam, then search and add questions in one place.</p>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i> Exam saved successfully.
        </div>
    <?php elseif (!empty($_GET['updated'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i> Exam updated successfully.
        </div>
    <?php elseif (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i> Exam deleted.
        </div>
    <?php elseif (!empty($_GET['status_updated'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i> Exam publish status updated.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $er): ?>
                    <li><?= htmlspecialchars($er) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($editExamId && !$editData): ?>
        <div class="alert alert-warning">Exam <?= htmlspecialchars($editExamId) ?> was not found.</div>
    <?php endif; ?>

    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $editData ? 'active' : 'active' ?>" id="create-tab" data-bs-toggle="tab" data-bs-target="#create-pane" type="button" role="tab">
                <i class="bi bi-pencil-square"></i> <?= $editData ? 'Edit Exam' : 'Create Exam' ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="list-tab" data-bs-toggle="tab" data-bs-target="#list-pane" type="button" role="tab">
                <i class="bi bi-list-ul"></i> Exam List <span class="count-pill ms-1"><?= count($examList) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
    <div class="tab-pane fade show active" id="create-pane" role="tabpanel">

    <?php if ($editData): ?>
        <div class="edit-banner">
            <i class="bi bi-info-circle-fill"></i>
            Editing <strong><?= htmlspecialchars($editData['title']) ?></strong> (<?= htmlspecialchars($editData['exam_id']) ?>).
            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="ms-auto">Start a new exam instead</a>
        </div>
    <?php endif; ?>

    <form id="examForm" method="post">
        <input type="hidden" name="action" id="formAction" value="<?= $editData ? 'update' : 'save' ?>">
        <input type="hidden" name="exam_id" id="exam_id" value="<?= $editData ? htmlspecialchars($editData['exam_id']) : '' ?>">

        <!-- Exam details -->
        <div class="card-modern mb-3">
            <div class="card-header"><i class="bi bi-info-circle text-primary"></i> Exam Details</div>
            <div class="card-body row g-3">
                <div class="col-md-8">
                    <label class="form-label">Exam Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="title" class="form-control" required
                           value="<?= $editData ? htmlspecialchars($editData['title']) : (isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '') ?>"
                           placeholder="e.g. Mid-Term Physics Assessment">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Exam Type <span class="text-danger">*</span></label>
                    <select name="exam_type_id" id="exam_type_id" class="form-select select2" required>
                        <option value="">Select Type</option>
                        <?php foreach ($examTypes as $type): ?>
                            <option value="<?= $type['id'] ?>" <?= ($editData && (int)$editData['exam_type_id'] === (int)$type['id']) ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ✨ NEW: Remarks field -->
                <div class="col-12">
                    <label class="form-label" for="remarks">
                        Remarks / Notes
                        <span class="text-muted" style="font-weight:400;">(optional)</span>
                    </label>
                    <textarea name="remarks" id="remarks" class="form-control" rows="2" maxlength="1000"
                              placeholder="e.g. Final exam for Class 10 — Physics, chapters 1–5. Duration: 60 min."><?php
                        if ($editData) {
                            echo htmlspecialchars($editData['remarks'] ?? '');
                        } elseif (isset($_POST['remarks'])) {
                            echo htmlspecialchars($_POST['remarks']);
                        }
                    ?></textarea>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-info-circle"></i>
                        এই নোট শুধু আপনি দেখতে পাবেন, শিক্ষার্থীরা নয়। সর্বোচ্চ ১০০০ অক্ষর।
                    </small>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Level <span class="text-danger">*</span></label>
                    <select id="level" name="level" class="form-select select2" required>
                        <option value="">Select Level</option>
                        <?php foreach ($levels as $lv): ?>
                            <option value="<?= $lv['id'] ?>" <?= ($editData && (int)$editData['level_id'] === (int)$lv['id']) ? 'selected' : '' ?>><?= htmlspecialchars($lv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Subject</label>
                    <select id="subject" name="subject" class="form-select select2">
                        <option value="">Select Subject</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Chapter</label>
                    <select id="chapter" name="chapter" class="form-select select2">
                        <option value="">Select Chapter</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Topic</label>
                    <select id="topic" name="topic" class="form-select select2">
                        <option value="">Select Topic</option>
                    </select>
                </div>
                <div class="col-12">
                    <small class="text-muted"><i class="bi bi-lightbulb"></i> Level is required; Subject/Chapter/Topic are optional filters to narrow the question pool below. Every dropdown is searchable &mdash; just start typing.</small>
                </div>
            </div>
        </div>

        <!-- Question picker -->
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card-modern h-100">
                    <div class="card-header justify-content-between">
                        <span><i class="bi bi-search text-primary"></i> Find Questions</span>
                        <span id="poolCount" class="text-muted" style="font-size:.8rem;"></span>
                    </div>
                    <div class="card-body">
                        <div class="search-box mb-3">
                            <i class="bi bi-search"></i>
                            <input type="text" id="questionSearch" class="form-control" placeholder="Type keywords to search questions...">
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllVisible">
                                <label class="form-check-label" for="selectAllVisible" style="font-size:.85rem;">Select all shown</label>
                            </div>
                            <button type="button" id="addSelectedBtn" class="btn btn-sm btn-brand" disabled>
                                <i class="bi bi-plus-lg"></i> Add Selected (<span id="checkedCount">0</span>)
                            </button>
                        </div>
                        <div id="questionPool" class="question-pool">
                            <div class="empty-state">
                                <i class="bi bi-funnel"></i>
                                Pick a Level (and optionally Subject/Chapter/Topic) or type a keyword to see matching questions.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card-modern h-100">
                    <div class="card-header justify-content-between">
                        <span><i class="bi bi-list-check text-success"></i> Selected Questions</span>
                        <span class="count-pill" id="questionCount">0</span>
                    </div>
                    <div class="card-body">
                        <div id="selectedQuestions" class="selected-panel">
                            <div class="empty-state" id="selectedEmpty">
                                <i class="bi bi-inbox"></i>
                                No questions added yet.
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-2" id="clearAllWrap" style="display:none;">
                            <button type="button" id="clearAllBtn" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="hiddenInputs"></div>

        <div class="save-bar">
            <?php if ($editData): ?>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary">Cancel Edit</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-brand btn-lg px-4">
                <i class="bi bi-check2-circle"></i> <span id="saveBtnLabel"><?= $editData ? 'Update Exam' : 'Save Exam' ?></span>
            </button>
        </div>
    </form>

    </div><!-- /create-pane -->

    <div class="tab-pane fade" id="list-pane" role="tabpanel">
        <div class="card-modern">
            <div class="card-header justify-content-between">
                <span><i class="bi bi-list-ul text-primary"></i> All Exams</span>
                <span class="count-pill" id="examListCount"><?= count($examList) ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($examList)): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i>No exams created yet.</div>
                <?php else: ?>
                <div class="p-3 pb-0">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="examListSearch" class="form-control" placeholder="Search by title, ID, type, level, remarks or status...">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="examListTable">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Level</th>
                                <th>Questions</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($examList as $ex):
                            $isPublished = $ex['AUTHORIZED_STATUS'] === 'Y';
                            $statusLabel = $isPublished ? 'Authorized' : 'Pending';
                            $searchBlob = strtolower(
                                $ex['title'] . ' ' .
                                $ex['exam_id'] . ' ' .
                                ($ex['exam_type_name'] ?? '') . ' ' .
                                ($ex['level_name'] ?? '') . ' ' .
                                $statusLabel . ' ' .
                                ($ex['remarks'] ?? '')
                            );
                        ?>
                            <tr class="exam-row" data-search="<?= htmlspecialchars($searchBlob) ?>">
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($ex['title']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($ex['exam_id']) ?></div>
                                    <?php if (!empty($ex['remarks'])): ?>
                                        <div class="exam-remarks" title="<?= htmlspecialchars($ex['remarks']) ?>">
                                            <i class="bi bi-chat-left-text"></i>
                                            <span><?= htmlspecialchars(mb_strimwidth($ex['remarks'], 0, 80, '…')) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($ex['exam_type_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ex['level_name'] ?? '-') ?></td>
                                <td><span class="count-pill"><?= (int)$ex['q_count'] ?></span></td>
                                <td>
                                    <span class="badge status-badge-<?= $isPublished ? 'Y' : 'N' ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($ex['created_date']) ?></td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-publish-toggle <?= $isPublished ? 'is-published' : 'is-unpublished' ?> toggle-status-btn"
                                            data-exam-id="<?= htmlspecialchars($ex['exam_id']) ?>"
                                            data-exam-title="<?= htmlspecialchars($ex['title']) ?>"
                                            data-current-status="<?= $isPublished ? 'Y' : 'N' ?>"
                                            title="<?= $isPublished ? 'Click to unpublish' : 'Click to publish' ?>">
                                        <i class="bi <?= $isPublished ? 'bi-eye-fill' : 'bi-eye-slash-fill' ?>"></i>
                                        <?= $isPublished ? 'Published' : 'Unpublished' ?>
                                    </button>
                                    <a href="?edit=<?= urlencode($ex['exam_id']) ?>#create-pane" class="btn btn-sm btn-outline-brand">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-exam-btn"
                                            data-exam-id="<?= htmlspecialchars($ex['exam_id']) ?>"
                                            data-exam-title="<?= htmlspecialchars($ex['title']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="examListEmpty" class="empty-state" style="display:none;">
                        <i class="bi bi-search"></i>No exams match your search.
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /list-pane -->
    </div><!-- /tab-content -->

</main>

<form id="deleteForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="exam_id" id="deleteExamId">
</form>

<form id="toggleStatusForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="exam_id" id="toggleStatusExamId">
</form>

<?php include 'footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
    $('.select2').select2({ width: '100%', theme: 'bootstrap-5' });

    // ---- Permanent fix for "valid JSON + trailing garbage" responses ----
    function extractLeadingJson(text) {
        let depth = 0, inString = false, escape = false, started = false;
        for (let i = 0; i < text.length; i++) {
            const ch = text[i];
            if (escape) { escape = false; continue; }
            if (ch === '\\' && inString) { escape = true; continue; }
            if (ch === '"') { inString = !inString; continue; }
            if (inString) continue;
            if (ch === '{' || ch === '[') { depth++; started = true; }
            else if (ch === '}' || ch === ']') {
                depth--;
                if (started && depth === 0) return text.slice(0, i + 1);
            }
        }
        return text;
    }

    function fetchJson(url, params) {
        return $.get(url, params || {}, null, 'text').then(function(raw) {
            const jsonPart = extractLeadingJson((raw || '').trim());
            try {
                return JSON.parse(jsonPart);
            } catch (e) {
                console.error('Could not parse JSON even after stripping trailing content:', raw);
                throw e;
            }
        });
    }

    // Data injected from PHP for edit mode
    const editMode      = <?= $editData ? 'true' : 'false' ?>;
    const editValues    = <?= safe_json_encode($editData ?: new stdClass()) ?>;
    const editQuestions = <?= safe_json_encode($editQuestions) ?>;

    // ---- Cascading dropdowns (Level -> Subject -> Chapter -> Topic) ----
    async function loadDropdown(target, type, id, keepValue){
        const $t = $(target);

        if(!id){
            $t.html('<option value="">Select</option>');
            if(!keepValue) $t.trigger('change.select2');
            return [];
        }

        $t.prop('disabled', true).html('<option value="">Loading...</option>').trigger('change.select2');

        try {
            const resp = await fetchJson(window.location.pathname, { fetch: type, id: id });
            const rows = Array.isArray(resp) ? resp : (resp && resp.rows) || [];

            $t.prop('disabled', false).html('<option value="">Select</option>');
            if(rows.length === 0){
                $t.append(new Option('No results found', ''));
                $t.find('option').last().prop('disabled', true);
            } else {
                rows.forEach(row => $t.append(new Option(row.name, row.id)));
            }
            if(!keepValue) $t.trigger('change.select2');
            return rows;
        } catch (err){
            console.error('Failed to load ' + type, err);
            $t.prop('disabled', false).html('<option value="">Failed to load — click to retry</option>');
            if(!keepValue) $t.trigger('change.select2');
            return [];
        }
    }

    $('#level').on('change', async function(){
        const levelId = this.value;
        await loadDropdown('#subject','subject', levelId);
        await loadDropdown('#chapter','chapter', null);
        await loadDropdown('#topic','topic', null);
        refreshQuestionPool();
    });
    $('#subject').on('change', async function(){
        const subjectId = this.value;
        await loadDropdown('#chapter','chapter', subjectId);
        await loadDropdown('#topic','topic', null);
        refreshQuestionPool();
    });
    $('#chapter').on('change', async function(){
        const chapterId = this.value;
        await loadDropdown('#topic','topic', chapterId);
        refreshQuestionPool();
    });
    $('#topic').on('change', function(){ refreshQuestionPool(); });

    // ---- Question pool (search + bulk add) ----
    let added = new Map(); // qid -> {text, exam_reference, remarks}
    let searchTimer = null;
    let currentOffset = 0;

    function refreshQuestionPool(append){
        const levelId   = $('#level').val();
        const subjectId = $('#subject').val();
        const chapterId = $('#chapter').val();
        const topicId    = $('#topic').val();
        const keyword    = $('#questionSearch').val().trim();

        if(!append){
            currentOffset = 0;
        }

        if(!levelId && !keyword){
            $('#questionPool').html(`
                <div class="empty-state">
                    <i class="bi bi-funnel"></i>
                    Pick a Level (and optionally Subject/Chapter/Topic) or type a keyword to see matching questions.
                </div>`);
            $('#poolCount').text('');
            updateCheckedCount();
            return;
        }

        if(!append){
            $('#questionPool').html(`
                <div class="empty-state">
                    <div class="spinner-border spinner-mini text-primary mb-2" role="status"></div><br>
                    Searching...
                </div>`);
        } else {
            $('#loadMoreBtn').prop('disabled', true).html('<span class="spinner-border spinner-mini" role="status"></span> Loading...');
        }

        const requestUrl = window.location.pathname + '?' + $.param({
            fetch: 'question',
            level_id: levelId,
            subject_id: subjectId,
            chapter_id: chapterId,
            topic_id: topicId,
            q: keyword,
            offset: currentOffset
        });

        $.get(requestUrl, null, null, 'text').done(function(raw){
            let resp;
            try {
                resp = JSON.parse(extractLeadingJson((raw || '').trim()));
            } catch (e) {
                console.error('Could not parse question search response:', raw);
                const rawEscaped = $('<div>').text((raw || '').trim() || '(completely empty response body)').html();
                $('#questionPool').html(`
                    <div class="empty-state" style="text-align:left; padding:1.25rem;">
                        <div style="text-align:center;">
                            <i class="bi bi-exclamation-triangle"></i>
                            Couldn't parse the server's response. Here's exactly what it sent back:
                        </div>
                        <pre style="white-space:pre-wrap; word-break:break-word; background:#fff; border:1px solid var(--border); border-radius:8px; padding:.75rem; margin-top:.75rem; font-size:.75rem; max-height:220px; overflow:auto;">${rawEscaped}</pre>
                        <div style="text-align:center; margin-top:.5rem;">
                            <a href="${requestUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-brand">
                                <i class="bi bi-box-arrow-up-right"></i> Open this request directly
                            </a>
                        </div>
                    </div>`);
                $('#poolCount').text('');
                return;
            }

            if (resp && resp.ok === false) {
                console.error('Question search error:', resp.error);
                $('#questionPool').html(`
                    <div class="empty-state">
                        <i class="bi bi-exclamation-triangle"></i>
                        Server error: ${$('<div>').text(resp.error || 'unknown error').html()}
                    </div>`);
                $('#poolCount').text('');
                updateCheckedCount();
                return;
            }
            const rows = (resp && resp.rows) || [];
            const diagnostic = resp && resp.diagnostic;
            const total = (resp && typeof resp.total === 'number') ? resp.total : rows.length;
            const hasMore = !!(resp && resp.has_more);
            renderQuestionPool(rows, diagnostic, { append, total, hasMore });
        }).fail(function(xhr){
            console.error('Question search failed', xhr.status, xhr.responseText);
            $('#questionPool').html(`
                <div class="empty-state">
                    <i class="bi bi-exclamation-triangle"></i>
                    Couldn't load questions (HTTP ${xhr.status}). <a href="${requestUrl}" target="_blank" rel="noopener">Open this request directly</a> to see why.
                </div>`);
            $('#poolCount').text('');
        });
    }

    function renderQuestionPool(data, diagnostic, meta){
        meta = meta || { append: false, total: data.length, hasMore: false };

        if(!meta.append && (!data || data.length === 0)){
            let msg = 'No questions found. Try a different filter or keyword.';
            if(diagnostic && diagnostic.total_unscoped > 0){
                const reasons = [];
                if(diagnostic.matches_br_org < diagnostic.total_unscoped){
                    reasons.push(`${diagnostic.total_unscoped - diagnostic.matches_br_org} belong to a different branch/organization`);
                }
                if(diagnostic.matches_status < diagnostic.total_unscoped){
                    reasons.push(`${diagnostic.total_unscoped - diagnostic.matches_status} aren't authorized (status isn't Y/N)`);
                }
                msg = `Found ${diagnostic.total_unscoped} question(s) tagged here, but none are visible to you` +
                      (reasons.length ? ' — ' + reasons.join(' and ') + '.' : ' due to your current scope.');
            }
            $('#questionPool').html(`
                <div class="empty-state">
                    <i class="bi bi-emoji-frown"></i>
                    ${msg}
                </div>`);
            $('#poolCount').text('');
            updateCheckedCount();
            return;
        }

        currentOffset += data.length;
        $('#poolCount').text(`Showing ${currentOffset} of ${meta.total} found`);

        const $pool = $('<div></div>');
        data.forEach(row => {
            const isAdded = added.has(String(row.id));
            const meta2 = [row.subject_name, row.chapter_name, row.topic_name].filter(Boolean).join(' • ');
            
            let additionalInfo = '';
            if (row.exam_reference) {
                additionalInfo += `<div class="q-reference"><i class="bi bi-tag"></i> Ref: ${$('<div>').text(row.exam_reference).html()}</div>`;
            }
            if (row.remarks) {
                additionalInfo += `<div class="q-remarks"><i class="bi bi-chat"></i> ${$('<div>').text(row.remarks).html()}</div>`;
            }
            
            const $row = $(`
                <div class="question-row ${isAdded ? 'is-added' : ''}" data-id="${row.id}" 
                     data-question="${encodeURIComponent(row.question)}"
                     data-reference="${encodeURIComponent(row.exam_reference || '')}"
                     data-remarks="${encodeURIComponent(row.remarks || '')}">
                    <input type="checkbox" class="pool-check" value="${row.id}" ${isAdded ? 'disabled checked' : ''}>
                    <div class="flex-grow-1">
                        <div class="q-text">${$('<div>').text(row.question).html()}</div>
                        ${meta2 ? `<div class="q-meta">${$('<div>').text(meta2).html()}</div>` : ''}
                        ${additionalInfo}
                    </div>
                    ${isAdded ? '<span class="badge bg-success-subtle text-success border border-success-subtle badge-added align-self-center">Added</span>' : ''}
                </div>
            `);
            $pool.append($row);
        });

        if(meta.append){
            $('#loadMoreWrap').remove();
            $('#questionPool').append($pool.html());
        } else {
            $('#questionPool').html($pool.html());
        }

        if(meta.hasMore){
            $('#questionPool').append(`
                <div id="loadMoreWrap" class="text-center p-2">
                    <button type="button" id="loadMoreBtn" class="btn btn-sm btn-outline-brand">
                        <i class="bi bi-arrow-down-circle"></i> Load ${Math.min(200, meta.total - currentOffset)} more (${meta.total - currentOffset} remaining)
                    </button>
                </div>`);
        }

        updateCheckedCount();
    }

    $('#questionPool').on('click', '#loadMoreBtn', function(){
        refreshQuestionPool(true);
    });

    $('#questionPool').on('click', '.question-row', function(e){
        if($(this).hasClass('is-added')) return;
        if(e.target.tagName.toLowerCase() !== 'input'){
            const $chk = $(this).find('.pool-check');
            $chk.prop('checked', !$chk.prop('checked'));
        }
        updateCheckedCount();
    });
    $('#questionPool').on('change', '.pool-check', updateCheckedCount);

    function updateCheckedCount(){
        const n = $('.pool-check:checked:not(:disabled)').length;
        $('#checkedCount').text(n);
        $('#addSelectedBtn').prop('disabled', n === 0);
    }

    $('#selectAllVisible').on('change', function(){
        $('.pool-check:not(:disabled)').prop('checked', this.checked);
        updateCheckedCount();
    });

    $('#questionSearch').on('input', function(){
        clearTimeout(searchTimer);
        searchTimer = setTimeout(refreshQuestionPool, 350);
    });

    $('#addSelectedBtn').on('click', function(){
        $('.pool-check:checked:not(:disabled)').each(function(){
            const qid = $(this).val();
            const $row = $(this).closest('.question-row');
            
            let qtext = $row.data('question');
            if (!qtext) {
                qtext = $row.find('.q-text').text();
            } else {
                qtext = decodeURIComponent(qtext);
            }
            
            const reference = decodeURIComponent($row.data('reference') || '');
            const remarks = decodeURIComponent($row.data('remarks') || '');
            
            addQuestion(qid, qtext, reference, remarks);
            $row.addClass('is-added');
            $row.find('.pool-check').prop('disabled', true);
            if(!$row.find('.badge-added').length){
                $row.append('<span class="badge bg-success-subtle text-success border border-success-subtle badge-added align-self-center">Added</span>');
            }
        });
        $('#selectAllVisible').prop('checked', false);
        updateCheckedCount();
    });

    function addQuestion(qid, qtext, reference, remarks){
        qid = String(qid);
        if(added.has(qid)) return;
        added.set(qid, { text: qtext, reference: reference, remarks: remarks });
        renderSelected();
    }

    function removeQuestion(qid){
        qid = String(qid);
        added.delete(qid);
        const $row = $(`.question-row[data-id="${qid}"]`);
        if($row.length){
            $row.removeClass('is-added');
            $row.find('.pool-check').prop('disabled', false).prop('checked', false);
            $row.find('.badge-added').remove();
        }
        renderSelected();
        updateCheckedCount();
    }

    function renderSelected(){
        $('#hiddenInputs').empty();
        $('#questionCount').text(added.size);

        if(added.size === 0){
            $('#selectedQuestions').html('<div class="empty-state" id="selectedEmpty"><i class="bi bi-inbox"></i>No questions added yet.</div>');
            $('#clearAllWrap').hide();
            return;
        }

        $('#clearAllWrap').show();
        const $list = $('<div></div>');
        let i = 1;
        added.forEach((data, qid) => {
            let infoHtml = '';
            if (data.reference) {
                infoHtml += `<span class="ref"><i class="bi bi-tag"></i> ${$('<div>').text(data.reference).html()}</span>`;
            }
            if (data.remarks) {
                infoHtml += `<span class="rmk"><i class="bi bi-chat"></i> ${$('<div>').text(data.remarks).html()}</span>`;
            }
            
            const $item = $(`
                <div class="selected-item" data-id="${qid}">
                    <div class="idx">${i}.</div>
                    <div class="txt">
                        ${$('<div>').text(data.text).html()}
                        ${infoHtml}
                    </div>
                    <button type="button" class="remove-selected" title="Remove"><i class="bi bi-x-circle-fill"></i></button>
                </div>
            `);
            $list.append($item);
            $('#hiddenInputs').append(`<input type="hidden" name="question_list[]" value="${qid}">`);
            i++;
        });
        $('#selectedQuestions').html($list.html());
    }

    $('#selectedQuestions').on('click', '.remove-selected', function(){
        const qid = $(this).closest('.selected-item').data('id');
        removeQuestion(qid);
    });

    $('#clearAllBtn').on('click', function(){
        if(!confirm('Remove all selected questions?')) return;
        added.forEach((_, qid) => {
            const $row = $(`.question-row[data-id="${qid}"]`);
            if($row.length){
                $row.removeClass('is-added');
                $row.find('.pool-check').prop('disabled', false).prop('checked', false);
                $row.find('.badge-added').remove();
            }
        });
        added.clear();
        renderSelected();
        updateCheckedCount();
    });

    $('#examForm').on('submit', function(e){
        if(added.size === 0){
            e.preventDefault();
            alert('Please add at least one question before saving.');
        }
    });

    // ---- Dynamic search on the Exam List tab ----
    $('#examListSearch').on('input', function(){
        const term = $(this).val().trim().toLowerCase();
        const terms = term.split(/\s+/).filter(Boolean);
        let visible = 0;

        $('.exam-row').each(function(){
            const haystack = $(this).data('search') || '';
            const matches = terms.every(t => haystack.includes(t));
            $(this).toggle(matches);
            if(matches) visible++;
        });

        $('#examListCount').text(visible + ' total');
        $('#examListEmpty').toggle(visible === 0);
        $('#examListTable').toggle(visible > 0 || term === '');
    });

    // ---- Delete exam (from the Exam List tab) ----
    $('.delete-exam-btn').on('click', function(){
        const examId = $(this).data('exam-id');
        const title  = $(this).data('exam-title');
        if(!confirm(`Delete exam "${title}"? This cannot be undone.`)) return;
        $('#deleteExamId').val(examId);
        $('#deleteForm').trigger('submit');
    });

    // ---- Publish / Unpublish toggle (from the Exam List tab) ----
    $('.toggle-status-btn').on('click', function(){
        const $btn    = $(this);
        const examId  = $btn.data('exam-id');
        const title   = $btn.data('exam-title');
        const current = $btn.data('current-status');
        const verb    = current === 'Y' ? 'unpublish' : 'publish';
        if(!confirm(`Are you sure you want to ${verb} "${title}"?`)) return;

        const $row    = $btn.closest('tr');
        const $badge  = $row.find('.badge[class*="status-badge-"]');
        const originalHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-mini" role="status"></span>');

        $.post(window.location.pathname, {
            action: 'toggle_status',
            exam_id: examId,
            ajax: 1
        }, null, 'text').done(function(raw){
            let resp;
            try {
                resp = JSON.parse(extractLeadingJson((raw || '').trim()));
            } catch (e) {
                console.error('Could not parse toggle-status response:', raw);
                alert('Something went wrong updating the status. Please try again.');
                $btn.prop('disabled', false).html(originalHtml);
                return;
            }

            if(!resp || !resp.ok){
                alert('Could not update status: ' + (resp && resp.error ? resp.error : 'unknown error'));
                $btn.prop('disabled', false).html(originalHtml);
                return;
            }

            const isPublished = resp.status === 'Y';

            $btn.prop('disabled', false)
                .data('current-status', resp.status)
                .attr('title', isPublished ? 'Click to unpublish' : 'Click to publish')
                .removeClass('is-published is-unpublished')
                .addClass(isPublished ? 'is-published' : 'is-unpublished')
                .html(`<i class="bi ${isPublished ? 'bi-eye-fill' : 'bi-eye-slash-fill'}"></i> ${isPublished ? 'Published' : 'Unpublished'}`);

            if($badge.length){
                $badge.removeClass('status-badge-Y status-badge-N')
                      .addClass(isPublished ? 'status-badge-Y' : 'status-badge-N')
                      .text(isPublished ? 'Authorized' : 'Pending');
            }

            const $tr = $row;
            const oldBlob = $tr.data('search') || '';
            const newBlob = oldBlob.replace(/\b(authorized|pending)\b/, isPublished ? 'authorized' : 'pending');
            $tr.attr('data-search', newBlob).data('search', newBlob);
        }).fail(function(xhr){
            console.error('Toggle status request failed', xhr.status, xhr.responseText);
            alert('Could not update status (HTTP ' + xhr.status + '). Please try again.');
            $btn.prop('disabled', false).html(originalHtml);
        });
    });

    // ---- Edit mode: hydrate the form with the exam being edited ----
    if(editMode && editValues && editValues.exam_id){
        // Update select2 display only (namespaced trigger skips our cascading 'change' handlers)
        $('#exam_type_id').val(editValues.exam_type_id).trigger('change.select2');
        $('#level').val(editValues.level_id).trigger('change.select2');

        // ✨ NEW: Sync remarks field from the edit values (extra safety — PHP already fills it)
        if (typeof editValues.remarks !== 'undefined') {
            $('#remarks').val(editValues.remarks || '');
        }

        loadDropdown('#subject', 'subject', editValues.level_id, true).then(function(){
            $('#subject').val(editValues.subject_id).trigger('change.select2');
            return loadDropdown('#chapter', 'chapter', editValues.subject_id, true);
        }).then(function(){
            $('#chapter').val(editValues.chapter_id).trigger('change.select2');
            return loadDropdown('#topic', 'topic', editValues.chapter_id, true);
        }).then(function(){
            $('#topic').val(editValues.topic_id).trigger('change.select2');
            refreshQuestionPool();
        });

        editQuestions.forEach(q => addQuestion(String(q.id), q.question, q.exam_reference || '', q.remarks || ''));
    }
});
</script>
</body>
</html>