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
$userType    = $userSession['user_type_id'];
$userOrg     = $userSession['org_code'];
$userBr      = $userSession['br_code'];

$canInsert   = $userSession['can_insert'] ?? 1;
$canEdit     = $userSession['can_edit'] ?? 1;
$canDelete   = $userSession['can_delete'] ?? 1;

$message     = '';
$editData    = null;

// -----------------------------
// AJAX: Load branches by organization
// -----------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $org = $_GET['org'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    if (!$org) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT BR_CODE, BRANCH_NAME FROM branch_info WHERE ORG_CODE = :org ORDER BY BRANCH_NAME");
    $stmt->execute(['org' => $org]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// -----------------------------
// AJAX: Real-time toggle for Authorized / Paid status
// (click a badge/button in the table -> flips Y<->N, no page reload)
// -----------------------------
if (isset($_POST['toggle_status']) && $canEdit) {
    header('Content-Type: application/json; charset=utf-8');

    $uid  = $_POST['uid']  ?? '';
    $type = $_POST['type'] ?? ''; // 'auth' or 'paid'
    $column = $type === 'paid' ? 'PAID_STATUS' : ($type === 'auth' ? 'AUTHORIZED_STATUS' : null);

    if (!$uid || !$column) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    // read current value
    $stmt = $pdo->prepare("SELECT $column FROM user_login_info WHERE USER_ID = :uid");
    $stmt->execute(['uid' => $uid]);
    $current = $stmt->fetchColumn();
    $new = ($current === 'Y') ? 'N' : 'Y';

    $stmt = $pdo->prepare("UPDATE user_login_info SET $column = :new WHERE USER_ID = :uid");
    $stmt->execute(['new' => $new, 'uid' => $uid]);

    echo json_encode(['ok' => true, 'uid' => $uid, 'type' => $type, 'new_status' => $new]);
    exit;
}

// -----------------------------
// Handle Delete
// -----------------------------
if (isset($_GET['delete']) && $canDelete) {
    $deleteUser = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM user_login_info WHERE USER_ID = :uid");
    $stmt->execute(['uid' => $deleteUser]);
    $message = "<div class='alert alert-success text-center'>User deleted successfully!</div>";
}

// -----------------------------
// Handle Edit Load
// -----------------------------
if (isset($_GET['edit']) && $canEdit) {
    $editUserId = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM user_login_info WHERE USER_ID = :uid");
    $stmt->execute(['uid' => $editUserId]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
}

// -----------------------------
// Handle Update
// -----------------------------
if (isset($_POST['update_user']) && $canEdit) {
    $uid = $_POST['edit_user_id'];
    $uname = trim($_POST['user_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $userTypePost = $_POST['user_type_id'];
    $auth = $_POST['authorized_status'];
    $paid = $_POST['paid_status'];
    $remarks = trim($_POST['remarks'] ?? '');
    $org = $_POST['org_code'];
    $br = $_POST['br_code'];

    $stmt = $pdo->prepare("UPDATE user_login_info 
                           SET USER_NAME=:uname, EMAIL=:email, PHONE=:phone,
                               USER_TYPE_ID=:utype, AUTHORIZED_STATUS=:auth, PAID_STATUS=:paid, REMARKS=:remarks, ORG_CODE=:org, BR_CODE=:br
                           WHERE USER_ID=:uid");
    $stmt->execute([
        'uname' => $uname,
        'email' => $email,
        'phone' => $phone,
        'utype' => $userTypePost,
        'auth'  => $auth,
        'paid'  => $paid,
        'remarks' => $remarks,
        'org'   => $org,
        'br'    => $br,
        'uid'   => $uid
    ]);
    $message = "<div class='alert alert-info text-center'>User updated successfully!</div>";
    $editData = null;
}

// -----------------------------
// Handle Insert
// -----------------------------
if (isset($_POST['save_user']) && $canInsert) {
    $loginName = trim($_POST['login_name']);
    $password  = trim($_POST['password']);
    $userName  = trim($_POST['user_name']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone']);
    $org       = $_POST['org_code'];
    $br        = $_POST['br_code'];
    $userTypePost  = $_POST['user_type_id'];
    $authStat  = $_POST['authorized_status'];
    $paidStat  = $_POST['paid_status'];
    $remarks   = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_login_info WHERE USER_ID = :login");
    $stmt->execute(['login' => $loginName]);

    if ($stmt->fetchColumn() > 0) {
        $message = "<div class='alert alert-danger text-center'>Login name already exists!</div>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO user_login_info 
            (USER_ID, USER_PASSWORD, USER_NAME, EMAIL, PHONE, ENTRY_DATE, AUTHORIZED_STATUS, PAID_STATUS, REMARKS, USER_TYPE_ID, ORG_CODE, BR_CODE)
            VALUES (:login, :pass, :uname, :email, :phone, CURDATE(), :auth, :paid, :remarks, :utype, :org, :br)");
        $stmt->execute([
            'login' => $loginName,
            'pass'  => password_hash($password, PASSWORD_DEFAULT),
            'uname' => $userName,
            'email' => $email,
            'phone' => $phone,
            'auth'  => $authStat,
            'paid'  => $paidStat,
            'remarks' => $remarks,
            'utype' => $userTypePost,
            'org'   => $org,
            'br'    => $br
        ]);
        $message = "<div class='alert alert-success text-center'>User added successfully!</div>";
    }
}

// -----------------------------
// Fetch organizations
// -----------------------------
if ($userType === 1) {
    $orgs = $pdo->query("SELECT ORG_CODE, ORGANIZATION_NAME FROM organization_info ORDER BY ORGANIZATION_NAME")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT ORG_CODE, ORGANIZATION_NAME FROM organization_info WHERE ORG_CODE = :org ORDER BY ORGANIZATION_NAME");
    $stmt->execute(['org' => $userOrg]);
    $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------
// Fetch branches for selected org
// -----------------------------
$branchOrg = $editData['ORG_CODE'] ?? $userOrg;
$stmt = $pdo->prepare("SELECT BR_CODE, BRANCH_NAME FROM branch_info WHERE ORG_CODE = :org ORDER BY BRANCH_NAME");
$stmt->execute(['org' => $branchOrg]);
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------
// Fetch user types
// -----------------------------
$userTypes = $pdo->query("SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info ORDER BY USER_TYPE_NAME")->fetchAll();

// -----------------------------
// Fetch users for table (sorted by ENTRY_DATE ascending)
// -----------------------------
$sql = "
    SELECT u.*, ut.USER_TYPE_NAME, o.ORGANIZATION_NAME, b.BRANCH_NAME, lv.name AS LEVEL_NAME
    FROM user_login_info u
    LEFT JOIN user_type_info ut ON u.USER_TYPE_ID = ut.USER_TYPE_ID
    LEFT JOIN organization_info o ON u.ORG_CODE = o.ORG_CODE
    LEFT JOIN branch_info b ON u.BR_CODE = b.BR_CODE
    LEFT JOIN levels lv ON u.level_id = lv.id
    WHERE 1=1
";

$params = [];
if ($userType == 2) { $sql .= " AND u.ORG_CODE = :org"; $params['org'] = $userOrg; }
elseif ($userType == 3) { $sql .= " AND u.BR_CODE = :br"; $params['br'] = $userBr; }
elseif ($userType == 4) { $sql .= " AND u.USER_ID = :uid"; $params['uid'] = $userId; }

$sql .= " ORDER BY u.ENTRY_DATE DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management</title>
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
  --ok:#16a34a;
  --ok-soft:#eafaf0;
  --warn:#dc2626;
  --warn-soft:#fdeeee;
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
  padding:.55rem .8rem;
  transition:box-shadow .15s ease, border-color .15s ease;
}
.form-control:focus,.form-select:focus{
  border-color:var(--brand);
  box-shadow:0 0 0 3px var(--brand-soft);
}
label{font-size:.82rem;font-weight:600;color:#565b73;margin-bottom:.3rem;display:block;}
.btn{border-radius:10px;font-weight:600;font-size:.85rem;}
.btn-primary{background:var(--brand);border-color:var(--brand);}
.btn-primary:hover{background:#4338ca;border-color:#4338ca;}

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
  padding:.9rem 1rem;white-space:nowrap;
}
.table-modern tbody td{
  padding:.75rem 1rem;vertical-align:middle;font-size:.88rem;
  border-color:#eef0f6;
}
.table-modern tbody tr{transition:background .12s ease;}
.table-modern tbody tr:hover{background:#f7f8fc;}

.status-pill{
  display:inline-flex;align-items:center;gap:.35rem;
  padding:.32rem .7rem;border-radius:999px;
  font-size:.78rem;font-weight:600;border:none;cursor:pointer;
  transition:transform .1s ease, filter .1s ease;
}
.status-pill:active{transform:scale(.96);}
.status-pill.is-y{background:var(--ok-soft);color:var(--ok);}
.status-pill.is-n{background:var(--warn-soft);color:var(--warn);}
.status-pill:disabled{cursor:default;opacity:.85;}
.status-pill .spinner-border{width:.7rem;height:.7rem;border-width:2px;}

.action-btn{
  width:32px;height:32px;border-radius:9px;
  display:inline-flex;align-items:center;justify-content:center;
  border:none;margin-right:.25rem;
}
.action-edit{background:#fff3e0;color:#b45309;}
.action-edit:hover{background:#fde8c8;}
.action-delete{background:#fee2e2;color:#b91c1c;}
.action-delete:hover{background:#fecaca;}
</style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4">
<h3 class="mb-3"><i class="bi bi-people-fill me-2 text-primary"></i>User Entry</h3>
<?= $message ?>

<!-- User Form -->
<?php if ($canInsert || $editData): ?>
<div class="card mb-4">
<div class="card-body p-4">
<form method="post">
<?php if ($editData): ?>
<input type="hidden" name="edit_user_id" value="<?= htmlspecialchars($editData['USER_ID']) ?>">
<?php endif; ?>

<div class="row">
<div class="col-md-6 mb-3">
<label>User ID</label>
<input type="text" name="login_name" class="form-control" value="<?= htmlspecialchars($editData['USER_ID'] ?? '') ?>" <?= $editData ? 'readonly' : 'required' ?>>
</div>
<div class="col-md-6 mb-3">
<label>Password <?= $editData ? '(unchanged)' : '' ?></label>
<input type="password" name="password" class="form-control" <?= $editData ? '' : 'required' ?>>
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>User Name</label>
<input type="text" name="user_name" class="form-control" required value="<?= htmlspecialchars($editData['USER_NAME'] ?? '') ?>">
</div>
<div class="col-md-6 mb-3">
<label>Email</label>
<input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editData['EMAIL'] ?? '') ?>">
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Phone</label>
<input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($editData['PHONE'] ?? '') ?>">
</div>
<div class="col-md-6 mb-3">
<label>Organization</label>
<select name="org_code" id="orgSelect" class="form-select" required>
    <option value="">-- Select Organization --</option>
    <?php foreach ($orgs as $org): ?>
    <option value="<?= $org['ORG_CODE'] ?>" <?= ($editData && $editData['ORG_CODE']==$org['ORG_CODE']) || (!$editData && $userOrg==$org['ORG_CODE']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($org['ORGANIZATION_NAME']) ?>
    </option>
    <?php endforeach; ?>
</select>
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Branch</label>
<select name="br_code" id="brSelect" class="form-select" required>
<option value="">-- Select Branch --</option>
<?php foreach ($branches as $b):
$selected = ($editData && $editData['BR_CODE']==$b['BR_CODE']) || (!$editData && $userBr==$b['BR_CODE']) ? 'selected' : '';
?>
<option value="<?= $b['BR_CODE'] ?>" <?= $selected ?>><?= htmlspecialchars($b['BRANCH_NAME']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6 mb-3">
<label>User Type</label>
<select name="user_type_id" class="form-select" required>
<option value="">-- Select User Type --</option>
<?php foreach ($userTypes as $ut): ?>
<?php if ($ut['USER_TYPE_ID'] > $userType): ?>
<option value="<?= $ut['USER_TYPE_ID'] ?>" <?= ($editData && $editData['USER_TYPE_ID']==$ut['USER_TYPE_ID'])?'selected':'' ?>>
<?= htmlspecialchars($ut['USER_TYPE_NAME']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="row">
<div class="col-md-6 mb-3">
<label>Authorization</label>
<select name="authorized_status" class="form-select" required>
<option value="Y" <?= ($editData && $editData['AUTHORIZED_STATUS']=='Y')?'selected':'' ?>>Y</option>
<option value="N" <?= ($editData && (!isset($editData['AUTHORIZED_STATUS']) || $editData['AUTHORIZED_STATUS']=='N'))?'selected':'' ?>>N</option>
</select>
</div>

<div class="col-md-6 mb-3">
<label>Paid Status</label>
<select name="paid_status" class="form-select" required>
<option value="Y" <?= ($editData && $editData['PAID_STATUS']=='Y')?'selected':'' ?>>Paid</option>
<option value="N" <?= ($editData && (!isset($editData['PAID_STATUS']) || $editData['PAID_STATUS']=='N'))?'selected':'' ?>>Unpaid</option>
</select>
</div>
</div>

<div class="row">
<div class="col-md-12 mb-3">
<label>Remarks</label>
<textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes about this user..."><?= htmlspecialchars($editData['REMARKS'] ?? '') ?></textarea>
</div>
</div>

<?php if ($editData): ?>
<button type="submit" name="update_user" class="btn btn-warning"><i class="bi bi-pencil-square me-1"></i>Update User</button>
<a href="create_user.php" class="btn btn-secondary">Cancel</a>
<?php else: ?>
<button type="submit" name="save_user" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save User</button>
<?php endif; ?>
</form>
</div>
</div>
<?php endif; ?>

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
<th>User Type</th>
<th>Email</th>
<th>Phone</th>
<th>Auth</th>
<th>Paid</th>
<th>Level</th>
<th>Remarks</th>
<th>Entry Date</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($users as $u):
    $authY = ($u['AUTHORIZED_STATUS'] ?? 'N') === 'Y';
    $paidY = ($u['PAID_STATUS'] ?? 'N') === 'Y';
?>
<tr>
<td><?= htmlspecialchars($u['USER_ID']) ?></td>
<td><?= htmlspecialchars($u['USER_NAME']) ?></td>
<td><?= htmlspecialchars($u['USER_TYPE_NAME']) ?></td>
<td><?= htmlspecialchars($u['EMAIL']) ?></td>
<td><?= htmlspecialchars($u['PHONE']) ?></td>

<td>
<?php if ($canEdit): ?>
<button type="button"
        class="status-pill toggle-status <?= $authY ? 'is-y' : 'is-n' ?>"
        data-type="auth" data-uid="<?= htmlspecialchars($u['USER_ID']) ?>">
  <i class="bi <?= $authY ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
  <span class="pill-label"><?= $authY ? 'Authorized' : 'Unauthorized' ?></span>
</button>
<?php else: ?>
<span class="status-pill <?= $authY ? 'is-y' : 'is-n' ?>"><?= $authY ? 'Authorized' : 'Unauthorized' ?></span>
<?php endif; ?>
</td>

<td>
<?php if ($canEdit): ?>
<button type="button"
        class="status-pill toggle-status <?= $paidY ? 'is-y' : 'is-n' ?>"
        data-type="paid" data-uid="<?= htmlspecialchars($u['USER_ID']) ?>">
  <i class="bi <?= $paidY ? 'bi-cash-coin' : 'bi-exclamation-circle-fill' ?>"></i>
  <span class="pill-label"><?= $paidY ? 'Paid' : 'Unpaid' ?></span>
</button>
<?php else: ?>
<span class="status-pill <?= $paidY ? 'is-y' : 'is-n' ?>"><?= $paidY ? 'Paid' : 'Unpaid' ?></span>
<?php endif; ?>
</td>

<td><?= htmlspecialchars($u['LEVEL_NAME'] ?? '') ?></td>
<td><?= htmlspecialchars($u['remarks'] ?? '') ?></td>
<td><?= htmlspecialchars($u['ENTRY_DATE']) ?></td>
<td class="text-nowrap">
<?php if ($canEdit): ?>
<a href="?edit=<?= urlencode($u['USER_ID']) ?>" class="action-btn action-edit" title="Edit"><i class="bi bi-pencil-fill"></i></a>
<?php endif; ?>
<?php if ($canDelete): ?>
<a href="?delete=<?= urlencode($u['USER_ID']) ?>" class="action-btn action-delete" title="Delete" onclick="return confirm('Delete this user?')"><i class="bi bi-trash-fill"></i></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<script>
<?php if($userType==1): ?>
// Admin: dynamic branch loading
document.getElementById('orgSelect').addEventListener('change', function() {
    const org = this.value;
    const brSelect = document.getElementById('brSelect');
    brSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('?ajax=1&org=' + org)
        .then(res => res.json())
        .then(data => {
            brSelect.innerHTML = '<option value="">-- Select Branch --</option>';
            data.forEach(b => {
                const option = document.createElement('option');
                option.value = b.BR_CODE;
                option.textContent = b.BRANCH_NAME;
                brSelect.appendChild(option);
            });
        });
});
<?php else: ?>
// Non-admin: fixed branch
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('brSelect').value = '<?= $userBr ?>';
});
<?php endif; ?>

// Dynamic (live) search across the user table
document.getElementById('userSearch').addEventListener('input', function() {
    const filter = this.value.trim().toLowerCase();
    const rows = document.querySelectorAll('#userTable tbody tr');
    rows.forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});

// Real-time status toggle (Authorize/Unauthorize, Paid/Unpaid) - no page reload
document.querySelectorAll('.toggle-status').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const type = btn.dataset.type;
        const uid  = btn.dataset.uid;
        const icon = btn.querySelector('i');
        const label = btn.querySelector('.pill-label');
        const originalIconClass = icon.className;
        const originalLabel = label.textContent;

        btn.disabled = true;
        icon.className = 'spinner-border';

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'toggle_status=1&type=' + encodeURIComponent(type) + '&uid=' + encodeURIComponent(uid)
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            if (!data.ok) {
                icon.className = originalIconClass;
                alert('Could not update status. Please try again.');
                return;
            }
            const isY = data.new_status === 'Y';
            btn.classList.toggle('is-y', isY);
            btn.classList.toggle('is-n', !isY);

            if (type === 'auth') {
                icon.className = isY ? 'bi bi-check-circle-fill' : 'bi bi-x-circle-fill';
                label.textContent = isY ? 'Authorized' : 'Unauthorized';
            } else {
                icon.className = isY ? 'bi bi-cash-coin' : 'bi bi-exclamation-circle-fill';
                label.textContent = isY ? 'Paid' : 'Unpaid';
            }
        })
        .catch(() => {
            btn.disabled = false;
            icon.className = originalIconClass;
            label.textContent = originalLabel;
            alert('Network error. Please try again.');
        });
    });
});
</script>

<!-- Footer -->
<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>