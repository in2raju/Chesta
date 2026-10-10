<?php
/* =========================================================
   index_user.php — Logged-in user version of the public homepage
   ========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------- Session guard: লগইন বাধ্যতামূলক --------
if (empty($_SESSION['user']) || empty($_SESSION['user']['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
require_once 'mailer.php';

use PHPMailer\PHPMailer\Exception;

/* ---------- Session user info ---------- */
$loggedUserName = $_SESSION['user']['user_name'] ?? 'Student';
$loggedUserInitials = 'U';
if ($loggedUserName) {
    $__w = array_filter(explode(' ', trim($loggedUserName)));
    $loggedUserInitials = strtoupper(implode('', array_map(fn($x) => mb_substr($x, 0, 1), array_slice($__w, 0, 2))));
}

/* ---------- Helpers ---------- */
function bn_to_en_digits($s) {
    return strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
}
function site_base_url() {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host   = preg_replace('/[^a-zA-Z0-9.:\-]/', '', $host);
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/* ---------- Levels ---------- */
$levels = [];
try {
    $levels = $pdo->query("SELECT id, name FROM levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $levels = [];
}

/* ---------- LIVE SITE STATS ---------- */
$totalStudents = 0;
$totalQuestions = 0;
$totalExamsCount = 0;
$totalCategories = 0;

try { $totalStudents   = (int) $pdo->query("SELECT COUNT(*) FROM user_login_info")->fetchColumn(); }
catch (Throwable $e) { error_log('stats students: ' . $e->getMessage()); }

try { $totalQuestions  = (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE DELETE_DATE IS NULL")->fetchColumn(); }
catch (Throwable $e) { error_log('stats questions: ' . $e->getMessage()); }

try {
    $totalExamsCount = (int) $pdo->query("
        SELECT COUNT(*) FROM exams_info
        WHERE DELETE_DATE IS NULL
          AND (AUTHORIZED_STATUS = 'Y' OR AUTHORIZED_STATUS IS NULL)
    ")->fetchColumn();
} catch (Throwable $e) { error_log('stats exams: ' . $e->getMessage()); }

try { $totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM levels")->fetchColumn(); }
catch (Throwable $e) { error_log('stats levels: ' . $e->getMessage()); }

/* ---------- Site Logo ---------- */
$siteLogo = 'logo.png';

$catIcons = ['🏛️','📚','🎓','🏦','⚙️','🩺','🏫','⚖️','🧠','🎯'];

/* =========================================================
   COURSES + BANNER — DB থেকে (courses টেবিল)
   হুবহু manage_courses.php এর ডেটা স্ট্রাকচার
   ========================================================= */
$courses = [];
$banner  = ['active' => false, 'meta' => []];

try {
    $sql = "
        SELECT 
            c.id, c.title, c.title_bn, c.subtitle, c.subject_line,
            c.tag, c.discount_pct, c.rating, c.meta_text, c.tone_gradient,
            c.price, c.discount_price, c.level_id, c.type_id,
            c.is_featured, c.display_order
        FROM courses c
        WHERE c.DELETE_DATE IS NULL
          AND c.is_active = 1
          AND c.AUTHORIZED_STATUS = 'Y'
        ORDER BY c.display_order ASC, c.is_featured DESC, c.id DESC
        LIMIT 12
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $oldPrice = (float)$r['price'];
        $newPrice = (!empty($r['discount_price']) && $r['discount_price'] > 0)
                    ? (float)$r['discount_price']
                    : $oldPrice;

        $courseUrl = 'enroll.php?course_id=' . (int)$r['id']
                   . '&level_id='    . (int)$r['level_id']
                   . '&exam_type_id=' . (int)$r['type_id'];

        $courses[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['title_bn'] ?: $r['title'],
            'title'       => $r['title'],
            'subtitle'    => $r['subtitle']     ?? '',
            'subject'     => $r['subject_line'] ?? '',
            'tag'         => $r['tag']          ?? '',
            'discount'    => $r['discount_pct'] ?? '',
            'old_price'   => $oldPrice,
            'new_price'   => $newPrice,
            'rating'      => (int)($r['rating'] ?? 5),
            'meta'        => $r['meta_text'] ?? '',
            'tone'        => $r['tone_gradient'] ?: 'linear-gradient(135deg,#7a1122 0%,#a31528 100%)',
            'url'         => $courseUrl,
            'is_featured' => (int)$r['is_featured'],
        ];

        if ((int)$r['is_featured'] === 1 && empty($banner['active'])) {
            $meta = array_filter([
                !empty($r['tag'])          ? '✅ ' . $r['tag'] : null,
                !empty($r['subtitle'])     ? '📝 ' . $r['subtitle'] : null,
                !empty($r['discount_pct']) && $r['discount_pct'] !== '০%'
                                            ? '🎯 ' . $r['discount_pct'] . ' ছাড়' : null,
            ]);

            if (empty($meta)) {
                $meta = ['🎓 প্রিমিয়াম কোর্স', '📝 লাইভ মডেল টেস্ট', '🎯 সম্পূর্ণ সিলেবাস'];
            }

            $banner = [
                'active'   => true,
                'badge'    => $r['discount_pct'] ?: 'নতুন',
                'title'    => $r['title_bn'] ?: $r['title'],
                'subtitle' => $r['subtitle'] ?: ($r['subject_line'] ?? ''),
                'cta_text' => 'কোর্স দেখুন',
                'cta_url'  => $courseUrl,
                'bg'       => $r['tone_gradient'] ?: 'linear-gradient(135deg,#7a1122 0%,#a31528 100%)',
                'meta'     => array_values($meta),
            ];
        }
    }

} catch (Throwable $e) {
    error_log('courses fetch failed: ' . $e->getMessage());
    $courses = [];
    $banner  = ['active' => false, 'meta' => []];
}

/* ---------- Default level for analysis ---------- */
$defaultAnalysisLevelId = 3;

/* ---------- Levels JSON ---------- */
$levelsJson = json_encode($levels, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#00693e">
<meta name="description" content="Exam360 — বাংলাদেশের সব পরীক্ষার MCQ প্রস্তুতির একটি প্ল্যাটফর্ম।">
<title>হোম — Chesta</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#fbfcfb;--surface:#f2f6f3;--card:#ffffff;--ink:#10231a;--mut:#5a6c62;--line:#e1e9e3;
  --g:#00693e;--g2:#0a8f55;--gd:#024a2c;--gl:#e4f2e9;--r:#f42a41;--rl:#fdecee;--gold:#d9a441;--on:#fff;
  --shadow:0 20px 50px -18px rgba(2,74,44,.25);
  --f:'Hind Siliguri',system-ui,-apple-system,'Segoe UI','Nirmala UI','Kohinoor Bangla',sans-serif;
  --nav:68px;
}
@media(prefers-color-scheme:dark){:root{
  --bg:#0b110e;--surface:#111a15;--card:#151f19;--ink:#e8f1ec;--mut:#9aaca1;--line:#233029;
  --g:#3fc088;--g2:#2aa874;--gd:#9be3c2;--gl:#133023;--r:#ff6b7d;--rl:#33181d;--on:#04140c;
  --shadow:0 20px 50px -18px rgba(0,0,0,.6)}}
html{scroll-behavior:smooth;scroll-padding-top:calc(var(--nav) + 12px)}
body{font-family:var(--f);background:var(--bg);color:var(--ink);line-height:1.75;font-size:16px;overflow-x:hidden;-webkit-text-size-adjust:100%}
button,input,select{font:inherit;color:inherit}
button{cursor:pointer}
a{color:inherit}
:focus-visible{outline:3px solid var(--g);outline-offset:2px}
.wrap{width:min(1160px,100% - 32px);margin:0 auto}
section{padding:88px 0}
.alt{background:var(--surface)}

/* buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 26px;border-radius:99px;border:1.5px solid transparent;font-weight:600;font-size:.98rem;text-decoration:none;transition:transform .15s,box-shadow .2s,background .2s}
.btn:active{transform:scale(.97)}
.btn-p{background:linear-gradient(135deg,var(--g),var(--g2));color:var(--on)}
.btn-p:hover{box-shadow:0 10px 26px -8px var(--g)}
.btn-o{background:transparent;border-color:var(--line);color:var(--ink)}
.btn-o:hover{border-color:var(--g);color:var(--g)}
.btn-lg{padding:15px 32px;font-size:1.05rem}

/* nav */
nav{position:fixed;inset:0 0 auto 0;z-index:60;height:calc(var(--nav) + env(safe-area-inset-top));padding-top:env(safe-area-inset-top);background:color-mix(in srgb,var(--bg) 85%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid transparent;transition:border-color .2s}
nav.s{border-color:var(--line)}
.nav{display:flex;align-items:center;justify-content:space-between;height:var(--nav);gap:14px}
.links{display:flex;gap:2px}
.links a{padding:8px 14px;border-radius:10px;text-decoration:none;color:var(--mut);font-weight:500;font-size:.95rem;white-space:nowrap}
.links a:hover{background:var(--surface);color:var(--g)}
.acts{display:flex;gap:10px;align-items:center}
.burger{display:none;background:none;border:0;width:42px;height:42px;border-radius:10px;font-size:1.4rem}
.mm{display:none;position:fixed;top:calc(var(--nav) + env(safe-area-inset-top));left:0;right:0;z-index:55;background:var(--bg);border-bottom:1px solid var(--line);padding:12px 16px 18px;flex-direction:column;gap:4px}
.mm.on{display:flex}
.mm a{padding:12px 10px;border-radius:10px;text-decoration:none;color:var(--ink);font-weight:500}
.mm a:hover{background:var(--surface)}
.mm .row{display:flex;gap:10px;margin-top:8px}
.mm .row>*{flex:1}

/* user chip */
.user-chip{display:flex;align-items:center;gap:10px;padding:5px 14px 5px 6px;border-radius:99px;background:var(--surface);border:1.5px solid var(--line);text-decoration:none;color:var(--ink);font-weight:600;font-size:.9rem;transition:border-color .2s,transform .15s}
.user-chip:hover{border-color:var(--g);transform:translateY(-1px)}
.user-chip .avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;display:grid;place-items:center;font-size:.82rem;font-weight:700;flex-shrink:0}
.user-chip .uname{max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-menu{position:relative}
.user-menu .dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:8px;min-width:200px;display:none;z-index:100}
.user-menu.open .dropdown{display:block}
.user-menu .dropdown a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;text-decoration:none;color:var(--ink);font-size:.92rem;font-weight:500}
.user-menu .dropdown a:hover{background:var(--surface);color:var(--g)}
.user-menu .dropdown a.danger{color:var(--r)}
.user-menu .dropdown a.danger:hover{background:var(--rl);color:var(--r)}
.user-menu .dropdown hr{border:0;border-top:1px solid var(--line);margin:6px 4px}

/* logo */
.logo{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0;min-width:0;font-weight:700;font-size:1.35rem}
.logo-img{height:42px;width:auto;max-width:160px;object-fit:contain;display:block;flex-shrink:0;transition:transform .2s ease}
.logo:hover .logo-img{transform:scale(1.05)}
.logo-text{display:flex;align-items:baseline;font-weight:700;font-size:1.35rem;letter-spacing:-.01em;line-height:1;color:var(--ink);white-space:nowrap}
.logo-text b{color:var(--r);font-weight:700;margin-left:1px}

/* hero */
.hero{position:relative;padding:calc(var(--nav) + 56px) 0 72px;overflow:hidden;background:
  radial-gradient(900px 480px at 85% -10%,color-mix(in srgb,var(--g) 18%,transparent),transparent 70%),
  radial-gradient(700px 400px at -10% 30%,color-mix(in srgb,var(--r) 10%,transparent),transparent 70%)}
.hgrid{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
.pill{display:inline-flex;align-items:center;gap:8px;background:var(--gl);color:var(--g);border-radius:99px;padding:6px 16px;font-size:.85rem;font-weight:600;margin-bottom:20px}
.pill::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--r);animation:pulse 1.8s infinite}
@keyframes pulse{50%{opacity:.35;transform:scale(1.4)}}
h1{font-size:clamp(2.1rem,5.2vw,3.5rem);line-height:1.3;font-weight:700;margin-bottom:18px;letter-spacing:-.01em}
h1 em{font-style:normal;background:linear-gradient(120deg,var(--g),var(--g2) 60%,var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.lead{color:var(--mut);font-size:1.08rem;max-width:560px;margin-bottom:30px}
.hbtn{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:34px}
.trust{display:flex;align-items:center;gap:12px;color:var(--mut);font-size:.9rem}
.av{display:flex}
.av span{width:32px;height:32px;border-radius:50%;border:2px solid var(--bg);margin-left:-8px;display:grid;place-items:center;font-size:.72rem;font-weight:700;color:#fff}
.av span:first-child{margin-left:0}
.av span:nth-child(1){background:#00693e}.av span:nth-child(2){background:#f42a41}.av span:nth-child(3){background:#d9a441}.av span:nth-child(4){background:#3b82a0}

/* hero banner card */
.demo{position:relative;background:var(--card);border:1px solid var(--line);border-radius:24px;padding:16px;box-shadow:var(--shadow);overflow:hidden}
.demo::before{content:"";position:absolute;inset:-1px;border-radius:25px;padding:1px;background:linear-gradient(135deg,var(--g),transparent 40%,var(--r));-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;opacity:.5;pointer-events:none}
.banner-course{position:relative;border-radius:18px;padding:34px 26px 30px;color:#fff;overflow:hidden;display:flex;flex-direction:column;gap:14px;min-height:360px;justify-content:center;text-align:center;align-items:center;box-shadow:inset 0 0 100px rgba(0,0,0,.22)}
.banner-course::after{content:"";position:absolute;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.14) 0%,transparent 70%);right:-100px;top:-120px;pointer-events:none}
.banner-course::before{content:"";position:absolute;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);left:-80px;bottom:-90px;pointer-events:none}
.banner-course .bc-tag{position:absolute;top:14px;right:0;background:#ffc107;color:#3a2600;font-size:.78rem;font-weight:700;padding:5px 16px;border-radius:8px 0 0 8px;letter-spacing:.03em;box-shadow:0 3px 8px rgba(0,0,0,.22);z-index:2}
.banner-course .bc-title{font-size:1.65rem;font-weight:700;line-height:1.35;letter-spacing:-.005em;position:relative;z-index:1;text-shadow:0 2px 8px rgba(0,0,0,.32);margin:0;max-width:95%;color:#fff}
.banner-course .bc-sub{display:inline-block;background:#fff;color:#024a2c;font-size:.92rem;font-weight:700;padding:8px 20px;border-radius:10px;box-shadow:0 3px 10px rgba(0,0,0,.22);position:relative;z-index:1;max-width:95%;line-height:1.5}
.banner-course .bc-meta{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;position:relative;z-index:1;margin-top:4px}
.banner-course .bc-meta span{background:rgba(255,193,7,.95);color:#3a2600;font-size:.8rem;font-weight:700;padding:6px 14px;border-radius:99px;box-shadow:0 3px 8px rgba(0,0,0,.2);letter-spacing:.01em;white-space:nowrap}
.banner-course .bc-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#ffc107;color:#3a2600;padding:13px 30px;border-radius:10px;font-weight:800;font-size:1rem;text-decoration:none;width:fit-content;position:relative;z-index:1;transition:transform .15s,box-shadow .2s,background .2s;margin-top:8px;box-shadow:0 6px 16px -4px rgba(255,193,7,.55);letter-spacing:.02em}
.banner-course .bc-cta:hover{transform:translateY(-2px);background:#ffd54a;box-shadow:0 12px 24px -6px rgba(255,193,7,.7)}

/* stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:56px}
.st{position:relative;overflow:hidden;border-radius:22px;padding:22px 20px;text-align:center;background:var(--card);border:1px solid var(--line);box-shadow:0 8px 24px -14px rgba(2,74,44,.15);transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s;isolation:isolate}
.st:hover{transform:translateY(-6px);box-shadow:0 24px 50px -18px rgba(2,74,44,.25)}
.st-1{background:linear-gradient(135deg,#e8f7ee 0%,#d3efdf 100%);border-color:#c3e6cf}
.st-2{background:linear-gradient(135deg,#e6f1ff 0%,#d0e3ff 100%);border-color:#bcd4f5}
.st-3{background:linear-gradient(135deg,#fff4e6 0%,#ffe6cc 100%);border-color:#f5d8b5}
.st-4{background:linear-gradient(135deg,#fdeef0 0%,#fbd9de 100%);border-color:#f5c3ca}
@media(prefers-color-scheme:dark){
  .st-1{background:linear-gradient(135deg,#0f2e1f 0%,#153a28 100%);border-color:#1f4a34}
  .st-2{background:linear-gradient(135deg,#0e1e33 0%,#152b45 100%);border-color:#1f3a55}
  .st-3{background:linear-gradient(135deg,#2e2311 0%,#3a2c15 100%);border-color:#4a3920}
  .st-4{background:linear-gradient(135deg,#2e1418 0%,#3a1c21 100%);border-color:#4a262c}
}
.st::before{content:"";position:absolute;width:120px;height:120px;border-radius:50%;top:-50px;right:-40px;background:rgba(255,255,255,.45);pointer-events:none;z-index:0}
.st::after{content:"";position:absolute;width:80px;height:80px;border-radius:50%;bottom:-45px;left:-30px;background:rgba(255,255,255,.35);pointer-events:none;z-index:0}
@media(prefers-color-scheme:dark){
  .st::before{background:rgba(255,255,255,.05)}
  .st::after{background:rgba(255,255,255,.04)}
}
.st b{position:relative;z-index:1;display:block;font-size:2.1rem;line-height:1.15;font-weight:700;letter-spacing:-.02em;margin-bottom:4px}
.st span{position:relative;z-index:1;display:block;font-size:.85rem;font-weight:600;letter-spacing:.01em}
.st-1 b{color:#0a5f38} .st-1 span{color:#2d6f4c}
.st-2 b{color:#1d4c96} .st-2 span{color:#3e6399}
.st-3 b{color:#a86018} .st-3 span{color:#8a6a3d}
.st-4 b{color:#b82038} .st-4 span{color:#a14453}
@media(prefers-color-scheme:dark){
  .st-1 b{color:#7fdba7} .st-1 span{color:#a3d7b9}
  .st-2 b{color:#8ab6ff} .st-2 span{color:#adc6e3}
  .st-3 b{color:#f5b76d} .st-3 span{color:#d4bd93}
  .st-4 b{color:#ff92a3} .st-4 span{color:#e0a5b0}
}

.eyebrow{display:inline-block;color:var(--r);font-weight:700;font-size:.85rem;letter-spacing:.06em;margin-bottom:10px}
h2{font-size:clamp(1.6rem,3.6vw,2.4rem);line-height:1.35;font-weight:700;margin-bottom:12px}
.sub{color:var(--mut);max-width:560px}
.head{margin-bottom:44px}
.head.c{text-align:center}.head.c .sub{margin:0 auto}

/* bento */
.bento{display:grid;grid-template-columns:repeat(6,1fr);gap:16px}
.bx{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:26px;transition:transform .2s,box-shadow .2s}
.bx:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.bx .ic{width:48px;height:48px;border-radius:14px;background:var(--gl);display:grid;place-items:center;font-size:1.5rem;margin-bottom:16px}
.bx h3{font-size:1.15rem;margin-bottom:6px}
.bx p{color:var(--mut);font-size:.93rem}
.s2{grid-column:span 2}.s3{grid-column:span 3}.s4{grid-column:span 4}
.bx.hl{background:linear-gradient(135deg,var(--gd),var(--g));color:#fff;border:0}
@media(prefers-color-scheme:dark){.bx.hl{background:linear-gradient(135deg,#0d3a26,#1b6f4b)}}
.bx.hl p{color:rgba(255,255,255,.85)}.bx.hl .ic{background:rgba(255,255,255,.16)}
.bars{display:flex;align-items:flex-end;gap:7px;height:70px;margin-top:16px}
.bars i{flex:1;background:rgba(255,255,255,.35);border-radius:6px 6px 0 0}

/* steps */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;counter-reset:s;position:relative}
.step{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px 22px;position:relative}
.step::before{counter-increment:s;content:counter(s,bengali);display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--g2));color:var(--on);font-weight:700;font-size:1.15rem;margin-bottom:14px}
.step h3{font-size:1.08rem;margin-bottom:6px}
.step p{color:var(--mut);font-size:.92rem}

/* ============================================
   COURSES — হুবহু manage_courses.php এর ডিজাইন
   ============================================ */
.course-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(310px,1fr));
    gap:22px;
    margin-bottom:20px;
}
.course-item{display:flex;flex-direction:column}

.course-card{
    background:#fff;border-radius:18px;overflow:hidden;
    box-shadow:0 10px 28px -10px rgba(0,0,0,.15);
    transition:transform .22s,box-shadow .22s;
    display:flex;flex-direction:column;
    text-decoration:none;color:inherit;
}
.course-card:hover{
    transform:translateY(-4px);
    box-shadow:0 18px 40px -12px rgba(0,0,0,.22);
}

.course-card-top{
    padding:22px 18px 26px;
    text-align:center;color:#fff;
    position:relative;min-height:210px;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    gap:10px;
}
.course-tag{
    position:absolute;top:14px;right:14px;
    background:#f5b301;color:#4a2c00;
    font-size:11px;font-weight:800;
    padding:5px 12px;border-radius:8px;
    letter-spacing:.5px;text-transform:uppercase;
}
.course-name{
    font-size:20px;font-weight:800;
    line-height:1.3;margin:18px 0 4px;
    letter-spacing:-.01em;padding:0 6px;
}
.course-subtitle-pill{
    background:#fff;color:#8b0d20;
    font-size:13px;font-weight:700;
    padding:6px 16px;border-radius:30px;
    display:inline-block;
    box-shadow:0 4px 12px rgba(0,0,0,.15);
}
.course-subject-pill{
    background:#f5b301;color:#2c1a00;
    font-size:12.5px;font-weight:600;
    padding:6px 14px;border-radius:30px;
    display:inline-block;max-width:90%;
    line-height:1.35;
    box-shadow:0 4px 12px rgba(0,0,0,.15);
}
.course-meta-row{
    display:flex;align-items:center;justify-content:center;
    gap:14px;margin-top:6px;width:100%;
    max-width:230px;margin-left:auto;margin-right:auto;
}
.course-meta-text{
    font-size:14px;font-weight:700;color:#fff;
    white-space:nowrap;flex-shrink:0;
}
.course-discount-circle{
    width:54px;height:54px;border-radius:50%;
    background:#f5b301;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    color:#2c1a00;font-weight:800;
    font-size:14px;line-height:1;
    box-shadow:0 4px 12px rgba(0,0,0,.2);
    flex-shrink:0;
}
.course-discount-circle small{font-size:9px;font-weight:700;margin-top:1px}

.course-card-body{
    padding:16px 18px 18px;
    text-align:center;
    display:flex;flex-direction:column;gap:10px;
    flex:1;
}
.course-body-title{
    font-size:15.5px;font-weight:800;
    color:#1a1a1a;line-height:1.35;
}
.course-bottom-row{
    display:flex;align-items:center;
    justify-content:space-between;gap:10px;margin-top:4px;
}
.course-stars{
    display:inline-flex;gap:2px;
    font-size:15px;
}
.course-stars .bi-star{color:#d1d5db}
.course-stars .bi-star-fill{color:#f5b301}
.course-view-link{
    font-size:13.5px;font-weight:700;
    color:#6b7280;text-decoration:none;
}
.course-view-link:hover{color:#8b0d20}
.course-cta-btn{
    display:inline-flex;align-items:center;justify-content:center;
    gap:6px;background:#8b0d20;color:#fff;
    font-weight:800;font-size:14px;
    padding:11px 22px;border-radius:30px;
    text-decoration:none;
    align-self:flex-end;margin-top:8px;
}
.course-cta-btn:hover{
    color:#fff;transform:translateY(-1px);
    box-shadow:0 8px 20px rgba(139,13,32,.35);
}

/* faq */
.faq{max-width:780px;margin:0 auto;display:grid;gap:10px}
details.q{background:var(--card);border:1px solid var(--line);border-radius:16px}
details.q summary{list-style:none;cursor:pointer;padding:18px 22px;font-weight:600;display:flex;justify-content:space-between;gap:12px}
details.q summary::-webkit-details-marker{display:none}
details.q summary::after{content:"+";color:var(--r);font-size:1.4rem;line-height:1;transition:transform .2s}
details.q[open] summary::after{transform:rotate(45deg)}
details.q p{padding:0 22px 20px;color:var(--mut)}

/* cta */
.cta{background:linear-gradient(135deg,var(--gd),var(--g));color:#fff;border-radius:28px;padding:56px 28px;text-align:center;position:relative;overflow:hidden}
@media(prefers-color-scheme:dark){.cta{background:linear-gradient(135deg,#0d3a26,#1b6f4b)}}
.cta::after{content:"";position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(244,42,65,.22);right:-80px;top:-120px}
.cta h2,.cta p,.cta .hbtn{position:relative;z-index:1}
.cta p{color:rgba(255,255,255,.85);max-width:520px;margin:0 auto 26px}
.cta .hbtn{justify-content:center;margin:0}
.cta .btn-w{background:#fff;color:#024a2c}
.cta .btn-ghost{border-color:rgba(255,255,255,.5);color:#fff}

/* footer */
footer{border-top:1px solid var(--line);padding:56px 0 28px;margin-top:0}
.fg{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:32px;margin-bottom:36px}
.fg p{color:var(--mut);font-size:.9rem;max-width:280px;margin-top:12px}
.fg h4{margin-bottom:12px;font-size:1rem}
.fg a{display:block;color:var(--mut);text-decoration:none;font-size:.92rem;margin-bottom:8px}
.fg a:hover{color:var(--g)}
.fb{border-top:1px solid var(--line);padding-top:20px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;color:var(--mut);font-size:.85rem}

/* analysis */
.level-tabs{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:22px}
.level-tab{border:1.5px solid var(--line);background:var(--card);color:var(--mut);border-radius:30px;padding:8px 18px;font-size:.9rem;font-weight:600;font-family:var(--f);cursor:pointer;transition:all .2s}
.level-tab:hover{border-color:var(--g);color:var(--g);transform:translateY(-2px)}
.level-tab.active{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;border-color:transparent;box-shadow:0 8px 22px -8px var(--g)}
.level-loading{font-size:.9rem;color:var(--mut);padding:8px 16px}
.analysis-empty{text-align:center;padding:50px 20px;color:var(--mut);font-size:.95rem;background:var(--card);border:1px dashed var(--line);border-radius:20px}
.analysis-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;margin-bottom:20px}
@media(max-width:900px){.analysis-grid{grid-template-columns:1fr}}
.chart-card{background:var(--card);border-radius:20px;box-shadow:var(--shadow);border:1px solid var(--line);padding:26px;transition:box-shadow .3s,border-color .3s;position:relative}
.chart-card:hover{box-shadow:0 24px 60px -18px rgba(2,74,44,.28);border-color:color-mix(in srgb,var(--g) 20%,var(--line))}
.chart-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:10px}
.chart-card-title{font-size:1rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:10px}
.chart-pill{font-size:11px;font-weight:700;letter-spacing:.3px;padding:5px 13px;border-radius:30px;background:var(--gl);color:var(--g);border:1.5px solid color-mix(in srgb,var(--g) 22%,transparent)}
.chart-icon-badge{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--gl);flex-shrink:0}
.subject-select{border-radius:12px;border:1.5px solid var(--line);padding:9px 14px;font-weight:600;font-size:13px;color:var(--ink);background:var(--surface);min-width:220px;font-family:var(--f);cursor:pointer;transition:border-color .2s,box-shadow .2s}
.subject-select:hover{border-color:var(--g)}
.subject-select:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 3px color-mix(in srgb,var(--g) 18%,transparent)}
.exam-item{display:flex;align-items:center;gap:12px;padding:12px 4px;border-bottom:1px solid var(--line);transition:background .2s,padding-left .2s;border-radius:10px}
.exam-item:hover{background:var(--surface);padding-left:10px}
.exam-item:last-child{border-bottom:none}
.exam-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.exam-info{flex:1;min-width:0}
.exam-subj{font-weight:600;color:var(--ink);font-size:.92rem}
.exam-meta{font-size:.78rem;color:var(--mut);margin-top:2px}
.exam-right{text-align:right;flex-shrink:0}
.chart-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--card) 80%,transparent);border-radius:20px;font-size:13px;font-weight:700;color:var(--g);opacity:0;pointer-events:none;transition:opacity .2s;backdrop-filter:blur(4px)}
.chart-loading.active{opacity:1}
.chart-loading::before{content:'';width:18px;height:18px;border:2.5px solid var(--line);border-top-color:var(--g);border-radius:50%;animation:spin .8s linear infinite;margin-right:10px}
@keyframes spin{to{transform:rotate(360deg)}}
.scroll-area::-webkit-scrollbar{width:8px}
.scroll-area::-webkit-scrollbar-track{background:transparent}
.scroll-area::-webkit-scrollbar-thumb{background:var(--line);border-radius:20px}
.scroll-area::-webkit-scrollbar-thumb:hover{background:var(--mut)}

/* reveal */
.rv{opacity:0;transform:translateY(22px);transition:opacity .55s ease,transform .55s ease}
.rv.in{opacity:1;transform:none}

@media(max-width:960px){
  .hgrid{grid-template-columns:1fr}.demo{max-width:540px;margin:12px auto 0}
  .stats{grid-template-columns:1fr 1fr}.steps{grid-template-columns:1fr 1fr}
  .bento{grid-template-columns:repeat(2,1fr)}.s2,.s3,.s4{grid-column:span 2}.bx.half{grid-column:span 1}
  .fg{grid-template-columns:1fr 1fr}
  .logo-img{height:38px;max-width:130px}.logo-text{font-size:1.2rem}
}
@media(max-width:720px){
  section{padding:64px 0}
  .links,.acts .btn-o{display:none}.burger{display:block}
  .steps{grid-template-columns:1fr}.bento{grid-template-columns:1fr}.s2,.s3,.s4,.bx.half{grid-column:auto}
  .fg{grid-template-columns:1fr}.hbtn .btn{flex:1}
  .st b{font-size:1.7rem}
  .banner-course{padding:28px 20px 26px;min-height:auto}
  .banner-course .bc-title{font-size:1.3rem}
  .banner-course .bc-sub{font-size:.85rem;padding:7px 16px}
  .banner-course .bc-meta span{font-size:.75rem;padding:5px 12px}
  .banner-course .bc-cta{font-size:.92rem;padding:12px 24px}
  .logo-img{height:36px;max-width:110px}.logo-text{font-size:1.1rem}.logo{gap:8px}
  .user-chip .uname{display:none}
  .user-chip{padding:4px}
  .course-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
  .logo-img{height:34px;max-width:95px}.logo-text{font-size:1rem}
}
@media(max-width:380px){
  .logo-text{display:none}
}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important;scroll-behavior:auto!important}.rv{opacity:1;transform:none}}
</style>
</head>
<body>

<!-- ===================== NAV ===================== -->
<nav id="nav">
  <div class="wrap nav">
    <a class="logo" href="index_user.php" aria-label="Chesta হোম">
      <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Chesta" class="logo-img">
      <span class="logo-text"><b></b></span>
    </a>

    <div class="links">
      <a href="#features">ফিচার</a>
      <a href="question_bank_user.php">প্রশ্ন ব্যাংক</a>
      <a href="#analysis">বিশ্লেষণ</a>
      <a href="#courses">কোর্স</a>
      <a href="#how">কীভাবে</a>
      <a href="#faq">প্রশ্নোত্তর</a>
    </div>

    <div class="acts">
      <div class="user-menu" id="userMenu">
        <a href="javascript:void(0)" class="user-chip" id="userChip">
          <span class="avatar"><?= htmlspecialchars($loggedUserInitials, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="uname"><?= htmlspecialchars($loggedUserName, ENT_QUOTES, 'UTF-8') ?></span>
          <i class="bi bi-chevron-down" style="font-size:.75rem;color:var(--mut)"></i>
        </a>
        <div class="dropdown">
          <a href="deshboard.php"><i class="bi bi-speedometer2"></i> ড্যাশবোর্ড</a>
          <hr>
          <a href="logout.php" class="danger"><i class="bi bi-box-arrow-right"></i> লগআউট</a>
        </div>
      </div>
      <button class="burger" id="burger" type="button" aria-label="মেনু" aria-expanded="false">☰</button>
    </div>
  </div>
</nav>

<div class="mm" id="mm">
  <a href="#features">ফিচার</a>
  <a href="question_bank_user.php">প্রশ্ন ব্যাংক</a>
  <a href="#analysis">বিশ্লেষণ</a>
  <a href="#courses">কোর্স</a>
  <a href="#how">কীভাবে</a>
  <a href="#faq">প্রশ্নোত্তর</a>
  <div class="row">
    <a href="deshboard.php" class="btn btn-p">ড্যাশবোর্ড</a>
    <a href="logout.php" class="btn btn-o">লগআউট</a>
  </div>
</div>

<!-- ===================== HERO ===================== -->
<header class="hero">
  <div class="wrap hgrid">
    <div>
      <span class="pill">স্বাগতম, <?= htmlspecialchars($loggedUserName, ENT_QUOTES, 'UTF-8') ?>!</span>
      <h1>চেস্টাতেই হোক <em>আপনার সকল প্রস্তুতি!</em></h1>
      <p class="lead">এক প্লাটফর্মে সব পরীক্ষা, স্মার্ট প্র্যাকটিস — সেরা প্রস্তুতি!</p>
      <div class="hbtn">
        <a class="btn btn-p btn-lg" href="deshboard.php">ড্যাশবোর্ডে যান →</a>
        <a class="btn btn-o btn-lg" href="question_bank_user.php">প্রশ্ন ব্যাংক দেখুন</a>
      </div>
      <div class="trust">
        <div class="av"><span>ব</span><span>র্ষা</span><span>জু</span><span>রা</span></div>
        <span>হাজারো শিক্ষার্থী প্রতিদিন অনুশীলন করছে</span>
      </div>
    </div>

    <div class="demo" aria-label="ঘোষণা">
      <?php if (!empty($banner['active'])): ?>
      <div class="banner-course" style="background:<?= htmlspecialchars($banner['bg'], ENT_QUOTES, 'UTF-8') ?>;">
        <?php if (!empty($banner['badge'])): ?>
          <span class="bc-tag"><?= htmlspecialchars($banner['badge'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <h2 class="bc-title"><?= htmlspecialchars($banner['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if (!empty($banner['subtitle'])): ?>
          <div class="bc-sub"><?= htmlspecialchars($banner['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($banner['meta'])): ?>
          <div class="bc-meta">
            <?php foreach ($banner['meta'] as $m): ?>
              <span><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($banner['cta_text']) && !empty($banner['cta_url'])): ?>
          <a class="bc-cta" href="<?= htmlspecialchars($banner['cta_url'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($banner['cta_text'], ENT_QUOTES, 'UTF-8') ?> →
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="wrap">
    <div class="stats">
      <div class="st st-1 rv"><b data-n="<?= $totalStudents ?>" data-s="+">০</b><span>নিবন্ধিত শিক্ষার্থী</span></div>
      <div class="st st-2 rv"><b data-n="<?= $totalQuestions ?>" data-s="+">০</b><span>MCQ প্রশ্ন</span></div>
      <div class="st st-3 rv"><b data-n="<?= $totalExamsCount ?>" data-s="+">০</b><span>মোট পরীক্ষা</span></div>
      <div class="st st-4 rv"><b data-n="<?= $totalCategories ?>" data-s="+">০</b><span>পরীক্ষার ক্যাটাগরি</span></div>
    </div>
  </div>
</header>

<!-- ===================== ANALYSIS ===================== -->
<section id="analysis" class="alt">
  <div class="wrap">
    <div class="head c rv" style="margin-bottom:32px;">
      <span class="eyebrow">লাইভ অ্যানালাইসিস (সিস্টেমে অন্তর্ভুক্ত প্রশ্নের উপর ভিত্তি করে)</span>
      <h2>বিষয়ভিত্তিক প্রশ্ন বিশ্লেষণ</h2>
      <p class="sub">আপনার লেভেল বেছে নিন — কোন বিষয়ে কত প্রশ্ন, আর কোথা থেকে বিগত পরীক্ষায় সবচেয়ে বেশি প্রশ্ন এসেছে তা দেখুন।</p>
      <div class="level-tabs" id="levelTabs">
        <span class="level-loading">লোড হচ্ছে…</span>
      </div>
    </div>

    <div id="analysisEmpty" class="analysis-empty" style="display:none;">
      <p>এই লেভেলে এখনো কোনো বিষয় যোগ করা হয়নি।</p>
    </div>

    <div id="analysisGrid" style="display:none;">
      <div class="analysis-grid">
        <div class="chart-card" style="position:relative;">
          <div class="chart-card-header">
            <div class="chart-card-title">
              <div class="chart-icon-badge">🥧</div>
              বিষয়ভিত্তিক প্রশ্নের হার
            </div>
            <span class="chart-pill" id="totalQuestionsPill">— টি মোট প্রশ্ন</span>
          </div>
          <div style="height:340px;">
            <canvas id="subjectPieChart"></canvas>
          </div>
          <div class="chart-loading" id="chartLoading">লোড হচ্ছে...</div>
        </div>

        <div class="chart-card" style="height:100%;">
          <div class="chart-card-title" style="margin-bottom:18px;">
            <div class="chart-icon-badge">📋</div>
            বিস্তারিত তালিকা
          </div>
          <div class="scroll-area" style="max-height:340px;overflow-y:auto;padding-right:6px;" id="subjectList"></div>
        </div>
      </div>

      <div class="chart-card" style="position:relative;">
        <div class="chart-card-header">
          <div class="chart-card-title">
            <div class="chart-icon-badge" style="background:var(--rl);">🎯</div>
            গুরুত্বপূর্ণ টপিক্স (বিগত পরীক্ষা অনুযায়ী)
          </div>
          <select id="importantTopicsSubject" class="subject-select"></select>
        </div>
        <div id="importantTopicsList" class="scroll-area" style="max-height:520px;overflow-y:auto;"></div>
        <div class="chart-loading" id="itLoading">লোড হচ্ছে...</div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== COURSES ===================== -->
<section id="courses">
  <div class="wrap">
    <div class="head c rv">
      <h2>আমাদের কোর্সসমূহ</h2>
    </div>

    <div class="course-grid">
      <?php foreach ($courses as $c):
        $titleBn  = $c['name'] ?: '';
        $subtitle = $c['subtitle'] ?: '';
        $subject  = $c['subject'] ?: '';
        $tag      = $c['tag'] ?: '';
        $discount = $c['discount'] ?: '';
        $meta     = $c['meta'] ?: '';
        $rating   = max(1, min(5, (int)($c['rating'] ?? 5)));
        $tone     = $c['tone'];
      ?>
      <div class="course-item">
        <a href="<?= htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8') ?>" class="course-card">
          <div class="course-card-top" style="background:<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($tag): ?>
              <span class="course-tag"><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>

            <h3 class="course-name"><?= htmlspecialchars($titleBn, ENT_QUOTES, 'UTF-8') ?></h3>

            <?php if ($subtitle): ?>
              <div class="course-subtitle-pill"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($subject): ?>
              <div class="course-subject-pill"><?= htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="course-meta-row">
              <?php if ($meta): ?>
                <span class="course-meta-text"><?= htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>

              <?php if ($discount): ?>
                <div class="course-discount-circle">
                  <?= htmlspecialchars($discount, ENT_QUOTES, 'UTF-8') ?>
                  <small>ছাড়</small>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="course-card-body">
            <div class="course-body-title">
              <?= htmlspecialchars($titleBn, ENT_QUOTES, 'UTF-8') ?>
              <?php if ($subtitle): ?> - <?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </div>

            <div class="course-bottom-row">
              <div class="course-stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i>
                <?php endfor; ?>
              </div>
              <span class="course-view-link">View Details</span>
            </div>

            <span class="course-cta-btn">কোর্সটি করুন</span>
          </div>
        </a>
      </div>
      <?php endforeach; ?>

      <?php if (empty($courses)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px 20px;background:#fff;border:1px dashed #e1e9e3;border-radius:18px;">
          <i class="bi bi-inbox" style="font-size:48px;color:#cbd5c4;"></i>
          <p style="color:#5a6c62;margin-top:12px;">কোনো কোর্স পাওয়া যায়নি।</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ===================== FEATURES ===================== -->
<section id="features" class="alt">
  <div class="wrap">
    <div class="head c rv">
      <span class="eyebrow">প্ল্যাটফর্মের সুবিধা</span>
      <h2>সফল হতে যা যা দরকার, সবই এখানে</h2>
      <p class="sub">নতুনদের জন্য সহজ অনুশীলন থেকে শুরু করে পূর্ণাঙ্গ মডেল টেস্ট পর্যন্ত।</p>
    </div>
    <div class="bento">
      <div class="bx hl s4 rv">
        <div class="ic">📈</div>
        <h3>বিস্তারিত পারফরম্যান্স বিশ্লেষণ</h3>
        <p>স্কোর, সঠিকতার হার আর উন্নতির ধারা দেখুন বিষয়ভিত্তিক গ্রাফে। কোথায় দুর্বল, সেটা ধরা পড়বে সহজেই।</p>
        <div class="bars" aria-hidden="true"><i style="height:30%"></i><i style="height:45%"></i><i style="height:38%"></i><i style="height:62%"></i><i style="height:55%"></i><i style="height:80%"></i><i style="height:92%"></i></div>
      </div>
      <div class="bx s2 rv"><div class="ic">🧠</div><h3>বিশাল প্রশ্ন ব্যাংক</h3><p>বিগত সালের প্রশ্নসহ হাজার হাজার MCQ, কোন কোন পরীক্ষায় এসেছে তার তথ্যসহ।</p></div>
      <div class="bx s2 rv"><div class="ic">⏱️</div><h3>সময়ভিত্তিক পরীক্ষা</h3><p>আসল পরীক্ষার চাপ অনুভব করতে টাইমার দিয়ে মডেল টেস্ট দিন।</p></div>
      <div class="bx s2 rv"><div class="ic">🏆</div><h3>লাইভ প্রতিযোগিতা</h3><p>হাজারো শিক্ষার্থীর সাথে লিডারবোর্ডে নিজের অবস্থান জানুন।</p></div>
      <div class="bx s2 rv"><div class="ic">📱</div><h3>মোবাইল-ফার্স্ট ডিজাইন</h3><p>ফোন, ট্যাবলেট বা কম্পিউটার, যেকোনো ডিভাইসে হালকা ও দ্রুত।</p></div>
    </div>
  </div>
</section>

<!-- ===================== HOW ===================== -->
<section id="how">
  <div class="wrap">
    <div class="head c rv">
      <span class="eyebrow">শুরু করবেন যেভাবে</span>
      <h2>মাত্র ৪টি ধাপে প্রস্তুতি শুরু</h2>
      <p class="sub">জটিল কিছু নেই। নিবন্ধন করুন, পরীক্ষা বেছে নিন, অনুশীলন শুরু করুন।</p>
    </div>
    <div class="steps">
      <div class="step rv"><h3>অ্যাকাউন্ট খুলুন</h3><p>ইমেইল ও মোবাইল নম্বর দিয়ে বিনামূল্যে নিবন্ধন করুন। অনুমোদন হয় ২৪ ঘণ্টার মধ্যে।</p></div>
      <div class="step rv"><h3>পরীক্ষা বেছে নিন</h3><p>বিসিএস, ব্যাংক, এসএসসি, এইচএসসিসহ আপনার লেভেল নির্বাচন করুন।</p></div>
      <div class="step rv"><h3>অনুশীলন করুন</h3><p>প্রশ্নের উত্তর দিন, সাথে সাথে ব্যাখ্যা দেখে ধারণা পরিষ্কার করুন।</p></div>
      <div class="step rv"><h3>উন্নতি ট্র্যাক করুন</h3><p>ফলাফল বিশ্লেষণ দেখে দুর্বল বিষয় ঠিক করুন, এগিয়ে যান।</p></div>
    </div>
  </div>
</section>

<!-- ===================== FAQ ===================== -->
<section id="faq" class="alt">
  <div class="wrap">
    <div class="head c rv"><span class="eyebrow">প্রশ্নোত্তর</span><h2>সচরাচর জিজ্ঞাসা</h2></div>
    <div class="faq">
      <details class="q rv"><summary>চেষ্টা কি বিনামূল্যে ব্যবহার করা যায়?</summary><p>হ্যাঁ। নিবন্ধন এবং হাজার হাজার প্রশ্নে প্রবেশাধিকার সম্পূর্ণ বিনামূল্যে।</p></details>
      <details class="q rv"><summary>অ্যাকাউন্ট অনুমোদন হতে কত সময় লাগে?</summary><p>নিবন্ধনের পর আমাদের টিম ২৪ ঘণ্টার মধ্যে অ্যাকাউন্ট যাচাই করে অনুমোদন দেয়।</p></details>
      <details class="q rv"><summary>আমার লগইন পাসওয়ার্ড কী হবে?</summary><p>নিবন্ধনের সময় দেওয়া আপনার মোবাইল নম্বরই শুরুতে পাসওয়ার্ড। লগইন করার পর প্রোফাইল থেকে এটি বদলে নিন।</p></details>
      <details class="q rv"><summary>মোবাইল ফোনে কি ব্যবহার করা যাবে?</summary><p>অবশ্যই। সাইটটি ফোন, ট্যাবলেট ও কম্পিউটার সব ডিভাইসে চলে।</p></details>
      <details class="q rv"><summary>পরীক্ষার ফলাফল কীভাবে নির্ধারিত হয়?</summary><p>জমা দেওয়ার সাথে সাথে ফলাফল পাওয়া যায়। সঠিক উত্তরে নম্বর যোগ হয়, নেগেটিভ মার্কিং চালু থাকলে ভুল উত্তরে নম্বর কাটা যায়।</p></details>
    </div>
  </div>
</section>

<!-- ===================== CTA ===================== -->
<section style="padding-top:0">
  <div class="wrap">
    <div class="cta rv">
      <h2>আপনার প্রস্তুতির যাত্রা চালিয়ে যান</h2>
      <p>ড্যাশবোর্ডে ফিরে যান এবং আপনার অগ্রগতি দেখুন, বাকি পরীক্ষা সম্পন্ন করুন।</p>
      <div class="hbtn">
        <a class="btn btn-w btn-lg" href="deshboard.php">ড্যাশবোর্ডে যান</a>
        <a class="btn btn-ghost btn-lg" href="question_bank_user.php">প্রশ্ন ব্যাংক</a>
      </div>
    </div>
  </div>
</section>

<!-- ===================== FOOTER ===================== -->
<footer>
  <div class="wrap">
    <div class="fg">
      <div>
        <a class="logo" href="index_user.php" style="margin:0" aria-label="Chesta হোম">
          <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Chesta" class="logo-img">
          <span class="logo-text"><b></b></span>
        </a>
        <p>বাংলাদেশের সেরা প্রস্তুতির প্ল্যাটফর্ম। স্মার্ট অনুশীলন, সেরা ফলাফল।</p>
      </div>
      <div>
        <h4>প্ল্যাটফর্ম</h4>
        <a href="#features">ফিচার</a>
        <a href="#analysis">বিশ্লেষণ</a>
        <a href="#how">কীভাবে কাজ করে</a>
        <a href="#faq">প্রশ্নোত্তর</a>
      </div>
      <div>
        <h4>অনুশীলন</h4>
        <a href="question_bank_user.php">প্রশ্ন ব্যাংক</a>
        <a href="#courses">কোর্সসমূহ</a>
      </div>
      <div>
        <h4>অ্যাকাউন্ট</h4>
        <a href="deshboard.php">ড্যাশবোর্ড</a>
        <a href="profile.php">প্রোফাইল</a>
        <a href="logout.php">লগআউট</a>
      </div>
    </div>
    <div class="fb">
      <span>©chesta <?= date('Y') ?> । All rights reserved.</span>
      <span>বাংলাদেশের শিক্ষার্থীদের জন্য ❤️</span>
    </div>
  </div>
</footer>

<script>
/* ============== Page config ============== */
window.EXAM360_LEVELS           = <?= $levelsJson ?>;
window.EXAM360_DEFAULT_LEVEL_ID = <?= (int)$defaultAnalysisLevelId ?>;

(function () {
  'use strict';

  const $  = s => document.querySelector(s);
  const $$ = s => document.querySelectorAll(s);
  const BN = '০১২৩৪৫৬৭৮৯';
  const bn  = n => String(n).replace(/\d/g, d => BN[d]);
  const bnComma = n => {
    const s = String(n);
    if (s.length <= 3) return bn(s);
    const l = s.slice(-3);
    const r = s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    return bn(r + ',' + l);
  };

  /* Sticky nav */
  const nav = $('#nav');
  if (nav) addEventListener('scroll', () => nav.classList.toggle('s', scrollY > 8), { passive: true });

  /* Mobile menu */
  const mm = $('#mm'), bg = $('#burger');
  if (bg && mm) {
    bg.onclick = () => {
      const o = mm.classList.toggle('on');
      bg.setAttribute('aria-expanded', o);
      bg.textContent = o ? '✕' : '☰';
    };
    mm.addEventListener('click', e => {
      if (e.target.closest('a,button')) {
        mm.classList.remove('on');
        bg.textContent = '☰';
        bg.setAttribute('aria-expanded', false);
      }
    });
  }

  /* User dropdown */
  const userMenu = $('#userMenu'), userChip = $('#userChip');
  if (userMenu && userChip) {
    userChip.addEventListener('click', e => {
      e.preventDefault();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', e => {
      if (!userMenu.contains(e.target)) userMenu.classList.remove('open');
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') userMenu.classList.remove('open');
    });
  }

  /* Reveal on scroll + counter animation */
  const io = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return;
    e.target.classList.add('in');
    io.unobserve(e.target);
    const c = e.target.querySelector && e.target.querySelector('[data-n]');
    if (c) count(c);
  }), { threshold: .15 });
  $$('.rv').forEach(el => io.observe(el));

  function count(el) {
    const to = +el.dataset.n, s = el.dataset.s || '', t0 = performance.now(), d = 1200;
    (function f(t) {
      const p = Math.min(1, (t - t0) / d);
      const v = Math.round(to * (1 - Math.pow(1 - p, 3)));
      el.textContent = bnComma(v) + s;
      if (p < 1) requestAnimationFrame(f);
    })(t0);
  }

  /* ================= ANALYSIS ================= */
  const tabsEl = $('#levelTabs');
  if (!tabsEl) return;

  const LEVELS           = window.EXAM360_LEVELS || [];
  const DEFAULT_LEVEL_ID = window.EXAM360_DEFAULT_LEVEL_ID || 3;
  const PIE_COLORS       = ['#00693e','#0a8f55','#f42a41','#d9a441','#3b82a0','#8e54e9','#43e97b','#fa8231','#4776e6','#e64980'];
  const banglaFont       = "'Hind Siliguri', system-ui, sans-serif";

  let currentChart = null;
  let currentLevelId = null;

  const gridEl    = $('#analysisGrid');
  const emptyEl   = $('#analysisEmpty');
  const listEl    = $('#subjectList');
  const pillEl    = $('#totalQuestionsPill');
  const selectEl  = $('#importantTopicsSubject');
  const topicsEl  = $('#importantTopicsList');
  const itLoadEl  = $('#itLoading');
  const chartLoad = $('#chartLoading');

  const esc = s => String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  function renderTabs() {
    if (!LEVELS.length) {
      tabsEl.innerHTML = '<span class="level-loading">কোনো লেভেল পাওয়া যায়নি।</span>';
      return;
    }
    let defaultLevel = LEVELS.find(l => Number(l.id) === Number(DEFAULT_LEVEL_ID));
    if (!defaultLevel) defaultLevel = LEVELS[0];

    tabsEl.innerHTML = LEVELS.map(l =>
      `<button type="button" class="level-tab${l.id == defaultLevel.id ? ' active' : ''}" data-level="${l.id}">${esc(l.name)}</button>`
    ).join('');

    tabsEl.querySelectorAll('.level-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.dataset.level == currentLevelId) return;
        tabsEl.querySelectorAll('.level-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        loadLevel(btn.dataset.level);
      });
    });

    loadLevel(defaultLevel.id);
  }

  function loadLevel(levelId) {
    currentLevelId = levelId;
    gridEl.style.display = 'none';
    emptyEl.style.display = 'none';
    chartLoad.classList.add('active');

    fetch('api_analysis.php?action=distribution&level_id=' + encodeURIComponent(levelId))
      .then(r => r.json())
      .then(data => {
        if (data.error) throw new Error(data.error);

        if (!data.subjects || !data.subjects.length || data.total === 0) {
          emptyEl.style.display = 'block';
          return;
        }

        gridEl.style.display = 'block';
        renderChart(data.subjects, data.total);
        renderSubjectList(data.subjects, data.total);
        renderSubjectSelect(data.subjects, data.default_subject);
        renderTopics(data.topics || []);
      })
      .catch(err => {
        console.error('loadLevel:', err);
        emptyEl.style.display = 'block';
        emptyEl.querySelector('p').textContent = 'লোড করতে সমস্যা হয়েছে।';
      })
      .finally(() => chartLoad.classList.remove('active'));
  }

  function renderChart(subjects, total) {
    const canvas = document.getElementById('subjectPieChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (currentChart) { currentChart.destroy(); currentChart = null; }

    pillEl.textContent = total + ' টি মোট প্রশ্ন';

    currentChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: subjects.map(s => s.label),
        datasets: [{
          data: subjects.map(s => Number(s.cnt) || 0),
          backgroundColor: subjects.map((_, i) => PIE_COLORS[i % PIE_COLORS.length]),
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
            padding: 12, cornerRadius: 10,
            callbacks: {
              label: function (item) {
                const v = item.raw;
                const pct = total > 0 ? ((v / total) * 100).toFixed(1) : 0;
                return ' ' + item.label + ': ' + v + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });
  }

  function renderSubjectList(subjects, total) {
    if (!subjects.length) {
      listEl.innerHTML = '<p style="text-align:center;color:var(--mut);padding:20px;">কোনো বিষয় নেই।</p>';
      return;
    }
    listEl.innerHTML = subjects.map((s, i) => {
      const pct = total > 0 ? ((s.cnt / total) * 100).toFixed(1) : 0;
      const color = PIE_COLORS[i % PIE_COLORS.length];
      const zero = Number(s.cnt) === 0 ? ' — এখনো যোগ করা হয়নি' : '';
      return `<div class="exam-item">
        <div class="exam-dot" style="background:${color}"></div>
        <div class="exam-info">
          <div class="exam-subj">${esc(s.label)}</div>
          <div class="exam-meta">${Number(s.cnt)} টি প্রশ্ন${zero}</div>
        </div>
        <div class="exam-right">
          <div class="exam-subj" style="color:${color}">${pct}%</div>
        </div>
      </div>`;
    }).join('');
  }

  function renderSubjectSelect(subjects, defaultId) {
    selectEl.innerHTML = subjects.map(s =>
      `<option value="${s.subject_id}"${s.subject_id == defaultId ? ' selected' : ''}>${esc(s.label)}</option>`
    ).join('');
  }

  function renderTopics(topics) {
    if (!topics || !topics.length) {
      topicsEl.innerHTML = '<p style="text-align:center;color:var(--mut);padding:20px;">এই বিষয়ে বিগত পরীক্ষায় কোনো প্রশ্ন ব্যবহৃত হয়নি।</p>';
      return;
    }
    topicsEl.innerHTML = topics.map((tp, i) => {
      const color = PIE_COLORS[i % PIE_COLORS.length];
      return `<div class="exam-item">
        <div class="exam-dot" style="background:${color};width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:800;border-radius:9px;">${i + 1}</div>
        <div class="exam-info">
          <div class="exam-subj">${esc(tp.label)}</div>
          <div class="exam-meta">${Number(tp.exam_count)} টি ভিন্ন পরীক্ষায় এসেছে</div>
        </div>
        <div class="exam-right">
          <div class="exam-subj" style="color:var(--g)">${Number(tp.exam_freq)} টি প্রশ্ন</div>
        </div>
      </div>`;
    }).join('');
  }

  selectEl?.addEventListener('change', function () {
    const sid = this.value;
    itLoadEl.classList.add('active');

    fetch('api_analysis.php?action=topics&subject_id=' + encodeURIComponent(sid))
      .then(r => r.json())
      .then(data => {
        if (data.error) throw new Error(data.error);
        renderTopics(data.topics || []);
      })
      .catch(err => {
        console.error(err);
        topicsEl.innerHTML = '<p style="text-align:center;color:var(--r);padding:20px;">লোড করতে সমস্যা হয়েছে।</p>';
      })
      .finally(() => itLoadEl.classList.remove('active'));
  });

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(renderTabs);
  } else {
    renderTabs();
  }
})();
</script>
</body>
</html>