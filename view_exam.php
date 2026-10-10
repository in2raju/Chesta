<?php
session_start();
require 'db.php'; // db.php must initialize $pdo

// 1. Configuration & Helper Functions
$exam_id = isset($_GET['id']) ? preg_replace('/[^A-Za-z0-9\-]/', '', $_GET['id']) : '';

if (empty($exam_id)) {
    die("Invalid Exam ID. Please make sure the URL contains a valid ID, e.g., ?id=EXAM-123");
}

/**
 * Converts a Google Drive "share" URL (e.g. .../file/d/FILE_ID/view)
 * into a direct, hotlinkable image URL. Leaves non-Drive URLs untouched.
 */
function toDirectImageUrl($url) {
    $url = trim((string)$url);
    if ($url === '') return '';

    if (strpos($url, 'drive.google.com') === false) {
        return $url;
    }

    $fileId = null;

    if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        $fileId = $m[1];
    } elseif (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
        $fileId = $m[1];
    }

    if ($fileId) {
        return "https://drive.google.com/thumbnail?id={$fileId}&sz=w1000";
    }

    return $url;
}

function isProbablyImageUrl($str) {
    $str = trim((string)$str);
    if ($str === '') return false;
    if (!preg_match('#^https?://#i', $str)) return false;
    if (stripos($str, 'drive.google.com') !== false) return true;
    if (preg_match('#\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$#i', $str)) return true;
    return false;
}

