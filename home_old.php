<?php
/**
 * home.php
 * Student dashboard — single file. Renders the full dashboard page,
 * including a pie chart of question share by subject (all subjects for the
 * student's level, computed server-side in one pass).
 */
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }
require 'db.php';

// --- SESSION DATA ---
$userId    = $_SESSION['user']['email']      ?? '';
$orgCode   = $_SESSION['user']['org_code']   ?? '';
$brCode    = $_SESSION['user']['br_code']    ?? '';
$userName  = $_SESSION['user']['user_name']  ?? 'Student';
$orgName   = $_SESSION['user']['org_name']   ?? '';
$userTypeId = $_SESSION['user']['user_type_id'] ?? ''; 

$levelId   = (int)($_SESSION['user']['level_id'] ?? 0);
$levelName = $_SESSION['user']['level_name'] ?? ('Level #' . $levelId);

$userId = trim($userId);

function jsonCleanUtf8($value)
{
    if (is_array($value)) {
        return array_map('jsonCleanUtf8', $value);
    }
    if (is_string($value)) {
        $value = strip_tags($value);
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return $value;
}

function safeJsonEncode($data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        return $json;
    }

    $firstError = json_last_error_msg();
    $cleaned = jsonCleanUtf8($data);
    $json = json_encode($cleaned, JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        error_log("safeJsonEncode: recovered from invalid UTF-8 (was: {$firstError})");
        return $json;
    }

    error_log("safeJsonEncode: could not recover — " . json_last_error_msg());
    return json_encode(['error' => 'JSON encode failed: ' . json_last_error_msg()]);
}

