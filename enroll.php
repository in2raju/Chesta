<?php
session_start();
require 'db.php';
require 'config_payment.php';   // h(), csrf_token(), csrf_ok(), course_price(), activate_enrollment() ...

$courseId = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);

// Ensure user is logged in (লগইনের পর আবার এই পেজেই ফিরে আসবে)
if (!isset($_SESSION['user'])) {
    header("Location: login.php?next=" . urlencode('enroll.php?course_id=' . $courseId));
    exit();
}

// Logged-in user info
$sessUser   = $_SESSION['user'];
$uid        = $sessUser['user_id'] ?? $sessUser['id'] ?? '';
$userType   = (int)($sessUser['user_type_id'] ?? 0);
$orgCode    = $sessUser['org_code'] ?? '';
$brCode     = $sessUser['br_code'] ?? '';
$canInsert  = $_SESSION['user']['can_insert']  ?? 0;
$canEdit    = $_SESSION['user']['can_edit']    ?? 0;
$canDelete  = $_SESSION['user']['can_delete']  ?? 0;
$canApprove = $_SESSION['user']['can_approve'] ?? 0;

// ভর্তির পারমিশন: স্টুডেন্ট (টাইপ ৫) অথবা যার insert পারমিশন আছে
$canEnroll = ($userType === 5) || (int)$canInsert === 1;

/* ---------- কোর্স ---------- */
$stmt = $pdo->prepare("SELECT id, title, title_bn, subtitle, subject_line, price, discount_price, tone_gradient, duration_days
                       FROM courses
                       WHERE id = :id AND DELETE_DATE IS NULL AND is_active = 1 AND AUTHORIZED_STATUS = 'Y'");
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(404);
    exit('কোর্সটি পাওয়া যায়নি।');
}

$amount   = course_price($course);
$oldPrice = (float)$course['price'];
$cname    = $course['title_bn'] ?: $course['title'];
$tone     = $course['tone_gradient'] ?: 'linear-gradient(135deg,#7a1122 0%,#a31528 100%)';
$fmt      = fn($n) => '৳' . en_to_bn(number_format($n, 0));

