<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userId          = $_SESSION['user']['user_id'];
$orgCode         = $_SESSION['user']['org_code'];
$brCode          = $_SESSION['user']['br_code'] ?? null;
$userTypeSession = (int)($_SESSION['user']['user_type_id'] ?? 0);
$canEdit         = (int)($_SESSION['user']['can_edit'] ?? 1);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf    = $_SESSION['csrf'];
$message = '';

// =====================================================
// Helpers
// =====================================================
function maxPermissionNumber(PDO $pdo): int
{
    $rows = $pdo->query("
        SELECT PERMISSION_ID FROM user_menu_view_permission
        WHERE PERMISSION_ID REGEXP '^R[0-9]+$'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $max = 0;
    foreach ($rows as $pid) {
        $n = (int)substr((string)$pid, 1);
        if ($n > $max) $max = $n;
    }
    return $max;
}

function ensurePermissionRows(PDO $pdo, array $menuIds, int $userTypeId, $orgCode, $brCode, $entryUser): void
{
    $st = $pdo->prepare("SELECT MENU_ID FROM user_menu_view_permission WHERE USER_TYPE_ID = :u");
    $st->execute(['u' => $userTypeId]);
    $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $missing  = array_values(array_diff($menuIds, $existing));
    if (!$missing) return;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $next = maxPermissionNumber($pdo) + 1;
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO user_menu_view_permission
                    (PERMISSION_ID, USER_TYPE_ID, MENU_ID, CAN_VIEW,
                     ENTRY_USER, ENTRY_DATE, ORG_CODE, BR_CODE)
                VALUES (:pid, :utid, :mid, 0, :eu, CURDATE(), :org, :br)
            ");
            foreach ($missing as $mid) {
                $ins->execute([
                    'pid'  => 'R' . str_pad((string)$next++, 3, '0', STR_PAD_LEFT),
                    'utid' => $userTypeId,
                    'mid'  => $mid,
                    'eu'   => $entryUser,
                    'org'  => $orgCode,
                    'br'   => $brCode,
                ]);
            }
            $pdo->commit();
            return;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() !== '23000') throw $e;
        }
    }
}

/** Flat ordered list with _level, _parent, _has_children. Cycle/orphan safe. */
function buildMenuTree(array $menus): array
{
    $byId = [];
    foreach ($menus as $m) $byId[(int)$m['MENU_ID']] = $m;

    $children = [];
    $roots    = [];
    foreach ($byId as $id => $m) {
        $pid = (int)($m['PARENT_ID'] ?? 0);
        if ($pid === 0 || $pid === $id || !isset($byId[$pid])) {
            $roots[] = $id;
        } else {
            $children[$pid][] = $id;
        }
    }

    $flat    = [];
    $visited = [];
    $walk = function (array $ids, int $level, int $parent) use (&$walk, &$flat, &$visited, $byId, $children) {
        foreach ($ids as $id) {
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            $node = $byId[$id];
            $node['_level']        = $level;
            $node['_parent']       = $parent;
            $node['_has_children'] = !empty($children[$id]);
            $flat[] = $node;
            if (!empty($children[$id])) $walk($children[$id], $level + 1, $id);
        }
    };
    $walk($roots, 0, 0);

    foreach ($byId as $id => $_) {
        if (!isset($visited[$id])) $walk([$id], 0, 0);
    }
    return $flat;
}

