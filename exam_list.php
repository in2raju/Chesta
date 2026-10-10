<?php
session_start();
require 'db.php';

// -----------------------------
// Check login
// -----------------------------
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// -----------------------------
// Session user info
// -----------------------------
$userSession = $_SESSION['user'];
$userType    = $userSession['user_type_id'] ?? 0; 
$userOrg     = $userSession['org_code'] ?? '';
$userBr      = $userSession['br_code'] ?? '';
$userLevelId = $userSession['level_id'] ?? 0;
$userId = $userSession['user_id'] ?? $userSession['USER_ID'] ?? '';

// Fetch level name
$userLevelName = '';
if ($userLevelId > 0) {
    try {
        $levelStmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
        $levelStmt->execute([$userLevelId]);
        $levelResult = $levelStmt->fetch(PDO::FETCH_ASSOC);
        $userLevelName = $levelResult['name'] ?? '';
    } catch (PDOException $e) {
        $userLevelName = '';
    }
}

// -----------------------------
// Role-based level access
// -----------------------------
$isAdmin = ($userType == 1 || $userType == 2);

// =============================================================
// HARDCODED EXAM TYPE — Always 3
// Users cannot change this; the dropdown is removed.
// =============================================================
$FIXED_EXAM_TYPE_ID = 3;

// -----------------------------
// Get selected filters from URL
// -----------------------------
$selectedSubject  = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$selectedLevel    = isset($_GET['level_id']) ? (int)$_GET['level_id'] : 0;
$selectedExamType = $FIXED_EXAM_TYPE_ID;
$selectedChapter  = isset($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : 0;
$selectedTopic    = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$searchKeyword    = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!$isAdmin && $userLevelId > 0) {
    $selectedLevel = $userLevelId;
}

// -----------------------------
// Get All Levels (for admin)
// -----------------------------
$allLevels = [];
if ($isAdmin) {
    try {
        $levelStmt = $pdo->query("SELECT id, name FROM levels WHERE AUTHORIZED_STATUS = 'Y' ORDER BY name");
        $allLevels = $levelStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $allLevels = [];
    }
}

// -----------------------------
// Fetch the fixed exam type's details
// -----------------------------
$fixedTypeName = '';
try {
    $typeStmt = $pdo->prepare("SELECT type_name FROM exam_types WHERE id = ?");
    $typeStmt->execute([$FIXED_EXAM_TYPE_ID]);
    $fixedTypeName = (string)$typeStmt->fetchColumn();
} catch (PDOException $e) {
    $fixedTypeName = '';
}
$selectedTypeKey = strtoupper(trim($fixedTypeName));

$wantsSubjectDropdown = in_array($selectedTypeKey, ['SUBJECT', 'CHAPTER', 'TOPIC'], true);
$wantsChapterDropdown = in_array($selectedTypeKey, ['CHAPTER', 'TOPIC'], true);
$wantsTopicDropdown   = ($selectedTypeKey === 'TOPIC');

