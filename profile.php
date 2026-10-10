<?php
// profile.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';

// -----------------------------
// Check login
// -----------------------------
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// -----------------------------
// Read from session (lowercase keys)
// -----------------------------
$userId   = $_SESSION['user']['user_id'] ?? null;
$userType = $_SESSION['user']['user_type_id'] ?? null;

if (!$userId) {
    die("<div class='alert alert-danger text-center'>User not found in session.</div>");
}

// -----------------------------
// Fetch full user info from DB
// -----------------------------
$stmt = $pdo->prepare("SELECT * FROM user_login_info WHERE USER_ID = :uid");
$stmt->execute(['uid' => $userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC); // Keep DB keys intact

if (!$userDb) {
    die("<div class='alert alert-danger text-center'>User not found in database.</div>");
}

$message = "";

// -----------------------------
// Change Password
// -----------------------------
if (isset($_POST['change_password'])) {
    $current = trim($_POST['current_password']);
    $new     = trim($_POST['new_password']);
    $confirm = trim($_POST['confirm_password']);

    if (!password_verify($current, $userDb['USER_PASSWORD'])) {
        $message = "<div class='alert alert-danger text-center'>Current password is incorrect!</div>";
    } elseif ($new !== $confirm) {
        $message = "<div class='alert alert-danger text-center'>New password and confirmation do not match!</div>";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE user_login_info SET USER_PASSWORD=:hash WHERE USER_ID=:uid");
        $stmt->execute(['hash' => $hash, 'uid' => $userId]);

        $message = "<div class='alert alert-success text-center'>Password updated successfully!</div>";

        // Refresh DB info
        $stmt = $pdo->prepare("SELECT * FROM user_login_info WHERE USER_ID = :uid");
        $stmt->execute(['uid' => $userId]);
        $userDb = $stmt->fetch(PDO::FETCH_ASSOC);

        // Update session (lowercase keys)
        $_SESSION['user'] = [
            'user_id'       => $userDb['USER_ID'],
            'user_type_id'  => $userDb['USER_TYPE_ID'],
            'user_name'     => $userDb['USER_NAME'],
            'email'         => $userDb['EMAIL'],
            'phone'         => $userDb['PHONE'],
            'user_password' => $userDb['USER_PASSWORD']
        ];
    }
}

// -----------------------------
// Update Basic Info
// -----------------------------
if (isset($_POST['update_info'])) {
    $userName = trim($_POST['user_name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);

    $stmt = $pdo->prepare("UPDATE user_login_info 
                           SET USER_NAME=:uname, EMAIL=:email, PHONE=:phone
                           WHERE USER_ID=:uid");
    $stmt->execute([
        'uname' => $userName,
        'email' => $email,
        'phone' => $phone,
        'uid'   => $userId
    ]);

    $message = "<div class='alert alert-success text-center'>Profile updated successfully!</div>";

    // Refresh DB info
    $stmt = $pdo->prepare("SELECT * FROM user_login_info WHERE USER_ID = :uid");
    $stmt->execute(['uid' => $userId]);
    $userDb = $stmt->fetch(PDO::FETCH_ASSOC);

    // Update session (lowercase keys)
    $_SESSION['user'] = [
        'user_id'       => $userDb['USER_ID'],
        'user_type_id'  => $userDb['USER_TYPE_ID'],
        'user_name'     => $userDb['USER_NAME'],
        'email'         => $userDb['EMAIL'],
        'phone'         => $userDb['PHONE'],
        'user_password' => $userDb['USER_PASSWORD']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.profile-card {
    background: linear-gradient(90deg, #266be2ff 0%, #06ce71ff 100%);
    color: #fff;
    padding: 20px;
    border-radius: 12px;
    text-align: center;
}
.profile-card img {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    margin-bottom: 15px;
    border: 3px solid rgba(255,255,255,0.6);
}
</style>
</head>
<body class="d-flex flex-column min-vh-100;">

<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
<h3 class="mb-4">My Profile</h3>

<?= $message ?>

<div class="row">

    <!-- Profile Card -->
    <div class="col-md-4 mb-4">
        <div class="profile-card shadow-sm">
            <img src="plogo.png" alt="Profile Logo"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($userDb['USER_NAME'] ?? 'User') ?>'">

            <h5 class="mt-2"><?= htmlspecialchars($userDb['USER_NAME'] ?? 'No Name') ?></h5>
            <p><strong>User ID:</strong> <?= htmlspecialchars($userDb['USER_ID'] ?? 'N/A') ?></p>
            <p class="mb-1"><?= htmlspecialchars($userDb['EMAIL'] ?? 'N/A') ?></p>
            <p class="mb-1"><?= htmlspecialchars($userDb['PHONE'] ?? 'N/A') ?></p>
        </div>
    </div>

    <!-- Update Info -->
    <div class="col-md-8">
        <div class="card mb-4 shadow-sm">
            <div class="card-body">
                <h5>Update Information</h5>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">User Name</label>
                        <input type="text" name="user_name" class="form-control"
                               value="<?= htmlspecialchars($userDb['USER_NAME'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($userDb['EMAIL'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= htmlspecialchars($userDb['PHONE'] ?? '') ?>">
                    </div>
                    <button type="submit" name="update_info" class="btn btn-primary">Update Info</button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card shadow-sm">
            <div class="card-body">
                <h5>Change Password</h5>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

</main>

<?php include 'footer.php'; ?>
</body>
</html>
