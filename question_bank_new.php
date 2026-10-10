<?php
require_once 'db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'PDO not found in db.php']);
    exit();
}

@set_time_limit(60);

// ---------- Image URL helper ----------
function imgUrl($path) {
    if (empty($path)) return '';
    $path = trim($path);
    if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $path, $m)) {
        return 'https://lh3.googleusercontent.com/d/' . $m[1];
    }
    if (preg_match('#drive\.google\.com/(?:open|uc)\?(?:export=\w+&)?id=([a-zA-Z0-9_-]+)#', $path, $m)) {
        return 'https://lh3.googleusercontent.com/d/' . $m[1];
    }
    if (preg_match('#^(https?:)?//#i', $path)) return $path;
    if ($path[0] === '/') return $path;
    $path = preg_replace('#^(\.\./|\./)+#', '', $path);
    return '/' . ltrim($path, '/');
}
function normalizeRowImages(&$row) {
    foreach (['image_url','image_url_option_a','image_url_option_b','image_url_option_c','image_url_option_d'] as $k) {
        $row[$k] = (!empty($row[$k])) ? imgUrl($row[$k]) : '';
    }
}

// ---------- Duplicate key (NULL-safe): same topic + question + options + correct answer (+ images) ----------
function dupKeySql($alias = 'q') {
    $a = $alias;
    return "MD5(CONCAT_WS(CHAR(31),
        IFNULL($a.topic_id,'<NULL>'), IFNULL(TRIM($a.question),'<NULL>'),
        IFNULL($a.option_a,'<NULL>'), IFNULL($a.option_b,'<NULL>'),
        IFNULL($a.option_c,'<NULL>'), IFNULL($a.option_d,'<NULL>'),
        IFNULL($a.correct_option,'<NULL>'),
        IFNULL($a.image_url,''), IFNULL($a.image_url_option_a,''), IFNULL($a.image_url_option_b,''),
        IFNULL($a.image_url_option_c,''), IFNULL($a.image_url_option_d,'')))";
}

// ---------- JSON helpers ----------
function emit_json_and_exit($data) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $out = json_encode($data, $flags);
    if ($out === false) $out = json_encode(['ok'=>false,'error'=>'json_encode failed']);
    echo $out;
    exit();
}
register_shutdown_function(function () {
    if (!isset($_GET['fetch'])) return;
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok'=>false,'error'=>'Fatal: '.$error['message']]);
});

