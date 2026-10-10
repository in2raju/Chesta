<?php
/**
 * browse-questions.php
 * ------------------------------------------------------------
 * Public page, two-step browse flow:
 *   1) Grid of Reference Exam cards (from `referance_exams`),
 *      each showing exam name, taker, year, and question count.
 *      A keyword box filters the exam list itself.
 *   2) Click an exam card -> drill into that exam's questions,
 *      further filterable by Subject / Chapter / Topic, plus
 *      a ChatGPT-style live keyword search.
 *
 * Single file serves both the HTML page (GET) and the JSON
 * AJAX endpoints (POST ?ajax=1, see $_POST['action']).
 * ------------------------------------------------------------
 */

// =========================================================================
// AJAX ENDPOINT HANDLER - MUST BE FIRST
// =========================================================================
if (isset($_GET['ajax'])) {
    // Clear ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        require_once __DIR__ . '/db.php';
        
        if (!isset($pdo)) {
            throw new Exception('Database connection not established');
        }
        
        $action = $_POST['action'] ?? '';
        $responseJson = null;
        
        switch ($action) {
            /* ---- list Reference Exams (step 1 cards) ---- */
            case 'list_exams': {
                $keyword = trim($_POST['keyword'] ?? '');
                $page    = max(0, (int) ($_POST['page'] ?? 0));
                $limit   = 12;
                $offset  = $page * $limit;
                
                // Build WHERE clause
                $where  = ['re.DELETE_DATE IS NULL'];
                $params = [];
                
                // Check if DELETE_DATE column exists, if not, remove it from WHERE
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM referance_exams LIKE 'DELETE_DATE'");
                    if ($colCheck->rowCount() == 0) {
                        $where = []; // No DELETE_DATE column
                    }
                } catch (PDOException $e) {
                    $where = [];
                }
                
                if ($keyword !== '') {
                    $where[] = 're.r_exam_name LIKE :kw';
                    $params[':kw'] = '%' . $keyword . '%';
                }
                
                $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
                
                // Get total count
                $countSql = "SELECT COUNT(*) FROM referance_exams re {$whereSql}";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($params);
                $total = (int) $countStmt->fetchColumn();
                
                // Get exams with question count
                $sql = "SELECT
                            re.referance_exam_id,
                            re.r_exam_name,
                            re.r_exam_taker,
                            re.r_exam_date,
                            re.r_exam_year,
                            COUNT(q.id) AS question_count
                        FROM referance_exams re
                        LEFT JOIN questions q
                            ON q.referance_exam_id = re.referance_exam_id
                            
                        {$whereSql}
                        GROUP BY
                            re.referance_exam_id,
                            re.r_exam_name,
                            re.r_exam_taker,
                            re.r_exam_date,
                            re.r_exam_year
                        ORDER BY re.r_exam_name ASC, re.r_exam_date DESC
                        LIMIT :limit OFFSET :offset";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    $stmt->bindValue($k, $v);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Ensure question_count is an integer
                foreach ($items as &$item) {
                    $item['question_count'] = (int)($item['question_count'] ?? 0);
                    // Convert year to string if it's null
                    if ($item['r_exam_year'] === null) {
                        $item['r_exam_year'] = '';
                    }
                }
                unset($item);
                
                $responseJson = json_encode([
                    'items'    => $items,
                    'total'    => $total,
                    'has_more' => ($offset + $limit) < $total,
                ]);
                break;
            }
            
            /* ---- distinct Subjects present in one exam's questions ---- */
            case 'get_exam_subjects': {
                $examId = (int) ($_POST['referance_exam_id'] ?? 0);
                if ($examId <= 0) {
                    throw new Exception('Invalid exam ID');
                }
                
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT s.id, s.name
                     FROM questions q
                     JOIN topics t   ON q.topic_id = t.id
                     JOIN chapters c ON t.chapter_id = c.id
                     JOIN subjects s ON c.subject_id = s.id
                     WHERE q.referance_exam_id = :eid 
                    
                     ORDER BY s.name ASC"
                );
                $stmt->execute([':eid' => $examId]);
                $responseJson = json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            }
            
            /* ---- distinct Chapters for one exam (+ optional subject) ---- */
            case 'get_exam_chapters': {
                $examId    = (int) ($_POST['referance_exam_id'] ?? 0);
                $subjectId = trim($_POST['subject_id'] ?? '');
                
                $where  = ['q.referance_exam_id = :eid'];
                $params = [':eid' => $examId];
                
                // Add DELETE_DATE condition if column exists
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM questions LIKE 'DELETE_DATE'");
                    if ($colCheck->rowCount() > 0) {
                        $where[] = "q.DELETE_DATE IS NULL";
                    }
                } catch (PDOException $e) {
                    // Column doesn't exist, skip
                }
                
                if ($subjectId !== '') {
                    $where[] = 's.id = :sid';
                    $params[':sid'] = (int) $subjectId;
                }
                $whereSql = implode(' AND ', $where);
                
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT c.id, c.name
                     FROM questions q
                     JOIN topics t   ON q.topic_id = t.id
                     JOIN chapters c ON t.chapter_id = c.id
                     JOIN subjects s ON c.subject_id = s.id
                     WHERE {$whereSql}
                     ORDER BY c.name ASC"
                );
                $stmt->execute($params);
                $responseJson = json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            }
            
            /* ---- distinct Topics for one exam (+ optional subject/chapter) ---- */
            case 'get_exam_topics': {
                $examId    = (int) ($_POST['referance_exam_id'] ?? 0);
                $subjectId = trim($_POST['subject_id'] ?? '');
                $chapterId = trim($_POST['chapter_id'] ?? '');
                
                $where  = ['q.referance_exam_id = :eid'];
                $params = [':eid' => $examId];
                
                // Add DELETE_DATE condition if column exists
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM questions LIKE 'DELETE_DATE'");
                    if ($colCheck->rowCount() > 0) {
                        $where[] = "q.DELETE_DATE IS NULL";
                    }
                } catch (PDOException $e) {
                    // Column doesn't exist, skip
                }
                
                if ($subjectId !== '') {
                    $where[] = 's.id = :sid';
                    $params[':sid'] = (int) $subjectId;
                }
                if ($chapterId !== '') {
                    $where[] = 'c.id = :cid';
                    $params[':cid'] = (int) $chapterId;
                }
                $whereSql = implode(' AND ', $where);
                
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT t.id, t.name
                     FROM questions q
                     JOIN topics t   ON q.topic_id = t.id
                     JOIN chapters c ON t.chapter_id = c.id
                     JOIN subjects s ON c.subject_id = s.id
                     WHERE {$whereSql}
                     ORDER BY t.name ASC"
                );
                $stmt->execute($params);
                $responseJson = json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            }
            
            /* ---- questions belonging to one exam, filterable (step 2 cards) ---- */
            case 'exam_questions': {
                $examId    = (int) ($_POST['referance_exam_id'] ?? 0);
                $subjectId = trim($_POST['subject_id'] ?? '');
                $chapterId = trim($_POST['chapter_id'] ?? '');
                $topicId   = trim($_POST['topic_id']   ?? '');
                $keyword   = trim($_POST['keyword']    ?? '');
                $page      = max(0, (int) ($_POST['page'] ?? 0));
                $limit     = 12;
                $offset    = $page * $limit;
                
                $where  = ['q.referance_exam_id = :eid'];
                $params = [':eid' => $examId];
                
                // Add DELETE_DATE condition if column exists
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM questions LIKE 'DELETE_DATE'");
                    if ($colCheck->rowCount() > 0) {
                        $where[] = "q.DELETE_DATE IS NULL";
                    }
                } catch (PDOException $e) {
                    // Column doesn't exist, skip
                }
                
                if ($subjectId !== '') {
                    $where[] = 's.id = :sid';
                    $params[':sid'] = (int) $subjectId;
                }
                if ($chapterId !== '') {
                    $where[] = 'c.id = :cid';
                    $params[':cid'] = (int) $chapterId;
                }
                if ($topicId !== '') {
                    $where[] = 't.id = :tid';
                    $params[':tid'] = (int) $topicId;
                }
                if ($keyword !== '') {
                    $where[] = '(q.question LIKE :kw OR q.option_a LIKE :kw OR q.option_b LIKE :kw
                                 OR q.option_c LIKE :kw OR q.option_d LIKE :kw OR q.explanation LIKE :kw
                                 OR s.name LIKE :kw OR c.name LIKE :kw OR t.name LIKE :kw)';
                    $params[':kw'] = '%' . $keyword . '%';
                }
                $whereSql = implode(' AND ', $where);
                
                $baseFrom = "FROM questions q
                             JOIN topics t   ON q.topic_id = t.id
                             JOIN chapters c ON t.chapter_id = c.id
                             JOIN subjects s ON c.subject_id = s.id
                             JOIN levels l   ON s.level_id = l.id
                             WHERE {$whereSql}";
                
                $countStmt = $pdo->prepare("SELECT COUNT(*) {$baseFrom}");
                $countStmt->execute($params);
                $total = (int) $countStmt->fetchColumn();
                
                $stmt = $pdo->prepare(
                    "SELECT q.id, q.question, q.option_a, q.option_b, q.option_c, q.option_d,
                            q.correct_option, q.explanation, q.image_url,
                            t.name AS topic_name, c.name AS chapter_name,
                            s.name AS subject_name, l.name AS level_name
                     {$baseFrom}
                     ORDER BY q.id ASC
                     LIMIT :limit OFFSET :offset"
                );
                foreach ($params as $k => $v) {
                    $stmt->bindValue($k, $v);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $responseJson = json_encode([
                    'items'    => $items,
                    'total'    => $total,
                    'has_more' => ($offset + $limit) < $total,
                ]);
                break;
            }
            
            default:
                http_response_code(400);
                $responseJson = json_encode(['error' => 'Unknown action: ' . $action]);
        }
        
        if ($responseJson === false) {
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        
        echo $responseJson;
        exit;
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Server error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// =========================================================================
// NORMAL PAGE LOAD - HTML
// =========================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Practice Questions — Exam360</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tiro+Bangla:ital@0;1&family=Hind+Siliguri:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #ffffff;
  --surface:  #f6f8f6;
  --surface2: #eef4ef;
  --card:     #ffffff;
  --green:    #00693e;
  --green-dp: #024a2c;
  --green-lt: #e7f2ea;
  --red:      #f42a41;
  --red-dp:   #c81e32;
  --gold:     #d9a441;
  --ink:      #12261c;
  --muted:    #5e6f66;
  --border:   #e3ece5;
  --radius:   18px;
  --shadow:   0 24px 60px -12px rgba(2,74,44,0.14);
  --font-d:   'Tiro Bangla', serif;
  --font-b:   'Hind Siliguri', sans-serif;
  --font-u:   'Inter', sans-serif;
  --nav-h:    72px;
}