// -----------------------------
// Get Subjects
// -----------------------------
$subjects = [];
if ($wantsSubjectDropdown) {
    try {
        $effectiveLevel = $isAdmin ? $selectedLevel : $userLevelId;
        if ($effectiveLevel > 0) {
            $subjectStmt = $pdo->prepare("
                SELECT DISTINCT s.id, s.name 
                FROM subjects s
                INNER JOIN exams_info e ON e.subject_id = s.id
                WHERE e.level_id = ? AND e.AUTHORIZED_STATUS = 'Y' AND e.exam_type_id = ?
                ORDER BY s.name
            ");
            $subjectStmt->execute([$effectiveLevel, $FIXED_EXAM_TYPE_ID]);
        } else {
            $subjectStmt = $pdo->prepare("
                SELECT DISTINCT s.id, s.name 
                FROM subjects s
                INNER JOIN exams_info e ON e.subject_id = s.id
                WHERE e.AUTHORIZED_STATUS = 'Y' AND e.exam_type_id = ?
                ORDER BY s.name
            ");
            $subjectStmt->execute([$FIXED_EXAM_TYPE_ID]);
        }
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $subjects = [];
    }
}

// -----------------------------
// Get Chapters
// -----------------------------
$chapters = [];
if ($wantsChapterDropdown && $selectedSubject > 0) {
    try {
        $effectiveLevel = $isAdmin ? $selectedLevel : $userLevelId;
        $chapterSql = "
            SELECT DISTINCT c.id, c.name 
            FROM chapters c
            INNER JOIN exams_info e ON e.chapter_id = c.id
            WHERE e.AUTHORIZED_STATUS = 'Y' 
              AND e.exam_type_id = :type_id
              AND e.subject_id = :subject_id";
        $chapterParams = [
            'type_id'    => $FIXED_EXAM_TYPE_ID,
            'subject_id' => $selectedSubject
        ];
        if ($effectiveLevel > 0) {
            $chapterSql .= " AND e.level_id = :level_id";
            $chapterParams['level_id'] = $effectiveLevel;
        }
        $chapterSql .= " ORDER BY c.name";
        $chapterStmt = $pdo->prepare($chapterSql);
        $chapterStmt->execute($chapterParams);
        $chapters = $chapterStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $chapters = [];
    }
}

// -----------------------------
// Get Topics
// -----------------------------
$topics = [];
if ($wantsTopicDropdown && $selectedChapter > 0) {
    try {
        $topicStmt = $pdo->prepare("
            SELECT DISTINCT tp.id, tp.name 
            FROM topics tp
            INNER JOIN exams_info e ON e.topic_id = tp.id
            WHERE e.AUTHORIZED_STATUS = 'Y' 
              AND e.exam_type_id = :type_id
              AND e.chapter_id = :chapter_id
            ORDER BY tp.name
        ");
        $topicStmt->execute([
            'type_id'    => $FIXED_EXAM_TYPE_ID,
            'chapter_id' => $selectedChapter
        ]);
        $topics = $topicStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $topics = [];
    }
}

// -----------------------------
// Fetch Exams (ALWAYS exam_type_id = 3)
// -----------------------------
$sql = "SELECT 
            e.exam_id, 
            e.title,
            e.subject_id,
            e.level_id,
            e.exam_type_id,
            s.name as subject_name,
            l.name as level_name,
            t.type_name as exam_type_name
        FROM exams_info e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN levels l ON e.level_id = l.id
        LEFT JOIN exam_types t ON e.exam_type_id = t.id
        WHERE e.AUTHORIZED_STATUS = 'Y'
          AND e.exam_type_id = :fixed_exam_type_id";
$params = ['fixed_exam_type_id' => $FIXED_EXAM_TYPE_ID];
$whereConditions = [];

if ($isAdmin) {
    if ($selectedLevel > 0) {
        $whereConditions[] = "e.level_id = :level_id";
        $params['level_id'] = $selectedLevel;
    }
} else {
    if ($userLevelId > 0) {
        $whereConditions[] = "e.level_id = :level_id";
        $params['level_id'] = $userLevelId;
    }
}

if ($selectedSubject > 0) {
    $whereConditions[] = "e.subject_id = :subject_id";
    $params['subject_id'] = $selectedSubject;
}

if ($selectedChapter > 0) {
    $whereConditions[] = "e.chapter_id = :chapter_id";
    $params['chapter_id'] = $selectedChapter;
}

if ($selectedTopic > 0) {
    $whereConditions[] = "e.topic_id = :topic_id";
    $params['topic_id'] = $selectedTopic;
}

if (!empty($searchKeyword)) {
    $whereConditions[] = "(
        e.exam_id LIKE :search1 
        OR e.title LIKE :search2 
        OR s.name LIKE :search3 
        OR l.name LIKE :search4
        OR t.type_name LIKE :search5
    )";
    $searchParam = "%{$searchKeyword}%";
    $params['search1'] = $searchParam;
    $params['search2'] = $searchParam;
    $params['search3'] = $searchParam;
    $params['search4'] = $searchParam;
    $params['search5'] = $searchParam;
}

if (!$isAdmin) {
    if ($userType == 2) { 
        $whereConditions[] = "e.org_code = :org";
        $params['org'] = $userOrg;
    } elseif ($userType > 2) { 
        $whereConditions[] = "e.org_code = :org";
        $whereConditions[] = "e.br_code = :br";
        $params['org'] = $userOrg;
        $params['br']  = $userBr;
    }
}

if (!empty($whereConditions)) {
    $sql .= " AND " . implode(" AND ", $whereConditions);
}

$sql .= " ORDER BY s.name ASC, e.title ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching exams: " . $e->getMessage());
    $exams = [];
}

// -----------------------------
// Get exam performance
// -----------------------------
$examStatuses = [];
if (!empty($exams) && !empty($userId)) {
    $examIds = array_column($exams, 'exam_id');
    $placeholders = implode(',', array_fill(0, count($examIds), '?'));
    
    try {
        $statusStmt = $pdo->prepare("
            SELECT 
                exam_id, 
                COUNT(*) as attempt_count, 
                MAX(score_pct) as best_score,
                MAX(correct_ans) as best_correct,
                total_questions,
                MIN(time_submitted) as first_attempt,
                MAX(time_submitted) as last_attempt,
                GROUP_CONCAT(DISTINCT grade ORDER BY score_pct DESC) as grades
            FROM exam_results 
            WHERE exam_id IN ($placeholders) AND user_id = ?
            GROUP BY exam_id
        ");
        $params2 = array_merge($examIds, [$userId]);
        $statusStmt->execute($params2);
        $results = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $result) {
            $bestScore = round($result['best_score']);
            $bestCorrect = $result['best_correct'];
            $totalQues = $result['total_questions'];
            
            if ($bestScore >= 80) {
                $performance = 'excellent';
                $performanceText = 'Excellent';
                $icon = 'bi-trophy-fill';
                $color = '#0a8f55';
            } elseif ($bestScore >= 60) {
                $performance = 'good';
                $performanceText = 'Good';
                $icon = 'bi-check-circle-fill';
                $color = '#3b82a0';
            } elseif ($bestScore >= 40) {
                $performance = 'average';
                $performanceText = 'Average';
                $icon = 'bi-flag-fill';
                $color = '#d9a441';
            } else {
                $performance = 'needs-improvement';
                $performanceText = 'Needs Work';
                $icon = 'bi-exclamation-triangle-fill';
                $color = '#f42a41';
            }
            
            $examStatuses[$result['exam_id']] = [
                'attempted' => true,
                'attempt_count' => $result['attempt_count'],
                'best_score' => $result['best_score'],
                'best_correct' => $bestCorrect,
                'total_questions' => $totalQues,
                'best_wrong' => $totalQues - $bestCorrect,
                'best_score_rounded' => $bestScore,
                'performance' => $performance,
                'performance_text' => $performanceText,
                'icon' => $icon,
                'color' => $color,
                'last_attempt' => $result['last_attempt'],
                'grades' => $result['grades']
            ];
        }
    } catch (PDOException $e) {
        error_log("Error fetching exam status: " . $e->getMessage());
    }
}