// ---------- AJAX endpoints ----------
if (isset($_GET['fetch'])) {
    ob_start();
    $type = $_GET['fetch'];
    $DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

    if ($type === 'levels') {
        try {
            // q_count = number of UNIQUE questions (duplicates across exams counted once)
            $sql = "
                SELECT l.id, l.name,
                       (SELECT COUNT(DISTINCT " . dupKeySql('q') . ")
                        FROM questions q
                        LEFT JOIN topics t   ON t.id = q.topic_id
                        LEFT JOIN chapters c ON c.id = t.chapter_id
                        LEFT JOIN subjects s ON s.id = c.subject_id
                        WHERE s.level_id = l.id
                          AND (q.exam_reference IS NULL OR q.exam_reference != 'MT')
                       ) AS q_count
                FROM levels l
                ORDER BY l.id ASC
            ";
            $stmt = $pdo->query($sql);
            emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->query("SELECT id, name, 0 AS q_count FROM levels ORDER BY id ASC");
                emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Throwable $e2) {
                emit_json_and_exit(['ok'=>false,'error'=>$e->getMessage()]);
            }
        }
    }

    if ($type === 'subject') {
        $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) emit_json_and_exit(['ok'=>true,'rows'=>[]]);
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE level_id=? ORDER BY name");
            $stmt->execute([$id]);
            emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { emit_json_and_exit(['ok'=>false,'error'=>$e->getMessage()]); }
    }

    if ($type === 'chapter') {
        $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) emit_json_and_exit(['ok'=>true,'rows'=>[]]);
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM chapters WHERE subject_id=? ORDER BY name");
            $stmt->execute([$id]);
            emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { emit_json_and_exit(['ok'=>false,'error'=>$e->getMessage()]); }
    }

    if ($type === 'topic') {
        $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) emit_json_and_exit(['ok'=>true,'rows'=>[]]);
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM topics WHERE chapter_id=? ORDER BY name");
            $stmt->execute([$id]);
            emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { emit_json_and_exit(['ok'=>false,'error'=>$e->getMessage()]); }
    }

    if ($type === 'questions') {
        $levelId   = !empty($_GET['level_id'])   ? (int)$_GET['level_id']   : null;
        $subjectId = !empty($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
        $chapterId = !empty($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : null;
        $topicId   = !empty($_GET['topic_id'])   ? (int)$_GET['topic_id']   : null;
        $keyword   = trim($_GET['q'] ?? '');
        $examRef   = trim($_GET['exam_reference'] ?? '');
        $pageSize  = 20;
        $offset    = max(0, (int)($_GET['offset'] ?? 0));

        $hasFilter = $levelId || $subjectId || $chapterId || $topicId || $keyword !== '' || $examRef !== '';
        if (!$hasFilter) {
            emit_json_and_exit([
                'ok' => true, 'rows' => [], 'total' => 0, 'offset' => 0, 'has_more' => false,
                'notice' => 'Apply a filter to see questions.',
            ]);
        }

        $DUP_KEY = dupKeySql('q');

        $joins = "LEFT JOIN topics t   ON t.id = q.topic_id
                  LEFT JOIN chapters c ON c.id = t.chapter_id
                  LEFT JOIN subjects s ON s.id = c.subject_id
                  LEFT JOIN levels l   ON l.id = s.level_id";

        $where  = " WHERE (q.exam_reference IS NULL OR q.exam_reference != 'MT')";
        $params = [];

        if ($topicId) {
            $where .= " AND q.topic_id = :topic_id";
            $params[':topic_id'] = $topicId;
        } elseif ($chapterId) {
            $where .= " AND t.chapter_id = :chapter_id";
            $params[':chapter_id'] = $chapterId;
        } elseif ($subjectId) {
            $where .= " AND c.subject_id = :subject_id";
            $params[':subject_id'] = $subjectId;
        } elseif ($levelId) {
            $where .= " AND s.level_id = :level_id";
            $params[':level_id'] = $levelId;
        }

        if ($keyword !== '') {
            $where .= " AND q.question LIKE :kw";
            $params[':kw'] = '%' . $keyword . '%';
        }

        // Exam reference filter matches the GROUP (any of its references),
        // so the question still shows ALL of its exam references.
        $having = '';
        if ($examRef !== '') {
            $having = " HAVING SUM(IFNULL(q.exam_reference,'') LIKE :ref) > 0";
            $params[':ref'] = '%' . $examRef . '%';
        }

        try {
            $pdo->exec("SET SESSION group_concat_max_len = 65535");

            // 1) Count UNIQUE questions
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (
                    SELECT 1
                    FROM questions q $joins $where
                    GROUP BY $DUP_KEY $having
                ) x");
            foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            // 2) One page of unique questions (group first, then fetch display columns for one representative row)
            $sql = "SELECT g.rep_id, g.dup_count, g.exam_ref_ids, g.ref_pairs, g.all_ids,
                           q.id, q.topic_id, q.question,
                           q.option_a, q.option_b, q.option_c, q.option_d,
                           q.correct_option, q.explanation,
                           q.image_url,
                           q.image_url_option_a, q.image_url_option_b,
                           q.image_url_option_c, q.image_url_option_d,
                           q.exam_year,
                           t.name AS topic_name, c.name AS chapter_name,
                           s.name AS subject_name, l.name AS level_name
                    FROM (
                        SELECT MIN(q.id) AS rep_id,
                               COUNT(*)  AS dup_count,
                               GROUP_CONCAT(DISTINCT q.referance_exam_id
                                            ORDER BY q.referance_exam_id SEPARATOR ',') AS exam_ref_ids,
                               GROUP_CONCAT(DISTINCT CONCAT(q.id, '~', IFNULL(TRIM(q.exam_reference),''))
                                            ORDER BY q.id SEPARATOR '||') AS ref_pairs,
                               GROUP_CONCAT(q.id ORDER BY q.id SEPARATOR ',') AS all_ids,
                               $DUP_KEY AS dup_key
                        FROM questions q $joins $where
                        GROUP BY dup_key $having
                        ORDER BY MAX(q.id) DESC
                        LIMIT $pageSize OFFSET $offset
                    ) g
                    JOIN questions q     ON q.id = g.rep_id
                    LEFT JOIN topics t   ON t.id = q.topic_id
                    LEFT JOIN chapters c ON c.id = t.chapter_id
                    LEFT JOIN subjects s ON s.id = c.subject_id
                    LEFT JOIN levels l   ON l.id = s.level_id
                    ORDER BY g.rep_id DESC";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                normalizeRowImages($r);

                // De-duplicated exam references; each keeps its original row ids
                $refs = [];
                foreach (array_filter(explode('||', (string)$r['ref_pairs'])) as $pair) {
                    [$rid, $name] = array_pad(explode('~', $pair, 2), 2, '');
                    $name = trim($name);
                    if ($name === '') continue;
                    $refs[$name]['name']  = $name;
                    $refs[$name]['ids'][] = (int)$rid;
                }
                $refs = array_values($refs);
                usort($refs, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                $r['exam_refs']    = $refs;                                   // [{name, ids:[..]}]
                $r['exam_ref_ids'] = array_values(array_filter(explode(',', (string)$r['exam_ref_ids'])));
                $r['all_ids']      = array_map('intval', explode(',', (string)$r['all_ids']));
                $r['edit_id']      = (int)$r['rep_id'];                       // original id to use for edit
                $r['dup_count']    = (int)$r['dup_count'];
                unset($r['ref_pairs']);
            }
            unset($r);
        } catch (Throwable $e) {
            emit_json_and_exit(['ok'=>false,'error'=>$e->getMessage()]);
        }

        $out = [
            'ok'       => true,
            'rows'     => $rows,
            'total'    => $total,
            'offset'   => $offset,
            'has_more' => ($offset + count($rows)) < $total,
        ];
        if ($DEBUG) $out['debug'] = ['params'=>$params, 'where'=>$where, 'having'=>$having, 'total'=>$total];
        emit_json_and_exit($out);
    }

    emit_json_and_exit(['ok'=>false,'error'=>'Unknown fetch type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Question Bank — Exam360</title>
<meta name="referrer" content="no-referrer">

<!-- Bengali fonts: Noto Serif Bengali (official, no clipping) + Noto Sans Bengali + Tiro Bangla -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Bengali:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500;600;700&family=Tiro+Bangla:ital@0;1&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:#ffffff; --surface:#f6f8f6; --card:#ffffff;
  --green:#00693e; --green-dp:#024a2c; --green-lt:#e7f2ea;
  --red:#f42a41; --gold:#d9a441;
  --ink:#12261c; --muted:#5e6f66; --border:#e3ece5;
  --radius:18px; --shadow:0 24px 60px -12px rgba(2,74,44,0.14);

  /* Bengali-first font stack — Noto Serif Bengali renders ১ ২ ৩ cleanly with no clipping */
  --font-bn: 'Noto Serif Bengali', 'Noto Sans Bengali', 'Tiro Bangla', 'Hind Siliguri', system-ui, sans-serif;
  --font-d:  'Tiro Bangla', 'Noto Serif Bengali', serif;
  --font-u:  'Inter', system-ui, sans-serif;
  --nav-h:72px;
}

html{scroll-behavior:smooth;}
body{font-family:var(--font-bn);background:var(--bg);color:var(--ink);line-height:1.7;overflow-x:hidden;}

::-webkit-scrollbar{width:8px;}
::-webkit-scrollbar-track{background:var(--surface);}
::-webkit-scrollbar-thumb{background:var(--green);border-radius:4px;}

.container{width:min(1200px,94%);margin:0 auto;}

.disc{--disc-size:46px;width:var(--disc-size);height:var(--disc-size);border-radius:14px;background:var(--green-lt);border:1px solid rgba(0,105,62,.16);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.disc i,.disc span.dnum{position:relative;z-index:2;color:var(--green-dp);}

.btn{display:inline-flex;align-items:center;gap:9px;padding:13px 28px;border-radius:50px;font-family:var(--font-u);font-size:.92rem;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;transition:all .25s;letter-spacing:.01em;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover{background:var(--green-dp);transform:translateY(-2px);box-shadow:0 10px 24px rgba(0,105,62,.28);}
.btn-outline{background:transparent;color:var(--green);border-color:var(--border);}
.btn-outline:hover{border-color:var(--green);background:var(--green-lt);}
.btn-sm{padding:9px 18px;font-size:.85rem;}

nav{position:fixed;top:0;width:100%;z-index:900;height:var(--nav-h);background:rgba(255,255,255,.9);backdrop-filter:blur(14px);border-bottom:1px solid var(--border);display:flex;align-items:center;}
.nav-inner{display:flex;align-items:center;justify-content:space-between;width:min(1200px,94%);margin:0 auto;}
.logo{display:flex;align-items:center;gap:12px;font-family:var(--font-d);font-size:1.5rem;font-weight:700;color:var(--ink);text-decoration:none;}
.logo span{color:var(--red);}
.nav-links{display:flex;align-items:center;gap:4px;}
.nav-links a{color:var(--muted);text-decoration:none;font-family:var(--font-u);font-size:.89rem;font-weight:500;padding:8px 15px;border-radius:8px;transition:all .2s;}
.nav-links a:hover,.nav-links a.active{color:var(--green-dp);background:var(--surface);}
.nav-actions{display:flex;gap:10px;align-items:center;}
.hamburger{display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:6px;}
.hamburger span{display:block;width:24px;height:2px;background:var(--ink);border-radius:2px;}

.hero{min-height:340px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;padding:calc(var(--nav-h) + 20px) 0 40px;text-align:center;}
.hero-disc-big{position:absolute;top:-10%;right:-10%;width:480px;height:480px;border-radius:50%;border:50px solid var(--green);opacity:.06;z-index:0;}
.hero-content{position:relative;z-index:2;width:min(860px,94%);margin:0 auto;}
.hero-badge{display:inline-flex;align-items:center;gap:9px;background:var(--green-lt);border:1px solid rgba(0,105,62,.18);color:var(--green-dp);padding:7px 18px;border-radius:50px;font-family:var(--font-u);font-size:.78rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;margin-bottom:22px;}
.hero-badge .dot{width:7px;height:7px;border-radius:50%;background:var(--red);}
.hero h1{font-family:var(--font-d);font-size:clamp(2rem,5vw,3.2rem);font-weight:400;line-height:1.28;margin-bottom:18px;color:var(--ink);}
.hero h1 em{font-style:normal;color:var(--green);}
.hero p{font-family:var(--font-bn);font-size:clamp(.95rem,2vw,1.05rem);color:var(--muted);max-width:760px;margin:0 auto 24px;line-height:1.9;padding-left:4px;}
.hero-search{max-width:640px;margin:0 auto;display:flex;gap:8px;background:#fff;border:1.5px solid var(--border);border-radius:50px;padding:6px 6px 6px 22px;box-shadow:0 14px 40px -18px rgba(2,74,44,.2);transition:border-color .2s;}
.hero-search:focus-within{border-color:var(--green);box-shadow:0 0 0 4px rgba(0,105,62,.08);}
.hero-search input{flex:1;border:none;outline:none;font-size:.98rem;padding:12px 0;background:transparent;color:var(--ink);font-family:var(--font-bn);}
.hero-search button{background:var(--green);color:#fff;border:none;border-radius:50px;padding:12px 26px;font-family:var(--font-u);font-weight:600;font-size:.9rem;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:7px;}
.hero-search button:hover{background:var(--green-dp);}

section{padding:60px 0;}
.section-label{display:flex;align-items:center;gap:10px;font-family:var(--font-u);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--red);margin-bottom:14px;justify-content:center;}
.section-label::before,.section-label::after{content:'';width:26px;height:2px;background:var(--red);}
h2.section-title{font-family:var(--font-d);font-size:clamp(1.6rem,3.5vw,2.2rem);font-weight:400;margin-bottom:14px;line-height:1.25;color:var(--ink);text-align:center;}
p.section-sub{font-family:var(--font-u);color:var(--muted);font-size:.98rem;max-width:540px;margin:0 auto 40px;text-align:center;}

#levelsView{background:var(--surface);}
.level-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;}
.level-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:26px 22px;cursor:pointer;transition:all .3s;text-align:center;}
.level-card:hover{transform:translateY(-4px);border-color:rgba(0,105,62,.28);box-shadow:var(--shadow);background:var(--green-lt);}
.level-card .icon{width:54px;height:54px;background:var(--green-lt);color:var(--green-dp);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 16px;transition:all .3s;}
.level-card:hover .icon{background:var(--green);color:#fff;}
.level-card h3{font-family:var(--font-d);font-weight:600;font-size:1.1rem;margin:0 0 8px;color:var(--ink);}
.level-card .count{font-family:var(--font-u);font-size:.82rem;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:6px;}
.level-card .count strong{color:var(--green);font-weight:700;font-size:.95rem;}

#questionsView{display:none;padding-top:calc(var(--nav-h) + 30px);}
#questionsView.active{display:block;}
.q-header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:28px;}
.q-header .back-btn{background:var(--surface);color:var(--green);border:1.5px solid var(--border);border-radius:50px;padding:10px 22px;font-family:var(--font-u);font-weight:600;font-size:.87rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .2s;}
.q-header .back-btn:hover{background:var(--green-lt);border-color:var(--green);}
.q-header h2{flex:1;margin:0;font-family:var(--font-d);font-weight:400;font-size:1.6rem;min-width:200px;color:var(--ink);}
.q-header .total-pill{background:var(--green);color:#fff;border-radius:50px;padding:8px 20px;font-family:var(--font-u);font-size:.82rem;font-weight:600;}

.filters{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:28px;box-shadow:0 8px 24px -16px rgba(2,74,44,.12);}
.filter-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}
.filter-field label{display:block;font-family:var(--font-u);font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
.filter-field input,.filter-field select{width:100%;border:1.5px solid var(--border);border-radius:12px;padding:11px 14px;font-size:.9rem;font-family:var(--font-bn);background:var(--surface);color:var(--ink);outline:none;transition:all .2s;appearance:none;}
.filter-field select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%235e6f66' d='M6 8L2 4h8z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:34px;}
.filter-field input:focus,.filter-field select:focus{border-color:var(--green);background-color:#fff;box-shadow:0 0 0 3px rgba(0,105,62,.1);}
.filter-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border);margin-top:16px;align-items:center;}
.filter-actions .auto-hint{font-family:var(--font-u);font-size:.78rem;color:var(--muted);margin-right:auto;display:inline-flex;align-items:center;gap:6px;}

.q-list{display:flex;flex-direction:column;gap:20px;overflow:visible;}
.q-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;transition:all .3s;overflow:visible;}
.q-card:hover{box-shadow:var(--shadow);border-color:rgba(0,105,62,.2);}
.q-num{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 12px;background:var(--green);color:#fff;border-radius:10px;font-family:var(--font-u);font-weight:700;font-size:.85rem;margin-right:12px;}
.q-tags{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;align-items:center;}
.q-tag{font-family:var(--font-u);font-size:.72rem;font-weight:600;padding:5px 12px;border-radius:50px;background:var(--surface);color:var(--muted);border:1px solid var(--border);}
.q-tag.ref{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.q-tag.year{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}

/* ── Bengali-safe text: Noto Serif Bengali renders numerals cleanly ── */
.q-text{
    font-family: 'Noto Serif Bengali', 'Noto Sans Bengali', 'Tiro Bangla', system-ui, sans-serif !important;
    font-size:1.05rem;
    font-weight:500;
    line-height:2;
    margin:0 0 20px;
    padding-top:4px;
    padding-left:6px;
    padding-right:2px;
    color:var(--ink);
    overflow:visible !important;
    word-wrap:break-word;
    word-break:break-word;
    letter-spacing:normal !important;
    min-height:1.8em;
    display:block;
    width:100%;
    max-width:100%;
    text-rendering:optimizeLegibility;
}
.q-image{display:block;max-width:100%;max-height:400px;width:auto;height:auto;border-radius:10px;margin:12px 0 4px;border:1px solid var(--border);background:#fff;object-fit:contain;}
.opt-image{display:block;max-width:100%;max-height:220px;width:auto;height:auto;border-radius:8px;margin-top:8px;border:1px solid var(--border);background:#fff;object-fit:contain;}

.options{display:grid;grid-template-columns:1fr;gap:10px;overflow:visible;}
@media (min-width:720px){.options{grid-template-columns:1fr 1fr;}}
.option{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:1.5px solid var(--border);border-radius:12px;background:var(--surface);transition:all .2s;cursor:pointer;user-select:none;overflow:visible;}
.option:hover{border-color:var(--green);background:var(--green-lt);}
.option .letter{flex:0 0 30px;height:30px;display:flex;align-items:center;justify-content:center;background:#fff;border:1.5px solid var(--border);border-radius:8px;font-family:var(--font-u);font-weight:700;font-size:.82rem;color:var(--muted);transition:all .2s;}
.option .opt-text{
    flex:1;
    font-family: 'Noto Serif Bengali', 'Noto Sans Bengali', 'Tiro Bangla', system-ui, sans-serif !important;
    font-size:.95rem;
    line-height:1.9;
    color:var(--ink);
    overflow:visible !important;
    word-wrap:break-word;
    word-break:break-word;
    padding-left:4px;
    letter-spacing:normal !important;
    min-height:1.7em;
    text-rendering:optimizeLegibility;
}
.option.correct{background:var(--green-lt);border-color:var(--green);}
.option.correct .letter{background:var(--green);color:#fff;border-color:var(--green);}
.option.wrong{background:#fef2f2;border-color:var(--red);}
.option.wrong .letter{background:var(--red);color:#fff;border-color:var(--red);}
.option.disabled{cursor:default;}
.option.disabled:hover{background:var(--surface);border-color:var(--border);}
.option.correct.disabled:hover{background:var(--green-lt);border-color:var(--green);}
.option.wrong.disabled:hover{background:#fef2f2;border-color:var(--red);}

/* Combined exam references (duplicates merged) */
.exam-refs{margin-top:18px;font-family:var(--font-u);font-size:.85rem;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.dup-note{color:var(--muted);}

.q-footer{display:flex;align-items:center;gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid var(--border);flex-wrap:wrap;}
.btn-reveal{background:transparent;color:var(--green);border:1.5px solid var(--green);border-radius:50px;padding:9px 20px;font-family:var(--font-u);font-weight:600;font-size:.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .2s;}
.btn-reveal:hover,.btn-reveal.active{background:var(--green);color:#fff;}

.explanation{
    margin-top:20px;
    padding:20px 24px;
    background:#fefce8;
    border-left:4px solid var(--gold);
    border-radius:12px;
    font-family: 'Noto Serif Bengali', 'Noto Sans Bengali', 'Tiro Bangla', system-ui, sans-serif !important;
    font-size:.95rem;
    line-height:1.9;
    display:none;
    color:var(--ink);
    overflow:visible !important;
    word-wrap:break-word;
    padding-left:26px;
    letter-spacing:normal !important;
}
.explanation.show{display:block;}
.explanation .label{display:inline-flex;align-items:center;gap:7px;font-family:var(--font-u);font-weight:700;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#a16207;margin-bottom:10px;}

.state{text-align:center;padding:60px 20px;color:var(--muted);font-family:var(--font-u);font-size:.95rem;}
.state i{font-size:2.5rem;display:block;margin-bottom:14px;opacity:.4;color:var(--green);}
.spinner{width:36px;height:36px;border:3px solid var(--green-lt);border-top-color:var(--green);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 14px;}
@keyframes spin{to{transform:rotate(360deg);}}

.load-more-wrap{text-align:center;padding:30px 0;}
.btn-load-more{background:#fff;color:var(--green);border:1.5px solid var(--green);border-radius:50px;padding:13px 32px;font-family:var(--font-u);font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px;}
.btn-load-more:hover{background:var(--green);color:#fff;transform:translateY(-2px);}
.btn-load-more:disabled{opacity:.6;cursor:wait;}

footer{background:#fff;border-top:1px solid var(--border);padding:64px 0 30px;margin-top:40px;}
.footer-grid{display:grid;grid-template-columns:1.8fr repeat(3,1fr);gap:40px;margin-bottom:48px;}
.footer-brand p{font-family:var(--font-u);color:var(--muted);font-size:.88rem;margin-top:16px;max-width:240px;line-height:1.7;}
.footer-col h4{font-family:var(--font-d);font-size:.98rem;font-weight:600;margin-bottom:18px;color:var(--ink);}
.footer-col a{display:block;font-family:var(--font-u);color:var(--muted);text-decoration:none;font-size:.86rem;margin-bottom:10px;transition:color .2s;}
.footer-col a:hover{color:var(--green);}
.footer-bottom{border-top:1px solid var(--border);padding-top:26px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.footer-bottom p{font-family:var(--font-u);font-size:.82rem;color:var(--muted);}
.social-links{display:flex;gap:12px;}
.social-links a{width:36px;height:36px;border-radius:10px;background:var(--surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.85rem;text-decoration:none;transition:all .2s;}
.social-links a:hover{background:var(--green);color:#fff;border-color:var(--green);}

.mobile-menu{display:none;position:fixed;top:var(--nav-h);left:0;width:100%;background:#fff;border-bottom:1px solid var(--border);padding:16px;z-index:890;flex-direction:column;gap:6px;box-shadow:var(--shadow);}
.mobile-menu.open{display:flex;}
.mobile-menu a{color:var(--muted);text-decoration:none;padding:11px 14px;border-radius:10px;font-size:.92rem;font-family:var(--font-u);transition:all .2s;}
.mobile-menu a:hover{color:var(--green-dp);background:var(--surface);}
.mobile-menu .mobile-btns{display:flex;gap:10px;margin-top:8px;}

@media (max-width:900px){.footer-grid{grid-template-columns:1fr 1fr;}}
@media (max-width:680px){
  .nav-links,.nav-actions{display:none;}
  .hamburger{display:flex;}
  .hero{min-height:300px;padding-top:calc(var(--nav-h) + 10px);padding-bottom:24px;}
  .hero-disc-big{width:300px;height:300px;}
  .footer-grid{grid-template-columns:1fr;}
  .footer-bottom{flex-direction:column;align-items:flex-start;}
  .q-card{padding:20px;}
  .q-text{padding-left:5px;}
  .options{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <a href="index.php" class="logo"><div class="disc"><span class="dnum">E</span></div>Exam<span>360</span></a>
    <div class="nav-links">
      <a href="index.php#features">Features</a>
      <a href="index.php#how-it-works">How It Works</a>
      <a href="index.php#exams">Exams</a>
      <a href="question_bank.php" class="active">Question Bank</a>
    </div>
    <div class="nav-actions">
      <a href="login.php" class="btn btn-outline btn-sm"><i class="fa fa-sign-in-alt"></i> Login</a>
      <a href="index.php#register" class="btn btn-primary btn-sm"><i class="fa fa-user-plus"></i> Register</a>
    </div>
    <button class="hamburger" onclick="toggleMenu()" aria-label="Menu"><span></span><span></span><span></span></button>
  </div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <a href="index.php#features" onclick="toggleMenu()">Features</a>
  <a href="index.php#how-it-works" onclick="toggleMenu()">How It Works</a>
  <a href="index.php#exams" onclick="toggleMenu()">Exams</a>
  <a href="question_bank.php" onclick="toggleMenu()">Question Bank</a>
  <div class="mobile-btns">
    <a href="login.php" class="btn btn-outline btn-sm" style="flex:1;justify-content:center"><i class="fa fa-sign-in-alt"></i> Login</a>
    <a href="index.php#register" class="btn btn-primary btn-sm" style="flex:1;justify-content:center"><i class="fa fa-user-plus"></i> Register</a>
  </div>
</div>

<section class="hero">
  <div class="hero-disc-big"></div>
  <div class="hero-content">
    <div class="hero-search">
      <input type="text" id="heroSearch" placeholder="যে কোন কীওয়ার্ড দিয়ে প্রশ্ন খুজুন...">
      <button type="button" id="heroSearchBtn"><i class="fa fa-search"></i> Search</button>
    </div>
  </div>
</section>

<div id="levelsView">
  <section>
    <div class="container">
      <div class="section-label"> </div> 
      <h2 class="section-title">প্রশ্নের ক্যাটাগরি বেছে নিন</em></h2> 
      <div class="level-grid" id="levelGrid">
        <div class="state" style="grid-column:1/-1;"><div class="spinner"></div>Loading levels...</div>
      </div>
    </div>
  </section>
</div>

<div id="questionsView">
  <div class="container">
    <div class="q-header">
      <button type="button" class="back-btn" id="backBtn"><i class="fa fa-arrow-left"></i> All Levels</button>
      <h2 id="currentLevelName">Questions</h2>
      <span class="total-pill" id="totalPill">0 found</span>
    </div>

    <div class="filters">
      <div class="filter-row">
        <div class="filter-field">
          <label>Keyword Search</label>
          <input type="text" id="fKeyword" placeholder="Type to search dynamically...">
        </div>
        <div class="filter-field">
          <label>Subject</label>
          <select id="fSubject"><option value="">All Subjects</option></select>
        </div>
        <div class="filter-field">
          <label>Chapter</label>
          <select id="fChapter"><option value="">All Chapters</option></select>
        </div>
        <div class="filter-field">
          <label>Topic</label>
          <select id="fTopic"><option value="">All Topics</option></select>
        </div>
        <div class="filter-field">
          <label>Exam Reference</label>
          <input type="text" id="fReference" placeholder="e.g. 41th BCS">
        </div>
      </div>
      <div class="filter-actions">
        <span class="auto-hint"><i class="fa fa-bolt"></i> Filters apply automatically</span>
        <button type="button" class="btn btn-outline btn-sm" id="clearFilters"><i class="fa fa-times"></i> Clear All</button>
        <button type="button" class="btn btn-primary btn-sm" id="applyFilters"><i class="fa fa-sync"></i> Refresh</button>
      </div>
    </div>

    <div class="q-list" id="qList">
      <div class="state">
        <i class="fa fa-filter"></i>
        Pick a level or use the filters above to see questions.
      </div>
    </div>

    <div class="load-more-wrap" id="loadMoreWrap" style="display:none;">
      <button type="button" class="btn-load-more" id="loadMoreBtn"><i class="fa fa-arrow-down"></i> Load More</button>
    </div>
  </div>
</div>

<footer>
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="index.php" class="logo"><div class="disc"><span class="dnum">E</span></div>Exam<span>360</span></a>
        <p>Bangladesh's leading MCQ exam preparation platform. Practice smarter, score higher.</p>
      </div>
      <div class="footer-col">
        <h4>Exams</h4>
        <a href="#">BCS Preliminary</a><a href="#">SSC / Dakhil</a><a href="#">HSC / Alim</a><a href="#">Bank Jobs</a><a href="#">Medical / MBBS</a>
      </div>
      <div class="footer-col">
        <h4>Platform</h4>
        <a href="index.php#features">Features</a><a href="index.php#how-it-works">How It Works</a><a href="question_bank.php">Question Bank</a><a href="#">Leaderboard</a><a href="#">Results</a>
      </div>
      <div class="footer-col">
        <h4>Account</h4>
        <a href="login.php">Login</a>
        <a href="index.php#register">Register</a>
        <a href="#">Contact Us</a><a href="#">Privacy Policy</a><a href="#">Terms of Use</a>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> Exam360. All rights reserved. Built with ❤️ for Bangladesh.</p>
      <div class="social-links">
        <a href="#"><i class="fab fa-facebook-f"></i></a>
        <a href="#"><i class="fab fa-youtube"></i></a>
        <a href="#"><i class="fab fa-telegram-plane"></i></a>
        <a href="#"><i class="fab fa-whatsapp"></i></a>
      </div>
    </div>
  </div>
</footer>

<script>
(function(){
    const PAGE_URL = 'question_bank.php';

    function extractLeadingJson(text){
        let depth=0,inString=false,escape=false,started=false;
        for(let i=0;i<text.length;i++){
            const ch=text[i];
            if(escape){escape=false;continue;}
            if(ch==='\\' && inString){escape=true;continue;}
            if(ch==='"'){inString=!inString;continue;}
            if(inString) continue;
            if(ch==='{'||ch==='['){depth++;started=true;}
            else if(ch==='}'||ch===']'){depth--;if(started && depth===0) return text.slice(0,i+1);}
        }
        return text;
    }
    async function api(params){
        const url = PAGE_URL + '?' + new URLSearchParams(params).toString();
        const res = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const raw = await res.text();
        try { return JSON.parse(extractLeadingJson(raw.trim())); }
        catch(e){ console.error('Bad JSON:', raw.substring(0,300)); throw new Error('Response was not valid JSON.'); }
    }

    const $ = sel => document.querySelector(sel);

    const state = {
        levelId:null, levelName:'',
        subjectId:'', chapterId:'', topicId:'',
        keyword:'', reference:'',
        offset:0, total:0, loading:false,
    };

    let inputTimer = null;
    function debouncedLoad(delay) {
        clearTimeout(inputTimer);
        inputTimer = setTimeout(() => loadQuestions(true), delay || 350);
    }

    async function loadLevels(){
        try {
            const resp = await api({fetch:'levels'});
            if (resp.ok === false) {
                $('#levelGrid').innerHTML = `<div class="state" style="grid-column:1/-1;max-width:600px;margin:0 auto;">
                    <i class="fa fa-exclamation-triangle" style="color:#f42a41;"></i>
                    <div style="font-weight:600;margin-bottom:8px;">Server error:</div>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:14px 18px;border-radius:10px;text-align:left;font-size:.85rem;word-break:break-word;">
                        ${escapeHtml(resp.error || 'Unknown error')}
                    </div>
                </div>`;
                return;
            }
            renderLevels(resp.rows || []);
        } catch(e){
            $('#levelGrid').innerHTML = `<div class="state" style="grid-column:1/-1;">
                <i class="fa fa-exclamation-triangle" style="color:#f42a41;"></i>
                Could not reach the server: ${escapeHtml(e.message)}
            </div>`;
        }
    }

    function renderLevels(rows){
        const grid = $('#levelGrid');
        const withQuestions = rows.filter(lv => (lv.q_count || 0) > 0);
        const visible = withQuestions.length ? withQuestions : rows;

        if(!visible.length){
            grid.innerHTML = `<div class="state" style="grid-column:1/-1;"><i class="fa fa-inbox"></i>No levels available yet.</div>`;
            return;
        }
        grid.innerHTML = visible.map(lv => `
            <div class="level-card" data-id="${lv.id}" data-name="${escapeAttr(lv.name)}">
                <div class="icon"><i class="fa fa-graduation-cap"></i></div>
                <h3>${escapeHtml(lv.name)}</h3>
                <div class="count"><i class="fa fa-question-circle"></i> <strong>${lv.q_count || 0}</strong> questions</div>
            </div>
        `).join('');
    }

    function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
    function escapeAttr(s){ return escapeHtml(s).replace(/"/g,'&quot;'); }

    function resolveImg(path) {
        if (!path) return '';
        path = String(path).trim();
        if (!path) return '';

        let m = path.match(/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (m) return 'https://lh3.googleusercontent.com/d/' + m[1];
        m = path.match(/drive\.google\.com\/(?:open|uc)\?(?:export=\w+&)?id=([a-zA-Z0-9_-]+)/);
        if (m) return 'https://lh3.googleusercontent.com/d/' + m[1];

        if (/^(https?:)?\/\//i.test(path)) return path;
        if (path.charAt(0) === '/') return path;
        path = path.replace(/^(\.\.\/|\.\/)+/, '');
        return '/' + path;
    }

    async function openLevel(id, name){
        state.levelId = id;
        state.levelName = name;
        state.subjectId=''; state.chapterId=''; state.topicId='';
        state.keyword=''; state.reference=''; state.offset=0;

        $('#currentLevelName').textContent = name;
        $('#levelsView').style.display = 'none';
        $('#questionsView').classList.add('active');
        window.scrollTo({top: 0, behavior: 'smooth'});

        $('#fKeyword').value = '';
        $('#fReference').value = '';
        $('#fSubject').innerHTML = '<option value="">All Subjects</option>';
        $('#fChapter').innerHTML = '<option value="">All Chapters</option>';
        $('#fTopic').innerHTML   = '<option value="">All Topics</option>';

        try {
            const resp = await api({fetch:'subject', id: id});
            (resp.rows||[]).forEach(s => {
                $('#fSubject').insertAdjacentHTML('beforeend',
                    `<option value="${s.id}">${escapeHtml(s.name)}</option>`);
            });
        } catch(e){ console.error(e); }

        loadQuestions(true);
    }

    $('#fSubject').addEventListener('change', async function(){
        state.subjectId = this.value;
        state.chapterId=''; state.topicId='';
        $('#fChapter').innerHTML = '<option value="">All Chapters</option>';
        $('#fTopic').innerHTML   = '<option value="">All Topics</option>';
        if(this.value){
            try {
                const resp = await api({fetch:'chapter', id: this.value});
                (resp.rows||[]).forEach(c => {
                    $('#fChapter').insertAdjacentHTML('beforeend',
                        `<option value="${c.id}">${escapeHtml(c.name)}</option>`);
                });
            } catch(e){ console.error(e); }
        }
        loadQuestions(true);
    });
    $('#fChapter').addEventListener('change', async function(){
        state.chapterId = this.value;
        state.topicId='';
        $('#fTopic').innerHTML = '<option value="">All Topics</option>';
        if(this.value){
            try {
                const resp = await api({fetch:'topic', id: this.value});
                (resp.rows||[]).forEach(t => {
                    $('#fTopic').insertAdjacentHTML('beforeend',
                        `<option value="${t.id}">${escapeHtml(t.name)}</option>`);
                });
            } catch(e){ console.error(e); }
        }
        loadQuestions(true);
    });
    $('#fTopic').addEventListener('change', function(){
        state.topicId = this.value;
        loadQuestions(true);
    });

    $('#fKeyword').addEventListener('input', function(){
        state.keyword = this.value.trim();
        debouncedLoad(350);
    });
    $('#fReference').addEventListener('input', function(){
        state.reference = this.value.trim();
        debouncedLoad(350);
    });

    $('#applyFilters').addEventListener('click', () => {
        state.keyword = $('#fKeyword').value.trim();
        state.reference = $('#fReference').value.trim();
        loadQuestions(true);
    });

    $('#clearFilters').addEventListener('click', () => {
        $('#fKeyword').value = '';
        $('#fReference').value = '';
        $('#fSubject').value = ''; state.subjectId='';
        $('#fChapter').value = ''; state.chapterId='';
        $('#fTopic').value = ''; state.topicId='';
        state.keyword=''; state.reference='';
        loadQuestions(true);
    });

    async function loadQuestions(reset){
        if(state.loading) return;
        state.loading = true;

        if(reset){
            state.offset = 0;
            $('#qList').innerHTML = `<div class="state"><div class="spinner"></div>Loading questions...</div>`;
            $('#loadMoreWrap').style.display = 'none';
        } else {
            const btn = $('#loadMoreBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading...';
        }

        const params = {
            fetch: 'questions',
            level_id: state.levelId || '',
            subject_id: state.subjectId || '',
            chapter_id: state.chapterId || '',
            topic_id: state.topicId || '',
            q: state.keyword || '',
            exam_reference: state.reference || '',
            offset: state.offset,
        };

        try {
            const resp = await api(params);
            if(resp.ok === false){
                $('#qList').innerHTML = `<div class="state">
                    <i class="fa fa-exclamation-triangle" style="color:#f42a41;"></i>
                    Server error: ${escapeHtml(resp.error || 'Unknown')}
                </div>`;
                $('#totalPill').textContent = '0 found';
                return;
            }

            state.total = resp.total || 0;
            const rows = resp.rows || [];
            renderQuestions(rows, !reset, state.offset);
            state.offset += rows.length;

            $('#totalPill').textContent = state.total + ' found';

            if(resp.has_more){
                $('#loadMoreWrap').style.display = 'block';
                $('#loadMoreBtn').disabled = false;
                $('#loadMoreBtn').innerHTML = `<i class="fa fa-arrow-down"></i> Load More (${state.total - state.offset} remaining)`;
            } else {
                $('#loadMoreWrap').style.display = 'none';
            }
        } catch(e){
            if(reset){
                $('#qList').innerHTML = `<div class="state">
                    <i class="fa fa-exclamation-triangle" style="color:#f42a41;"></i>
                    Could not load questions: ${escapeHtml(e.message)}
                </div>`;
                $('#totalPill').textContent = '0 found';
            }
        } finally {
            state.loading = false;
        }
    }

    function renderQuestions(rows, append, startIndex){
        const list = $('#qList');
        if(!append){
            if(!rows.length){
                list.innerHTML = `<div class="state"><i class="fa fa-search"></i>No questions match your filters. Try adjusting them.</div>`;
                return;
            }
            list.innerHTML = '';
        }
        rows.forEach((q, i) => {
            list.insertAdjacentHTML('beforeend', buildCard(q, startIndex + i + 1));
        });
    }

    function buildCard(q, num){
        const tags = [];
        if(q.subject_name) tags.push(`<span class="q-tag">${escapeHtml(q.subject_name)}</span>`);
        if(q.chapter_name) tags.push(`<span class="q-tag">${escapeHtml(q.chapter_name)}</span>`);
        if(q.topic_name)   tags.push(`<span class="q-tag">${escapeHtml(q.topic_name)}</span>`);
        if(q.exam_year)    tags.push(`<span class="q-tag year"><i class="fa fa-calendar"></i> ${escapeHtml(q.exam_year)}</span>`);

        // Combined, de-duplicated exam references (one badge each, beside each other)
        const refBadges = (q.exam_refs || []).map(r =>
            `<span class="q-tag ref" data-row-ids="${escapeAttr((r.ids||[]).join(','))}"><i class="fa fa-tag"></i> ${escapeHtml(r.name)}</span>`
        ).join('');

        const qText = q.question ? escapeHtml(q.question) : '';
        const qImgSrc = resolveImg(q.image_url);
        const qImg = qImgSrc
            ? `<img src="${escapeAttr(qImgSrc)}" alt="Question image" class="q-image" loading="lazy" referrerpolicy="no-referrer" crossorigin="anonymous" onerror="this.style.display='none'">`
            : '';
        const questionBlock = (qText || qImg)
            ? `<div class="q-text">${qText}${qImg}</div>`
            : '';

        const opts = [
            ['A','option_a','image_url_option_a'],
            ['B','option_b','image_url_option_b'],
            ['C','option_c','image_url_option_c'],
            ['D','option_d','image_url_option_d'],
        ].map(([letter, key, imgKey]) => {
            const text = q[key] ? escapeHtml(q[key]) : '';
            const imgSrc = resolveImg(q[imgKey]);
            const img = imgSrc
                ? `<img src="${escapeAttr(imgSrc)}" alt="Option ${letter} image" class="opt-image" loading="lazy" referrerpolicy="no-referrer" crossorigin="anonymous" onerror="this.style.display='none'">`
                : '';
            if (!text && !img) return '';
            return `<div class="option" data-letter="${letter}">
                <div class="letter">${letter}</div>
                <div class="opt-text">${text}${img}</div>
            </div>`;
        }).join('');

        const hasExplanation = q.explanation && String(q.explanation).trim() !== '';

        return `
            <div class="q-card" data-correct="${escapeAttr(q.correct_option||'')}"
                 data-edit-id="${q.edit_id}" data-all-ids="${escapeAttr((q.all_ids||[]).join(','))}">
                <div class="q-tags">
                    <span class="q-num">Q${num}</span>
                    ${tags.join('')}
                </div>
                ${questionBlock}
                <div class="options">${opts}</div>
                ${refBadges ? `<div class="exam-refs"><strong>Exam References:</strong> ${refBadges}
                    ${q.dup_count > 1 ? `<small class="dup-note">(${q.dup_count} records merged)</small>` : ''}</div>` : ''}
                <div class="q-footer">
                    ${hasExplanation ? `<button type="button" class="btn-reveal toggle-explanation"><i class="fa fa-lightbulb"></i> Show Explanation</button>` : ''}
                    <button type="button" class="btn-reveal reveal-answer" style="margin-left:auto;"><i class="fa fa-eye"></i> Reveal Answer</button>
                </div>
                ${hasExplanation ? `<div class="explanation"><div class="label"><i class="fa fa-lightbulb"></i> Explanation</div>${escapeHtml(q.explanation)}</div>` : ''}
            </div>
        `;
    }

    $('#qList').addEventListener('click', e => {
        const card = e.target.closest('.q-card');
        if(!card) return;
        if(e.target.tagName === 'IMG') return;

        const option = e.target.closest('.option');
        if(option && !option.classList.contains('disabled')){
            const correct = card.dataset.correct;
            const chosen = option.dataset.letter;
            card.querySelectorAll('.option').forEach(o => {
                o.classList.add('disabled');
                if(o.dataset.letter === correct) o.classList.add('correct');
                else if(o.dataset.letter === chosen) o.classList.add('wrong');
            });
            return;
        }

        if(e.target.closest('.reveal-answer')){
            const correct = card.dataset.correct;
            card.querySelectorAll('.option').forEach(o => {
                o.classList.add('disabled');
                if(o.dataset.letter === correct) o.classList.add('correct');
            });
            return;
        }

        const toggleBtn = e.target.closest('.toggle-explanation');
        if(toggleBtn){
            const exp = card.querySelector('.explanation');
            if(exp){
                exp.classList.toggle('show');
                toggleBtn.classList.toggle('active');
                toggleBtn.innerHTML = exp.classList.contains('show')
                    ? '<i class="fa fa-lightbulb"></i> Hide Explanation'
                    : '<i class="fa fa-lightbulb"></i> Show Explanation';
            }
        }
    });

    $('#loadMoreBtn').addEventListener('click', () => loadQuestions(false));

    $('#backBtn').addEventListener('click', () => {
        $('#questionsView').classList.remove('active');
        $('#levelsView').style.display = '';
        window.scrollTo({top: 0, behavior: 'smooth'});
    });

    $('#heroSearchBtn').addEventListener('click', heroSearch);
    $('#heroSearch').addEventListener('keydown', e => { if(e.key === 'Enter') heroSearch(); });

    function heroSearch(){
        const kw = $('#heroSearch').value.trim();
        if(!kw) return;
        state.levelId = null;
        state.levelName = 'All Questions';
        state.subjectId=''; state.chapterId=''; state.topicId='';
        state.keyword = kw; state.reference = ''; state.offset = 0;
        $('#currentLevelName').textContent = 'Search: "' + kw + '"';
        $('#levelsView').style.display = 'none';
        $('#questionsView').classList.add('active');
        $('#fKeyword').value = kw;
        $('#fReference').value = '';
        $('#fSubject').innerHTML = '<option value="">All Subjects</option>';
        $('#fChapter').innerHTML = '<option value="">All Chapters</option>';
        $('#fTopic').innerHTML   = '<option value="">All Topics</option>';
        window.scrollTo({top: 0, behavior: 'smooth'});
        loadQuestions(true);
    }

    loadLevels();

    $('#levelGrid').addEventListener('click', e => {
        const card = e.target.closest('.level-card');
        if(!card) return;
        openLevel(card.dataset.id, card.dataset.name);
    });
})();

function toggleMenu() {
    document.getElementById('mobileMenu').classList.toggle('open');
}
window.addEventListener('scroll', () => {
    document.querySelector('nav').style.boxShadow =
        window.scrollY > 10 ? '0 4px 30px rgba(2,38,23,.08)' : 'none';
});
</script>
</body>
</html>