body { font-family: var(--font-b); background: var(--bg); color: var(--ink); line-height: 1.7; }
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--surface); }
::-webkit-scrollbar-thumb { background: var(--green); border-radius: 4px; }
.container { width: min(1200px, 94%); margin: 0 auto; }

.disc {
  --disc-size: 46px; width: var(--disc-size); height: var(--disc-size);
  border-radius: 14px; background: var(--green-lt); border: 1px solid rgba(0,105,62,.16);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--green-dp);
}

.btn {
  display: inline-flex; align-items: center; gap: 9px; padding: 12px 24px; border-radius: 50px;
  font-family: var(--font-u); font-size: .88rem; font-weight: 600; cursor: pointer;
  border: 1.5px solid transparent; text-decoration: none; transition: all .25s; letter-spacing: .01em;
}
.btn-primary { background: var(--green); color: #fff; }
.btn-primary:hover { background: var(--green-dp); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,105,62,.28); }
.btn-outline { background: transparent; color: var(--green); border-color: var(--border); }
.btn-outline:hover { border-color: var(--green); background: var(--green-lt); }
.btn:disabled { opacity: .55; cursor: not-allowed; transform: none !important; }

.tag { display: inline-flex; align-items: center; gap: 6px; padding: 5px 13px; border-radius: 50px; font-size: .72rem; font-weight: 600; letter-spacing: .04em; font-family: var(--font-u); }
.tag-green { background: var(--green-lt); color: var(--green-dp); }
.tag-red   { background: rgba(244,42,65,.09); color: var(--red-dp); }
.tag-gold  { background: rgba(217,164,65,.14); color: #93701f; }

nav {
  position: fixed; top: 0; width: 100%; z-index: 900; height: var(--nav-h);
  background: rgba(255,255,255,.92); backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border); display: flex; align-items: center;
}
.nav-inner { display: flex; align-items: center; justify-content: space-between; width: min(1200px, 94%); margin: 0 auto; }
.logo { display: flex; align-items: center; gap: 12px; font-family: var(--font-d); font-size: 1.4rem; font-weight: 700; color: var(--ink); text-decoration: none; }
.logo span { color: var(--red); }
.nav-actions { display: flex; gap: 10px; align-items: center; }

