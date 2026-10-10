<?php
session_start();
require 'db.php';

// -----------------------------
// Check login
// -----------------------------
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// -----------------------------
// Session user info
// -----------------------------
$userSession = $_SESSION['user'];
$userId      = $userSession['user_id'];
$userType    = $userSession['user_type_id']; // 1=Super Admin, 2=Admin, etc
$userOrg     = $userSession['org_code'];
$userBr      = $userSession['br_code'];

$canReset    = ($userType <= 2); // only Super Admin or Admin can reset passwords
$message     = '';

// -----------------------------
// Handle Reset Password
// -----------------------------
if (isset($_POST['reset_password_btn']) && $canReset) {
    $resetUser = $_POST['reset_user_id'] ?? null;
    $newPassword = trim($_POST['new_password'] ?? '');

    if ($resetUser && $newPassword) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE user_login_info SET USER_PASSWORD=:hash WHERE USER_ID=:uid");
        $stmt->execute(['hash' => $hash, 'uid' => $resetUser]);

        $stmt = $pdo->prepare("SELECT USER_NAME FROM user_login_info WHERE USER_ID=:uid");
        $stmt->execute(['uid' => $resetUser]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $message = "<div class='alert alert-success text-center'>
                        Password for <strong>" . htmlspecialchars($userRow['USER_NAME'] ?? $resetUser) . "</strong> has been reset successfully.
                    </div>";
    } else {
        $message = "<div class='alert alert-danger text-center'>Please select a user and enter a new password.</div>";
    }
}

// -----------------------------
// Fetch users for table
// -----------------------------
$sql = "SELECT u.USER_ID, u.USER_NAME, u.USER_ID AS LOGIN_ID, u.PHONE, u.EMAIL,
               o.ORGANIZATION_NAME, b.BRANCH_NAME, u.USER_TYPE_ID
        FROM user_login_info u
        LEFT JOIN organization_info o ON u.ORG_CODE = o.ORG_CODE
        LEFT JOIN branch_info b ON u.BR_CODE = b.BR_CODE
        WHERE 1=1";

$params = [];
if ($userType == 2) { // Admin: only users of same organization
    $sql .= " AND u.ORG_CODE = :org";
    $params['org'] = $userOrg;
} elseif ($userType > 2) { // lower users cannot reset anyone
    $sql .= " AND u.USER_ID = :uid";
    $params['uid'] = $userId;
}

