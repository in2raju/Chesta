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

/* ---- Today (ইউজারের লোকাল তারিখ ধরে) ---- */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM incomes WHERE user_id=? AND entry_date=?");
$stmt->execute([$uid, $today]); $todayIncome = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE user_id=? AND entry_date=?");
$stmt->execute([$uid, $today]); $todayExpense = (float)$stmt->fetchColumn();

$todayBalance = $todayIncome - $todayExpense;

/* ---- This month (ইউজারের মাস অনুযায়ী) ---- */
$monthStart = date('Y-m-01', strtotime($today));
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM incomes WHERE user_id=? AND entry_date BETWEEN ? AND ?");
$stmt->execute([$uid, $monthStart, $today]); $monthIncome = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE user_id=? AND entry_date BETWEEN ? AND ?");
$stmt->execute([$uid, $monthStart, $today]); $monthExpense = (float)$stmt->fetchColumn();

/* ---- Receivable / Payable ---- */
function party_total(PDO $pdo, int $uid, string $type): float
{
    $sql = "SELECT COALESCE(SUM(p.opening_due),0)
              + COALESCE((SELECT SUM(d.amount) FROM due_transactions d JOIN parties p2 ON p2.id=d.party_id WHERE p2.user_id=? AND p2.type=? AND d.type='due'),0)
              - COALESCE((SELECT SUM(d.amount) FROM due_transactions d JOIN parties p2 ON p2.id=d.party_id WHERE p2.user_id=? AND p2.type=? AND d.type='payment'),0) t
            FROM parties p WHERE p.user_id=? AND p.type=?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $type, $uid, $type, $uid, $type]);
    return (float)$stmt->fetchColumn();
}
$receivable = party_total($pdo, $uid, 'customer');
$payable    = party_total($pdo, $uid, 'supplier');

/* ---- Last 7 days raw data (ইউজারের লোকাল তারিখ ধরে) ---- */
$seriesStart = date('Y-m-d', strtotime("$today -6 days"));

