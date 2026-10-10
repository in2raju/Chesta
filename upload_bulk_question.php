<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user       = $_SESSION['user'];
$userId     = $user['user_id'];
$brCode     = $user['br_code'];
$orgCode    = $user['org_code'];

$errors = [];
$warnings = [];
$previewRows = []; 

// Generate upload process ID for this session (per upload)
if (!isset($_SESSION['upload_process_id'])) {
    $uploadProcessId = 'UPL-' . $brCode . '-' . date('YmdHis') . '-' . rand(1000,9999);
    $_SESSION['upload_process_id'] = $uploadProcessId;
} else {
    $uploadProcessId = $_SESSION['upload_process_id'];
}

// Function to convert various date formats to YYYY-MM-DD
function convertDateFormat($date) {
    if (empty($date)) return null;
    
    if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
        return $date;
    }
    
    if (preg_match("~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~", $date, $matches)) {
        $month = $matches[1];
        $day = $matches[2];
        $year = $matches[3];
        
        if (strlen($year) == 2) {
            $year = '20' . $year;
        }
        
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }
    
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}

// Function to fix UTF-8 encoding issues
function fixUtf8String($str) {
    if (empty($str)) return '';
    
    // Remove any BOM
    if (substr($str, 0, 3) == "\xEF\xBB\xBF") {
        $str = substr($str, 3);
    }
    
    // Try to detect and fix encoding
    $encoding = mb_detect_encoding($str, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
    
    if ($encoding === false) {
        // If detection fails, try to convert from Windows-1252
        $str = mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    } elseif ($encoding !== 'UTF-8') {
        // Convert to UTF-8
        $str = mb_convert_encoding($str, 'UTF-8', $encoding);
    }
    
    // Remove any invalid UTF-8 characters
    $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    
    return $str;
}

// Function to read CSV with proper UTF-8 handling
function readCSVFile($filePath) {
    // Try different ways to read the file
    $content = file_get_contents($filePath);
    
    if ($content === false) {
        return ['error' => 'Could not read file'];
    }
    
    // Try to fix UTF-8 encoding
    $content = fixUtf8String($content);
    
    // Split into lines
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $rows = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            // Parse CSV line
            $row = str_getcsv($line);
            // Clean each field
            $row = array_map(function($field) {
                $field = trim($field);
                $field = fixUtf8String($field);
                return $field;
            }, $row);
            $rows[] = $row;
        }
    }
    
    return $rows;
}

/* ------------------------------------------------------
   CSV TEMPLATE DOWNLOAD
-------------------------------------------------------*/
if (isset($_GET['download_template'])) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=bulk_questions_template.csv");
    
    // Add UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    
    $output = fopen("php://output", "w");
    
    // CSV header
    fputcsv($output, [
        "topic_id",
        "question",
        "option_a",
        "option_b",
        "option_c",
        "option_d",
        "correct_option",
        "explanation",
        "exam_reference",
        "exam_year",
        "exam_date",
        "exam_taker",
        "remarks"
    ]);

    // Sample row with Bangla text
    fputcsv($output, [
        "1",
        "বাংলা ভাষার উদ্ভব কোন ভাষা পরিবার থেকে?",
        "ইন্দো-ইউরোপীয়",
        "দ্রাবিড়ীয়",
        "অস্ট্রো-এশিয়াটিক",
        "তিব্বতি-বর্মী",
        "A",
        "বাংলা ভাষা ইন্দো-ইউরোপীয় ভাষা পরিবারের সদস্য।",
        "বাংলা ব্যাকরণ",
        "2024",
        "2024-01-15",
        "বাংলা একাডেমি",
        "এটি একটি নমুনা প্রশ্ন"
    ]);

    fclose($output);
    exit;
}

