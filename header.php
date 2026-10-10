<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }
require 'db.php';

/* ---------- Site Logo ---------- */
$siteLogo = 'logo.png';   /* ← আপনার লোগো ফাইলের নাম/পাথ */

// ---------------------------------------------------------
// 1. UPDATE LOGIC
// ---------------------------------------------------------
$userId = $_SESSION['user']['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    $userName = trim($_POST['user_name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);

    $stmt = $pdo->prepare("UPDATE user_login_info SET USER_NAME=:uname, EMAIL=:email, PHONE=:phone WHERE USER_ID=:uid");
    $stmt->execute(['uname' => $userName, 'email' => $email, 'phone' => $phone, 'uid' => $userId]);

    $_SESSION['user']['user_name'] = $userName;
    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['phone'] = $phone;
}

// ---------------------------------------------------------
// 2. FETCH DATA & MENUS
// ---------------------------------------------------------
$loginUserName = $_SESSION['user']['user_name'] ?? 'User';
$userTypeId    = $_SESSION['user']['user_type_id'] ?? '';
$orgName       = $_SESSION['user']['org_name'] ?? 'EduPortal';
$brCode        = $_SESSION['user']['br_code'] ?? 'N/A';

$sqlMenus = "SELECT m.MENU_ID, m.MENU_NAME, m.MENU_LINK, m.PARENT_ID
             FROM menu_info m
             INNER JOIN user_menu_view_permission ump ON m.MENU_ID = ump.MENU_ID
             WHERE ump.USER_TYPE_ID = :utid AND ump.CAN_VIEW = true
             ORDER BY m.MENU_ID";
