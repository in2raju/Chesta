<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];

/* ---- ইউজারের লোকাল তারিখ (JS থেকে GET প্যারামিটার) ---- */
$localDate = get('local_date');
if (!$localDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
    $localDate = date('Y-m-d'); // fallback: সার্ভার তারিখ
}
$today = $localDate;

$from   = get('from') ?: date('Y-m-01', strtotime($today)); // এই মাসের ১ তারিখ
$to     = get('to')   ?: $today;                            // আজ
$search = trim(get('q'));
$page   = max(1, (int)get('page', 1));
$perPage = 20;

/* Combined query (income + expense) within range */
$baseSql = "
    SELECT 'income' AS kind, i.id, i.entry_date, ih.name AS head, i.amount, i.payment_method, i.note, i.created_at
      FROM incomes i JOIN income_heads ih ON ih.id = i.head_id
     WHERE i.user_id = :uid1 AND i.entry_date BETWEEN :from1 AND :to1
     " . ($search !== '' ? "AND (i.note LIKE :q1 OR ih.name LIKE :q1b)" : "") . "
    UNION ALL
    SELECT 'expense' AS kind, e.id, e.entry_date, eh.name AS head, e.amount, e.payment_method, e.note, e.created_at
      FROM expenses e JOIN expense_heads eh ON eh.id = e.head_id
     WHERE e.user_id = :uid2 AND e.entry_date BETWEEN :from2 AND :to2
     " . ($search !== '' ? "AND (e.note LIKE :q2 OR eh.name LIKE :q2b)" : "") . "
";

$params = ['uid1'=>$uid,'from1'=>$from,'to1'=>$to,'uid2'=>$uid,'from2'=>$from,'to2'=>$to];
if ($search !== '') { $params['q1']=$params['q1b']=$params['q2']=$params['q2b']="%$search%"; }

/* Count total for pagination */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($baseSql) t");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$p = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("SELECT * FROM ($baseSql) t ORDER BY entry_date DESC, created_at DESC LIMIT $perPage OFFSET {$p['offset']}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* Period totals (not just current page) */
$sumStmt = $pdo->prepare("SELECT kind, COALESCE(SUM(amount),0) t FROM ($baseSql) t GROUP BY kind");
$sumStmt->execute($params);
$sums = ['income'=>0,'expense'=>0];
foreach ($sumStmt->fetchAll() as $s) { $sums[$s['kind']] = (float)$s['t']; }
$periodIncome = $sums['income']; $periodExpense = $sums['expense'];
$periodBalance = $periodIncome - $periodExpense;

