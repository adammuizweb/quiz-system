<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.operate');
require __DIR__ . '/../../_init.php';

$base = ADMIN_BASE_PATH;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
quiz_csrf_check();

$attempt_id = (int)($_POST['attempt_id'] ?? 0);
if ($attempt_id <= 0) { http_response_code(400); exit('Attempt ID is required.'); }
qz_require_attempt_access($pdo, $attempt_id);

$via = trim((string)($_POST['verified_via'] ?? 'manual'));
if (!in_array($via, ['email', 'whatsapp', 'manual'], true)) $via = 'manual';

$note = trim((string)($_POST['verified_note'] ?? ''));
if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255, 'UTF-8');

$admin_id = (int)($_SESSION['user_id'] ?? 0);

try {
  $pdo->beginTransaction();
  qz_lock_attempt_for_mutation($pdo, $attempt_id, (int)qz_admin_user($pdo)['id'], 'plugin.quiz.attempts.operate');
  quiz_mark_attempt_manually_verified($pdo, $attempt_id, $via, $admin_id ?: null, $note === '' ? null : $note);
  $pdo->commit();
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(403); exit('You are no longer authorized to modify this Attempt.');
}

$loc = $base . '/?page=admin/quiz/attempts/view&attempt_id=' . $attempt_id
     . '&msg=' . rawurlencode('Attempt Verified via ' . $via . ($note ? ' - ' . $note : '') . '.');

qz_redirect($loc);
