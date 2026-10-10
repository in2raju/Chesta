<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$userId     = $_SESSION['user']['user_id']      ?? 'SYSTEM';
$userTypeId = $_SESSION['user']['user_type_id'] ?? 2;
$canInsert  = $_SESSION['user']['can_insert']   ?? 1;
$canEdit    = $_SESSION['user']['can_edit']     ?? 1;
$canDelete  = $_SESSION['user']['can_delete']   ?? 1;

$orgCode = $_SESSION['user']['org_code'] ?? '100';
$brCode  = $_SESSION['user']['br_code']  ?? '100-100';

$message  = '';
$editMenu = null;

// Super Admin user type id
$DEFAULT_PERMISSION_USER_TYPE = 1;

// =====================================================
// Helper: Generate a new PERMISSION_ID in format "R001", "R002", ...
// Robust: reads all R### rows, extracts max in PHP (no SQL cast surprises),
// then finds the next free slot.
// =====================================================
function generatePermissionId(PDO $pdo): string
{
    // 1. Pull all R###-style IDs
    $stmt = $pdo->query("
        SELECT PERMISSION_ID
        FROM user_menu_view_permission
        WHERE PERMISSION_ID REGEXP '^R[0-9]+$'
    ");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Find max numeric part in PHP
    $max = 0;
    foreach ($existing as $pid) {
        $num = (int) substr((string)$pid, 1);   // strip leading "R"
        if ($num > $max) {
            $max = $num;
        }
    }

    // 3. Find the next free R### slot
    $next = $max + 1;
    while (true) {
        $candidate = 'R' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);

        $chk = $pdo->prepare("SELECT 1 FROM user_menu_view_permission WHERE PERMISSION_ID = :pid");
        $chk->execute(['pid' => $candidate]);
        if (!$chk->fetchColumn()) {
            return $candidate;   // guaranteed R001+, never "0"
        }
        $next++;
    }
}

