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

$otp = (string)random_int(100000, 999999);

try {
  $pdo->beginTransaction();
  $lockedAttempt = qz_lock_attempt_for_mutation($pdo, $attempt_id, (int)qz_admin_user($pdo)['id'], 'plugin.quiz.attempts.operate');
  if ((string)($lockedAttempt['status'] ?? '') !== 'registered' || !empty($lockedAttempt['email_verified_at'])) {
    throw new RuntimeException('OTP can only be rotated for an unverified registered attempt.');
  }
  $stmt = $pdo->prepare("
    UPDATE quiz_attempts
    SET email_verification_code = :otp,
        email_verified_at = NULL,
        verified_via = NULL,
        verified_by = NULL,
        verified_note = NULL,
        otp_email_sent_at = NULL,
        otp_wa_sent_at = NULL,
        otp_try_count = 0,
        otp_locked_at = NULL,
        last_activity_at = NOW()
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([':otp' => $otp, ':id' => $attempt_id]);
  $pdo->commit();
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(403); exit('You are no longer authorized to modify this Attempt.');
}

$loc = $base . '/?page=admin/quiz/attempts/view&attempt_id=' . $attempt_id;
qz_flash_set('msg', 'New OTP generated for the Registered Attempt.');

qz_redirect($loc);