.page-head { padding: calc(var(--nav-h) + 44px) 0 8px; background: var(--surface); }
.section-label { display: flex; align-items: center; gap: 10px; font-family: var(--font-u); font-size: .74rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--red); margin-bottom: 14px; }
.section-label::before { content: ''; width: 24px; height: 2px; background: var(--red); }
h1.page-title { font-family: var(--font-d); font-size: clamp(1.7rem, 4vw, 2.4rem); font-weight: 400; margin-bottom: 10px; }
p.page-sub { font-family: var(--font-u); color: var(--muted); font-size: .95rem; max-width: 620px; margin-bottom: 34px; }

.filter-bar {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 22px; display: grid; gap: 14px; align-items: end; box-shadow: var(--shadow);
  position: relative; top: 26px; margin-bottom: 50px;
}
#examSearchBar.filter-bar { grid-template-columns: 1fr auto; }
#questionFilterBar.filter-bar { grid-template-columns: 1fr 1fr 1fr 1.6fr auto; }
.filter-group label { display: block; font-family: var(--font-u); font-size: .74rem; font-weight: 600; color: var(--muted); margin-bottom: 7px; letter-spacing: .03em; text-transform: uppercase; }
.form-control {
  width: 100%; padding: 12px 14px; background: var(--surface); border: 1.5px solid var(--border);
  border-radius: 12px; color: var(--ink); font-family: var(--font-b); font-size: .93rem;
  transition: all .2s; outline: none; appearance: none;
}
.form-control:focus { border-color: var(--green); background: #fff; box-shadow: 0 0 0 3px rgba(0,105,62,.1); }
.search-input-wrap { position: relative; }
.search-input-wrap i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.search-input-wrap input { padding-left: 40px; }
.filter-clear-wrap { display: flex; }
.filter-clear-wrap .btn { white-space: nowrap; width: 100%; justify-content: center; padding: 12px 18px; }

#resultCount, #examResultCount { font-family: var(--font-u); font-size: .86rem; color: var(--muted); margin-bottom: 22px; }
#resultCount strong, #examResultCount strong { color: var(--green-dp); }

.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }

