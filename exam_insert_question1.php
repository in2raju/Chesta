<?php
require 'db.php';

// Insert question logic
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $topic_id = $_POST['topic_id'];
    $question = $_POST['question'];
    $option_a = $_POST['option_a'];
    $option_b = $_POST['option_b'];
    $option_c = $_POST['option_c'];
    $option_d = $_POST['option_d'];
    $correct_option = $_POST['correct_option'];
    $explanation = $_POST['explanation'];

    $stmt = $pdo->prepare("INSERT INTO questions (topic_id, question, option_a, option_b, option_c, option_d, correct_option, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$topic_id, $question, $option_a, $option_b, $option_c, $option_d, $correct_option, $explanation]);
    $success = "Question inserted successfully!";
}

// Fetch all data once
$levels = $pdo->query("SELECT id, name FROM levels")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT id, name, level_id FROM subjects")->fetchAll(PDO::FETCH_ASSOC);
$chapters = $pdo->query("SELECT id, name, subject_id FROM chapters")->fetchAll(PDO::FETCH_ASSOC);
$topics = $pdo->query("SELECT id, name, chapter_id FROM topics")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Question - Optimized</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="container mt-4">
    <h3>Add New Question</h3>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label for="level" class="form-label">Select Level</label>
            <select class="form-select" id="level" required>
                <option value="">Select Level</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="subject" class="form-label">Select Subject</label>
            <select class="form-select" id="subject" required></select>
        </div>

        <div class="mb-3">
            <label for="chapter" class="form-label">Select Chapter</label>
            <select class="form-select" id="chapter" required></select>
        </div>

        <div class="mb-3">
            <label for="topic" class="form-label">Select Topic</label>
            <select class="form-select" name="topic_id" id="topic" required></select>
        </div>

        <!-- Question and options -->
        <div class="mb-3">
            <label class="form-label">Question</label>
            <textarea class="form-control" name="question" rows="3" required></textarea>
        </div>

        <div class="mb-3"><label class="form-label">Option A</label><input type="text" class="form-control" name="option_a" required></div>
        <div class="mb-3"><label class="form-label">Option B</label><input type="text" class="form-control" name="option_b" required></div>
        <div class="mb-3"><label class="form-label">Option C</label><input type="text" class="form-control" name="option_c" required></div>
        <div class="mb-3"><label class="form-label">Option D</label><input type="text" class="form-control" name="option_d" required></div>

        <div class="mb-3">
            <label class="form-label">Correct Option</label>
            <select class="form-select" name="correct_option" required>
                <option value="">Select</option>
                <option value="A">A</option>
                <option value="B">B</option>
                <option value="C">C</option>
                <option value="D">D</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Explanation (optional)</label>
            <textarea class="form-control" name="explanation" rows="2"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Add Question</button>
    </form>

<script>
    // All data loaded at once from PHP
    const subjects = <?= json_encode($subjects) ?>;
    const chapters = <?= json_encode($chapters) ?>;
    const topics = <?= json_encode($topics) ?>;

    const levelSelect = document.getElementById('level');
    const subjectSelect = document.getElementById('subject');
    const chapterSelect = document.getElementById('chapter');
    const topicSelect = document.getElementById('topic');

    function resetSelect(selectElem, placeholder) {
        selectElem.innerHTML = `<option value="">${placeholder}</option>`;
    }

    function populateSubjects(levelId) {
        resetSelect(subjectSelect, "Select Subject");
        resetSelect(chapterSelect, "Select Chapter");
        resetSelect(topicSelect, "Select Topic");

        if (!levelId) return;

        subjects.forEach(subj => {
            if (subj.level_id == levelId) {
                const opt = document.createElement('option');
                opt.value = subj.id;
                opt.textContent = subj.name;
                subjectSelect.appendChild(opt);
            }
        });
    }

    function populateChapters(subjectId) {
        resetSelect(chapterSelect, "Select Chapter");
        resetSelect(topicSelect, "Select Topic");

        if (!subjectId) return;

        chapters.forEach(chap => {
            if (chap.subject_id == subjectId) {
                const opt = document.createElement('option');
                opt.value = chap.id;
                opt.textContent = chap.name;
                chapterSelect.appendChild(opt);
            }
        });
    }

    function populateTopics(chapterId) {
        resetSelect(topicSelect, "Select Topic");

        if (!chapterId) return;

        topics.forEach(top => {
            if (top.chapter_id == chapterId) {
                const opt = document.createElement('option');
                opt.value = top.id;
                opt.textContent = top.name;
                topicSelect.appendChild(opt);
            }
        });
    }

    levelSelect.addEventListener('change', () => populateSubjects(levelSelect.value));
    subjectSelect.addEventListener('change', () => populateChapters(subjectSelect.value));
    chapterSelect.addEventListener('change', () => populateTopics(chapterSelect.value));
</script>

</body>
</html>
