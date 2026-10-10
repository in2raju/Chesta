<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$user      = $_SESSION['user'];
$userId    = $user['user_id'];
$userType  = $user['user_type_id'];
$orgCode   = $user['org_code'];
$canInsert = $user['can_insert'] ?? 1;
$canEdit   = $user['can_edit'] ?? 1;
$canDelete = $user['can_delete'] ?? 1;

$editBranch = null;
$message = "";

// ----------------------
// Fetch organizations
// ----------------------
if ($userType == 1) { // 1 = Super Admin
    $orgStmt = $pdo->query("SELECT ORG_CODE, ORGANIZATION_NAME FROM organization_info ORDER BY ORGANIZATION_NAME");
    $organizations = $orgStmt->fetchAll();
} else {
    $orgStmt = $pdo->prepare("SELECT ORG_CODE, ORGANIZATION_NAME FROM organization_info WHERE ORG_CODE = :org_code");
    $orgStmt->execute(['org_code' => $orgCode]);
    $organizations = $orgStmt->fetchAll();
}

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $branchId   = $_POST['branch_id'] ?? '';
    $orgCodeSel = $_POST['org_code'];
    $brCode     = trim($_POST['br_code']);
    $branchName = trim($_POST['branch_name']);
    $branchAddr = trim($_POST['branch_address']);
    $branchCont = trim($_POST['branch_contact']);

    if ($branchId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE branch_info 
                                   SET BRANCH_NAME = :name, 
                                       BRANCH_ADDRESS = :address, 
                                       BRANCH_CONTACT = :contact, 
                                       EDIT_USER = :user, 
                                       EDIT_DATE = CURDATE() 
                                   WHERE BR_CODE = :br_code AND ORG_CODE = :org_code");
            $stmt->execute([
                'name'     => $branchName,
                'address'  => $branchAddr,
                'contact'  => $branchCont,
                'user'     => $userId,
                'br_code'  => $brCode,
                'org_code' => $orgCodeSel
            ]);
            $message = "<div class='alert alert-success text-center'>Branch updated successfully!</div>";
        }
    } else {
        // Insert
        if ($canInsert) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM branch_info WHERE BR_CODE = :br_code");
            $check->execute(['br_code' => $brCode]);
            if ($check->fetchColumn() > 0) {
                $message = "<div class='alert alert-danger text-center'>Error: Branch code already exists!</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO branch_info 
                    (BR_CODE, BRANCH_NAME, BRANCH_ADDRESS, BRANCH_CONTACT, ORG_CODE, ENTRY_USER, ENTRY_DATE)
                    VALUES (:br_code, :name, :address, :contact, :org_code, :entry_user, CURDATE())");
                $stmt->execute([
                    'br_code'    => $brCode,
                    'name'       => $branchName,
                    'address'    => $branchAddr,
                    'contact'    => $branchCont,
                    'org_code'   => $orgCodeSel,
                    'entry_user' => $userId
                ]);
                $message = "<div class='alert alert-success text-center'>Branch added successfully!</div>";
            }
        }
    }
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM branch_info WHERE BR_CODE = :br_code");
    $stmt->execute(['br_code' => $_GET['edit']]);
    $editBranch = $stmt->fetch();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("DELETE FROM branch_info WHERE BR_CODE = :br_code");
    $stmt->execute(['br_code' => $_GET['delete']]);
    header("Location: add_branch.php");
    exit();
}

// ----------------------
// Fetch Branches
// ----------------------
if ($userType == 1) {
    $stmt = $pdo->query("SELECT b.*, o.ORGANIZATION_NAME 
                         FROM branch_info b
                         JOIN organization_info o ON b.ORG_CODE = o.ORG_CODE
                         ORDER BY o.ORGANIZATION_NAME, b.BRANCH_NAME");
    $branches = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT b.*, o.ORGANIZATION_NAME 
                           FROM branch_info b
                           JOIN organization_info o ON b.ORG_CODE = o.ORG_CODE
                           WHERE b.ORG_CODE = :org_code
                           ORDER BY b.BRANCH_NAME");
    $stmt->execute(['org_code' => $orgCode]);
    $branches = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Branch Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        function updateBranchPrefix() {
            const orgSelect = document.getElementById('orgSelect');
            const orgCode = orgSelect.value;
            const brCodeField = document.getElementById('br_code');
            if (orgCode) {
                brCodeField.value = orgCode + "-";
            } else {
                brCodeField.value = "";
            }
        }
    </script>
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Branch Management</h3>
    <?= $message ?>

    <!-- Branch Form -->
    <?php if ($canInsert || $editBranch): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="branch_id" value="<?= htmlspecialchars($editBranch['BR_CODE'] ?? '') ?>">

                <div class="mb-3">
                    <label>Organization <span class="text-danger">*</span></label>
                    <select name="org_code" id="orgSelect" class="form-select" required onchange="updateBranchPrefix()">
                        <option value="">Select Organization</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= htmlspecialchars($org['ORG_CODE']) ?>"
                                <?= isset($editBranch['ORG_CODE']) && $editBranch['ORG_CODE'] == $org['ORG_CODE'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['ORGANIZATION_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label>Branch Code <span class="text-danger">*</span></label>
                    <input type="text" id="br_code" name="br_code" class="form-control" required
                           value="<?= htmlspecialchars($editBranch['BR_CODE'] ?? '') ?>" <?= $editBranch ? 'readonly' : '' ?>>
                </div>

                <div class="mb-3">
                    <label>Branch Name <span class="text-danger">*</span></label>
                    <input type="text" name="branch_name" class="form-control" required
                           value="<?= htmlspecialchars($editBranch['BRANCH_NAME'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label>Branch Address</label>
                    <textarea name="branch_address" class="form-control"><?= htmlspecialchars($editBranch['BRANCH_ADDRESS'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label>Branch Contact</label>
                    <input type="text" name="branch_contact" class="form-control"
                           value="<?= htmlspecialchars($editBranch['BRANCH_CONTACT'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-primary"><?= $editBranch ? 'Update' : 'Add' ?></button>
                <?php if ($editBranch): ?>
                    <a href="add_branch.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Branch Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Organization</th>
                    <th>BR_CODE</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th>ENTRY USER</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branches as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b['ORGANIZATION_NAME']) ?></td>
                        <td><?= htmlspecialchars($b['BR_CODE']) ?></td>
                        <td><?= htmlspecialchars($b['BRANCH_NAME']) ?></td>
                        <td><?= htmlspecialchars($b['BRANCH_ADDRESS']) ?></td>
                        <td><?= htmlspecialchars($b['BRANCH_CONTACT']) ?></td>
                        <td><?= htmlspecialchars($b['ENTRY_USER']) ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                                <a href="?edit=<?= urlencode($b['BR_CODE']) ?>" class="btn btn-sm btn-warning">Edit</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <a href="?delete=<?= urlencode($b['BR_CODE']) ?>" class="btn btn-sm btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this branch?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