/* ---- Exam card (step 1) ---- */
.exam-card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 26px; cursor: pointer; transition: all .3s; display: flex; flex-direction: column; gap: 14px;
}
.exam-card:hover { transform: translateY(-4px); border-color: rgba(0,105,62,.28); box-shadow: var(--shadow); background: var(--green-lt); }
.exam-card .disc { --disc-size: 50px; }
.exam-card h3 { font-family: var(--font-d); font-size: 1.15rem; font-weight: 600; color: var(--ink); line-height: 1.4; }
.exam-card .exam-meta { font-family: var(--font-u); font-size: .82rem; color: var(--muted); display: flex; flex-wrap: wrap; gap: 6px 14px; }
.exam-card .exam-meta span { display: inline-flex; align-items: center; gap: 6px; }
.exam-card .exam-footer { margin-top: auto; display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px dashed var(--border); }
.exam-card .view-link { font-family: var(--font-u); font-size: .82rem; font-weight: 600; color: var(--green-dp); display: flex; align-items: center; gap: 6px; }

/* ---- Question card (step 2) ---- */
.q-card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 26px; transition: all .3s; display: flex; flex-direction: column; gap: 16px;
}
.q-card:hover { transform: translateY(-3px); border-color: rgba(0,105,62,.26); box-shadow: var(--shadow); }
.q-tags { display: flex; flex-wrap: wrap; gap: 7px; }
.q-text { font-family: var(--font-b); font-size: 1rem; font-weight: 500; color: var(--ink); line-height: 1.6; }
.q-image { max-width: 100%; border-radius: 10px; border: 1px solid var(--border); }