$stmt = $pdo->prepare("
    SELECT entry_date, COALESCE(SUM(amount),0) t
    FROM incomes
    WHERE user_id=? AND entry_date BETWEEN ? AND ?
    GROUP BY entry_date
");
$stmt->execute([$uid, $seriesStart, $today]);
$incomeRaw = [];
foreach ($stmt->fetchAll() as $r) $incomeRaw[$r['entry_date']] = (float)$r['t'];

$stmt = $pdo->prepare("
    SELECT entry_date, COALESCE(SUM(amount),0) t
    FROM expenses
    WHERE user_id=? AND entry_date BETWEEN ? AND ?
    GROUP BY entry_date
");
$stmt->execute([$uid, $seriesStart, $today]);
$expenseRaw = [];
foreach ($stmt->fetchAll() as $r) $expenseRaw[$r['entry_date']] = (float)$r['t'];

/* ---- Recent transactions (union) ---- */
$stmt = $pdo->prepare("
    (SELECT 'income' AS kind, i.entry_date, ih.name AS head, i.amount, i.payment_method, i.note, i.created_at
       FROM incomes i JOIN income_heads ih ON ih.id=i.head_id WHERE i.user_id=?)
    UNION ALL
    (SELECT 'expense' AS kind, e.entry_date, eh.name AS head, e.amount, e.payment_method, e.note, e.created_at
       FROM expenses e JOIN expense_heads eh ON eh.id=e.head_id WHERE e.user_id=?)
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([$uid, $uid]);
$recent = $stmt->fetchAll();

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-speedometer2 text-primary-dl"></i> Dashboard</h4>
  <span class="text-muted small" id="userDateTime">
    <i class="bi bi-clock"></i> <span class="placeholder col-6"></span>
  </span>
</div>

<div class="row g-3 mb-2">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-income p-3">
      <i class="bi bi-cash-coin stat-icon"></i>
      <div class="stat-label">Today's Income</div>
      <div class="stat-value"><?= money($todayIncome) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-expense p-3">
      <i class="bi bi-wallet2 stat-icon"></i>
      <div class="stat-label">Today's Expense</div>
      <div class="stat-value"><?= money($todayExpense) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-balance p-3">
      <i class="bi bi-piggy-bank stat-icon"></i>
      <div class="stat-label">Today's Balance</div>
      <div class="stat-value"><?= money($todayBalance) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-month p-3">
      <i class="bi bi-calendar3 stat-icon"></i>
      <div class="stat-label">Month Income</div>
      <div class="stat-value" style="font-size:1.25rem"><?= money($monthIncome) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-month p-3">
      <i class="bi bi-calendar3-week stat-icon"></i>
      <div class="stat-label">Month Expense</div>
      <div class="stat-value" style="font-size:1.25rem"><?= money($monthExpense) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card bg-balance p-3">
      <i class="bi bi-bar-chart stat-icon"></i>
      <div class="stat-label">Month Balance</div>
      <div class="stat-value" style="font-size:1.25rem"><?= money($monthIncome - $monthExpense) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-6">
    <div class="card stat-card bg-receivable p-3">
      <i class="bi bi-people stat-icon"></i>
      <div class="stat-label">Customer Receivable</div>
      <div class="stat-value"><?= money($receivable) ?></div>
      <a href="parties.php?type=customer" class="small text-white text-decoration-underline">View customers →</a>
    </div>
  </div>
  <div class="col-6 col-md-6">
    <div class="card stat-card bg-payable p-3">
      <i class="bi bi-truck stat-icon"></i>
      <div class="stat-label">Supplier Payable</div>
      <div class="stat-value"><?= money($payable) ?></div>
      <a href="parties.php?type=supplier" class="small text-white text-decoration-underline">View suppliers →</a>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-graph-up"></i> Income vs Expense (Last 7 Days)</div>
      <div class="card-body"><canvas id="trendChart" height="230"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-clock-history"></i> Recent Transactions</div>
      <div class="card-body p-0" style="max-height:320px; overflow-y:auto;">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Type</th><th>Head</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No transactions yet</td></tr>
          <?php endif; ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="small"><?= date('d M', strtotime($r['entry_date'])) ?></td>
              <td><span class="badge <?= $r['kind']==='income'?'bg-success':'bg-danger' ?>"><?= $r['kind']==='income'?'Income':'Expense' ?></span></td>
              <td class="small"><?= e($r['head']) ?></td>
              <td class="text-end small fw-semibold"><?= money($r['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white text-end">
        <a href="ledger.php" class="small">View full ledger →</a>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
/* ===== Remote user local date & time ===== */
(function () {
  const el = document.getElementById("userDateTime");
  if (!el) return;

  function render() {
    const now = new Date();
    const datePart = new Intl.DateTimeFormat(undefined, {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric"
    }).format(now);
    const timePart = new Intl.DateTimeFormat(undefined, {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true
    }).format(now);
    el.innerHTML = "<i class=\"bi bi-clock\"></i> " + datePart + " &middot; " + timePart;
  }

  render();
  setInterval(render, 1000);
})();

/* ===== ইউজারের লোকাল তারিখ সার্ভারে পাঠানো (প্রথমবার লোডে reload) ===== */
(function () {
  const pad = n => String(n).padStart(2, "0");
  const now = new Date();
  const localDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

  const url = new URL(window.location.href);
  const urlLocal = url.searchParams.get("local_date");

  // URL-এ local_date নেই বা ভিন্ন → ব্রাউজারের তারিখ সহ রিলোড
  if (urlLocal !== localDate) {
    url.searchParams.set("local_date", localDate);
    window.location.replace(url.toString());
  }
})();

/* ===== Last 7 days chart: ইউজারের লোকাল তারিখ অনুযায়ী ===== */
(function () {
  const incomeRaw  = ' . json_encode($incomeRaw) . ';
  const expenseRaw = ' . json_encode($expenseRaw) . ';
  const pad = n => String(n).padStart(2, "0");

  const labels = [], incomeSeries = [], expenseSeries = [];
  const today = new Date();

  for (let i = 6; i >= 0; i--) {
    const d = new Date(today);
    d.setDate(today.getDate() - i);

    const key = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const label = new Intl.DateTimeFormat(undefined, { day: "2-digit", month: "short" }).format(d);

    labels.push(label);
    incomeSeries.push(Number(incomeRaw[key]  || 0));
    expenseSeries.push(Number(expenseRaw[key] || 0));
  }

  new Chart(document.getElementById("trendChart"), {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        { label: "Income",  data: incomeSeries,  backgroundColor: "#2f6f4e", borderRadius: 4 },
        { label: "Expense", data: expenseSeries, backgroundColor: "#c0392b", borderRadius: 4 }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: "bottom" } },
      scales: { y: { beginAtZero: true } }
    }
  });
})();
</script>';
require_once __DIR__ . '/includes/footer.php';