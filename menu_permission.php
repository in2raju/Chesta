<?php
session_start();
require 'db.php';

// 🔐 Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// 🧍 Logged-in user details
$userId      = $_SESSION['user']['user_id'];
$userTypeId  = $_SESSION['user']['user_type_id'];
$brCode      = $_SESSION['user']['br_code'];
$orgCode     = $_SESSION['user']['org_code'];
$canInsert   = $_SESSION['user']['can_insert'] ?? 0;
$canEdit     = $_SESSION['user']['can_edit'] ?? 0;
$canDelete   = $_SESSION['user']['can_delete'] ?? 0;

// Initialize edit variable
$editPermission = null;

// Fetch user types and menus for dropdowns
$userTypes = $pdo->query("SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info ORDER BY USER_TYPE_NAME")->fetchAll(PDO::FETCH_ASSOC);
$menus     = $pdo->query("SELECT MENU_ID, MENU_NAME FROM menu_info ORDER BY MENU_NAME")->fetchAll(PDO::FETCH_ASSOC);

// ================================
// Handle Add / Update
// ================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $permissionId   = $_POST['permission_id'] ?? '';
    $userTypeIdForm = $_POST['user_type_id'] ?? '';
    $menuId         = $_POST['menu_id'] ?? '';

    if ($userTypeIdForm && $menuId) {

        if ($permissionId && $canEdit) {
            // 🔹 Edit existing permission
            $stmt = $pdo->prepare("UPDATE user_menu_view_permission
                                   SET USER_TYPE_ID = :user_type_id, MENU_ID = :menu_id,
                                       EDIT_USER = :edit_user, EDIT_DATE = NOW()
                                   WHERE PERMISSION_ID = :id AND BR_CODE = :br_code");
            $stmt->execute([
                'user_type_id' => $userTypeIdForm,
                'menu_id'      => $menuId,
                'edit_user'    => $userId,
                'id'           => $permissionId,
                'br_code'      => $brCode
            ]);
        } elseif (!$permissionId && $canInsert) {
            // 🔹 Check if USER_TYPE_ID + MENU_ID already exists
            $stmtCheck = $pdo->prepare("SELECT PERMISSION_ID FROM user_menu_view_permission 
                                        WHERE USER_TYPE_ID = :user_type_id AND MENU_ID = :menu_id AND BR_CODE = :br_code");
            $stmtCheck->execute([
                'user_type_id' => $userTypeIdForm,
                'menu_id'      => $menuId,
                'br_code'      => $brCode
            ]);
            $existingPermissionId = $stmtCheck->fetchColumn();

            if ($existingPermissionId) {
                // 🔹 Already exists – update authorized status instead of insert
                $stmtUpd = $pdo->prepare("UPDATE user_menu_view_permission
                                          SET AUTHORIZED_STATUS = 'Y', EDIT_USER = :edit_user, EDIT_DATE = NOW()
                                          WHERE PERMISSION_ID = :id AND BR_CODE = :br_code");
                $stmtUpd->execute([
                    'edit_user' => $userId,
                    'id'        => $existingPermissionId,
                    'br_code'   => $brCode
                ]);
            } else {
                // 🔹 New record insert
                $stmt = $pdo->prepare("INSERT INTO user_menu_view_permission
                                       (USER_TYPE_ID, MENU_ID, AUTHORIZED_STATUS, ENTRY_USER, ENTRY_DATE, ORG_CODE, BR_CODE)
                                       VALUES (:user_type_id, :menu_id, 'Y', :entry_user, NOW(), :org_code, :br_code)");
                $stmt->execute([
                    'user_type_id' => $userTypeIdForm,
                    'menu_id'      => $menuId,
                    'entry_user'   => $userId,
                    'org_code'     => $orgCode,
                    'br_code'      => $brCode
                ]);
            }
        }

        header("Location: add_permission.php");
        exit();
    }
}

// ================================
// Handle Edit request
// ================================
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM user_menu_view_permission WHERE PERMISSION_ID = :id AND BR_CODE = :br_code");
    $stmt->execute(['id' => $_GET['edit'], 'br_code' => $brCode]);
    $editPermission = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ================================
// Handle Delete request
// ================================
if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("DELETE FROM user_menu_view_permission WHERE PERMISSION_ID = :id AND BR_CODE = :br_code");
    $stmt->execute(['id' => $_GET['delete'], 'br_code' => $brCode]);
    header("Location: add_permission.php");
    exit();
}

// ================================
// Fetch all permissions for this branch
// ================================
$stmt = $pdo->prepare("SELECT p.*, u.USER_TYPE_NAME, m.MENU_NAME
                       FROM user_menu_view_permission p
                       JOIN user_type_info u ON p.USER_TYPE_ID = u.USER_TYPE_ID
                       JOIN menu_info m ON p.MENU_ID = m.MENU_ID
                       WHERE p.BR_CODE = :br_code
                       ORDER BY u.USER_TYPE_NAME, m.MENU_NAME");
$stmt->execute(['br_code' => $brCode]);
$allPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================================
// Fetch menus visible for logged-in user
// ================================
if ($userTypeId == 1) {
    // Super Admin – all menus
    $stmtMenus = $pdo->query("SELECT MENU_ID, MENU_NAME FROM menu_info ORDER BY MENU_NAME");
    $userMenus = $stmtMenus->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Normal user – only authorized menus
    $stmtMenus = $pdo->prepare("SELECT m.MENU_ID, m.MENU_NAME
                                FROM user_menu_view_permission p
                                JOIN menu_info m ON p.MENU_ID = m.MENU_ID
                                WHERE p.USER_TYPE_ID = :user_type_id
                                  AND p.BR_CODE = :br_code
                                  AND p.AUTHORIZED_STATUS = 'Y'
                                ORDER BY m.MENU_NAME");
    $stmtMenus->execute([
        'user_type_id' => $userTypeId,
        'br_code' => $brCode
    ]);
    $userMenus = $stmtMenus->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Menu Permission - Stock3600</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<main class="flex-grow-1 container py-4">
    <h3>User Menu Permissions</h3>

    <!-- Permission Form -->
    <?php if ($canInsert || $editPermission): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="permission_id" value="<?= htmlspecialchars($editPermission['PERMISSION_ID'] ?? '') ?>">

                <div class="mb-3">
                    <label>User Type</label>
                    <select name="user_type_id" class="form-control" required>
                        <option value="">Select User Type</option>
                        <?php foreach ($userTypes as $type): ?>
                            <option value="<?= $type['USER_TYPE_ID'] ?>" <?= ($editPermission['USER_TYPE_ID'] ?? '') == $type['USER_TYPE_ID'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['USER_TYPE_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Menu</label>
                    <select name="menu_id" class="form-control" required>
                        <option value="">Select Menu</option>
                        <?php foreach ($menus as $menu): ?>
                            <option value="<?= $menu['MENU_ID'] ?>" <?= ($editPermission['MENU_ID'] ?? '') == $menu['MENU_ID'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($menu['MENU_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"><?= $editPermission ? 'Update' : 'Add' ?></button>
                <?php if ($editPermission): ?>
                    <a href="add_permission.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Permissions Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-dark">
                <tr>
                    <th>User Type</th>
                    <th>Menu</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allPermissions as $perm): ?>
                <tr>
                    <td><?= htmlspecialchars($perm['USER_TYPE_NAME']) ?></td>
                    <td><?= htmlspecialchars($perm['MENU_NAME']) ?></td>
                    <td>
                        <?php if ($canEdit): ?>
                            <a href="?edit=<?= urlencode($perm['PERMISSION_ID']) ?>" class="btn btn-sm btn-warning">Edit</a>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <a href="?delete=<?= urlencode($perm['PERMISSION_ID']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Logged-in User Menus -->
    <h4 class="mt-4">Menus Visible for You</h4>
    <ul>
        <?php foreach ($userMenus as $um): ?>
            <li><?= htmlspecialchars($um['MENU_NAME']) ?></li>
        <?php endforeach; ?>
    </ul>

</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
