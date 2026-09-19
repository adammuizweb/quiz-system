<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
$qid     = (int)($_GET['question_id'] ?? 0);
if ($quiz_id <= 0 || $qid <= 0) jfail('Missing quiz_id/question_id', 400);
try {
  $quiz = require_quiz_exists($quiz_id);
  require_question_in_quiz($quiz_id, $qid);
  $stmt = $pdo->prepare("
    SELECT q.id, q.quiz_id, q.created_by, q.passage_id, q.passage_seq,
           q.category_id, cq.name AS question_category_name,
           q.question_text, q.question_type, q.is_active, q.sort_order,
           q.image_media_id, q.file_id, q.youtube_url,
           m.url AS image_url,
           p.title AS passage_title,
           p.category_id AS passage_category_id,
           cp.name AS passage_category_name
    FROM questions q
    LEFT JOIN media m ON m.id = q.image_media_id AND m.visibility='public' AND m.storage_disk='public' AND m.access_scope='public'
    LEFT JOIN question_passages p ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
    LEFT JOIN question_categories cp ON cp.id = p.category_id AND cp.quiz_id = q.quiz_id
    LEFT JOIN question_categories cq ON cq.id = q.category_id AND cq.quiz_id = q.quiz_id
    WHERE q.id = :qid AND q.quiz_id = :quiz_id LIMIT 1
  ");
  $stmt->execute([':qid'=>$qid, ':quiz_id'=>$quiz_id]);
  $question = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$question) jfail('Not found', 404);
  $question['created_by'] = (int)$question['created_by'];
  $question['can_mutate'] = qz_api_can_mutate($quiz, $question);
  $question['image_media_id'] = isset($question['image_media_id']) ? (int)$question['image_media_id'] : null;
  if ($question['image_media_id'] !== null && $question['image_media_id'] <= 0) $question['image_media_id'] = null;
  $question['file_id'] = isset($question['file_id']) ? (int)$question['file_id'] : null;
  if ($question['file_id'] !== null && $question['file_id'] <= 0) $question['file_id'] = null;
  $question['passage_id'] = isset($question['passage_id']) ? (int)$question['passage_id'] : null;
  if ($question['passage_id'] !== null && $question['passage_id'] <= 0) $question['passage_id'] = null;
  $question['passage_seq'] = isset($question['passage_seq']) ? (int)$question['passage_seq'] : null;
  if ($question['passage_seq'] !== null && $question['passage_seq'] <= 0) $question['passage_seq'] = null;
  if ($question['passage_id'] === null) $question['passage_seq'] = null;
  $question['category_id'] = isset($question['category_id']) ? (int)$question['category_id'] : null;
  if ($question['category_id'] !== null && $question['category_id'] <= 0) $question['category_id'] = null;
  $question['passage_category_id'] = isset($question['passage_category_id']) ? (int)$question['passage_category_id'] : null;
  if ($question['passage_category_id'] !== null && $question['passage_category_id'] <= 0) $question['passage_category_id'] = null;
  $question['youtube_url']   = (string)($question['youtube_url'] ?? '');
  $question['image_url']     = (string)($question['image_url'] ?? '');
  if ($question['image_url'] === '') $question['image_media_id'] = null;
  $question['passage_title'] = (string)($question['passage_title'] ?? '');
  $question['passage_category_name']  = (string)($question['passage_category_name'] ?? '');
  $question['question_category_name'] = (string)($question['question_category_name'] ?? '');
  $question['file_label'] = '';
  if (!empty($question['file_id'])) {
    try {
      $st = $pdo->prepare("SELECT filename, title FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
      $st->execute([':id'=>(int)$question['file_id']]);
      $fr = $st->fetch(PDO::FETCH_ASSOC);
      if ($fr) {
        $label = $fr['filename'] ?? ($fr['name'] ?? ($fr['title'] ?? ($fr['original_name'] ?? '')));
        $question['file_label'] = (string)$label;
      } else $question['file_id'] = null;
    } catch (Throwable $e) {}
  }
  $stmt = $pdo->prepare("SELECT id, answer_text, score, sort_order, is_active FROM question_answers WHERE question_id=:qid ORDER BY (sort_order <= 0) ASC, sort_order ASC, id ASC");
  $stmt->execute([':qid'=>$qid]);
  $answers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($answers as &$answer) {
    $answer['id'] = (int)$answer['id'];
    $answer['score'] = (int)$answer['score'];
    $answer['sort_order'] = isset($answer['sort_order']) ? (int)$answer['sort_order'] : null;
    $answer['is_active'] = (int)$answer['is_active'];
    $answer['can_mutate'] = $question['can_mutate'];
  }
  unset($answer);
  $question['answers'] = $answers;
  $question['answer_order_revision'] = order_revision($answers);
  jok(['question'=>$question]);
} catch (Throwable $e) {
  jfail('DB error: '.$e->getMessage(), 500);
}