/* ---------- ইউজারের তথ্য (গেটওয়েতে পাঠাতে) ---------- */
$stmt = $pdo->prepare("SELECT USER_ID, USER_NAME, EMAIL, PHONE FROM user_login_info WHERE USER_ID = :id");
$stmt->execute(['id' => $uid]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['USER_NAME' => '', 'EMAIL' => $uid, 'PHONE' => ''];

/* ---------- বর্তমান অবস্থা ---------- */
$stmt = $pdo->prepare("SELECT * FROM course_enrollments WHERE user_id = :u AND course_id = :c");
$stmt->execute(['u' => $uid, 'c' => $courseId]);
$enr = $stmt->fetch(PDO::FETCH_ASSOC);

$isActive = $enr && $enr['status'] === 'active'
            && (empty($enr['expires_at']) || strtotime($enr['expires_at']) > time());
$isVerifying = false;
if ($enr && !$isActive) {
    $stmt = $pdo->prepare("SELECT 1 FROM payments WHERE enrollment_id = :e AND status = 'verifying' LIMIT 1");
    $stmt->execute(['e' => $enr['id']]);
    $isVerifying = (bool)$stmt->fetchColumn();
}

$methods = $pdo->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id")
               ->fetchAll(PDO::FETCH_ASSOC);

$notice    = ['', ''];   // [type, text]  type: ok | info | err
$activeTab = (($_POST['action'] ?? '') === 'manual') ? 'manual' : 'online';

// ----------------------
// Handle Payment (POST)
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$isActive && !$isVerifying) {
    $action = $_POST['action'] ?? '';

    if (!$canEnroll) {
        $notice = ['err', 'আপনার অ্যাকাউন্টে কোর্সে ভর্তি হওয়ার অনুমতি নেই।'];
    }
    elseif (!csrf_ok()) {
        $notice = ['err', 'সেশনের মেয়াদ শেষ হয়েছে। পেজটি রিফ্রেশ করে আবার চেষ্টা করুন।'];
    }
    /* --- ফ্রি কোর্স --- */
    elseif ($action === 'free' && $amount <= 0) {
        $eid = get_or_create_enrollment($pdo, $uid, $courseId);
        $pdo->prepare("INSERT INTO payments (enrollment_id,user_id,course_id,amount,method_type,provider,status,paid_at)
                       VALUES (?,?,?,0,'free','free','paid',NOW())")->execute([$eid, $uid, $courseId]);
        activate_enrollment($pdo, $eid);
        header("Location: enroll.php?course_id=" . $courseId . "&paid=1");
        exit();
    }
    /* --- অনলাইন পেমেন্ট (SSLCommerz) --- */
    elseif ($action === 'online' && $amount > 0) {
        $eid  = get_or_create_enrollment($pdo, $uid, $courseId);
        $tran = 'CH' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));

        $pdo->prepare("INSERT INTO payments (enrollment_id,user_id,course_id,amount,method_type,provider,tran_id,status)
                       VALUES (?,?,?,?, 'gateway','sslcommerz',?, 'pending')")
            ->execute([$eid, $uid, $courseId, $amount, $tran]);

        $base = site_base_url();
        $resp = http_request(SSLC_INIT_URL, [
            'store_id'         => SSLC_STORE_ID,
            'store_passwd'     => SSLC_STORE_PASS,
            'total_amount'     => number_format($amount, 2, '.', ''),
            'currency'         => 'BDT',
            'tran_id'          => $tran,
            'success_url'      => $base . '/payment_callback.php?a=success',
            'fail_url'         => $base . '/payment_callback.php?a=fail',
            'cancel_url'       => $base . '/payment_callback.php?a=cancel',
            'ipn_url'          => $base . '/payment_callback.php?a=ipn',
            'cus_name'         => $profile['USER_NAME'] ?: 'Student',
            'cus_email'        => $profile['EMAIL'] ?: $uid,
            'cus_phone'        => $profile['PHONE'] ?: '01700000000',
            'cus_add1'         => 'Dhaka',
            'cus_city'         => 'Dhaka',
            'cus_country'      => 'Bangladesh',
            'shipping_method'  => 'NO',
            'product_name'     => mb_substr($course['title'], 0, 100),
            'product_category' => 'Course',
            'product_profile'  => 'non-physical-goods',
            'value_a'          => (string)$courseId,
        ]);

        if ($resp && ($resp['status'] ?? '') === 'SUCCESS' && !empty($resp['GatewayPageURL'])) {
            header('Location: ' . $resp['GatewayPageURL']);
            exit();
        }
        $pdo->prepare("UPDATE payments SET status='failed', gateway_response=? WHERE tran_id=?")
            ->execute([json_encode($resp, JSON_UNESCAPED_UNICODE), $tran]);
        $notice = ['err', 'পেমেন্ট গেটওয়ে চালু করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন বা ম্যানুয়াল পেমেন্ট ব্যবহার করুন।'];
    }
    /* --- ম্যানুয়াল পেমেন্ট --- */
    elseif ($action === 'manual' && $amount > 0) {
        $mid    = (int)($_POST['method_id'] ?? 0);
        $sender = bn_digits_to_en(trim($_POST['sender'] ?? ''));
        $trx    = strtoupper(trim($_POST['trx'] ?? ''));

        $method = null;
        foreach ($methods as $m) if ((int)$m['id'] === $mid) $method = $m;

        if (!$method)                                      $notice = ['err', 'একটি পেমেন্ট মাধ্যম বেছে নিন।'];
        elseif (!preg_match('/^[0-9]{10,15}$/', $sender))  $notice = ['err', 'যে নম্বর থেকে টাকা পাঠিয়েছেন সেটি ১০–১৫ অংকে দিন।'];
        elseif (!preg_match('/^[A-Z0-9]{6,20}$/', $trx))   $notice = ['err', 'TrxID সঠিক নয় (শুধু ইংরেজি অক্ষর ও সংখ্যা, ৬–২০ অক্ষর)।'];
        else {
            try {
                $eid = get_or_create_enrollment($pdo, $uid, $courseId);
                $pdo->prepare("INSERT INTO payments
                    (enrollment_id,user_id,course_id,amount,method_type,provider,payment_method_id,sender_number,manual_trx_id,status)
                    VALUES (?,?,?,?, 'manual','manual',?,?,?, 'verifying')")
                    ->execute([$eid, $uid, $courseId, $amount, $mid, $sender, $trx]);
                header("Location: enroll.php?course_id=" . $courseId . "&submitted=1");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $notice = ['err', 'এই TrxID আগেই জমা দেওয়া হয়েছে। সঠিক TrxID দিন।'];
                } else {
                    error_log('manual pay: ' . $e->getMessage());
                    $notice = ['err', 'জমা দেওয়া যায়নি। আবার চেষ্টা করুন।'];
                }
            }
        }
    }
}

