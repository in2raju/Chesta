<?php
session_start();
require 'db.php';
require 'config_payment.php';   // h(), csrf_token(), csrf_ok()

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$user      = $_SESSION['user'];
$userId    = $user['user_id'] ?? $user['id'] ?? '';
$userType  = (int)($user['user_type_id'] ?? 0);
$orgCode   = $user['org_code'] ?? '';
$brCode    = $user['br_code'] ?? '';
$canInsert  = $_SESSION['user']['can_insert']  ?? 0;
$canEdit    = $_SESSION['user']['can_edit']    ?? 0;
$canDelete  = $_SESSION['user']['can_delete']  ?? 0;
$canApprove = $_SESSION['user']['can_approve'] ?? 0;

// স্টুডেন্ট এই পেজ দেখতে পারবে না
if ($userType === 5 || !($canInsert || $canEdit || $canDelete)) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}

$notice = ['', ''];   // [type, text]  type: ok | err
$editMethod = null;

// ----------------------
// Handle Add / Update / Toggle / Delete (POST + CSRF)
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'save';

    if (!csrf_ok()) {
        $notice = ['err', 'সেশন শেষ হয়েছে, পেজ রিফ্রেশ করুন।'];
    }
    // --- Add / Update ---
    elseif ($action === 'save') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        $name     = mb_substr(trim($_POST['name'] ?? ''), 0, 50);
        $type     = mb_substr(trim($_POST['account_type'] ?? 'Personal'), 0, 30);
        $accNo    = mb_substr(trim($_POST['account_no'] ?? ''), 0, 40);
        $instr    = trim($_POST['instructions'] ?? '');
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || $accNo === '') {
            $notice = ['err', 'মাধ্যমের নাম ও অ্যাকাউন্ট নম্বর দেওয়া বাধ্যতামূলক।'];
        } elseif ($methodId) {
            // Update
            if ($canEdit) {
                $stmt = $pdo->prepare("UPDATE payment_methods
                                       SET name = :name, account_type = :type, account_no = :acc, instructions = :ins, sort_order = :sort
                                       WHERE id = :id");
                $stmt->execute(['name' => $name, 'type' => $type, 'acc' => $accNo, 'ins' => $instr, 'sort' => $sort, 'id' => $methodId]);
                $notice = ['ok', 'পেমেন্ট মাধ্যম আপডেট হয়েছে।'];
            } else {
                $notice = ['err', 'আপনার এডিট করার অনুমতি নেই।'];
            }
        } else {
            // Insert
            if ($canInsert) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE name = :name AND account_no = :acc");
                $check->execute(['name' => $name, 'acc' => $accNo]);

                if ($check->fetchColumn() > 0) {
                    $notice = ['err', 'এই নাম ও নম্বরে মাধ্যম আগেই আছে।'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payment_methods (name, account_type, account_no, instructions, sort_order, is_active)
                                           VALUES (:name, :type, :acc, :ins, :sort, 1)");
                    $stmt->execute(['name' => $name, 'type' => $type, 'acc' => $accNo, 'ins' => $instr, 'sort' => $sort]);
                    $notice = ['ok', 'পেমেন্ট মাধ্যম যোগ হয়েছে।'];
                }
            } else {
                $notice = ['err', 'আপনার নতুন যোগ করার অনুমতি নেই।'];
            }
        }
    }
    // --- Enable / Disable ---
    elseif ($action === 'toggle' && $canEdit) {
        $pdo->prepare("UPDATE payment_methods SET is_active = 1 - is_active WHERE id = :id")
            ->execute(['id' => (int)($_POST['method_id'] ?? 0)]);
        header("Location: manage_payment_methods.php");
        exit();
    }
    // --- Delete (ব্যবহৃত হয়ে থাকলে মুছবে না, বন্ধ করুন) ---
    elseif ($action === 'delete' && $canDelete) {
        $mid = (int)($_POST['method_id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_method_id = :id");
        $used->execute(['id' => $mid]);

        if ($used->fetchColumn() > 0) {
            $notice = ['err', 'এই মাধ্যমে পেমেন্টের রেকর্ড আছে, তাই মোছা যাবে না। মুছে ফেলার বদলে "বন্ধ করুন" ব্যবহার করুন।'];
        } else {
            $pdo->prepare("DELETE FROM payment_methods WHERE id = :id")->execute(['id' => $mid]);
            header("Location: manage_payment_methods.php");
            exit();
        }
    }
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editMethod = $stmt->fetch();

    if (!$editMethod) {
        $notice = ['err', 'রেকর্ডটি পাওয়া যায়নি।'];
    }
}

// ----------------------
// Fetch All Methods
// ----------------------
$methods = $pdo->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Method Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* কাস্টম গ্রিন ডিজাইন — .adm র‍্যাপারে সীমিত, প্রিফিক্স "ad-" (header/Bootstrap-এর সাথে সংঘর্ষ নেই) */
    .adm{--ink:#10231a;--mut:#5a6c62;--line:#e1e9e3;--surface:#f2f6f3;--card:#fff;--g:#00693e;--g2:#0a8f55;--gl:#e4f2e9;--r:#f42a41;--rl:#fdecee;--gold:#d9a441;
         font-family:'Hind Siliguri',system-ui,'Nirmala UI','Kohinoor Bangla',sans-serif;color:var(--ink);line-height:1.65}
    .adm *,.adm *::before,.adm *::after{box-sizing:border-box}
    .adm button,.adm input,.adm textarea{font:inherit}
    .adm :focus-visible{outline:3px solid var(--g);outline-offset:2px}
    .ad-title{font-size:1.5rem;font-weight:700;margin:0 0 16px}
    .ad-msg{padding:12px 14px;border-radius:12px;font-size:.93rem;margin-bottom:16px;border:1px solid}
    .ad-msg.ok{background:var(--gl);color:var(--g);border-color:var(--g)}
    .ad-msg.err{background:var(--rl);color:var(--r);border-color:var(--r)}
    .ad-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:24px;margin-bottom:22px;box-shadow:0 14px 34px -22px rgba(2,74,44,.3)}
    .ad-card h2{font-size:1.1rem;margin:0 0 16px}
    .ad-fields{display:grid;grid-template-columns:1.2fr 1fr 1.2fr .6fr;gap:14px}
    @media(max-width:900px){.ad-fields{grid-template-columns:1fr 1fr}}
    @media(max-width:520px){.ad-fields{grid-template-columns:1fr}}
    .ad-fgp{margin-bottom:14px}
    .ad-fgp label{display:block;font-weight:600;font-size:.88rem;margin-bottom:5px}
    .ad-fgp label b{color:var(--r)}
    .ad-fc{width:100%;border:1.5px solid var(--line);border-radius:12px;background:var(--surface);padding:10px 14px;font-size:16px;color:var(--ink)}
    .ad-fc:focus{border-color:var(--g);background:#fff;outline:0}
    textarea.ad-fc{resize:vertical;min-height:84px}
    .ad-btns{display:flex;gap:10px;flex-wrap:wrap}
    .ad-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:99px;padding:9px 22px;font-weight:700;font-size:.9rem;color:#fff;cursor:pointer;text-decoration:none}
    .ad-btn:hover{color:#fff}
    .ad-btn.ok{background:linear-gradient(135deg,var(--g),var(--g2))}
    .ad-btn.no{background:var(--r)}
    .ad-btn.warn{background:var(--gold);color:#2c1a00}
    .ad-btn.warn:hover{color:#2c1a00}
    .ad-btn.ghost{background:var(--surface);color:var(--ink);border:1.5px solid var(--line)}
    .ad-btn.ghost:hover{color:var(--g);border-color:var(--g)}
    .ad-btn.sm{padding:6px 16px;font-size:.82rem}
    .ad-tw{overflow-x:auto;border:1px solid var(--line);border-radius:18px;background:var(--card);box-shadow:0 14px 34px -22px rgba(2,74,44,.3)}
    .ad-table{width:100%;border-collapse:collapse;font-size:.92rem;min-width:640px}
    .ad-table th,.ad-table td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
    .ad-table th{background:var(--surface);color:var(--mut);font-weight:600;white-space:nowrap}
    .ad-table tbody tr:last-child td{border-bottom:0}
    .ad-table tbody tr:hover{background:var(--surface)}
    .ad-mut{color:var(--mut);font-size:.82rem}
    .ad-badge{display:inline-block;padding:3px 14px;border-radius:99px;font-size:.78rem;font-weight:700}
    .ad-badge.ok{background:var(--gl);color:var(--g)}
    .ad-badge.off{background:var(--surface);color:var(--mut)}
    .ad-act{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .ad-act form{margin:0}
    .ad-empty{text-align:center;color:var(--mut);padding:34px 12px!important}
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
<div class="adm">
    <h1 class="ad-title">পেমেন্ট মাধ্যম ব্যবস্থাপনা</h1>

    <?php if ($notice[0] !== ''): ?>
        <div class="ad-msg <?= h($notice[0]) ?>" role="status"><?= h($notice[1]) ?></div>
    <?php endif; ?>

    <?php if ($canInsert || $editMethod): ?>
    <div class="ad-card">
        <h2><?= $editMethod ? 'মাধ্যম এডিট করুন' : 'নতুন মাধ্যম যোগ করুন' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="method_id" value="<?= h($editMethod['id'] ?? '') ?>">

            <div class="ad-fields">
                <div class="ad-fgp">
                    <label for="name">মাধ্যমের নাম <b>*</b></label>
                    <input class="ad-fc" id="name" name="name" type="text" required maxlength="50" placeholder="bKash"
                           value="<?= h($editMethod['name'] ?? '') ?>">
                </div>
                <div class="ad-fgp">
                    <label for="account_type">অ্যাকাউন্টের ধরন</label>
                    <input class="ad-fc" id="account_type" name="account_type" type="text" maxlength="30" placeholder="Personal / Merchant"
                           value="<?= h($editMethod['account_type'] ?? 'Personal') ?>">
                </div>
                <div class="ad-fgp">
                    <label for="account_no">অ্যাকাউন্ট নম্বর <b>*</b></label>
                    <input class="ad-fc" id="account_no" name="account_no" type="text" required maxlength="40" placeholder="01XXXXXXXXX"
                           value="<?= h($editMethod['account_no'] ?? '') ?>">
                </div>
                <div class="ad-fgp">
                    <label for="sort_order">ক্রম</label>
                    <input class="ad-fc" id="sort_order" name="sort_order" type="number" value="<?= h($editMethod['sort_order'] ?? 0) ?>">
                </div>
            </div>

            <div class="ad-fgp">
                <label for="instructions">নির্দেশনা (শিক্ষার্থীকে দেখানো হবে)</label>
                <textarea class="ad-fc" id="instructions" name="instructions" rows="3"><?= h($editMethod['instructions'] ?? '') ?></textarea>
            </div>

            <div class="ad-btns">
                <button type="submit" class="ad-btn ok"><?= $editMethod ? 'আপডেট করুন' : 'যোগ করুন' ?></button>
                <?php if ($editMethod): ?>
                    <a href="manage_payment_methods.php" class="ad-btn ghost">বাতিল</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="ad-tw">
        <table class="ad-table">
            <thead>
                <tr>
                    <th>SL</th>
                    <th>মাধ্যম</th>
                    <th>অ্যাকাউন্ট নম্বর</th>
                    <th>অবস্থা</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sl = 1;
                foreach ($methods as $m): ?>
                    <tr>
                        <td><?= $sl++ ?></td>
                        <td><strong><?= h($m['name']) ?></strong><div class="ad-mut"><?= h($m['account_type']) ?></div></td>
                        <td><?= h($m['account_no']) ?></td>
                        <td>
                            <?php if ((int)$m['is_active'] === 1): ?>
                                <span class="ad-badge ok">চালু</span>
                            <?php else: ?>
                                <span class="ad-badge off">বন্ধ</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="ad-act">
                            <?php if ($canEdit): ?>
                                <a href="?edit=<?= urlencode($m['id']) ?>" class="ad-btn warn sm">এডিট</a>

                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="method_id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="ad-btn ghost sm"><?= (int)$m['is_active'] === 1 ? 'বন্ধ করুন' : 'চালু করুন' ?></button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canDelete): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="method_id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="ad-btn no sm"
                                            onclick="return confirm('মাধ্যমটি মুছে ফেলবেন?')">মুছুন</button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$methods): ?>
                    <tr><td colspan="5" class="ad-empty">এখনো কোনো পেমেন্ট মাধ্যম যোগ করা হয়নি।</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</main>

<?php include 'footer.php'; ?>
</body>
</html>