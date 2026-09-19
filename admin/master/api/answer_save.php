<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$qid     = (int)($in['question_id'] ?? 0);
$id      = (int)($in['id'] ?? 0);
$answer_text = trim((string)($in['answer_text'] ?? ''));
$score = (int)($in['score'] ?? 0);
$is_active = (int)($in['is_active'] ?? 1);
if ($quiz_id<=0 || $qid<=0) jfail('Missing quiz_id/question_id', 400);
if ($answer_text === '') jfail('answer_text required', 400);
if ($score < 0 || $score > 100) jfail('Score must be 0..100', 400);
if ($is_active !== 0 && $is_active !== 1) $is_active = 1;
require_quiz_exists($quiz_id);
require_question_in_quiz($quiz_id, $qid);
try {
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_question_for_mutation($pdo, $quiz_id, $qid, (int)$user['id']);
  if ($id > 0) {
    qz_lock_answer_for_mutation($pdo, $quiz_id, $qid, $id, (int)$user['id']);
    $stmt = $pdo->prepare("UPDATE question_answers SET answer_text=:txt, score=:score, is_active=:act WHERE id=:id AND question_id=:qid LIMIT 1");
    $stmt->execute([':txt'=>$answer_text, ':score'=>$score, ':act'=>$is_active, ':id'=>$id, ':qid'=>$qid]);
    $pdo->commit();
    jok(['id'=>$id]);
  }
  $sort_order = qz_append_sort_order($pdo, 'answers', $qid);
  $stmt = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, score, sort_order, is_active) VALUES (:qid, :txt, :score, :sort, :act)");
  $stmt->execute([':qid'=>$qid, ':txt'=>$answer_text, ':score'=>$score, ':sort'=>$sort_order, ':act'=>$is_active]);
  $id = (int)$pdo->lastInsertId();
  $pdo->commit();
  jok(['id'=>$id, 'sort_order'=>$sort_order]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