.options-list { display: flex; flex-direction: column; gap: 9px; }
.option-item {
  display: flex; gap: 10px; align-items: flex-start; padding: 11px 13px; border-radius: 10px;
  background: var(--surface); border: 1.5px solid var(--border); font-family: var(--font-b);
  font-size: .9rem; color: var(--ink); transition: all .2s;
}
.option-item .opt-letter {
  flex-shrink: 0; width: 22px; height: 22px; border-radius: 7px; background: var(--card);
  border: 1.5px solid var(--border); display: flex; align-items: center; justify-content: center;
  font-family: var(--font-u); font-size: .72rem; font-weight: 700; color: var(--muted);
}
.q-card.revealed .option-item.is-correct { background: var(--green-lt); border-color: var(--green); }
.q-card.revealed .option-item.is-correct .opt-letter { background: var(--green); border-color: var(--green); color: #fff; }

.q-footer { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: auto; padding-top: 14px; border-top: 1px dashed var(--border); }
.reveal-btn {
  background: none; border: 1.5px solid var(--border); color: var(--green-dp); padding: 9px 16px;
  border-radius: 50px; font-family: var(--font-u); font-size: .82rem; font-weight: 600; cursor: pointer; transition: all .2s;
}
.reveal-btn:hover { background: var(--green-lt); border-color: var(--green); }

.explanation-box {
  display: none; background: var(--surface2); border-radius: 10px; padding: 13px 15px;
  font-family: var(--font-u); font-size: .84rem; color: var(--muted); line-height: 1.65;
}
.q-card.revealed .explanation-box { display: block; }

.state-box { text-align: center; padding: 70px 20px; font-family: var(--font-u); color: var(--muted); }
.state-box i { font-size: 2rem; color: var(--green); margin-bottom: 14px; display: block; }
.skeleton { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 26px; height: 220px; position: relative; overflow: hidden; }
.skeleton::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(0,105,62,.06), transparent); animation: shimmer 1.3s infinite; }
@keyframes shimmer { from { transform: translateX(-100%); } to { transform: translateX(100%); } }

.load-more-wrap { display: none; justify-content: center; margin: 40px 0 90px; }

.back-btn { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 22px; }
#questionsHeading { font-family: var(--font-d); font-size: 1.5rem; font-weight: 400; margin-bottom: 6px; }
#questionsSubheading { font-family: var(--font-u); color: var(--muted); font-size: .88rem; margin-bottom: 30px; }

#questionsView { display: none; }

