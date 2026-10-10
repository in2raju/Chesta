<?php
/* =========================================================
   config_payment.php — পেমেন্ট কনফিগ ও হেল্পার
   ========================================================= */
if (session_status() === PHP_SESSION_NONE) session_start();

/* ---- SSLCommerz (sandbox দিয়ে শুরু করুন, লাইভে SSLC_SANDBOX=false) ---- */
define('SSLC_SANDBOX',    true);
define('SSLC_STORE_ID',   'your_store_id');
define('SSLC_STORE_PASS', 'your_store_password');
define('SSLC_INIT_URL', SSLC_SANDBOX
    ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
    : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php');
define('SSLC_VALIDATE_URL', SSLC_SANDBOX
    ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
    : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php');

/* ---- আপনার লগইন সিস্টেম অনুযায়ী এই দুটি ফাংশন বদলান ---- */
function current_user_id(): ?string {
    // login.php: $_SESSION['user']['user_id']
    return $_SESSION['user']['user_id'] ?? null;
}
function is_admin(): bool {
    // স্টুডেন্টের USER_TYPE_ID = 5 এবং তাদের can_approve = 0।
    // যাদের approve পারমিশন আছে (user_action_permission.can_approve = 1) তারাই পেমেন্ট অনুমোদন করতে পারবে।
    return (int)($_SESSION['user']['can_approve'] ?? 0) === 1
        && (int)($_SESSION['user']['user_type_id'] ?? 5) !== 5;
}

/* ---- Helpers ---- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bn_digits_to_en(string $s): string {
    return strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
}
function en_to_bn(string $s): string {
    return strtr($s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    return !empty($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

function site_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host  = preg_replace('/[^a-zA-Z0-9.:\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/* কোর্সের চূড়ান্ত দাম (ছাড়সহ) */
function course_price(array $c): float {
    $p = (float)$c['price'];
    $d = (float)($c['discount_price'] ?? 0);
    return ($d > 0 && $d < $p) ? $d : $p;
}

/* ভর্তি সক্রিয় করা — গেটওয়ে সফল হলে / অ্যাডমিন অনুমোদন দিলে / ফ্রি কোর্সে */
function activate_enrollment(PDO $pdo, int $enrollmentId): void {
    $pdo->prepare("
        UPDATE course_enrollments e
        JOIN courses c ON c.id = e.course_id
        SET e.status = 'active',
            e.enrolled_at = NOW(),
            e.expires_at = IF(c.duration_days > 0, DATE_ADD(NOW(), INTERVAL c.duration_days DAY), NULL)
        WHERE e.id = ?
    ")->execute([$enrollmentId]);
}

/* ইউজার+কোর্সের জন্য enrollment সারি তৈরি/খুঁজে বের করা, id রিটার্ন করে */
function get_or_create_enrollment(PDO $pdo, string $uid, int $courseId): int {
    $pdo->prepare("
        INSERT INTO course_enrollments (user_id, course_id, status) VALUES (?, ?, 'pending')
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                                status = IF(status = 'active', 'active', 'pending')
    ")->execute([$uid, $courseId]);
    return (int)$pdo->lastInsertId();
}

/* ছোট cURL হেল্পার */
function http_request(string $url, ?array $postFields = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $res = curl_exec($ch);
    if ($res === false) { error_log('cURL: ' . curl_error($ch)); curl_close($ch); return null; }
    curl_close($ch);
    $j = json_decode($res, true);
    return is_array($j) ? $j : null;
}