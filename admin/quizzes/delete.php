<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$routeUser = qz_admin_user($pdo);
if (!$routeUser['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$id = (int)($_POST['id'] ?? 0);
$csrf = (string)($_POST['csrf_token'] ?? '');

if ($id <= 0) {
  http_response_code(400);
  exit('Missing or invalid id');
}
if (!csrf_check($csrf)) {
  http_response_code(403);
  exit('Invalid CSRF');
}
try {
  $pdo->beginTransaction();
  $quiz = qz_lock_quiz_for_owner_mutation($pdo, $id, (int)$routeUser['id']);
  $params = [':id' => $id];
  $title = (string)$quiz['title'];

  $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = :id LIMIT 1");
  $stmt->execute($params);
  if ($stmt->rowCount() !== 1) throw new RuntimeException('Quiz deletion did not affect one row.');
  $pdo->commit();
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[admin/quiz/quizzes/delete] ".$e->getMessage());
  qz_redirect($base . "/?page=admin/quiz/quizzes&err=" . urlencode("Gagal hapus quiz. Cek error_log."));
}

$msg = $title !== '' ? ("Quiz dihapus: {$title}") : "Quiz berhasil dihapus.";
qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode($msg));