@media (max-width: 900px) {
  #questionFilterBar.filter-bar { grid-template-columns: 1fr 1fr; }
  .filter-clear-wrap { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
  .filter-bar { grid-template-columns: 1fr !important; top: 0; }
  .page-head { padding-bottom: 30px; }
}
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <a href="index.php" class="logo"><div class="disc"><span>E</span></div>Exam<span>360</span></a>
    <div class="nav-actions">
      <a href="login.php" class="btn btn-outline"><i class="fa fa-sign-in-alt"></i> Login</a>
    </div>
  </div>
</nav>

<div class="page-head">
  <div class="container">
    <div class="section-label">Practice Question Bank</div>
    <h1 class="page-title" id="mainHeading">Browse questions by <em style="color:var(--green);font-style:normal">exam</em></h1>
    <p class="page-sub" id="mainSub">Pick a past exam — BCS, Bank, HSC, SSC and more — then filter its questions by subject, chapter, or topic.</p>
  </div>
</div>

<div class="container">

  <!-- ===================== STEP 1: EXAM LIST ===================== -->
  <div id="examsView">
    <div class="filter-bar" id="examSearchBar">
      <div class="filter-group">
        <label for="examKeywordInput">Search Exams</label>
        <div class="search-input-wrap">
          <i class="fa fa-search"></i>
          <input type="text" id="examKeywordInput" class="form-control" placeholder="e.g. BCS 46th, Sonali Bank Officer, HSC 2025...">
        </div>
      </div>
      <div class="filter-group filter-clear-wrap">
        <button class="btn btn-outline" id="clearExamSearchBtn"><i class="fa fa-rotate-left"></i> Clear</button>
      </div>
    </div>

    <p id="examResultCount">Loading exams…</p>
    <div id="examCardsGrid" class="cards-grid"></div>
    <div id="examEmptyState" class="state-box" style="display:none">
      <i class="fa fa-folder-open"></i>
      No exams matched your search.
    </div>
    <div id="examLoadMoreWrap" class="load-more-wrap">
      <button class="btn btn-primary" id="examLoadMoreBtn"><i class="fa fa-plus"></i> Load More Exams</button>
    </div>
  </div>

  <!-- ===================== STEP 2: QUESTIONS OF ONE EXAM ===================== -->
  <div id="questionsView">
    <button class="btn btn-outline back-btn" id="backToExamsBtn"><i class="fa fa-arrow-left"></i> Back to Exams</button>
    <h2 id="questionsHeading">Exam</h2>
    <p id="questionsSubheading"></p>

    <div class="filter-bar" id="questionFilterBar">
      <div class="filter-group">
        <label for="subjectSelect">Subject</label>
        <select id="subjectSelect" class="form-control">
          <option value="">All Subjects</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="chapterSelect">Chapter</label>
        <select id="chapterSelect" class="form-control" disabled>
          <option value="">All Chapters</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="topicSelect">Topic</label>
        <select id="topicSelect" class="form-control" disabled>
          <option value="">All Topics</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="keywordInput">Keyword Search</label>
        <div class="search-input-wrap">
          <i class="fa fa-search"></i>
          <input type="text" id="keywordInput" class="form-control" placeholder="Search within this exam...">
        </div>
      </div>
      <div class="filter-group filter-clear-wrap">
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fa fa-rotate-left"></i> Clear</button>
      </div>
    </div>

    <p id="resultCount">Loading questions…</p>
    <div id="cardsGrid" class="cards-grid"></div>
    <div id="emptyState" class="state-box" style="display:none">
      <i class="fa fa-magnifying-glass"></i>
      No questions matched your filters. Try a different subject, chapter, or keyword.
    </div>
    <div id="loadMoreWrap" class="load-more-wrap">
      <button class="btn btn-primary" id="loadMoreBtn"><i class="fa fa-plus"></i> Load More Questions</button>
    </div>
  </div>

</div>

<script>
const AJAX_URL = 'browse-questions.php?ajax=1';

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

/* ============================================================
   STEP 1 — EXAM LIST
   ============================================================ */
const examsView       = document.getElementById('examsView');
const examCardsGrid   = document.getElementById('examCardsGrid');
const examResultCount = document.getElementById('examResultCount');
const examEmptyState  = document.getElementById('examEmptyState');
const examLoadMoreWrap= document.getElementById('examLoadMoreWrap');
const examLoadMoreBtn = document.getElementById('examLoadMoreBtn');
const examKeywordInput= document.getElementById('examKeywordInput');

let examPage = 0;
let examKeywordTimer = null;

function showExamSkeletons(count) {
  examCardsGrid.innerHTML = '';
  for (let i = 0; i < count; i++) {
    const s = document.createElement('div');
    s.className = 'skeleton';
    examCardsGrid.appendChild(s);
  }
}

function buildExamCard(exam) {
  const card = document.createElement('div');
  card.className = 'exam-card';

  const metaBits = [];
  if (exam.r_exam_taker) metaBits.push(`<span><i class="fa fa-building-columns"></i> ${escapeHtml(exam.r_exam_taker)}</span>`);
  if (exam.r_exam_year)  metaBits.push(`<span><i class="fa fa-calendar"></i> ${escapeHtml(exam.r_exam_year)}</span>`);

  card.innerHTML = `
    <div class="disc"><i class="fa fa-file-lines"></i></div>
    <h3>${escapeHtml(exam.r_exam_name)}</h3>
    <div class="exam-meta">${metaBits.join('')}</div>
    <div class="exam-footer">
      <span class="tag tag-green">${escapeHtml(exam.question_count)} question${exam.question_count == 1 ? '' : 's'}</span>
      <span class="view-link">View Questions <i class="fa fa-arrow-right"></i></span>
    </div>
  `;
  card.addEventListener('click', () => openExam(exam));
  return card;
}

function loadExams(reset = true) {
  if (reset) {
    examPage = 0;
    examCardsGrid.innerHTML = '';
    examEmptyState.style.display = 'none';
    showExamSkeletons(6);
  }
  examLoadMoreWrap.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'list_exams');
  fd.append('keyword', examKeywordInput.value.trim());
  fd.append('page', examPage);

  fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (reset) examCardsGrid.innerHTML = '';
      if (data.error) {
        examResultCount.textContent = data.error;
        return;
      }
      data.items.forEach(exam => examCardsGrid.appendChild(buildExamCard(exam)));
      examResultCount.innerHTML = `<strong>${data.total}</strong> exam${data.total === 1 ? '' : 's'} available`;
      examEmptyState.style.display = data.total === 0 ? 'block' : 'none';
      examLoadMoreWrap.style.display = data.has_more ? 'flex' : 'none';
    })
    .catch((err) => { 
      examResultCount.textContent = 'Could not load exams. Please try again.';
      console.error('Load exams error:', err);
    });
}

