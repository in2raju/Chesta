<?php
session_start();

// ডাটাবেস কানেকশন
$host = 'localhost';
$dbname = 'edu360';    // আপনার ডাটাবেস নাম
$username = 'root';    // ইউজারনেম
$password = '';        // পাসওয়ার্ড

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// পুনরায় চেষ্টা করার জন্য সেশন মুছে ফেলা
if (isset($_POST['retry'])) {
    unset($_SESSION['questions']);
    header("Location: " . basename(__FILE__));
    exit;
}

// যখন পরীক্ষার উত্তর সাবমিট হয়
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $user_answers = $_POST['answers'] ?? [];
    $questions = $_SESSION['questions'] ?? [];

    $correct = $incorrect = $unanswered = 0;
    $results = [];

    foreach ($questions as $index => $q) {
        $qid = $q['id'];
        $correct_option = strtoupper(trim($q['correct_option']));
        $given_answer = isset($user_answers[$qid]) ? strtoupper(trim($user_answers[$qid])) : '';

        if ($given_answer === '') {
            $unanswered++;
        } elseif ($given_answer === $correct_option) {
            $correct++;
        } else {
            $incorrect++;
        }

        $results[] = [
            'question' => $q['question'],
            'options' => [
                'A' => $q['option_a'],
                'B' => $q['option_b'],
                'C' => $q['option_c'],
                'D' => $q['option_d'],
            ],
            'correct_option' => $correct_option,
            'given_answer' => $given_answer,
            'explanation' => $q['explanation']
        ];
    }

    // ফলাফল দেখানো
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
            <div class="card-header bg-success text-white">
                <h3>আপনার ফলাফল</h3>
            </div>
            <div class="card-body">
                <p>মোট প্রশ্ন: <?= count($questions) ?></p>
                <p class="text-success">সঠিক উত্তর: <?= $correct ?></p>
                <p class="text-danger">ভুল উত্তর: <?= $incorrect ?></p>
                <p class="text-warning">উত্তর দেননি: <?= $unanswered ?></p>
                <h4>আপনি পেয়েছেন: <?= $correct ?> / <?= count($questions) ?></h4>
                <hr>
                <?php foreach ($results as $index => $res): ?>
                    <div class="mb-4">
                        <h5><?= ($index + 1) . ". " . htmlspecialchars($res['question']) ?></h5>
                        <?php foreach ($res['options'] as $key => $opt): 
                            $status = '';
                            if ($res['given_answer'] === $key && $key !== $res['correct_option']) {
                                $status = "<span class='text-danger'> ❌ (আপনার উত্তর)</span>";
                            } elseif ($key === $res['correct_option']) {
                                $status = "<span class='text-success'> ✅ (সঠিক উত্তর)</span>";
                            }
                        ?>
                            <p><?= $key ?>) <?= htmlspecialchars($opt) ?> <?= $status ?></p>
                        <?php endforeach; ?>

                        <?php if (!empty($res['explanation'])): ?>
                            <p><em>ব্যাখ্যা:</em> <?= htmlspecialchars($res['explanation']) ?></p>
                        <?php endif; ?>
                    </div>
                    <hr>
                <?php endforeach; ?>

                <form method="post" class="text-center mt-4">
                    <button type="submit" name="retry" class="btn btn-primary">🔄 পুনরায় চেষ্টা করুন</button>
                </form>
            </div>
        </div>
    </div>



    </body>
    </html>
    <?php
    // পরীক্ষার পরে সেশন মুছে ফেলুন
    unset($_SESSION['questions']);
    exit();
}

// প্রশ্ন লোড করা (প্রথমবার)
if (!isset($_SESSION['questions'])) {
    $query = "SELECT * FROM exam1 ORDER BY RAND()";
    $result = $conn->query($query);
    $questions = [];
    while ($row = $result->fetch_assoc()) {
        $questions[] = $row;
    }
    $_SESSION['questions'] = $questions;
}

// প্রশ্ন দেখানো
$questions = $_SESSION['questions'];
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>MCQ পরীক্ষা</title>
<script>
  document.addEventListener("contextmenu", function (e) {
    e.preventDefault();
  });
</script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-5">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h3>MCQ পরীক্ষা</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?php foreach ($questions as $index => $q): ?>
                    <fieldset class="mb-4">
                        <legend><?= ($index + 1) . ". " . htmlspecialchars($q['question']) ?></legend>
                        <?php foreach (['A','B','C','D'] as $opt): 
                            $option_text = $q["option_" . strtolower($opt)];
                            if ($option_text):
                        ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" 
                                       name="answers[<?= $q['id'] ?>]" id="q<?= $q['id'] ?><?= $opt ?>" 
                                       value="<?= $opt ?>" >
                                <label class="form-check-label" for="q<?= $q['id'] ?><?= $opt ?>">
                                    <?= $opt ?>) <?= htmlspecialchars($option_text) ?>
                                </label>
                            </div>
                        <?php endif; endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
                <button type="submit" name="submit_exam" class="btn btn-success">✅ সাবমিট করুন</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
