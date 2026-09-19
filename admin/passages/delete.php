<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$id = (int)($_POST['id'] ?? 0);
$quiz_id = (int)($_POST['quiz_id'] ?? 0);
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($id <= 0 || $quiz_id <= 0) { http_response_code(400); exit('Missing id/quiz_id'); }
if (!csrf_check($csrf)) { http_response_code(403); exit('Invalid CSRF'); }
$quiz = qz_require_quiz_access($pdo, $quiz_id);
qz_require_passage_access($pdo, $quiz_id, $id);

try {
  $user = qz_admin_user($pdo);
  $userId = (int)$user['id'];
  $pdo->beginTransaction();
  qz_lock_passage_for_mutation($pdo, $quiz_id, $id, $userId);
  $ownerStmt = $pdo->prepare('SELECT created_by FROM quizzes WHERE id=:id LIMIT 1');
  $ownerStmt->execute([':id' => $quiz_id]);
  $currentQuizOwner = (int)$ownerStmt->fetchColumn();
  if (!qz_can_manage_any($pdo, $userId) && $currentQuizOwner !== $userId) {
    $dependent = $pdo->prepare('SELECT created_by FROM questions WHERE quiz_id=:qid AND passage_id=:id FOR UPDATE');
    $dependent->execute([':qid'=>$quiz_id, ':id'=>$id]);
    foreach ($dependent->fetchAll(PDO::FETCH_COLUMN) ?: [] as $createdBy) {
      if ((int)$createdBy !== $userId) throw new RuntimeException('Passage memiliki soal milik kolaborator lain.');
    }
  }
  $u = $pdo->prepare("UPDATE questions SET passage_id=NULL,passage_seq=NULL WHERE quiz_id=:qid AND passage_id=:pid");
  $u->execute([':qid'=>$quiz_id,':pid'=>$id]);
  $d = $pdo->prepare("DELETE FROM question_passages WHERE id=:id AND quiz_id=:qid LIMIT 1");
  $d->execute([':id'=>$id,':qid'=>$quiz_id]);
  $pdo->commit();
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect("$base/?page=admin/quiz/passages/index&quiz_id=$quiz_id&err=" . urlencode("Gagal menghapus passage: ".$e->getMessage()));
}
qz_redirect("$base/?page=admin/quiz/passages/index&quiz_id=$quiz_id&msg=" . urlencode("Passage berhasil dihapus. Soal yang terhubung sudah dilepas."));
