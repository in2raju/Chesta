<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'save') {
        $id       = (int)post('id');
        $name     = trim(post('name'));
        $username = trim(post('username'));
        $email    = trim(post('email'));
        $phone    = trim(post('phone'));
        $business = trim(post('business_name'));
        $role     = post('role') === 'admin' ? 'admin' : 'user';
        $password = post('password');

        if ($name === '' || $username === '') {
            flash('danger', 'Name and username are required.');
        } else {
            try {
                if ($id > 0) {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET name=?, username=?, email=?, phone=?, business_name=?, role=?, password=? WHERE id=?");
                        $stmt->execute([$name, $username, $email, $phone, $business, $role, $hash, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET name=?, username=?, email=?, phone=?, business_name=?, role=? WHERE id=?");
                        $stmt->execute([$name, $username, $email, $phone, $business, $role, $id]);
                    }
                    flash('success', 'User updated.');
                } else {
                    if ($password === '') $password = 'Pass@123';
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, phone, business_name, role, password) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$name, $username, $email, $phone, $business, $role, $hash]);
                    flash('success', "User created. Temporary password: $password");
                }
            } catch (PDOException $ex) {
                flash('danger', 'Username or email already exists.');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)post('id');
        if ($id === $uid) {
            flash('danger', 'You cannot deactivate your own account.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id=?");
            $stmt->execute([$id]);
            flash('success', 'User status updated.');
        }
    } elseif ($action === 'reset_password') {
        $id = (int)post('id');
        $newPass = post('new_password') ?: 'Pass@123';
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->execute([$hash, $id]);
        flash('success', "Password reset. New password: $newPass");
    }
    redirect('users.php');
}

$search = trim(get('q'));
$page = max(1, (int)get('page', 1));
$perPage = 15;
$where = "WHERE 1=1"; $params = [];
if ($search !== '') { $where .= " AND (name LIKE ? OR username LIKE ? OR email LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$p = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY created_at DESC LIMIT $perPage OFFSET {$p['offset']}");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users'; $activeMenu = 'users';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-person-badge text-primary-dl"></i> Manage Users</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal()"><i class="bi bi-plus-lg"></i> Add User</button>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2">
      <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name/username/email..." value="<?= e($search) ?>"></div>
      <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Search</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Users (<?= $total ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Business</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['username']) ?></td>
          <td class="small"><?= e($u['email']) ?></td>
          <td class="small"><?= e($u['business_name']) ?></td>
          <td><span class="badge <?= $u['role']==='admin'?'bg-dark':'bg-info text-dark' ?>"><?= ucfirst($u['role']) ?></span></td>
          <td><span class="badge <?= $u['status']==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($u['status']) ?></span></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary" title="Edit" onclick='openUserModal(<?= json_encode($u) ?>)'><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-warning" title="Reset Password" onclick='openResetModal(<?= (int)$u['id'] ?>, "<?= e($u['name']) ?>")'><i class="bi bi-key"></i></button>
            <?php if ((int)$u['id'] !== $uid): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" title="Activate/Deactivate"><i class="bi bi-power"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><?= pagination_links('users.php', ['q'=>$search], $p['currentPage'], $p['totalPages']) ?></div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="u_id">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small">Full Name</label><input type="text" name="name" id="u_name" class="form-control" required></div>
          <div class="col-md-6"><label class="form-label small">Username</label><input type="text" name="username" id="u_username" class="form-control" required></div>
          <div class="col-md-6"><label class="form-label small">Email</label><input type="email" name="email" id="u_email" class="form-control"></div>
          <div class="col-md-6"><label class="form-label small">Phone</label><input type="text" name="phone" id="u_phone" class="form-control"></div>
          <div class="col-md-6"><label class="form-label small">Business Name</label><input type="text" name="business_name" id="u_business" class="form-control"></div>
          <div class="col-md-6">
            <label class="form-label small">Role</label>
            <select name="role" id="u_role" class="form-select">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small">Password <span class="text-muted" id="u_pass_hint">(leave blank to keep unchanged)</span></label>
            <input type="text" name="password" id="u_password" class="form-control" placeholder="Set a password">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" id="r_id">
      <div class="modal-header">
        <h5 class="modal-title">Reset Password for <span id="r_name"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small">New Password</label>
        <input type="text" name="new_password" class="form-control" placeholder="Leave blank for default: Pass@123">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUserModal(data) {
  document.getElementById('userModalTitle').textContent = data ? 'Edit User' : 'Add User';
  document.getElementById('u_id').value = data ? data.id : '';
  document.getElementById('u_name').value = data ? data.name : '';
  document.getElementById('u_username').value = data ? data.username : '';
  document.getElementById('u_email').value = data ? data.email : '';
  document.getElementById('u_phone').value = data ? data.phone : '';
  document.getElementById('u_business').value = data ? data.business_name : '';
  document.getElementById('u_role').value = data ? data.role : 'user';
  document.getElementById('u_password').value = '';
  document.getElementById('u_pass_hint').style.display = data ? 'inline' : 'none';
}
function openResetModal(id, name) {
  document.getElementById('r_id').value = id;
  document.getElementById('r_name').textContent = name;
  new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
