<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];

/* ================= Handle POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    /* ---------- Save Account Type ---------- */
    if ($action === 'save_type') {
        $id     = (int)post('id');
        $name   = trim(post('name'));
        $code   = strtolower(preg_replace('/[^a-z0-9_]/', '', trim(post('code'))));
        $icon   = trim(post('icon')) ?: 'bi-wallet2';
        $status = post('status') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || $code === '') {
            flash('danger', 'Type name and code are required.');
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE account_types SET name=?, code=?, icon=?, status=? WHERE id=? AND user_id=? AND is_system=0");
                    $stmt->execute([$name, $code, $icon, $status, $id, $uid]);
                    flash('success', 'Account type updated.');
                } else {
                    $stmt = $pdo->prepare("INSERT INTO account_types (user_id, name, code, icon, is_system, status) VALUES (?,?,?,?,0,?)");
                    $stmt->execute([$uid, $name, $code, $icon, $status]);
                    flash('success', 'Account type added.');
                }
            } catch (PDOException $ex) {
                flash('danger', 'A type with this code already exists.');
            }
        }
    }

    /* ---------- Delete Account Type ---------- */
    elseif ($action === 'delete_type') {
        $id = (int)post('id');
        $chk = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE type_id=? AND user_id=?");
        $chk->execute([$id, $uid]);
        if ((int)$chk->fetchColumn() > 0) {
            flash('danger', 'Cannot delete: this type is in use.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM account_types WHERE id=? AND user_id=? AND is_system=0");
            $stmt->execute([$id, $uid]);
            flash('success', 'Account type deleted.');
        }
    }

    /* ---------- Save Account ---------- */
    elseif ($action === 'save_account') {
        $id     = (int)post('id');
        $typeId = (int)post('type_id');
        $name   = trim(post('name'));
        $accNo  = trim(post('account_no'));
        $bank   = trim(post('bank_name'));
        $branch = trim(post('branch'));
        $open   = clean_amount(post('opening_balance'));
        $limit  = clean_amount(post('credit_limit'));
        $bill   = (int)post('billing_day') ?: null;
        $due    = (int)post('due_day') ?: null;
        $note   = trim(post('note'));
        $status = post('status') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || !$typeId) {
            flash('danger', 'Account name and type are required.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM account_types WHERE id=? AND user_id=?");
            $chk->execute([$typeId, $uid]);
            if ((int)$chk->fetchColumn() === 0) {
                flash('danger', 'Invalid account type.');
            } else {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE accounts SET type_id=?, name=?, account_no=?, bank_name=?, branch=?, credit_limit=?, billing_day=?, due_day=?, note=?, status=? WHERE id=? AND user_id=?");
                    $stmt->execute([$typeId, $name, $accNo, $bank, $branch, $limit, $bill, $due, $note, $status, $id, $uid]);
                    flash('success', 'Account updated.');
                } else {
                    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, type_id, name, account_no, bank_name, branch, opening_balance, current_balance, credit_limit, billing_day, due_day, note, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$uid, $typeId, $name, $accNo, $bank, $branch, $open, $open, $limit, $bill, $due, $note, $status]);
                    flash('success', 'Account added.');
                }
            }
        }
    }

    /* ---------- Delete Account ---------- */
    elseif ($action === 'delete_account') {
        $id = (int)post('id');
        $chk = $pdo->prepare("SELECT COUNT(*) FROM account_transactions WHERE account_id=? AND user_id=?");
        $chk->execute([$id, $uid]);
        if ((int)$chk->fetchColumn() > 0) {
            flash('danger', 'Cannot delete: this account has transactions.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM accounts WHERE id=? AND user_id=?");
            $stmt->execute([$id, $uid]);
            flash('success', 'Account deleted.');
        }
    }

    redirect('config_accounts.php');
}

/* ================= Fetch Data ================= */
$types = $pdo->prepare("SELECT * FROM account_types WHERE user_id=? ORDER BY is_system DESC, name");
$types->execute([$uid]);
$types = $types->fetchAll();

