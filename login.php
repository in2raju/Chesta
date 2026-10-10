<?php
declare(strict_types=1);
session_start();

require_once 'db.php';  // Secure connection framework initializing $pdo handle

/* ---------- Site Logo ---------- */
$siteLogo = 'logo.png';   /* ← আপনার লোগো ফাইলের নাম/পাথ */

/* ---------- লগইনের পর কোথায় যাবে (?next=) ---------- */
function safe_next(string $n, string $fallback = 'deshboard.php'): string {
    // শুধু একই সাইটের .php পেজ; http://, // বা ../ হলে বাতিল (open redirect ঠেকাতে)
    return preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%\-]*)?$/', $n) ? $n : $fallback;
}
$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['user_id']) ? trim($_POST['user_id']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($userId === '' || $password === '') {
        $error = 'Please provide both User ID and Password.';
    } else {
        $sql = "
            SELECT 
                u.USER_ID, 
                u.USER_PASSWORD, 
                u.USER_NAME,
                u.EMAIL,
                u.PHONE,
                u.ENTRY_DATE,
                u.AUTHORIZED_STATUS,
                u.BR_CODE, 
                u.ORG_CODE,
                u.USER_TYPE_ID, 
                u.level_id,
                u.email_verified,
                u.PAID_STATUS,
                b.BRANCH_NAME
            FROM user_login_info u
            LEFT JOIN branch_info b ON u.BR_CODE = b.BR_CODE
            WHERE u.AUTHORIZED_STATUS='Y' AND u.USER_ID = :user_id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['USER_PASSWORD'])) {
            $orgCode = $user['ORG_CODE'];
            
            $stmtOrg = $pdo->prepare("
                SELECT ORGANIZATION_NAME
                FROM organization_info
                WHERE ORG_CODE = :org_code
                LIMIT 1
            ");
            $stmtOrg->execute(['org_code' => $orgCode]);
            $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
            $orgName = $org['ORGANIZATION_NAME'] ?? '';

            $stmtPerm = $pdo->prepare("
                SELECT can_insert, can_edit, can_delete, can_approve
                FROM user_action_permission
                WHERE user_type_id = :type_id
                LIMIT 1
            ");
            $stmtPerm->execute(['type_id' => $user['USER_TYPE_ID']]);
            $perm = $stmtPerm->fetch(PDO::FETCH_ASSOC);

            $canInsert = $canEdit = $canDelete = $canApprove = 0;
            if ($perm) {
                $canInsert  = (int)$perm['can_insert'];
                $canEdit    = (int)$perm['can_edit'];
                $canDelete  = (int)$perm['can_delete'];
                $canApprove = (int)$perm['can_approve'];
            }

            $_SESSION['user'] = [
                'user_id'           => $user['USER_ID'],
                'user_name'         => $user['USER_NAME'],
                'email'             => $user['EMAIL'],
                'phone'             => $user['PHONE'],
                'entry_date'        => $user['ENTRY_DATE'],
                'authorized_status' => $user['AUTHORIZED_STATUS'],
                'user_type_id'      => $user['USER_TYPE_ID'],
                'level_id'          => $user['level_id'] !== null ? (int)$user['level_id'] : null,
                'email_verified'    => (int)$user['email_verified'],
                'paid_status'       => $user['PAID_STATUS'],
                'br_code'           => $user['BR_CODE'],
                'branch_name'       => $user['BRANCH_NAME'],
                'org_code'          => $orgCode,
                'org_name'          => $orgName,
                'can_insert'        => $canInsert,
                'can_edit'          => $canEdit,
                'can_delete'        => $canDelete,
                'can_approve'       => $canApprove,
            ];

            session_regenerate_id(true);

            header('Location: ' . safe_next($next));
            exit;
        } else {
            $error = 'Invalid User ID, unverified account, or wrong password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Chesta</title>

    <!-- Favicon — uses your logo -->
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tiro+Bangla:ital@0;1&family=Hind+Siliguri:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #ffffff;
            --surface:  #f6f8f6;
            --card:     #ffffff;
            --green:    #00693e;
            --green-dp: #024a2c;
            --green-lt: #e7f2ea;
            --red:      #f42a41;
            --red-dp:   #c81e32;
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

        body {
            font-family: var(--font-b);
            background: var(--bg);
            color: var(--ink);
            line-height: 1.7;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        .page-bg { position: fixed; inset: 0; z-index: -2; background: var(--bg); }
        .page-disc { position: fixed; top: -10%; right: -12%; width: 560px; height: 560px; border-radius: 50%; border: 56px solid var(--green); opacity: .05; z-index: -1; }

        nav { height: var(--nav-h); background: rgba(255,255,255,.9); backdrop-filter: blur(14px); border-bottom: 1px solid var(--border); display: flex; align-items: center; width: 100%; }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; width: min(1200px, 94%); margin: 0 auto; gap: 16px; }

        /* ============================================
           LOGO — Responsive image logo
           ============================================ */
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
            min-width: 0;
        }
        .logo-img {
            height: 46px;              /* ← ডিফল্ট সাইজ */
            width: auto;
            max-width: 180px;
            object-fit: contain;
            display: block;
            flex-shrink: 0;
            transition: transform .2s ease;
        }
        .logo:hover .logo-img { transform: scale(1.05); }

        /* Optional logo text (badge style) */
        .logo-text {
            display: inline-flex;
            align-items: baseline;
            font-family: var(--font-d);
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -.01em;
            line-height: 1;
            white-space: nowrap;
        }
        .logo-text span { color: var(--red); margin-left: 1px; }

        /* Responsive logo sizes */
        @media (max-width: 960px) {
            .logo-img { height: 42px; max-width: 160px; }
            .logo-text { font-size: 1.3rem; }
        }
        @media (max-width: 720px) {
            :root { --nav-h: 64px; }
            .logo-img { height: 38px; max-width: 140px; }
            .logo-text { font-size: 1.2rem; }
            .logo { gap: 10px; }
        }
        @media (max-width: 520px) {
            .logo-img { height: 34px; max-width: 120px; }
            .logo-text { font-size: 1.1rem; }
        }
        @media (max-width: 380px) {
            .logo-text { display: none; }   /* very small screens: image only */
            .logo-img { height: 36px; max-width: 140px; }
        }

        .btn-nav-register { display: inline-flex; align-items: center; gap: 8px; padding: 11px 24px; border-radius: 50px; font-family: var(--font-u); font-size: .88rem; font-weight: 600; cursor: pointer; background: transparent; color: var(--green); border: 1.5px solid var(--border); text-decoration: none; transition: all .25s; white-space: nowrap; }
        .btn-nav-register:hover { border-color: var(--green); background: var(--green-lt); }

        .login-container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 16px; position: relative; }
        .auth-card { background: var(--card); border: 1px solid var(--border); border-radius: 22px; width: min(420px, 100%); box-shadow: var(--shadow); padding: 38px; animation: cardIn .4s cubic-bezier(0.16, 1, 0.3, 1); }
        .auth-topline { width: 42px; height: 4px; border-radius: 4px; background: var(--red); margin: 0 auto 22px; }
        .auth-header { text-align: center; margin-bottom: 30px; }
        .auth-header h2 { font-family: var(--font-d); font-size: 1.75rem; font-weight: 600; margin-bottom: 8px; color: var(--ink); }
        .auth-header p { font-family: var(--font-u); color: var(--muted); font-size: .88rem; }

        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; font-family: var(--font-u); font-size: .76rem; font-weight: 600; color: var(--muted); margin-bottom: 8px; letter-spacing: .06em; text-transform: uppercase; }
        .input-icon-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon-wrapper i { position: absolute; left: 16px; color: var(--muted); font-size: .92rem; }

        .form-control { width: 100%; padding: 13px 16px 13px 44px; background: var(--surface); border: 1.5px solid var(--border); border-radius: 12px; color: var(--ink); font-family: var(--font-b); font-size: .95rem; outline: none; transition: all .2s; }
        .form-control:focus { border-color: var(--green); background: #fff; box-shadow: 0 0 0 3px rgba(0,105,62,.1); }
        .form-control::placeholder { color: #9caea3; }

        .btn-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; border-radius: 50px; font-family: var(--font-u); font-size: .95rem; font-weight: 600; cursor: pointer; border: none; background: var(--green); color: #fff; transition: all .25s; margin-top: 6px; }
        .btn-submit:hover { background: var(--green-dp); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,105,62,.28); }

        .alert-error { font-family: var(--font-u); background: rgba(244,42,65,.08); color: var(--red-dp); border: 1px solid rgba(244,42,65,.2); padding: 13px 16px; border-radius: 12px; font-size: .87rem; margin-bottom: 22px; display: flex; align-items: center; gap: 10px; }
        .auth-footer-text { font-family: var(--font-u); text-align: center; margin-top: 26px; font-size: .88rem; color: var(--muted); }
        .auth-footer-text a { color: var(--green); text-decoration: none; font-weight: 600; }
        .forgot-link { text-align: center; margin-top: 16px; }
        .forgot-link a { font-family: var(--font-u); color: var(--red); text-decoration: none; font-size: .85rem; font-weight: 500; }
        .forgot-link a:hover { text-decoration: underline; }

        @media (max-width: 520px) {
            .auth-card { padding: 28px 22px; border-radius: 18px; }
            .auth-header h2 { font-size: 1.5rem; }
            .btn-nav-register { padding: 9px 16px; font-size: .8rem; }
        }

        @keyframes cardIn { from { opacity: 0; transform: scale(.96) translateY(15px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    </style>
</head>
<body>

    <div class="page-bg"></div>
    <div class="page-disc"></div>

    <nav>
        <div class="nav-inner">
            <!-- Logo — responsive image -->
            <a href="index.php" class="logo" aria-label="Chesta Home">
                <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Exam360" class="logo-img">
                <span class="logo-text"><span></span></span>
            </a>

            <a href="index.php?action=trigger_signup" class="btn-nav-register">
                <i class="fa fa-user-plus"></i> Create Account
            </a>
        </div>
    </nav>

    <main class="login-container">
        <div class="auth-card">
            <div class="auth-topline"></div>
            <div class="auth-header">
                <h2>স্বাগতম, Welcome Back</h2>
                <p>Sign in to access your dashboard</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="fa fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" novalidate>
                <!-- লগইনের পর ফিরে যাওয়ার পেজ (যেমন enroll.php?course_id=2) -->
                <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="user_id">User ID / Registered Email</label>
                    <div class="input-icon-wrapper">
                        <input type="text" name="user_id" id="user_id" class="form-control" placeholder="name@example.com" required maxlength="100" value="<?= isset($_POST['user_id']) ? htmlspecialchars($_POST['user_id'], ENT_QUOTES) : '' ?>">
                        <i class="fa fa-envelope"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-icon-wrapper">
                        <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                        <i class="fa fa-lock"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Sign In</span><i class="fa fa-arrow-right"></i>
                </button>

            </form>

            <div class="auth-footer-text">
                Don't have an approved account? <a href="index.php?action=trigger_signup">Register here</a>
            </div>
            <div class="forgot-link">
                <a href="forgot_password.php"><i class="fa fa-key"></i> Forgot Password?</a>
            </div>
        </div>

    </main>
</body>
</html>