// -----------------------------
// Aggregate stats
// -----------------------------
$totalExamsCount = count($exams);
$attemptedCount = 0;
$scoreSum = 0;
$scoreCounted = 0;
foreach ($exams as $exam) {
    if (isset($examStatuses[$exam['exam_id']])) {
        $attemptedCount++;
        $scoreSum += $examStatuses[$exam['exam_id']]['best_score_rounded'];
        $scoreCounted++;
    }
}
$notStartedCount = $totalExamsCount - $attemptedCount;
$avgScore = $scoreCounted > 0 ? round($scoreSum / $scoreCounted) : 0;

// -----------------------------
// Performance graph data
// -----------------------------
$finishedPct = $totalExamsCount > 0 
    ? round(($attemptedCount / $totalExamsCount) * 100, 1) 
    : 0;

$unfinishedPct = $totalExamsCount > 0 
    ? round(($notStartedCount / $totalExamsCount) * 100, 1) 
    : 0;

$excellentCount = 0;
$goodCount = 0;
$averageCount = 0;
$needsWorkCount = 0;

foreach ($examStatuses as $status) {
    $s = (int)$status['best_score_rounded'];
    if ($s >= 80)      $excellentCount++;
    elseif ($s >= 60)  $goodCount++;
    elseif ($s >= 40)  $averageCount++;
    else               $needsWorkCount++;
}

