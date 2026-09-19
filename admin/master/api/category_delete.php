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
require_category_in_quiz($quiz_id, $id);
try {
  $user = qz_api_user();
  $userId = (int)$user['id'];
  $pdo->beginTransaction();
  qz_lock_category_for_mutation($pdo, $quiz_id, $id, $userId);
  $ownerStmt = $pdo->prepare('SELECT created_by FROM quizzes WHERE id=:id LIMIT 1');
  $ownerStmt->execute([':id' => $quiz_id]);
  $currentQuizOwner = (int)$ownerStmt->fetchColumn();
  if (!qz_can_manage_any($pdo, $userId) && $currentQuizOwner !== $userId) {
    $dependent = $pdo->prepare('SELECT created_by FROM question_passages WHERE quiz_id=:quiz_id AND category_id=:id FOR UPDATE');
    $dependent->execute([':quiz_id'=>$quiz_id, ':id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('Category has passages owned by another collaborator.');
    }
    $dependent = $pdo->prepare('SELECT q.created_by FROM questions q LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id WHERE q.quiz_id=:quiz_id AND (q.category_id=:direct_id OR p.category_id=:passage_id) FOR UPDATE');
    $dependent->execute([':quiz_id'=>$quiz_id, ':direct_id'=>$id, ':passage_id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('Category has questions owned by another collaborator.');
    }
  }
  $up = $pdo->prepare("UPDATE question_passages SET category_id=NULL WHERE quiz_id=:quiz_id AND category_id=:cid");
  $up->execute([':quiz_id'=>$quiz_id, ':cid'=>$id]);
  $up = $pdo->prepare("UPDATE questions SET category_id=NULL WHERE quiz_id=:quiz_id AND category_id=:cid");
  $up->execute([':quiz_id'=>$quiz_id, ':cid'=>$id]);
  $del = $pdo->prepare("DELETE FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
  $del->execute([':id'=>$id, ':quiz_id'=>$quiz_id]);
  $pdo->commit();
  jok(['id'=>$id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
}
