<?php
require_once 'db.php';

/* ---------- Site Logo ---------- */
$siteLogo = 'logo.png';

/* ---------- Hidden constants ---------- */
$HIDDEN_REF_EXAM_ID = 145;
$HIDDEN_REF_NAME    = 'MT';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src * data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'");
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'PDO not found in db.php']);
    exit();
}
@set_time_limit(60);

function imgUrl($path) {
    if (empty($path)) return '';
    $path = trim($path);
    if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $path, $m)) return 'https://lh3.googleusercontent.com/d/' . $m[1];
    if (preg_match('#drive\.google\.com/(?:open|uc)\?(?:export=\w+&)?id=([a-zA-Z0-9_-]+)#', $path, $m)) return 'https://lh3.googleusercontent.com/d/' . $m[1];
    if (preg_match('#^(https?:)?//#i', $path)) return $path;
    if ($path[0] === '/') return $path;
    $path = preg_replace('#^(\.\./|\./)+#', '', $path);
    return '/' . ltrim($path, '/');
}
function normalizeRowImages(&$row) {
    $cols = [
        'image_url',
        'image_url_option_a', 'image_url_option_b', 'image_url_option_c', 'image_url_option_d',
        'explanation_image_url'   /* ← NEW */
    ];
    foreach ($cols as $k) {
        $row[$k] = (!empty($row[$k])) ? imgUrl($row[$k]) : '';
    }
}
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
function qb_dir() {
    $dir = __DIR__ . '/qb_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}
function qb_fail($e) {
    error_log('question_bank: ' . $e->getMessage());
    return 'Server error. Please try again.';
}
function qb_rate_limit($max = 120, $window = 60) {
    $dir = qb_dir();
    $f   = $dir . '/rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
    $now = time();
    if (mt_rand(1, 100) === 1) {
        foreach ((array)@glob($dir . '/rl_*.json') as $old) { if (@filemtime($old) < $now - 3600) @unlink($old); }
    }
    $fh = @fopen($f, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $j = json_decode((string)stream_get_contents($fh), true);
    $d = (is_array($j) && isset($j['t'], $j['n']) && $now - $j['t'] < $window) ? $j : ['t' => $now, 'n' => 0];
    $d['n']++;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN); fclose($fh);
    if ($d['n'] > $max) {
        http_response_code(429);
        emit_json_and_exit(['ok'=>false,'error'=>'Too many requests. Please wait a minute and try again.']);
    }
}
register_shutdown_function(function () {
    if (!isset($_GET['fetch'])) return;
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    error_log('question_bank fatal: ' . $error['message']);
    echo json_encode(['ok'=>false,'error'=>'Server error. Please try again.']);
});

if (isset($_GET['fetch'])) {
    ob_start();
    $type = $_GET['fetch'];
    qb_rate_limit(120, 60);

    $EXCL_BY_ID   = "(q.referance_exam_id IS NULL OR q.referance_exam_id != {$HIDDEN_REF_EXAM_ID})";
    $EXCL_BY_NAME = "(re.r_exam_name IS NULL OR UPPER(TRIM(re.r_exam_name)) != '" . $HIDDEN_REF_NAME . "')";
    $EXCL_FREE_TX = "(q.exam_reference IS NULL OR TRIM(q.exam_reference) = '' OR UPPER(TRIM(q.exam_reference)) != '" . $HIDDEN_REF_NAME . "')";
    $EXCL_ALL     = $EXCL_BY_ID . " AND " . $EXCL_BY_NAME . " AND " . $EXCL_FREE_TX;

    /* ============================================================
       LEVELS
       ============================================================ */
    if ($type === 'levels') {
        $cacheFile = qb_dir() . '/levels.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached)) emit_json_and_exit(['ok'=>true,'rows'=>$cached]);
        }
        try {
            $sql = "SELECT l.id, l.name,
                       (SELECT COUNT(*) FROM (
                            SELECT 1
                            FROM questions q
                            LEFT JOIN topics t   ON t.id = q.topic_id
                            LEFT JOIN chapters c ON c.id = t.chapter_id
                            LEFT JOIN subjects s ON s.id = c.subject_id
                            LEFT JOIN referance_exams re ON re.referance_exam_id = q.referance_exam_id
                            WHERE s.level_id = l.id
                              AND q.DELETE_DATE IS NULL
                              AND {$EXCL_ALL}
                            GROUP BY MD5(CONCAT_WS('|', TRIM(q.question), TRIM(q.option_a), TRIM(q.option_b),
                                                   TRIM(q.option_c), TRIM(q.option_d), q.correct_option))
                       ) u) AS q_count
                    FROM levels l ORDER BY l.id ASC";
            $lvRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            @file_put_contents($cacheFile, json_encode($lvRows, JSON_UNESCAPED_UNICODE));
            emit_json_and_exit(['ok'=>true,'rows'=>$lvRows]);
        } catch (Throwable $e) {
            try {
                $sql = "SELECT l.id, l.name,
                           (SELECT COUNT(*) FROM questions q
                            LEFT JOIN topics t   ON t.id = q.topic_id
                            LEFT JOIN chapters c ON c.id = t.chapter_id
                            LEFT JOIN subjects s ON s.id = c.subject_id
                            LEFT JOIN referance_exams re ON re.referance_exam_id = q.referance_exam_id
                            WHERE s.level_id = l.id
                              AND q.DELETE_DATE IS NULL
                              AND {$EXCL_ALL}) AS q_count
                        FROM levels l ORDER BY l.id ASC";
                emit_json_and_exit(['ok'=>true,'rows'=>$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Throwable $e2) {
                try {
                    emit_json_and_exit(['ok'=>true,'rows'=>$pdo->query("SELECT id, name, 0 AS q_count FROM levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Throwable $e3) { emit_json_and_exit(['ok'=>false,'error'=>qb_fail($e)]); }
            }
        }
    }

    /* ============================================================
       SIMPLE: subject / chapter / topic
       ============================================================ */
    $simple = ['subject'=>['subjects','level_id'], 'chapter'=>['chapters','subject_id'], 'topic'=>['topics','chapter_id']];
    if (isset($simple[$type])) {
        $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) emit_json_and_exit(['ok'=>true,'rows'=>[]]);
        try {
            [$tbl, $col] = $simple[$type];
            if ($type === 'topic') {
                $stmt = $pdo->prepare("SELECT t.id, t.name FROM topics t
                    WHERE t.chapter_id = ?
                      AND EXISTS (SELECT 1 FROM questions q
                                  LEFT JOIN referance_exams re ON re.referance_exam_id = q.referance_exam_id
                                  WHERE q.topic_id = t.id
                                    AND q.DELETE_DATE IS NULL
                                    AND {$EXCL_ALL})
                    ORDER BY t.name");
            } else {
                $stmt = $pdo->prepare("SELECT id, name FROM $tbl WHERE $col=? ORDER BY name");
            }
            $stmt->execute([$id]);
            emit_json_and_exit(['ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { emit_json_and_exit(['ok'=>false,'error'=>qb_fail($e)]); }
    }

    /* ============================================================
       QUESTIONS
       ============================================================ */
    if ($type === 'questions') {
        $levelId   = !empty($_GET['level_id'])   ? (int)$_GET['level_id']   : null;
        $subjectId = !empty($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
        $chapterId = !empty($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : null;
        $topicId   = !empty($_GET['topic_id'])   ? (int)$_GET['topic_id']   : null;
        $keyword   = trim($_GET['q'] ?? '');
        $examRef   = trim($_GET['exam_reference'] ?? '');
        $pageSize  = 20;
        $offset    = min(5000, max(0, (int)($_GET['offset'] ?? 0)));
        if ($keyword !== '' && mb_strlen($keyword) < 2) {
            emit_json_and_exit(['ok'=>true,'rows'=>[],'total'=>0,'offset'=>0,'has_more'=>false,'notice'=>'Type at least 2 characters.']);
        }

        if (!($levelId || $subjectId || $chapterId || $topicId || $keyword !== '' || $examRef !== '')) {
            emit_json_and_exit(['ok'=>true,'rows'=>[],'total'=>0,'offset'=>0,'has_more'=>false,'notice'=>'Apply a filter to see questions.']);
        }

        $joins = "LEFT JOIN topics t   ON t.id = q.topic_id
                  LEFT JOIN chapters c ON c.id = t.chapter_id
                  LEFT JOIN subjects s ON s.id = c.subject_id
                  LEFT JOIN levels l   ON l.id = s.level_id
                  LEFT JOIN referance_exams re ON re.referance_exam_id = q.referance_exam_id";

        $where  = " WHERE q.DELETE_DATE IS NULL AND {$EXCL_ALL}";
        $params = [];

        if ($topicId)        { $where .= " AND q.topic_id = :topic_id";     $params[':topic_id'] = $topicId; }
        elseif ($chapterId)  { $where .= " AND t.chapter_id = :chapter_id"; $params[':chapter_id'] = $chapterId; }
        elseif ($subjectId)  { $where .= " AND c.subject_id = :subject_id"; $params[':subject_id'] = $subjectId; }
        elseif ($levelId)    { $where .= " AND s.level_id = :level_id";     $params[':level_id'] = $levelId; }

        if ($keyword !== '') { $where .= " AND q.question LIKE :kw";        $params[':kw'] = '%' . $keyword . '%'; }

        $having = '';
        if ($examRef !== '') {
            $having = " HAVING SUM(
                          CASE 
                              WHEN q.referance_exam_id = {$HIDDEN_REF_EXAM_ID} THEN 0
                              WHEN UPPER(TRIM(re.r_exam_name)) = '" . $HIDDEN_REF_NAME . "' THEN 0
                              ELSE re.r_exam_name LIKE :ref 
                          END
                      ) > 0";
            $params[':ref'] = '%' . $examRef . '%';
        }

        $groupBy = " GROUP BY q.question_hash";

        try {
            $pdo->exec("SET SESSION group_concat_max_len = 100000");
            if ($offset === 0) {
                $pdo->exec("UPDATE questions SET question_hash = MD5(CONCAT_WS('|', TRIM(question), TRIM(option_a), TRIM(option_b), TRIM(option_c), TRIM(option_d), correct_option)) WHERE question_hash IS NULL");
            }

            if ($offset > 0 && !empty($_GET['total'])) {
                $total = (int)$_GET['total'];
            } else {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM questions q $joins $where $groupBy $having) x");
                foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
                $countStmt->execute();
                $total = (int)$countStmt->fetchColumn();
            }

            $sql = "SELECT MAX(q.id) AS id,
                           MAX(q.question) AS question,
                           MAX(q.option_a) AS option_a, MAX(q.option_b) AS option_b,
                           MAX(q.option_c) AS option_c, MAX(q.option_d) AS option_d,
                           MAX(q.correct_option) AS correct_option,
                           MAX(q.explanation) AS explanation,
                           MAX(q.explanation_image_url) AS explanation_image_url,
                           MAX(q.image_url) AS image_url,
                           MAX(q.image_url_option_a) AS image_url_option_a,
                           MAX(q.image_url_option_b) AS image_url_option_b,
                           MAX(q.image_url_option_c) AS image_url_option_c,
                           MAX(q.image_url_option_d) AS image_url_option_d,
                           COUNT(*) AS appear_count,
                           GROUP_CONCAT(DISTINCT
                               CASE 
                                   WHEN q.referance_exam_id = {$HIDDEN_REF_EXAM_ID} THEN NULL
                                   WHEN UPPER(TRIM(re.r_exam_name)) = '" . $HIDDEN_REF_NAME . "' THEN NULL
                                   WHEN TRIM(re.r_exam_name) = '' THEN NULL
                                   WHEN re.r_exam_name IS NULL THEN NULL
                                   WHEN UPPER(TRIM(q.exam_reference)) = '" . $HIDDEN_REF_NAME . "' THEN NULL
                                   ELSE TRIM(re.r_exam_name)
                               END
                               ORDER BY re.r_exam_name
                               SEPARATOR ', ') AS exam_refs,
                           MAX(t.name) AS topic_name, MAX(c.name) AS chapter_name,
                           MAX(s.name) AS subject_name, MAX(l.name) AS level_name
                    FROM questions q $joins $where $groupBy $having
                    ORDER BY MAX(q.id) DESC LIMIT $pageSize OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) normalizeRowImages($r);
            unset($r);
        } catch (Throwable $e) { emit_json_and_exit(['ok'=>false,'error'=>qb_fail($e)]); }

        $out = ['ok'=>true,'rows'=>$rows,'total'=>$total,'offset'=>$offset,'has_more'=>($offset + count($rows)) < $total];
        emit_json_and_exit($out);
    }

    emit_json_and_exit(['ok'=>false,'error'=>'Unknown fetch type']);
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="referrer" content="no-referrer">
<title>Question Bank — Exam360</title>

<!-- Favicon -->
<link rel="icon" type="image/png" href="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>">

<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#f6f8f7;--card:#fff;--ink:#14231b;--mut:#61726a;--line:#e1e8e4;--g:#00693e;--gl:#e4f1e9;--on:#fff;--bad:#e5384d;--badl:#fdecee;--hint:#fff8e1;--r:14px;
--f:system-ui,-apple-system,'Segoe UI','Nirmala UI','Noto Sans Bengali','Kohinoor Bangla',sans-serif}
@media(prefers-color-scheme:dark){:root{--bg:#0e1411;--card:#161f1a;--ink:#e6efe9;--mut:#93a49a;--line:#25322b;--g:#43c48a;--gl:#173225;--on:#06140d;--bad:#ff6b7d;--badl:#3a1a20;--hint:#2a2510}}
body{font-family:var(--f);background:var(--bg);color:var(--ink);line-height:1.7;font-size:16px;-webkit-text-size-adjust:100%}
button,input,select{font:inherit;color:inherit}
button{cursor:pointer}
:focus-visible{outline:3px solid var(--g);outline-offset:2px}
.wrap{width:min(920px,100% - 28px);margin:0 auto}

header{position:sticky;top:0;z-index:50;background:var(--bg);border-bottom:1px solid var(--line);padding-top:env(safe-area-inset-top)}
.bar{display:flex;align-items:center;gap:12px;height:60px}

.logo{
    display:flex;
    align-items:center;
    text-decoration:none;
    white-space:nowrap;
    flex-shrink:0;
}
.logo-img{
    height:42px;
    width:auto;
    max-width:160px;
    object-fit:contain;
    display:block;
    transition:transform .2s ease;
}
.logo:hover .logo-img{transform:scale(1.05)}

.search{flex:1;min-width:0;display:flex;align-items:center;background:var(--card);border:1.5px solid var(--line);border-radius:99px;padding:0 6px 0 14px}
.search:focus-within{border-color:var(--g)}
.search input{flex:1;min-width:0;border:0;outline:0;background:none;height:40px;font-size:16px}
.search button{border:0;background:none;width:34px;height:34px;border-radius:50%;color:var(--mut);font-size:1.1rem}
.login{color:var(--g);text-decoration:none;font-weight:600;font-size:.9rem;padding:8px 4px;white-space:nowrap}

main{padding:24px 0 48px;min-height:70vh}
h1{font-size:1.4rem;font-weight:600;margin-bottom:4px}
.sub{color:var(--mut);font-size:.92rem;margin-bottom:20px}

.levels{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.level{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px;text-align:left;}
.level:hover{border-color:var(--g);transform:translateY(-2px)}
.level strong{display:block;font-weight:600;line-height:1.4}
.level span{color:var(--mut);font-size:.85rem}

#qv{display:none}
#qv.on{display:block}
.top{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.top h1{flex:1;min-width:0;margin:0;font-size:1.2rem}
.chip{border:1px solid var(--line);background:var(--card);border-radius:99px;padding:6px 14px;font-size:.85rem;font-weight:600;white-space:nowrap}
.chip.g{background:var(--gl);border-color:transparent;color:var(--g)}

details.f{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin-bottom:18px}
details.f summary{list-style:none;cursor:pointer;padding:12px 16px;font-weight:600;font-size:.92rem;display:flex;justify-content:space-between}
details.f summary::-webkit-details-marker{display:none}
details.f summary::after{content:"▾";color:var(--mut);transition:transform .2s}
details.f[open] summary::after{transform:rotate(180deg)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:0 16px 16px}
.grid label{display:block;font-size:.78rem;color:var(--mut);margin-bottom:3px}
.grid input,.grid select{width:100%;height:42px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);padding:0 12px;outline:0}
.grid input:focus,.grid select:focus{border-color:var(--g)}
.reset{margin:0 16px 16px;background:none;border:0;color:var(--g);font-weight:600;font-size:.88rem;padding:6px 0}

.list{display:flex;flex-direction:column;gap:14px}
.q{content-visibility:auto;contain-intrinsic-size:auto 280px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:18px}
.meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;font-size:.75rem;color:var(--mut);align-items:center}
.meta .n{background:var(--g);color:var(--on);border-radius:8px;padding:1px 9px;font-weight:600}
.meta i{font-style:normal;background:var(--bg);border:1px solid var(--line);border-radius:99px;padding:0 9px}
.meta i.ref{background:var(--hint);border-radius:10px;padding:2px 10px;line-height:1.6;word-break:break-word}
.meta i.cnt{background:var(--gl);border-color:transparent;color:var(--g);font-weight:600}
.qt{font-size:1.05rem;font-weight:600;line-height:1.85;margin-bottom:14px;word-break:break-word}
.opts{display:grid;gap:8px}
@media(min-width:720px){.opts{grid-template-columns:1fr 1fr}}
.opt{display:flex;gap:10px;align-items:flex-start;width:100%;text-align:left;background:var(--bg);border:1.5px solid var(--line);border-radius:12px;padding:10px 12px;min-height:48px}
.opt:hover{border-color:var(--g)}
.opt .l{flex:0 0 28px;height:28px;border-radius:8px;border:1.5px solid var(--line);display:grid;place-items:center;font-size:.8rem;font-weight:600;color:var(--mut);background:var(--card)}
.opt .t{flex:1;line-height:1.75;word-break:break-word}
.opt.done{cursor:default}
.opt.done:hover{border-color:var(--line)}
.opt.ok{background:var(--gl);border-color:var(--g)!important}
.opt.ok .l{background:var(--g);border-color:var(--g);color:var(--on)}
.opt.ok::after{content:"✓";color:var(--g);font-weight:700;align-self:center}
.opt.no{background:var(--badl);border-color:var(--bad)!important}
.opt.no .l{background:var(--bad);border-color:var(--bad);color:#fff}
.opt.no::after{content:"✕";color:var(--bad);font-weight:700;align-self:center}
img.pic{display:block;max-width:100%;max-height:300px;margin-top:8px;border-radius:8px;border:1px solid var(--line);background:#fff;object-fit:contain;cursor:zoom-in}
.acts{display:flex;gap:16px;margin-top:12px}
.link{background:none;border:0;color:var(--g);font-weight:600;font-size:.88rem;padding:8px 0}
.exp{display:none;margin-top:8px;padding:14px 16px;background:var(--hint);border-left:3px solid #d9a441;border-radius:10px;line-height:1.85;font-size:.95rem}
.exp.on{display:block}

/* Explanation content — supports text + image */
.exp .exp-text{
    white-space:pre-wrap;
    word-break:break-word;
}
.exp .exp-text:not(:last-child){
    margin-bottom:10px;
}
.exp img.pic{
    margin-top:6px;
}

.msg{text-align:center;color:var(--mut);padding:48px 12px}
.msg.err{color:var(--bad)}
.more{display:block;margin:22px auto 0;background:var(--card);border:1.5px solid var(--g);color:var(--g);border-radius:99px;padding:11px 28px;font-weight:600}
.more:disabled{opacity:.6}

footer{border-top:1px solid var(--line);color:var(--mut);font-size:.82rem;padding:20px 0 calc(20px + env(safe-area-inset-bottom))}
footer .wrap{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center}
footer a{color:var(--mut)}
.foot-logo{display:inline-flex;align-items:center;gap:8px}
.foot-logo img{height:22px;width:auto;object-fit:contain;display:block}

#top{position:fixed;right:14px;bottom:calc(14px + env(safe-area-inset-bottom));width:44px;height:44px;border-radius:50%;border:0;background:var(--g);color:var(--on);font-size:1.2rem;display:none;}
#top.on{display:block}
#lb{position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;z-index:100;padding:14px;cursor:zoom-out}
#lb.on{display:flex}
#lb img{max-width:100%;max-height:100%;background:#fff;border-radius:8px;object-fit:contain}

@media(max-width:520px){
    .wrap{width:calc(100% - 24px)}
    .q{padding:14px}
    .login{display:none}
    .levels{grid-template-columns:1fr 1fr;gap:10px}
    .grid input,.grid select{font-size:16px}
    .logo-img{height:36px;max-width:130px}
}
@media(prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
</style>
</head>
<body>
<header>
  <div class="wrap bar">
    <a class="logo" href="index.php" aria-label="Exam360">
      <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Exam360" class="logo-img">
    </a>
    <form class="search" id="sf" role="search">
      <input id="hs" type="search" placeholder="প্রশ্ন খুঁজুন…" aria-label="Search questions" enterkeyhint="search">
      <button type="submit" aria-label="Search">⌕</button>
    </form>
    <a class="login" href="login.php">Login</a>
  </div>
</header>

<main class="wrap">
  <section id="lv">
    <h1>প্রশ্ন ব্যাংক</h1>
    <p class="sub">একটি ক্যাটাগরি বেছে নিন, অথবা উপরে সার্চ করুন।</p>
    <div class="levels" id="lg"><p class="msg" style="grid-column:1/-1">Loading…</p></div>
  </section>

  <section id="qv">
    <div class="top">
      <button class="chip" id="back" type="button">← Back</button>
      <h1 id="ttl">Questions</h1>
      <span class="chip g" id="score" hidden></span>
      <span class="chip" id="cnt">0</span>
    </div>
    <details class="f" id="flt" open>
      <summary>Filters</summary>
      <div class="grid">
        <div><label for="fk">Keyword</label><input id="fk" type="search" placeholder="Type to search"></div>
        <div><label for="fs">Subject</label><select id="fs"><option value="">All</option></select></div>
        <div><label for="fc">Chapter</label><select id="fc"><option value="">All</option></select></div>
        <div><label for="ft">Topic</label><select id="ft"><option value="">All</option></select></div>
        <div><label for="fr">Exam</label><input id="fr" type="search" placeholder="e.g. 41th BCS"></div>
      </div>
      <button class="reset" id="clr" type="button">Clear filters</button>
    </details>
    <div class="list" id="ql" aria-live="polite"></div>
    <button class="more" id="more" type="button" hidden>Load more</button>
  </section>
</main>

<footer>
  <div class="wrap">
    <span class="foot-logo">
      <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Exam360">
      © <?= date('Y') ?> Exam360
    </span>
    <span>
      <a href="index.php">Home</a> · 
      <a href="login.php">Login</a> · 
      <a href="index.php#register">Register</a>
    </span>
  </div>
</footer>

<button id="top" type="button" aria-label="Back to top">↑</button>
<div id="lb"><img alt="" id="lbi"></div>

<script>
(()=>{
const $=s=>document.querySelector(s);
const EM={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
const esc=s=>s==null?'':String(s).replace(/[&<>"']/g,c=>EM[c]);
const S={level:null,sub:'',chap:'',topic:'',kw:'',ref:'',off:0,total:0,ans:0,right:0};
let rid=0,timer;

/* ---- hidden reference filters (client-side extra safety) ---- */
const HIDDEN_REF_ID   = '145';
const HIDDEN_REF_NAME = 'MT';

function lead(t){let d=0,q=0,e=0,s=0;for(let i=0;i<t.length;i++){const c=t[i];if(e){e=0;continue}if(c==='\\'&&q){e=1;continue}if(c==='"'){q=!q;continue}if(q)continue;if(c==='{'||c==='['){d++;s=1}else if(c==='}'||c===']'){d--;if(s&&!d)return t.slice(0,i+1)}}return t}
const cache=new Map();
async function raw(k){
  const r=await fetch('question_bank.php?'+k,{headers:{'X-Requested-With':'XMLHttpRequest'}});
  const t=await r.text();
  try{return JSON.parse(lead(t.trim()))}catch(e){console.error(t.slice(0,300));throw new Error('Invalid server response')}
}
function api(p){
  const k=new URLSearchParams(p).toString();
  if(cache.has(k))return cache.get(k);
  const pr=raw(k).then(r=>{if(r&&r.ok===false)cache.delete(k);return r},e=>{cache.delete(k);throw e});
  cache.set(k,pr);
  if(cache.size>80)cache.delete(cache.keys().next().value);
  return pr;
}
const qp=(off,total)=>({fetch:'questions',level_id:S.level||'',subject_id:S.sub,chapter_id:S.chap,topic_id:S.topic,q:S.kw,exam_reference:S.ref,offset:off,total:total||''});
function img(p){
  if(!p)return'';p=String(p).trim();if(!p)return'';
  let m=p.match(/drive\.google\.com\/file\/d\/([\w-]+)/)||p.match(/drive\.google\.com\/(?:open|uc)\?(?:export=\w+&)?id=([\w-]+)/);
  if(m)return'https://lh3.googleusercontent.com/d/'+m[1];
  if(/^(https?:)?\/\//i.test(p)||p[0]==='/')return p;
  return'/'+p.replace(/^(\.\.\/|\.\/)+/,'');
}
const pic=(p,a)=>{const s=img(p);return s?`<img class="pic" src="${esc(s)}" alt="${a}" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">`:''};

const open=()=>$('#qv').classList.contains('on');
function showQ(){$('#lv').hidden=true;$('#qv').classList.add('on');$('#flt').open=innerWidth>=680;scrollTo(0,0)}
function showL(){$('#qv').classList.remove('on');$('#lv').hidden=false;scrollTo(0,0)}
function push(){if(!(history.state&&history.state.qb))history.pushState({qb:1},'')}
addEventListener('popstate',()=>{if(open())showL()});
$('#back').onclick=()=>history.state&&history.state.qb?history.back():showL();

async function levels(){
  const g=$('#lg');
  try{
    const r=await api({fetch:'levels'});
    if(r.ok===false)throw new Error(r.error||'Server error');
    let rows=r.rows||[];const w=rows.filter(x=>+x.q_count>0);if(w.length)rows=w;
    g.innerHTML=rows.length?rows.map(l=>`<button class="level" type="button" data-id="${l.id}" data-name="${esc(l.name).replace(/"/g,'&quot;')}"><strong>${esc(l.name)}</strong><span>${+l.q_count||0} questions</span></button>`).join(''):'<p class="msg" style="grid-column:1/-1">No levels yet.</p>';
  }catch(e){g.innerHTML=`<p class="msg err" style="grid-column:1/-1">${esc(e.message)}</p>`}
}
$('#lg').onclick=e=>{const b=e.target.closest('.level');if(b)openLevel(b.dataset.id,b.dataset.name)};

const opt=(el,html)=>{el.innerHTML=html};
function resetSel(){opt($('#fs'),'<option value="">All</option>');opt($('#fc'),'<option value="">All</option>');opt($('#ft'),'<option value="">All</option>')}
async function fill(sel,type,id){
  if(!id)return;
  try{const r=await api({fetch:type,id});(r.rows||[]).forEach(x=>sel.insertAdjacentHTML('beforeend',`<option value="${x.id}">${esc(x.name)}</option>`))}catch(e){console.error(e)}
}
async function openLevel(id,name){
  Object.assign(S,{level:id,sub:'',chap:'',topic:'',kw:'',ref:''});
  $('#ttl').textContent=name;$('#fk').value='';$('#fr').value='';resetSel();
  push();showQ();
  fill($('#fs'),'subject',id);
  load(true);
}
$('#fs').onchange=async e=>{S.sub=e.target.value;S.chap=S.topic='';opt($('#fc'),'<option value="">All</option>');opt($('#ft'),'<option value="">All</option>');load(true);fill($('#fc'),'chapter',S.sub)};
$('#fc').onchange=async e=>{S.chap=e.target.value;S.topic='';opt($('#ft'),'<option value="">All</option>');load(true);fill($('#ft'),'topic',S.chap)};
$('#ft').onchange=e=>{S.topic=e.target.value;load(true)};
const deb=()=>{clearTimeout(timer);timer=setTimeout(()=>load(true),350)};
$('#fk').oninput=e=>{S.kw=e.target.value.trim();deb()};
$('#fr').oninput=e=>{S.ref=e.target.value.trim();deb()};
$('#clr').onclick=()=>{$('#fk').value=$('#fr').value='';$('#fs').value=$('#fc').value=$('#ft').value='';Object.assign(S,{sub:'',chap:'',topic:'',kw:'',ref:''});load(true)};

$('#sf').onsubmit=e=>{
  e.preventDefault();
  const kw=$('#hs').value.trim();if(!kw)return;
  Object.assign(S,{level:null,sub:'',chap:'',topic:'',kw,ref:''});
  $('#ttl').textContent='Search: '+kw;$('#fk').value=kw;$('#fr').value='';resetSel();
  push();showQ();load(true);$('#hs').blur();
};

async function load(reset){
  const my=++rid,L=$('#ql'),M=$('#more');
  if(reset){S.off=0;S.ans=S.right=0;$('#score').hidden=true;L.innerHTML='<p class="msg">Loading…</p>';M.hidden=true}
  else{M.disabled=true;M.textContent='Loading…'}
  try{
    const r=await api(qp(S.off,reset?'':S.total));
    if(my!==rid)return;
    if(r.ok===false){L.innerHTML=`<p class="msg err">${esc(r.error||'Server error')}</p>`;$('#cnt').textContent='0';return}
    const rows=r.rows||[];S.total=r.total||0;
    if(reset)L.innerHTML=rows.length?'':'<p class="msg">No questions match. Try changing the filters.</p>';
    L.insertAdjacentHTML('beforeend',rows.map((q,i)=>card(q,S.off+i+1)).join(''));
    S.off+=rows.length;
    $('#cnt').textContent=S.total+' found';
    M.hidden=!r.has_more;M.disabled=false;M.textContent=`Load more (${S.total-S.off} left)`;
    if(r.has_more)api(qp(S.off,S.total)).catch(()=>{});
  }catch(e){
    if(my!==rid)return;
    if(reset)L.innerHTML=`<p class="msg err">${esc(e.message)}</p>`;
    else{M.disabled=false;M.textContent='Retry'}
  }
}
$('#more').onclick=()=>load(false);
if('IntersectionObserver' in window)new IntersectionObserver(es=>{const m=$('#more');if(es[0].isIntersecting&&!m.hidden&&!m.disabled)load(false)},{rootMargin:'800px'}).observe($('#more'));

function card(q,n){
  /* Clean the exam_refs string */
  let refs = q.exam_refs || '';
  if (refs) {
      refs = refs.split(',')
                 .map(s => s.trim())
                 .filter(s => s && s.toUpperCase() !== HIDDEN_REF_NAME)
                 .join(', ');
  }

  const tags=[q.subject_name,q.chapter_name,q.topic_name].filter(Boolean).map(t=>`<i>${esc(t)}</i>`).join('')
    +(refs?`<i class="ref">📌 ${esc(refs)}</i>`:'')
    +(+q.appear_count>1?`<i class="cnt">${+q.appear_count}× asked</i>`:'');

  const qt=(q.question?esc(q.question):'')+pic(q.image_url,'Question image');

  const opts=['A','B','C','D'].map(L=>{
    const k=L.toLowerCase(),t=q['option_'+k]?esc(q['option_'+k]):'',p=pic(q['image_url_option_'+k],'Option '+L);
    return t||p?`<button type="button" class="opt" data-l="${L}"><span class="l">${L}</span><span class="t">${t}${p}</span></button>`:'';
  }).join('');

  /* ---- Build explanation (text and/or image) ---- */
  const hasExplText  = q.explanation && String(q.explanation).trim();
  const hasExplImage = q.explanation_image_url && String(q.explanation_image_url).trim();
  const hasExpl      = hasExplText || hasExplImage;

  let explInner = '';
  if (hasExplText) {
      explInner += `<div class="exp-text">${esc(q.explanation)}</div>`;
  }
  if (hasExplImage) {
      explInner += pic(q.explanation_image_url, 'Explanation image');
  }

  return `<article class="q" data-c="${esc(q.correct_option||'').toUpperCase()}">
    <div class="meta"><span class="n">Q${n}</span>${tags}</div>
    ${qt?`<div class="qt">${qt}</div>`:''}
    <div class="opts">${opts}</div>
    <div class="acts">
      ${hasExpl?'<button type="button" class="link tg" aria-expanded="false">Explanation</button>':''}
      <button type="button" class="link rv">Show answer</button>
    </div>
    ${hasExpl?`<div class="exp">${explInner}</div>`:''}
  </article>`;
}

$('#ql').onclick=e=>{
  const c=e.target.closest('.q');if(!c)return;
  if(e.target.tagName==='IMG'){$('#lbi').src=e.target.currentSrc||e.target.src;$('#lb').classList.add('on');return}
  const o=e.target.closest('.opt');
  if(o&&!o.classList.contains('done')){
    const first=!c.dataset.d;c.dataset.d=1;
    const ok=o.dataset.l===c.dataset.c;
    c.querySelectorAll('.opt').forEach(x=>{x.classList.add('done');if(x.dataset.l===c.dataset.c)x.classList.add('ok');else if(x===o)x.classList.add('no')});
    if(first){S.ans++;if(ok)S.right++;const s=$('#score');s.hidden=false;s.textContent=`✓ ${S.right} / ${S.ans}`}
    return;
  }
  if(e.target.closest('.rv')){c.dataset.d=1;c.querySelectorAll('.opt').forEach(x=>{x.classList.add('done');if(x.dataset.l===c.dataset.c)x.classList.add('ok')});return}
  const t=e.target.closest('.tg');
  if(t){const x=c.querySelector('.exp'),on=x.classList.toggle('on');t.setAttribute('aria-expanded',on);t.textContent=on?'Hide explanation':'Explanation'}
};

$('#lb').onclick=()=>$('#lb').classList.remove('on');
addEventListener('keydown',e=>{if(e.key==='Escape')$('#lb').classList.remove('on')});
$('#top').onclick=()=>scrollTo({top:0,behavior:'smooth'});
addEventListener('scroll',()=>$('#top').classList.toggle('on',scrollY>700),{passive:true});

levels();
})();
</script>
</body>
</html>