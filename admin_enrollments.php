<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userType = (int)($_SESSION['user']['user_type_id'] ?? 0);
if (!in_array($userType, [1, 2], true)) {
    header("Location: deshboard.php");
    exit();
}

/* Approve manual payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    if ($paymentId > 0) {
        $pdo->prepare("
            UPDATE course_payments
            SET status = 'success',
                paid_at = NOW(),
                verified_at = NOW(),
                verified_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user']['user_id'] ?? 'admin', $paymentId]);

        /* Activate enrollment */
        $pdo->prepare("
            UPDATE course_enrollments e
            JOIN course_payments p ON p.enrollment_id = e.id
            SET e.status = 'active',
                e.paid_amount = p.amount,
                e.due_amount = 0,
                e.activated_at = NOW(),
                e.expires_at = DATE_ADD(NOW(), INTERVAL 1 YEAR)
            WHERE p.id = ?
        ")->execute([$paymentId]);
    }
    header("Location: admin_enrollments.php?approved=1");
    exit();
}

$st = $pdo->query("
    SELECT p.*, e.enrollment_code, e.status AS enroll_status,
           c.title, c.title_bn,
           u.USER_NAME
    FROM course_payments p
    JOIN course_enrollments e ON e.id = p.enrollment_id
    JOIN courses c ON c.id = p.course_id
    LEFT JOIN user_login_info u ON u.USER_ID = p.user_id
    WHERE p.status = 'pending'
    ORDER BY p.initiated_at DESC
");
$payments = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>এনরোলমেন্ট পেমেন্ট ম্যানেজমেন্ট</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Hind Siliguri',sans-serif;background:#f4f6fb;padding:24px;}</style>
</head>
<body>

<div class="container">
    <h3 class="mb-4"><i class="bi bi-cash-stack"></i> পেন্ডিং পেমেন্ট (ম্যানুয়াল verification)</h3>

    <?php if (!empty($_GET['approved'])): ?>
        <div class="alert alert-success">পেমেন্ট অনুমোদিত হয়েছে।</div>
    <?php endif; ?>

    <?php if (empty($payments)): ?>
        <div class="alert alert-info">কোনো পেন্ডিং পেমেন্ট নেই।</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle bg-white">
                <thead>
                    <tr>
                        <th>Payment Code</th>
                        <th>Student</th>
                        <th>Course</th>
                        <th>Amount</th>
                        <th>TrxID</th>
                        <th>Mobile</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($p['payment_code']) ?></code></td>
                        <td><?= htmlspecialchars($p['USER_NAME'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($p['title_bn'] ?: $p['title']) ?></td>
                        <td>৳ <?= number_format($p['amount'], 0) ?></td>
                        <td><code><?= htmlspecialchars($p['gateway_txn_id'] ?? '-') ?></code></td>
                        <td><?= htmlspecialchars($p['payer_mobile'] ?? '-') ?></td>
                        <td>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Approve this payment?');">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</body>
</html>