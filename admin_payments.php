<?php
session_start();
require 'db.php';
require 'config_payment.php';   // h(), csrf_token(), csrf_ok(), activate_enrollment()

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

// শুধু approve পারমিশনধারী (স্টুডেন্ট নয়) এই পেজ দেখতে পারবে
if (!$canApprove || $userType === 5) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}

$notice = ['', ''];   // [type, text]  type: ok | warn | err

// ----------------------
// Handle Approve / Reject (POST + CSRF)
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $action    = $_POST['action'] ?? '';
    $note      = mb_substr(trim($_POST['note'] ?? ''), 0, 250);

    if (!csrf_ok()) {
        $notice = ['err', 'সেশন শেষ হয়েছে, পেজ রিফ্রেশ করুন।'];
    } else {
        $pdo->beginTransaction();
        try {
            // শুধু নিজের org-এর ইউজারের, যাচাইয়ের অপেক্ষায় থাকা ম্যানুয়াল পেমেন্ট
            $stmt = $pdo->prepare("SELECT p.* FROM payments p
                                   JOIN user_login_info u ON u.USER_ID = p.user_id
                                   WHERE p.id = :id AND p.method_type = 'manual' AND p.status = 'verifying'
                                     AND u.ORG_CODE = :org_code
                                   FOR UPDATE");
            $stmt->execute(['id' => $paymentId, 'org_code' => $orgCode]);
            $pay = $stmt->fetch();

            if ($pay && $action === 'approve') {
                $pdo->prepare("UPDATE payments
                               SET status = 'paid', paid_at = NOW(), verified_by = :by, verified_at = NOW(), admin_note = :note
                               WHERE id = :id")
                    ->execute(['by' => $userId, 'note' => $note ?: null, 'id' => $paymentId]);
                activate_enrollment($pdo, (int)$pay['enrollment_id']);
                $notice = ['ok', 'অনুমোদিত — শিক্ষার্থীর কোর্স চালু হয়েছে।'];
            } elseif ($pay && $action === 'reject') {
                $pdo->prepare("UPDATE payments
                               SET status = 'rejected', verified_by = :by, verified_at = NOW(), admin_note = :note
                               WHERE id = :id")
                    ->execute(['by' => $userId, 'note' => $note ?: 'তথ্য মেলেনি', 'id' => $paymentId]);
                $notice = ['warn', 'পেমেন্ট বাতিল করা হয়েছে।'];
            } else {
                $notice = ['err', 'পেমেন্টটি পাওয়া যায়নি বা আগেই প্রসেস হয়েছে।'];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_payments: ' . $e->getMessage());
            $notice = ['err', 'ত্রুটি হয়েছে। আবার চেষ্টা করুন।'];
        }
    }
}

// ----------------------
// Fetch Payments (filter by status)
// ----------------------
$allowed = ['verifying', 'paid', 'rejected', 'failed'];
$filter  = in_array($_GET['s'] ?? '', $allowed, true) ? $_GET['s'] : 'verifying';

$stmt = $pdo->prepare("
    SELECT p.*, COALESCE(c.title_bn, c.title) AS course_name, u.USER_NAME, pm.name AS method_name
    FROM payments p
    JOIN courses c ON c.id = p.course_id
    JOIN user_login_info u ON u.USER_ID = p.user_id
    LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
    WHERE p.status = :status AND u.ORG_CODE = :org_code
    ORDER BY p.id DESC
    LIMIT 200
");
$stmt->execute(['status' => $filter, 'org_code' => $orgCode]);
$payments = $stmt->fetchAll();

$tabs  = ['verifying' => 'যাচাইয়ের অপেক্ষায়', 'paid' => 'অনুমোদিত', 'rejected' => 'বাতিল', 'failed' => 'ব্যর্থ'];
$badge = ['verifying' => 'wait', 'paid' => 'ok', 'rejected' => 'no', 'failed' => 'off'];
$stLbl = ['verifying' => 'অপেক্ষায়', 'paid' => 'অনুমোদিত', 'rejected' => 'বাতিল', 'failed' => 'ব্যর্থ'];
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* কাস্টম গ্রিন ডিজাইন — .adm র‍্যাপারে সীমিত, প্রিফিক্স "ad-" (header/Bootstrap-এর সাথে সংঘর্ষ নেই) */
    .adm{--ink:#10231a;--mut:#5a6c62;--line:#e1e9e3;--surface:#f2f6f3;--card:#fff;--g:#00693e;--g2:#0a8f55;--gl:#e4f2e9;--r:#f42a41;--rl:#fdecee;--gold:#d9a441;
         font-family:'Hind Siliguri',system-ui,'Nirmala UI','Kohinoor Bangla',sans-serif;color:var(--ink);line-height:1.65}
    .adm *,.adm *::before,.adm *::after{box-sizing:border-box}
    .adm button,.adm input{font:inherit}
    .adm :focus-visible{outline:3px solid var(--g);outline-offset:2px}
    .ad-title{font-size:1.5rem;font-weight:700;margin:0 0 16px}
    .ad-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
    .ad-filters a{padding:7px 18px;border:1.5px solid var(--line);border-radius:99px;text-decoration:none;color:var(--mut);font-weight:600;font-size:.9rem;background:var(--card)}
    .ad-filters a:hover{border-color:var(--g);color:var(--g)}
    .ad-filters a.on{background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;border-color:transparent}
    .ad-msg{padding:12px 14px;border-radius:12px;font-size:.93rem;margin-bottom:16px;border:1px solid}
    .ad-msg.ok{background:var(--gl);color:var(--g);border-color:var(--g)}
    .ad-msg.warn{background:#fff6e0;color:#8a5a00;border-color:var(--gold)}
    .ad-msg.err{background:var(--rl);color:var(--r);border-color:var(--r)}
    .ad-tw{overflow-x:auto;border:1px solid var(--line);border-radius:18px;background:var(--card);box-shadow:0 14px 34px -22px rgba(2,74,44,.3)}
    .ad-table{width:100%;border-collapse:collapse;font-size:.9rem;min-width:900px}
    .ad-table th,.ad-table td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
    .ad-table th{background:var(--surface);color:var(--mut);font-weight:600;white-space:nowrap}
    .ad-table tbody tr:last-child td{border-bottom:0}
    .ad-table tbody tr:hover{background:var(--surface)}
    .ad-mut{color:var(--mut);font-size:.82rem}
    .ad-code{background:var(--gl);color:var(--g);padding:2px 8px;border-radius:7px;font-family:monospace;font-size:.85rem}
    .ad-badge{display:inline-block;padding:3px 12px;border-radius:99px;font-size:.78rem;font-weight:700}
    .ad-badge.ok{background:var(--gl);color:var(--g)}
    .ad-badge.wait{background:#fff6e0;color:#8a5a00}
    .ad-badge.no{background:var(--rl);color:var(--r)}
    .ad-badge.off{background:var(--surface);color:var(--mut)}
    .ad-act{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .ad-input{border:1.5px solid var(--line);background:var(--surface);color:var(--ink);border-radius:10px;padding:6px 10px;width:150px;font-size:.88rem}
    .ad-input:focus{border-color:var(--g);background:#fff;outline:0}
    .ad-btn{border:0;border-radius:99px;padding:7px 18px;font-weight:700;font-size:.85rem;color:#fff;cursor:pointer}
    .ad-btn.ok{background:linear-gradient(135deg,var(--g),var(--g2))}
    .ad-btn.no{background:var(--r)}
    .ad-empty{text-align:center;color:var(--mut);padding:34px 12px!important}
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
<div class="adm">
    <h1 class="ad-title">ম্যানুয়াল পেমেন্ট যাচাই</h1>

    <?php if ($notice[0] !== ''): ?>
        <div class="ad-msg <?= h($notice[0]) ?>" role="status"><?= h($notice[1]) ?></div>
    <?php endif; ?>

    <div class="ad-filters">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?s=<?= $key ?>" class="<?= $filter === $key ? 'on' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="ad-tw">
        <table class="ad-table">
            <thead>
                <tr>
                    <th>SL</th>
                    <th>শিক্ষার্থী</th>
                    <th>কোর্স</th>
                    <th>পরিমাণ</th>
                    <th>মাধ্যম / প্রেরক</th>
                    <th>TrxID / Ref</th>
                    <th>সময়</th>
                    <th>অবস্থা</th>
                    <?php if ($filter === 'verifying'): ?><th>অ্যাকশন</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $sl = 1;
                foreach ($payments as $p): ?>
                    <tr>
                        <td><?= $sl++ ?></td>
                        <td><?= h($p['USER_NAME'] ?: '—') ?><div class="ad-mut"><?= h($p['user_id']) ?></div></td>
                        <td><?= h($p['course_name']) ?></td>
                        <td><strong>৳<?= h(number_format((float)$p['amount'], 0)) ?></strong></td>
                        <td><?= h($p['method_name'] ?: $p['provider']) ?><div class="ad-mut"><?= h($p['sender_number']) ?></div></td>
                        <td><span class="ad-code"><?= h($p['manual_trx_id'] ?: $p['gateway_ref'] ?: $p['tran_id']) ?></span></td>
                        <td class="ad-mut"><?= h($p['created_at']) ?>
                            <?php if ($p['admin_note']): ?><div><?= h($p['admin_note']) ?></div><?php endif; ?></td>
                        <td><span class="ad-badge <?= $badge[$p['status']] ?? 'off' ?>"><?= h($stLbl[$p['status']] ?? $p['status']) ?></span></td>

                        <?php if ($filter === 'verifying'): ?>
                        <td>
                            <form method="post" class="ad-act">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                <input type="text" name="note" class="ad-input" placeholder="নোট (ঐচ্ছিক)" maxlength="250">
                                <button type="submit" name="action" value="approve" class="ad-btn ok"
                                        onclick="return confirm('পেমেন্টটি অনুমোদন করবেন?')">অনুমোদন</button>
                                <button type="submit" name="action" value="reject" class="ad-btn no"
                                        onclick="return confirm('পেমেন্টটি বাতিল করবেন?')">বাতিল</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr><td colspan="9" class="ad-empty">কিছু পাওয়া যায়নি।</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</main>

<?php include 'footer.php'; ?>
</body>
</html>