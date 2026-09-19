<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) jfail('Missing quiz_id', 400);
try {
  $quiz = require_quiz_exists($quiz_id);
  $stmt = $pdo->prepare("
     SELECT c.id, c.quiz_id, c.created_by, c.name, c.description,
           c.shuffle_passages, c.shuffle_questions, c.max_questions, c.min_questions,
           c.sort_order, c.is_active, c.created_at, c.updated_at,
           (SELECT COUNT(*) FROM question_passages p WHERE p.quiz_id=c.quiz_id AND p.category_id=c.id) AS passage_count,
           (SELECT COUNT(*) FROM question_passages p WHERE p.quiz_id=c.quiz_id AND p.category_id=c.id AND p.is_active=1) AS passage_count_active,
           (SELECT COUNT(*) FROM questions q WHERE q.quiz_id=c.quiz_id AND q.category_id=c.id AND (q.passage_id IS NULL OR q.passage_id=0)) AS direct_question_count,
           (SELECT COUNT(*) FROM questions q WHERE q.quiz_id=c.quiz_id AND q.category_id=c.id AND (q.passage_id IS NULL OR q.passage_id=0) AND q.is_active=1) AS direct_question_count_active
    FROM question_categories c WHERE c.quiz_id=:quiz_id ORDER BY (c.sort_order IS NULL) ASC, c.sort_order ASC, c.id ASC
  ");
  $stmt->execute([':quiz_id'=>$quiz_id]);
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($items as &$c) {
    $c['id'] = (int)$c['id']; $c['quiz_id'] = (int)$c['quiz_id'];
    $c['created_by'] = (int)$c['created_by'];
    $c['can_mutate'] = qz_api_can_mutate($quiz, $c);
    $c['shuffle_passages'] = (int)($c['shuffle_passages'] ?? 1);
    $c['shuffle_questions'] = (int)($c['shuffle_questions'] ?? 1);
    $c['max_questions'] = isset($c['max_questions']) ? (int)$c['max_questions'] : null;
    if ($c['max_questions'] !== null && $c['max_questions'] <= 0) $c['max_questions'] = null;
    $c['min_questions'] = (int)($c['min_questions'] ?? 0);
    if ($c['min_questions'] < 0) $c['min_questions'] = 0;
    $c['sort_order'] = isset($c['sort_order']) ? (int)$c['sort_order'] : null;
    $c['is_active'] = (int)($c['is_active'] ?? 1);
    $c['name'] = (string)($c['name'] ?? '');
    $c['description'] = (string)($c['description'] ?? '');
    $c['passage_count'] = (int)($c['passage_count'] ?? 0);
    $c['passage_count_active'] = (int)($c['passage_count_active'] ?? 0);
    $c['direct_question_count'] = (int)($c['direct_question_count'] ?? 0);
    $c['direct_question_count_active'] = (int)($c['direct_question_count_active'] ?? 0);
  }
  unset($c);
  jok(['categories' => $items, 'order_revision' => order_revision($items)]);
} catch (Throwable $e) {
  jfail('DB error: '.$e->getMessage(), 500);
}
