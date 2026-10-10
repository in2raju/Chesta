<?php
// questions_ajax.php
ob_start();                       // swallow any stray output (whitespace/BOM from db.php, warnings)
session_start();
require 'db.php';

ini_set('display_errors', '0');   // never print PHP errors into the JSON
header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200) {
    http_response_code($code);
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['user'])) {
    out(['error' => 'Not logged in'], 401);
}

$user    = $_SESSION['user'];
$orgCode = $user['org_code'] ?? '';
$brCode  = $user['br_code'] ?? '';
$action  = $_GET['action'] ?? '';

const REF_TABLE = 'referance_exams';

$selectCols = "
    q.*,
    t.name AS topic_name,
    c.name AS chapter_name,
    s.name AS subject_name,
    l.name AS level_name,
    re.r_exam_name AS reference_exam_name,
    IFNULL(q.authorized_status, 'N') AS authorized_status
";
$joins = "
    FROM questions q
    LEFT JOIN topics    t ON q.topic_id   = t.id
    LEFT JOIN chapters  c ON t.chapter_id = c.id
    LEFT JOIN subjects  s ON c.subject_id = s.id
    LEFT JOIN levels    l ON s.level_id   = l.id
    LEFT JOIN " . REF_TABLE . " re ON re.referance_exam_id = q.referance_exam_id
";

try {
    // ---------------- DETAIL ----------------
    if ($action === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) out(['error' => 'Invalid id'], 400);

        $stmt = $pdo->prepare("SELECT $selectCols $joins
                               WHERE q.id = ? AND q.org_code = ? AND q.br_code = ?
                               LIMIT 1");
        $stmt->execute([$id, $orgCode, $brCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) out(['error' => 'Not found'], 404);
        out($row);
    }

    // ---------------- LIST (pagination + search) ----------------
    if ($action === 'list') {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 25)));
        $qStr     = trim($_GET['q'] ?? '');

        $where  = "WHERE q.org_code = ? AND q.br_code = ?";
        $params = [$orgCode, $brCode];

        if ($qStr !== '') {
            $like = '%' . $qStr . '%';
            $where .= " AND (
                CAST(q.id AS CHAR) LIKE ?
                OR q.question LIKE ?
                OR t.name LIKE ?
                OR c.name LIKE ?
                OR s.name LIKE ?
                OR l.name LIKE ?
                OR q.exam_reference LIKE ?
                OR re.r_exam_name LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        // Total
        $cnt = $pdo->prepare("SELECT COUNT(*) $joins $where");
        $cnt->execute($params);
        $total      = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $pageSize;

        // Rows
        $stmt = $pdo->prepare("SELECT $selectCols $joins $where
                               ORDER BY q.id DESC
                               LIMIT $pageSize OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        out([
            'rows'        => $rows,
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => $totalPages,
        ]);
    }

    out(['error' => 'Unknown action'], 400);

} catch (Throwable $e) {
    // Visible in DevTools > Network > Response, so you can see what actually broke
    out(['error' => $e->getMessage()], 500);
}