$accounts = $pdo->prepare("
  SELECT a.*, t.name AS type_name, t.code AS type_code, t.icon AS type_icon
  FROM accounts a
  JOIN account_types t ON t.id = a.type_id
  WHERE a.user_id = ?
  ORDER BY t.name, a.name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll();

$pageTitle = 'Account Configuration'; $activeMenu = 'config_accounts';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-gear text-primary-dl"></i> Account Configuration</h4>
  <div>
    <a href="accounts.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> View Accounts</a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="openAccountModal()"><i class="bi bi-plus-lg"></i> Add Account</button>
  </div>
</div>

<!-- ============ Account Types ============ -->
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-tags"></i> Account Types</span>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#typeModal" onclick="openTypeModal()"><i class="bi bi-plus-lg"></i> Add Type</button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:60px">Icon</th>
          <th>Name</th>
          <th>Code</th>
          <th>System</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$types): ?>
        <tr><td colspan="6" class="text-center text-muted py-3">No account types yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($types as $t): ?>
        <tr>
          <td><i class="bi <?= e($t['icon']) ?> fs-5 text-primary-dl"></i></td>
          <td class="fw-semibold"><?= e($t['name']) ?></td>
          <td><code><?= e($t['code']) ?></code></td>
          <td><?= $t['is_system'] ? '<span class="badge bg-secondary">System</span>' : '<span class="badge bg-info text-dark">Custom</span>' ?></td>
          <td><span class="badge <?= $t['status']==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($t['status']) ?></span></td>
          <td class="text-end text-nowrap">
            <?php if (!$t['is_system']): ?>
              <button type="button" class="btn btn-sm btn-outline-primary btn-edit-type" data-row="<?= htmlspecialchars(json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline confirm-action" data-confirm="Delete this account type?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_type">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php else: ?>
              <span class="text-muted small">Locked</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ Accounts ============ -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-wallet2"></i> Accounts (<?= count($accounts) ?>)</span>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="openAccountModal()"><i class="bi bi-plus-lg"></i> Add Account</button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Type</th>
          <th>Name</th>
          <th>Account No</th>
          <th>Bank</th>
          <th class="text-end">Opening</th>
          <th class="text-end">Current</th>
          <th class="text-end">Limit</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$accounts): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">
          No accounts yet. Click <b>Add Account</b> to create your first bank / MFS / credit card account.
        </td></tr>
      <?php endif; ?>
      <?php foreach ($accounts as $a): ?>
        <tr>
          <td>
            <i class="bi <?= e($a['type_icon']) ?> text-primary-dl"></i>
            <span class="ms-1 small"><?= e($a['type_name']) ?></span>
          </td>
          <td class="fw-semibold"><?= e($a['name']) ?></td>
          <td class="small"><?= e($a['account_no']) ?: '—' ?></td>
          <td class="small"><?= e($a['bank_name']) ?: '—' ?></td>
          <td class="text-end"><?= money($a['opening_balance']) ?></td>
          <td class="text-end fw-semibold"><?= money($a['current_balance']) ?></td>
          <td class="text-end text-muted"><?= $a['credit_limit'] > 0 ? money($a['credit_limit']) : '—' ?></td>
          <td><span class="badge <?= $a['status']==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($a['status']) ?></span></td>
          <td class="text-end text-nowrap">
            <a href="account_ledger.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ledger"><i class="bi bi-journal-text"></i></a>
            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-account" data-row="<?= htmlspecialchars(json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline confirm-action" data-confirm="Delete this account?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_account">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ Type Modal ============ -->
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_type">
      <input type="hidden" name="id" id="t_id">
      <div class="modal-header">
        <h5 class="modal-title" id="typeModalTitle">Add Account Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small">Type Name</label>
          <input type="text" name="name" id="t_name" class="form-control" required placeholder="e.g. Mobile Wallet, Investment">
        </div>
        <div class="mb-2">
          <label class="form-label small">Code (unique short)</label>
          <input type="text" name="code" id="t_code" class="form-control" required placeholder="e.g. wallet, invest">
        </div>
        <div class="mb-2">
          <label class="form-label small">Bootstrap Icon Class</label>
          <input type="text" name="icon" id="t_icon" class="form-control" value="bi-wallet2">
          <div class="form-text">Examples: <code>bi-bank</code>, <code>bi-phone</code>, <code>bi-credit-card</code></div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Status</label>
          <select name="status" id="t_status" class="form-select">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Save Type</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ Account Modal ============ -->
<div class="modal fade" id="accountModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_account">
      <input type="hidden" name="id" id="a_id">
      <div class="modal-header">
        <h5 class="modal-title" id="accountModalTitle">Add Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small">Account Type <span class="text-danger">*</span></label>
            <select name="type_id" id="a_type" class="form-select" required onchange="toggleCardFields()">
              <option value="">— Select Type —</option>
              <?php foreach ($types as $t): if ($t['status']!=='active') continue; ?>
                <option value="<?= $t['id'] ?>" data-code="<?= e($t['code']) ?>"><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Account Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="a_name" class="form-control" required placeholder="e.g. BRAC Bank, bKash Personal">
          </div>

          <div class="col-md-6">
            <label class="form-label small">Account / Card Number</label>
            <input type="text" name="account_no" id="a_no" class="form-control" placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="form-label small">Bank / Provider Name</label>
            <input type="text" name="bank_name" id="a_bank" class="form-control" placeholder="Optional">
          </div>

          <div class="col-md-6">
            <label class="form-label small">Branch</label>
            <input type="text" name="branch" id="a_branch" class="form-control" placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="form-label small">Opening Balance</label>
            <input type="number" step="0.01" name="opening_balance" id="a_open" class="form-control" value="0">
          </div>

          <div class="col-md-6 card-only d-none">
            <label class="form-label small">Credit Limit</label>
            <input type="number" step="0.01" name="credit_limit" id="a_limit" class="form-control" value="0">
          </div>
          <div class="col-md-3 card-only d-none">
            <label class="form-label small">Billing Day</label>
            <input type="number" min="1" max="31" name="billing_day" id="a_bill" class="form-control">
          </div>
          <div class="col-md-3 card-only d-none">
            <label class="form-label small">Due Day</label>
            <input type="number" min="1" max="31" name="due_day" id="a_due" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label small">Status</label>
            <select name="status" id="a_status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label small">Note</label>
            <textarea name="note" id="a_note" class="form-control" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Save Account</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ============ Type Modal ============ */
function openTypeModal(data) {
  document.getElementById('typeModalTitle').textContent = data ? 'Edit Account Type' : 'Add Account Type';
  document.getElementById('t_id').value     = data ? data.id : '';
  document.getElementById('t_name').value   = data ? data.name : '';
  document.getElementById('t_code').value   = data ? data.code : '';
  document.getElementById('t_icon').value   = data ? data.icon : 'bi-wallet2';
  document.getElementById('t_status').value = data ? data.status : 'active';
}

/* ============ Account Modal ============ */
function openAccountModal(data) {
  document.getElementById('accountModalTitle').textContent = data ? 'Edit Account' : 'Add Account';
  document.getElementById('a_id').value     = data ? data.id : '';
  document.getElementById('a_type').value   = data ? String(data.type_id) : '';
  document.getElementById('a_name').value   = data ? data.name : '';
  document.getElementById('a_no').value     = data ? (data.account_no || '') : '';
  document.getElementById('a_bank').value   = data ? (data.bank_name || '') : '';
  document.getElementById('a_branch').value = data ? (data.branch || '') : '';
  document.getElementById('a_open').value   = data ? (data.opening_balance || 0) : 0;
  document.getElementById('a_limit').value  = data ? (data.credit_limit || 0) : 0;
  document.getElementById('a_bill').value   = data ? (data.billing_day || '') : '';
  document.getElementById('a_due').value    = data ? (data.due_day || '') : '';
  document.getElementById('a_note').value   = data ? (data.note || '') : '';
  document.getElementById('a_status').value = data ? data.status : 'active';
  toggleCardFields();
}

function toggleCardFields() {
  const sel = document.getElementById('a_type');
  const code = sel.options[sel.selectedIndex]?.dataset.code || '';
  const show = (code === 'card');
  document.querySelectorAll('.card-only').forEach(el => el.classList.toggle('d-none', !show));
}

/* ============ Delegated edit buttons (safe) ============ */
document.addEventListener('click', function (e) {
  const editTypeBtn = e.target.closest('.btn-edit-type');
  if (editTypeBtn) {
    try {
      const data = JSON.parse(editTypeBtn.dataset.row);
      openTypeModal(data);
      new bootstrap.Modal(document.getElementById('typeModal')).show();
    } catch (err) {
      console.error('Type parse error:', err, editTypeBtn.dataset.row);
      alert('Could not load type data. Check console.');
    }
    return;
  }

  const editAccBtn = e.target.closest('.btn-edit-account');
  if (editAccBtn) {
    try {
      const data = JSON.parse(editAccBtn.dataset.row);
      openAccountModal(data);
      new bootstrap.Modal(document.getElementById('accountModal')).show();
    } catch (err) {
      console.error('Account parse error:', err, editAccBtn.dataset.row);
      alert('Could not load account data. Check console.');
    }
    return;
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>