$stmt = $pdo->prepare($sqlMenus);
$stmt->execute(['utid' => $userTypeId]);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$menuTree = [];
foreach ($menus as $m) {
    $parentId = $m['PARENT_ID'] ?: 0;
    $menuTree[$parentId][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($orgName) ?></title>

    <!-- Favicon — uses your logo -->
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 280px; --dark-navy: #1a1a2e; --light-navy: #252545; }
        body { background-color: #f8f9fc; margin: 0; min-height: 100vh; display: flex; }

        /* Sidebar Styles */
        #sidebar {
            width: var(--sidebar-width); height: 100vh; position: fixed;
            top: 0; left: calc(-1 * var(--sidebar-width));
            background: var(--dark-navy); display: flex; flex-direction: column;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 2000;
            padding-top: 130px; /* space below fixed navbar */
        }
        #sidebar.active { left: 0 !important; box-shadow: 10px 0 40px rgba(0,0,0,0.4); }
        #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1999; }
        #sidebar-overlay.show { display: block; }

        .sidebar-header { padding: 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.05); position: absolute; top: 0; width: 100%; }
        .sidebar-menu-wrapper { flex-grow: 1; overflow-y: auto; padding: 0 0 15px 0; }
        
        .nav-link {
            color: #a2a3b7 !important; padding: 12px 20px; margin: 2px 10px;
            border-radius: 8px; display: flex; align-items: center; text-decoration: none;
        }
        .nav-link:hover, .nav-link.active { color: #fff !important; background: var(--light-navy); }
        .nav-link i { margin-right: 15px; font-size: 1.1rem; }

        .submenu { 
            list-style: none; padding: 0; margin: 0 10px; 
            background: rgba(0,0,0,0.15); border-radius: 8px; display: none; 
        }
        .submenu .nav-link { padding-left: 50px; font-size: 0.9rem; }

        #page-content { margin-left: var(--sidebar-width); padding-top: 130px; padding-left: 20px; padding-right: 20px; }

        .top-navbar {
            background: #fff; height: 65px; padding: 0 20px;
            display: flex; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            position: fixed; top: 0; left: 0; right: 0; z-index: 1050;
        }

        .bi-chevron-down { transition: transform 0.3s; }
        .parent-toggle.open .bi-chevron-down { transform: rotate(180deg); }

        /* ============================================
           LOGO — Responsive image logo
           ============================================ */
        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
            min-width: 0;
            max-width: 100%;
        }
        .logo-img {
            height: 40px;
            width: auto;
            max-width: 140px;
            object-fit: contain;
            display: block;
            flex-shrink: 0;
            transition: transform .2s ease;
            background: transparent;
        }
        .logo-wrap:hover .logo-img { transform: scale(1.04); }

        /* Sidebar logo variant (dark background) */
        .sidebar-header .logo-img {
            height: 38px;
            max-width: 130px;
            filter: brightness(0) invert(1);   /* লোগো সাদা করে দেখাবে, চাইলে সরান */
        }
        .sidebar-header .logo-wrap { width: 100%; }

        /* Top navbar logo variant */
        .top-navbar .logo-img {
            height: 42px;
            max-width: 150px;
        }

        /* Logo text (optional — shown alongside image) */
        .logo-text {
            display: flex;
            align-items: baseline;
            font-weight: 700;
            font-size: 1.2rem;
            letter-spacing: -.01em;
            line-height: 1;
            white-space: nowrap;
            color: #fff;
        }
        .top-navbar .logo-text { color: #1a1a2e; font-size: 1.15rem; }
        .logo-text b { font-weight: 700; margin-left: 1px; color: #ffc107; }

        /* Responsive logo sizes */
        @media (max-width: 960px) {
            .logo-img { height: 36px; max-width: 120px; }
            .top-navbar .logo-img { height: 38px; max-width: 130px; }
            .sidebar-header .logo-img { height: 34px; max-width: 110px; }
            .logo-text { font-size: 1.1rem; }
        }
        @media (max-width: 720px) {
            .logo-img { height: 34px; max-width: 100px; }
            .top-navbar .logo-img { height: 36px; max-width: 110px; }
            .sidebar-header .logo-img { height: 32px; max-width: 95px; }
            .logo-text { font-size: 1rem; }
        }
        @media (max-width: 520px) {
            .logo-img { height: 32px; max-width: 85px; }
            .top-navbar .logo-img { height: 34px; max-width: 95px; }
            .logo-text { font-size: .95rem; }
        }
        @media (max-width: 380px) {
            .logo-text { display: none; }   /* very small screens: image only */
        }
    </style>
</head>
<body>

<div id="sidebar-overlay"></div>

<nav id="sidebar">
    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <!-- Sidebar Logo -->
        <a href="deshboard.php" class="logo-wrap" aria-label="<?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?>" class="logo-img">
            <span class="logo-text"><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <button class="btn text-white p-0 d-lg-none" id="close-sidebar"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="sidebar-menu-wrapper">
        <a href="deshboard.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'deshboard.php') ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <?php if (isset($menuTree[0])): foreach ($menuTree[0] as $m): ?>
            <?php if (isset($menuTree[$m['MENU_ID']])): ?>
                <div class="menu-item">
                    <a class="nav-link parent-toggle" href="javascript:void(0)">
                        <i class="bi bi-layers"></i> <?= htmlspecialchars($m['MENU_NAME']) ?>
                        <i class="bi bi-chevron-down ms-auto small"></i>
                    </a>
                    <ul class="submenu">
                        <?php foreach ($menuTree[$m['MENU_ID']] as $sub): ?>
                            <li><a href="<?= $sub['MENU_LINK'] ?>" class="nav-link"><?= htmlspecialchars($sub['MENU_NAME']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <a href="<?= $m['MENU_LINK'] ?>" class="nav-link">
                    <i class="bi bi-dot"></i> <?= htmlspecialchars($m['MENU_NAME']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; endif; ?>

        <hr class="mx-3 text-secondary opacity-25">
        
        <a href="profile.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'profile.php') ? 'active' : '' ?>">
            <i class="bi bi-person-gear"></i> My Settings
        </a>
        <a href="logout.php" class="nav-link text-danger">
            <i class="bi bi-power"></i> Logout
        </a>
    </div>

    <div class="p-3 bg-black bg-opacity-25 mt-auto border-top border-secondary">
        <div class="d-flex align-items-center">
            <img src="https://ui-avatars.com/api/?name=<?= urlencode($loginUserName) ?>&background=random" class="rounded-circle me-2" width="35">
            <div class="overflow-hidden">
                <p class="text-white mb-0 small fw-bold text-truncate"><?= htmlspecialchars($loginUserName) ?></p>
                <p class="text-muted mb-0" style="font-size: 10px;">Branch: <?= htmlspecialchars($brCode) ?></p>
            </div>
        </div>
    </div>
</nav>

<!-- Fixed Top Navbar -->
<header class="top-navbar">
    <button class="btn btn-light border shadow-sm me-3" id="mobile-toggle">
        <i class="bi bi-list fs-4"></i>
    </button>

    <!-- Top Navbar Logo -->
    <a href="index_user.php" class="logo-wrap me-auto" aria-label="<?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?>">
        <img src="<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?>" class="logo-img">
        <div class="d-flex flex-column" style="line-height:1.1;">
            <span class="fw-bold text-dark"><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></span>
            <small class="text-muted" style="font-size: 11px;">Career & Skill Hub</small>
        </div>
    </a>
</header>

<!-- PAGE CONTENT -->
<div id="page-content">
    <!-- Your page content goes here -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const mobileToggle = document.getElementById('mobile-toggle');
    const closeSidebar = document.getElementById('close-sidebar');

    function toggleSidebar() {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('show');
    }

    mobileToggle.addEventListener('click', toggleSidebar);
    closeSidebar.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', toggleSidebar);

    // Sidebar Dropdowns
    document.querySelectorAll('.parent-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const submenu = this.nextElementSibling;
            const isOpen = submenu.style.display === 'block';
            
            document.querySelectorAll('.submenu').forEach(s => s.style.display = 'none');
            document.querySelectorAll('.parent-toggle').forEach(p => p.classList.remove('open'));

            if (!isOpen) {
                submenu.style.display = 'block';
                this.classList.add('open');
            }
        });
    });
</script>
</body>
</html>