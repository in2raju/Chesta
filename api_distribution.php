<?php
/**
 * api_distribution.php
 * Public JSON API — returns subject-wise question distribution
 * and important topics for a given level. No login required.
 */

ini_set('display_errors', '0');
while (ob_get_level()) { ob_end_clean(); }
ob_start();
header('Content-Type: application/json; charset=utf-8');

require 'db.php';

function cleanUtf8($v) {
    if (is_array($v)) return array_map('cleanUtf8', $v);
    if (is_string($v)) {
        $v = strip_tags($v);
        return mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    }
    return $v;
}
function jsonOut($data) {
    $j = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($j === false) {
        $j = json_encode(cleanUtf8($data), JSON_UNESCAPED_UNICODE);
    }
    if ($j === false) {
        $j = json_encode(['error' => 'encode failed']);
    }
    ob_end_clean();
    echo $j;
    exit();
}

function getSubjectDistribution(PDO $pdo, int $levelId): array {
    $stmt = $pdo->prepare("
        SELECT s.id AS subject_id, s.name AS label, COUNT(q.id) AS cnt
        FROM subjects s
        LEFT JOIN chapters c  ON c.subject_id = s.id AND c.DELETE_DATE IS NULL
        LEFT JOIN topics t    ON t.chapter_id = c.id AND t.DELETE_DATE IS NULL
        LEFT JOIN questions q ON q.topic_id = t.id AND q.DELETE_DATE IS NULL
        WHERE s.level_id = ? AND s.DELETE_DATE IS NULL
        GROUP BY s.id, s.name
        ORDER BY cnt DESC, s.name ASC
    ");
    $stmt->execute([$levelId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['label'] = strip_tags($r['label']);
    unset($r);
    return [
        'subjects' => $rows,
        'total'    => (int) array_sum(array_column($rows, 'cnt')),
    ];
}

function getImportantTopics(PDO $pdo, int $subjectId, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT
            t.id AS topic_id,
            t.name AS label,
            COUNT(q.id) AS exam_freq,
            COUNT(DISTINCT q.referance_exam_id) AS ref_exam_count,
            COUNT(DISTINCT CASE
                    WHEN q.referance_exam_id IS NULL
                    THEN NULLIF(TRIM(q.exam_reference), '')
                  END) AS free_text_exam_count
        FROM topics t
        INNER JOIN chapters c ON c.id = t.chapter_id AND c.DELETE_DATE IS NULL
        INNER JOIN questions q ON q.topic_id = t.id AND q.DELETE_DATE IS NULL
        WHERE c.subject_id = ?
          AND t.DELETE_DATE IS NULL
          AND (
                q.referance_exam_id IS NOT NULL
             OR NULLIF(TRIM(q.exam_reference), '') IS NOT NULL
          )
        GROUP BY t.id, t.name
        HAVING exam_freq > 0
        ORDER BY exam_freq DESC, t.name ASC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$subjectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['label'] = strip_tags($r['label']);
        $r['exam_count'] = (int)$r['ref_exam_count'] + (int)$r['free_text_exam_count'];
        unset($r['ref_exam_count'], $r['free_text_exam_count']);
    }
    unset($r);
    return $rows;
}

try {
    $action = $_GET['action'] ?? '';

    // --- 1. Fetch full distribution for a level ---
    if ($action === 'distribution') {
        $levelId = (int)($_GET['level_id'] ?? 0);
        if ($levelId <= 0) jsonOut(['error' => 'Invalid level_id']);

        $chk = $pdo->prepare("SELECT id FROM levels WHERE id = ?");
        $chk->execute([$levelId]);
        if (!$chk->fetchColumn()) jsonOut(['error' => 'Level not found']);

        $dist = getSubjectDistribution($pdo, $levelId);

        // Also preload important topics for the top subject
        $defaultSubjectId = (int)($dist['subjects'][0]['subject_id'] ?? 0);
        $topics = $defaultSubjectId ? getImportantTopics($pdo, $defaultSubjectId) : [];

        jsonOut([
            'success'         => true,
            'subjects'        => $dist['subjects'],
            'total'           => $dist['total'],
            'default_subject' => $defaultSubjectId,
            'topics'          => $topics,
        ]);
    }

    // --- 2. Fetch important topics for one subject ---
    if ($action === 'topics') {
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        if ($subjectId <= 0) jsonOut(['error' => 'Invalid subject_id']);

        $chk = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND DELETE_DATE IS NULL");
        $chk->execute([$subjectId]);
        if (!$chk->fetchColumn()) jsonOut(['error' => 'Subject not found']);

        jsonOut(['success' => true, 'topics' => getImportantTopics($pdo, $subjectId)]);
    }

    jsonOut(['error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log("api_distribution error: " . $e->getMessage());
    jsonOut(['error' => 'Server error']);
}