examLoadMoreBtn.addEventListener('click', () => { examPage += 1; loadExams(false); });
examKeywordInput.addEventListener('input', () => {
  clearTimeout(examKeywordTimer);
  examKeywordTimer = setTimeout(() => loadExams(), 400);
});
document.getElementById('clearExamSearchBtn').addEventListener('click', () => {
  examKeywordInput.value = '';
  loadExams();
});

/* ============================================================
   STEP 2 — QUESTIONS OF ONE EXAM
   ============================================================ */
const questionsView       = document.getElementById('questionsView');
const questionsHeading    = document.getElementById('questionsHeading');
const questionsSubheading = document.getElementById('questionsSubheading');
const subjectSelect       = document.getElementById('subjectSelect');
const chapterSelect       = document.getElementById('chapterSelect');
const topicSelect         = document.getElementById('topicSelect');
const keywordInput        = document.getElementById('keywordInput');
const cardsGrid            = document.getElementById('cardsGrid');
const resultCount          = document.getElementById('resultCount');
const emptyState           = document.getElementById('emptyState');
const loadMoreWrap         = document.getElementById('loadMoreWrap');
const loadMoreBtn          = document.getElementById('loadMoreBtn');

let currentExam = null;
let qPage = 0;
let qKeywordTimer = null;

function openExam(exam) {
  currentExam = exam;
  examsView.style.display = 'none';
  questionsView.style.display = 'block';

  questionsHeading.textContent = exam.r_exam_name;
  const subBits = [];
  if (exam.r_exam_taker) subBits.push(exam.r_exam_taker);
  if (exam.r_exam_year) subBits.push(exam.r_exam_year);
  subBits.push(`${exam.question_count} question${exam.question_count == 1 ? '' : 's'}`);
  questionsSubheading.textContent = subBits.join(' · ');

  // reset filters
  subjectSelect.value = '';
  chapterSelect.innerHTML = '<option value="">All Chapters</option>';
  chapterSelect.disabled = true;
  topicSelect.innerHTML = '<option value="">All Topics</option>';
  topicSelect.disabled = true;
  keywordInput.value = '';

  loadExamSubjects();
  loadExamQuestions(true);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.getElementById('backToExamsBtn').addEventListener('click', () => {
  questionsView.style.display = 'none';
  examsView.style.display = 'block';
  currentExam = null;
});

function loadExamSubjects() {
  const fd = new FormData();
  fd.append('action', 'get_exam_subjects');
  fd.append('referance_exam_id', currentExam.referance_exam_id);
  subjectSelect.innerHTML = '<option value="">All Subjects</option>';
  fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      (data.items || []).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        subjectSelect.appendChild(opt);
      });
    });
}

subjectSelect.addEventListener('change', () => {
  chapterSelect.innerHTML = '<option value="">All Chapters</option>';
  topicSelect.innerHTML = '<option value="">All Topics</option>';
  topicSelect.disabled = true;

  chapterSelect.disabled = false;
  const fd = new FormData();
  fd.append('action', 'get_exam_chapters');
  fd.append('referance_exam_id', currentExam.referance_exam_id);
  fd.append('subject_id', subjectSelect.value);
  fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      (data.items || []).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        chapterSelect.appendChild(opt);
      });
    });
  loadExamQuestions(true);
});

