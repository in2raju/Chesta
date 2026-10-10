<?php
session_start();
require 'db.php';

// ----------------------
// Session & User Info
// ----------------------
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userId    = $_SESSION['user']['user_id'];
$brCode    = str_replace('-', '', $_SESSION['user']['br_code']); 
$orgCode   = $_SESSION['user']['org_code'];
$canInsert = $_SESSION['user']['can_insert'] ?? 0;
$canEdit   = $_SESSION['user']['can_edit'] ?? 0;
$canDelete = $_SESSION['user']['can_delete'] ?? 0;
$canApprove= $_SESSION['user']['can_approve'] ?? 0;

// Handle Post-Redirect-Get Messages
$message = $_SESSION['flash_message'] ?? "";
unset($_SESSION['flash_message']); // Clear message after displaying

$editStudent = null;

// ----------------------
// Fetch Levels (Class Dropdown)
// ----------------------
$levels = $pdo->query("SELECT id, name FROM levels ORDER BY name ASC")
              ->fetchAll(PDO::FETCH_ASSOC);

// ----------------------
// Handle Insert / Update
// ----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id            = $_POST['id'] ?? '';
    $fullName      = $_POST['full_name'];
    $fatherName    = $_POST['father_name'];
    $motherName    = $_POST['mother_name'];
    $dob           = $_POST['date_of_birth'];
    $gender        = $_POST['gender'];
    $mobile        = $_POST['mobile'];
    $email         = $_POST['email'];
    $address       = $_POST['address'];
    $classId       = $_POST['class_id'];
    $section       = $_POST['section'];
    $rollNo        = $_POST['roll_no'];
    $admissionDate = $_POST['admission_date'];
    $status        = $_POST['status'] ?? 'Active';

    if ($id) {
        // ---------------- UPDATE ----------------
        if ($canEdit) {
            $stmt = $pdo->prepare("UPDATE student SET
                full_name=:full_name, father_name=:father_name, mother_name=:mother_name,
                date_of_birth=:dob, gender=:gender, mobile=:mobile, email=:email,
                address=:address, class_id=:class_id, section=:section,
                roll_no=:roll_no, admission_date=:admission_date, status=:status,
                edit_user=:edit_user
                WHERE id=:id");

            $stmt->execute([
                'full_name'=>$fullName, 'father_name'=>$fatherName, 'mother_name'=>$motherName,
                'dob'=>$dob, 'gender'=>$gender, 'mobile'=>$mobile, 'email'=>$email,
                'address'=>$address, 'class_id'=>$classId, 'section'=>$section,
                'roll_no'=>$rollNo, 'admission_date'=>$admissionDate, 'status'=>$status,
                'edit_user'=>$userId, 'id'=>$id
            ]);

            $_SESSION['flash_message'] = "<div class='alert alert-success'>Student updated successfully!</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } else {
        // ---------------- INSERT ----------------
        if ($canInsert) {
            $year = date("Y");
            $time = date("His"); 

            $stmtSerial = $pdo->prepare("
                SELECT COUNT(*) FROM student
                WHERE YEAR(admission_date) = ? AND class_id = ? AND br_code = ?
            ");
            $stmtSerial->execute([$year, $classId, $brCode]);

            $serial = $stmtSerial->fetchColumn() + 1;
            $serialFormatted = str_pad($serial, 3, "0", STR_PAD_LEFT);
            $studentId = $brCode . $year . $time . $serialFormatted;

            $stmt = $pdo->prepare("INSERT INTO student (
                student_id, full_name, father_name, mother_name,
                date_of_birth, gender, mobile, email, address,
                class_id, section, roll_no, admission_date, status,
                entry_user, org_code, br_code, authorized_status
            ) VALUES (
                :student_id, :full_name, :father_name, :mother_name,
                :dob, :gender, :mobile, :email, :address,
                :class_id, :section, :roll_no, :admission_date, :status,
                :entry_user, :org_code, :br_code, 'N'
            )");

            $stmt->execute([
                'student_id'=>$studentId, 'full_name'=>$fullName, 'father_name'=>$fatherName, 
                'mother_name'=>$motherName, 'dob'=>$dob, 'gender'=>$gender, 'mobile'=>$mobile, 
                'email'=>$email, 'address'=>$address, 'class_id'=>$classId, 'section'=>$section, 
                'roll_no'=>$rollNo, 'admission_date'=>$admissionDate, 'status'=>$status,
                'entry_user'=>$userId, 'org_code'=>$orgCode, 'br_code'=>$brCode
            ]);

            $_SESSION['flash_message'] = "<div class='alert alert-success'>Student Registered Successfully! ID: $studentId</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// ----------------------
// Handle Edit/Delete/Approve (Remaining Logic)
// ----------------------
if (isset($_GET['edit']) && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM student WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $editStudent = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['delete']) && $canDelete) {
    $stmt = $pdo->prepare("UPDATE student SET delete_status='Yes', delete_date=NOW(), delete_user=? WHERE id=?");
    $stmt->execute([$userId, $_GET['delete']]);
    header("Location: student_entry.php");
    exit();
}

if (isset($_GET['approve']) && $canApprove) {
    $stmt = $pdo->prepare("UPDATE student SET authorized_status='Y', authorized_user=?, authorized_date=NOW() WHERE id=?");
    $stmt->execute([$userId, $_GET['approve']]);
    header("Location: student_entry.php");
    exit();
}

// Fetch Students
$stmt = $pdo->prepare("
    SELECT s.*, l.name AS class_name
    FROM student s
    LEFT JOIN levels l ON s.class_id = l.id
    WHERE s.delete_status='No' AND s.org_code=? AND s.br_code=?
    ORDER BY s.id DESC
");
$stmt->execute([$orgCode, $brCode]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include 'header.php'; ?>

<main class="container py-4 flex-grow-1">

<h3>Student Registration</h3>
<?= $message ?>

<div class="card shadow-sm mb-4">
<div class="card-body">

<form method="post" action="<?= $_SERVER['PHP_SELF'] ?>">
<input type="hidden" name="id" value="<?= $editStudent['id'] ?? '' ?>">

<div class="row">
    <div class="col-md-4 mb-3">
        <label>Full Name *</label>
        <input type="text" name="full_name" class="form-control" required value="<?= $editStudent['full_name'] ?? '' ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Mobile</label>
        <input type="text" name="mobile" class="form-control" value="<?= $editStudent['mobile'] ?? '' ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Father Name</label>
        <input type="text" name="father_name" class="form-control" value="<?= $editStudent['father_name'] ?? '' ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Mother Name</label>
        <input type="text" name="mother_name" class="form-control" value="<?= $editStudent['mother_name'] ?? '' ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Date of Birth</label>
        <input type="date" name="date_of_birth" class="form-control" value="<?= $editStudent['date_of_birth'] ?? '' ?>">
    </div>
    <div class="col-md-3 mb-3">
        <label>Gender</label>
        <select name="gender" class="form-select">
            <option value="Male" <?= (isset($editStudent['gender']) && $editStudent['gender']=='Male')?'selected':'' ?>>Male</option>
            <option value="Female" <?= (isset($editStudent['gender']) && $editStudent['gender']=='Female')?'selected':'' ?>>Female</option>
        </select>
    </div>
    <div class="col-md-3 mb-3">
        <label>Class *</label>
        <select name="class_id" class="form-select" required>
            <option value="">Select Class</option>
            <?php foreach ($levels as $level): ?>
            <option value="<?= $level['id'] ?>" <?= (isset($editStudent['class_id']) && $editStudent['class_id']==$level['id'])?'selected':'' ?>>
                <?= htmlspecialchars($level['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 mb-3">
        <label>Section</label>
        <input type="text" name="section" class="form-control" value="<?= $editStudent['section'] ?? '' ?>">
    </div>
    <div class="col-md-3 mb-3">
        <label>Roll No *</label>
        <input type="text" name="roll_no" class="form-control" required value="<?= $editStudent['roll_no'] ?? '' ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Admission Date *</label>
        <input type="date" name="admission_date" class="form-control" required value="<?= $editStudent['admission_date'] ?? date('Y-m-d') ?>">
    </div>
    <div class="col-md-4 mb-3">
        <label>Status</label>
        <select name="status" class="form-select">
            <option value="Active" <?= (isset($editStudent['status']) && $editStudent['status']=='Active')?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= (isset($editStudent['status']) && $editStudent['status']=='Inactive')?'selected':'' ?>>Inactive</option>
        </select>
    </div>
</div>

<button type="submit" class="btn btn-primary">
    <?= $editStudent ? 'Update Student' : 'Register Student' ?>
</button>
<?php if($editStudent): ?>
    <a href="student_entry.php" class="btn btn-secondary">Cancel</a>
<?php endif; ?>
</form>
</div>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>SL</th>
                <th>Student ID</th>
                <th>Name</th>
                <th>Roll</th>
                <th>Class</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php $sl=1; foreach($students as $stu): ?>
            <tr>
                <td><?= $sl++ ?></td>
                <td><?= htmlspecialchars($stu['student_id']) ?></td>
                <td><?= htmlspecialchars($stu['full_name']) ?></td>
                <td><?= htmlspecialchars($stu['roll_no']) ?></td>
                <td><?= htmlspecialchars($stu['class_name']) ?></td>
                <td>
                    <?php if($stu['authorized_status']=='Y'): ?>
                        <span class="badge bg-success">Authorized</span>
                    <?php elseif($canApprove): ?>
                        <a href="?approve=<?= $stu['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Authorize?')">Approve</a>
                    <?php endif; ?>

                    <?php if($canEdit): ?>
                        <a href="?edit=<?= $stu['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                    <?php endif; ?>

                    <?php if($canDelete): ?>
                        <a href="?delete=<?= $stu['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main>

<?php include 'footer.php'; ?>
</body>
</html>