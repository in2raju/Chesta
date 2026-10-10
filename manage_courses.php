<?php
session_start();
require 'db.php';

/* =============================================================
   AUTH
   ============================================================= */
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userSession = $_SESSION['user'];
$userType    = (int)($userSession['user_type_id'] ?? 0);
$userOrg     = $userSession['org_code'] ?? null;
$userBr      = $userSession['br_code']  ?? null;
$userId      = $userSession['user_id'] ?? $userSession['USER_ID'] ?? 'admin';

$isAdmin = in_array($userType, [1, 2], true);
if (!$isAdmin) {
    header("Location: deshboard.php");
    exit();
}

$canInsert  = (int)($userSession['can_insert']  ?? 1);
$canEdit    = (int)($userSession['can_edit']    ?? 1);
$canDelete  = (int)($userSession['can_delete']  ?? 1);
$canApprove = (int)($userSession['can_approve'] ?? 1);

/* =============================================================
   DROPDOWNS
   ============================================================= */
$levelsStmt = $pdo->prepare("
    SELECT id, name FROM levels
    WHERE (BR_CODE = ? OR BR_CODE IS NULL)
      AND (ORG_CODE = ? OR ORG_CODE IS NULL)
      AND (AUTHORIZED_STATUS = 'Y' OR AUTHORIZED_STATUS IS NULL)
      AND DELETE_DATE IS NULL
    ORDER BY name ASC
");
$levelsStmt->execute([$userBr, $userOrg]);
$levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

$types = [];
try {
    $types = $pdo->query("
        SELECT id, type_name AS name FROM exam_types
        WHERE status = 'Y' OR status IS NULL
        ORDER BY type_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($types)) {
        $types = $pdo->query("
            SELECT id, type_name AS name FROM exam_types
            ORDER BY type_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('exam_types fetch failed: ' . $e->getMessage());
}

/* =============================================================
   EDIT PRELOAD
   ============================================================= */
$editId     = (int)($_GET['edit'] ?? 0);
$editCourse = null;

if ($editId > 0) {
    $st = $pdo->prepare("
        SELECT * FROM courses
        WHERE id = ? AND DELETE_DATE IS NULL LIMIT 1
    ");
    $st->execute([$editId]);
    $editCourse = $st->fetch(PDO::FETCH_ASSOC);
}

/* =============================================================
   INPUT STATE
   ============================================================= */
$errors = [];
$old = [
    'course_code'       => '',
    'slug'              => '',
    'title'             => '',
    'title_bn'          => '',
    'subtitle'          => '',
    'subject_line'      => '',
    'short_desc'        => '',
    'description'       => '',
    'instructor_name'   => '',
    'tag'               => '',
    'discount_pct'      => '',
    'rating'            => 5,
    'meta_text'         => '',
    'tone_gradient'     => 'linear-gradient(135deg,#7a1122 0%,#a31528 100%)',
    'display_order'     => 0,
    'is_featured'       => 0,
    'level_id'          => '',
    'type_id'           => '',
    'price'             => '0',
    'discount_price'    => '',
    'total_lessons'     => 0,
    'duration_hours'    => 0,
    'is_active'         => 1,
    'AUTHORIZED_STATUS' => 'N',
    '_thumbnail'        => null,
];

if ($editCourse && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($old as $k => $v) {
        if (array_key_exists($k, $editCourse)) $old[$k] = $editCourse[$k];
    }
    $old['_thumbnail']      = $editCourse['thumbnail']      ?? null;
    $old['is_featured']     = (int)($editCourse['is_featured']    ?? 0);
    $old['is_active']       = (int)($editCourse['is_active']      ?? 1);
    $old['rating']          = (int)($editCourse['rating']         ?? 5);
    $old['display_order']   = (int)($editCourse['display_order']  ?? 0);
    $old['total_lessons']   = (int)($editCourse['total_lessons']  ?? 0);
    $old['duration_hours']  = (int)($editCourse['duration_hours'] ?? 0);
    $old['level_id']        = (int)($editCourse['level_id']       ?? 0);
    $old['type_id']         = (int)($editCourse['type_id']        ?? 0);
}

/* =============================================================
   POST — SAVE / UPDATE / DELETE / APPROVE
   ============================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---------- DELETE ---------- */
    if ($action === 'delete') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId > 0 && $canDelete) {
            $pdo->prepare("
                UPDATE courses
                SET DELETE_DATE = NOW(), DELETE_USER = ?
                WHERE id = ? AND IFNULL(AUTHORIZED_STATUS, 'N') != 'Y'
            ")->execute([$userId, $courseId]);
            $_SESSION['flash_success'] = 'কোর্স মুছে ফেলা হয়েছে।';
        }
        header("Location: manage_courses.php");
        exit();
    }

    /* ---------- APPROVE ---------- */
    if ($action === 'approve') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId > 0 && $canApprove) {
            $pdo->prepare("
                UPDATE courses
                SET AUTHORIZED_STATUS = 'Y',
                    AUTHORIZED_USER   = ?,
                    AUTHORIZED_DATE   = CURDATE()
                WHERE id = ?
            ")->execute([$userId, $courseId]);
            $_SESSION['flash_success'] = 'কোর্স অনুমোদিত হয়েছে।';
        }
        header("Location: manage_courses.php");
        exit();
    }

    /* ---------- SAVE / UPDATE ---------- */
    if (in_array($action, ['save', 'update'], true)) {

        $old['course_code']       = trim($_POST['course_code'] ?? '');
        $old['slug']              = trim($_POST['slug'] ?? '');
        $old['title']             = trim($_POST['title'] ?? '');
        $old['title_bn']          = trim($_POST['title_bn'] ?? '');
        $old['subtitle']          = trim($_POST['subtitle'] ?? '');
        $old['subject_line']      = trim($_POST['subject_line'] ?? '');
        $old['short_desc']        = trim($_POST['short_desc'] ?? '');
        $old['description']       = trim($_POST['description'] ?? '');
        $old['instructor_name']   = trim($_POST['instructor_name'] ?? '');
        $old['tag']               = trim($_POST['tag'] ?? '');
        $old['discount_pct']      = trim($_POST['discount_pct'] ?? '');
        $old['rating']            = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $old['meta_text']         = trim($_POST['meta_text'] ?? '');
        $old['tone_gradient']     = trim($_POST['tone_gradient'] ?? '');
        $old['display_order']     = (int)($_POST['display_order'] ?? 0);
        $old['is_featured']       = isset($_POST['is_featured']) ? 1 : 0;
        $old['level_id']          = (int)($_POST['level_id'] ?? 0);
        $old['type_id']           = (int)($_POST['type_id'] ?? 0);
        $old['price']             = trim($_POST['price'] ?? '0');
        $old['discount_price']    = trim($_POST['discount_price'] ?? '');
        $old['total_lessons']     = (int)($_POST['total_lessons'] ?? 0);
        $old['duration_hours']    = (int)($_POST['duration_hours'] ?? 0);
        $old['is_active']         = isset($_POST['is_active']) ? 1 : 0;
        $old['AUTHORIZED_STATUS'] = ($_POST['AUTHORIZED_STATUS'] ?? 'N') === 'Y' ? 'Y' : 'N';

        if ($old['course_code'] === '') $errors[] = 'Course Code দিতে হবে।';
        if ($old['title'] === '')       $errors[] = 'Title (English) দিতে হবে।';
        if ($old['level_id'] <= 0)      $errors[] = 'Level নির্বাচন করুন।';
        if ($old['type_id']  <= 0)      $errors[] = 'Course Type নির্বাচন করুন।';
        if (!is_numeric($old['price'])) $errors[] = 'Price সংখ্যা হতে হবে।';

        if ($old['slug'] === '' && $old['title'] !== '') {
            $old['slug'] = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $old['title']), '-'));
        }

        if (empty($errors)) {
            $chk = $pdo->prepare("SELECT id FROM courses WHERE course_code = ? AND id != ? LIMIT 1");
            $chk->execute([$old['course_code'], $editId]);
            if ($chk->fetchColumn()) $errors[] = 'এই Course Code ইতিমধ্যে ব্যবহৃত।';
        }

        /* Thumbnail */
        $thumbnailPath = $old['_thumbnail'];
        if (empty($errors) && !empty($_FILES['thumbnail']['name'])) {
            $allowed = ['jpg','jpeg','png','webp','gif'];
            $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed)) {
                $errors[] = 'Thumbnail: শুধু JPG, PNG, WEBP, GIF।';
            } elseif ($_FILES['thumbnail']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Thumbnail: ২ MB-এর বেশি হতে পারবে না।';
            } else {
                $uploadDir = __DIR__ . '/uploads/courses/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newName = 'course_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . $newName)) {
                    $thumbnailPath = 'uploads/courses/' . $newName;
                } else {
                    $errors[] = 'Thumbnail সংরক্ষণ করা যায়নি।';
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($editCourse) {
                    $pdo->prepare("
                        UPDATE courses SET
                            course_code = ?, slug = ?, title = ?, title_bn = ?,
                            subtitle = ?, subject_line = ?, short_desc = ?, description = ?,
                            thumbnail = ?, instructor_name = ?, tag = ?, discount_pct = ?,
                            rating = ?, meta_text = ?, tone_gradient = ?,
                            display_order = ?, is_featured = ?,
                            level_id = ?, type_id = ?,
                            price = ?, discount_price = ?,
                            total_lessons = ?, duration_hours = ?,
                            is_active = ?, AUTHORIZED_STATUS = ?,
                            EDIT_DATE = CURDATE(), EDIT_USER = ?
                        WHERE id = ?
                    ")->execute([
                        $old['course_code'], $old['slug'] ?: null,
                        $old['title'], $old['title_bn'] ?: null,
                        $old['subtitle'] ?: null, $old['subject_line'] ?: null,
                        $old['short_desc'] ?: null, $old['description'] ?: null,
                        $thumbnailPath, $old['instructor_name'] ?: null,
                        $old['tag'] ?: null, $old['discount_pct'] ?: null,
                        $old['rating'], $old['meta_text'] ?: null, $old['tone_gradient'] ?: null,
                        $old['display_order'], $old['is_featured'],
                        $old['level_id'], $old['type_id'],
                        (float)$old['price'],
                        $old['discount_price'] !== '' ? (float)$old['discount_price'] : null,
                        $old['total_lessons'], $old['duration_hours'],
                        $old['is_active'], $old['AUTHORIZED_STATUS'],
                        $userId, (int)$editCourse['id'],
                    ]);
                    $_SESSION['flash_success'] = 'কোর্স সফলভাবে আপডেট হয়েছে।';
                } else {
                    $pdo->prepare("
                        INSERT INTO courses (
                            course_code, slug, title, title_bn, subtitle, subject_line,
                            short_desc, description, thumbnail, instructor_name,
                            tag, discount_pct, rating, meta_text, tone_gradient,
                            display_order, is_featured, level_id, type_id,
                            price, discount_price, total_lessons, duration_hours,
                            is_active, BR_CODE, ORG_CODE,
                            AUTHORIZED_STATUS, AUTHORIZED_USER, AUTHORIZED_DATE,
                            ENTRY_DATE, ENTRY_USER
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            NOW(), ?
                        )
                    ")->execute([
                        $old['course_code'], $old['slug'] ?: null,
                        $old['title'], $old['title_bn'] ?: null,
                        $old['subtitle'] ?: null, $old['subject_line'] ?: null,
                        $old['short_desc'] ?: null, $old['description'] ?: null,
                        $thumbnailPath, $old['instructor_name'] ?: null,
                        $old['tag'] ?: null, $old['discount_pct'] ?: null,
                        $old['rating'], $old['meta_text'] ?: null, $old['tone_gradient'] ?: null,
                        $old['display_order'], $old['is_featured'],
                        $old['level_id'], $old['type_id'],
                        (float)$old['price'],
                        $old['discount_price'] !== '' ? (float)$old['discount_price'] : null,
                        $old['total_lessons'], $old['duration_hours'],
                        $old['is_active'], $userBr, $userOrg,
                        $old['AUTHORIZED_STATUS'],
                        $old['AUTHORIZED_STATUS'] === 'Y' ? $userId : null,
                        $old['AUTHORIZED_STATUS'] === 'Y' ? date('Y-m-d') : null,
                        $userId,
                    ]);
                    $_SESSION['flash_success'] = 'নতুন কোর্স সফলভাবে যোগ হয়েছে।';
                }

                header("Location: manage_courses.php");
                exit();

            } catch (Throwable $e) {
                error_log('course save failed: ' . $e->getMessage());
                $errors[] = 'ডেটাবেস ত্রুটি: ' . $e->getMessage();
            }
        }
    }
}

