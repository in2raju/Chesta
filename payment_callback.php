<?php
/* =========================================================
   payment_callback.php — SSLCommerz success / fail / cancel / IPN
   ⚠ গেটওয়ে POST করে আসে, তাই সেশনের উপর নির্ভর করা যাবে না।
     সবসময় tran_id দিয়ে payments সারি খুঁজুন এবং সার্ভার-টু-সার্ভার ভ্যালিডেশন করুন।
   ========================================================= */
require_once 'db.php';
require_once 'config_payment.php';

/**
 * গেটওয়ে থেকে আসা ডেটা যাচাই করে পেমেন্ট paid করে ও ভর্তি সক্রিয় করে।
 * রিটার্ন: ['ok'=>bool, 'course_id'=>int]
 */
function process_gateway_payment(PDO $pdo, array $p): array {
    $tran  = trim($p['tran_id'] ?? '');
    $valId = trim($p['val_id'] ?? '');
    $out   = ['ok' => false, 'course_id' => 0];
    if ($tran === '') return $out;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM payments WHERE tran_id = ? FOR UPDATE");
        $st->execute([$tran]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay) { $pdo->rollBack(); return $out; }

        $out['course_id'] = (int)$pay['course_id'];

        if ($pay['status'] === 'paid') { $pdo->commit(); $out['ok'] = true; return $out; } // IPN + success দুইবার এলে
        if (in_array($pay['status'], ['rejected', 'refunded'], true) || $valId === '') { $pdo->rollBack(); return $out; }

        $v = http_request(SSLC_VALIDATE_URL . '?' . http_build_query([
            'val_id'       => $valId,
            'store_id'     => SSLC_STORE_ID,
            'store_passwd' => SSLC_STORE_PASS,
            'format'       => 'json',
        ]));

        $valid = $v
            && in_array($v['status'] ?? '', ['VALID', 'VALIDATED'], true)
            && ($v['tran_id'] ?? '') === $tran
            && ($v['currency_type'] ?? 'BDT') === $pay['currency']
            && abs((float)($v['amount'] ?? 0) - (float)$pay['amount']) < 0.01;

        if ($valid) {
            $pdo->prepare("UPDATE payments SET status='paid', paid_at=NOW(), gateway_ref=?, gateway_response=? WHERE id=?")
                ->execute([$v['bank_tran_id'] ?? null, json_encode($v, JSON_UNESCAPED_UNICODE), $pay['id']]);
            activate_enrollment($pdo, (int)$pay['enrollment_id']);
            $out['ok'] = true;
        } else {
            $pdo->prepare("UPDATE payments SET status='failed', gateway_response=? WHERE id=? AND status='pending'")
                ->execute([json_encode($v ?? $p, JSON_UNESCAPED_UNICODE), $pay['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('gateway callback: ' . $e->getMessage());
    }
    return $out;
}

$a = $_GET['a'] ?? '';
$p = $_POST ?: $_GET;   // কিছু ক্ষেত্রে GET-এ আসতে পারে

switch ($a) {
    case 'success':
        $r = process_gateway_payment($pdo, $p);
        header('Location: enroll.php?course_id=' . $r['course_id'] . ($r['ok'] ? '&paid=1' : '&failed=1'));
        break;

    case 'fail':
    case 'cancel':
        $tran = trim($p['tran_id'] ?? '');
        $cid  = 0;
        if ($tran !== '') {
            $st = $pdo->prepare("SELECT course_id FROM payments WHERE tran_id = ?");
            $st->execute([$tran]);
            $cid = (int)$st->fetchColumn();
            $pdo->prepare("UPDATE payments SET status=? WHERE tran_id=? AND status='pending'")
                ->execute([$a === 'cancel' ? 'cancelled' : 'failed', $tran]);
        }
        header('Location: enroll.php?course_id=' . $cid . '&' . ($a === 'cancel' ? 'cancelled=1' : 'failed=1'));
        break;

    case 'ipn':   // সার্ভার-টু-সার্ভার; ব্যবহারকারী ব্রাউজার বন্ধ করে দিলেও এটি পেমেন্ট নিশ্চিত করবে
        process_gateway_payment($pdo, $p);
        http_response_code(200);
        echo 'OK';
        break;

    default:
        http_response_code(400);
        echo 'Bad request';
}