/* ---------- ফ্ল্যাশ মেসেজ (redirect-এর পর) ---------- */
if ($notice[0] === '') {
    if (isset($_GET['paid']) && $isActive)  $notice = ['ok',   'পেমেন্ট সফল! কোর্সে আপনার ভর্তি সম্পন্ন হয়েছে।'];
    elseif (isset($_GET['submitted']))      $notice = ['info', 'আপনার পেমেন্টের তথ্য জমা হয়েছে। যাচাই শেষে ২৪ ঘণ্টার মধ্যে কোর্স চালু হবে।'];
    elseif (isset($_GET['failed']))         $notice = ['err',  'পেমেন্ট সম্পন্ন হয়নি। আবার চেষ্টা করুন।'];
    elseif (isset($_GET['cancelled']))      $notice = ['err',  'আপনি পেমেন্ট বাতিল করেছেন।'];
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Course Enrollment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* সব স্টাইল .enr র‍্যাপারের ভেতরে সীমিত, প্রিফিক্স "en-" — header/Bootstrap-এর সাথে সংঘর্ষ নেই */
    .enr{--ink:#10231a;--mut:#5a6c62;--line:#e1e9e3;--surface:#f2f6f3;--card:#fff;--g:#00693e;--g2:#0a8f55;--gl:#e4f2e9;--r:#f42a41;--rl:#fdecee;
         font-family:'Hind Siliguri',system-ui,'Nirmala UI','Kohinoor Bangla',sans-serif;color:var(--ink);line-height:1.7}
    .enr *,.enr *::before,.enr *::after{box-sizing:border-box}
    .enr button,.enr input{font:inherit}
    .enr :focus-visible{outline:3px solid var(--g);outline-offset:2px}
    .en-back{display:inline-block;margin-bottom:16px;text-decoration:none;color:var(--mut);font-weight:500}
    .en-grid{display:grid;grid-template-columns:1fr 1.15fr;gap:22px;align-items:start}
    @media(max-width:820px){.en-grid{grid-template-columns:1fr}}
    .en-box{background:var(--card);border:1px solid var(--line);border-radius:20px;overflow:hidden}
    .en-sum-top{padding:30px 22px;color:#fff;text-align:center;display:grid;gap:10px;justify-items:center}
    .en-sum-top h1{font-size:1.45rem;line-height:1.35;margin:0;color:#fff}
    .en-pill{background:#fff;color:#8b0d20;font-weight:700;font-size:.85rem;padding:5px 14px;border-radius:30px}
    .en-sum-body{padding:20px 22px}
    .en-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);font-size:.95rem}
    .en-row:last-child{border:0}
    .en-row.total{font-weight:700;font-size:1.15rem}
    .en-row s{color:var(--mut);margin-right:8px;font-weight:400}
    .en-pay{padding:24px}
    .en-pay h2{font-size:1.25rem;margin:0 0 16px}
    .en-tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px}
    .en-tab{border:1.5px solid var(--line);background:var(--surface);border-radius:14px;padding:12px 10px;text-align:center;font-weight:600;cursor:pointer;color:var(--ink)}
    .en-tab small{display:block;font-weight:400;color:var(--mut);font-size:.78rem}
    .en-tab[aria-selected=true]{border-color:var(--g);background:var(--gl);color:var(--g)}
    .en-panel[hidden]{display:none}
    .en-btn{display:flex;align-items:center;justify-content:center;width:100%;height:50px;border:0;border-radius:99px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;font-weight:700;font-size:1.02rem;cursor:pointer;text-decoration:none}
    .en-btn:hover{color:#fff;box-shadow:0 10px 26px -8px var(--g)}
    .en-btn:disabled{opacity:.6;cursor:wait}
    .en-note{color:var(--mut);font-size:.9rem;margin:0 0 14px}
    .en-msg{padding:12px 14px;border-radius:12px;font-size:.93rem;margin-bottom:16px;border:1px solid}
    .en-msg.ok{background:var(--gl);color:var(--g);border-color:var(--g)}
    .en-msg.info{background:var(--surface);border-color:var(--line)}
    .en-msg.err{background:var(--rl);color:var(--r);border-color:var(--r)}
    .en-methods{display:grid;gap:8px;margin-bottom:14px}
    .en-method{display:flex;gap:12px;align-items:center;border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;cursor:pointer;background:var(--surface);margin:0}
    .en-method:has(input:checked){border-color:var(--g);background:var(--gl)}
    .en-method b{display:block}
    .en-method span{color:var(--mut);font-size:.88rem}
    .en-instr{background:var(--surface);border:1px dashed var(--line);border-radius:14px;padding:14px;font-size:.92rem;margin-bottom:14px}
    .en-instr strong{color:var(--g)}
    .en-fgp{margin-bottom:14px}
    .en-fgp label{display:block;font-weight:600;font-size:.88rem;margin-bottom:5px}
    .en-fc{width:100%;height:48px;border:1.5px solid var(--line);border-radius:12px;background:var(--surface);padding:0 14px;font-size:16px;color:var(--ink)}
    .en-fc:focus{border-color:var(--g);background:#fff;outline:0}
    .en-hint{font-size:.78rem;color:var(--mut);margin-top:4px}
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
<div class="enr">
    <a class="en-back" href="index.php#courses">← সব কোর্স</a>

    <?php if ($notice[0] !== ''): ?>
        <div class="en-msg <?= h($notice[0]) ?>" role="status"><?= h($notice[1]) ?></div>
    <?php endif; ?>

    <div class="en-grid">
        <!-- কোর্সের সারাংশ -->
        <aside class="en-box">
            <div class="en-sum-top" style="background:<?= h($tone) ?>">
                <h1><?= h($cname) ?></h1>
                <?php if ($course['subtitle']): ?><span class="en-pill"><?= h($course['subtitle']) ?></span><?php endif; ?>
            </div>
            <div class="en-sum-body">
                <?php if ($course['subject_line']): ?><div class="en-row"><span>বিষয়</span><span><?= h($course['subject_line']) ?></span></div><?php endif; ?>
                <div class="en-row"><span>মেয়াদ</span><span><?= (int)$course['duration_days'] > 0 ? en_to_bn((string)(int)$course['duration_days']) . ' দিন' : 'আজীবন' ?></span></div>
                <div class="en-row"><span>শিক্ষার্থী</span><span><?= h($profile['USER_NAME'] ?: $uid) ?></span></div>
                <div class="en-row total"><span>মোট</span>
                    <span><?php if ($oldPrice > $amount): ?><s><?= $fmt($oldPrice) ?></s><?php endif; ?><?= $amount > 0 ? $fmt($amount) : 'ফ্রি' ?></span></div>
            </div>
        </aside>

        <!-- পেমেন্ট -->
        <section class="en-box en-pay">
        <?php if ($isActive): ?>
            <h2>আপনি এই কোর্সে ভর্তি আছেন</h2>
            <p class="en-note">কোর্সের কনটেন্ট ও পরীক্ষা আপনার ড্যাশবোর্ডে পাবেন।</p>
            <a class="en-btn" href="deshboard.php">ড্যাশবোর্ডে যান</a>

        <?php elseif ($isVerifying): ?>
            <h2>পেমেন্ট যাচাই চলছে</h2>
            <p class="en-note">আপনার TrxID আমাদের টিম মিলিয়ে দেখছে। অনুমোদন হলে কোর্স চালু হবে।</p>

        <?php elseif (!$canEnroll): ?>
            <h2>ভর্তির অনুমতি নেই</h2>
            <p class="en-note">এই অ্যাকাউন্ট দিয়ে কোর্সে ভর্তি হওয়া যাবে না। শিক্ষার্থী অ্যাকাউন্ট দিয়ে লগইন করুন।</p>

        <?php elseif ($amount <= 0): ?>
            <h2>এই কোর্সটি ফ্রি</h2>
            <form method="post" class="en-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="action" value="free">
                <button class="en-btn" type="submit">ফ্রিতে ভর্তি হন</button>
            </form>

        <?php else: ?>
            <h2>পেমেন্ট পদ্ধতি বেছে নিন</h2>
            <div class="en-tabs" role="tablist">
                <button type="button" class="en-tab" role="tab" id="t-online" aria-selected="<?= $activeTab === 'online' ? 'true' : 'false' ?>" aria-controls="p-online">অনলাইন পেমেন্ট<small>কার্ড · bKash · Nagad · ব্যাংকিং</small></button>
                <button type="button" class="en-tab" role="tab" id="t-manual" aria-selected="<?= $activeTab === 'manual' ? 'true' : 'false' ?>" aria-controls="p-manual">ম্যানুয়াল পেমেন্ট<small>Send Money + TrxID</small></button>
            </div>

            <!-- অনলাইন -->
            <form method="post" class="en-panel en-form" id="p-online" role="tabpanel" aria-labelledby="t-online" <?= $activeTab === 'online' ? '' : 'hidden' ?>>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="action" value="online">
                <p class="en-note">পেমেন্ট সম্পন্ন হলে সাথে সাথে কোর্স চালু হবে। নিরাপদ পেমেন্ট পেজে আপনাকে নিয়ে যাওয়া হবে।</p>
                <button class="en-btn" type="submit"><?= $fmt($amount) ?> পেমেন্ট করুন</button>
            </form>

            <!-- ম্যানুয়াল -->
            <form method="post" class="en-panel en-form" id="p-manual" role="tabpanel" aria-labelledby="t-manual" <?= $activeTab === 'manual' ? '' : 'hidden' ?>>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="action" value="manual">

                <?php if (!$methods): ?>
                    <p class="en-note">এই মুহূর্তে ম্যানুয়াল পেমেন্ট চালু নেই। অনলাইন পেমেন্ট ব্যবহার করুন।</p>
                <?php else: ?>
                <div class="en-methods">
                    <?php foreach ($methods as $i => $m): ?>
                    <label class="en-method">
                        <input type="radio" name="method_id" value="<?= (int)$m['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>
                               data-no="<?= h($m['account_no']) ?>" data-type="<?= h($m['account_type']) ?>" data-ins="<?= h($m['instructions']) ?>">
                        <div><b><?= h($m['name']) ?></b><span><?= h($m['account_type']) ?> · <?= h($m['account_no']) ?></span></div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="en-instr" id="instr" aria-live="polite"></div>

                <div class="en-fgp">
                    <label for="sender">যে নম্বর থেকে টাকা পাঠিয়েছেন</label>
                    <input class="en-fc" id="sender" name="sender" type="tel" inputmode="numeric" maxlength="15" placeholder="01XXXXXXXXX" required
                           value="<?= h($_POST['sender'] ?? $profile['PHONE']) ?>">
                </div>
                <div class="en-fgp">
                    <label for="trx">TrxID</label>
                    <input class="en-fc" id="trx" name="trx" type="text" maxlength="20" placeholder="যেমন: 8N7A6D5EE7" autocapitalize="characters" required
                           value="<?= h($_POST['trx'] ?? '') ?>">
                    <div class="en-hint">টাকা পাঠানোর পর পাওয়া SMS বা অ্যাপের রসিদে TrxID থাকে।</div>
                </div>
                <button class="en-btn" type="submit">পেমেন্ট তথ্য জমা দিন</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        </section>
    </div>
</div>
</main>

<?php include 'footer.php'; ?>
<script>
(function () {
    var AMOUNT = <?= json_encode($fmt($amount), JSON_UNESCAPED_UNICODE) ?>;

    /* ট্যাব */
    var tabs = document.querySelectorAll('.en-tab');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) {
                var on = (x === t);
                x.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
            });
        });
    });

    /* নির্বাচিত ম্যানুয়াল মাধ্যমের নির্দেশনা */
    var instr = document.getElementById('instr');
    function showInstr() {
        var r = document.querySelector('input[name=method_id]:checked');
        if (!r || !instr) return;
        instr.textContent = '';
        var p = document.createElement('p');
        p.style.margin = '0';
        var b1 = document.createElement('strong'); b1.textContent = r.dataset.no + ' (' + r.dataset.type + ')';
        var b2 = document.createElement('strong'); b2.textContent = AMOUNT;
        p.append(b1, ' নম্বরে ', b2, ' টাকা Send Money করুন।');
        instr.appendChild(p);
        if (r.dataset.ins) {
            var q = document.createElement('p');
            q.style.cssText = 'margin:8px 0 0;color:#5a6c62;white-space:pre-line';
            q.textContent = r.dataset.ins;
            instr.appendChild(q);
        }
    }
    document.querySelectorAll('input[name=method_id]').forEach(function (r) { r.addEventListener('change', showInstr); });
    showInstr();

    /* ডাবল সাবমিট ঠেকানো */
    document.querySelectorAll('form.en-form').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type=submit]');
            if (b) { b.disabled = true; b.textContent = 'অপেক্ষা করুন…'; }
        });
    });
})();
</script>
</body>
</html>