<?php
session_start();

// DB Connection
$host = 'localhost';
$dbname = 'mcqdb';
$username = 'root';
$password = '';
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Result Processing
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SESSION['questions'])) {
    $answers = $_POST['answer'] ?? [];
    $questions = $_SESSION['questions'];
    $score = 0;
    $wrong = 0;
    $unanswered = 0;
    ?>

    <!DOCTYPE html>
    <html lang="bn">
    <head>
        <meta charset="UTF-8">
        <title>ফলাফল</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container my-5">
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white"><h4>আপনার ফলাফল</h4></div>
            <div class="card-body">
                <?php
                foreach ($questions as $qid) {
                    $stmt = $conn->prepare("SELECT question, correct_option, option_a, option_b, option_c, option_d, explanation, exam_reference FROM exam1 WHERE id = ?");
                    $stmt->bind_param("i", $qid);
                    $stmt->execute();
                    $stmt->bind_result($question, $correct, $a, $b, $c, $d, $explanation, $ref);
                    $stmt->fetch();
                    $stmt->close();

                    $user_ans = $answers[$qid] ?? null;

                    echo "<div class='mb-4'>";
                    echo "<h5>$question</h5>";

                    if ($user_ans === null) {
                        echo "<p class='text-warning'><strong>✘ আপনি উত্তর দেননি</strong></p>";
                        $unanswered++;
                    } else {
                        echo "<p>আপনার উত্তর: <strong>$user_ans</strong></p>";
                        if ($user_ans === $correct) {
                            echo "<p class='text-success fw-bold'>✔ সঠিক</p>";
                            $score++;
                        } else {
                            echo "<p class='text-danger fw-bold'>✘ ভুল</p>";
                            $wrong++;
                        }
                    }

                    echo "<p>সঠিক উত্তর: <strong>$correct</strong></p>";
                    if (!empty($explanation)) echo "<p><strong>ব্যাখ্যা:</strong> $explanation</p>";
                    if (!empty($ref)) echo "<p><strong>পরীক্ষার রেফারেন্স:</strong> $ref</p>";
                    echo "</div><hr>";
                }

                $total = count($questions);
                unset($_SESSION['questions']); // Clear session
                ?>

                <div class="row text-center mt-4">
                    <div class="col-md-3"><strong>মোট প