/* ------------------------------------------------------
   UPLOAD & PREVIEW CSV
-------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && !isset($_POST['save_preview'])) {

    if ($_FILES['file']['error'] !== 0) {
        $errors[] = "File upload failed.";
    } else {
        $fileType = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        
        if (strtolower($fileType) !== 'csv') {
            $errors[] = "Only CSV files are allowed.";
        } else {
            $rows = readCSVFile($_FILES['file']['tmp_name']);
            
            if (isset($rows['error'])) {
                $errors[] = $rows['error'];
            } else if (count($rows) <= 1) {
                $errors[] = "CSV seems empty.";
            } else {
                $header = array_map('trim', $rows[0]);
                
                $minExpectedColumns = 9;
                $maxExpectedColumns = 13;
                
                if (count($header) < $minExpectedColumns) {
                    $errors[] = "CSV has " . count($header) . " columns but minimum required is $minExpectedColumns";
                    $errors[] = "Found headers: " . implode(', ', $header);
                } else {
                    
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // Skip empty rows
                        if (empty($row[1]) && empty($row[0])) continue;
                        
                        // Pad row with empty values if needed
                        while (count($row) < $maxExpectedColumns) {
                            $row[] = '';
                        }
                        
                        $topic_id = trim($row[0] ?? '');
                        $question = trim($row[1] ?? '');
                        $optA = trim($row[2] ?? '');
                        $optB = trim($row[3] ?? '');
                        $optC = trim($row[4] ?? '');
                        $optD = trim($row[5] ?? '');
                        $correct = trim($row[6] ?? '');
                        $explanation = trim($row[7] ?? '');
                        $exam_ref = trim($row[8] ?? '');
                        $exam_year = trim($row[9] ?? '');
                        $exam_date = trim($row[10] ?? '');
                        $exam_taker = trim($row[11] ?? '');
                        $remarks = trim($row[12] ?? '');
                        
                        // Fix UTF-8 for Bangla text
                        $question = fixUtf8String($question);
                        $optA = fixUtf8String($optA);
                        $optB = fixUtf8String($optB);
                        $optC = fixUtf8String($optC);
                        $optD = fixUtf8String($optD);
                        $explanation = fixUtf8String($explanation);
                        $exam_taker = fixUtf8String($exam_taker);
                        $remarks = fixUtf8String($remarks);
                        
                        if (empty($question)) {
                            $warnings[] = "Row #" . ($i+1) . " has empty question, skipped.";
                            continue;
                        }
                        
                        // Validate topic exists
                        $stmt = $pdo->prepare("SELECT id FROM topics WHERE id = ?");
                        $stmt->execute([$topic_id]);
                        if ($stmt->rowCount() == 0) {
                            $errors[] = "Row #" . ($i+1) . " → Invalid topic_id: $topic_id";
                            continue;
                        }
                        
                        // Validate correct option
                        $correct = strtoupper($correct);
                        if (!in_array($correct, ['A', 'B', 'C', 'D'])) {
                            $warnings[] = "Row #" . ($i+1) . " → Invalid correct_option: $correct. Setting to A.";
                            $correct = 'A';
                        }
                        
                        // Convert date format if needed
                        $convertedDate = null;
                        if (!empty($exam_date)) {
                            $convertedDate = convertDateFormat($exam_date);
                            if ($convertedDate === null) {
                                $warnings[] = "Row #" . ($i+1) . " → Could not parse exam_date: $exam_date";
                            }
                        }
                        
                        // Validate exam year
                        if ($exam_year != "" && !is_numeric($exam_year)) {
                            $warnings[] = "Row #" . ($i+1) . " → Invalid exam_year: $exam_year";
                            $exam_year = '';
                        }
                        
                        $previewRows[] = [
                            "topic_id" => $topic_id,
                            "question" => $question,
                            "option_a" => $optA,
                            "option_b" => $optB,
                            "option_c" => $optC,
                            "option_d" => $optD,
                            "correct_option" => $correct,
                            "explanation" => $explanation,
                            "exam_reference" => $exam_ref,
                            "exam_year" => $exam_year,
                            "exam_date" => $convertedDate,
                            "exam_taker" => $exam_taker,
                            "remarks" => $remarks,
                            "upload_process_id" => $uploadProcessId
                        ];
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------
   SAVE FINAL DATA
-------------------------------------------------------*/
if (isset($_POST['save_preview'])) {

    $data = $_POST['preview_data'] ?? [];

    if (!empty($data)) {
        try {
            $pdo->beginTransaction();

            foreach ($data as $row) {
                $stmt = $pdo->prepare("
                    INSERT INTO questions
                    (topic_id, question, option_a, option_b, option_c, option_d,
                     correct_option, explanation, exam_reference,
                     exam_year, exam_date, exam_taker, remarks,
                     BR_CODE, ORG_CODE,
                     upload_process_id, entry_user, entry_date, authorized_status)
                    VALUES
                    (:topic, :q, :a, :b, :c, :d,
                     :correct, :explanation, :exam_ref,
                     :exam_year, :exam_date, :exam_taker, :remarks,
                     :br, :org,
                     :process_id, :user, CURDATE(), 'N')
                ");

                $stmt->execute([
                    ":topic" => $row["topic_id"],
                    ":q" => $row["question"],
                    ":a" => $row["option_a"],
                    ":b" => $row["option_b"],
                    ":c" => $row["option_c"],
                    ":d" => $row["option_d"],
                    ":correct" => $row["correct_option"],
                    ":explanation" => $row["explanation"],
                    ":exam_ref" => $row["exam_reference"],
                    ":exam_year" => !empty($row["exam_year"]) ? $row["exam_year"] : null,
                    ":exam_date" => !empty($row["exam_date"]) ? $row["exam_date"] : null,
                    ":exam_taker" => $row["exam_taker"] ?? '',
                    ":remarks" => $row["remarks"] ?? '',
                    ":br" => $brCode,
                    ":org" => $orgCode,
                    ":process_id" => $row["upload_process_id"],
                    ":user" => $userId
                ]);
            }

            $pdo->commit();
            $successCount = count($data);
            unset($_SESSION['upload_process_id']);

            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1&pid=" . urlencode($uploadProcessId) . "&count=" . $successCount);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database Error: ".$e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="Content-Language" content="bn">
<title>বাল্ক প্রশ্ন আপলোড - CSV</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri&display=swap" rel="stylesheet">
<style>
    @charset "UTF-8";
    
    * {
        font-family: 'Hind Siliguri', 'Segoe UI', 'Nikosh', 'SutonnyMJ', 'Siyam Rupali', Tahoma, Geneva, Verdana, sans-serif,'Aptos Narrow';
    }
    
    body {
        font-size: 16px;
        line-height: 1.6;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .table {
        min-width: 100%;
        margin-bottom: 0;
    }
    
    .table th, .table td {
        vertical-align: middle;
        padding: 10px 8px;
        border: 1px solid #dee2e6;
    }
    
    .question-cell {
        min-width: 250px;
        max-width: 350px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    .option-cell {
        min-width: 100px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    .explanation-cell {
        min-width: 150px;
        max-width: 250px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    .remarks-cell {
        min-width: 120px;
        max-width: 150px;
        word-wrap: break-word;
        white-space: normal;
    }
    
    .action-cell {
        white-space: nowrap;
        min-width: 100px;
    }
    
    .table thead th {
        background-color: #343a40;
        color: white;
        position: sticky;
        top: 0;
        z-index: 10;
    }
</style>
</head>

<body class="d-flex flex-column min-vh-100">

<?php include "header.php"; ?>

<main class="container py-4 flex-grow-1">

<h3>📚 বাল্ক প্রশ্ন আপলোড (CSV)</h3>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">
    <b>✓ <?= htmlspecialchars($_GET['count'] ?? 0) ?> টি প্রশ্ন সফলভাবে সংরক্ষণ করা হয়েছে!</b><br>
    আপলোড প্রসেস আইডি: <b><?= htmlspecialchars($_GET['pid']) ?></b>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <b>আপলোড প্রসেস আইডি:</b> <?= htmlspecialchars($uploadProcessId) ?>
</div>

<div class="mb-3">
    <a href="?download_template=1" class="btn btn-success">📥 CSV টেমপ্লেট ডাউনলোড করুন</a>
</div>

<div class="alert alert-secondary">
    <strong>📋 CSV ফরম্যাট (১৩টি কলাম):</strong><br>
    <code>topic_id, question, option_a, option_b, option_c, option_d, correct_option, explanation, exam_reference, exam_year, exam_date, exam_taker, remarks</code>
    <br><br>
    <strong class="text-success">✅ কিভাবে সঠিক CSV তৈরি করবেন:</strong>
    <ul class="mb-0 mt-2">
        <li>ডাউনলোড করা CSV টেমপ্লেট ব্যবহার করুন</li>
        <li>Notepad++ ব্যবহার করলে: Encoding → Convert to UTF-8 নির্বাচন করুন</li>
        <li>Excel ব্যবহার করলে: File → Save As → CSV UTF-8 (Comma delimited) নির্বাচন করুন</li>
        <li>Google Sheets ব্যবহার করলে: File → Download → Comma-separated values নির্বাচন করুন</li>
        <li><strong class="text-danger">কখনোই Notepad দিয়ে CSV খুলবেন না (এনকোডিং নষ্ট হয়)</strong></li>
    </ul>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">CSV ফাইল নির্বাচন করুন</label>
                <input type="file" name="file" required class="form-control" accept=".csv">
                <small class="text-muted">UTF-8 এনকোডিং এ সংরক্ষিত CSV ফাইল আপলোড করুন</small>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button class="btn btn-primary w-100">আপলোড ও প্রিভিউ দেখুন</button>
            </div>
        </form>
    </div>
</div>

<?php if ($warnings): ?>
<div class="alert alert-warning">
    <strong>⚠️ সতর্কতা:</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($warnings as $warning): ?>
            <li><?= htmlspecialchars($warning) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <strong>❌ ত্রুটি:</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($errors as $er): ?>
            <li><?= htmlspecialchars($er) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($previewRows): ?>

<div class="mb-2">
    <b>মোট প্রিভিউ প্রশ্ন:</b> <span id="previewCount"><?= count($previewRows) ?></span>
</div>

<form method="post" id="previewForm">
    <input type="hidden" name="save_preview" value="1">
    
    <div class="card">
        <div class="card-header bg-primary text-white">
            <strong>📋 আপলোডকৃত প্রশ্নের প্রিভিউ (<?= count($previewRows) ?> টি)</strong>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Topic ID</th>
                            <th>প্রশ্ন</th>
                            <th>A</th>
                            <th>B</th>
                            <th>C</th>
                            <th>D</th>
                            <th>সঠিক</th>
                            <th>ব্যাখ্যা</th>
                            <th>Exam Ref</th>
                            <th>বছর</th>
                            <th>তারিখ</th>
                            <th>পরীক্ষার্থী</th>
                            <th>মন্তব্য</th>
                            <th>অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody id="previewTable">
                        <?php foreach ($previewRows as $idx => $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r["topic_id"]) ?></td>
                            <td class="question-cell"><?= nl2br(htmlspecialchars($r["question"])) ?></td>
                            <td class="option-cell"><?= htmlspecialchars($r["option_a"]) ?></td>
                            <td class="option-cell"><?= htmlspecialchars($r["option_b"]) ?></td>
                            <td class="option-cell"><?= htmlspecialchars($r["option_c"]) ?></td>
                            <td class="option-cell"><?= htmlspecialchars($r["option_d"]) ?></td>
                            <td class="text-center"><strong><?= htmlspecialchars($r["correct_option"]) ?></strong></td>
                            <td class="explanation-cell"><?= nl2br(htmlspecialchars($r["explanation"])) ?></td>
                            <td><?= htmlspecialchars($r["exam_reference"]) ?></td>
                            <td><?= htmlspecialchars($r["exam_year"] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r["exam_date"] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r["exam_taker"] ?: '-') ?></td>
                            <td class="remarks-cell"><?= nl2br(htmlspecialchars(substr($r["remarks"] ?? '', 0, 100))) ?></td>
                            <td class="action-cell">
                                <button type="button" class="btn btn-sm btn-danger removeRow">🗑 সরান</button>
                                <?php foreach ($r as $key => $val): ?>
                                    <input type="hidden" name="preview_data[<?= $idx ?>][<?= $key ?>]" value="<?= htmlspecialchars($val) ?>">
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-success">💾 সকল সংরক্ষণ করুন (<?= count($previewRows) ?> টি)</button>
            <button type="button" id="cancelPreview" class="btn btn-secondary">বাতিল করুন</button>
        </div>
    </div>
</form>

<?php endif; ?>

</main>

<?php include "footer.php"; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script>
$(function(){
    function updateCount() {
        var rowCount = $("#previewTable tr").length;
        $("#previewCount").text(rowCount);
        $(".card-footer .btn-success").text("সকল সংরক্ষণ করুন (" + rowCount + " টি)");
    }

    $("#previewTable").on("click", ".removeRow", function() {
        $(this).closest("tr").remove();
        updateCount();
    });

    $("#cancelPreview").click(function(){
        if(confirm("আপনি কি বাতিল করতে চান? সমস্ত প্রিভিউ ডাটা মুছে যাবে।")) {
            window.location.href = window.location.pathname;
        }
    });
});
</script>

</body>
</html>