function getGrade($pct) {
    if ($pct >= 80) return ['label' => 'Excellent', 'color' => '#10b981', 'bg' => '#ecfdf5', 'icon' => 'bi-trophy'];
    if ($pct >= 60) return ['label' => 'Good', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => 'bi-hand-thumbs-up'];
    if ($pct >= 40) return ['label' => 'Average', 'color' => '#f59e0b', 'bg' => '#fffbeb', 'icon' => 'bi-info-circle'];
    return ['label' => 'Keep Trying', 'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'bi-exclamation-triangle'];
}

/**
 * Fetches exam questions with taxonomy info AND explanation image.
 */
function fetchExamQuestionsDirect($pdo, $eid) {
    $sql = "SELECT 
                ex.question_id,
                q.question AS questiontext,
                q.option_a,
                q.option_b,
                q.option_c,
                q.option_d,
                q.image_url_option_a,
                q.image_url_option_b,
                q.image_url_option_c,
                q.image_url_option_d,
                q.correct_option,
                q.explanation,
                q.explanation_image_url,
                q.image_url,
                l.id AS level_id,
                s.id AS subject_id,
                c.id AS chapter_id,
                t.id AS topic_id,
                q.BR_CODE AS br_code,
                q.ORG_CODE AS org_code,
                q.exam_year,
                q.exam_date
            FROM exam_questions ex
            INNER JOIN questions q ON ex.question_id = q.id
            LEFT JOIN topics t ON q.topic_id = t.id
            LEFT JOIN chapters c ON t.chapter_id = c.id
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN levels l ON s.level_id = l.id
            WHERE ex.exam_id = :eid
            ORDER BY ex.id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['eid' => $eid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Handle Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['retry'])) {
        unset($_SESSION['questions'][$exam_id]);
        unset($_SESSION['results'][$exam_id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($exam_id));
        exit;
    }

    if (isset($_POST['submit_exam'])) {
        $questions = isset($_SESSION['questions'][$exam_id]) ? $_SESSION['questions'][$exam_id] : [];
        $user_answers = $_POST['answers'] ?? [];

        if (empty($questions)) {
            $questions = fetchExamQuestionsDirect($pdo, $exam_id);
            $_SESSION['questions'][$exam_id] = $questions; 
        }

        if (empty($questions)) {
            die("Error: This exam (#" . htmlspecialchars($exam_id) . ") returned no questions from the database.");
        }

        $correct = 0; 
        $incorrect = 0; 
        $review_data = [];
        
        foreach ($questions as $q) {
            $qid = (string)$q['question_id'];
            $correct_opt = strtoupper(trim($q['correct_option'] ?? ''));
            $user_opt = isset($user_answers[$qid]) ? strtoupper(trim($user_answers[$qid])) : '';

            if ($user_opt === $correct_opt) {
                $correct++;
            } elseif ($user_opt !== '') {
                $incorrect++;
            }

            $review_data[] = [
                'question'              => $q['questiontext'] ?? '',
                'image_url'             => toDirectImageUrl($q['image_url'] ?? ''),
                'options' => [
                    'A' => $q['option_a'] ?? '', 
                    'B' => $q['option_b'] ?? '', 
                    'C' => $q['option_c'] ?? '', 
                    'D' => $q['option_d'] ?? ''
                ],
                'option_images' => [
                    'A' => toDirectImageUrl($q['image_url_option_a'] ?? ''),
                    'B' => toDirectImageUrl($q['image_url_option_b'] ?? ''),
                    'C' => toDirectImageUrl($q['image_url_option_c'] ?? ''),
                    'D' => toDirectImageUrl($q['image_url_option_d'] ?? ''),
                ],
                'correct_option'        => $correct_opt, 
                'given_answer'          => $user_opt, 
                'explanation'           => $q['explanation'] ?? '',
                'explanation_image_url' => toDirectImageUrl($q['explanation_image_url'] ?? '')
            ];
        }

        $total = (int)count($questions);
        $correct = (int)$correct;
        $incorrect = (int)$incorrect;
        $unanswered = (int)($total - ($correct + $incorrect));
        $score_pct = $total > 0 ? (float)round(($correct / $total) * 100, 2) : 0.00;
        
        $grade_info = getGrade($score_pct);
        $gradeLabel = $grade_info['label'];

        $studentId = $_SESSION['user']['user_id'] ?? $_SESSION['user_id'] ?? null;
        if (empty($studentId)) {
            $studentId = substr('GUEST-' . session_id(), 0, 200); 
        }
        
        $orgCode = $_SESSION['user']['org_code'] ?? $_SESSION['org_code'] ?? null;
        $brCode  = $_SESSION['user']['br_code'] ?? $_SESSION['br_code'] ?? null;

        $firstQuestion = !empty($questions) ? $questions[0] : [];
        $levelName     = !empty($firstQuestion['level_id'])   ? substr($firstQuestion['level_id'], 0, 200)   : null;
        $subjectName   = !empty($firstQuestion['subject_id']) ? substr($firstQuestion['subject_id'], 0, 200) : null;
        $chapterName   = !empty($firstQuestion['chapter_id']) ? substr($firstQuestion['chapter_id'], 0, 200) : null;
        $topicName     = !empty($firstQuestion['topic_id'])   ? substr($firstQuestion['topic_id'], 0, 200)   : null;

        if (empty($orgCode)) { $orgCode = !empty($firstQuestion['org_code']) ? substr($firstQuestion['org_code'], 0, 20) : null; }
        if (empty($brCode))  { $brCode  = !empty($firstQuestion['br_code'])  ? substr($firstQuestion['br_code'], 0, 20)  : null; }

        $timeStarted   = $_POST['time_started'] ?? date('Y-m-d H:i:s'); 
        $timeSubmitted = date('Y-m-d H:i:s');
        $durationSec   = isset($_POST['duration_seconds']) ? (int)$_POST['duration_seconds'] : 0;
        $timeLimitMin  = isset($firstQuestion['time_limit_min']) ? (int)$firstQuestion['time_limit_min'] : null;

        try {
            $sqlInsert = "INSERT INTO exam_results (
                exam_id, user_id, org_code, br_code, total_questions, correct_ans, 
                wrong_ans, not_answered, score_pct, grade, time_started, 
                time_submitted, duration_seconds, time_limit_min, mode, level_name, subject_name, chapter_name, topic_name
            ) VALUES (
                :exam_id, :user_id, :org_code, :br_code, :total_questions, :correct_ans, 
                :wrong_ans, :not_answered, :score_pct, :grade, :time_started, 
                :time_submitted, :duration_seconds, :time_limit_min, 'practice', :level_name, :subject_name, :chapter_name, :topic_name
            )";

            $stmtInsert = $pdo->prepare($sqlInsert);
            $executionStatus = $stmtInsert->execute([
                'exam_id'          => substr($exam_id, 0, 32),
                'user_id'          => $studentId,
                'org_code'         => $orgCode,
                'br_code'          => $brCode,
                'total_questions'  => $total,
                'correct_ans'      => $correct,
                'wrong_ans'        => $incorrect,
                'not_answered'     => $unanswered,
                'score_pct'        => $score_pct,
                'grade'            => substr($gradeLabel, 0, 30),
                'time_started'     => $timeStarted,
                'time_submitted'   => $timeSubmitted,
                'duration_seconds' => $durationSec,
                'time_limit_min'   => $timeLimitMin,
                'level_name'       => $levelName,
                'subject_name'     => $subjectName,
                'chapter_name'     => $chapterName,
                'topic_name'       => $topicName
            ]);

            if (!$executionStatus) {
                echo "<h3>PDO Statement execution returned false without throwing Exception.</h3>";
                print_r($stmtInsert->errorInfo());
                die();
            }

        } catch (PDOException $e) {
            echo "<h2>❌ Database Insertion Failed!</h2>";
            echo "<p><strong>Error Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>SQL State Error Code:</strong> " . htmlspecialchars($e->getCode()) . "</p>";
            die(); 
        }
        
        $_SESSION['results'][$exam_id] = [
            'total'      => $total, 
            'correct'    => $correct, 
            'incorrect'  => $incorrect,
            'unanswered' => $unanswered, 
            'pct'        => $score_pct,
            'review'     => $review_data, 
            'grade'      => $grade_info
        ];
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($exam_id));
        exit;
    }
}

