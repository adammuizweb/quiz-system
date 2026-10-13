<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$id = (int)($_POST['id'] ?? 0);
$quiz_id = (int)($_POST['quiz_id'] ?? 0);
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($id <= 0 || $quiz_id <= 0) { http_response_code(400); exit('Missing category ID or quiz_id.'); }
if (!csrf_check($csrf)) { http_response_code(403); exit('The CSRF token is invalid.'); }
$quiz = qz_require_quiz_access($pdo, $quiz_id);
qz_require_category_access($pdo, $quiz_id, $id);

$stmt = $pdo->prepare("SELECT c.id, c.name, q.title AS quiz_title FROM question_categories c JOIN quizzes q ON q.id=c.quiz_id WHERE c.id=:id AND c.quiz_id=:qid LIMIT 1");
$stmt->execute([':id'=>$id,':qid'=>$quiz_id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Category not found, or you do not have access.'); }

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM question_passages WHERE quiz_id=:qid AND category_id=:cid");
$cntStmt->execute([':qid'=>$quiz_id,':cid'=>$id]);
$passCount = (int)($cntStmt->fetchColumn() ?: 0);

try {
  $user = qz_admin_user($pdo);
  $userId = (int)$user['id'];
  $pdo->beginTransaction();
  qz_lock_category_for_mutation($pdo, $quiz_id, $id, $userId);
  $ownerStmt = $pdo->prepare('SELECT created_by FROM quizzes WHERE id=:id LIMIT 1');
  $ownerStmt->execute([':id' => $quiz_id]);
  $currentQuizOwner = (int)$ownerStmt->fetchColumn();
  if (!qz_can_manage_any($pdo, $userId) && $currentQuizOwner !== $userId) {
    $dependent = $pdo->prepare('SELECT created_by FROM question_passages WHERE quiz_id=:qid AND category_id=:id FOR UPDATE');
    $dependent->execute([':qid'=>$quiz_id, ':id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('The category contains a passage owned by another collaborator.');
    }
    $dependent = $pdo->prepare('SELECT q.created_by FROM questions q LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id WHERE q.quiz_id=:qid AND (q.category_id=:direct_id OR p.category_id=:passage_id) FOR UPDATE');
    $dependent->execute([':qid'=>$quiz_id, ':direct_id'=>$id, ':passage_id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('The category contains a question owned by another collaborator.');
    }
  }
  $pdo->prepare('UPDATE question_passages SET category_id=NULL WHERE quiz_id=:qid AND category_id=:id')->execute([':qid'=>$quiz_id, ':id'=>$id]);
  $pdo->prepare('UPDATE questions SET category_id=NULL WHERE quiz_id=:qid AND category_id=:id')->execute([':qid'=>$quiz_id, ':id'=>$id]);
  $del = $pdo->prepare("DELETE FROM question_categories WHERE id=:id AND quiz_id=:qid LIMIT 1");
  $del->execute([':id'=>$id,':qid'=>$quiz_id]);
  $pdo->commit();
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect("$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id&err=" . urlencode("Could not delete the category: ".$e->getMessage()));
}

$msg = "Category deleted successfully. Passages detached: {$passCount}.";
qz_redirect("$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id&msg=" . urlencode($msg));