$sql .= " ORDER BY o.ORGANIZATION_NAME, b.BRANCH_NAME, u.USER_NAME";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Only users this account is actually allowed to reset (same rule the dropdown used to apply)
$users = array_filter($allUsers, function($u) use ($userType) {
    return $u['USER_TYPE_ID'] > $userType;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Reset User Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg-soft:#f4f6fb;
  --card-radius:16px;
  --brand:#4f46e5;
  --brand-soft:#eef0ff;
  --muted:#8a8fa3;
}
body{
  font-family:'Inter',system-ui,-apple-system,sans-serif;
  background:var(--bg-soft);
}
h3{font-weight:700;color:#1f2333;letter-spacing:-0.02em;}
.card{
  border:none;
  border-radius:var(--card-radius);
  box-shadow:0 4px 24px rgba(31,35,51,0.06);
}
.form-control,.form-select{
  border-radius:10px;
  border:1px solid #e3e5ee;
  padding:.6rem .85rem;
  font-size:.95rem;
  transition:box-shadow .15s ease, border-color .15s ease;
}
.form-control:focus,.form-select:focus{
  border-color:var(--brand);
  box-shadow:0 0 0 3px var(--brand-soft);
}
.form-label{font-size:.82rem;font-weight:600;color:#565b73;}
.btn{border-radius:10px;font-weight:600;font-size:.9rem;}

#searchWrap{position:relative;}
#searchWrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);}
#userSearch{padding-left:38px;border-radius:12px;background:#fff;}

.table-modern{
  background:#fff;
  border-radius:var(--card-radius);
  overflow:hidden;
  box-shadow:0 4px 24px rgba(31,35,51,0.06);
  border:1px solid #e3e5ee;
}
.table-modern table{margin-bottom:0;}
.table-modern thead{background:#1f2333 !important;}
.table-modern thead tr{background:#1f2333 !important;}
.table-modern thead th{
  background:#1f2333 !important;
  color:#ffffff !important;
  font-weight:600;font-size:.78rem;
  text-transform:uppercase;letter-spacing:.04em;
  border:none;border-bottom:2px solid #30354a;
  padding:.85rem 1rem;white-space:nowrap;
}
.table-modern tbody td{
  padding:.7rem 1rem;vertical-align:middle;font-size:.88rem;
  border-color:#eef0f6;
}
.table-modern tbody tr:hover{background:#f7f8fc;}

.reset-btn{
  border-radius:9px;font-weight:600;font-size:.82rem;
  padding:.4rem .85rem;background:#fee2e2;color:#b91c1c;border:none;
}
.reset-btn:hover{background:#fecaca;color:#991b1b;}

/* Responsive */
@media (max-width: 767.98px){
  main.container{padding-left:.9rem;padding-right:.9rem;}
  h3{font-size:1.25rem;}
  .form-select, .form-control{font-size:1rem;}
  .table-modern tbody td, .table-modern thead th{padding:.6rem .7rem;font-size:.82rem;}
  .reset-btn{width:100%;}
}
</style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-3 py-md-4">
    <h3 class="mb-3 mb-md-4"><i class="bi bi-key-fill me-2 text-primary"></i>Reset User Password</h3>

    <?= $message ?>

    <?php if($canReset): ?>

    <!-- Dynamic Search -->
    <div id="searchWrap" class="mb-3">
        <i class="bi bi-search"></i>
        <input type="text" id="userSearch" class="form-control" placeholder="Search by login, name, email, phone, organization, branch...">
    </div>

    <!-- User Table -->
    <div class="table-responsive table-modern">
        <table class="table align-middle mb-0" id="userTable">
            <thead>
                <tr>
                    <th>Login Name</th>
                    <th>User Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Organization</th>
                    <th>Branch</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No users available to reset.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['LOGIN_ID']) ?></td>
                    <td><?= htmlspecialchars($u['USER_NAME']) ?></td>
                    <td><?= htmlspecialchars($u['EMAIL'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['PHONE'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['ORGANIZATION_NAME'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['BRANCH_NAME'] ?? '') ?></td>
                    <td>
                        <button type="button" class="reset-btn open-reset-modal"
                                data-uid="<?= htmlspecialchars($u['USER_ID']) ?>"
                                data-name="<?= htmlspecialchars($u['USER_NAME']) ?>"
                                data-login="<?= htmlspecialchars($u['LOGIN_ID']) ?>">
                            <i class="bi bi-arrow-repeat me-1"></i>Reset Password
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
          <form method="post">
            <div class="modal-header">
              <h5 class="modal-title" id="resetModalLabel"><i class="bi bi-key-fill me-2 text-primary"></i>Reset Password</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="reset_user_id" id="modal_reset_user_id">

              <div class="mb-3">
                <label class="form-label">User</label>
                <input type="text" class="form-control" id="modal_user_display" readonly>
              </div>

              <div class="mb-1">
                <label for="modal_new_password" class="form-label">New Password</label>
                <input type="text" class="form-control" id="modal_new_password" name="new_password" placeholder="Enter new password" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="reset_password_btn" class="btn btn-danger">
                <i class="bi bi-arrow-repeat me-1"></i>Reset Password
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <?php else: ?>
        <div class="alert alert-warning text-center">You do not have permission to reset passwords.</div>
    <?php endif; ?>
</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Dynamic (live) search across the user table
document.getElementById('userSearch')?.addEventListener('input', function() {
    const filter = this.value.trim().toLowerCase();
    const rows = document.querySelectorAll('#userTable tbody tr');
    rows.forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});

// Reset button -> fetch that user's data into the modal fields
document.querySelectorAll('.open-reset-modal').forEach(function(btn){
    btn.addEventListener('click', function(){
        const uid   = btn.dataset.uid;
        const name  = btn.dataset.name;
        const login = btn.dataset.login;

        document.getElementById('modal_reset_user_id').value = uid;
        document.getElementById('modal_user_display').value = name + ' (' + login + ')';
        document.getElementById('modal_new_password').value = '';

        const modal = new bootstrap.Modal(document.getElementById('resetModal'));
        modal.show();
    });
});
</script>
</body>
</html>