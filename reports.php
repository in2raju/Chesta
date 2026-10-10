<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$uid = $_SESSION['user_id'];

$groupBy = get('group', 'daily') === 'monthly' ? 'monthly' : 'daily';

/* ডিফল্ট তারিখ: ইউজারের ব্রাউজার থেকে আসা লোকাল তারিখ (fallback: সার্ভার তারিখ) */
$localDate = get('local_date');
if (!$localDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
    $localDate = date('Y-m-d');
}
$today = $localDate;

/* ⬇⬇ ডিফল্ট রেঞ্জ = শেষ ৭ দিন ⬇⬇ */
$from = get('from') ?: date('Y-m-d', strtotime("$today -6 days"));
$to   = get('to')   ?: $today;

/* ⬇⬇ নিরাপত্তা: যদি from/to ভুল থাকে, ঠিক করো ⬇⬇ */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime("$today -6 days"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;

$dateExpr = $groupBy === 'monthly' ? "DATE_FORMAT(entry_date, '%Y-%m')" : "entry_date";

/* ---- Income grouped ---- */
$stmt = $pdo->prepare("SELECT $dateExpr AS period, COALESCE(SUM(amount),0) t FROM incomes WHERE user_id=? AND entry_date BETWEEN ? AND ? GROUP BY period ORDER BY period");
$stmt->execute([$uid, $from, $to]);
$incomeByPeriod = [];
foreach ($stmt->fetchAll() as $r) $incomeByPeriod[$r['period']] = (float)$r['t'];

/* ---- Expense grouped ---- */
$stmt = $pdo->prepare("SELECT $dateExpr AS period, COALESCE(SUM(amount),0) t FROM expenses WHERE user_id=? AND entry_date BETWEEN ? AND ? GROUP BY period ORDER BY period");
$stmt->execute([$uid, $from, $to]);
$expenseByPeriod = [];
foreach ($stmt->fetchAll() as $r) $expenseByPeriod[$r['period']] = (float)$r['t'];

/* ⬇⬇ periods তৈরি: ৭ দিনের সব তারিখ রাখো, ডেটা না থাকলেও 0 ⬇⬇ */
if ($groupBy === 'daily') {
    $periods = [];
    $start = new DateTime($from);
    $end   = new DateTime($to);
    while ($start <= $end) {
        $periods[] = $start->format('Y-m-d');
        $start->modify('+1 day');
    }
} else {
    $periods = array_unique(array_merge(array_keys($incomeByPeriod), array_keys($expenseByPeriod)));
    sort($periods);
}

$grandIncome  = array_sum($incomeByPeriod);
$grandExpense = array_sum($expenseByPeriod);

/* ---- Income head-wise ---- */
$stmt = $pdo->prepare("SELECT ih.name, COALESCE(SUM(i.amount),0) t FROM incomes i JOIN income_heads ih ON ih.id=i.head_id WHERE i.user_id=? AND i.entry_date BETWEEN ? AND ? GROUP BY ih.name ORDER BY t DESC");
$stmt->execute([$uid, $from, $to]);
$incomeByHead = $stmt->fetchAll();

/* ---- Expense head-wise ---- */
$stmt = $pdo->prepare("SELECT eh.name, COALESCE(SUM(e.amount),0) t FROM expenses e JOIN expense_heads eh ON eh.id=e.head_id WHERE e.user_id=? AND e.entry_date BETWEEN ? AND ? GROUP BY eh.name ORDER BY t DESC");
$stmt->execute([$uid, $from, $to]);
$expenseByHead = $stmt->fetchAll();

/* ---- Payment method-wise ---- */
$stmt = $pdo->prepare("
   SELECT payment_method, SUM(inc) inc, SUM(exp) exp FROM (
     SELECT payment_method, amount inc, 0 exp FROM incomes WHERE user_id=? AND entry_date BETWEEN ? AND ?
     UNION ALL
     SELECT payment_method, 0 inc, amount exp FROM expenses WHERE user_id=? AND entry_date BETWEEN ? AND ?
   ) t GROUP BY payment_method
");
$stmt->execute([$uid, $from, $to, $uid, $from, $to]);
$byMethod = $stmt->fetchAll();

/* ---- Chart data ---- */
$chartLabels = array_map(function($per) use ($groupBy) {
    return $groupBy==='monthly' ? date('M Y', strtotime($per.'-01')) : date('d M', strtotime($per));
}, $periods);
$chartIncome  = array_map(fn($per) => $incomeByPeriod[$per] ?? 0, $periods);
$chartExpense = array_map(fn($per) => $expenseByPeriod[$per] ?? 0, $periods);

$pageTitle = 'Reports'; $activeMenu = 'reports';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-bar-chart-line text-primary-dl"></i> Reports</h4>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted small" id="userDateTime"></span>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
  </div>
</div>

<div class="card mb-3 no-print">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" id="reportForm">
      <input type="hidden" name="local_date" id="local_date" value="<?= e($localDate) ?>">

      <div class="col-md-3">
        <label class="form-label small mb-1">Group By</label>
        <select name="group" class="form-select form-select-sm">
          <option value="daily"   <?= $groupBy==='daily'?'selected':'' ?>>Date-wise</option>
          <option value="monthly" <?= $groupBy==='monthly'?'selected':'' ?>>Month-wise</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" id="fromDate" class="form-control form-control-sm" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" id="toDate" class="form-control form-control-sm" value="<?= e($to) ?>">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Generate</button>
      </div>
      <div class="col-12">
        <div class="form-text" id="localDateTimeHint"></div>
      </div>
    </form>
  </div>
</div>

<div class="text-center mb-3 d-none d-print-block">
  <h4><?= e(APP_NAME) ?> — Financial Report</h4>
  <p><?= $groupBy==='monthly'?'Month-wise':'Date-wise' ?> · <?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?></p>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-4">
    <div class="card stat-card bg-income p-3"><div class="stat-label">Total Income</div><div class="stat-value"><?= money($grandIncome) ?></div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card stat-card bg-expense p-3"><div class="stat-label">Total Expense</div><div class="stat-value"><?= money($grandExpense) ?></div></div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card stat-card bg-balance p-3"><div class="stat-label">Net Balance</div><div class="stat-value"><?= money($grandIncome - $grandExpense) ?></div></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-graph-up"></i> Income vs Expense Trend</div>
      <div class="card-body"><canvas id="repChart" height="90"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><?= $groupBy==='monthly'?'Month-wise':'Date-wise' ?> Summary</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th><?= $groupBy==='monthly'?'Month':'Date' ?></th><th class="text-end">Income</th><th class="text-end">Expense</th><th class="text-end">Balance</th></tr></thead>
          <tbody>
          <?php if (!$periods): ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
          <?php foreach ($periods as $per):
              $inc = $incomeByPeriod[$per] ?? 0; $exp = $expenseByPeriod[$per] ?? 0;
              $label = $groupBy==='monthly' ? date('M Y', strtotime($per.'-01')) : date('d M Y', strtotime($per));
          ?>
            <tr>
              <td><?= e($label) ?></td>
              <td class="text-end text-success"><?= money($inc) ?></td>
              <td class="text-end text-danger"><?= money($exp) ?></td>
              <td class="text-end fw-semibold"><?= money($inc-$exp) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Payment Method Summary</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Method</th><th class="text-end">Income</th><th class="text-end">Expense</th></tr></thead>
          <tbody>
          <?php if (!$byMethod): ?><tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
          <?php foreach ($byMethod as $m): ?>
            <tr><td><?= e($m['payment_method']) ?></td><td class="text-end text-success"><?= money($m['inc']) ?></td><td class="text-end text-danger"><?= money($m['exp']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header text-success">Income by Head</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Head</th><th class="text-end">Amount</th><th class="text-end">%</th></tr></thead>
          <tbody>
          <?php if (!$incomeByHead): ?><tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
          <?php foreach ($incomeByHead as $h): ?>
            <tr><td><?= e($h['name']) ?></td><td class="text-end"><?= money($h['t']) ?></td><td class="text-end text-muted"><?= $grandIncome>0?round($h['t']/$grandIncome*100,1):0 ?>%</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header text-danger">Expense by Head</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Head</th><th class="text-end">Amount</th><th class="text-end">%</th></tr></thead>
          <tbody>
          <?php if (!$expenseByHead): ?><tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
          <?php foreach ($expenseByHead as $h): ?>
            <tr><td><?= e($h['name']) ?></td><td class="text-end"><?= money($h['t']) ?></td><td class="text-end text-muted"><?= $grandExpense>0?round($h['t']/$grandExpense*100,1):0 ?>%</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
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

/* ===== শেষ ৭ দিনের রেঞ্জ নিশ্চিত করা ===== */
(function () {
  const pad = n => String(n).padStart(2, "0");
  const now = new Date();

  const localDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

  // শেষ ৭ দিনের শুরু = আজ - ৬ দিন
  const start = new Date(now);
  start.setDate(now.getDate() - 6);
  const weekStart = `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`;

  const url = new URL(window.location.href);
  const urlLocal = url.searchParams.get("local_date");
  const urlFrom  = url.searchParams.get("from");
  const urlTo    = url.searchParams.get("to");

  const monthStart = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`;

  /* রিসেট দরকার যদি:
     - local_date নেই বা ভিন্ন
     - from/to নেই
     - from === মাসের ১ তারিখ (পুরনো ডিফল্ট)
     - to !== আজ
     - from, ৭ দিনের বাইরে (আজকের থেকে ৭+ দিন আগে)
  */
  const isOldFrom = urlFrom && (urlFrom < weekStart || urlFrom === monthStart);
  const needsReset =
    urlLocal !== localDate ||
    !urlFrom ||
    !urlTo ||
    urlTo !== localDate ||
    isOldFrom;

  if (needsReset) {
    url.searchParams.set("local_date", localDate);
    url.searchParams.set("from", weekStart);
    url.searchParams.set("to", localDate);
    window.location.replace(url.toString());
  }
})();

/* ===== হিন্ট ও সাবমিট হ্যান্ডলিং ===== */
(function () {
  const localDateField = document.getElementById("local_date");
  const hint = document.getElementById("localDateTimeHint");
  const form = document.getElementById("reportForm");

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

/* ===== Chart ===== */
new Chart(document.getElementById("repChart"), {
  type: "line",
  data: {
    labels: ' . json_encode($chartLabels) . ',
    datasets: [
      { label: "Income",  data: ' . json_encode($chartIncome)  . ', borderColor:"#2f6f4e", backgroundColor:"rgba(47,111,78,.15)",  tension:.3, fill:true },
      { label: "Expense", data: ' . json_encode($chartExpense) . ', borderColor:"#c0392b", backgroundColor:"rgba(192,57,43,.15)", tension:.3, fill:true }
    ]
  },
  options: { responsive:true, plugins:{legend:{position:"bottom"}}, scales:{y:{beginAtZero:true}} }
});
</script>';
require_once __DIR__ . '/includes/footer.php';