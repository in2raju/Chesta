<?php
require_once 'db.php';
require_once 'mailer.php';

use PHPMailer\PHPMailer\Exception;

/* ---------- Site Logo ---------- */
$siteLogo = 'logo.png';

// Handle Forgot Password Request (Send OTP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email address is required.']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT USER_ID, USER_NAME, EMAIL FROM user_login_info WHERE USER_ID = ? OR EMAIL = ?");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Email not found in our records.']);
            exit;
        }
        
        $userEmail = !empty($user['EMAIL']) ? $user['EMAIL'] : $user['USER_ID'];
        
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $token_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        try {
            $checkTokenColumn = $pdo->query("SHOW COLUMNS FROM user_login_info LIKE 'reset_token'");
            if ($checkTokenColumn->rowCount() == 0) {
                $pdo->exec("ALTER TABLE user_login_info ADD COLUMN reset_token VARCHAR(10) NULL");
            }
            
            $checkExpiryColumn = $pdo->query("SHOW COLUMNS FROM user_login_info LIKE 'reset_token_expires'");
            if ($checkExpiryColumn->rowCount() == 0) {
                $pdo->exec("ALTER TABLE user_login_info ADD COLUMN reset_token_expires DATETIME NULL");
            }
            
            $updateStmt = $pdo->prepare("UPDATE user_login_info SET reset_token = ?, reset_token_expires = ? WHERE USER_ID = ?");
            $updateStmt->execute([$otp, $token_expiry, $user['USER_ID']]);
            
            $mail = createMailer();
            $mail->addAddress($userEmail, $user['USER_NAME'] ?? 'User');
            $mail->Subject = 'Password Reset OTP - Chesta';
            
            $mail->Body = "
            <div style='font-family:sans-serif;max-width:560px;margin:auto;background:#ffffff;color:#12261c;border-radius:16px;overflow:hidden;border:1px solid #e3ece5;'>
                <div style='background:#006a4e;padding:32px;text-align:center;'>
                    <h1 style='margin:0;font-size:1.6rem;font-weight:800;color:#ffffff;'><span style='color:#f9d94a;'>Chesta</span></h1>
                </div>
                <div style='padding:32px;'>
                    <h2 style='color:#006a4e;font-size:1.3rem;margin:0 0 12px;'>Reset Your Password</h2>
                    <p style='color:#5e6f66;line-height:1.7;margin:0 0 24px;'>
                        Hello " . htmlspecialchars($user['USER_NAME'] ?? 'User') . ",<br><br>
                        We received a request to reset your password for your Chesta account. Use the OTP below to reset your password.
                    </p>
                    <div style='background:#f6f8f6;border:1px solid #e3ece5;border-radius:12px;padding:20px;text-align:center;margin-bottom:24px;'>
                        <p style='margin:0 0 10px;font-size:.85rem;color:#5e6f66;letter-spacing:.06em;'>Your OTP Code</p>
                        <div style='font-size:42px;font-weight:800;color:#006a4e;letter-spacing:8px;'>{$otp}</div>
                        <p style='margin:15px 0 0;font-size:.8rem;color:#5e6f66;'>Valid for 15 minutes</p>
                    </div>
                    <p style='color:#3c4a43;font-size:.9rem;margin:0 0 8px;'>If you didn't request this, please ignore this email.</p>
                    <p style='color:#f42a41;font-size:.85rem;'>For security, never share this OTP with anyone.</p>
                </div>
                <div style='border-top:1px solid #e3ece5;padding:20px 32px;text-align:center;'>
                    <p style='color:#94a29b;font-size:.8rem;margin:0;'>© " . date('Y') . " Chesta. All rights reserved.</p>
                </div>
            </div>";
            
            $mail->AltBody = "Password Reset OTP for Chesta\n\nYour OTP code is: {$otp}\nValid for 15 minutes.\n\nIf you didn't request this, please ignore this email.";
            
            $mail->send();
            
            session_start();
            $_SESSION['reset_email'] = $user['USER_ID'];
            $_SESSION['reset_otp'] = $otp;
            $_SESSION['reset_expiry'] = $token_expiry;
            
            echo json_encode(['success' => true, 'message' => 'OTP sent to your email address. Valid for 15 minutes.']);
            
        } catch (Exception $e) {
            error_log('OTP email error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP: ' . $e->getMessage()]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'verify_otp') {
        $email = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');
        
        if (empty($email) || empty($otp)) {
            echo json_encode(['success' => false, 'message' => 'Email and OTP are required.']);
            exit;
        }
        
        error_log("Verifying OTP - Email: $email, OTP: $otp");
        
        $stmt = $pdo->prepare("SELECT USER_ID, reset_token, reset_token_expires, USER_NAME FROM user_login_info WHERE USER_ID = ? OR EMAIL = ?");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            error_log("User not found: $email");
            echo json_encode(['success' => false, 'message' => 'Email not found.']);
            exit;
        }
        
        error_log("Found user: " . $user['USER_ID'] . ", Token in DB: " . ($user['reset_token'] ?? 'NULL') . ", Expires: " . ($user['reset_token_expires'] ?? 'NULL'));
        
        if (empty($user['reset_token'])) {
            echo json_encode(['success' => false, 'message' => 'No OTP request found. Please request a new OTP.']);
            exit;
        }
        
        if ($user['reset_token'] !== $otp) {
            error_log("OTP mismatch - Input: $otp, DB: " . $user['reset_token']);
            echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please check and try again.']);
            exit;
        }
        
        $now = date('Y-m-d H:i:s');
        if ($user['reset_token_expires'] < $now) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
            exit;
        }
        
        session_start();
        $_SESSION['reset_verified'] = true;
        $_SESSION['reset_user_id'] = $user['USER_ID'];
        
        echo json_encode(['success' => true, 'message' => 'OTP verified successfully. You can now reset your password.']);
        exit;
        
    } elseif ($_POST['action'] === 'reset_password') {
        session_start();
        
        if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true) {
            echo json_encode(['success' => false, 'message' => 'Please verify OTP first.']);
            exit;
        }
        
        $newPassword = trim($_POST['new_password'] ?? '');
        $userId = $_SESSION['reset_user_id'] ?? '';
        
        if (empty($newPassword) || empty($userId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        
        if (strlen($newPassword) < 4) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters long.']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT USER_ID, USER_NAME, EMAIL FROM user_login_info WHERE USER_ID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE user_login_info SET USER_PASSWORD = ?, reset_token = NULL, reset_token_expires = NULL WHERE USER_ID = ?");
        
        if ($updateStmt->execute([$hashedPassword, $userId])) {
            try {
                $mail = createMailer();
                $userEmail = !empty($user['EMAIL']) ? $user['EMAIL'] : $userId;
                $mail->addAddress($userEmail, $user['USER_NAME'] ?? 'User');
                $mail->Subject = 'Password Changed Successfully - Chesta';
                $mail->Body = "
                <div style='font-family:sans-serif;max-width:560px;margin:auto;background:#ffffff;color:#12261c;border-radius:16px;overflow:hidden;border:1px solid #e3ece5;'>
                    <div style='background:#006a4e;padding:32px;text-align:center;'>
                        <h1 style='margin:0;font-size:1.5rem;font-weight:800;color:#ffffff;'>Password Changed!</h1>
                    </div>
                    <div style='padding:32px;text-align:center;'>
                        <h2 style='color:#006a4e;margin:0 0 12px;'>Password Reset Successful</h2>
                        <p style='color:#5e6f66;line-height:1.7;'>Hello " . htmlspecialchars($user['USER_NAME'] ?? 'User') . ",<br><br>Your password has been successfully changed.</p>
                        <p style='color:#f42a41;margin-top:20px;'>If you didn't make this change, please contact support immediately.</p>
                        <div style='margin-top:30px;'>
                            <a href='login.php' style='display:inline-block;padding:12px 30px;background:#006a4e;color:#ffffff;border-radius:50px;text-decoration:none;font-weight:700;'>Login Now</a>
                        </div>
                    </div>
                </div>";
                $mail->AltBody = "Password Reset Successful\n\nYour password has been changed successfully.\n\nIf you didn't make this change, please contact support immediately.";
                $mail->send();
            } catch (Exception $e) {
                error_log('Password change confirmation email error: ' . $e->getMessage());
            }
            
            unset($_SESSION['reset_verified']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_otp']);
            unset($_SESSION['reset_expiry']);
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully! Please login with your new password.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reset password. Please try again.']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Chesta</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tiro+Bangla:ital@0;1&family=Hind+Siliguri:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --green:    #00693e;
            --green-dp: #024a2c;
            --green-lt: #e7f2ea;
            --red:      #f42a41;
            --red-dp:   #c81e32;
            --ink:      #12261c;
            --muted:    #5e6f66;
            --border:   #e3ece5;
            --surface:  #f6f8f6;
            --font-d:   'Tiro Bangla', serif;
            --font-b:   'Hind Siliguri', sans-serif;
            --font-u:   'Inter', sans-serif;
        }

        body {
            font-family: var(--font-b);
            background: #ffffff;
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: -12%; right: -14%;
            width: 520px; height: 520px;
            border-radius: 50%;
            border: 52px solid var(--green);
            opacity: .05;
            z-index: -1;
        }
        .container {
            max-width: 480px;
            width: 100%;
            position: relative;
            z-index: 1;
        }
        .card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 42px;
            box-shadow: 0 24px 60px -12px rgba(2,74,44,0.14);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-img {
            height: 60px;
            width: auto;
            max-width: 200px;
            object-fit: contain;
            margin-bottom: 14px;
            display: inline-block;
        }
        h1 {
            font-family: var(--font-d);
            font-weight: 600;
            font-size: 28px;
            color: var(--ink);
            text-align: center;
            margin-bottom: 8px;
        }
        .subtitle {
            font-family: var(--font-u);
            text-align: center;
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-family: var(--font-u);
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 9px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }
        input {
            width: 100%;
            padding: 13px 15px 13px 46px;
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            color: var(--ink);
            font-family: var(--font-b);
            font-size: 14px;
            transition: all 0.2s;
        }
        input:focus {
            outline: none;
            border-color: var(--green);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,105,62,0.1);
        }
        input::placeholder { color: #9caea3; }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 50px;
            font-family: var(--font-u);
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--green);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--green-dp);
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(0,105,62,0.28);
        }
        .btn-secondary {
            background: transparent;
            color: var(--green);
            border: 1.5px solid var(--border);
            margin-top: 12px;
        }
        .btn-secondary:hover {
            border-color: var(--green);
            background: var(--green-lt);
        }
        .msg-box {
            font-family: var(--font-u);
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 20px;
            display: none;
        }
        .msg-box.success {
            background: var(--green-lt);
            color: var(--green-dp);
            border: 1px solid rgba(0,105,62,0.2);
            display: block;
        }
        .msg-box.error {
            background: rgba(244,42,65,0.08);
            color: var(--red-dp);
            border: 1px solid rgba(244,42,65,0.2);
            display: block;
        }
        .step {
            display: none;
        }
        .step.active {
            display: block;
        }
        .back-link {
            text-align: center;
            margin-top: 22px;
        }
        .back-link a {
            font-family: var(--font-u);
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            transition: color 0.2s;
        }
        .back-link a:hover {
            color: var(--green);
        }
        .timer {
            font-family: var(--font-u);
            text-align: center;
            font-size: 12px;
            color: var(--muted);
            margin-top: 10px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="logo">
            <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Chesta" class="logo-img">
            <div class="subtitle">Reset Your Password</div>
        </div>

        <div id="msgBox" class="msg-box"></div>

        <!-- Step 1: Enter Email -->
        <div id="step1" class="step active">
            <div class="form-group">
                <label><i class="fa fa-envelope"></i> Email Address</label>
                <div class="input-wrapper">
                    <i class="fa fa-envelope"></i>
                    <input type="email" id="email" placeholder="Enter your registered email" autocomplete="off">
                </div>
            </div>
            <button class="btn btn-primary" onclick="sendOTP()" id="sendOtpBtn">
                <i class="fa fa-paper-plane"></i> Send OTP
            </button>
        </div>

        <!-- Step 2: Verify OTP -->
        <div id="step2" class="step">
            <div class="form-group">
                <label><i class="fa fa-key"></i> Enter OTP</label>
                <div class="input-wrapper">
                    <i class="fa fa-key"></i>
                    <input type="text" id="otp" placeholder="Enter 6-digit OTP" maxlength="6" autocomplete="off">
                </div>
            </div>
            <div class="timer" id="timer"></div>
            <button class="btn btn-primary" onclick="verifyOTP()" id="verifyOtpBtn">
                <i class="fa fa-check-circle"></i> Verify OTP
            </button>
            <button class="btn btn-secondary" onclick="resendOTP()">
                <i class="fa fa-redo"></i> Resend OTP
            </button>
        </div>

        <!-- Step 3: Reset Password -->
        <div id="step3" class="step">
            <div class="form-group">
                <label><i class="fa fa-lock"></i> New Password</label>
                <div class="input-wrapper">
                    <i class="fa fa-lock"></i>
                    <input type="password" id="newPassword" placeholder="Enter new password">
                </div>
            </div>
            <div class="form-group">
                <label><i class="fa fa-lock"></i> Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fa fa-lock"></i>
                    <input type="password" id="confirmPassword" placeholder="Confirm new password">
                </div>
            </div>
            <button class="btn btn-primary" onclick="resetPassword()" id="resetPasswordBtn">
                <i class="fa fa-save"></i> Reset Password
            </button>
        </div>

        <div class="back-link">
            <a href="login.php"><i class="fa fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</div>

<script>
let userEmail = '';
let timerInterval;

function showMessage(type, text) {
    const msgBox = document.getElementById('msgBox');
    msgBox.className = 'msg-box ' + type;
    msgBox.textContent = text;
    msgBox.style.display = 'block';
    setTimeout(() => {
        msgBox.style.display = 'none';
    }, 5000);
}

function startTimer(duration) {
    let timer = duration;
    const timerElement = document.getElementById('timer');
    
    if (timerInterval) clearInterval(timerInterval);
    
    timerInterval = setInterval(() => {
        const minutes = Math.floor(timer / 60);
        const seconds = timer % 60;
        timerElement.textContent = `⏱️ OTP expires in: ${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
        
        if (--timer < 0) {
            clearInterval(timerInterval);
            timerElement.textContent = '❌ OTP expired. Please request a new one.';
            timerElement.style.color = '#f42a41';
        }
    }, 1000);
}

function sendOTP() {
    const email = document.getElementById('email').value.trim();
    
    if (!email) {
        showMessage('error', 'Please enter your email address.');
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showMessage('error', 'Please enter a valid email address.');
        return;
    }
    
    const btn = document.getElementById('sendOtpBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';
    
    const formData = new FormData();
    formData.append('action', 'send_otp');
    formData.append('email', email);
    
    fetch('forgot_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            userEmail = email;
            showMessage('success', data.message);
            document.getElementById('step1').classList.remove('active');
            document.getElementById('step2').classList.add('active');
            startTimer(900);
            document.getElementById('otp').focus();
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('error', 'Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function verifyOTP() {
    const otp = document.getElementById('otp').value.trim();
    
    if (!otp || otp.length !== 6) {
        showMessage('error', 'Please enter a valid 6-digit OTP.');
        return;
    }
    
    const btn = document.getElementById('verifyOtpBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verifying...';
    
    const formData = new FormData();
    formData.append('action', 'verify_otp');
    formData.append('email', userEmail);
    formData.append('otp', otp);
    
    fetch('forgot_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step3').classList.add('active');
            if (timerInterval) clearInterval(timerInterval);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('error', 'Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function resendOTP() {
    if (!userEmail) {
        showMessage('error', 'Please go back and enter your email first.');
        return;
    }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';
    
    const formData = new FormData();
    formData.append('action', 'send_otp');
    formData.append('email', userEmail);
    
    fetch('forgot_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', 'New OTP sent to your email.');
            startTimer(900);
            document.getElementById('otp').value = '';
            document.getElementById('otp').focus();
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('error', 'Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function resetPassword() {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    if (!newPassword || !confirmPassword) {
        showMessage('error', 'Please enter and confirm your new password.');
        return;
    }
    
    if (newPassword.length < 4) {
        showMessage('error', 'Password must be at least 4 characters long.');
        return;
    }
    
    if (newPassword !== confirmPassword) {
        showMessage('error', 'Passwords do not match.');
        return;
    }
    
    const btn = document.getElementById('resetPasswordBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Resetting...';
    
    const formData = new FormData();
    formData.append('action', 'reset_password');
    formData.append('new_password', newPassword);
    
    fetch('forgot_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 3000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('error', 'Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

document.getElementById('email')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendOTP();
});
document.getElementById('otp')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') verifyOTP();
});
document.getElementById('newPassword')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') resetPassword();
});
</script>
</body>
</html>