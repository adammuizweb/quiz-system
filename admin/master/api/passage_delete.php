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
require_passage_in_quiz($quiz_id, $id);
try {
  $user = qz_api_user();
  $userId = (int)$user['id'];
  $pdo->beginTransaction();
  qz_lock_passage_for_mutation($pdo, $quiz_id, $id, $userId);
  $ownerStmt = $pdo->prepare('SELECT created_by FROM quizzes WHERE id=:id LIMIT 1');
  $ownerStmt->execute([':id' => $quiz_id]);
  $currentQuizOwner = (int)$ownerStmt->fetchColumn();
  if (!qz_can_manage_any($pdo, $userId) && $currentQuizOwner !== $userId) {
    $dependent = $pdo->prepare('SELECT created_by FROM questions WHERE quiz_id=:quiz_id AND passage_id=:id FOR UPDATE');
    $dependent->execute([':quiz_id'=>$quiz_id, ':id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('Passage has questions owned by another collaborator.');
    }
  }
  $up = $pdo->prepare('UPDATE questions SET passage_id=NULL, passage_seq=NULL WHERE quiz_id=:quiz_id AND passage_id=:id');
  $up->execute([':quiz_id'=>$quiz_id, ':id'=>$id]);
  $del = $pdo->prepare("DELETE FROM question_passages WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
  $del->execute([':id'=>$id, ':quiz_id'=>$quiz_id]);
  $pdo->commit();
  jok(['id'=>$id]);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($error->getMessage(), $error instanceof RuntimeException ? 409 : 500);
}
