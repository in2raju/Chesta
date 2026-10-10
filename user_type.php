<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Logged-in user info
$userId      = $_SESSION['user']['user_id'];
$brCode      = $_SESSION['user']['br_code'];
$orgCode     = $_SESSION['user']['org_code'];
$userTypeId  = $_SESSION['user']['user_type_id'];
$canInsert   = $_SESSION['user']['can_insert'] ?? 0;
$canEdit     = $_SESSION['user']['can_edit'] ?? 0;
$canDelete   = $_SESSION['user']['can_delete'] ?? 0;

$editUserType = null;

// ----------------------
// Handle Add / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $typeId   = $_POST['USER_TYPE_ID'] ?? '';
    $typeName = trim($_POST['USER_TYPE_NAME']);
    $typeCode = trim($_POST['USER_TYPE_CODE']);

    if ($typeId) {
        // Update
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE user_type_info 
                                   SET USER_TYPE_NAME = :name, 
                                       USER_TYPE_CODE = :code 
                                   WHERE USER_TYPE_ID = :id");
            $stmt->execute([
                'name' => $typeName,
                'code' => $typeCode,
                'id'   => $typeId
            ]);
        }
    } else {
        // Insert
        if ($canInsert) {
            $stmt = $pdo->prepare("INSERT INTO user_type_info (USER_TYPE_NAME, USER_TYPE_CODE) 
                                   VALUES (:name, :code)");
            $stmt->execute([
                'name' => $typeName,
                'code' => $typeCode
            ]);
        }
    }

    header("Location: user_type.php");
    exit();
}

// ----------------------
// Handle Edit Request
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM user_type_info WHERE USER_TYPE_ID = :id");
    $stmt->execute(['id' => $_GET['edit']]);
    $editUserType = $stmt->fetch();
}

// ----------------------
// Handle Delete Request
// ----------------------
if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("DELETE FROM user_type_info WHERE USER_TYPE_ID = :id");
    $stmt->execute(['id' => $_GET['delete']]);
    header("Location: user_type.php");
    exit();
}

