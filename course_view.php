<?php
session_start();
require 'db.php';

$siteLogo = 'logo.png';

/* =============================================================
   LOGIN STATE
   ============================================================= */
$isLoggedIn  = isset($_SESSION['user']);
$userSession = $_SESSION['user'] ?? [];
$userId      = $userSession['user_id'] ?? $userSession['USER_ID'] ?? '';
$userTypeId  = (int)($userSession['user_type_id'] ?? 0);

/* =============================================================
   COURSE LOOKUP
   course_id থেকে level_id + exam_type_id নিয়ে আসে
   ============================================================= */
$courseId   = (int)($_GET['course_id'] ?? 0);
$course     = null;
$levelId    = 0;
$examTypeId = 0;

if ($courseId > 0) {
    try {
        $st = $pdo->prepare("
            SELECT c.*,
                   l.name       AS level_name,
                   et.type_name AS type_name
            FROM courses c
            LEFT JOIN levels     l  ON l.id  = c.level_id
            LEFT JOIN exam_types et ON et.id = c.type_id
            WHERE c.id = ?
              AND (c.DELETE_DATE IS NULL OR c.DELETE_DATE = '0000-00-00 00:00:00')
            LIMIT 1
        ");
        $st->execute([$courseId]);
        $course = $st->fetch(PDO::FETCH_ASSOC);

        if ($course) {
            /* ★ কার্ড থেকেই level_id + exam_type_id ★ */
            $levelId    = (int)($course['level_id'] ?? 0);
            $examTypeId = (int)($course['type_id']  ?? 0);
        }
    } catch (Throwable $e) {
        error_log('course_view lookup failed: ' . $e->getMessage());
    }
}

/* ---------- Course title ---------- */
$courseTitle = $course
    ? ($course['title_bn'] ?: $course['title'])
    : 'সব পরীক্ষা';

/* ---------- Level name ---------- */
$levelName = $course['level_name'] ?? '';
if (empty($levelName) && $levelId > 0) {
    $st = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
    $st->execute([$levelId]);
    $levelName = $st->fetchColumn() ?: '';
}

/* ---------- Type name ---------- */
$typeName = $course['type_name'] ?? '';

/* ---------- Subject (course-এর subject_line থেকে) ---------- */
$subjectText = '';
if ($course && !empty($course['subject_line'])) {
    $subjectText = trim($course['subject_line']);
    $subjectText = preg_replace('/^বিষয়\s*[:：]\s*/u', '', $subjectText);
}

/* ---------- Job Category ---------- */
$jobCategory = 'Job Preparation';
$lower = mb_strtolower($levelName, 'UTF-8');
if (strpos($lower, 'বিসিএস') !== false || stripos($levelName, 'BCS') !== false) {
    $jobCategory = 'BCS Preparation';
} elseif (strpos($lower, 'ব্যাংক') !== false || stripos($levelName, 'Bank') !== false) {
    $jobCategory = 'Bank Job Preparation';
} elseif (strpos($lower, 'শিক্ষক') !== false || stripos($levelName, 'Teacher') !== false) {
    $jobCategory = 'Teacher Recruitment';
}

/* =============================================================
   STRICT EXAM FETCH
   SELECT * FROM exams_info
   WHERE level_id = {course.level_id}
     AND exam_type_id = {course.type_id}
     AND AUTHORIZED_STATUS = 'Y'
   ============================================================= */
$exams = [];

if ($levelId > 0 && $examTypeId > 0) {
    try {
        $st = $pdo->prepare("
            SELECT *
            FROM exams_info
            WHERE AUTHORIZED_STATUS = 'Y'
              AND (DELETE_DATE IS NULL OR DELETE_DATE = '0000-00-00 00:00:00')
              AND level_id     = :lvl
              AND exam_type_id = :etid
            ORDER BY title ASC
        ");
        $st->execute([
            ':lvl'  => $levelId,
            ':etid' => $examTypeId,
        ]);
        $exams = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('course_view fetch failed: ' . $e->getMessage());
        $exams = [];
    }
}

$totalExams = count($exams);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($courseTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($siteLogo) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            font-family:
                -apple-system, BlinkMacSystemFont,
                'Segoe UI', Roboto,
                'Noto Sans Bengali', 'Noto Sans',
                'Kalpurush', 'SolaimanLipi',
                'Nirmala UI', 'Vrinda',
                sans-serif;
            background: #f8faf8;
            color: #10231a;
            line-height: 1.75;
            -webkit-font-smoothing: antialiased;
        }

        body { padding: 24px 16px; }
        .container { max-width: 900px; margin: 0 auto; }

        /* ---------- HEADER ---------- */
        .head {
            background: linear-gradient(135deg, #024a2c 0%, #00693e 100%);
            color: #fff;
            border-radius: 16px;
            padding: 26px 24px;
            margin-bottom: 22px;
            box-shadow: 0 10px 30px -12px rgba(2,74,44,0.35);
        }
        .back {
            display: inline-block;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 12px;
            font-weight: 500;
        }
        .back:hover { color: #fff; }

        .badge-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            align-items: center;
        }
        .job-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,213,74,0.25);
            border: 1px solid rgba(255,213,74,0.4);
            color: #ffd54a;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
        }
        .level-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
        }
        .job-badge i, .type-badge i, .level-badge i { font-size: 12px; }

        .head h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
            line-height: 1.45;
        }
        .head h1 em {
            font-style: normal;
            color: #ffd54a;
            font-size: 16px;
            display: block;
            margin-top: 4px;
            font-weight: 600;
            opacity: 0.95;
        }
        .head .count {
            font-size: 15px;
            color: rgba(255,255,255,0.92);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .head .count b {
            font-size: 22px;
            color: #ffd54a;
            margin: 0 4px;
            font-weight: 700;
        }

        /* ---------- EXAM LIST ---------- */
        .exam-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .exam-item {
            background: #fff;
            border: 1px solid #e1e9e3;
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
        }
        .exam-item:hover {
            border-color: #00693e;
            box-shadow: 0 10px 24px -12px rgba(2,74,44,0.2);
            transform: translateY(-2px);
        }
        .exam-num {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #e4f2e9;
            color: #024a2c;
            font-weight: 700;
            font-size: 15px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .exam-info { flex: 1; min-width: 0; }
        .exam-title {
            font-size: 16px;
            font-weight: 600;
            color: #10231a;
            margin-bottom: 4px;
            line-height: 1.5;
        }
        .exam-remarks {
            font-size: 13px;
            color: #6b7280;
            font-style: italic;
            line-height: 1.6;
            margin: 4px 0 8px;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .exam-remarks i {
            color: #d9a441;
            font-size: 13px;
            margin-top: 3px;
            flex-shrink: 0;
        }
        .exam-meta {
            font-size: 13px;
            color: #5a6c62;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .exam-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .exam-meta i { color: #00693e; font-size: 13px; }

        .exam-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 13.5px;
            text-decoration: none;
            background: linear-gradient(135deg, #00693e, #0a8f55);
            color: #fff;
            white-space: nowrap;
            flex-shrink: 0;
            transition: transform 0.15s, box-shadow 0.2s;
        }
        .exam-btn:hover {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -8px #00693e;
        }
        .exam-btn.locked {
            background: linear-gradient(135deg, #d9a441, #f5c35c);
            color: #7a3f00;
            font-size: 12.5px;
        }
        .exam-btn.locked:hover {
            color: #7a3f00;
            box-shadow: 0 10px 20px -8px rgba(217,164,65,0.5);
        }

        /* ---------- EMPTY ---------- */
        .empty {
            background: #fff;
            border: 2px dashed #e1e9e3;
            border-radius: 16px;
            padding: 60px 20px;
            text-align: center;
        }
        .empty i {
            font-size: 48px;
            color: #cbd5c4;
            display: block;
            margin-bottom: 12px;
        }
        .empty h3 {
            font-size: 18px;
            font-weight: 700;
            color: #10231a;
            margin-bottom: 6px;
        }
        .empty p {
            color: #5a6c62;
            font-size: 14px;
        }
        .empty-diag {
            margin-top: 16px;
            padding: 14px 16px;
            background: #f4f8f5;
            border-radius: 10px;
            font-size: 13px;
            text-align: left;
            color: #5a6c62;
            line-height: 1.8;
        }
        .empty-diag strong { color: #10231a; }
        .empty-diag code {
            background: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12.5px;
            color: #024a2c;
            border: 1px solid #e1e9e3;
        }
        .empty-diag .mini-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            padding: 7px 14px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            background: linear-gradient(135deg, #00693e, #0a8f55);
            color: #fff;
        }
        .empty-diag .mini-btn:hover { color: #fff; }

        /* ---------- CTA ---------- */
        .cta {
            margin-top: 24px;
            background: linear-gradient(135deg, #024a2c, #00693e);
            color: #fff;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
        }
        .cta h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .cta p {
            font-size: 14px;
            color: rgba(255,255,255,0.9);
            margin-bottom: 16px;
        }
        .cta .btns {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .cta a {
            text-decoration: none;
            padding: 11px 24px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.15s;
        }
        .cta .primary {
            background: #fff;
            color: #024a2c;
        }
        .cta .primary:hover {
            transform: translateY(-2px);
            color: #024a2c;
            box-shadow: 0 10px 20px -8px rgba(0,0,0,0.3);
        }
        .cta .ghost {
            background: transparent;
            border: 1.5px solid rgba(255,255,255,0.5);
            color: #fff;
        }
        .cta .ghost:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        @media (max-width: 600px) {
            .exam-item {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .exam-btn {
                justify-content: center;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- =========================================================
         HEADER
         ========================================================= -->
    <div class="head">
        <a href="manage_courses.php" class="back">
            <i class="fa-solid fa-arrow-left"></i> কোর্সে ফিরে যান
        </a>

        <div class="badge-row">
            <span class="job-badge">
                <i class="fa-solid fa-briefcase"></i>
                <?= htmlspecialchars($jobCategory) ?>
            </span>

            <?php if ($typeName): ?>
                <span class="type-badge">
                    <i class="fa-solid fa-tag"></i>
                    <?= htmlspecialchars($typeName) ?>
                </span>
            <?php endif; ?>

            <?php if ($levelName): ?>
                <span class="level-badge">
                    <i class="fa-solid fa-layers"></i>
                    <?= htmlspecialchars($levelName) ?>
                </span>
            <?php endif; ?>
        </div>

        <h1>
            <?= htmlspecialchars($courseTitle) ?>
            <?php if ($subjectText !== ''): ?>
                <em><?= htmlspecialchars($subjectText) ?></em>
            <?php endif; ?>
        </h1>

        <div class="count">
            <i class="fa-solid fa-book-open"></i>
            মোট <b><?= $totalExams ?></b> টি পরীক্ষা আছে
        </div>
    </div>

    <!-- =========================================================
         EXAM LIST
         ========================================================= -->
    <?php if (empty($exams)): ?>

        <div class="empty">
            <i class="fa-solid fa-inbox"></i>
            <h3>কোনো পরীক্ষা পাওয়া যায়নি</h3>
            <p>এই কোর্সে এই মুহূর্তে কোনো পরীক্ষা নেই।</p>

            <?php if ($isLoggedIn && in_array($userTypeId, [1, 2], true)): ?>
                <div class="empty-diag">
                    <strong>অ্যাডমিন ডায়াগনস্টিক:</strong><br>
                    Course ID: <code><?= (int)$courseId ?></code><br>
                    Course Title: <code><?= htmlspecialchars($courseTitle) ?></code><br>
                    Level ID (course থেকে): <code><?= (int)$levelId ?></code><br>
                    Exam Type ID (course থেকে): <code><?= (int)$examTypeId ?></code><br>
                    <br>
                    এই কম্বিনেশনে কোনো authorized পরীক্ষা নেই।<br>
                    <a href="create_exam.php" class="mini-btn">
                        <i class="fa-solid fa-plus"></i> পরীক্ষা যোগ করুন
                    </a>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="exam-list">
            <?php foreach ($exams as $i => $ex): ?>
                <div class="exam-item">
                    <div class="exam-num"><?= $i + 1 ?></div>

                    <div class="exam-info">

                        <!-- Title -->
                        <div class="exam-title">
                            <?= htmlspecialchars($ex['title']) ?>
                        </div>

                        <!-- Remarks (exams_info.remarks) -->
                        <?php if (!empty($ex['remarks'])): ?>
                            <div class="exam-remarks">
                                <i class="fa-solid fa-circle-info"></i>
                                <?= htmlspecialchars($ex['remarks']) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Meta row -->
                        <div class="exam-meta">
                            <?php if (!empty($ex['exam_date'])): ?>
                                <span>
                                    <i class="fa-solid fa-calendar-days"></i>
                                    <?= htmlspecialchars(date('d M Y', strtotime($ex['exam_date']))) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($typeName): ?>
                                <span>
                                    <i class="fa-solid fa-tag"></i>
                                    <?= htmlspecialchars($typeName) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($levelName): ?>
                                <span>
                                    <i class="fa-solid fa-layers"></i>
                                    <?= htmlspecialchars($levelName) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isLoggedIn): ?>
                        <a href="view_exam.php?id=<?= urlencode($ex['exam_id']) ?>" class="exam-btn">
                            শুরু করুন <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    <?php else: ?>
                        <a href="index.php" class="exam-btn locked">
                            <i class="fa-solid fa-lock"></i> ফ্রি রেজিস্ট্রেশন
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <!-- =========================================================
         CTA (guest only)
         ========================================================= -->
    <?php if (!$isLoggedIn && $totalExams > 0): ?>
        <div class="cta">
            <h3>
                <i class="fa-solid fa-gift"></i> Exam দিতে ফ্রি রেজিস্ট্রেশন করুন
            </h3>
            <p>৩০ সেকেন্ডে বিনামূল্যে অ্যাকাউন্ট খুলুন এবং এই <?= $totalExams ?>টি পরীক্ষা শুরু করুন।</p>
            <div class="btns">
                <a href="index.php" class="primary">
                    <i class="fa-solid fa-user-plus"></i> ফ্রি নিবন্ধন
                </a>
                <a href="login.php" class="ghost">
                    <i class="fa-solid fa-right-to-bracket"></i> লগইন
                </a>
            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>