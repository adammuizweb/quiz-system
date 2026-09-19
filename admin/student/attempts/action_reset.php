<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.operate');
require __DIR__ . '/../../_init.php';

$base = ADMIN_BASE_PATH;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
quiz_csrf_check();

$attempt_id = (int)($_POST['attempt_id'] ?? 0);
$reason = trim((string)($_POST['reset_reason'] ?? ''));
if ($attempt_id <= 0) { http_response_code(400); exit('Missing attempt_id'); }
if ($reason === '') { http_response_code(400); exit('Reset reason required'); }
if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255, 'UTF-8');
qz_require_attempt_access($pdo, $attempt_id);

$admin_id = (int)($_SESSION['user_id'] ?? 0);

$pdo->beginTransaction();
try {
  qz_lock_attempt_for_mutation($pdo, $attempt_id, (int)qz_admin_user($pdo)['id'], 'plugin.quiz.attempts.operate');
  $pdo->prepare("DELETE FROM attempt_answers WHERE attempt_id=:id")->execute([':id' => $attempt_id]);
  $pdo->prepare("DELETE FROM attempt_questions WHERE attempt_id=:id")->execute([':id' => $attempt_id]);

  $otp = (string)random_int(100000, 999999);

  $stmt = $pdo->prepare("
    UPDATE quiz_attempts
    SET email_verification_code = :otp,
        email_verified_at = NULL,
        verified_via = NULL,
        verified_by = NULL,
        verified_note = NULL,
        status = 'registered',
        started_at = NULL,
        submitted_at = NULL,
        otp_email_sent_at = NULL,
        otp_wa_sent_at = NULL,
        otp_try_count = 0,
        otp_locked_at = NULL,
        last_activity_at = NOW(),
        reset_count = reset_count + 1,
        reset_at = NOW(),
        reset_by = :by,
        reset_reason = :reason,
        total_score = 0
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([
    ':otp'    => $otp,
    ':by'     => $admin_id ?: null,
    ':reason' => $reason,
    ':id'     => $attempt_id,
  ]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  $loc = $base . '/?page=admin/quiz/attempts/view&attempt_id=' . $attempt_id
       . '&err=' . rawurlencode('Reset gagal: ' . $e->getMessage());
  qz_redirect($loc);
}

$loc = $base . '/?page=admin/quiz/attempts/view&attempt_id=' . $attempt_id;
qz_flash_set('msg', 'Attempt di-reset dan OTP baru dibuat.');

qz_redirect($loc);
