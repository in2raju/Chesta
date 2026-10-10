<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$userId          = $_SESSION['user']['user_id'];
$orgCode         = $_SESSION['user']['org_code'];
$userTypeSession = $_SESSION['user']['user_type_id'] ?? 0;
$canInsert       = $_SESSION['user']['can_insert'] ?? 1;
$canEdit         = $_SESSION['user']['can_edit'] ?? 1;
$canDelete       = $_SESSION['user']['can_delete'] ?? 1;
$canApprove      = $_SESSION['user']['can_approve'] ?? 1;

$message = '';

// Super Admin can manage all user types
if ($userTypeSession == 1) {
    $userTypes = $pdo->query("SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info ORDER BY USER_TYPE_NAME")
                     ->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Non-super-admin can only manage their own type
    $stmt = $pdo->prepare("SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info WHERE USER_TYPE_ID = :uid");
    $stmt->execute(['uid' => $userTypeSession]);
    $userTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selectedUserTypeId = $_POST['user_type_id'] ?? '';

// Handle Add / Update permissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_permission'])) {
    $userTypeId = $_POST['user_type_id'];
    $canInsert  = isset($_POST['can_insert']) ? 1 : 0;
    $canEdit    = isset($_POST['can_edit']) ? 1 : 0;
    $canDelete  = isset($_POST['can_delete']) ? 1 : 0;
    $canApprove = isset($_POST['can_approve']) ? 1 : 0;

    // Check if permission entry exists
    $stmt = $pdo->prepare("SELECT permission_id FROM user_action_permission WHERE user_type_id = :uid");
    $stmt->execute(['uid' => $userTypeId]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE user_action_permission 
                               SET can_insert = :ins, can_edit = :edit, can_delete = :del, can_approve = :app
                               WHERE user_type_id = :uid");
        $stmt->execute([
            'ins' => $canInsert,
            'edit'=> $canEdit,
            'del' => $canDelete,
            'app' => $canApprove,
            'uid' => $userTypeId
        ]);
        $message = "<div class='alert alert-success text-center'>✅ Permissions updated successfully!</div>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO user_action_permission (user_type_id, can_insert, can_edit, can_delete, can_approve)
                               VALUES (:uid, :ins, :edit, :del, :app)");
        $stmt->execute([
            'uid'  => $userTypeId,
            'ins'  => $canInsert,
            'edit' => $canEdit,
            'del'  => $canDelete,
            'app'  => $canApprove
        ]);
        $message = "<div class='alert alert-success text-center'>✅ Permissions created successfully!</div>";
    }
}

// Fetch current permissions for selected user type
$currentPerm = ['can_insert'=>0, 'can_edit'=>0, 'can_delete'=>0, 'can_approve'=>0];
if ($selectedUserTypeId) {
    $stmt = $pdo->prepare("SELECT can_insert, can_edit, can_delete, can_approve
                           FROM user_action_permission 
                           WHERE user_type_id = :uid");
    $stmt->execute(['uid' => $selectedUserTypeId]);
    $currentPerm = $stmt->fetch(PDO::FETCH_ASSOC) ?: $currentPerm;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Action Permissions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container my-4 flex-grow-1">
    <h3 class="mb-3">User Action Permissions</h3>

    <?= $message ?>

    <!-- User Type Selection -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Select User Type</label>
                    <select name="user_type_id" class="form-select" onchange="this.form.submit()" required>
                        <option value="">-- Select User Type --</option>
                        <?php foreach ($userTypes as $ut): ?>
                            <option value="<?= $ut['USER_TYPE_ID'] ?>" <?= ($selectedUserTypeId == $ut['USER_TYPE_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ut['USER_TYPE_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Permissions Form -->
    <?php if ($selectedUserTypeId): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="user_type_id" value="<?= htmlspecialchars($selectedUserTypeId) ?>">

                <table class="table table-bordered table-striped w-50 mx-auto text-center">
                    <thead class="table-dark">
                        <tr>
                            <th>Permission</th>
                            <th>Allowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Can Insert</td>
                            <td><input type="checkbox" name="can_insert" <?= $currentPerm['can_insert'] ? 'checked' : '' ?>></td>
                        </tr>
                        <tr>
                            <td>Can Edit</td>
                            <td><input type="checkbox" name="can_edit" <?= $currentPerm['can_edit'] ? 'checked' : '' ?>></td>
                        </tr>
                        <tr>
                            <td>Can Delete</td>
                            <td><input type="checkbox" name="can_delete" <?= $currentPerm['can_delete'] ? 'checked' : '' ?>></td>
                        </tr>
                        <tr>
                            <td>Can Approve</td>
                            <td><input type="checkbox" name="can_approve" <?= $currentPerm['can_approve'] ? 'checked' : '' ?>></td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-center mt-3">
                    <button type="submit" name="update_permission" class="btn btn-primary">💾 Save Permissions</button>
                    <a href="user_action_permission.php" class="btn btn-secondary">❌ Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
