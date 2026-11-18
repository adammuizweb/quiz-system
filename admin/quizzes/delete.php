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
  $quiz = qz_lock_quiz_for_owner_mutation($pdo, $id, (int)$routeUser['id'], false);
  $revision = quiz_assert_quiz_deletable($pdo, $id);
  $params = [':id' => $id];
  $title = (string)$quiz['title'];

  if ((string)$revision['state'] === 'current') {
    $clearCurrent = $pdo->prepare('UPDATE quiz_instruments SET current_quiz_id=NULL WHERE id=:instrument_id AND current_quiz_id=:quiz_id');
    $clearCurrent->execute([':instrument_id' => (int)$revision['instrument_id'], ':quiz_id' => $id]);
    if ($clearCurrent->rowCount() !== 1) throw new RuntimeException('Quiz instrument changed during deletion.');
    $deleteInstrument = $pdo->prepare('DELETE FROM quiz_instruments WHERE id=:instrument_id LIMIT 1');
    $deleteInstrument->execute([':instrument_id' => (int)$revision['instrument_id']]);
    if ($deleteInstrument->rowCount() !== 1) throw new RuntimeException('Quiz instrument deletion failed.');
  } else {
    $deleteRevision = $pdo->prepare("DELETE FROM quiz_instrument_revisions
      WHERE id=:revision_id AND quiz_id=:quiz_id AND state='draft' LIMIT 1");
    $deleteRevision->execute([':revision_id' => (int)$revision['id'], ':quiz_id' => $id]);
    if ($deleteRevision->rowCount() !== 1) throw new RuntimeException('Quiz draft revision deletion failed.');
  }

  $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = :id LIMIT 1");
  $stmt->execute($params);
  if ($stmt->rowCount() !== 1) throw new RuntimeException('Quiz deletion did not affect one row.');
  $pdo->commit();
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[admin/quiz/quizzes/delete] ".$e->getMessage());
  qz_redirect($base . "/?page=admin/quiz/quizzes&err=" . urlencode("Could not delete the Quiz. Check error_log."));
}

$msg = $title !== '' ? ("Quiz deleted: {$title}") : "Quiz deleted.";
qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode($msg));