/* Opening balance = everything before "from" date */
$obStmt = $pdo->prepare("
   SELECT
     (SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND entry_date < ?) -
     (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND entry_date < ?) AS ob
");
$obStmt->execute([$uid, $from, $uid, $from]);
$openingBalance = (float)$obStmt->fetchColumn();

$pageTitle = 'Daily Ledger'; $activeMenu = 'ledger';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-journal-text text-primary-dl"></i> Daily Ledger</h4>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted small" id="userDateTime"></span>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<div class="card mb-3 no-print">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" id="ledgerForm">
      <!-- JS থেকে পাঠানো লোকাল তারিখ -->
      <input type="hidden" name="local_date" id="local_date" value="<?= e($localDate) ?>">

      <div class="col-md-3">
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" id="fromDate" class="form-control form-control-sm" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" id="toDate" class="form-control form-control-sm" value="<?= e($to) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Search (note/head)</label>
        <input type="text" name="q" class="form-control form-control-sm" value="<?= e($search) ?>">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filter</button>
      </div>
      <div class="col-12">
        <div class="form-text" id="localDateTimeHint"></div>
      </div>
    </form>
  </div>
</div>

<div class="text-center mb-3 d-none d-print-block">
  <h4><?= e(APP_NAME) ?> — Daily Ledger</h4>
  <p>Period: <?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?></p>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card stat-card bg-month p-3"><div class="stat-label">Opening Balance</div><div class="stat-value" style="font-size:1.25rem"><?= money($openingBalance) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card bg-income p-3"><div class="stat-label">Period Income</div><div class="stat-value" style="font-size:1.25rem"><?= money($periodIncome) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card bg-expense p-3"><div class="stat-label">Period Expense</div><div class="stat-value" style="font-size:1.25rem"><?= money($periodExpense) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card bg-balance p-3"><div class="stat-label">Closing Balance</div><div class="stat-value" style="font-size:1.25rem"><?= money($openingBalance + $periodBalance) ?></div></div>
  </div>
</div>

<div class="card">
  <div class="card-header">Ledger Entries (<?= $total ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Type</th><th>Head</th><th>Method</th><th>Note</th><th class="text-end">Income</th><th class="text-end">Expense</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No transactions in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= date('d M Y', strtotime($r['entry_date'])) ?></td>
          <td><span class="badge <?= $r['kind']==='income'?'bg-success':'bg-danger' ?>"><?= $r['kind']==='income'?'Income':'Expense' ?></span></td>
          <td><?= e($r['head']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= e($r['payment_method']) ?></span></td>
          <td class="small text-muted"><?= e($r['note']) ?></td>
          <td class="text-end text-success fw-semibold"><?= $r['kind']==='income' ? money($r['amount']) : '' ?></td>
          <td class="text-end text-danger fw-semibold"><?= $r['kind']==='expense' ? money($r['amount']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr class="table-light fw-bold">
          <td colspan="5" class="text-end">Page Total</td>
          <td class="text-end text-success"><?= money(array_sum(array_map(fn($r)=>$r['kind']==='income'?$r['amount']:0,$rows))) ?></td>
          <td class="text-end text-danger"><?= money(array_sum(array_map(fn($r)=>$r['kind']==='expense'?$r['amount']:0,$rows))) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="card-footer no-print">
    <?= pagination_links('ledger.php', ['from'=>$from,'to'=>$to,'q'=>$search], $p['currentPage'], $p['totalPages']) ?>
  </div>
</div>

<?php
$extraScripts = '<script>
/* ===== ইউজারের ব্রাউজারের বর্তমান তারিখ ও সময় ===== */
(function () {
  const dateEl = document.getElementById("userDateTime");
  if (dateEl) {
    const now = new Date();
    dateEl.textContent = new Intl.DateTimeFormat(undefined, {
      weekday: "long", day: "numeric", month: "long", year: "numeric",
      hour: "2-digit", minute: "2-digit"
    }).format(now);
  }
})();

/* ===== প্রথমবার লোডে local_date পাঠানো (রিলোড) ===== */
(function () {
  const pad = n => String(n).padStart(2, "0");
  const now = new Date();
  const localDate  = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  const monthStart = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`;

  const url = new URL(window.location.href);
  const urlLocal = url.searchParams.get("local_date");

  // URL-এ local_date নেই বা ভিন্ন → ব্রাউজারের তারিখ সহ রিলোড
  if (urlLocal !== localDate) {
    url.searchParams.set("local_date", localDate);
    if (!url.searchParams.has("from")) url.searchParams.set("from", monthStart);
    if (!url.searchParams.has("to"))   url.searchParams.set("to", localDate);
    window.location.replace(url.toString());
  }
})();

/* ===== হিন্ট ও সাবমিট হ্যান্ডলিং ===== */
(function () {
  const localDateField = document.getElementById("local_date");
  const hint = document.getElementById("localDateTimeHint");
  const form = document.getElementById("ledgerForm");

  function getLocalDate() {
    const now = new Date();
    const pad = n => String(n).padStart(2, "0");
    return {
      date: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
      display: now.toLocaleString(undefined, {
        weekday: "long", day: "numeric", month: "long", year: "numeric",
        hour: "2-digit", minute: "2-digit"
      })
    };
  }

  const dt = getLocalDate();
  if (hint) hint.textContent = "Your local time: " + dt.display;

  if (form) {
    form.addEventListener("submit", function () {
      if (localDateField) localDateField.value = getLocalDate().date;
    });
  }
})();
</script>';
require_once __DIR__ . '/includes/footer.php';