// 3. Fetch Questions if not in session for initial render
$results = $_SESSION['results'][$exam_id] ?? null;
$questions = [];

if (!$results) {
    if (!isset($_SESSION['questions'][$exam_id]) || empty($_SESSION['questions'][$exam_id])) {
        $_SESSION['questions'][$exam_id] = fetchExamQuestionsDirect($pdo, $exam_id);
    }
    $questions = $_SESSION['questions'][$exam_id] ?? [];
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam System — #<?= htmlspecialchars($exam_id) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5; --primary-dark: #4338ca;
            --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
            --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-200: #e2e8f0;
            --slate-700: #334155; --slate-900: #0f172a;
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        body { font-family: 'Inter', 'Hind Siliguri', sans-serif; background-color: var(--slate-50); color: var(--slate-900); margin: 0; padding-bottom: 100px; }
        
        .sticky-bar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--slate-200);
            padding: 0.75rem 1rem;
        }

        .exam-container {
            max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem;
            display: grid; grid-template-columns: 1fr 300px; gap: 2rem;
        }

        @media (max-width: 992px) {
            .exam-container { grid-template-columns: 1fr; }
            .sidebar { order: 2; } 
            .main-content { order: 1; }
            .sidebar-card { position: relative !important; top: 0 !important; }
        }

        .q-card { background: white; border: 1px solid var(--slate-200); border-radius: 1rem; margin-bottom: 1.5rem; overflow: hidden; }
        .q-header { padding: 1.25rem; background: white; border-bottom: 1px solid var(--slate-100); display: flex; gap: 1rem; font-weight: 600; }
        .q-num { background: var(--slate-100); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
        .q-image-wrap { padding: 0 1.25rem 1.25rem; }
        .q-image-wrap img { max-width: 100%; height: auto; border-radius: 0.75rem; border: 1px solid var(--slate-200); display: block; }

        .options-grid { padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem; }
        .option-item { border: 1px solid var(--slate-200); padding: 1rem; border-radius: 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 1rem; transition: 0.2s; }
        .option-item input[type="radio"] { cursor: pointer; width: 18px; height: 18px; flex-shrink: 0; }
        .option-item:hover { border-color: var(--primary); background: var(--slate-50); }
        .option-content { display: flex; align-items: center; gap: 0.75rem; width: 100%; flex-wrap: wrap; }
        .option-img { max-width: 160px; max-height: 90px; width: auto; height: auto; object-fit: contain; border-radius: 0.4rem; border: 1px solid var(--slate-200); display: block; flex-shrink: 0; }
        @media (max-width: 480px) { .option-img { max-width: 110px; max-height: 70px; } }
        .option-img-error { font-size: 0.8rem; color: var(--warning); background: #fffbeb; border: 1px dashed var(--warning); border-radius: 0.5rem; padding: 0.5rem; }
        .option-img-error a { color: var(--primary); }
        
        .sidebar-card {
            position: sticky; top: 100px;
            background: white; border: 1px solid var(--slate-200);
            border-radius: 1rem; padding: 1.25rem;
        }

        .nav-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.5rem; }
        .nav-grid-wrapper { max-height: 400px; overflow-y: auto; padding-right: 4px; }
        .nav-btn {
            width: 100%; aspect-ratio: 1; border: 1px solid var(--slate-200);
            border-radius: 0.5rem; background: white; cursor: pointer;
            font-weight: 600; font-size: 0.85rem; transition: 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        
        .btn { padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--slate-700); color: white; }
        .btn-success { background: var(--success); color: white; }
        
        .mobile-nav-trigger { display: none; background: var(--slate-100); border: none; padding: 0.5rem; border-radius: 0.5rem; cursor: pointer; }
        @media (max-width: 992px) { .mobile-nav-trigger { display: block; } }

        .text-success { color: var(--success) !important; }
        .text-danger { color: var(--danger) !important; }
        .text-warning { color: var(--warning) !important; }
        .fw-bold { font-weight: bold; }
        .card { background: white; border-radius: 1rem; border: 1px solid var(--slate-200); }
        .shadow { box-shadow: var(--shadow-md); }
        .alert-info { background: #e0f2fe; color: #0369a1; padding: 1rem; border-radius: 0.5rem; margin-top: 10px; }
        .review-img { max-width: 100%; height: auto; border-radius: 0.75rem; border: 1px solid var(--slate-200); margin-bottom: 1rem; display: block; }
        .review-option-img { max-width: 160px; max-height: 90px; width: auto; height: auto; object-fit: contain; border-radius: 0.4rem; border: 1px solid var(--slate-200); display: block; margin-top: 0.5rem; }
        @media (max-width: 480px) { .review-option-img { max-width: 110px; max-height: 70px; } }

        /* Explanation block — supports text and image */
        .explanation-box {
            background: #fffbeb;
            border-left: 4px solid var(--warning);
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
        .explanation-box .expl-title {
            font-weight: 700;
            color: #78350f;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .explanation-box .expl-text {
            color: #78350f;
            font-size: 0.95rem;
            line-height: 1.8;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .explanation-box .expl-text:not(:last-child) {
            margin-bottom: 12px;
        }
        .explanation-box .expl-img {
            max-width: 100%;
            height: auto;
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
            display: block;
            background: #fff;
        }
    </style>
</head>

<body class="d-flex flex-column min-vh-100">
    <?php include 'header.php'; ?>

    <main class="flex-grow-1">
    <?php if ($results): ?>
        <div class="container my-4" style="max-width: 900px;">
            <div class="text-center p-4 rounded mb-4" style="background: <?= $results['grade']['color'] ?>; color:white;">
                <h1><i class="bi <?= $results['grade']['icon'] ?>"></i> <?= htmlspecialchars($results['grade']['label']) ?></h1>
                <h2>Score: <?= $results['pct'] ?>%</h2>
            </div>

            <div class="card shadow mb-5">
                <div class="card-header bg-dark text-white text-center py-3">
                    <h3 class="m-0">পরীক্ষার ফলাফল (Exam Review)</h3>
                </div>

                <div class="card-body p-4">
                    <div class="row text-center mb-4 g-2 fs-5">
                        <div class="col-6 col-md-3"><strong>মোট:</strong> <?= $results['total'] ?></div>
                        <div class="col-6 col-md-3 text-success"><strong>সঠিক:</strong> <?= $results['correct'] ?></div>
                        <div class="col-6 col-md-3 text-danger"><strong>ভুল:</strong> <?= $results['incorrect'] ?></div>
                        <div class="col-6 col-md-3 text-warning"><strong>বাকি:</strong> <?= $results['unanswered'] ?></div>
                    </div>

                    <hr>

                    <?php foreach ($results['review'] as $i => $res): ?>
                        <?php $qnum = $i + 1; ?>
                        <div class="mb-4 border-bottom pb-3">
                            <h5 class="mb-3"><?= $qnum . ". " . htmlspecialchars($res['question']) ?></h5>

                            <?php if (!empty($res['image_url'])): ?>
                                <img src="<?= htmlspecialchars(toDirectImageUrl($res['image_url'])) ?>" alt="Question <?= $qnum ?> image" class="review-img">
                            <?php endif; ?>

                            <?php foreach ($res['options'] as $key => $opt):
                                if (empty($opt)) continue;

                                $optImg = $res['option_images'][$key] ?? '';

                                $class = "";
                                $icon = "";

                                if ($res['given_answer'] === $key) {
                                    if ($key === $res['correct_option']) {
                                        $class = "text-success fw-bold border-success";
                                        $icon = " <i class='bi bi-check-circle-fill'></i>";
                                    } else {
                                        $class = "text-danger fw-bold border-danger";
                                        $icon = " <i class='bi bi-x-circle-fill'></i>";
                                    }
                                } elseif ($key === $res['correct_option']) {
                                    $class = "text-success fw-bold";
                                }
                            ?>
                                <div class="mb-2 p-2 border rounded <?= $class ?>" style="background: <?= $class ? '#f8fafc' : 'transparent' ?>">
                                    <p class="mb-0"><?= $key . ") " . htmlspecialchars($opt) . $icon ?></p>
                                    <?php if (!empty($optImg)): ?>
                                        <img src="<?= htmlspecialchars($optImg) ?>" alt="Option <?= $key ?> image" class="review-option-img">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php
                            /* ---------- EXPLANATION: Text + Image ---------- */
                            $hasExplText  = !empty($res['explanation']) && trim((string)$res['explanation']) !== '';
                            $hasExplImage = !empty($res['explanation_image_url']) && trim((string)$res['explanation_image_url']) !== '';
                            ?>
                            <?php if ($hasExplText || $hasExplImage): ?>
                                <div class="explanation-box">
                                    <div class="expl-title">
                                        <i class="bi bi-lightbulb-fill"></i> ব্যাখ্যা (Explanation)
                                    </div>

                                    <?php if ($hasExplText): ?>
                                        <div class="expl-text"><?= htmlspecialchars($res['explanation']) ?></div>
                                    <?php endif; ?>

                                    <?php if ($hasExplImage): ?>
                                        <img src="<?= htmlspecialchars(toDirectImageUrl($res['explanation_image_url'])) ?>"
                                             alt="Explanation image for Q<?= $qnum ?>"
                                             class="expl-img"
                                             loading="lazy"
                                             onerror="this.onerror=null; this.replaceWith(Object.assign(document.createElement('div'), {className:'expl-text', style:'color:#b45309;font-size:.85rem;', innerHTML:'⚠️ Explanation image failed to load.'}));">
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php /* ---------- END EXPLANATION ---------- */ ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="text-center mt-4 gap-2 d-flex justify-content-center">
                        <form method="post">
                            <button type="submit" name="retry" class="btn btn-primary">🔄 আবার দিন (Retry)</button>
                        </form>
                        <a href="exam_list.php" class="btn btn-secondary">ফিরুন (Back)</a>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="sticky-bar">
            <div style="max-width: 1100px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" class="mobile-nav-trigger" onclick="document.getElementById('question-nav').scrollIntoView({behavior:'smooth'})" title="Jump to Grid">
                        <i class="bi bi-grid-3x3-gap-fill" style="font-size: 1.2rem; color: var(--primary);"></i>
                    </button>
                    <div>
                        <h4 style="margin:0; font-size: 1rem;">Exam #<?= htmlspecialchars($exam_id) ?></h4>
                        <small id="progressText" style="color: #64748b;">0 / <?= count($questions) ?> Answered</small>
                    </div>
                </div>
                <div style="background: var(--slate-900); color: white; padding: 0.4rem 0.8rem; border-radius: 2rem; font-weight: 700; font-size: 0.9rem;">
                    <i class="bi bi-clock"></i> <span id="timer">00:00</span>
                </div>
            </div>
        </div>

        <form method="post" id="examForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . urlencode($exam_id) ?>">
            
            <input type="hidden" name="time_started" value="<?= date('Y-m-d H:i:s') ?>">
            <input type="hidden" name="duration_seconds" id="durationSecondsInput" value="0">

            <div class="exam-container">
                <div class="main-content">
                    <?php if (empty($questions)): ?>
                        <div class="alert alert-warning p-4 rounded text-center">
                            <h5>কোন প্রশ্ন খুঁজে পাওয়া যায়নি! (No questions available for this exam)</h5>
                            <a href="exam_list.php" class="btn btn-secondary mt-2">ফিরে যান</a>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($questions as $i => $q): $qnum = $i + 1; ?>
                        <div class="q-card" id="q-<?= $qnum ?>">
                            <div class="q-header">
                                <div class="q-num"><?= $qnum ?></div>
                                <div><?= htmlspecialchars($q['questiontext']) ?></div>
                            </div>
                            <?php if (!empty($q['image_url'])): ?>
                                <div class="q-image-wrap">
                                    <img src="<?= htmlspecialchars(toDirectImageUrl($q['image_url'])) ?>" alt="Question <?= $qnum ?> image" loading="lazy">
                                </div>
                            <?php endif; ?>
                            <div class="options-grid">
                                <?php foreach (['A', 'B', 'C', 'D'] as $opt): 
                                    $col = 'option_' . strtolower($opt);
                                    $imgCol = 'image_url_option_' . strtolower($opt);
                                    if (empty($q[$col])) continue;
                                    $optImgUrl = !empty($q[$imgCol]) ? toDirectImageUrl($q[$imgCol]) : '';
                                ?>
                                    <label class="option-item">
                                        <input type="radio"
                                               name="answers[<?= htmlspecialchars($q['question_id']) ?>]"
                                               value="<?= $opt ?>"
                                               data-qnum="<?= $qnum ?>"
                                               data-qid="<?= htmlspecialchars($q['question_id']) ?>"
                                               onclick="handleOptionClick(event)">
                                        <span class="option-content">
                                            <span><strong><?= $opt ?>.</strong> <?= htmlspecialchars($q[$col]) ?></span>
                                            <?php if (!empty($optImgUrl)): ?>
                                                <img src="<?= htmlspecialchars($optImgUrl) ?>"
                                                     alt="Option <?= $opt ?> image"
                                                     class="option-img"
                                                     loading="lazy"
                                                     onerror="this.onerror=null; this.replaceWith(Object.assign(document.createElement('div'), {className:'option-img-error', innerHTML:'⚠️ Image failed to load — check Drive sharing is set to \'Anyone with the link\'. <a href=\'<?= htmlspecialchars($optImgUrl) ?>\' target=\'_blank\' rel=\'noopener\'>Open link</a>'}));">
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($questions)): ?>
                        <div style="text-align: center; padding: 2rem 0;">
                            <button type="submit" name="submit_exam" class="btn btn-success" style="width: 100%; justify-content: center; max-width: 400px; font-size: 1.1rem;" onclick="return confirm('Are you sure you want to submit your answers?')">
                                <i class="bi bi-send-check"></i> Submit Examination
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="sidebar" id="question-nav">
                    <div class="sidebar-card">
                        <h5 style="margin-top:0; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-grid-3x3-gap"></i> Jump to Question
                        </h5>
                        <div class="nav-grid-wrapper">
                            <div class="nav-grid">
                                <?php for ($i=1; $i<=count($questions); $i++): ?>
                                    <button type="button" class="nav-btn" id="nav-<?= $i ?>" onclick="scrollToQuestion(<?= $i ?>)">
                                        <?= $i ?>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </form>

        <script>
            const answered = new Set();
            const selectedByQid = {};

            function updateProgress(qnum) {
                answered.add(qnum);
                document.getElementById('progressText').innerText = answered.size + ' / <?= count($questions) ?> Answered';
                const btn = document.getElementById('nav-' + qnum);
                if(btn) {
                    btn.style.background = 'var(--primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--primary)';
                }
            }

            function clearProgress(qnum) {
                answered.delete(qnum);
                document.getElementById('progressText').innerText = answered.size + ' / <?= count($questions) ?> Answered';
                const btn = document.getElementById('nav-' + qnum);
                if(btn) {
                    btn.style.background = '';
                    btn.style.color = '';
                    btn.style.borderColor = '';
                }
            }

            function handleOptionClick(e) {
                const radio = e.target;
                const qid = radio.dataset.qid;
                const qnum = radio.dataset.qnum;
                const value = radio.value;

                if (selectedByQid[qid] === value) {
                    radio.checked = false;
                    delete selectedByQid[qid];
                    clearProgress(qnum);
                } else {
                    selectedByQid[qid] = value;
                    updateProgress(qnum);
                }
            }

            function scrollToQuestion(num) {
                const el = document.getElementById('q-' + num);
                if(!el) return;
                const offset = 100; 
                const elementPosition = el.getBoundingClientRect().top + window.pageYOffset;
                const offsetPosition = elementPosition - offset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }

            let sec = 0;
            setInterval(() => {
                sec++;
                const durationInput = document.getElementById('durationSecondsInput');
                if(durationInput) durationInput.value = sec;

                let m = Math.floor(sec / 60).toString().padStart(2, '0');
                let s = (sec % 60).toString().padStart(2, '0');
                const timerEl = document.getElementById('timer');
                if(timerEl) timerEl.innerText = m + ':' + s;
            }, 1000);
        </script>
    <?php endif; ?>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>