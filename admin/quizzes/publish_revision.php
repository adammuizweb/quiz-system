<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$user = qz_admin_user($pdo);
if (!$user['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$quizId = (int)($_POST['id'] ?? 0);
if ($quizId <= 0) { http_response_code(400); exit('Missing or invalid id'); }
if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid CSRF'); }

$prefixLocked = false;
try {
  if (!quiz_public_prefix_lock($pdo, 10)) throw new RuntimeException('Public URL namespace is busy.');
  $prefixLocked = true;
  $pdo->beginTransaction();
  if (!qz_user_can($pdo, (int)$user['id'], 'plugin.quiz.quizzes.manage')) {
    throw new RuntimeException('Quiz management permission is no longer valid.');
  }
  qz_lock_quiz_for_instrument_mutation($pdo, $quizId, (int)$user['id']);
  $revision = quiz_publish_revision($pdo, $quizId, (int)$user['id'], qz_can_manage_any($pdo, (int)$user['id']));
  $pdo->commit();
  quiz_public_prefix_unlock($pdo);
  $prefixLocked = false;
  qz_redirect($base . '/?page=admin/quiz/quizzes&msg=' . urlencode(
    'Revision ' . (int)$revision['revision_number'] . ' published.'));
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if ($prefixLocked) quiz_public_prefix_unlock($pdo);
  error_log('[admin/quiz/quizzes/publish_revision] ' . $error->getMessage());
  qz_redirect($base . '/?page=admin/quiz/quizzes&err=' . urlencode('Could not publish the Quiz revision.'));
}
