<?php
session_start();
include 'db.php'; // আপনার ডাটাবেজ কানেকশন

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_exam'])) {
    $num_questions = (int)$_POST['num_questions'];
    
    $query = "SELECT * FROM exam1 ORDER BY RAND() LIMIT $num_questions";
    $result = mysqli_query($conn, $query);
    
    $_SESSION['questions'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $_SESSION['questions'][] = $row;
    }
    
    $_SESSION['start_time'] = time();
    header("Location: exam1_custom.php");
    exit;
}

// যখন পরীক্ষার উত্তর সাবমিট হয়
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $user_answers = $_POST['answers'] ?? [];
    $questions = $_SESSION['questions'];

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

    echo "<h2>ফলাফল</h2>";
    echo "<p>মোট প্রশ্ন: " . count($questions) . "</p>";
    echo "<p>সঠিক উত্তর দিয়েছেন: $correct</p>";
    echo "<p>ভুল উত্তর দিয়েছেন: $incorrect</p>";
    echo "<p>উত্তর দেননি: $unanswered</p>";
    echo "<p>আপনি পেয়েছেন: $correct / " . count($questions) . "</p><hr>";

    foreach ($results as $index => $res) {
        echo "<h4>" . ($index + 1) . ". " . $res['question'] . "</h4>";
        foreach ($res['options'] as $key => $opt) {
            $status = '';
            if ($res['given_answer'] === $key && $key !== $res['correct_option']) {
                $status = " ❌ (আপনার উত্তর)";
            } elseif ($key === $res['correct_option']) {
                $status = $res['given_answer'] === $key ? " ✅ (সঠিক উত্তর)" : " ✅ (সঠিক উত্তর)";
            }
            echo "$key) $opt $status<br>";
        }
        if (!empty($res['explanation'])) {
            echo "<em>ব্যাখ্যা:</em> " . $res['explanation'] . "<br>";
        }
        echo "<hr>";
    }

    echo '<form method="post"><button type="submit" name="retry">🔄 পুনরায় চেষ্টা করুন</button></form>';
    exit;
}

if (isset($_POST['retry'])) {
    unset($_SESSION['questions']);
    header("Location: exam1_custom.php");
    exit;
}

// প্রশ্ন এখনো সিলেক্ট করা হয়নি
if (!isset($_SESSION['questions'])) {
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MCQ পরীক্ষা (exam1_custom)</title>
</head>
<body>
    <h2>পরীক্ষা শুরু করুন</h2>
    <form method="post">
        <label for="num_questions">প্রশ্নের সংখ্যা নির্বাচন করুন:</label>
        <select name="num_questions" id="num_questions" required>
            <option value="3">3</option>
            <option value="5">5</option>
            <option value="10">10</option>
        </select>
        <button type="submit" name="start_exam">শুরু করুন</button>
    </form>
</body>
</html>
<?php
exit;
}

// প্রশ্ন দেখানোর অংশ
$questions = $_SESSION['questions'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MCQ পরীক্ষা</title>
</head>
<body>
    <h2>MCQ পরীক্ষা</h2>
    <form method="post">
        <?php foreach ($questions as $index => $q): ?>
            <fieldset style="margin-bottom: 20px;">
                <legend><?php echo ($index + 1) . ". " . $q['question']; ?></legend>
                <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                    <?php
                        $option_text = $q["option_" . strtolower($opt)];
                        if ($option_text):
                    ?>
                    <label>
                        <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="<?php echo $opt; ?>">
                        <?php echo "$opt) $option_text"; ?>
                    </label><br>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        <button type="submit" name="submit_exam">✅ সাবমিট করুন</button>
    </form>
</body>
</html>