// ----------------------
// Fetch All User Types
// ----------------------
$stmt = $pdo->query("SELECT * FROM user_type_info ORDER BY USER_TYPE_ID");
$userTypes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Type Management</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary:       #266be2;
            --primary-dark:  #1a52b8;
            --primary-light: #e8f0fd;
            --accent:        #06ce71;
            --accent-dark:   #04a85b;
            --danger:        #e63946;
            --danger-light:  #fdecea;
            --warning:       #f4a261;
            --warning-light: #fff3e6;
            --surface:       #ffffff;
            --surface-2:     #f5f7fb;
            --border:        #e3e8f0;
            --text-primary:  #0f1c36;
            --text-secondary:#5a6787;
            --text-muted:    #9aa5bf;
            --radius-sm:     8px;
            --radius-md:     14px;
            --radius-lg:     20px;
            --shadow-sm:     0 1px 4px rgba(38,107,226,.07);
            --shadow-md:     0 4px 20px rgba(38,107,226,.11);
            --shadow-lg:     0 8px 40px rgba(38,107,226,.15);
            --font-head:     'Syne', sans-serif;
            --font-body:     'DM Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-body);
            background: var(--surface-2);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Page wrapper ── */
        .page-main {
            flex: 1;
            padding: 2rem 1rem 3rem;
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        /* ── Page header ── */
        .page-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 2rem;
        }
        .page-header-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: var(--shadow-md);
        }
        .page-header-text h1 {
            font-family: var(--font-head);
            font-size: clamp(1.3rem, 4vw, 1.75rem);
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.1;
        }
        .page-header-text p {
            font-size: .85rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .card-header-strip {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header-strip .strip-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }
        .strip-icon.add  { background: var(--primary-light); color: var(--primary); }
        .strip-icon.edit { background: var(--warning-light); color: var(--warning); }
        .card-header-strip h2 {
            font-family: var(--font-head);
            font-size: 1rem;
            font-weight: 600;
        }
        .card-body-pad { padding: 1.5rem; }

        /* ── Form ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 560px) { .form-row { grid-template-columns: 1fr; } }

        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-label {
            font-size: .8rem;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .form-control {
            font-family: var(--font-body);
            font-size: .95rem;
            padding: .65rem 1rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--surface-2);
            color: var(--text-primary);
            transition: border-color .2s, box-shadow .2s, background .2s;
            width: 100%;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(38,107,226,.12);
        }

        .form-actions {
            display: flex;
            gap: .75rem;
            margin-top: 1.25rem;
            flex-wrap: wrap;
        }

        /* ── Buttons ── */
        .btn {
            font-family: var(--font-body);
            font-size: .88rem;
            font-weight: 500;
            padding: .6rem 1.3rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: transform .15s, box-shadow .15s, background .2s, opacity .2s;
            white-space: nowrap;
        }
        .btn:active { transform: scale(.97); }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), #3d82f5);
            color: #fff;
            box-shadow: 0 3px 12px rgba(38,107,226,.3);
        }
        .btn-primary:hover { box-shadow: 0 5px 18px rgba(38,107,226,.4); background: var(--primary-dark); color: #fff; }

        .btn-secondary {
            background: var(--surface-2);
            color: var(--text-secondary);
            border: 1.5px solid var(--border);
        }
        .btn-secondary:hover { background: var(--border); color: var(--text-primary); }

        .btn-warning-soft {
            background: var(--warning-light);
            color: #c47a2b;
            border: 1.5px solid #f4c28a;
        }
        .btn-warning-soft:hover { background: #fde5c2; }

        .btn-danger-soft {
            background: var(--danger-light);
            color: var(--danger);
            border: 1.5px solid #f5b8bc;
        }
        .btn-danger-soft:hover { background: #fbd5d7; }

        .btn-sm { font-size: .8rem; padding: .38rem .9rem; }

        /* ── Stats strip ── */
        .stats-strip {
            display: flex;
            gap: .75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .stat-pill {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: .35rem 1rem;
            font-size: .8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .stat-pill strong { color: var(--text-primary); }

        /* ── Table card ── */
        .table-card { margin-top: 1.5rem; }
        .table-card .card-header-strip { background: var(--surface); }

        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }
        thead tr {
            background: var(--surface-2);
            border-bottom: 2px solid var(--border);
        }
        thead th {
            padding: .85rem 1.1rem;
            text-align: left;
            font-family: var(--font-head);
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-secondary);
            white-space: nowrap;
        }
        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--primary-light); }

        tbody td {
            padding: .9rem 1.1rem;
            color: var(--text-primary);
            vertical-align: middle;
        }

        /* ID badge */
        .id-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--primary-light);
            color: var(--primary);
            font-family: var(--font-head);
            font-size: .8rem;
            font-weight: 700;
        }

        /* Type name */
        .type-name {
            font-weight: 500;
            color: var(--text-primary);
        }

        /* Code chip */
        .code-chip {
            display: inline-block;
            background: #f0f4ff;
            color: var(--primary-dark);
            border: 1px solid #c8d8f8;
            border-radius: 6px;
            padding: 2px 10px;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .04em;
            font-family: 'Courier New', monospace;
        }

        /* Actions cell */
        .action-cell {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        .empty-state i { font-size: 2.5rem; color: var(--text-muted); margin-bottom: .75rem; display: block; }
        .empty-state p { color: var(--text-secondary); font-size: .95rem; }

        /* Mobile card layout for small screens */
        @media (max-width: 640px) {
            thead { display: none; }
            tbody tr {
                display: block;
                margin-bottom: .75rem;
                border-radius: var(--radius-md);
                border: 1px solid var(--border);
                box-shadow: var(--shadow-sm);
                background: var(--surface);
            }
            tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: .65rem 1rem;
                border-bottom: 1px solid var(--border);
                font-size: .88rem;
            }
            tbody td:last-child { border-bottom: none; }
            tbody td::before {
                content: attr(data-label);
                font-size: .75rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: var(--text-muted);
                margin-right: auto;
            }
            .action-cell { justify-content: flex-end; }
        }

        /* ── Animations ── */
        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-in { animation: fadeSlideIn .35s ease both; }
        .animate-in:nth-child(2) { animation-delay: .06s; }
        .animate-in:nth-child(3) { animation-delay: .12s; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="page-main">

    <!-- Page Header -->
    <div class="page-header animate-in">
        <div class="page-header-icon">
            <i class="bi bi-people-fill"></i>
        </div>
        <div class="page-header-text">
            <h1>User Type Management</h1>
            <p>Manage roles and access types for your system</p>
        </div>
    </div>

    <!-- Stats strip -->
    <div class="stats-strip animate-in">
        <div class="stat-pill">
            <i class="bi bi-layers" style="color:var(--primary)"></i>
            Total types: <strong><?= count($userTypes) ?></strong>
        </div>
        <?php if ($canInsert): ?>
        <div class="stat-pill">
            <i class="bi bi-check-circle" style="color:var(--accent)"></i>
            <strong>Can Add</strong>
        </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <div class="stat-pill">
            <i class="bi bi-pencil" style="color:var(--warning)"></i>
            <strong>Can Edit</strong>
        </div>
        <?php endif; ?>
        <?php if ($canDelete): ?>
        <div class="stat-pill">
            <i class="bi bi-trash" style="color:var(--danger)"></i>
            <strong>Can Delete</strong>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add / Edit Form -->
    <?php if ($canInsert || $editUserType): ?>
    <div class="card animate-in" style="margin-bottom:1.5rem;">
        <div class="card-header-strip">
            <div class="strip-icon <?= $editUserType ? 'edit' : 'add' ?>">
                <i class="bi bi-<?= $editUserType ? 'pencil-square' : 'plus-lg' ?>"></i>
            </div>
            <h2><?= $editUserType ? 'Edit User Type' : 'Add New User Type' ?></h2>
        </div>
        <div class="card-body-pad">
            <form method="post">
                <input type="hidden" name="USER_TYPE_ID" value="<?= htmlspecialchars($editUserType['USER_TYPE_ID'] ?? '') ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">User Type Name</label>
                        <input type="text" name="USER_TYPE_NAME" class="form-control"
                               placeholder="e.g. Administrator"
                               required
                               value="<?= htmlspecialchars($editUserType['USER_TYPE_NAME'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">User Type Code</label>
                        <input type="text" name="USER_TYPE_CODE" class="form-control"
                               placeholder="e.g. ADM"
                               required
                               value="<?= htmlspecialchars($editUserType['USER_TYPE_CODE'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-<?= $editUserType ? 'check-lg' : 'plus-lg' ?>"></i>
                        <?= $editUserType ? 'Update Type' : 'Add Type' ?>
                    </button>
                    <?php if ($editUserType): ?>
                        <a href="user_type.php" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Table Card -->
    <div class="card table-card animate-in">
        <div class="card-header-strip">
            <div class="strip-icon add">
                <i class="bi bi-table"></i>
            </div>
            <h2>All User Types</h2>
        </div>

        <div class="table-wrap">
            <?php if (empty($userTypes)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No user types found. Add one above to get started.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type Name</th>
                        <th>Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userTypes as $ut): ?>
                    <tr>
                        <td data-label="ID">
                            <span class="id-badge"><?= htmlspecialchars($ut['USER_TYPE_ID']) ?></span>
                        </td>
                        <td data-label="Type Name">
                            <span class="type-name"><?= htmlspecialchars($ut['USER_TYPE_NAME']) ?></span>
                        </td>
                        <td data-label="Code">
                            <span class="code-chip"><?= htmlspecialchars($ut['USER_TYPE_CODE']) ?></span>
                        </td>
                        <td data-label="Actions">
                            <div class="action-cell">
                                <?php if ($canEdit): ?>
                                    <a href="?edit=<?= urlencode($ut['USER_TYPE_ID']) ?>"
                                       class="btn btn-sm btn-warning-soft">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <a href="?delete=<?= urlencode($ut['USER_TYPE_ID']) ?>"
                                       onclick="return confirm('Delete this user type?');"
                                       class="btn btn-sm btn-danger-soft">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php include 'footer.php'; ?>
</body>
</html>