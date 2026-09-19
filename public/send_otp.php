<?php
// /quiz/send_otp.php (attempt-based)
// POST only
// - via=email   : send OTP attempt (1x) -> set quiz_attempts.otp_email_sent_at
// - via=whatsapp: mark request (1x)     -> set quiz_attempts.otp_wa_sent_at
//
// SECURITY (konsisten dengan verify):
// - wajib cookie quiz_cid
// - attempt harus milik student tsb (by attempt_alias)
// - wajib token cookie quiz_token_{quiz_id} + UA match attempt.token_hash + attempt.user_agent_hash
//
// NOTE:
// - OTP source of truth: quiz_attempts.email_verification_code (dibuat di confirm; kalau kosong, dibuat di sini sekali)

declare(strict_types=1);

require_once __DIR__ . '/_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

if (!qz_public_csrf_check(qz_public_csrf_from_request())) {
  http_response_code(419);
  exit('CSRF token mismatch');
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

function ua_hash(): string {
  return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function gen_otp6(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function send_mail_basic(
  string $to,
  string $subject,
  string $html,
  string $fromName='Quiz',
  string $fromEmail='no-reply@example.com'
): bool {
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/html; charset=UTF-8';
  $headers[] = 'From: '.$fromName.' <'.$fromEmail.'>';
  return @mail($to, $subject, $html, implode("\r\n", $headers));
}

$mail_from_name  = settings_get($pdo, 'quiz_email_from_name', 'Quiz') ?? 'Quiz';
$default_from_email = function_exists('quiz_default_sender_email') ? quiz_default_sender_email() : 'no-reply@example.com';
$mail_from_email = settings_get($pdo, 'quiz_email_from_address', $default_from_email) ?? $default_from_email;
$email_enabled = (settings_get($pdo, 'quiz_otp_email_enabled', '1') ?? '1') !== '0';
$whatsapp_enabled = (settings_get($pdo, 'quiz_otp_whatsapp_enabled', '0') ?? '0') !== '0';

$via = strtolower(trim((string)($_POST['via'] ?? ''))); // email|whatsapp
if (!in_array($via, ['email','whatsapp'], true)) {
  http_response_code(400);
  exit('Invalid via');
}

// ambil alias dari POST, fallback cookie quiz_attempt
$alias = trim((string)($_POST['a'] ?? ''));
if ($alias === '') $alias = trim((string)($_COOKIE['quiz_attempt'] ?? ''));
if ($alias === '' || !preg_match('/^[a-zA-Z0-9_\-]{6,80}$/', $alias)) {
  go('/quiz/start/?err=' . rawurlencode('Invalid attempt. Restart from Start.'));
}

// student cookie
$cookieName = 'quiz_cid';
$cookie_id = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
if ($cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $cookie_id)) {
  go('/quiz/register/?next=' . urlencode('/quiz/start/'));
}

try {
  // cari student_id
  $st = $pdo->prepare("SELECT id, name FROM students WHERE cookie_id=:cid LIMIT 1");
  $st->execute([':cid'=>$cookie_id]);
  $student = $st->fetch(PDO::FETCH_ASSOC);
  $sid = (int)($student['id'] ?? 0);
  if ($sid <= 0) go('/quiz/register/?next=' . urlencode('/quiz/start/'));

  $pdo->beginTransaction();

  // lock attempt row
  $st = $pdo->prepare("
SELECT id, quiz_id, student_id, status, email_verified_at,
       token_hash, user_agent_hash,
       contact_email, contact_phone,
       email_verification_code,
       otp_email_sent_at, otp_wa_sent_at,
       otp_try_count, otp_locked_at
    FROM quiz_attempts
    WHERE attempt_alias = :a AND student_id = :sid
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([':a'=>$alias, ':sid'=>$sid]);
  $A = $st->fetch(PDO::FETCH_ASSOC);

  if (!$A) {
    $pdo->rollBack();
    go('/quiz/start/?err=' . rawurlencode('Attempt not found. Restart from Start.'));
  }

  $quiz_id = (int)$A['quiz_id'];
  $status  = (string)($A['status'] ?? '');

  $methodEnabled = $via === 'email' ? $email_enabled : $whatsapp_enabled;
  if (!$methodEnabled) {
    $pdo->commit();
    go('/quiz/verify/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('This OTP request method is disabled. Please use an available method or contact admin.'));
  }

  // kalau sudah berjalan / selesai, jangan kirim OTP lagi
  if (in_array($status, ['in_progress','verified','submitted'], true) || !empty($A['email_verified_at'] ?? null)) {
    $pdo->commit();
    go('/quiz/take/?a=' . rawurlencode($alias));
  }

  // security: token cookie + UA wajib match
  $tokenCookieName = 'quiz_token_' . $quiz_id;
  $token_plain = isset($_COOKIE[$tokenCookieName]) ? (string)$_COOKIE[$tokenCookieName] : '';
  if ($token_plain === '') {
    $pdo->commit();
    go('/quiz/start/confirm/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('Token does not exist. Please try again from Confirmation.'));
  }

  $tokHash = hash('sha256', $token_plain);
  $uaH = ua_hash();

  if (
    !hash_equals((string)$A['token_hash'], $tokHash) ||
    !hash_equals((string)$A['user_agent_hash'], $uaH)
  ) {
    $pdo->commit();
    go('/quiz/start/confirm/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('Invalid access. Please use the same browser/device.'));
  }

  $MAX_TRIES = 10;
  $tries = (int)($A['otp_try_count'] ?? 0);
  $lockedAt = $A['otp_locked_at'] ?? null;

  if ($lockedAt || $tries >= $MAX_TRIES) {
    if (!$lockedAt) {
      $pdo->prepare("UPDATE quiz_attempts SET otp_locked_at = NOW() WHERE id=:id LIMIT 1")
          ->execute([':id'=>(int)$A['id']]);
    }
    $pdo->commit();
    go('/quiz/verify/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('OTP is locked. Contact admin for a reset attempt.'));
  }

  // pastikan OTP ada (sekali)
  $otp = (string)($A['email_verification_code'] ?? '');
  if (!preg_match('/^\d{6}$/', $otp)) {
    $otp = gen_otp6();
    $pdo->prepare("UPDATE quiz_attempts SET email_verification_code=:o WHERE id=:id LIMIT 1")
        ->execute([':o'=>$otp, ':id'=>(int)$A['id']]);
  }

  if ($via === 'email') {

    if (!empty($A['otp_email_sent_at'])) {
      $pdo->commit();
      go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('The OTP email has been sent. Please check your Inbox/Spam.'));
    }

    // mark sent dulu (rule 1x)
    $pdo->prepare("
      UPDATE quiz_attempts
      SET otp_email_sent_at = NOW(),
          last_activity_at = NOW()
      WHERE id = :id
      LIMIT 1
    ")->execute([':id'=>(int)$A['id']]);

    $pdo->commit();

    $to = trim((string)($A['contact_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $fallback = $whatsapp_enabled ? 'Use WhatsApp or contact admin.' : 'Contact admin.';
      go('/quiz/verify/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('Invalid email. ' . $fallback));
    }

    $name = (string)($student['name'] ?? 'Peserta');
    $subject = 'OTP Verifikasi Quiz';
    $html = '
      <div style="font-family:Arial,sans-serif;line-height:1.6;color:#0f172a">
        <h2 style="margin:0 0 10px">Quiz OTP Verification</h2>
        <p>Hello <b>'.h($name).'</b>,</p>
        <p>Your OTP code:</p>
        <div style="font-size:28px;font-weight:900;letter-spacing:.25em;padding:10px 14px;border:1px solid #e5e7eb;border-radius:12px;display:inline-block;background:#f8fafc">
          '.h($otp).'
        </div>
        <p style="margin-top:14px">Please enter this OTP on the verification page.</p>
      </div>
    ';

    $ok = send_mail_basic($to, $subject, $html, $mail_from_name, $mail_from_email);
    if (!$ok) {
      $fallback = $whatsapp_enabled ? 'Please use WhatsApp or contact admin.' : 'Please contact admin.';
      go('/quiz/verify/?quiz='.(int)$quiz_id.'&err=' . rawurlencode('Failed to send OTP email. ' . $fallback));
    }

    go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('The OTP has been sent to your email. Check your inbox/spam..'));

  } else { // whatsapp

    if (!empty($A['otp_wa_sent_at'])) {
      $pdo->commit();
      go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('WhatsApp request has already been made. Please wait admin.'));
    }

    $pdo->prepare("
      UPDATE quiz_attempts
      SET otp_wa_sent_at = NOW(),
          last_activity_at = NOW()
      WHERE id = :id
      LIMIT 1
    ")->execute([':id'=>(int)$A['id']]);

    $pdo->commit();
    go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('Your WhatsApp request has been recorded. Please wait for the admin to send you an OTP.'));
  }

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz/send_otp attempt] ' . $e->getMessage());
  go('/quiz/start/?err=' . rawurlencode('An error occurred. Please try again.'));
}
