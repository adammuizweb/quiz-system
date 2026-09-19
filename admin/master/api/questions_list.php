<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) jfail('Missing quiz_id', 400);
try {
  $quiz = require_quiz_exists($quiz_id);
  $stmt = $pdo->prepare("
    SELECT q.id, q.created_by, q.question_text, q.question_type, q.is_active, q.sort_order,
           q.image_media_id, q.file_id, q.youtube_url,
           q.passage_id, q.passage_seq, p.title AS passage_title,
           q.category_id, cq.name AS question_category_name,
           p.category_id AS passage_category_id, cp.name AS passage_category_name,
           (SELECT COUNT(*) FROM question_answers a WHERE a.question_id=q.id AND a.is_active=1) AS answer_count
    FROM questions q
    LEFT JOIN question_passages p ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
    LEFT JOIN question_categories cp ON cp.id = p.category_id AND cp.quiz_id = q.quiz_id
    LEFT JOIN question_categories cq ON cq.id = q.category_id AND cq.quiz_id = q.quiz_id
    WHERE q.quiz_id = :quiz_id
    ORDER BY (q.sort_order IS NULL) ASC, q.sort_order ASC, q.id ASC
  ");
  $stmt->execute([':quiz_id'=>$quiz_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r) {
    $r['created_by'] = (int)$r['created_by'];
    $r['can_mutate'] = qz_api_can_mutate($quiz, $r);
    $r['image_media_id'] = isset($r['image_media_id']) ? (int)$r['image_media_id'] : null;
    if ($r['image_media_id'] !== null && $r['image_media_id'] <= 0) $r['image_media_id'] = null;
    $r['file_id'] = isset($r['file_id']) ? (int)$r['file_id'] : null;
    if ($r['file_id'] !== null && $r['file_id'] <= 0) $r['file_id'] = null;
    $r['passage_id'] = isset($r['passage_id']) ? (int)$r['passage_id'] : null;
    if ($r['passage_id'] !== null && $r['passage_id'] <= 0) $r['passage_id'] = null;
    $r['passage_seq'] = isset($r['passage_seq']) ? (int)$r['passage_seq'] : null;
    if ($r['passage_seq'] !== null && $r['passage_seq'] <= 0) $r['passage_seq'] = null;
    if ($r['passage_id'] === null) $r['passage_seq'] = null;
    $r['category_id'] = isset($r['category_id']) ? (int)$r['category_id'] : null;
    if ($r['category_id'] !== null && $r['category_id'] <= 0) $r['category_id'] = null;
    $r['passage_category_id'] = isset($r['passage_category_id']) ? (int)$r['passage_category_id'] : null;
    if ($r['passage_category_id'] !== null && $r['passage_category_id'] <= 0) $r['passage_category_id'] = null;
    $r['youtube_url']   = (string)($r['youtube_url'] ?? '');
    $r['passage_title'] = (string)($r['passage_title'] ?? '');
    $r['passage_category_name']  = (string)($r['passage_category_name'] ?? '');
    $r['question_category_name'] = (string)($r['question_category_name'] ?? '');
    $r['answer_count']  = (int)($r['answer_count'] ?? 0);
  }
  unset($r);
  jok(['questions'=>$rows, 'order_revision' => order_revision($rows)]);
} catch (Throwable $e) {
  jfail('DB error: '.$e->getMessage(), 500);
}
