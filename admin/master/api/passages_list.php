<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) jfail('Missing quiz_id', 400);
$quiz = require_quiz_exists($quiz_id);
$stmt = $pdo->prepare("
  SELECT p.id, p.quiz_id, p.created_by, p.category_id, c.name AS category_name,
         p.title, p.passage_text, p.image_media_id, p.file_id, p.youtube_url,
         p.shuffle_questions, p.min_questions, p.max_questions, p.sort_order, p.is_active,
         p.created_at, p.updated_at
  FROM question_passages p
  LEFT JOIN question_categories c ON c.id = p.category_id AND c.quiz_id = p.quiz_id
  WHERE p.quiz_id=:quiz_id ORDER BY (p.sort_order IS NULL) ASC, p.sort_order ASC, p.id ASC
");
$stmt->execute([':quiz_id'=>$quiz_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($items as &$p) {
  $p['id'] = (int)$p['id']; $p['quiz_id'] = (int)$p['quiz_id'];
  $p['created_by'] = (int)$p['created_by'];
  $p['can_mutate'] = qz_api_can_mutate($quiz, $p);
  $p['category_id'] = isset($p['category_id']) ? (int)$p['category_id'] : null;
  if ($p['category_id'] !== null && $p['category_id'] <= 0) $p['category_id'] = null;
  $p['category_name'] = (string)($p['category_name'] ?? '');
  $p['shuffle_questions'] = (int)($p['shuffle_questions'] ?? 1);
  $p['min_questions'] = isset($p['min_questions']) ? (int)$p['min_questions'] : 0;
  if ($p['min_questions'] < 0) $p['min_questions'] = 0;
  $p['max_questions'] = isset($p['max_questions']) ? (int)$p['max_questions'] : null;
  if ($p['max_questions'] !== null && $p['max_questions'] <= 0) $p['max_questions'] = null;
  $p['sort_order'] = isset($p['sort_order']) ? (int)$p['sort_order'] : null;
  $p['is_active'] = (int)($p['is_active'] ?? 1);
  $p['title'] = (string)($p['title'] ?? ''); $p['passage_text'] = (string)($p['passage_text'] ?? '');
  $p['youtube_url'] = (string)($p['youtube_url'] ?? '');
}
unset($p);
jok(['passages' => $items, 'order_revision' => order_revision($items)]);