function getSubjectQuestionDistribution(PDO $pdo, int $levelId, string $orgCode, string $brCode): array
{
    $stmt = $pdo->prepare("
        SELECT s.id AS subject_id, s.name AS label, COUNT(q.id) AS cnt
        FROM subjects s
        LEFT JOIN chapters c  ON c.subject_id = s.id AND c.DELETE_DATE IS NULL
        LEFT JOIN topics t    ON t.chapter_id = c.id AND t.DELETE_DATE IS NULL
        LEFT JOIN questions q ON q.topic_id = t.id AND q.DELETE_DATE IS NULL
        WHERE s.level_id = ? AND s.org_code = ? AND s.br_code = ? AND s.DELETE_DATE IS NULL
        GROUP BY s.id, s.name
        ORDER BY cnt DESC, s.name ASC
    ");
    $stmt->execute([$levelId, $orgCode, $brCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['label'] = strip_tags($row['label']);
    }
    unset($row);

    return [
        'subjects' => $rows,
        'total'    => (int) array_sum(array_column($rows, 'cnt')),
    ];
}

function getImportantTopics(PDO $pdo, int $subjectId, int $limit = 10): array
{
    $stmt = $pdo->prepare("
        SELECT
            t.id AS topic_id,
            t.name AS label,
            COUNT(q.id) AS exam_freq,
            COUNT(DISTINCT q.referance_exam_id) AS ref_exam_count,
            COUNT(DISTINCT CASE
                    WHEN q.referance_exam_id IS NULL
                    THEN NULLIF(TRIM(q.exam_reference), '')
                  END) AS free_text_exam_count
        FROM topics t
        INNER JOIN chapters c
                ON c.id = t.chapter_id
               AND c.DELETE_DATE IS NULL
        INNER JOIN questions q
                ON q.topic_id = t.id
               AND q.DELETE_DATE IS NULL
        WHERE c.subject_id = ?
          AND t.DELETE_DATE IS NULL
          AND (
                q.referance_exam_id IS NOT NULL
             OR NULLIF(TRIM(q.exam_reference), '') IS NOT NULL
          )
        GROUP BY t.id, t.name
        HAVING exam_freq > 0
        ORDER BY exam_freq DESC, t.name ASC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$subjectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['label'] = strip_tags($r['label']);
        $r['exam_count'] = (int)$r['ref_exam_count'] + (int)$r['free_text_exam_count'];
        unset($r['ref_exam_count'], $r['free_text_exam_count']);
    }
    unset($r);

    return $rows;
}

// =========================================================================
// AJAX BRANCH
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'important_topics') {
    ini_set('display_errors', '0');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    header('Content-Type: application/json; charset=utf-8');

    $subjectId  = 0;
    $statusCode = 200;
    $payload    = null;

    try {
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            $statusCode = 400;
            $payload = ['error' => 'Invalid subject_id'];
        } else {
            $sessLevelId = (int)($_SESSION['user']['level_id'] ?? 0);
            $sessOrgCode = $_SESSION['user']['org_code'] ?? '';
            $sessBrCode  = $_SESSION['user']['br_code']  ?? '';

            $check = $pdo->prepare("
                SELECT id FROM subjects
                WHERE id = ? AND level_id = ? AND org_code = ? AND br_code = ? AND DELETE_DATE IS NULL
            ");
            $check->execute([$subjectId, $sessLevelId, $sessOrgCode, $sessBrCode]);

            if (!$check->fetchColumn()) {
                $statusCode = 403;
                $payload = ['error' => 'Subject not available for your level/org/branch'];
            } else {
                $topics = getImportantTopics($pdo, $subjectId);
                $payload = ['topics' => $topics];
            }
        }
    } catch (Throwable $e) {
        error_log("important_topics crashed for subject_id={$subjectId}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        $statusCode = 500;
        $payload = ['error' => 'Server error: ' . $e->getMessage()];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    ob_end_clean();
    http_response_code($statusCode);
    echo safeJsonEncode($payload);
    exit();
}

// =========================================================================
// NORMAL PAGE LOAD
// =========================================================================

$words    = array_filter(explode(' ', trim($userName)));
$initials = strtoupper(implode('', array_map(function($w) { return $w[0]; }, array_slice($words, 0, 2))));

// --- 1. TOTAL EXAMS ---
$stmtTotal = $pdo->prepare("
    SELECT COUNT(*) FROM exams_info
    WHERE level_id = ? AND org_code = ? AND br_code = ?
      AND (AUTHORIZED_STATUS = 'Y' OR AUTHORIZED_STATUS IS NULL)
      AND DELETE_DATE IS NULL
");
$stmtTotal->execute([$levelId, $orgCode, $brCode]);
$totalExams = (int)$stmtTotal->fetchColumn();

// --- 2. COMPLETED vs PENDING ---
$completedExams = 0;
$pendingExamList = [];
$pendingExams = 0;
$examResultsTableExists = true;

try {
    $stmtDone = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT er.exam_id) AS completedExams
        FROM exam_results er
        INNER JOIN exams_info ei 
            ON er.exam_id = ei.exam_id
           AND ei.DELETE_DATE IS NULL
        WHERE er.user_id = ?
          AND ei.level_id = ?
          AND ei.org_code = ?
          AND ei.br_code = ?
    ");

    $stmtDone->execute([$userId, $levelId, $orgCode, $brCode]);
    $completedExams = (int)$stmtDone->fetchColumn();

    $stmtPendingCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM exams_info e
        WHERE e.level_id = ?
          AND e.org_code = ?
          AND e.br_code = ?
          AND (e.AUTHORIZED_STATUS = 'Y' OR e.AUTHORIZED_STATUS IS NULL)
          AND e.DELETE_DATE IS NULL
          AND e.exam_id NOT IN (
              SELECT DISTINCT er.exam_id 
              FROM exam_results er
              WHERE er.user_id = ?
          )
    ");
    
    $stmtPendingCount->execute([$levelId, $orgCode, $brCode, $userId]);
    $pendingExams = (int)$stmtPendingCount->fetchColumn();

    $stmtPending = $pdo->prepare("
        SELECT e.exam_id, e.title, e.exam_date
        FROM exams_info e
        WHERE e.level_id = ?
          AND e.org_code = ?
          AND e.br_code = ?
          AND (e.AUTHORIZED_STATUS = 'Y' OR e.AUTHORIZED_STATUS IS NULL)
          AND e.DELETE_DATE IS NULL
          AND e.exam_id NOT IN (
              SELECT DISTINCT er.exam_id 
              FROM exam_results er
              WHERE er.user_id = ?
          )
        ORDER BY e.exam_date ASC
        LIMIT 8
    ");
    
    $stmtPending->execute([$levelId, $orgCode, $brCode, $userId]);
    $pendingExamList = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $examResultsTableExists = false;
    $completedExams = 0;
    $pendingExams = 0;
    error_log("Exam results query error: " . $e->getMessage());
}

$completionRate = $totalExams > 0 ? round(($completedExams / $totalExams) * 100, 1) : 0;

// --- 3. QUESTION DISTRIBUTION BY SUBJECT ---
$distribution  = getSubjectQuestionDistribution($pdo, $levelId, $orgCode, $brCode);
$distSubjects  = $distribution['subjects'];
$distTotal     = $distribution['total'];

$pieColors = ['#00693e','#0a8f55','#f42a41','#d9a441','#3b82a0','#8e54e9','#43e97b','#fa8231','#4776e6','#e64980'];

$distLabelsJ = json_encode(array_column($distSubjects, 'label'));
$distCountsJ = json_encode(array_map('intval', array_column($distSubjects, 'cnt')));

$topSubject    = ($distSubjects[0]['cnt'] ?? 0) > 0 ? $distSubjects[0]['label'] : null;
$topSubjectCnt = $distSubjects[0]['cnt'] ?? 0;

// --- 4. IMPORTANT TOPICS ---
$defaultSubjectId = (int)($distSubjects[0]['subject_id'] ?? 0);
$importantTopics  = $defaultSubjectId ? getImportantTopics($pdo, $defaultSubjectId) : [];

// --- Helper: initials + greeting ---
$hour = (int)date('H');
if ($hour < 12)      $greeting = 'শুভ সকাল';
elseif ($hour < 17)  $greeting = 'শুভ অপরাহ্ন';
elseif ($hour < 20)  $greeting = 'শুভ সন্ধ্যা';
else                 $greeting = 'শুভ রাত্রি';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#00693e">
    <title>Dashboard | Exam360</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            /* Exam360 brand palette */
            --g:      #00693e;
            --g2:     #0a8f55;
            --gd:     #024a2c;
            --gl:     #e4f2e9;
            --r:      #f42a41;
            --rl:     #fdecee;
            --gold:   #d9a441;
            --goldl:  #fdf3e0;
            --teal:   #3b82a0;
            --teall:  #e6f1f6;

            /* Surfaces */
            --bg:        #fbfcfb;
            --surface:   #f2f6f3;
            --card:      #ffffff;
            --line:      #e1e9e3;
            --line-soft: #eef3ef;

            /* Text */
            --ink:     #10231a;
            --ink-mid: #45564b;
            --mut:     #6b7d72;

            /* Shadow */
            --shadow-sm: 0 2px 8px rgba(2,74,44,.05);
            --shadow:    0 8px 28px -10px rgba(2,74,44,.14);
            --shadow-lg: 0 20px 50px -18px rgba(2,74,44,.22);

            --radius:    20px;
            --radius-sm: 14px;
            --radius-lg: 28px;

            --f: 'Hind Siliguri', 'Inter', system-ui, -apple-system, 'Segoe UI', 'Nirmala UI', sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --g: #3fc088; --g2: #2aa874; --gd: #9be3c2; --gl: #133023;
                --r: #ff6b7d; --rl: #33181d;
                --gold: #f5c35c; --goldl: #332b15;
                --teal: #63b3d0; --teall: #12262e;

                --bg:        #0b110e;
                --surface:   #111a15;
                --card:      #151f19;
                --line:      #233029;
                --line-soft: #1c2822;

                --ink:     #e8f1ec;
                --ink-mid: #b3c2b8;
                --mut:     #8ea398;

                --shadow-sm: 0 2px 8px rgba(0,0,0,.3);
                --shadow:    0 8px 28px -10px rgba(0,0,0,.5);
                --shadow-lg: 0 20px 50px -18px rgba(0,0,0,.7);
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--f);
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            line-height: 1.65;
            overflow-x: hidden;
        }

        /* ============ WELCOME BANNER ============ */
        .welcome-banner {
            background: linear-gradient(120deg, var(--gd) 0%, var(--g) 45%, var(--g2) 100%);
            border-radius: var(--radius-lg);
            padding: 32px 34px;
            color: #fff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        @media (prefers-color-scheme: dark) {
            .welcome-banner { background: linear-gradient(120deg, #0d3a26 0%, #1b6f4b 100%); }
        }
        .welcome-banner::before {
            content: ''; position: absolute; top: -80px; right: -60px;
            width: 260px; height: 260px; background: rgba(255,255,255,.09); border-radius: 50%;
        }
        .welcome-banner::after {
            content: ''; position: absolute; bottom: -60px; right: 140px;
            width: 160px; height: 160px; background: rgba(244,42,65,.22); border-radius: 50%;
        }
        .welcome-banner .wb-deco {
            position: absolute; top: 50%; right: 40px; transform: translateY(-50%);
            font-size: 130px; opacity: .08; pointer-events: none; user-select: none;
            line-height: 1; font-weight: 800;
        }
        .welcome-banner h4 { font-size: 1.55rem; font-weight: 700; margin: 0 0 4px; letter-spacing: -.01em; }
        .welcome-banner p { font-size: .95rem; opacity: .88; margin: 0; }
        .wb-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; position: relative; z-index: 1; }
        .welcome-badge, .level-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 40px; padding: 5px 14px; font-size: 12px; font-weight: 600;
            letter-spacing: .3px; backdrop-filter: blur(8px);
        }
        .welcome-banner h4, .welcome-banner p { position: relative; z-index: 1; }

        /* ============ METRIC CARDS ============ */
        .metric-card {
            border-radius: var(--radius);
            padding: 24px 22px;
            color: #fff;
            position: relative;
            overflow: hidden;
            transition: transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s;
            cursor: default;
            border: none;
            height: 100%;
            box-shadow: var(--shadow);
        }
        .metric-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); }
        .metric-card::before {
            content: ''; position: absolute; top: -40px; right: -40px;
            width: 130px; height: 130px; background: rgba(255,255,255,.13); border-radius: 50%;
        }
        .metric-card::after {
            content: ''; position: absolute; bottom: -60px; left: 40%;
            width: 170px; height: 170px; background: rgba(255,255,255,.06); border-radius: 50%;
        }

        .mc-1 { background: linear-gradient(135deg, var(--g) 0%, var(--g2) 100%); }
        .mc-2 { background: linear-gradient(135deg, #0a8f55 0%, #3fc088 100%); }
        .mc-3 { background: linear-gradient(135deg, var(--r) 0%, #ff7a8a 100%); }
        .mc-4 { background: linear-gradient(135deg, var(--gold) 0%, #f5c35c 100%); color: #3a2600 !important; }
        .mc-4 .mc-label, .mc-4 .mc-sub, .mc-4 .mc-tag { color: #3a2600 !important; opacity: .78; }

        .mc-icon-wrap {
            width: 52px; height: 52px; border-radius: 16px;
            background: rgba(255,255,255,.22);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; margin-bottom: 16px; backdrop-filter: blur(6px);
            position: relative; z-index: 1;
        }
        .mc-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1.4px;
            text-transform: uppercase; opacity: .85; margin-bottom: 4px;
            position: relative; z-index: 1;
        }
        .mc-value {
            font-family: var(--f); font-size: 2.35rem; font-weight: 700;
            letter-spacing: -.02em; line-height: 1; margin-bottom: 8px;
            position: relative; z-index: 1;
        }
        .mc-sub { font-size: 12px; opacity: .82; font-weight: 500; position: relative; z-index: 1; }
        .mc-tag {
            position: absolute; top: 16px; right: 16px;
            background: rgba(255,255,255,.22); color: #fff;
            border-radius: 30px; padding: 3px 11px;
            font-size: 11px; font-weight: 700; letter-spacing: .3px;
            backdrop-filter: blur(6px);
            z-index: 1;
        }

        /* ============ SECTION HEADING ============ */
        .section-heading {
            display: flex; align-items: center; gap: 10px;
            font-size: 1.15rem; font-weight: 700; color: var(--ink);
            margin-bottom: 16px;
        }
        .section-heading .sh-dot {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
            box-shadow: 0 0 0 4px color-mix(in srgb, currentColor 12%, transparent);
        }

        /* ============ CHART / CONTENT CARDS ============ */
        .chart-card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--line-soft);
            padding: 26px;
            transition: box-shadow .3s, border-color .3s;
            height: 100%;
            position: relative;
        }
        .chart-card:hover { box-shadow: var(--shadow-lg); border-color: var(--line); }

        .chart-card-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 22px; flex-wrap: wrap; gap: 10px;
        }
        .chart-card-title {
            font-size: 1rem; font-weight: 700; color: var(--ink);
            display: flex; align-items: center; gap: 10px;
        }
        .chart-pill {
            font-size: 11px; font-weight: 700; letter-spacing: .3px;
            padding: 5px 13px; border-radius: 30px;
            background: var(--gl); color: var(--g);
            border: 1.5px solid color-mix(in srgb, var(--g) 22%, transparent);
        }
        .chart-icon-badge {
            width: 38px; height: 38px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; background: var(--gl); flex-shrink: 0;
        }

        /* ============ SUBJECT SELECT ============ */
        .subject-select {
            border-radius: 12px;
            border: 1.5px solid var(--line);
            padding: 9px 14px;
            font-weight: 600; font-size: 13px;
            color: var(--ink); background: var(--surface);
            min-width: 220px;
            font-family: var(--f);
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s;
        }
        .subject-select:hover { border-color: var(--g); }
        .subject-select:focus {
            outline: none; border-color: var(--g);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--g) 18%, transparent);
        }

        /* ============ LIST ITEMS ============ */
        .exam-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 4px;
            border-bottom: 1px solid var(--line-soft);
            transition: background .2s, padding-left .2s;
            border-radius: 10px;
        }
        .exam-item:hover {
            background: var(--surface);
            padding-left: 10px;
        }
        .exam-item:last-child { border-bottom: none; }
        .exam-dot {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        }
        .exam-info { flex: 1; min-width: 0; }
        .exam-subj { font-weight: 600; color: var(--ink); font-size: .92rem; }
        .exam-meta { font-size: .78rem; color: var(--mut); margin-top: 2px; }
        .exam-right { text-align: right; flex-shrink: 0; }
        .exam-date { font-size: .7rem; color: var(--mut); }

        .btn-start {
            background: linear-gradient(135deg, var(--g) 0%, var(--g2) 100%);
            color: #fff; border: none;
            font-size: 12px; font-weight: 700;
            padding: 7px 16px; border-radius: 30px;
            text-decoration: none;
            display: inline-block;
            transition: transform .15s, box-shadow .2s;
            letter-spacing: .2px;
        }
        .btn-start:hover {
            color: #fff;
            transform: scale(1.05);
            box-shadow: 0 8px 20px -8px var(--g);
        }

        /* ============ CALLOUTS ============ */
        .callout-improve {
            display: flex; align-items: center; gap: 14px;
            background: var(--gl);
            border-left: 4px solid var(--g);
            border-radius: 14px;
            padding: 14px 18px; margin-bottom: 24px;
            font-size: 14px; color: var(--ink);
            border: 1px solid color-mix(in srgb, var(--g) 15%, transparent);
            border-left-width: 4px;
        }
        .callout-improve strong { color: var(--gd); }
        .callout-warn {
            background: var(--goldl);
            border-left-color: var(--gold);
            border-color: color-mix(in srgb, var(--gold) 30%, transparent);
        }
        .callout-warn strong { color: #8a5e00; }
        @media (prefers-color-scheme: dark) { .callout-warn strong { color: var(--gold); } }

        /* ============ ANIMATIONS ============ */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-card { animation: fadeUp .5s ease both; }
        .d1 { animation-delay: .05s; } .d2 { animation-delay: .12s; }
        .d3 { animation-delay: .19s; } .d4 { animation-delay: .26s; }
        .d5 { animation-delay: .33s; } .d6 { animation-delay: .40s; }

        /* ============ CHART LOADING ============ */
        .chart-loading {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            background: color-mix(in srgb, var(--card) 80%, transparent);
            border-radius: var(--radius);
            font-size: 13px; font-weight: 700;
            color: var(--g);
            opacity: 0; pointer-events: none;
            transition: opacity .2s;
            backdrop-filter: blur(4px);
        }
        .chart-loading.active { opacity: 1; }
        .chart-loading::before {
            content: ''; width: 18px; height: 18px;
            border: 2.5px solid var(--line); border-top-color: var(--g);
            border-radius: 50%; animation: spin .8s linear infinite;
            margin-right: 10px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ============ SCROLLBAR ============ */
        .scroll-area::-webkit-scrollbar { width: 8px; }
        .scroll-area::-webkit-scrollbar-track { background: transparent; }
        .scroll-area::-webkit-scrollbar-thumb {
            background: var(--line); border-radius: 20px;
        }
        .scroll-area::-webkit-scrollbar-thumb:hover { background: var(--mut); }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            main { padding: 16px !important; }
            .mc-value { font-size: 1.85rem; }
            .welcome-banner { padding: 22px; }
            .welcome-banner h4 { font-size: 1.25rem; }
            .metric-card { padding: 18px 16px; }
            .chart-card { padding: 20px; }
            .subject-select { min-width: 100%; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main style="padding:28px 32px;">

    <!-- Welcome Banner -->
    <div class="welcome-banner animate-card d1">
        <div class="wb-deco">চেস্টা</div>
        <div class="wb-badges">
            <span class="welcome-badge"><i class="bi bi-stars"></i> Student Dashboard</span>
            <span class="level-badge"><i class="bi bi-layers-half"></i> <?= htmlspecialchars($levelName) ?></span>
        </div>
        <h4><?= $greeting ?>, <?= htmlspecialchars($userName) ?>! 👋</h4>
        <p>আপনার <?= htmlspecialchars($levelName) ?> লেভেলের আজকের সারসংক্ষেপ নিচে দেখুন।</p>
    </div>

    <?php if (!$examResultsTableExists): ?>
    <div class="callout-improve callout-warn animate-card d2">
        <i class="bi bi-exclamation-triangle-fill text-warning fs-4"></i>
        <div>
            <strong>Completed/Pending tracking isn't set up yet.</strong>
            <div style="font-size:12px;margin-top:2px;opacity:.75">
                Create an <code>exam_results</code> table to enable this. Question analysis below still works.
            </div>
        </div>
    </div>
    <?php elseif ($pendingExams > 0): ?>
    <div class="callout-improve animate-card d2">
        <i class="bi bi-flag-fill fs-4" style="color:var(--r)"></i>
        <div>
            <strong>আপনার আরও <?= $pendingExams ?> টি পরীক্ষা বাকি আছে।</strong>
            <div style="font-size:12px;margin-top:2px;opacity:.75">
                <?= htmlspecialchars($levelName) ?> লেভেলের <?= $completionRate ?>% পরীক্ষা সম্পন্ন হয়েছে।
            </div>
        </div>
    </div>
    <?php elseif ($totalExams > 0): ?>
    <div class="callout-improve animate-card d2">
        <i class="bi bi-check2-circle fs-4" style="color:var(--g)"></i>
        <div>
            <strong>সব শেষ! 🎉</strong>
            <div style="font-size:12px;margin-top:2px;opacity:.75">
                <?= htmlspecialchars($levelName) ?> লেভেলের সব পরীক্ষা আপনি সম্পন্ন করেছেন।
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3 animate-card d2">
            <div class="metric-card mc-1">
                <span class="mc-tag">লেভেল</span>
                <div class="mc-icon-wrap"><i class="bi bi-file-earmark-check"></i></div>
                <div class="mc-label">মোট এক্সাম</div>
                <div class="mc-value count" data-count="<?= $totalExams ?>">0</div>
                <div class="mc-sub"><?= htmlspecialchars($levelName) ?> এর জন্য</div>
            </div>
        </div>

        <div class="col-6 col-lg-3 animate-card d3">
            <div class="metric-card mc-2">
                <span class="mc-tag">সম্পন্ন</span>
                <div class="mc-icon-wrap"><i class="bi bi-check-circle"></i></div>
                <div class="mc-label">সম্পন্ন এক্সাম</div>
                <div class="mc-value count" data-count="<?= $completedExams ?>">0</div>
                <div class="mc-sub">মোট <?= $totalExams ?> এর মধ্যে</div>
            </div>
        </div>

        <div class="col-6 col-lg-3 animate-card d4">
            <div class="metric-card mc-3">
                <span class="mc-tag">বাকি</span>
                <div class="mc-icon-wrap"><i class="bi bi-hourglass-split"></i></div>
                <div class="mc-label">বাকি এক্সাম</div>
                <div class="mc-value count" data-count="<?= $pendingExams ?>">0</div>
                <div class="mc-sub">এখনো দেওয়া হয়নি</div>
            </div>
        </div>

        <div class="col-6 col-lg-3 animate-card d5">
            <div class="metric-card mc-4">
                <span class="mc-tag">প্রোগ্রেস</span>
                <div class="mc-icon-wrap"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="mc-label">সম্পন্ন হার</div>
                <div class="mc-value"><span class="count" data-count="<?= $completionRate ?>">0</span><span style="font-size:1.4rem">%</span></div>
                <div class="mc-sub">এখন পর্যন্ত</div>
            </div>
        </div>
    </div>

    <!-- Question Analysis Section -->
    <div class="row g-4 mb-3">
        <div class="col-12">
            <div class="section-heading animate-card d5">
                <span class="sh-dot" style="background:var(--g);color:var(--g)"></span>
                কোয়েশ্চন অ্যানালাইসিস (বিষয়ভিত্তিক)
            </div>
        </div>
    </div>

    <?php if (empty($distSubjects)): ?>
    <div class="chart-card mb-4 animate-card d5">
        <p class="text-center py-4 mb-0" style="color:var(--mut)">No subjects found for <?= htmlspecialchars($levelName) ?> yet.</p>
    </div>
    <?php elseif ($distTotal === 0): ?>
    <div class="chart-card mb-4 animate-card d5">
        <p class="text-center py-4 mb-0" style="color:var(--mut)">এখনো কোনো বিষয়ে প্রশ্ন যোগ করা হয়নি।</p>
    </div>
    <?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-7 animate-card d5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <div class="chart-icon-badge">🥧</div>
                        বিষয়ভিত্তিক প্রশ্নের হার
                    </div>
                    <span class="chart-pill"><?= $distTotal ?> টি মোট প্রশ্ন</span>
                </div>
                <div style="height:360px;">
                    <canvas id="subjectPieChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-5 animate-card d5">
            <div class="chart-card" style="height:100%;">
                <div class="chart-card-title mb-3">
                    <div class="chart-icon-badge">📋</div>
                    বিস্তারিত তালিকা
                </div>
                <div class="scroll-area" style="max-height:360px;overflow-y:auto;padding-right:6px;">
                    <?php foreach ($distSubjects as $i => $subj):
                        $pct = $distTotal > 0 ? round(($subj['cnt'] / $distTotal) * 100, 1) : 0;
                        $color = $pieColors[$i % count($pieColors)];
                    ?>
                    <div class="exam-item">
                        <div class="exam-dot" style="background:<?= $color ?>"></div>
                        <div class="exam-info">
                            <div class="exam-subj"><?= htmlspecialchars($subj['label']) ?></div>
                            <div class="exam-meta">
                                <?= (int)$subj['cnt'] ?> টি প্রশ্ন
                                <?= $subj['cnt'] == 0 ? ' — এখনো যোগ করা হয়নি' : '' ?>
                            </div>
                        </div>
                        <div class="exam-right">
                            <div class="exam-subj" style="color:<?= $color ?>"><?= $pct ?>%</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($topSubject): ?>
    <div class="callout-improve animate-card d6">
        <i class="bi bi-lightbulb-fill fs-4" style="color:var(--gold)"></i>
        <div>
            <strong>সবচেয়ে বেশি প্রশ্ন আছে: <?= htmlspecialchars($topSubject) ?></strong>
            <div style="font-size:12px;margin-top:2px;opacity:.75">
                মোট <?= $topSubjectCnt ?> টি প্রশ্ন (<?= round($topSubjectCnt / $distTotal * 100, 1) ?>%) — এই বিষয়ে ভালোভাবে প্রস্তুতি নিন।
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Important Topics -->
    <div class="row g-4 mb-3">
        <div class="col-12">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 animate-card d5">
                <div class="section-heading mb-0">
                    <span class="sh-dot" style="background:var(--r);color:var(--r)"></span>
                    গুরুত্বপূর্ণ টপিক্স (বিগত পরীক্ষা অনুযায়ী)
                </div>
                <?php if (!empty($distSubjects)): ?>
                <select id="importantTopicsSubject" class="subject-select">
                    <?php foreach ($distSubjects as $s): ?>
                        <option value="<?= (int)$s['subject_id'] ?>" <?= (int)$s['subject_id'] === $defaultSubjectId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (empty($distSubjects)): ?>
    <div class="chart-card mb-4 animate-card d5">
        <p class="text-center py-4 mb-0" style="color:var(--mut)">No subjects found for <?= htmlspecialchars($levelName) ?> yet.</p>
    </div>
    <?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-12 animate-card d5">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <div class="chart-icon-badge">🎯</div>
                        যেখান থেকে বিগত পরীক্ষায় বেশি প্রশ্ন এসেছে
                    </div>
                    <span class="chart-pill" id="itSubjectPill"><?= htmlspecialchars($distSubjects[0]['label'] ?? '') ?></span>
                </div>
                <div id="importantTopicsList" class="scroll-area" style="max-height:520px;overflow-y:auto;">
                    <?php if (empty($importantTopics)): ?>
                        <p class="text-center py-4 mb-0" id="itEmptyMsg" style="color:var(--mut)">এই বিষয়ে বিগত পরীক্ষায় কোনো প্রশ্ন ব্যবহৃত হয়নি।</p>
                    <?php else: ?>
                        <?php foreach ($importantTopics as $i => $tp): ?>
                        <div class="exam-item">
                            <div class="exam-dot" style="background:<?= $pieColors[$i % count($pieColors)] ?>; width:28px; height:28px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px; font-weight:800; border-radius:9px;">
                                <?= $i + 1 ?>
                            </div>
                            <div class="exam-info">
                                <div class="exam-subj"><?= htmlspecialchars($tp['label']) ?></div>
                                <div class="exam-meta"><?= (int)$tp['exam_count'] ?> টি ভিন্ন পরীক্ষায় এসেছে</div>
                            </div>
                            <div class="exam-right">
                                <div class="exam-subj" style="color:var(--g)"><?= (int)$tp['exam_freq'] ?> টি প্রশ্ন</div> 
                            </div> 
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="chart-loading" id="itLoading">লোড হচ্ছে...</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Exams -->
    <?php if ($examResultsTableExists): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="chart-card animate-card d6">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <div class="chart-icon-badge" style="background:var(--goldl)">⏳</div>
                        বাকি এক্সামসমূহ
                    </div>
                    <span class="chart-pill" style="background:var(--goldl);color:#8a5e00;border-color:color-mix(in srgb,var(--gold) 30%,transparent)"><?= count($pendingExamList) ?> টি</span>
                </div>
                <?php if (empty($pendingExamList)): ?>
                    <p class="text-center py-4 mb-0" style="color:var(--mut)">কোনো বাকি এক্সাম নেই। সব সম্পন্ন! 🎉</p>
                <?php else: ?>
                    <?php foreach ($pendingExamList as $row): ?>
                    <div class="exam-item">
                        <div class="exam-dot" style="background:var(--r)"></div>
                        <div class="exam-info">
                            <div class="exam-subj"><?= htmlspecialchars($row['title'] ?? '—') ?></div>
                            <div class="exam-meta"><?= $row['exam_date'] ? date('d M, Y', strtotime($row['exam_date'])) : 'তারিখ নির্ধারিত নেই' ?></div>
                        </div>
                        <div class="exam-right">
                            <a href="exam_list.php?exam_id=<?= urlencode($row['exam_id']) ?>" class="btn-start">শুরু করুন</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {

    // Counter animation
    $('.count').each(function() {
        $(this).prop('Counter', 0).animate({ Counter: $(this).data('count') }, {
            duration: 1400, easing: 'swing',
            step: function(now) { $(this).text(Math.ceil(now).toLocaleString()); }
        });
    });

    const banglaFont = "'Hind Siliguri', 'Inter', sans-serif";
    Chart.defaults.font.family = banglaFont;
    Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--mut').trim() || '#6b7d72';

    <?php if ($distTotal > 0): ?>
    function renderSubjectPie() {
        const labels = <?= $distLabelsJ ?>;
        const counts = <?= $distCountsJ ?>;
        const total  = <?= $distTotal ?>;
        const colors = ['#00693e','#0a8f55','#f42a41','#d9a441','#3b82a0','#8e54e9','#43e97b','#fa8231','#4776e6','#e64980'];
        const bg = labels.map((_, i) => colors[i % colors.length]);

        const canvasEl = document.getElementById('subjectPieChart');
        if (!canvasEl) return;
        const ctx = canvasEl.getContext('2d');

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: counts,
                    backgroundColor: bg,
                    borderWidth: 3,
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card').trim() || '#fff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(2,74,44,.95)',
                        titleFont: { family: banglaFont, size: 13, weight: '600' },
                        bodyFont:  { family: banglaFont, size: 12 },
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        callbacks: {
                            label: function(item) {
                                const val = item.raw;
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ' ' + item.label + ': ' + val + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(renderSubjectPie);
    } else {
        renderSubjectPie();
    }
    <?php endif; ?>

    // --- Important Topics ---
    function renderImportantTopics(topics, errorMsg) {
        const list = $('#importantTopicsList');
        list.empty();

        if (errorMsg) {
            list.append('<p class="text-center text-danger py-4 mb-0" id="itEmptyMsg">' + $('<div>').text(errorMsg).html() + '</p>');
            return;
        }

        if (!topics || !topics.length) {
            list.append('<p class="text-center py-4 mb-0" id="itEmptyMsg" style="color:var(--mut)">এই বিষয়ে বিগত পরীক্ষায় কোনো প্রশ্ন ব্যবহৃত হয়নি।</p>');
            return;
        }

        const colors = ['#00693e','#0a8f55','#f42a41','#d9a441','#3b82a0','#8e54e9','#43e97b','#fa8231','#4776e6','#e64980'];

        topics.forEach((tp, i) => {
            const color = colors[i % colors.length];
            const row = $(
                '<div class="exam-item">' +
                    '<div class="exam-dot" style="background:' + color + ';width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:800;border-radius:9px;">' + (i + 1) + '</div>' +
                    '<div class="exam-info">' +
                        '<div class="exam-subj"></div>' +
                        '<div class="exam-meta"></div>' +
                    '</div>' +
                    '<div class="exam-right">' +
                        '<div class="exam-subj" style="color:var(--g)"></div>' +
                    '</div>' +
                '</div>'
            );
            row.find('.exam-subj').first().text(tp.label);
            row.find('.exam-meta').text(tp.exam_count + ' টি ভিন্ন পরীক্ষায় এসেছে');
            row.find('.exam-subj').last().text(tp.exam_freq + ' টি প্রশ্ন');
            list.append(row);
        });
    }

    $('#importantTopicsSubject').on('change', function () {
        const subjectId = $(this).val();
        const subjectName = $(this).find('option:selected').text();
        const loading = $('#itLoading');
        const pill = $('#itSubjectPill');
        
        loading.addClass('active');
        pill.text(subjectName);

        fetch('home.php?ajax=important_topics&subject_id=' + encodeURIComponent(subjectId))
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => {
                        throw new Error('HTTP ' + res.status + ': ' + text.substring(0, 200));
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    console.error('important_topics error:', data.error);
                    renderImportantTopics([], data.error);
                    return;
                }
                renderImportantTopics(data.topics || []);
            })
            .catch(err => {
                console.error('Important topics fetch failed:', err.message);
                renderImportantTopics([], 'লোড করতে ব্যর্থ: ' + err.message);
            })
            .finally(() => loading.removeClass('active'));
    });

});
</script>

<?php include 'footer.php'; ?>
</body>
</html>