// =====================================================
// Helper: Ensure a permission row exists for (menu, user type)
//   - if exists  → UPDATE CAN_VIEW = 1
//   - if missing → INSERT with new PERMISSION_ID (R### style)
// =====================================================
function ensurePermissionRow(PDO $pdo, int $menuId, int $userTypeId,
                             string $orgCode, string $brCode, string $entryUser): void
{
    // Check existing
    $chk = $pdo->prepare("
        SELECT PERMISSION_ID
        FROM user_menu_view_permission
        WHERE MENU_ID = :mid AND USER_TYPE_ID = :utid
        LIMIT 1
    ");
    $chk->execute(['mid' => $menuId, 'utid' => $userTypeId]);
    $existing = $chk->fetchColumn();

    if ($existing) {
        // Update existing → ensure CAN_VIEW = 1
        $upd = $pdo->prepare("
            UPDATE user_menu_view_permission
            SET CAN_VIEW  = 1,
                EDIT_USER = :edit_user,
                EDIT_DATE = CURDATE()
            WHERE PERMISSION_ID = :pid
        ");
        $upd->execute([
            'edit_user' => $entryUser,
            'pid'       => $existing,
        ]);
    } else {
        // Insert fresh row with auto-generated ID
        $newPid = generatePermissionId($pdo);

        $ins = $pdo->prepare("
            INSERT INTO user_menu_view_permission
                (PERMISSION_ID, USER_TYPE_ID, MENU_ID, CAN_VIEW,
                 AUTHORIZED_STATUS, AUTHORIZED_USER, AUTHORIZED_DATE,
                 ENTRY_USER, ENTRY_DATE,
                 EDIT_USER, EDIT_DATE,
                 DELETE_USER, DELETE_DATE,
                 ORG_CODE, BR_CODE)
            VALUES
                (:pid, :utid, :mid, 1,
                 NULL, NULL, NULL,
                 :entry_user, CURDATE(),
                 NULL, NULL,
                 NULL, NULL,
                 :org, :br)
        ");
        $ins->execute([
            'pid'        => $newPid,
            'utid'       => $userTypeId,
            'mid'        => $menuId,
            'entry_user' => $entryUser,
            'org'        => $orgCode,
            'br'         => $brCode,
        ]);
    }
}

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Strict int parsing — "" or "0" means INSERT
    $menuId   = (isset($_POST['menu_id']) && $_POST['menu_id'] !== '')
                    ? (int) $_POST['menu_id']
                    : 0;
    $menuName = trim($_POST['menu_name'] ?? '');
    $menuLink = trim($_POST['menu_link'] ?? '');
    $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;

    try {
        if ($menuId > 0) {
            // ============ UPDATE ============
            if ($canEdit) {
                $check = $pdo->prepare("
                    SELECT COUNT(*) FROM menu_info
                    WHERE MENU_NAME = :name AND MENU_ID != :id
                ");
                $check->execute(['name' => $menuName, 'id' => $menuId]);

                if ($check->fetchColumn() > 0) {
                    $message = "<div class='alert alert-danger text-center'>Error: MENU_NAME already exists.</div>";
                } else {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        UPDATE menu_info
                        SET MENU_NAME = :name,
                            MENU_LINK = :link,
                            PARENT_ID = :parent
                        WHERE MENU_ID  = :id
                    ");
                    $stmt->execute([
                        'name'   => $menuName,
                        'link'   => $menuLink !== '' ? $menuLink : null,
                        'parent' => $parentId,
                        'id'     => $menuId,
                    ]);

                    ensurePermissionRow($pdo, $menuId, $DEFAULT_PERMISSION_USER_TYPE,
                                        $orgCode, $brCode, $userId);

                    $pdo->commit();
                    $message = "<div class='alert alert-success text-center'>Menu updated successfully! Super Admin permission verified.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>You do not have permission to edit menus.</div>";
            }

        } else {
            // ============ INSERT ============
            if ($canInsert) {
                if ($menuName === '') {
                    $message = "<div class='alert alert-danger text-center'>Error: MENU_NAME is required.</div>";
                } else {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM menu_info WHERE MENU_NAME = :name");
                    $check->execute(['name' => $menuName]);

                    if ($check->fetchColumn() > 0) {
                        $message = "<div class='alert alert-danger text-center'>Error: MENU_NAME already exists.</div>";
                    } else {
                        $pdo->beginTransaction();

                        // 1) Insert menu (let AUTO_INCREMENT assign MENU_ID)
                        $stmt = $pdo->prepare("
                            INSERT INTO menu_info (MENU_NAME, MENU_LINK, PARENT_ID)
                            VALUES (:name, :link, :parent)
                        ");
                        $stmt->execute([
                            'name'   => $menuName,
                            'link'   => $menuLink !== '' ? $menuLink : null,
                            'parent' => $parentId,
                        ]);

                        $newMenuId = (int) $pdo->lastInsertId();
                        if ($newMenuId <= 0) {
                            throw new RuntimeException("Failed to retrieve new MENU_ID.");
                        }

                        // 2) Auto-create permission for Super Admin (USER_TYPE_ID = 1)
                        ensurePermissionRow($pdo, $newMenuId, $DEFAULT_PERMISSION_USER_TYPE,
                                            $orgCode, $brCode, $userId);

                        $pdo->commit();
                        $message = "<div class='alert alert-success text-center'>
                                        Menu added successfully! Super Admin permission granted with auto-generated ID.
                                    </div>";
                    }
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>You do not have permission to add menus.</div>";
            }
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger text-center'>Database error: "
                   . htmlspecialchars($e->getMessage()) . "</div>";

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger text-center'>Error: "
                   . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ----------------------
// Handle Edit Request
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM menu_info WHERE MENU_ID = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editMenu = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ----------------------
// Handle Delete Request
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    try {
        $pdo->beginTransaction();

        $delId = (int) $_GET['delete'];

        $stmt = $pdo->prepare("DELETE FROM user_menu_view_permission WHERE MENU_ID = :id");
        $stmt->execute(['id' => $delId]);

        $stmt = $pdo->prepare("DELETE FROM menu_info WHERE MENU_ID = :id");
        $stmt->execute(['id' => $delId]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    header("Location: menu.php");
    exit();
}

// ----------------------
// Fetch All Menus
// ----------------------
$stmt  = $pdo->query("SELECT * FROM menu_info ORDER BY PARENT_ID, MENU_ID");
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$parentMap = [];
foreach ($menus as $m) {
    $parentMap[$m['MENU_ID']] = $m['MENU_NAME'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin Menu Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <h3>Super Admin Menu Management</h3>
    <?= $message ?>

    <!-- Menu Form -->
    <?php if ($canInsert || $editMenu): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="menu_id" value="<?= (int)($editMenu['MENU_ID'] ?? 0) ?>">

                <div class="mb-3">
                    <label class="form-label">Menu Name <span class="text-danger">*</span></label>
                    <input type="text" name="menu_name" class="form-control" required
                           value="<?= htmlspecialchars($editMenu['MENU_NAME'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Menu Link</label>
                    <input type="text" name="menu_link" class="form-control"
                           value="<?= htmlspecialchars($editMenu['MENU_LINK'] ?? '') ?>"
                           placeholder="e.g., dashboard.php or #">
                </div>

                <div class="mb-3">
                    <label class="form-label">Parent Menu</label>
                    <select name="parent_id" class="form-select">
                        <option value="">-- None (Top Menu) --</option>
                        <?php foreach ($menus as $m): ?>
                            <?php
                              if (!empty($editMenu['MENU_ID']) && $editMenu['MENU_ID'] == $m['MENU_ID']) continue;
                            ?>
                            <option value="<?= (int)$m['MENU_ID'] ?>"
                                <?= (!empty($editMenu['PARENT_ID']) && $editMenu['PARENT_ID'] == $m['MENU_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['MENU_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"><?= $editMenu ? 'Update' : 'Add Menu' ?></button>
                <?php if ($editMenu): ?>
                    <a href="add_menu.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Menu Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Menu Name</th>
                    <th>Menu Link</th>
                    <th>Parent Menu</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($menus)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No menus found.</td></tr>
                <?php else: ?>
                    <?php foreach ($menus as $m): ?>
                        <tr>
                            <td><?= (int)$m['MENU_ID'] ?></td>
                            <td><?= htmlspecialchars($m['MENU_NAME']) ?></td>
                            <td><?= htmlspecialchars($m['MENU_LINK'] ?? '') ?></td>
                            <td><?= htmlspecialchars($m['PARENT_ID'] ? ($parentMap[$m['PARENT_ID']] ?? '-') : '-') ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="?edit=<?= (int)$m['MENU_ID'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <a href="?delete=<?= (int)$m['MENU_ID'] ?>" class="btn btn-sm btn-danger"
                                       onclick="return confirm('Are you sure to delete this menu?')">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>