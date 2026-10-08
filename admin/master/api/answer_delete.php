<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$qid     = (int)($in['question_id'] ?? 0);
$id      = (int)($in['id'] ?? 0);
if ($quiz_id<=0 || $qid<=0 || $id<=0) jfail('Missing quiz_id/question_id/id', 400);
require_quiz_exists($quiz_id);
require_question_in_quiz($quiz_id, $qid);
try {
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
  qz_lock_answer_for_mutation($pdo, $quiz_id, $qid, $id, (int)$user['id']);
  qz_assert_answer_not_used_by_attempt($pdo, $qid, $id);
  $stmt = $pdo->prepare("DELETE FROM question_answers WHERE id=:id AND question_id=:qid LIMIT 1");
  $stmt->execute([':id'=>$id, ':qid'=>$qid]);
  $pdo->commit();
  jok();
} catch (QuizAssignedContentException $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($error->getMessage(), 409, ['code' => 'assigned_content_locked']);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: ' . $error->getMessage(), 500);
}
