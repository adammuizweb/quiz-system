<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.delete');
require __DIR__ . '/../../_init.php';

$base = ADMIN_BASE_PATH;
$fallback = $base . '/?page=admin/quiz/attempts';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  qz_redirect($fallback . '&err=' . rawurlencode('Invalid method.'));
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!function_exists('csrf_token') || !hash_equals((string)csrf_token(), $csrf)) {
  qz_redirect($fallback . '&err=' . rawurlencode('Invalid CSRF token.'));
}

$attempt_id = (int)($_POST['attempt_id'] ?? 0);
if ($attempt_id <= 0) {
  qz_redirect($fallback . '&err=' . rawurlencode('Invalid Attempt ID.'));
}
qz_require_attempt_access($pdo, $attempt_id);

$redirect = trim((string)($_POST['redirect'] ?? ''));
$dest = $fallback;
if ($redirect !== '' && strpos($redirect, $base . '/') === 0) {
  $dest = $redirect;
}

try {
  $st = $pdo->prepare("SELECT attempt_alias FROM quiz_attempts WHERE id=:id LIMIT 1");
  $st->execute([':id' => $attempt_id]);
  $alias = (string)($st->fetchColumn() ?: '');
  if ($alias === '') $alias = '#' . $attempt_id;

  $pdo->beginTransaction();
  qz_lock_attempt_for_mutation($pdo, $attempt_id, (int)qz_admin_user($pdo)['id'], 'plugin.quiz.attempts.delete');
  $pdo->prepare("DELETE FROM quiz_attempts WHERE id=:id LIMIT 1")->execute([':id' => $attempt_id]);
  $pdo->commit();

  $glue = (strpos($dest, '?') !== false) ? '&' : '?';
  qz_redirect($dest . $glue . 'msg=' . rawurlencode("Attempt {$alias} deleted."));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[admin/student/attempts/action_delete] " . $e->getMessage());
  $glue = (strpos($dest, '?') !== false) ? '&' : '?';
  qz_redirect($dest . $glue . 'err=' . rawurlencode('Could not delete the Attempt. Check error_log.'));
}