// Function to build exam card
function getExamCard($exam, $statuses, $searchKeyword) {
    $examId = $exam['exam_id'];
    $hasResult = isset($statuses[$examId]) && $statuses[$examId]['attempted'];

    $ringColor = $hasResult ? $statuses[$examId]['color'] : '#94a3b8';
    $scoreValue = $hasResult ? $statuses[$examId]['best_score_rounded'] : 0;
    $circumference = 2 * M_PI * 24;
    $offset = $circumference - ($circumference * $scoreValue / 100);

    ob_start();
    ?>
    <div class="exam-card" id="exam-row-<?= htmlspecialchars($examId) ?>">
        <div class="exam-card-top">
            <?php if (!empty($exam['subject_name'])): ?>
                <span class="chip chip-subject"><i class="bi bi-book"></i> <?= htmlspecialchars($exam['subject_name']) ?></span>
            <?php else: ?>
                <span class="chip chip-muted">Not Assigned</span>
            <?php endif; ?>

            <?php if (!empty($exam['exam_type_name'])): ?>
                <span class="chip chip-type"><i class="bi bi-tag"></i> <?= htmlspecialchars($exam['exam_type_name']) ?></span>
            <?php endif; ?>

            <?php if ($hasResult): ?>
                <span class="chip chip-status" style="background:<?= $ringColor ?>1a; color:<?= $ringColor ?>;">
                    <i class="bi <?= $statuses[$examId]['icon'] ?>"></i> <?= $statuses[$examId]['performance_text'] ?>
                </span>
            <?php else: ?>
                <span class="chip chip-status chip-not-started"><i class="bi bi-hourglass-split"></i> Not Started</span>
            <?php endif; ?>
        </div>

        <h3 class="exam-title">
            <?= htmlspecialchars($exam['title']) ?>
            <?php if (!empty($searchKeyword) && stripos($exam['title'], $searchKeyword) !== false): ?>
                <span class="match-dot" title="Matches your search"></span>
            <?php endif; ?>
        </h3>

        <div class="exam-card-body">
            <div class="ring-wrap">
                <svg class="score-ring" width="60" height="60" viewBox="0 0 60 60">
                    <circle cx="30" cy="30" r="24" class="ring-track"/>
                    <circle cx="30" cy="30" r="24" class="ring-progress"
                        stroke="<?= $ringColor ?>"
                        stroke-dasharray="<?= $circumference ?>"
                        stroke-dashoffset="<?= $hasResult ? $offset : $circumference ?>"/>
                </svg>
                <span class="ring-label"><?= $hasResult ? $scoreValue . '%' : '—' ?></span>
            </div>

            <div class="exam-meta">
                <?php if ($hasResult): ?>
                    <div class="meta-row">
                        <i class="bi bi-check2-circle"></i>
                        <span><?= $statuses[$examId]['best_correct'] ?>/<?= $statuses[$examId]['total_questions'] ?> correct</span>
                    </div>
                    <div class="meta-row">
                        <i class="bi bi-arrow-repeat"></i>
                        <span><?= $statuses[$examId]['attempt_count'] ?> attempt<?= $statuses[$examId]['attempt_count'] > 1 ? 's' : '' ?></span>
                    </div>
                <?php else: ?>
                    <div class="meta-row">
                        <i class="bi bi-play-circle"></i>
                        <span>Ready to begin</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <a href="view_exam.php?id=<?= urlencode($examId) ?>" class="exam-action">
            <?= $hasResult ? 'Retake Exam' : 'Start Exam' ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    <?php
    return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exam Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* ============================================
           THEME — Exam360 Green / Gold / Red
           ============================================ */
        :root {
            --g:      #00693e;
            --g2:     #0a8f55;
            --gd:     #024a2c;
            --gl:     #e4f2e9;
            --r:      #f42a41;
            --rl:     #fdecee;
            --gold:   #d9a441;

            --ink:    #10231a;
            --muted:  #5a6c62;
            --border: #e1e9e3;
            --bg:     #fbfcfb;
            --card:   #ffffff;
            --surface:#f2f6f3;

            --radius: 18px;
            --shadow: 0 12px 32px -12px rgba(2, 74, 44, 0.15);
            --shadow-lg: 0 24px 60px -18px rgba(2, 74, 44, 0.25);
        }

        * { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
        body { background: var(--bg); color: var(--ink); }
        main.container { max-width: 1200px; }

        /* ============================================
           HERO
           ============================================ */
        .hero {
            background: linear-gradient(135deg, var(--gd) 0%, var(--g) 55%, var(--g2) 100%);
            border-radius: 22px;
            padding: 34px 32px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        .hero::after {
            content: '';
            position: absolute;
            top: -70px; right: -70px;
            width: 260px; height: 260px;
            background: rgba(244, 42, 65, 0.18);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero::before {
            content: '';
            position: absolute;
            bottom: -80px; left: -40px;
            width: 200px; height: 200px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-title {
            font-weight: 800;
            font-size: 26px;
            margin-bottom: 4px;
            position: relative;
            z-index: 1;
            letter-spacing: -0.01em;
        }
        .hero-sub {
            opacity: 0.9;
            font-size: 14px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        .hero-sub i { color: var(--gold); }

        .stat-row {
            display: flex;
            gap: 16px;
            margin-top: 20px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        .stat-pill {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(6px);
            border-radius: 14px;
            padding: 14px 20px;
            min-width: 130px;
            transition: background 0.2s, transform 0.2s;
        }
        .stat-pill:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateY(-2px);
        }
        .stat-pill .num { font-size: 22px; font-weight: 800; line-height: 1; }
        .stat-pill .lbl { font-size: 12px; opacity: 0.9; margin-top: 4px; }

        /* ============================================
           PERFORMANCE GRAPH SECTION
           ============================================ */
        .perf-section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .perf-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .perf-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.01em;
        }
        .perf-title i { color: var(--g); font-size: 18px; }
        .perf-sub { font-size: 13px; color: var(--muted); margin: 0; }
        .perf-badge {
            background: linear-gradient(135deg, var(--g), var(--g2));
            color: #fff;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 6px 16px -6px var(--g);
            white-space: nowrap;
        }
        .perf-badge i { color: var(--gold); }

        .perf-grid {
            display: grid;
            grid-template-columns: auto 1fr 1.3fr;
            gap: 32px;
            align-items: center;
        }

        .perf-donut-wrap {
            position: relative;
            width: 220px;
            height: 220px;
            flex-shrink: 0;
        }
        .perf-donut-wrap canvas {
            width: 220px !important;
            height: 220px !important;
        }
        .perf-donut-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            pointer-events: none;
        }
        .pdc-num {
            display: block;
            font-size: 32px;
            font-weight: 800;
            color: var(--g);
            line-height: 1;
            letter-spacing: -0.02em;
        }
        .pdc-lbl {
            display: block;
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .perf-legend {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: background 0.2s, transform 0.2s;
        }
        .legend-item:hover {
            background: var(--gl);
            transform: translateX(4px);
        }
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }
        .legend-body { flex: 1; }
        .legend-lbl {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .legend-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--ink);
            line-height: 1.2;
            margin-top: 2px;
        }
        .legend-val small {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-left: 4px;
        }

        .perf-bars {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .perf-bar-row {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            gap: 12px;
            align-items: center;
        }
        .perf-bar-lbl {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .perf-bar-lbl i { font-size: 12px; color: var(--g); }
        .perf-bar-lbl span { color: var(--muted); font-size: 11px; font-weight: 500; }
        .perf-bar-track {
            height: 10px;
            background: var(--surface);
            border-radius: 99px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .perf-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .perf-bar-val {
            font-size: 14px;
            font-weight: 800;
            color: var(--ink);
            min-width: 28px;
            text-align: right;
        }

        /* ============================================
           FILTER SECTION
           ============================================ */
        .filter-section {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .search-box { position: relative; }
        .search-box .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }
        .search-box input {
            padding-left: 40px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            height: 46px;
            background: var(--surface);
            color: var(--ink);
            font-size: 15px;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .search-box input:focus {
            border-color: var(--g);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0, 105, 62, 0.12);
            outline: none;
        }
        .search-box input::placeholder { color: #9caea3; }

        .loading-indicator {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        .search-box.loading .loading-indicator { display: block; }
        .loading-indicator .spinner-border { color: var(--g) !important; }

        .form-select {
            border-radius: 12px;
            border: 1.5px solid var(--border);
            height: 46px;
            background: var(--surface);
            color: var(--ink);
            font-size: 14.5px;
            font-weight: 500;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .form-select:focus {
            border-color: var(--g);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0, 105, 62, 0.12);
            outline: none;
        }
        .form-select:disabled { background-color: var(--surface); opacity: 0.6; }

        .btn-clear {
            border-radius: 12px;
            height: 46px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            font-weight: 600;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }
        .btn-clear:hover {
            background: var(--rl);
            border-color: var(--r);
            color: var(--r);
        }

        .filter-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .filter-label i { color: var(--g); }

        .admin-badge {
            background: linear-gradient(135deg, var(--r), #c81e32);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 10px;
            vertical-align: middle;
            box-shadow: 0 4px 12px rgba(244, 42, 65, 0.35);
        }

        .hierarchy-divider {
            font-size: 12px;
            font-weight: 700;
            color: var(--gd);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding-top: 14px;
            margin-top: 4px;
            margin-bottom: 12px;
            border-top: 1px dashed var(--border);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .hierarchy-divider i { color: var(--g); }

        .info-alert {
            background: var(--gl);
            border-left: 4px solid var(--g);
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 10px;
            color: var(--gd);
            font-size: 14px;
        }
        .info-alert i { color: var(--g); margin-right: 6px; }

        /* ============================================
           EXAM GRID & CARDS
           ============================================ */
        #examGrid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }

        .exam-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 18px 18px;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
            position: relative;
            overflow: hidden;
        }
        .exam-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--g), var(--g2));
            opacity: 0;
            transition: opacity 0.25s;
        }
        .exam-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
            border-color: rgba(0, 105, 62, 0.25);
        }
        .exam-card:hover::before { opacity: 1; }

        .exam-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .chip {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 11px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            letter-spacing: 0.01em;
        }
        .chip i { font-size: 11px; }
        .chip-subject { background: var(--gl); color: var(--gd); }
        .chip-type { background: #fdf3e0; color: #8a5e00; }
        .chip-muted { background: var(--surface); color: var(--muted); }
        .chip-status { background: var(--surface); color: var(--muted); }
        .chip-not-started { background: var(--surface); color: var(--muted); }

        .exam-title {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 14px;
            line-height: 1.4;
            min-height: 44px;
            letter-spacing: -0.005em;
        }
        .match-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--r);
            margin-left: 6px;
            box-shadow: 0 0 0 3px rgba(244, 42, 65, 0.2);
        }

        .exam-card-body {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            flex: 1;
        }

        .ring-wrap { position: relative; width: 60px; height: 60px; flex-shrink: 0; }
        .score-ring { transform: rotate(-90deg); }
        .ring-track { fill: none; stroke: var(--border); stroke-width: 6; }
        .ring-progress { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.4s ease; }
        .ring-label {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            font-size: 12px; font-weight: 800; color: var(--ink);
        }

        .exam-meta { font-size: 12.5px; color: var(--muted); }
        .meta-row { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
        .meta-row i { font-size: 12px; color: var(--g); }

        .exam-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            text-align: center;
            background: linear-gradient(135deg, var(--g), var(--g2));
            color: #fff;
            font-weight: 700;
            font-size: 13.5px;
            padding: 11px 16px;
            border-radius: 11px;
            transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s;
            letter-spacing: 0.01em;
        }
        .exam-action:hover {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px -8px var(--g);
            filter: brightness(1.05);
        }
        .exam-action i { margin-left: 2px; transition: transform 0.2s; }
        .exam-action:hover i { transform: translateX(3px); }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
            background: var(--card);
            border: 1px dashed var(--border);
            border-radius: var(--radius);
        }
        .empty-state i {
            font-size: 48px;
            color: #cbd5c4;
            margin-bottom: 12px;
            display: inline-block;
        }
        .empty-state p { color: var(--muted); margin: 0; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 900px) {
            .perf-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
            .perf-donut-wrap { margin: 0 auto; }
            .perf-badge { padding: 6px 12px; font-size: 12px; }
        }
        @media (max-width: 576px) {
            .hero { padding: 24px 22px; }
            .hero-title { font-size: 22px; }
            .stat-pill { min-width: 110px; padding: 12px 16px; }
            .stat-pill .num { font-size: 20px; }
            .filter-section .row > div { margin-bottom: 10px; }
            .perf-bar-row { grid-template-columns: 1fr; gap: 6px; }
            .perf-bar-val { text-align: left; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>
<main class="container py-4 flex-grow-1">

    <!-- =========================================================
         HERO
         ========================================================= -->
    <div class="hero">
        <div class="hero-title">
            My Exams
            <?php if ($isAdmin): ?>
                <span class="admin-badge"><i class="bi bi-shield-lock"></i> Admin Mode</span>
            <?php endif; ?>
        </div>
        <div class="hero-sub">
            <?php if (!$isAdmin && !empty($userLevelName) && $userLevelId > 0): ?>
                <i class="bi bi-star-fill"></i> Your Level: <?= htmlspecialchars($userLevelName) ?> (<?= $userLevelId ?>)
            <?php else: ?>
                Track your progress and jump back into an exam.
            <?php endif; ?>
        </div>

        <div class="stat-row">
            <div class="stat-pill">
                <div class="num" id="statTotal"><?= $totalExamsCount ?></div>
                <div class="lbl">Total Exams</div>
            </div>
            <div class="stat-pill">
                <div class="num" id="statAttempted"><?= $attemptedCount ?></div>
                <div class="lbl">Attempted</div>
            </div>
            <div class="stat-pill">
                <div class="num" id="statNotStarted"><?= $notStartedCount ?></div>
                <div class="lbl">Not Started</div>
            </div>
            <div class="stat-pill">
                <div class="num" id="statAvg"><?= $avgScore ?>%</div>
                <div class="lbl">Average Score</div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         PERFORMANCE GRAPH
         ========================================================= -->
    <div class="perf-section">
        <div class="perf-header">
            <div>
                <h4 class="perf-title">
                    <i class="bi bi-bar-chart-line-fill"></i> Performance Overview
                </h4>
                <p class="perf-sub">Your progress across all assigned exams</p>
            </div>
            <div class="perf-badge">
                <i class="bi bi-trophy-fill"></i> <?= $avgScore ?>% Average
            </div>
        </div>

        <div class="perf-grid">
            <!-- Donut chart -->
            <div class="perf-donut-wrap">
                <canvas id="perfDonut" width="220" height="220"></canvas>
                <div class="perf-donut-center">
                    <span class="pdc-num"><?= $finishedPct ?>%</span>
                    <span class="pdc-lbl">Completed</span>
                </div>
            </div>

            <!-- Legend -->
            <div class="perf-legend">
                <div class="legend-item">
                    <span class="legend-dot" style="background:#00693e"></span>
                    <div class="legend-body">
                        <div class="legend-lbl">Finished</div>
                        <div class="legend-val">
                            <?= $attemptedCount ?> <small>exams (<?= $finishedPct ?>%)</small>
                        </div>
                    </div>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#d9a441"></span>
                    <div class="legend-body">
                        <div class="legend-lbl">Unfinished</div>
                        <div class="legend-val">
                            <?= $notStartedCount ?> <small>exams (<?= $unfinishedPct ?>%)</small>
                        </div>
                    </div>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#f42a41"></span>
                    <div class="legend-body">
                        <div class="legend-lbl">Total Assigned</div>
                        <div class="legend-val">
                            <?= $totalExamsCount ?> <small>exams (100%)</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score breakdown bars -->
            <div class="perf-bars">
                <div class="perf-bar-row">
                    <div class="perf-bar-lbl">
                        <i class="bi bi-trophy-fill"></i> Excellent <span>(≥80%)</span>
                    </div>
                    <div class="perf-bar-track">
                        <div class="perf-bar-fill" 
                             style="width:<?= $attemptedCount > 0 ? round(($excellentCount / $attemptedCount) * 100, 1) : 0 ?>%; 
                                    background:linear-gradient(90deg,#0a8f55,#3fc088)">
                        </div>
                    </div>
                    <div class="perf-bar-val"><?= $excellentCount ?></div>
                </div>

                <div class="perf-bar-row">
                    <div class="perf-bar-lbl">
                        <i class="bi bi-check-circle-fill"></i> Good <span>(60–79%)</span>
                    </div>
                    <div class="perf-bar-track">
                        <div class="perf-bar-fill" 
                             style="width:<?= $attemptedCount > 0 ? round(($goodCount / $attemptedCount) * 100, 1) : 0 ?>%; 
                                    background:linear-gradient(90deg,#3b82a0,#63b3d0)">
                        </div>
                    </div>
                    <div class="perf-bar-val"><?= $goodCount ?></div>
                </div>

                <div class="perf-bar-row">
                    <div class="perf-bar-lbl">
                        <i class="bi bi-flag-fill"></i> Average <span>(40–59%)</span>
                    </div>
                    <div class="perf-bar-track">
                        <div class="perf-bar-fill" 
                             style="width:<?= $attemptedCount > 0 ? round(($averageCount / $attemptedCount) * 100, 1) : 0 ?>%; 
                                    background:linear-gradient(90deg,#d9a441,#f5c35c)">
                        </div>
                    </div>
                    <div class="perf-bar-val"><?= $averageCount ?></div>
                </div>

                <div class="perf-bar-row">
                    <div class="perf-bar-lbl">
                        <i class="bi bi-exclamation-triangle-fill"></i> Needs Work <span>(&lt;40%)</span>
                    </div>
                    <div class="perf-bar-track">
                        <div class="perf-bar-fill" 
                             style="width:<?= $attemptedCount > 0 ? round(($needsWorkCount / $attemptedCount) * 100, 1) : 0 ?>%; 
                                    background:linear-gradient(90deg,#f42a41,#ff7a8a)">
                        </div>
                    </div>
                    <div class="perf-bar-val"><?= $needsWorkCount ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         FILTER SECTION
         ========================================================= -->
    <div class="filter-section">
        <div class="row align-items-end">
            <div class="col-md-12 mb-3">
                <div class="filter-label">Search</div>
                <div class="search-box" id="searchBox">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" 
                           class="form-control" 
                           placeholder="Search by Exam ID, Title, Subject, Level, or Type..." 
                           value="<?= htmlspecialchars($searchKeyword) ?>"
                           id="searchInput"
                           autocomplete="off">
                    <div class="loading-indicator">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($isAdmin): ?>
            <div class="col-6 col-md-4 col-lg-3 filter-group mb-3">
                <div class="filter-label"><i class="bi bi-star"></i> Level</div>
                <select name="level_id" class="form-select" id="levelSelect">
                    <option value="0">All Levels</option>
                    <?php foreach ($allLevels as $level): ?>
                        <option value="<?= $level['id'] ?>" <?= $selectedLevel == $level['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($level['name']) ?> (<?= $level['id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Exam Type dropdown is REMOVED — hardcoded to ID 3 -->

            <div class="col-6 col-md-4 col-lg-3 filter-group mb-3">
                <div class="filter-label">&nbsp;</div>
                <button type="button" class="btn btn-clear w-100" id="clearFiltersBtn">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>

        <?php if ($wantsSubjectDropdown || $wantsChapterDropdown || $wantsTopicDropdown): ?>
        <div id="hierarchyPanel">
            <div class="hierarchy-divider"><i class="bi bi-diagram-3"></i> Narrow down by</div>
            <div class="row align-items-end">
                <?php if ($wantsSubjectDropdown): ?>
                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="subjectFilterGroup"
                     data-has-options="<?= !empty($subjects) ? '1' : '0' ?>">
                    <div class="filter-label"><i class="bi bi-book"></i> Subject</div>
                    <select name="subject_id" class="form-select" id="subjectSelect">
                        <option value="0">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= $subject['id'] ?>" <?= $selectedSubject == $subject['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subject['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($wantsChapterDropdown): ?>
                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="chapterFilterGroup"
                     data-has-options="<?= !empty($chapters) ? '1' : '0' ?>">
                    <div class="filter-label"><i class="bi bi-bookmark"></i> Chapter</div>
                    <select name="chapter_id" class="form-select" id="chapterSelect" <?= $selectedSubject <= 0 ? 'disabled' : '' ?>>
                        <option value="0">All Chapters</option>
                        <?php foreach ($chapters as $chapter): ?>
                            <option value="<?= $chapter['id'] ?>" <?= $selectedChapter == $chapter['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($chapter['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($wantsTopicDropdown): ?>
                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="topicFilterGroup"
                     data-has-options="<?= !empty($topics) ? '1' : '0' ?>">
                    <div class="filter-label"><i class="bi bi-diagram-3"></i> Topic</div>
                    <select name="topic_id" class="form-select" id="topicSelect" <?= $selectedChapter <= 0 ? 'disabled' : '' ?>>
                        <option value="0">All Topics</option>
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?= $topic['id'] ?>" <?= $selectedTopic == $topic['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($topic['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (count($exams) == 0 && !empty($searchKeyword)): ?>
        <div class="info-alert">
            <i class="bi bi-info-circle-fill"></i> 
            No exams found matching "<strong><?= htmlspecialchars($searchKeyword) ?></strong>". Try a different search term.
        </div>
    <?php endif; ?>

    <div id="examGrid">
        <?php if (count($exams) > 0): ?>
            <?php foreach ($exams as $exam): ?>
                <?= getExamCard($exam, $examStatuses, $searchKeyword) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state" id="no-results-row">
                <i class="bi bi-inbox"></i>
                <p>No exams found matching your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* =========================================================
       PERFORMANCE DONUT CHART
       ========================================================= */
    (function initPerfChart() {
        const canvas = document.getElementById('perfDonut');
        if (!canvas) return;

        const finished   = <?= (int)$attemptedCount ?>;
        const unfinished = <?= (int)$notStartedCount ?>;
        const totalQ     = finished + unfinished;

        const cGreen = '#00693e';
        const cGold  = '#d9a441';

        if (totalQ === 0) {
            new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['No exams assigned'],
                    datasets: [{
                        data: [1],
                        backgroundColor: ['#e1e9e3'],
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: false,
                    cutout: '72%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                }
            });
            return;
        }

        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Finished', 'Unfinished'],
                datasets: [{
                    data: [finished, unfinished],
                    backgroundColor: [cGreen, cGold],
                    borderColor: '#ffffff',
                    borderWidth: 4,
                    hoverOffset: 8,
                    hoverBorderWidth: 4
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: {
                    animateRotate: true,
                    animateScale: false,
                    duration: 900,
                    easing: 'easeOutCubic'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(2, 74, 44, 0.95)',
                        titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: '700' },
                        bodyFont:  { family: 'Plus Jakarta Sans', size: 12, weight: '500' },
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        callbacks: {
                            label: function (item) {
                                const val = item.raw;
                                const pct = totalQ > 0 ? ((val / totalQ) * 100).toFixed(1) : 0;
                                return ' ' + item.label + ': ' + val + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    })();

    /* =========================================================
       FILTERS & AJAX
       ========================================================= */
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    const levelSelect = document.getElementById('levelSelect');
    const subjectSelect = document.getElementById('subjectSelect');
    const chapterSelect = document.getElementById('chapterSelect');
    const topicSelect = document.getElementById('topicSelect');
    const clearBtn = document.getElementById('clearFiltersBtn');
    const searchBox = document.getElementById('searchBox');
    const examGrid = document.getElementById('examGrid');
    const statTotal = document.getElementById('statTotal');
    const statAttempted = document.getElementById('statAttempted');
    const statNotStarted = document.getElementById('statNotStarted');
    const statAvg = document.getElementById('statAvg');

    function fetchFilteredResults() {
        const searchValue = searchInput ? searchInput.value : '';
        const levelValue = levelSelect ? levelSelect.value : 0;
        const subjectValue = subjectSelect ? subjectSelect.value : 0;
        const chapterValue = chapterSelect ? chapterSelect.value : 0;
        const topicValue = topicSelect ? topicSelect.value : 0;

        if (searchBox) searchBox.classList.add('loading');

        let url = window.location.pathname + '?';
        if (levelValue && levelValue != 0) url += `level_id=${levelValue}&`;
        if (subjectValue && subjectValue != 0) url += `subject_id=${subjectValue}&`;
        if (chapterValue && chapterValue != 0) url += `chapter_id=${chapterValue}&`;
        if (topicValue && topicValue != 0) url += `topic_id=${topicValue}&`;
        if (searchValue) url += `search=${encodeURIComponent(searchValue)}&`;

        url = url.replace(/[&?]$/, '');

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newGrid = doc.getElementById('examGrid');
            const newStatTotal = doc.getElementById('statTotal');
            const newStatAttempted = doc.getElementById('statAttempted');
            const newStatNotStarted = doc.getElementById('statNotStarted');
            const newStatAvg = doc.getElementById('statAvg');
            const newSubjectSelect = doc.getElementById('subjectSelect');
            const newChapterSelect = doc.getElementById('chapterSelect');
            const newTopicSelect = doc.getElementById('topicSelect');

            if (newGrid) examGrid.innerHTML = newGrid.innerHTML;
            if (newStatTotal) statTotal.innerHTML = newStatTotal.innerHTML;
            if (newStatAttempted) statAttempted.innerHTML = newStatAttempted.innerHTML;
            if (newStatNotStarted) statNotStarted.innerHTML = newStatNotStarted.innerHTML;
            if (newStatAvg) statAvg.innerHTML = newStatAvg.innerHTML;

            if (subjectSelect && newSubjectSelect) {
                subjectSelect.innerHTML = newSubjectSelect.innerHTML;
                subjectSelect.value = subjectValue || '0';
            }
            if (chapterSelect && newChapterSelect) {
                chapterSelect.innerHTML = newChapterSelect.innerHTML;
                chapterSelect.value = chapterValue || '0';
                chapterSelect.disabled = !(subjectSelect && subjectSelect.value != 0);
            }
            if (topicSelect && newTopicSelect) {
                topicSelect.innerHTML = newTopicSelect.innerHTML;
                topicSelect.value = topicValue || '0';
                topicSelect.disabled = !(chapterSelect && chapterSelect.value != 0);
            }

            window.history.pushState({}, '', url);
        })
        .catch(error => {
            console.error('Error:', error);
        })
        .finally(() => {
            if (searchBox) searchBox.classList.remove('loading');
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetchFilteredResults();
            }, 400);
        });
    }

    if (levelSelect) {
        levelSelect.addEventListener('change', function() {
            if (subjectSelect) subjectSelect.value = '0';
            if (chapterSelect) chapterSelect.value = '0';
            if (topicSelect) topicSelect.value = '0';
            fetchFilteredResults();
        });
    }

    if (subjectSelect) {
        subjectSelect.addEventListener('change', function() {
            if (chapterSelect) chapterSelect.value = '0';
            if (topicSelect) topicSelect.value = '0';
            if (chapterSelect) chapterSelect.disabled = (subjectSelect.value == 0);
            if (topicSelect) topicSelect.disabled = true;
            fetchFilteredResults();
        });
    }

    if (chapterSelect) {
        chapterSelect.addEventListener('change', function() {
            if (topicSelect) topicSelect.value = '0';
            if (topicSelect) topicSelect.disabled = (chapterSelect.value == 0);
            fetchFilteredResults();
        });
    }

    if (topicSelect) {
        topicSelect.addEventListener('change', function() {
            fetchFilteredResults();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (levelSelect) levelSelect.value = '0';
            if (subjectSelect) subjectSelect.value = '0';
            if (chapterSelect) chapterSelect.value = '0';
            if (topicSelect) topicSelect.value = '0';
            fetchFilteredResults();
        });
    }
</script>
</body>
</html>