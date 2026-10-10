<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$brCode = $_SESSION['user']['br_code'];

// AJAX: Fetch permitted menu
if (isset($_GET['fetch_permitted_menu']) && isset($_GET['user_type_id'])) {
    $userTypeId = intval($_GET['user_type_id']);

    try {
        if ($userTypeId === 1) {
            // Type 1 sees all menus
            $menus = $pdo->query("SELECT MENU_ID, MENU_NAME FROM menu_info ORDER BY MENU_NAME")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT m.MENU_ID, m.MENU_NAME 
                FROM menu_info m
                JOIN user_menu_view_permission p 
                ON m.MENU_ID = p.MENU_ID
                WHERE p.USER_TYPE_ID = :user_type_id 
                  AND p.AUTHORIZED_STATUS = 'Y' 
                  AND p.BR_CODE = :br_code
                ORDER BY m.MENU_NAME
            ");
            $stmt->execute(['user_type_id' => $userTypeId, 'br_code' => $brCode]);
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        header('Content-Type: application/json');
        echo json_encode($menus, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Fetch all user types for dropdown
$userTypes = $pdo->query("SELECT USER_TYPE_ID, USER_TYPE_NAME FROM user_type_info ORDER BY USER_TYPE_NAME")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assign Permission - Stock3600</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<main class="flex-grow-1 container py-4">
    <h3>Assign Permission by User Type</h3>

    <div class="row mb-3">
        <div class="col-md-4">
            <label>User Type</label>
            <select id="user_type_id" class="form-select">
                <option value="">Select User Type</option>
                <?php foreach ($userTypes as $type): ?>
                    <option value="<?= $type['USER_TYPE_ID'] ?>"><?= htmlspecialchars($type['USER_TYPE_NAME']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered" id="permitted_table">
            <thead class="table-dark">
                <tr>
                    <th>Menu ID</th>
                    <th>Menu Name</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="2" class="text-center">Select a User Type</td></tr>
            </tbody>
        </table>
    </div>
</main>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('user_type_id').addEventListener('change', function() {
    const userTypeId = this.value;
    const tableBody = document.querySelector('#permitted_table tbody');

    tableBody.innerHTML = '<tr><td colspan="2" class="text-center">Loading...</td></tr>';

    if (!userTypeId) {
        tableBody.innerHTML = '<tr><td colspan="2" class="text-center">Select a User Type</td></tr>';
        return;
    }

    fetch(`assign_permission.php?fetch_permitted_menu=1&user_type_id=${userTypeId}`)
        .then(res => {
            if (!res.ok) throw new Error('Server returned ' + res.status);
            return res.json();
        })
        .then(data => {
            tableBody.innerHTML = '';
            if (!data || data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="2" class="text-center">No permitted menu</td></tr>';
            } else {
                data.forEach(menu => {
                    tableBody.innerHTML += `<tr>
                        <td>${menu.MENU_ID}</td>
                        <td>${menu.MENU_NAME}</td>
                    </tr>`;
                });
            }
        })
        .catch(err => {
            console.error("AJAX Error:", err);
            tableBody.innerHTML = '<tr><td colspan="2" class="text-center text-danger">Error fetching data</td></tr>';
        });
});
</script>
</body>
</html>
