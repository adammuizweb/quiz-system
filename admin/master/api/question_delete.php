<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$id      = (int)($in['id'] ?? 0);
if ($quiz_id<=0 || $id<=0) jfail('Missing quiz_id/id', 400);
require_quiz_exists($quiz_id);
require_question_in_quiz($quiz_id, $id);
try {
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_question_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
  $stmt = $pdo->prepare("DELETE FROM questions WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
  $stmt->execute([':id'=>$id, ':quiz_id'=>$quiz_id]);
  $pdo->commit();
  jok(['id'=>$id]);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: ' . $error->getMessage(), 500);
}
