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
// Session user info for filtering
// -----------------------------
$userSession = $_SESSION['user'];
$userType    = $userSession['user_type_id'] ?? 0; 
$userOrg     = $userSession['org_code'] ?? '';
$userBr      = $userSession['br_code'] ?? '';
$userLevelId = $userSession['level_id'] ?? 0;
$userId = $userSession['user_id'] ?? $userSession['USER_ID'] ?? '';

// Fetch level name from levels table
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
// Role-based Level access
// Role levels (user_type_id) 1 and 2 => can see all levels + get the Level filter.
// Every other role level => Level filter hidden entirely; their own level_id is
// always applied automatically and cannot be changed via the URL/filters.
// -----------------------------
$isAdmin = ($userType == 1 || $userType == 2);

// -----------------------------
// Get selected filters from URL
// Nothing is applied by default - these all start at 0/'' until the user picks one.
// -----------------------------
$selectedSubject  = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$selectedLevel    = isset($_GET['level_id']) ? (int)$_GET['level_id'] : 0;
$selectedExamType = isset($_GET['exam_type_id']) ? (int)$_GET['exam_type_id'] : 0;
$selectedChapter  = isset($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : 0;
$selectedTopic    = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$searchKeyword    = isset($_GET['search']) ? trim($_GET['search']) : '';

// Non-admin roles: level is always their own, fixed, never user-selectable
if (!$isAdmin && $userLevelId > 0) {
    $selectedLevel = $userLevelId;
}

// -----------------------------
// Get All Levels (for admin dropdown only - roles 1 & 2)
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
// Get All Exam Types (for dropdown)
// Only types that have at least one authorized exam show up, scoped to the
// effective level when one is set (selected by admin, or fixed for non-admin).
// Table assumed: exam_types (id, type_name, description, status)
// -----------------------------
$allExamTypes = [];
try {
    $effectiveLevelForTypes = $isAdmin ? $selectedLevel : $userLevelId;
    if ($effectiveLevelForTypes > 0) {
        $typeStmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.type_name, t.description
            FROM exam_types t
            INNER JOIN exams_info e ON e.exam_type_id = t.id
            WHERE e.AUTHORIZED_STATUS = 'Y' AND e.level_id = ? AND t.status = 'Y'
            ORDER BY t.type_name
        ");
        $typeStmt->execute([$effectiveLevelForTypes]);
        $allExamTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Admin with no level chosen yet: show all types that have any authorized exam
        $typeStmt = $pdo->query("
            SELECT DISTINCT t.id, t.type_name, t.description
            FROM exam_types t
            INNER JOIN exams_info e ON e.exam_type_id = t.id
            WHERE e.AUTHORIZED_STATUS = 'Y' AND t.status = 'Y'
            ORDER BY t.type_name
        ");
        $allExamTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching exam types: " . $e->getMessage());
    $allExamTypes = [];
}

// -----------------------------
// Exam Type is a standalone filter, kept deliberately separate from the
// Subject/Chapter/Topic taxonomy filters below. It ONLY decides which (if any)
// of those taxonomy dropdowns are relevant to show:
//   - no exam type selected  -> no taxonomy dropdowns at all
//   - "FULL"    exam type    -> no taxonomy dropdowns at all (covers everything)
//   - "SUBJECT" exam type    -> Subject only
//   - "CHAPTER" exam type    -> Subject + Chapter
//   - "TOPIC"   exam type    -> Subject + Chapter + Topic
//
// IMPORTANT: this lookup is done directly against exam_types by id, NOT by
// searching $allExamTypes. $allExamTypes is scoped to the current effective
// level (for populating the dropdown options), so a type that was selected
// under one level can silently disappear from that list after the level
// filter changes — which would wrongly blank out $selectedTypeKey and hide
// dropdowns that should still be visible. Looking it up directly avoids that.
// -----------------------------
$selectedTypeKey = '';
if ($selectedExamType > 0) {
    try {
        $typeKeyStmt = $pdo->prepare("SELECT type_name FROM exam_types WHERE id = ?");
        $typeKeyStmt->execute([$selectedExamType]);
        $typeKeyName = $typeKeyStmt->fetchColumn();
        if ($typeKeyName !== false) {
            $selectedTypeKey = strtoupper($typeKeyName);
        }
    } catch (PDOException $e) {
        $selectedTypeKey = '';
    }
}
$wantsSubjectDropdown = in_array($selectedTypeKey, ['SUBJECT', 'CHAPTER', 'TOPIC'], true);
$wantsChapterDropdown = in_array($selectedTypeKey, ['CHAPTER', 'TOPIC'], true);
$wantsTopicDropdown   = ($selectedTypeKey === 'TOPIC');

// -----------------------------
// Get Subjects based on selected level
// -----------------------------
$subjects = [];
if ($isAdmin && $selectedLevel > 0) {
    try {
        $subjectStmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.name 
            FROM subjects s
            INNER JOIN exams_info e ON e.subject_id = s.id
            WHERE e.level_id = ? AND e.AUTHORIZED_STATUS = 'Y'
            ORDER BY s.name
        ");
        $subjectStmt->execute([$selectedLevel]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $subjects = [];
    }
} elseif (!$isAdmin) {
    try {
        $subjectStmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.name 
            FROM subjects s
            INNER JOIN exams_info e ON e.subject_id = s.id
            WHERE e.level_id = ? AND e.AUTHORIZED_STATUS = 'Y'
            ORDER BY s.name
        ");
        $subjectStmt->execute([$userLevelId]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $subjects = [];
    }
} else {
    // Admin, no level chosen yet: all subjects with any authorized exam
    try {
        $subjectStmt = $pdo->query("
            SELECT DISTINCT s.id, s.name 
            FROM subjects s
            INNER JOIN exams_info e ON e.subject_id = s.id
            WHERE e.AUTHORIZED_STATUS = 'Y' 
            ORDER BY s.name
        ");
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $subjects = [];
    }
}

// -----------------------------
// Get Chapters based on selected subject (and effective level)
// Assumes table: chapters (id, name, subject_id, AUTHORIZED_STATUS)
// -----------------------------
$chapters = [];
if ($selectedSubject > 0) {
    try {
        $effectiveLevel = $isAdmin ? $selectedLevel : $userLevelId;
        $chapterSql = "
            SELECT DISTINCT c.id, c.name 
            FROM chapters c
            INNER JOIN exams_info e ON e.chapter_id = c.id
            WHERE e.AUTHORIZED_STATUS = 'Y' AND e.subject_id = :subject_id";
        $chapterParams = ['subject_id' => $selectedSubject];
        if ($effectiveLevel > 0) {
            $chapterSql .= " AND e.level_id = :level_id";
            $chapterParams['level_id'] = $effectiveLevel;
        }
        $chapterSql .= " ORDER BY c.name";
        $chapterStmt = $pdo->prepare($chapterSql);
        $chapterStmt->execute($chapterParams);
        $chapters = $chapterStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching chapters: " . $e->getMessage());
        $chapters = [];
    }
}

// -----------------------------
// Get Topics based on selected chapter
// Assumes table: topics (id, name, chapter_id, AUTHORIZED_STATUS)
// -----------------------------
$topics = [];
if ($selectedChapter > 0) {
    try {
        $topicStmt = $pdo->prepare("
            SELECT DISTINCT tp.id, tp.name 
            FROM topics tp
            INNER JOIN exams_info e ON e.topic_id = tp.id
            WHERE e.AUTHORIZED_STATUS = 'Y' AND e.chapter_id = :chapter_id
            ORDER BY tp.name
        ");
        $topicStmt->execute(['chapter_id' => $selectedChapter]);
        $topics = $topicStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching topics: " . $e->getMessage());
        $topics = [];
    }
}

// -----------------------------
// Fetch Exams with filters and search
// Base rule (always applied): e.AUTHORIZED_STATUS = 'Y'
// Everything else (level for admins, exam type, subject, chapter, topic,
// search) is only added to the WHERE clause when the user has actually
// selected it - so on first load with nothing selected, ALL authorized
// exams show up (still scoped to a non-admin's fixed level, since that's
// role-based access, not an optional filter).
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
        WHERE e.AUTHORIZED_STATUS = 'Y'";
$params = [];
$whereConditions = [];

// Level filter
if ($isAdmin) {
    // Admin/Manager: only restrict by level if they actually picked one
    if ($selectedLevel > 0) {
        $whereConditions[] = "e.level_id = :level_id";
        $params['level_id'] = $selectedLevel;
    }
} else {
    // Non-admin: role-based restriction, always applied, not optional
    if ($userLevelId > 0) {
        $whereConditions[] = "e.level_id = :level_id";
        $params['level_id'] = $userLevelId;
    }
}

// Exam Type filter
if ($selectedExamType > 0) {
    $whereConditions[] = "e.exam_type_id = :exam_type_id";
    $params['exam_type_id'] = $selectedExamType;
}

// Subject filter
if ($selectedSubject > 0) {
    $whereConditions[] = "e.subject_id = :subject_id";
    $params['subject_id'] = $selectedSubject;
}

// Chapter filter
if ($selectedChapter > 0) {
    $whereConditions[] = "e.chapter_id = :chapter_id";
    $params['chapter_id'] = $selectedChapter;
}

// Topic filter
if ($selectedTopic > 0) {
    $whereConditions[] = "e.topic_id = :topic_id";
    $params['topic_id'] = $selectedTopic;
}

// Global Search
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

// Organization/Branch filters for non-admin (role-based, always applied)
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

// Add WHERE clause if there are conditions beyond the base AUTHORIZED_STATUS check
if (!empty($whereConditions)) {
    $sql .= " AND " . implode(" AND ", $whereConditions);
}

// Add ORDER BY subject name
$sql .= " ORDER BY s.name ASC, e.title ASC";

// Execute the query
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching exams: " . $e->getMessage());
    $exams = [];
}

// -----------------------------
// Get exam performance from exam_results table
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
            
            // Determine performance level
            if ($bestScore >= 80) {
                $performance = 'excellent';
                $performanceText = 'Excellent';
                $icon = 'bi-trophy-fill';
                $color = '#10b981';
            } elseif ($bestScore >= 60) {
                $performance = 'good';
                $performanceText = 'Good';
                $icon = 'bi-check-circle-fill';
                $color = '#3b82f6';
            } elseif ($bestScore >= 40) {
                $performance = 'average';
                $performanceText = 'Average';
                $icon = 'bi-flag-fill';
                $color = '#f59e0b';
            } else {
                $performance = 'needs-improvement';
                $performanceText = 'Needs Work';
                $icon = 'bi-exclamation-triangle-fill';
                $color = '#ef4444';
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
// Aggregate stats for hero banner
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

// Function to build a single exam card (grid item)
function getExamCard($exam, $statuses, $searchKeyword) {
    $examId = $exam['exam_id'];
    $hasResult = isset($statuses[$examId]) && $statuses[$examId]['attempted'];

    $ringColor = $hasResult ? $statuses[$examId]['color'] : '#94a3b8';
    $scoreValue = $hasResult ? $statuses[$examId]['best_score_rounded'] : 0;
    $circumference = 2 * M_PI * 24; // r=24
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
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --ink: #0f172a;
            --muted: #64748b;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --radius: 16px;
        }

        * { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }

        body { background: var(--bg); color: var(--ink); }

        main.container { max-width: 1200px; }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
            border-radius: 20px;
            padding: 32px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }
        .hero-title { font-weight: 800; font-size: 26px; margin-bottom: 4px; }
        .hero-sub { opacity: 0.9; font-size: 14px; margin-bottom: 20px; }
        .hero-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .hero-badge {
            background: rgba(255,255,255,0.16);
            backdrop-filter: blur(4px);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .stat-row { display: flex; gap: 16px; margin-top: 20px; flex-wrap: wrap; position: relative; z-index: 1; }
        .stat-pill {
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 14px;
            padding: 14px 20px;
            min-width: 130px;
        }
        .stat-pill .num { font-size: 22px; font-weight: 800; line-height: 1; }
        .stat-pill .lbl { font-size: 12px; opacity: 0.85; margin-top: 4px; }

        /* Filter bar */
        .filter-section {
            background: #fff;
            padding: 18px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }
        .search-box { position: relative; }
        .search-box .search-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--muted);
        }
        .search-box input {
            padding-left: 40px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            height: 46px;
        }
        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .loading-indicator {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            display: none;
        }
        .search-box.loading .loading-indicator { display: block; }

        .form-select {
            border-radius: 12px;
            border: 1.5px solid var(--border);
            height: 46px;
        }
        .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .form-select:disabled { background-color: #f8fafc; opacity: 0.6; }

        .btn-clear {
            border-radius: 12px;
            height: 46px;
            border: 1.5px solid var(--border);
            color: var(--muted);
            font-weight: 600;
        }
        .btn-clear:hover { background: #f1f5f9; }

        .filter-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 6px;
        }

        .admin-badge {
            background: linear-gradient(135deg, #f43f5e, #e11d48);
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 10px;
            vertical-align: middle;
        }

        .hierarchy-divider {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding-top: 14px;
            margin-top: 4px;
            margin-bottom: 10px;
            border-top: 1px dashed var(--border);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-alert {
            background: var(--primary-light);
            border-left: 4px solid var(--primary);
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 10px;
            color: #3730a3;
        }

        /* Exam grid */
        #examGrid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }

        .exam-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
        }
        .exam-card:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            transform: translateY(-2px);
        }

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
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .chip-subject { background: var(--primary-light); color: var(--primary-dark); }
        .chip-type { background: #fdf2f8; color: #be185d; }
        .chip-muted { background: #f1f5f9; color: var(--muted); }
        .chip-status { background: #f1f5f9; color: var(--muted); }
        .chip-not-started { background: #f1f5f9; color: var(--muted); }

        .exam-title {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 14px;
            line-height: 1.35;
            min-height: 42px;
        }
        .match-dot {
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #f59e0b;
            margin-left: 6px;
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
        .ring-track { fill: none; stroke: #eef0f4; stroke-width: 6; }
        .ring-progress { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.4s ease; }
        .ring-label {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            font-size: 12px; font-weight: 800; color: var(--ink);
        }

        .exam-meta { font-size: 12.5px; color: var(--muted); }
        .meta-row { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
        .meta-row i { font-size: 12px; }

        .exam-action {
            text-decoration: none;
            text-align: center;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            font-size: 13.5px;
            padding: 10px;
            border-radius: 10px;
            transition: background 0.15s ease;
        }
        .exam-action:hover { background: var(--primary-dark); color: #fff; }
        .exam-action i { margin-left: 4px; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
        }

        @media (max-width: 576px) {
            .hero { padding: 22px; }
            .filter-section .row > div { margin-bottom: 10px; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>
<main class="container py-4 flex-grow-1">

    <!-- Hero -->
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

    <!-- Filter Section -->
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
            <!-- Level filter: role levels 1 & 2 ONLY -->
            <div class="col-6 col-md-4 col-lg-2 filter-group mb-3">
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
            <!-- Every other role level: no Level control rendered at all; $userLevelId is applied server-side above -->

            <!-- Exam Type: standalone filter, deliberately its own field here (not grouped
                 with Subject/Chapter/Topic below). Changing it only decides which of the
                 taxonomy dropdowns appear in the separate panel underneath. -->
            <div class="col-6 col-md-4 col-lg-2 filter-group mb-3">
                <div class="filter-label"><i class="bi bi-tag"></i> Exam Type</div>
                <select name="exam_type_id" class="form-select" id="examTypeSelect">
                    <option value="0" data-type="">All Types</option>
                    <?php foreach ($allExamTypes as $type): ?>
                        <option value="<?= $type['id'] ?>"
                                data-type="<?= htmlspecialchars(strtoupper($type['type_name'])) ?>"
                                title="<?= htmlspecialchars($type['description']) ?>"
                                <?= $selectedExamType == $type['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type['type_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-4 col-lg-2 filter-group mb-3">
                <div class="filter-label">&nbsp;</div>
                <button type="button" class="btn btn-clear w-100" id="clearFiltersBtn">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>

        <!-- Hierarchy filters: Subject -> Chapter -> Topic. Kept in a visually separate
             panel below Exam Type (its own bordered/labeled block), and only rendered
             at all once the selected Exam Type actually needs one or more of them. -->
        <div id="hierarchyPanel" style="<?= ($wantsSubjectDropdown || $wantsChapterDropdown || $wantsTopicDropdown) ? '' : 'display:none;' ?>">
            <div class="hierarchy-divider"><i class="bi bi-diagram-3"></i> Narrow down by</div>
            <div class="row align-items-end">
                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="subjectFilterGroup"
                     data-has-options="<?= !empty($subjects) ? '1' : '0' ?>"
                     style="<?= ($wantsSubjectDropdown && !empty($subjects)) ? '' : 'display:none;' ?>">
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

                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="chapterFilterGroup"
                     data-has-options="<?= !empty($chapters) ? '1' : '0' ?>"
                     style="<?= ($wantsChapterDropdown && !empty($chapters)) ? '' : 'display:none;' ?>">
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

                <div class="col-6 col-md-4 col-lg-3 filter-group mb-3" id="topicFilterGroup"
                     data-has-options="<?= !empty($topics) ? '1' : '0' ?>"
                     style="<?= ($wantsTopicDropdown && !empty($topics)) ? '' : 'display:none;' ?>">
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
            </div>
        </div>
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
                <i class="bi bi-inbox" style="font-size: 48px; color: #cbd5e1;"></i>
                <p class="text-muted mb-0 mt-2">No exams found matching your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    const levelSelect = document.getElementById('levelSelect'); // may be null for non-admin roles
    const subjectSelect = document.getElementById('subjectSelect');
    const chapterSelect = document.getElementById('chapterSelect');
    const topicSelect = document.getElementById('topicSelect');
    const examTypeSelect = document.getElementById('examTypeSelect');
    const subjectFilterGroup = document.getElementById('subjectFilterGroup');
    const chapterFilterGroup = document.getElementById('chapterFilterGroup');
    const topicFilterGroup = document.getElementById('topicFilterGroup');
    const hierarchyPanel = document.getElementById('hierarchyPanel');
    const clearBtn = document.getElementById('clearFiltersBtn');
    const searchBox = document.getElementById('searchBox');
    const examGrid = document.getElementById('examGrid');
    const statTotal = document.getElementById('statTotal');
    const statAttempted = document.getElementById('statAttempted');
    const statNotStarted = document.getElementById('statNotStarted');
    const statAvg = document.getElementById('statAvg');

    // Exam Type is a standalone filter, kept separate from Subject/Chapter/Topic. It is the
    // ONLY thing that decides whether the taxonomy dropdowns are relevant at all:
    //   - nothing selected ("All Types") -> no taxonomy dropdowns
    //   - "FULL"    -> no taxonomy dropdowns (a full-syllabus exam has nothing to narrow)
    //   - "SUBJECT" -> Subject only
    //   - "CHAPTER" -> Subject + Chapter
    //   - "TOPIC"   -> Subject + Chapter + Topic
    // On top of that gate, a dropdown still only shows if it actually has real options in
    // it (data-has-options="1"), not just the "All ..." placeholder.
    function hasRealOptions(groupEl) {
        return !!groupEl && groupEl.dataset.hasOptions === '1';
    }

    function applyExamTypeVisibility() {
        if (!examTypeSelect) return;
        const opt = examTypeSelect.options[examTypeSelect.selectedIndex];
        const typeKey = opt ? (opt.dataset.type || '') : '';

        const wantsSubject = ['SUBJECT', 'CHAPTER', 'TOPIC'].includes(typeKey);
        const wantsChapter  = ['CHAPTER', 'TOPIC'].includes(typeKey);
        const wantsTopic    = typeKey === 'TOPIC';

        const showSubject = wantsSubject && hasRealOptions(subjectFilterGroup);
        const showChapter = wantsChapter && hasRealOptions(chapterFilterGroup);
        const showTopic   = wantsTopic && hasRealOptions(topicFilterGroup);

        if (subjectFilterGroup) subjectFilterGroup.style.display = showSubject ? '' : 'none';
        if (chapterFilterGroup) chapterFilterGroup.style.display = showChapter ? '' : 'none';
        if (topicFilterGroup) topicFilterGroup.style.display = showTopic ? '' : 'none';
        if (hierarchyPanel) hierarchyPanel.style.display = (showSubject || showChapter || showTopic) ? '' : 'none';

        // Whenever a dropdown becomes hidden, clear its value too — a filter you can't
        // see shouldn't silently keep narrowing the results.
        if (!showSubject && subjectSelect) subjectSelect.value = '0';
        if (!showChapter && chapterSelect) chapterSelect.value = '0';
        if (!showTopic && topicSelect) topicSelect.value = '0';
    }

    // Recompute whether a freshly-loaded <select> has any real (non-placeholder) options,
    // and stash that on its filter-group's data-has-options attribute.
    function refreshHasOptions(selectEl, groupEl) {
        if (!selectEl || !groupEl) return;
        groupEl.dataset.hasOptions = (selectEl.options.length > 1) ? '1' : '0';
    }

    // Function to fetch filtered results
    function fetchFilteredResults() {
        const searchValue = searchInput ? searchInput.value : '';
        const levelValue = levelSelect ? levelSelect.value : 0;
        const subjectValue = subjectSelect ? subjectSelect.value : 0;
        const chapterValue = chapterSelect ? chapterSelect.value : 0;
        const topicValue = topicSelect ? topicSelect.value : 0;
        const examTypeValue = examTypeSelect ? examTypeSelect.value : 0;

        if (searchBox) searchBox.classList.add('loading');

        let url = window.location.pathname + '?';
        if (levelValue && levelValue != 0) url += `level_id=${levelValue}&`;
        if (examTypeValue && examTypeValue != 0) url += `exam_type_id=${examTypeValue}&`;
        if (subjectValue && subjectValue != 0) url += `subject_id=${subjectValue}&`;
        if (chapterValue && chapterValue != 0) url += `chapter_id=${chapterValue}&`;
        if (topicValue && topicValue != 0) url += `topic_id=${topicValue}&`;
        if (searchValue) url += `search=${encodeURIComponent(searchValue)}&`;

        url = url.replace(/[&?]$/, '');
        if (url === window.location.pathname + '?') {
            url = window.location.pathname;
        }

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
                refreshHasOptions(subjectSelect, subjectFilterGroup);
            }
            if (chapterSelect && newChapterSelect) {
                chapterSelect.innerHTML = newChapterSelect.innerHTML;
                chapterSelect.value = chapterValue || '0';
                chapterSelect.disabled = !(subjectSelect && subjectSelect.value != 0);
                refreshHasOptions(chapterSelect, chapterFilterGroup);
            }
            if (topicSelect && newTopicSelect) {
                topicSelect.innerHTML = newTopicSelect.innerHTML;
                topicSelect.value = topicValue || '0';
                topicSelect.disabled = !(chapterSelect && chapterSelect.value != 0);
                refreshHasOptions(topicSelect, topicFilterGroup);
            }

            // Re-apply visibility now that data-has-options reflects the fresh data.
            applyExamTypeVisibility();

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

    if (examTypeSelect) {
        examTypeSelect.addEventListener('change', function() {
            applyExamTypeVisibility();
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
            if (examTypeSelect) examTypeSelect.value = '0';
            applyExamTypeVisibility();
            fetchFilteredResults();
        });
    }

    // Set initial visibility on page load based on any pre-selected exam type (none, by default)
    applyExamTypeVisibility();
</script>
</body>
</html>