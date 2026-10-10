<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
// Logged-in user info
$user      = $_SESSION['user'];
$userId    = $user['user_id'] ?? $user['id'] ?? '';
$userType  = $user['user_type_id'] ?? '';
$orgCode   = $user['org_code'] ?? '';
$brCode    = $user['br_code'] ?? '';
$canInsert  = $_SESSION['user']['can_insert']  ?? 0;
$canEdit    = $_SESSION['user']['can_edit']    ?? 0;
$canDelete  = $_SESSION['user']['can_delete']  ?? 0;
$canApprove = $_SESSION['user']['can_approve'] ?? 0;

$message = "";
$editLevel = null;

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $levelId   = $_POST['level_id'] ?? '';
    $levelName = trim($_POST['level_name']);

    if ($levelId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE levels 
                                   SET name = :name 
                                   WHERE id = :id AND org_code = :org_code AND br_code = :br_code AND authorized_status != 'Y'");
            $stmt->execute([
                'name'     => $levelName, 
                'id'       => $levelId,
                'org_code' => $orgCode,
                'br_code'  => $brCode
            ]);
            $message = "<div class='alert alert-success text-center'>Level updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to edit levels.</div>";
        }
    } else {
        // Insert
        if ($canInsert) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM levels WHERE name = :name AND org_code = :org_code AND br_code = :br_code");
            $check->execute([
                'name'     => $levelName,
                'org_code' => $orgCode,
                'br_code'  => $brCode
            ]);
            
            if ($check->fetchColumn() > 0) {
                $message = "<div class='alert alert-danger text-center'>Error: Level name already exists in this branch!</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO levels (name, org_code, br_code, authorized_status) VALUES (:name, :org_code, :br_code, 'N')");
                $stmt->execute([
                    'name'     => $levelName,
                    'org_code' => $orgCode,
                    'br_code'  => $brCode
                ]);
                $message = "<div class='alert alert-success text-center'>Level added successfully!</div>";
            }
        } else {
            $message = "<div class='alert alert-danger text-center'>You do not have permission to add levels.</div>";
        }
    }
}

// ----------------------
// Handle Edit
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    // Only allow editing if it isn't approved yet
    $stmt = $pdo->prepare("SELECT * FROM levels WHERE id = :id AND org_code = :org_code AND br_code = :br_code AND authorized_status != 'Y'");
    $stmt->execute([
        'id'       => $_GET['edit'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    $editLevel = $stmt->fetch();
    
    if (!$editLevel) {
        $message = "<div class='alert alert-danger text-center'>Error: Record is either approved or does not exist.</div>";
    }
}

// ----------------------
// Handle Approve Action
// ----------------------
if (isset($_GET['approve'])) {
    $stmt = $pdo->prepare("UPDATE levels SET authorized_status = 'Y' WHERE id = :id AND org_code = :org_code AND br_code = :br_code");
    $stmt->execute([
        'id'       => $_GET['approve'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_level.php");
    exit();
}

// ----------------------
// Handle Delete
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    // Prevent deletion if authorized_status is 'Y'
    $stmt = $pdo->prepare("DELETE FROM levels WHERE id = :id AND org_code = :org_code AND br_code = :br_code AND authorized_status != 'Y'");
    $stmt->execute([
        'id'       => $_GET['delete'],
        'org_code' => $orgCode,
        'br_code'  => $brCode
    ]);
    header("Location: exam_insert_level.php");
    exit();
}

// ----------------------
// Fetch All Levels
// ----------------------
$stmt = $pdo->prepare("SELECT id, name, authorized_status FROM levels WHERE org_code = :org_code AND br_code = :br_code ORDER BY id ASC");
$stmt->execute([
    'org_code' => $orgCode,
    'br_code'  => $brCode
]);
$levels = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Level Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Level Management</h3>
    <?= $message ?>

    <?php if ($canInsert || $editLevel): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="level_id" value="<?= htmlspecialchars($editLevel['id'] ?? '') ?>">

                <div class="mb-3">
                    <label>Level Name <span class="text-danger">*</span></label>
                    <input type="text" name="level_name" class="form-control" required
                           value="<?= htmlspecialchars($editLevel['name'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-primary"><?= $editLevel ? 'Update Level' : 'Add Level' ?></button>
                <?php if ($editLevel): ?>
                    <a href="exam_insert_level.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>SL</th>
                    <th>Level Name</th>
                    <th style="width: 250px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sl = 1;
                foreach ($levels as $level): ?>
                    <tr>
                        <td><?= $sl++ ?></td>
                        <td><?= htmlspecialchars($level['name']) ?></td>
                        <td>
                            <?php if ($level['authorized_status'] === 'Y'): ?>
                                <span class="badge bg-success py-2 px-3">Authorized</span>
                            <?php else: ?>
                                <?php if ($canEdit): ?>
                                    <a href="?edit=<?= urlencode($level['id']) ?>" class="btn btn-sm btn-warning">Edit</a>
                                <?php endif; ?>
                                
                                <?php if ($canDelete): ?>
                                    <a href="?delete=<?= urlencode($level['id']) ?>" class="btn btn-sm btn-danger"
                                       onclick="return confirm('Are you sure you want to delete this level?')">Delete</a>
                                <?php endif; ?>

                                <a href="?approve=<?= urlencode($level['id']) ?>" class="btn btn-sm btn-success"
                                   onclick="return confirm('Are you sure you want to approve this level?')">Approve</a>
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