chapterSelect.addEventListener('change', () => {
  topicSelect.innerHTML = '<option value="">All Topics</option>';
  topicSelect.disabled = false;

  const fd = new FormData();
  fd.append('action', 'get_exam_topics');
  fd.append('referance_exam_id', currentExam.referance_exam_id);
  fd.append('subject_id', subjectSelect.value);
  fd.append('chapter_id', chapterSelect.value);
  fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      (data.items || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        topicSelect.appendChild(opt);
      });
    });
  loadExamQuestions(true);
});

topicSelect.addEventListener('change', () => loadExamQuestions(true));
keywordInput.addEventListener('input', () => {
  clearTimeout(qKeywordTimer);
  qKeywordTimer = setTimeout(() => loadExamQuestions(true), 400);
});
document.getElementById('clearFiltersBtn').addEventListener('click', () => {
  subjectSelect.value = '';
  chapterSelect.innerHTML = '<option value="">All Chapters</option>';
  chapterSelect.disabled = true;
  topicSelect.innerHTML = '<option value="">All Topics</option>';
  topicSelect.disabled = true;
  keywordInput.value = '';
  loadExamQuestions(true);
});

function showQuestionSkeletons(count) {
  cardsGrid.innerHTML = '';
  for (let i = 0; i < count; i++) {
    const s = document.createElement('div');
    s.className = 'skeleton';
    cardsGrid.appendChild(s);
  }
}

function buildQuestionCard(q) {
  const letters = ['a', 'b', 'c', 'd'];
  const optionsHtml = letters.map(letter => {
    const isCorrect = q.correct_option && q.correct_option.toLowerCase() === letter;
    return `<div class="option-item ${isCorrect ? 'is-correct' : ''}">
              <span class="opt-letter">${letter.toUpperCase()}</span>
              <span>${escapeHtml(q['option_' + letter])}</span>
            </div>`;
  }).join('');

  const tags = [q.subject_name, q.chapter_name, q.topic_name]
    .filter(Boolean)
    .map((t, i) => `<span class="tag ${i === 0 ? 'tag-red' : 'tag-green'}">${escapeHtml(t)}</span>`)
    .join('');

  const imageHtml = q.image_url
    ? `<img class="q-image" src="${escapeHtml(q.image_url)}" alt="Question image" loading="lazy">`
    : '';

  const explanationHtml = q.explanation
    ? `<div class="explanation-box"><strong>Explanation:</strong> ${escapeHtml(q.explanation)}</div>`
    : '';

  const card = document.createElement('div');
  card.className = 'q-card';
  card.innerHTML = `
    <div class="q-tags">${tags}</div>
    <div class="q-text">${escapeHtml(q.question)}</div>
    ${imageHtml}
    <div class="options-list">${optionsHtml}</div>
    ${explanationHtml}
    <div class="q-footer">
      <button class="reveal-btn"><i class="fa fa-eye"></i> Show Answer</button>
    </div>
  `;
  card.querySelector('.reveal-btn').addEventListener('click', () => card.classList.toggle('revealed'));
  return card;
}

function loadExamQuestions(reset = true) {
  if (!currentExam) return;
  if (reset) {
    qPage = 0;
    cardsGrid.innerHTML = '';
    emptyState.style.display = 'none';
    showQuestionSkeletons(6);
  }
  loadMoreWrap.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'exam_questions');
  fd.append('referance_exam_id', currentExam.referance_exam_id);
  fd.append('subject_id', subjectSelect.value);
  fd.append('chapter_id', chapterSelect.value);
  fd.append('topic_id', topicSelect.value);
  fd.append('keyword', keywordInput.value.trim());
  fd.append('page', qPage);

  fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (reset) cardsGrid.innerHTML = '';
      if (data.error) {
        resultCount.textContent = data.error;
        return;
      }
      data.items.forEach(q => cardsGrid.appendChild(buildQuestionCard(q)));
      resultCount.innerHTML = `<strong>${data.total}</strong> question${data.total === 1 ? '' : 's'} found`;
      emptyState.style.display = data.total === 0 ? 'block' : 'none';
      loadMoreWrap.style.display = data.has_more ? 'flex' : 'none';
    })
    .catch((err) => { 
      resultCount.textContent = 'Could not load questions. Please try again.';
      console.error('Load questions error:', err);
    });
}

loadMoreBtn.addEventListener('click', () => { qPage += 1; loadExamQuestions(false); });

/* initial load: start on the exam list */
loadExams();
</script>
</body>
</html>