// =====================================================
// Data
// =====================================================
if ($userTypeSession === 1) {
    $permUserTypes = $pdo->query("
        SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info ORDER BY USER_TYPE_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info WHERE USER_TYPE_ID = :uid
    ");
    $stmt->execute(['uid' => $userTypeSession]);
    $permUserTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$allowedTypeIds = array_map(fn($u) => (int)$u['USER_TYPE_ID'], $permUserTypes);

$permTypeId = (int)($_POST['user_type_id'] ?? $_GET['user_type_id'] ?? 0);
if (!in_array($permTypeId, $allowedTypeIds, true)) $permTypeId = 0;

$permMenusRaw = $pdo->query("
    SELECT MENU_ID, MENU_NAME, PARENT_ID, MENU_LINK FROM menu_info ORDER BY MENU_NAME
")->fetchAll(PDO::FETCH_ASSOC);
$permMenus   = buildMenuTree($permMenusRaw);
$permMenuIds = array_map(fn($m) => (int)$m['MENU_ID'], $permMenus);

// =====================================================
// Save
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permission']) && $permTypeId) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = ['danger', 'Invalid session token. Please reload the page and try again.'];
    } elseif (!$canEdit) {
        $message = ['danger', 'You do not have permission to edit.'];
    } else {
        $selected = array_flip(array_intersect(array_map('intval', (array)($_POST['menus'] ?? [])), $permMenuIds));
        try {
            ensurePermissionRows($pdo, $permMenuIds, $permTypeId, $orgCode, $brCode, $userId);

            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE user_menu_view_permission
                SET CAN_VIEW = :cv, EDIT_USER = :eu, EDIT_DATE = CURDATE()
                WHERE USER_TYPE_ID = :uid AND MENU_ID = :mid
            ");
            foreach ($permMenuIds as $mid) {
                $upd->execute([
                    'cv'  => isset($selected[$mid]) ? 1 : 0,
                    'eu'  => $userId,
                    'uid' => $permTypeId,
                    'mid' => $mid,
                ]);
            }
            $pdo->commit();
            $message = ['success', 'Permissions saved. ' . count($selected) . ' of ' . count($permMenuIds) . ' menus are now visible.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = ['danger', 'Could not save permissions. Please try again.'];
        }
    }
}

$permByType = [];
if ($permTypeId) {
    ensurePermissionRows($pdo, $permMenuIds, $permTypeId, $orgCode, $brCode, $userId);

    if ($allowedTypeIds) {
        $in = implode(',', array_map('intval', $allowedTypeIds));
        $rows = $pdo->query("
            SELECT USER_TYPE_ID, MENU_ID FROM user_menu_view_permission
            WHERE CAN_VIEW = 1 AND USER_TYPE_ID IN ($in)
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $permByType[(int)$r['USER_TYPE_ID']][] = (int)$r['MENU_ID'];
        }
    }
}
$permCurrentSet = array_flip($permByType[$permTypeId] ?? []);
$permTypeName   = '';
foreach ($permUserTypes as $ut) {
    if ((int)$ut['USER_TYPE_ID'] === $permTypeId) $permTypeName = $ut['USER_TYPE_NAME'];
}

// =====================================================
// Render the page body NOW (before header.php runs), so nothing
// in header.php can overwrite these variables.
// =====================================================
ob_start();
?>
<div class="perm-page">

    <div class="perm-hero">
        <div>
            <h1 class="perm-title">Menu permissions</h1>
            <p class="perm-sub">Choose which menus each user type can see.</p>
        </div>
        <form method="get" class="perm-type-pick">
            <label for="utSelect" class="perm-eyebrow">User type</label>
            <select id="utSelect" name="user_type_id" class="form-select" onchange="this.form.submit()" required>
                <option value="">Select a user type…</option>
                <?php foreach ($permUserTypes as $ut): ?>
                    <option value="<?= (int)$ut['USER_TYPE_ID'] ?>"
                        <?= $permTypeId === (int)$ut['USER_TYPE_ID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ut['USER_TYPE_NAME']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message[0] ?> perm-alert" role="alert">
            <i class="bi <?= $message[0] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
            <span><?= htmlspecialchars($message[1]) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$permTypeId): ?>
        <div class="perm-empty-state">
            <i class="bi bi-diagram-3"></i>
            <h5>Select a user type to begin</h5>
            <p>The menu tree will appear here.</p>
        </div>
    <?php elseif (!$permMenus): ?>
        <div class="perm-empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No menus found</h5>
            <p>Add rows to <code>menu_info</code> first.</p>
        </div>
    <?php else: ?>
    <form method="post" id="permForm" class="perm-card">
        <input type="hidden" name="user_type_id" value="<?= $permTypeId ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <!-- Summary -->
        <div class="perm-summary">
            <div class="perm-summary-main">
                <div class="perm-eyebrow">Editing</div>
                <div class="perm-summary-name"><?= htmlspecialchars($permTypeName) ?></div>
            </div>
            <div class="perm-summary-stat">
                <div class="perm-stat-row">
                    <span><strong id="selected-count">0</strong> menus visible</span>
                    <span class="perm-dirty" id="dirtyTag"><i class="bi bi-dot"></i>Unsaved changes</span>
                </div>
                <div class="progress perm-progress"><div class="progress-bar" id="selBar" style="width:0%"></div></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="perm-toolbar">
            <div class="perm-search">
                <i class="bi bi-search"></i>
                <input type="search" id="treeSearch" class="form-control" placeholder="Search menus…" autocomplete="off">
                <kbd>/</kbd>
            </div>

            <div class="perm-tools">
                <button type="button" class="btn btn-soft" onclick="tree.checkAll(true)"><i class="bi bi-check2-all"></i> Select all</button>
                <button type="button" class="btn btn-soft" onclick="tree.checkAll(false)"><i class="bi bi-x-lg"></i> Clear</button>
                <span class="perm-sep"></span>
                <button type="button" class="btn btn-soft" onclick="tree.expandAll(true)"><i class="bi bi-arrows-expand"></i> Expand</button>
                <button type="button" class="btn btn-soft" onclick="tree.expandAll(false)"><i class="bi bi-arrows-collapse"></i> Collapse</button>
            </div>

        </div>

        <!-- Tree -->
        <div class="perm-tree" id="tree">
            <?php foreach ($permMenus as $pm):
                $pmId     = (int)$pm['MENU_ID'];
                $pmLevel  = (int)$pm['_level'];
                $pmParent = $pm['_has_children'];
                $pmOn     = isset($permCurrentSet[$pmId]);
                $pmSearch = mb_strtolower($pm['MENU_NAME'] . ' ' . ($pm['MENU_LINK'] ?? ''));
            ?>
            <div class="tree-row <?= $pmParent ? 'is-parent' : '' ?>"
                 data-level="<?= $pmLevel ?>" data-id="<?= $pmId ?>"
                 data-parent="<?= (int)$pm['_parent'] ?>"
                 data-search="<?= htmlspecialchars($pmSearch) ?>">

                <span class="tree-indent" aria-hidden="true">
                    <?php for ($i = 0; $i < $pmLevel; $i++): ?>
                        <span class="col <?= $i === $pmLevel - 1 ? 'elbow' : '' ?>"></span>
                    <?php endfor; ?>
                </span>

                <button type="button" class="tree-toggle <?= $pmParent ? '' : 'leaf' ?>"
                        <?= $pmParent ? '' : 'tabindex="-1" disabled' ?> aria-label="Expand or collapse">
                    <i class="bi bi-chevron-down"></i>
                </button>

                <span class="tree-icon <?= $pmParent ? 'is-folder' : 'is-page' ?>">
                    <i class="bi <?= $pmParent ? 'bi-folder2-open' : 'bi-file-earmark-text' ?>"></i>
                </span>

                <label class="tree-label">
                    <span class="tree-name"><?= htmlspecialchars($pm['MENU_NAME']) ?></span>
                    <?php if (!empty($pm['MENU_LINK'])): ?>
                        <code class="tree-link"><?= htmlspecialchars($pm['MENU_LINK']) ?></code>
                    <?php endif; ?>
                    <?php if ($pmParent): ?><span class="tree-count"></span><?php endif; ?>
                    <input type="checkbox" class="form-check-input tree-cb" name="menus[]"
                           value="<?= $pmId ?>" data-id="<?= $pmId ?>" <?= $pmOn ? 'checked' : '' ?>>
                </label>
            </div>
            <?php endforeach; ?>
            <div class="tree-none" id="emptyNote">
                <i class="bi bi-search"></i> No menus match your search.
            </div>
        </div>

        <!-- Footer -->
        <div class="perm-footer">
            <span class="perm-hint"><i class="bi bi-info-circle"></i> Selecting a menu also selects the folders above it.</span>
            <div class="perm-actions">
                <a href="user_menu_permission.php?user_type_id=<?= $permTypeId ?>" class="btn btn-light border">Reset</a>
                <?php if ($canEdit): ?>
                    <button type="submit" name="save_permission" class="btn btn-brand"><i class="bi bi-check2"></i> Save changes</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php
$permContent = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Menu Permissions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .perm-page {
            --brand: #4f46e5;
            --brand-soft: #eef0ff;
            --ink: #172033;
            --muted: #6b7690;
            --line: #e6e9f2;
            --surface: #ffffff;
            --bg: #f5f6fb;
            --radius: 16px;
            max-width: 1040px; margin: 0 auto; color: var(--ink);
        }
        body { background: #f5f6fb; }

        /* Hero */
        .perm-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .perm-title { font-size: 1.65rem; font-weight: 700; letter-spacing: -.02em; margin: 0; }
        .perm-sub { color: var(--muted); margin: 2px 0 0; }
        .perm-eyebrow { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 600; margin-bottom: 4px; display: block; }
        .perm-type-pick { min-width: 260px; }
        .perm-type-pick .form-select { border-radius: 12px; border-color: var(--line); padding: 10px 14px; box-shadow: 0 1px 2px rgba(20,30,60,.04); }

        .perm-alert { display: flex; align-items: center; gap: 10px; border: 0; border-radius: 12px; }

        /* Empty states */
        .perm-empty-state { text-align: center; background: var(--surface); border: 1px dashed #cfd5e6; border-radius: var(--radius); padding: 56px 20px; color: var(--muted); }
        .perm-empty-state i { font-size: 2.4rem; color: #aab3cf; }
        .perm-empty-state h5 { color: var(--ink); margin: 12px 0 4px; }

        /* Card */
        .perm-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: 0 8px 30px rgba(20,30,70,.06); overflow: hidden; }

        .perm-summary { display: flex; justify-content: space-between; align-items: center; gap: 24px; flex-wrap: wrap; padding: 18px 22px; background: linear-gradient(135deg, #4f46e5 0%, #7c5cff 100%); color: #fff; }
        .perm-summary .perm-eyebrow { color: rgba(255,255,255,.75); }
        .perm-summary-name { font-size: 1.25rem; font-weight: 700; }
        .perm-summary-stat { min-width: 260px; flex: 1; max-width: 380px; }
        .perm-stat-row { display: flex; justify-content: space-between; font-size: .88rem; margin-bottom: 6px; }
        .perm-progress { height: 8px; background: rgba(255,255,255,.25); border-radius: 99px; }
        .perm-progress .progress-bar { background: #fff; border-radius: 99px; transition: width .25s ease; }
        .perm-dirty { display: none; font-weight: 600; }
        .dirty .perm-dirty { display: inline-flex; align-items: center; }
        .perm-dirty i { font-size: 1.4rem; line-height: 0; color: #ffd166; }

        /* Toolbar */
        .perm-toolbar { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; padding: 14px 22px; border-bottom: 1px solid var(--line); }
        .perm-search { position: relative; flex: 1 1 260px; }
        .perm-search > i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); }
        .perm-search .form-control { padding-left: 38px; padding-right: 38px; border-radius: 12px; background: #f6f7fc; border-color: transparent; height: 42px; }
        .perm-search .form-control:focus { background: #fff; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(79,70,229,.12); }
        .perm-search kbd { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: #fff; color: var(--muted); border: 1px solid var(--line); font-size: .7rem; padding: 1px 6px; }
        .perm-tools { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .perm-sep { width: 1px; height: 22px; background: var(--line); margin: 0 4px; }
        .btn-soft { background: #f3f4fb; border: 0; color: #38435f; border-radius: 10px; font-size: .85rem; padding: 7px 12px; }
        .btn-soft:hover { background: var(--brand-soft); color: var(--brand); }
        .perm-copy { width: 220px; }
        .perm-copy .form-select { border-radius: 10px 0 0 10px; font-size: .85rem; background-color: #f3f4fb; border: 0; }
        .perm-copy .btn { border-radius: 0 10px 10px 0; }
        .form-switch .form-check-input:checked { background-color: var(--brand); border-color: var(--brand); }
        .form-switch .form-check-label { font-size: .85rem; color: #38435f; }

        /* Tree */
        .perm-tree { padding: 10px 14px; max-height: 62vh; overflow-y: auto; }
        .tree-row { display: flex; align-items: center; min-height: 44px; padding: 0 8px; border-radius: 10px; transition: background .12s ease; }
        .tree-row:hover { background: #f4f6ff; }
        .tree-row.hidden { display: none; }
        .tree-row.dim .tree-name { opacity: .5; }
        .tree-row.is-parent .tree-name { font-weight: 600; }

        .tree-indent { display: inline-flex; align-self: stretch; flex-shrink: 0; }
        .tree-indent .col { width: 26px; position: relative; flex-shrink: 0; }
        .tree-indent .col::before { content: ""; position: absolute; left: 12px; top: 0; bottom: 0; width: 1px; background: #e3e7f3; }
        .tree-indent .col.elbow::before { bottom: 50%; }
        .tree-indent .col.elbow::after { content: ""; position: absolute; left: 12px; top: 50%; width: 13px; height: 1px; background: #e3e7f3; }

        .tree-toggle { width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border: 0; background: transparent; border-radius: 8px; color: var(--muted); flex-shrink: 0; padding: 0; transition: background .15s ease; }
        .tree-toggle:hover { background: #e7eafb; color: var(--brand); }
        .tree-toggle i { font-size: .8rem; transition: transform .18s ease; }
        .tree-toggle.collapsed i { transform: rotate(-90deg); }
        .tree-toggle.leaf { visibility: hidden; }

        .tree-icon { width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; margin: 0 10px 0 4px; flex-shrink: 0; font-size: .95rem; }
        .tree-icon.is-folder { background: #fff3d6; color: #c98a00; }
        .tree-icon.is-page   { background: #e8f0ff; color: #3b6fe0; }

        .tree-label { flex: 1; display: flex; align-items: center; gap: 10px; margin: 0; min-height: 44px; cursor: pointer; min-width: 0; }
        .tree-name { font-size: .92rem; white-space: nowrap; }
        .tree-link { font-size: .72rem; color: #8a94ab; background: #f3f4fa; padding: 2px 7px; border-radius: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tree-count { font-size: .72rem; font-weight: 600; color: var(--brand); background: var(--brand-soft); padding: 2px 8px; border-radius: 99px; }
        .tree-cb { margin: 0 6px 0 auto !important; width: 1.25rem; height: 1.25rem; cursor: pointer; border-color: #c4cbe0; }
        .tree-cb:checked, .tree-cb:indeterminate { background-color: var(--brand); border-color: var(--brand); }
        .tree-cb:focus { box-shadow: 0 0 0 4px rgba(79,70,229,.18); border-color: var(--brand); }
        mark { padding: 0 2px; background: #ffe58a; border-radius: 3px; }

        .tree-none { display: none; text-align: center; padding: 36px; color: var(--muted); }

        /* Footer */
        .perm-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 22px; border-top: 1px solid var(--line); background: #fafbff; }
        .perm-hint { font-size: .82rem; color: var(--muted); }
        .perm-actions { display: flex; gap: 8px; }
        .btn-brand { background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 8px 20px; font-weight: 600; box-shadow: 0 6px 16px rgba(79,70,229,.3); }
        .btn-brand:hover { background: #4338ca; color: #fff; }
        .perm-actions .btn-light { border-radius: 10px; }

        @media (max-width: 640px) {
            .tree-link { display: none; }
            .perm-copy { width: 100%; }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">
    <?= $permContent ?>
</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const STORAGE_KEY   = 'menuPermCollapsed_<?= $permTypeId ?>';
</script>
<script>
const tree = (function () {
    const form = document.getElementById('permForm');
    if (!form) return {};

    const rows = Array.from(document.querySelectorAll('.tree-row'));
    const byId = {};
    const childMap = {};
    rows.forEach(r => {
        const id = +r.dataset.id, pid = +r.dataset.parent;
        byId[id] = {
            row: r, parent: pid,
            cb: r.querySelector('.tree-cb'),
            toggle: r.querySelector('.tree-toggle'),
            name: r.querySelector('.tree-name'),
            badge: r.querySelector('.tree-count'),
            icon: r.querySelector('.tree-icon i'),
            raw: r.querySelector('.tree-name').textContent
        };
        if (pid) (childMap[pid] = childMap[pid] || []).push(id);
    });

    const ui = {
        search: document.getElementById('treeSearch'),
        count: document.getElementById('selected-count'),
        bar: document.getElementById('selBar'),
        empty: document.getElementById('emptyNote'),
    };

    let collapsed = new Set();
    try { collapsed = new Set(JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]')); } catch (e) {}
    const persist = () => { try { localStorage.setItem(STORAGE_KEY, JSON.stringify([...collapsed])); } catch (e) {} };

    const descendants = id => (childMap[id] || []).flatMap(c => [c, ...descendants(c)]);
    const ancestors   = id => { const p = byId[id].parent; return p && byId[p] ? [p, ...ancestors(p)] : []; };
    const esc = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function refreshParentStates() {
        Object.keys(childMap).map(Number)
            .sort((a, b) => ancestors(b).length - ancestors(a).length)
            .forEach(id => {
                const d = descendants(id);
                const on = d.filter(x => byId[x].cb.checked).length;
                const cb = byId[id].cb;
                cb.checked = on > 0;
                cb.indeterminate = on > 0 && on < d.length;
                if (byId[id].badge) byId[id].badge.textContent = on + '/' + d.length;
            });
    }

    function updateCounter() {
        const total = rows.length;
        const sel = rows.filter(r => byId[+r.dataset.id].cb.checked).length;
        ui.count.textContent = sel;
        ui.bar.style.width = (total ? Math.round(sel / total * 100) : 0) + '%';
    }

    function applyVisibility() {
        const q = ui.search.value.trim().toLowerCase();
        const matchSet = new Set();

        if (q) {
            rows.forEach(r => {
                const id = +r.dataset.id;
                if (r.dataset.search.includes(q)) {
                    matchSet.add(id);
                    ancestors(id).forEach(a => matchSet.add(a));
                    descendants(id).forEach(d => matchSet.add(d));
                }
            });
        }

        let visible = 0;
        rows.forEach(r => {
            const id = +r.dataset.id, n = byId[id];
            let show = true;
            if (q && !matchSet.has(id)) show = false;
            if (show && !q) show = !ancestors(id).some(a => collapsed.has(a));

            r.classList.toggle('hidden', !show);
            r.classList.toggle('dim', !!q && !r.dataset.search.includes(q));

            const isCollapsed = collapsed.has(id) && !q;
            n.toggle.classList.toggle('collapsed', isCollapsed);
            if (childMap[id] && n.icon) n.icon.className = 'bi ' + (isCollapsed ? 'bi-folder2' : 'bi-folder2-open');

            if (q && r.dataset.search.includes(q)) {
                const i = n.raw.toLowerCase().indexOf(q);
                n.name.innerHTML = i < 0 ? esc(n.raw)
                    : esc(n.raw.slice(0, i)) + '<mark>' + esc(n.raw.slice(i, i + q.length)) + '</mark>' + esc(n.raw.slice(i + q.length));
            } else {
                n.name.textContent = n.raw;
            }
            if (show) visible++;
        });
        ui.empty.style.display = visible ? 'none' : 'block';
    }

    function toggle(id) {
        collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
        persist(); applyVisibility();
    }

    const snapshot = () => rows.map(r => byId[+r.dataset.id].cb.checked ? 1 : 0).join('');
    let baseline = '', submitting = false;
    const markDirty = () => form.classList.toggle('dirty', snapshot() !== baseline);
    window.addEventListener('beforeunload', e => {
        if (!submitting && form.classList.contains('dirty')) { e.preventDefault(); e.returnValue = ''; }
    });
    form.addEventListener('submit', () => { submitting = true; });

    rows.forEach(r => {
        const id = +r.dataset.id, n = byId[id];

        if (!n.toggle.disabled) n.toggle.addEventListener('click', () => toggle(id));

        n.cb.addEventListener('change', () => {
            const state = n.cb.checked;
            n.cb.indeterminate = false;
            descendants(id).forEach(d => byId[d].cb.checked = state);
            if (state) ancestors(id).forEach(a => byId[a].cb.checked = true);
            refreshParentStates(); updateCounter(); markDirty();
        });

        n.cb.addEventListener('keydown', e => {
            const vis = rows.filter(x => !x.classList.contains('hidden'));
            const i = vis.indexOf(r);
            if (e.key === 'ArrowDown' && vis[i + 1]) { vis[i + 1].querySelector('.tree-cb').focus(); e.preventDefault(); }
            else if (e.key === 'ArrowUp' && vis[i - 1]) { vis[i - 1].querySelector('.tree-cb').focus(); e.preventDefault(); }
            else if (e.key === 'ArrowRight' && childMap[id] && collapsed.has(id)) { toggle(id); e.preventDefault(); }
            else if (e.key === 'ArrowLeft') {
                if (childMap[id] && !collapsed.has(id)) toggle(id);
                else if (n.parent && byId[n.parent]) byId[n.parent].cb.focus();
                e.preventDefault();
            }
        });
    });

    ui.search.addEventListener('input', applyVisibility);
    document.addEventListener('keydown', e => {
        if (e.key === '/' && !/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)) {
            e.preventDefault(); ui.search.focus();
        }
        if (e.key === 'Escape' && document.activeElement === ui.search) { ui.search.value = ''; applyVisibility(); }
    });

    // Init
    refreshParentStates();
    updateCounter();
    baseline = snapshot();
    applyVisibility();

    return {
        checkAll(state) {
            const vis = rows.filter(r => !r.classList.contains('hidden'));
            vis.forEach(r => byId[+r.dataset.id].cb.checked = state);
            if (state) vis.forEach(r => ancestors(+r.dataset.id).forEach(a => byId[a].cb.checked = true));
            refreshParentStates(); updateCounter(); markDirty();
        },
        expandAll(expand) {
            collapsed = expand ? new Set() : new Set(Object.keys(childMap).map(Number));
            persist(); applyVisibility();
        }
    };
})();
</script>
</body>
</html>