/* =============================================================
   FILTERS + FETCH
   ============================================================= */
$selectedLevel = (int)($_GET['level_id'] ?? 0);
$selectedType  = (int)($_GET['type_id']  ?? 0);
$searchKeyword = trim($_GET['search'] ?? '');

$sql = "
    SELECT c.*, l.name AS level_name, et.type_name AS type_name
    FROM courses c
    LEFT JOIN levels     l  ON l.id  = c.level_id
    LEFT JOIN exam_types et ON et.id = c.type_id
    WHERE c.DELETE_DATE IS NULL
      AND (c.BR_CODE = :br OR c.BR_CODE IS NULL OR c.BR_CODE = '')
      AND (c.ORG_CODE = :org OR c.ORG_CODE IS NULL OR c.ORG_CODE = '')
";
$params = [':br' => $userBr, ':org' => $userOrg];
$where = [];

if ($selectedLevel > 0) {
    $where[] = "c.level_id = :lid";
    $params[':lid'] = $selectedLevel;
}
if ($selectedType > 0) {
    $where[] = "c.type_id = :tid";
    $params[':tid'] = $selectedType;
}
if ($searchKeyword !== '') {
    $where[] = "(c.title LIKE :s1 OR c.title_bn LIKE :s2
                 OR c.course_code LIKE :s3 OR c.instructor_name LIKE :s4
                 OR c.tag LIKE :s5)";
    $kw = "%{$searchKeyword}%";
    $params[':s1'] = $kw; $params[':s2'] = $kw;
    $params[':s3'] = $kw; $params[':s4'] = $kw; $params[':s5'] = $kw;
}
if ($where) $sql .= " AND " . implode(" AND ", $where);
$sql .= " ORDER BY c.display_order ASC, c.is_featured DESC, c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCourses  = count($courses);
$approvedCount = 0; $pendingCount = 0; $freeCount = 0;
foreach ($courses as $c) {
    if (($c['AUTHORIZED_STATUS'] ?? 'N') === 'Y') $approvedCount++;
    else $pendingCount++;
    $price = (!empty($c['discount_price']) && $c['discount_price'] > 0) ? $c['discount_price'] : $c['price'];
    if ($price == 0) $freeCount++;
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$formOpen = ($editCourse || !empty($errors)) ? true : false;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Courses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --g: #00693e; --g2: #0a8f55; --gd: #024a2c; --gl: #e4f2e9;
            --r: #f42a41; --rl: #fdecee;
            --gold: #d9a441; --goldl: #fdf3e0;
            --ink: #10231a; --muted: #5a6c62; --border: #e1e9e3;
            --bg: #fbfcfb; --card: #fff; --surface: #f2f6f3;
            --radius: 18px;
            --shadow: 0 12px 32px -12px rgba(2,74,44,.15);
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Hind Siliguri', 'Plus Jakarta Sans', -apple-system, sans-serif;
            background: var(--bg); color: var(--ink); margin: 0;
        }

        .admin-bar {
            background: linear-gradient(135deg, var(--gd) 0%, var(--g) 55%, var(--g2) 100%);
            color: #fff; padding: 22px 26px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            box-shadow: var(--shadow);
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 16px;
        }
        .admin-bar h3 { margin: 0 0 4px; font-weight: 800; font-size: 22px; }
        .admin-bar p  { margin: 0; opacity: .9; font-size: 13.5px; }
        .admin-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .admin-stat  { text-align: right; }
        .admin-stat .num { font-size: 22px; font-weight: 800; }
        .admin-stat .lbl { font-size: 10.5px; opacity: .82; text-transform: uppercase; letter-spacing: .06em; }
        .btn-add-new {
            background: #fff; color: var(--g);
            font-weight: 700; font-size: 14px;
            padding: 11px 22px; border-radius: 50px;
            text-decoration: none; border: 0; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 8px 20px rgba(0,0,0,.15);
            transition: transform .15s, box-shadow .2s;
            font-family: inherit;
        }
        .btn-add-new:hover { color: var(--g); transform: translateY(-2px); box-shadow: 0 12px 26px rgba(0,0,0,.22); }

        .flash-msg {
            padding: 12px 18px; border-radius: 12px;
            margin-bottom: 18px; font-size: 14px;
            display: flex; align-items: center; gap: 8px;
            background: var(--gl); color: var(--gd);
            border-left: 4px solid var(--g);
        }

        .form-drawer {
            background: #fff; border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: none;
        }
        .form-drawer.open { display: block; }

        .drawer-head {
            padding: 16px 22px;
            background: linear-gradient(135deg, var(--gd), var(--g) 55%, var(--g2));
            color: #fff;
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px;
        }
        .drawer-head h5 {
            margin: 0; font-weight: 800; font-size: 16px;
            display: flex; align-items: center; gap: 8px;
        }
        .drawer-close {
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.3);
            color: #fff; font-weight: 700;
            padding: 6px 14px; border-radius: 30px;
            cursor: pointer; font-size: 12.5px;
            font-family: inherit;
        }
        .drawer-close:hover { background: rgba(255,255,255,.28); }

        .drawer-body { padding: 22px; }

        .form-section-title {
            font-size: 13px; font-weight: 800;
            color: var(--gd); margin: 0 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gl);
            display: flex; align-items: center; gap: 8px;
        }
        .form-section-title .num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--g); color: #fff;
            font-size: 12px; font-weight: 800;
        }
        .form-block { margin-bottom: 26px; }

        .form-label-custom {
            font-size: 11.5px; font-weight: 700; color: var(--muted);
            text-transform: uppercase; letter-spacing: .05em;
            margin-bottom: 5px; display: block;
        }
        .form-label-custom .req { color: var(--r); }

        .form-control-custom {
            border-radius: 10px; border: 1.5px solid var(--border);
            padding: 9px 13px; font-size: 14px;
            background: var(--surface); color: var(--ink);
            width: 100%; font-family: inherit;
            transition: all .15s;
        }
        .form-control-custom:focus {
            border-color: var(--g); background: #fff;
            box-shadow: 0 0 0 3px rgba(0,105,62,.12); outline: none;
        }
        textarea.form-control-custom { resize: vertical; }

        .help-text { font-size: 11px; color: var(--muted); margin-top: 4px; }

        .tone-picker { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .tone-swatch {
            width: 36px; height: 36px; border-radius: 9px;
            cursor: pointer; border: 3px solid transparent;
            transition: transform .15s;
        }
        .tone-swatch:hover { transform: scale(1.08); }
        .tone-swatch.active { border-color: var(--ink); transform: scale(1.05); }

        .btn-brand {
            background: linear-gradient(135deg, var(--g), var(--g2));
            border: none; color: #fff;
            font-weight: 700; font-size: 14px;
            padding: 11px 26px; border-radius: 11px;
            cursor: pointer; font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-brand:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 10px 22px -8px var(--g); }
        .btn-outline-brand {
            background: transparent; color: var(--g);
            border: 1.5px solid var(--g);
            font-weight: 700; font-size: 14px;
            padding: 10px 22px; border-radius: 11px;
            text-decoration: none; display: inline-flex;
            align-items: center; gap: 6px;
        }
        .btn-outline-brand:hover { background: var(--g); color: #fff; }

        .filter-bar {
            background: #fff; border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px;
            margin-bottom: 22px; box-shadow: var(--shadow);
            display: flex; flex-wrap: wrap; gap: 14px;
            align-items: flex-end;
        }
        .filter-bar .field { flex: 1 1 200px; min-width: 180px; }
        .filter-bar .field label {
            font-size: 11px; font-weight: 700; color: var(--muted);
            text-transform: uppercase; letter-spacing: .05em;
            margin-bottom: 5px; display: block;
        }
        .filter-bar .field input,
        .filter-bar .field select {
            width: 100%; height: 44px;
            border: 1.5px solid var(--border);
            border-radius: 11px; padding: 0 14px;
            background: var(--surface); font-size: 14px;
            color: var(--ink); font-family: inherit;
        }
        .filter-bar .field input:focus,
        .filter-bar .field select:focus {
            border-color: var(--g); background: #fff;
            box-shadow: 0 0 0 3px rgba(0,105,62,.1); outline: none;
        }
        .btn-clear {
            height: 44px; padding: 0 20px;
            border: 1.5px solid var(--border); background: var(--surface);
            color: var(--muted); font-weight: 600; font-size: 14px;
            border-radius: 11px; cursor: pointer; font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none;
        }
        .btn-clear:hover { background: var(--rl); border-color: var(--r); color: var(--r); }

        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
            gap: 22px;
            margin-bottom: 40px;
        }
        .course-item { display: flex; flex-direction: column; }

        .admin-card-actions {
            display: flex; gap: 6px; margin-bottom: 8px;
            flex-wrap: wrap; justify-content: flex-end;
        }
        .admin-act-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 5px; padding: 6px 12px;
            font-size: 12px; font-weight: 700;
            border-radius: 8px; border: 1.5px solid transparent;
            cursor: pointer; text-decoration: none;
            font-family: inherit; transition: all .18s;
        }
        .admin-act-edit    { background: var(--gl); color: var(--gd); border-color: #cbe6d6; }
        .admin-act-edit:hover { background: var(--g); color: #fff; }
        .admin-act-approve { background: #e4f2e9; color: #024a2c; border-color: #a7d9c0; }
        .admin-act-approve:hover { background: #0a8f55; color: #fff; }
        .admin-act-delete  { background: var(--rl); color: #c81e32; border-color: #fbc8ce; }
        .admin-act-delete:hover { background: var(--r); color: #fff; }

        .course-card {
            background: #fff; border-radius: 18px; overflow: hidden;
            box-shadow: 0 10px 28px -10px rgba(0,0,0,.15);
            transition: transform .22s, box-shadow .22s;
            display: flex; flex-direction: column;
        }
        .course-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 40px -12px rgba(0,0,0,.22);
        }

        .course-card-top {
            padding: 22px 18px 26px;
            text-align: center; color: #fff;
            position: relative; min-height: 210px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 10px;
        }
        .course-tag {
            position: absolute; top: 14px; right: 14px;
            background: #f5b301; color: #4a2c00;
            font-size: 11px; font-weight: 800;
            padding: 5px 12px; border-radius: 8px;
            letter-spacing: .5px; text-transform: uppercase;
        }
        .course-name {
            font-size: 20px; font-weight: 800;
            line-height: 1.3; margin: 18px 0 4px;
            letter-spacing: -.01em; padding: 0 6px;
        }
        .course-subtitle-pill {
            background: #fff; color: #8b0d20;
            font-size: 13px; font-weight: 700;
            padding: 6px 16px; border-radius: 30px;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .course-subject-pill {
            background: #f5b301; color: #2c1a00;
            font-size: 12.5px; font-weight: 600;
            padding: 6px 14px; border-radius: 30px;
            display: inline-block; max-width: 90%;
            line-height: 1.35;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .course-meta-row {
            display: flex; align-items: center; justify-content: center;
            gap: 14px; margin-top: 6px; width: 100%;
            max-width: 230px; margin-left: auto; margin-right: auto;
        }
        .course-meta-text {
            font-size: 14px; font-weight: 700; color: #fff;
            white-space: nowrap; flex-shrink: 0;
        }
        .course-discount-circle {
            width: 54px; height: 54px; border-radius: 50%;
            background: #f5b301;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            color: #2c1a00; font-weight: 800;
            font-size: 14px; line-height: 1;
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
            flex-shrink: 0;
        }
        .course-discount-circle small { font-size: 9px; font-weight: 700; margin-top: 1px; }

        .course-card-body {
            padding: 16px 18px 18px;
            text-align: center;
            display: flex; flex-direction: column; gap: 10px;
            flex: 1;
        }
        .course-body-title {
            font-size: 15.5px; font-weight: 800;
            color: #1a1a1a; line-height: 1.35;
        }
        .course-bottom-row {
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 10px; margin-top: 4px;
        }
        .course-stars {
            display: inline-flex; gap: 2px;
            color: #f5b301; font-size: 15px;
        }
        .course-stars i.empty { color: #d1d5db; }
        .course-view-link {
            font-size: 13.5px; font-weight: 700;
            color: #6b7280; text-decoration: none;
            transition: color .15s;
        }
        .course-view-link:hover { color: #8b0d20; }
        .course-cta-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; background: #8b0d20; color: #fff;
            font-weight: 800; font-size: 14px;
            padding: 11px 22px; border-radius: 30px;
            text-decoration: none; border: 0;
            transition: transform .15s, box-shadow .2s;
            font-family: inherit; align-self: flex-end;
            margin-top: 8px;
        }
        .course-cta-btn:hover {
            color: #fff; transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(139,13,32,.35);
        }

        .empty-state {
            grid-column: 1 / -1; text-align: center;
            padding: 60px 20px; background: #fff;
            border: 1px dashed var(--border);
            border-radius: var(--radius);
        }
        .empty-state i { font-size: 48px; color: #cbd5c4; }
        .empty-state p { color: var(--muted); margin: 12px 0 0; }

        @media (max-width: 720px) {
            .admin-bar { flex-direction: column; align-items: flex-start; }
            .filter-bar .field { flex: 1 1 100%; }
            .course-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<div class="container py-4" style="max-width: 1280px;">

    <!-- ============ ADMIN BAR ============ -->
    <div class="admin-bar">
        <div>
            <h3><i class="bi bi-mortarboard-fill"></i> Manage Courses</h3>
            <p>সব কোর্স এক জায়গায় — Add, Edit, Approve, Delete</p>
        </div>

        <div class="admin-stats">
            <div class="admin-stat"><div class="num"><?= $totalCourses ?></div><div class="lbl">Total</div></div>
            <div class="admin-stat"><div class="num"><?= $approvedCount ?></div><div class="lbl">Approved</div></div>
            <div class="admin-stat"><div class="num"><?= $pendingCount ?></div><div class="lbl">Pending</div></div>
            <div class="admin-stat"><div class="num"><?= $freeCount ?></div><div class="lbl">Free</div></div>
        </div>

        <?php if ($canInsert): ?>
            <button type="button" class="btn-add-new" id="toggleFormBtn">
                <i class="bi bi-plus-circle-fill"></i>
                <span id="toggleBtnLabel">নতুন কোর্স</span>
            </button>
        <?php endif; ?>
    </div>

    <!-- ============ FLASH ============ -->
    <?php if ($flashSuccess): ?>
        <div class="flash-msg">
            <i class="bi bi-check-circle-fill"></i>
            <?= htmlspecialchars($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <!-- ============ ERRORS ============ -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:12px;">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ============ FORM DRAWER ============ -->
    <div class="form-drawer <?= $formOpen ? 'open' : '' ?>" id="formDrawer">

        <div class="drawer-head">
            <h5>
                <i class="bi <?= $editCourse ? 'bi-pencil-square' : 'bi-plus-circle-fill' ?>"></i>
                <?= $editCourse
                    ? 'কোর্স এডিট: ' . htmlspecialchars($editCourse['title_bn'] ?: $editCourse['title'])
                    : 'নতুন কোর্স যোগ করুন' ?>
            </h5>
            <button type="button" class="drawer-close" id="closeDrawer">
                <i class="bi bi-x-lg"></i> বন্ধ
            </button>
        </div>

        <div class="drawer-body">
            <form method="post" enctype="multipart/form-data" id="courseForm">
                <input type="hidden" name="action" value="<?= $editCourse ? 'update' : 'save' ?>">
                <input type="hidden" name="course_id" value="<?= $editCourse ? (int)$editCourse['id'] : '' ?>">

                <!-- 1) LEVEL + TYPE -->
                <div class="form-block">
                    <h6 class="form-section-title">
                        <span class="num">1</span> Level ও Course Type
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Level <span class="req">*</span></label>
                            <select name="level_id" class="form-control-custom" required>
                                <option value="">-- Level নির্বাচন --</option>
                                <?php foreach ($levels as $lv): ?>
                                    <option value="<?= (int)$lv['id'] ?>"
                                        <?= $old['level_id'] == $lv['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lv['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Course Type <span class="req">*</span></label>
                            <select name="type_id" class="form-control-custom" required>
                                <option value="">-- Type নির্বাচন --</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"
                                        <?= $old['type_id'] == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 2) TITLES -->
                <div class="form-block">
                    <h6 class="form-section-title">
                        <span class="num">2</span> টাইটেল ও কোড
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label-custom">Title (English) <span class="req">*</span></label>
                            <input type="text" name="title" class="form-control-custom" required
                                   value="<?= htmlspecialchars($old['title']) ?>"
                                   placeholder="52nd BCS Preliminary">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Course Code <span class="req">*</span></label>
                            <input type="text" name="course_code" class="form-control-custom" required
                                   value="<?= htmlspecialchars($old['course_code']) ?>"
                                   placeholder="BCS-52-PAID"
                                   pattern="[A-Za-z0-9\-_]+">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label-custom">Title (বাংলা)</label>
                            <input type="text" name="title_bn" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['title_bn']) ?>"
                                   placeholder="৫২তম বিসিএস প্রিলিমিনারি">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Slug</label>
                            <input type="text" name="slug" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['slug']) ?>"
                                   placeholder="bcs-52-preliminary">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Instructor</label>
                            <input type="text" name="instructor_name" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['instructor_name']) ?>">
                        </div>
                    </div>
                </div>

                <!-- 3) CARD DISPLAY -->
                <div class="form-block">
                    <h6 class="form-section-title">
                        <span class="num">3</span> হোমপেজ কার্ড ডিসপ্লে
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Subtitle</label>
                            <input type="text" name="subtitle" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['subtitle']) ?>"
                                   placeholder="পেইড এক্সাম ব্যাচ">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Tag</label>
                            <input type="text" name="tag" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['tag']) ?>"
                                   placeholder="BCS">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Discount %</label>
                            <input type="text" name="discount_pct" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['discount_pct']) ?>"
                                   placeholder="৮০%">
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Subject Line</label>
                            <input type="text" name="subject_line" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['subject_line']) ?>"
                                   placeholder="বিষয়: বিসিএস মডেল টেস্ট">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Meta Text</label>
                            <input type="text" name="meta_text" class="form-control-custom"
                                   value="<?= htmlspecialchars($old['meta_text']) ?>"
                                   placeholder="ভর্তি চলছে...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Rating (1–5)</label>
                            <input type="number" name="rating" min="1" max="5"
                                   class="form-control-custom"
                                   value="<?= (int)$old['rating'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Display Order</label>
                            <input type="number" name="display_order" min="0"
                                   class="form-control-custom"
                                   value="<?= (int)$old['display_order'] ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Card Gradient (tone)</label>
                            <input type="text" name="tone_gradient" id="toneInput"
                                   class="form-control-custom"
                                   value="<?= htmlspecialchars($old['tone_gradient']) ?>">
                            <div class="tone-picker">
                                <?php
                                $swatches = [
                                    'linear-gradient(135deg,#7a1122 0%,#a31528 100%)',
                                    'linear-gradient(135deg,#1e5128 0%,#2d7a3f 100%)',
                                    'linear-gradient(135deg,#0a3d62 0%,#1565a0 100%)',
                                    'linear-gradient(135deg,#00693e 0%,#0a8f55 100%)',
                                    'linear-gradient(135deg,#0d3a26 0%,#1b6f4b 100%)',
                                    'linear-gradient(135deg,#d9a441 0%,#f5c35c 100%)',
                                    'linear-gradient(135deg,#7c3aed 0%,#a78bfa 100%)',
                                    'linear-gradient(135deg,#334155 0%,#64748b 100%)',
                                ];
                                foreach ($swatches as $sw):
                                ?>
                                    <div class="tone-swatch <?= $old['tone_gradient'] === $sw ? 'active' : '' ?>"
                                         style="background:<?= htmlspecialchars($sw) ?>"
                                         data-tone="<?= htmlspecialchars($sw) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4) PRICING -->
                <div class="form-block">
                    <h6 class="form-section-title">
                        <span class="num">4</span> Pricing ও কনটেন্ট
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label-custom">Old Price ৳ <span class="req">*</span></label>
                            <input type="number" name="price" min="0" step="0.01" required
                                   class="form-control-custom"
                                   value="<?= htmlspecialchars($old['price']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">New Price ৳</label>
                            <input type="number" name="discount_price" min="0" step="0.01"
                                   class="form-control-custom"
                                   value="<?= htmlspecialchars($old['discount_price']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Total Lessons</label>
                            <input type="number" name="total_lessons" min="0"
                                   class="form-control-custom"
                                   value="<?= (int)$old['total_lessons'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Duration (hours)</label>
                            <input type="number" name="duration_hours" min="0"
                                   class="form-control-custom"
                                   value="<?= (int)$old['duration_hours'] ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Short Description</label>
                            <input type="text" name="short_desc" maxlength="500"
                                   class="form-control-custom"
                                   value="<?= htmlspecialchars($old['short_desc']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Full Description</label>
                            <textarea name="description" rows="3"
                                      class="form-control-custom"><?= htmlspecialchars($old['description']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Thumbnail (২ MB max)</label>
                            <input type="file" name="thumbnail" accept="image/*"
                                   class="form-control-custom">
                            <?php if ($editCourse && !empty($old['_thumbnail'])): ?>
                                <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
                                    <img src="<?= htmlspecialchars($old['_thumbnail']) ?>"
                                         style="height:55px;border-radius:8px;border:1px solid var(--border);">
                                    <small style="color:var(--muted);">পুরোনো থাকবে (নতুন না দিলে)</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 5) STATUS -->
                <div class="form-block">
                    <h6 class="form-section-title">
                        <span class="num">5</span> Status
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="is_featured" id="is_featured" value="1"
                                       <?= $old['is_featured'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="is_featured">
                                    <i class="bi bi-star-fill text-warning"></i> Featured
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="is_active" id="is_active" value="1"
                                       <?= $old['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="is_active">
                                    <i class="bi bi-eye-fill text-success"></i> Active
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Authorize</label>
                            <select name="AUTHORIZED_STATUS" class="form-control-custom">
                                <option value="N" <?= $old['AUTHORIZED_STATUS'] === 'N' ? 'selected' : '' ?>>Pending (N)</option>
                                <option value="Y" <?= $old['AUTHORIZED_STATUS'] === 'Y' ? 'selected' : '' ?>>Authorized (Y)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn-outline-brand" id="cancelBtn">বাতিল</button>
                    <button type="submit" class="btn-brand">
                        <i class="bi bi-check2-circle"></i>
                        <?= $editCourse ? 'আপডেট করুন' : 'সংরক্ষণ করুন' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ FILTER BAR ============ -->
    <form class="filter-bar" method="get" id="filterForm">
        <div class="field" style="flex: 2 1 320px;">
            <label><i class="bi bi-search"></i> Search</label>
            <input type="text" name="search" id="filterSearch"
                   placeholder="Course code, title, instructor, tag..."
                   value="<?= htmlspecialchars($searchKeyword) ?>">
        </div>
        <div class="field">
            <label><i class="bi bi-layers"></i> Level</label>
            <select name="level_id" id="filterLevel">
                <option value="0">All Levels</option>
                <?php foreach ($levels as $lv): ?>
                    <option value="<?= (int)$lv['id'] ?>" <?= $selectedLevel == $lv['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lv['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label><i class="bi bi-tag"></i> Type</label>
            <select name="type_id" id="filterType">
                <option value="0">All Types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $selectedType == $t['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex: 0 0 auto;">
            <button type="submit" class="btn-add-new" style="background:var(--g);color:#fff;height:44px;">
                <i class="bi bi-funnel-fill"></i> Filter
            </button>
        </div>
        <div class="field" style="flex: 0 0 auto;">
            <a href="manage_courses.php" class="btn-clear">
                <i class="bi bi-x-circle"></i> Clear
            </a>
        </div>
    </form>

    <!-- ============ COURSE GRID ============ -->
    <div class="course-grid">
        <?php if ($totalCourses > 0): ?>
            <?php foreach ($courses as $c):
                $titleBn   = $c['title_bn'] ?: $c['title'];
                $subtitle  = $c['subtitle'] ?: '';
                $subject   = $c['subject_line'] ?: '';
                $tag       = $c['tag'] ?: '';
                $discount  = $c['discount_pct'] ?: '';
                $meta      = $c['meta_text'] ?: '';
                $rating    = max(1, min(5, (int)($c['rating'] ?? 5)));
                $tone      = $c['tone_gradient'] ?: 'linear-gradient(135deg,#7a1122 0%,#a31528 100%)';

                $isAuth    = ($c['AUTHORIZED_STATUS'] ?? 'N') === 'Y';

                $viewUrl = 'course_view.php?course_id='   . (int)$c['id']
                         . '&level_id='    . (int)$c['level_id']
                         . '&exam_type_id=' . (int)$c['type_id'];
            ?>
            <div class="course-item">

                <?php if ($canEdit || $canDelete || $canApprove): ?>
                <div class="admin-card-actions">
                    <?php if ($canEdit): ?>
                        <a href="?edit=<?= (int)$c['id'] ?>"
                           class="admin-act-btn admin-act-edit" title="Edit">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>
                    <?php endif; ?>

                    <?php if ($canApprove && !$isAuth): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('এই কোর্স approve করবেন?');">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="admin-act-btn admin-act-approve">
                                <i class="bi bi-check-circle"></i> Approve
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canDelete && !$isAuth): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('এই কোর্স মুছে ফেলবেন?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="admin-act-btn admin-act-delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="course-card">
                    <div class="course-card-top" style="background:<?= htmlspecialchars($tone) ?>">
                        <?php if ($tag): ?>
                            <span class="course-tag"><?= htmlspecialchars($tag) ?></span>
                        <?php endif; ?>

                        <h3 class="course-name"><?= htmlspecialchars($titleBn) ?></h3>

                        <?php if ($subtitle): ?>
                            <div class="course-subtitle-pill"><?= htmlspecialchars($subtitle) ?></div>
                        <?php endif; ?>

                        <?php if ($subject): ?>
                            <div class="course-subject-pill"><?= htmlspecialchars($subject) ?></div>
                        <?php endif; ?>

                        <div class="course-meta-row">
                            <?php if ($meta): ?>
                                <span class="course-meta-text"><?= htmlspecialchars($meta) ?></span>
                            <?php endif; ?>
                            <?php if ($discount): ?>
                                <div class="course-discount-circle">
                                    <?= htmlspecialchars($discount) ?>
                                    <small>ছাড়</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="course-card-body">
                        <div class="course-body-title">
                            <?= htmlspecialchars($titleBn) ?>
                            <?php if ($subtitle): ?> - <?= htmlspecialchars($subtitle) ?><?php endif; ?>
                        </div>
                        <div class="course-bottom-row">
                            <div class="course-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star empty' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <a href="<?= htmlspecialchars($viewUrl) ?>" class="course-view-link">
                                View Details
                            </a>
                        </div>
                        <a href="<?= htmlspecialchars($viewUrl) ?>" class="course-cta-btn">
                            কোর্সটি করুন
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p>কোনো কোর্স পাওয়া যায়নি। "নতুন কোর্স" ক্লিক করুন।</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   FORM DRAWER TOGGLE
   ============================================================ */
const formDrawer = document.getElementById('formDrawer');
const toggleBtn  = document.getElementById('toggleFormBtn');
const closeBtn   = document.getElementById('closeDrawer');
const cancelBtn  = document.getElementById('cancelBtn');
const labelEl    = document.getElementById('toggleBtnLabel');

function openForm() {
    formDrawer.classList.add('open');
    if (labelEl) labelEl.textContent = 'ফর্ম বন্ধ করুন';
    formDrawer.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function closeForm() {
    formDrawer.classList.remove('open');
    if (labelEl) labelEl.textContent = 'নতুন কোর্স';

    const url = new URL(window.location.href);
    if (url.searchParams.has('edit')) {
        url.searchParams.delete('edit');
        window.history.replaceState({}, '', url.toString());
    }
}
function toggleForm() {
    if (formDrawer.classList.contains('open')) closeForm();
    else openForm();
}

toggleBtn?.addEventListener('click', toggleForm);
closeBtn?.addEventListener('click', closeForm);
cancelBtn?.addEventListener('click', closeForm);

/* ============================================================
   TONE SWATCHES
   ============================================================ */
const toneInput = document.getElementById('toneInput');
document.querySelectorAll('.tone-swatch').forEach(sw => {
    sw.addEventListener('click', () => {
        if (!toneInput) return;
        toneInput.value = sw.dataset.tone;
        document.querySelectorAll('.tone-swatch').forEach(x => x.classList.remove('active'));
        sw.classList.add('active');
    });
});

/* ============================================================
   AUTO-APPLY FILTERS
   ★ শুধু filter bar-এর input/select-এ কাজ করবে (id দিয়ে ধরা)
   ★ ফর্ম drawer-এর select-এ কোনো লিসেনার নেই — তাই auto submit হবে না
   ============================================================ */
let searchTimer;
const filterSearch = document.getElementById('filterSearch');
const filterLevel  = document.getElementById('filterLevel');
const filterType   = document.getElementById('filterType');

function applyFilters() {
    const params = new URLSearchParams(window.location.search);

    if (filterSearch?.value.trim()) params.set('search', filterSearch.value.trim());
    else params.delete('search');

    if (filterLevel && filterLevel.value !== '0') params.set('level_id', filterLevel.value);
    else params.delete('level_id');

    if (filterType && filterType.value !== '0') params.set('type_id', filterType.value);
    else params.delete('type_id');

    /* filter করার সময় edit mode বন্ধ */
    params.delete('edit');

    window.location.search = params.toString();
}

if (filterSearch) {
    filterSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 600);
    });
}
filterLevel?.addEventListener('change', applyFilters);
filterType?.addEventListener('change', applyFilters);
</script>

</body>
</html>