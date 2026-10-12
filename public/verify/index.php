<?php
// /quiz/verify/index.php
// GET  : UI verifikasi OTP (pilih channel + input OTP)
// POST : verifikasi OTP -> set verified -> redirect /quiz/take/?a=ALIAS
//
// SECURITY:
// - wajib cookie quiz_cid
// - wajib token cookie quiz_token_{quiz_id}
// - UA hash match attempt.user_agent_hash
// - token hash match attempt.token_hash
//
// RULES:
// - max 10x percobaan OTP
// - email/wa request masing-masing max 1x (sent_at)

declare(strict_types=1);

require_once __DIR__ . '/../_security.php';

$page_title = 'Verifikasi OTP';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

function quiz_is_open(array $quiz, string $now): bool {
  if ((int)($quiz['is_active'] ?? 0) !== 1) return false;
  if (!empty($quiz['start_at']) && $now < (string)$quiz['start_at']) return false;
  if (!empty($quiz['end_at']) && $now > (string)$quiz['end_at']) return false;
  return true;
}

function normalize_phone_id(string $phone): string {
  $p = trim($phone);
  if ($p === '') return '';
  $p = preg_replace('/[^\d\+]/', '', $p);
  if (strpos($p, '+62') === 0) $p = '0' . substr($p, 3);
  elseif (strpos($p, '62') === 0) $p = '0' . substr($p, 2);
  return $p;
}

function ua_hash(): string {
  return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/* =========================
   1) Validate quiz
   ========================= */
$quiz_id = (int)($_GET['quiz'] ?? 0);
$boundQuizId = (int)(quiz_public_route_context()['quiz_id'] ?? 0);
if ($boundQuizId > 0) {
  if ($quiz_id > 0 && $quiz_id !== $boundQuizId) quiz_render_core_404();
  $quiz_id = $boundQuizId;
}
if ($quiz_id <= 0) {
  http_response_code(400);
  $content_html = '<div class="container" style="padding:40px 16px">Quiz tidak valid.</div>';
  require __DIR__ . '/../layout.php';
  exit;
}
quiz_public_require_quiz($quiz_id);
$startUrl = quiz_public_url('start');
$confirmUrl = quiz_public_url('start/confirm', ['quiz' => $quiz_id]);

$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare("
  SELECT id, title, description, is_active, start_at, end_at, questions_per_attempt
  FROM quizzes
  WHERE id=:id
  LIMIT 1
");
$stmt->execute([':id' => $quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz || !quiz_is_open($quiz, $now)) {
  http_response_code(404);
  $content_html = '<div class="container" style="padding:40px 16px">Quiz not available.</div>';
  require __DIR__ . '/../layout.php';
  exit;
}

/* =========================
   2) Cookie checks
   ========================= */
$studentCookieName = 'quiz_cid';
$student_cookie_id = isset($_COOKIE[$studentCookieName]) ? trim((string)$_COOKIE[$studentCookieName]) : '';
if ($student_cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $student_cookie_id)) {
  go(quiz_public_url('register', ['next' => $confirmUrl]));
}

$tokenCookieName = 'quiz_token_' . $quiz_id;
$token_plain = isset($_COOKIE[$tokenCookieName]) ? (string)$_COOKIE[$tokenCookieName] : '';
if ($token_plain === '') {
  // token dibuat di confirm; jangan balik ke start list
  go(quiz_public_url('start/confirm', ['quiz' => $quiz_id, 'err' => 'Token missing. Please continue from Confirmation.']));
}

$user_agent_hash = ua_hash();
$token_hash = hash('sha256', $token_plain);

/* =========================
   3) Load student + attempt
   ========================= */
$stmt = $pdo->prepare("SELECT id, name, email, phone FROM students WHERE cookie_id = :cid LIMIT 1");
$stmt->execute([':cid' => $student_cookie_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
  go(quiz_public_url('register', ['next' => $confirmUrl]));
}
$student_id = (int)$student['id'];

$stmt = $pdo->prepare("
  SELECT *
  FROM quiz_attempts
  WHERE quiz_id=:quiz_id AND student_id=:student_id
  ORDER BY id DESC
  LIMIT 1
");
$stmt->execute([':quiz_id' => $quiz_id, ':student_id' => $student_id]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$attempt) {
  go($confirmUrl);
}

if ((string)$attempt['status'] === 'submitted') {
$content_html = '
  <section class="st">
    <div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">QUIZ</div>
        <h1 class="st-h1">Quiz Already Submitted</h1>
        <div class="st-desc">You cannot retake this quiz.</div>
      </div>
      <div class="st-box ok">
        <a class="st-btn primary" href="'.h($startUrl).'">Back</a>
      </div>
    </div>
  </section>';
  require __DIR__ . '/../layout.php';
  exit;
}

// Hard security: token + UA must match attempt
if (
  !hash_equals((string)$attempt['token_hash'], $token_hash) ||
  !hash_equals((string)$attempt['user_agent_hash'], $user_agent_hash)
) {
$content_html = '
  <section class="st">
    <div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">SECURITY</div>
        <h1 class="st-h1">Invalid Access</h1>
        <div class="st-desc">Please use the same browser/device that was used to start the quiz.</div>
      </div>
      <div class="st-box warn">
        <a class="st-btn primary" href="'.h($confirmUrl).'">Back to Confirmation</a>
      </div>
    </div>
  </section>';
  require __DIR__ . '/../layout.php';
  exit;
}

$attempt_id    = (int)$attempt['id'];
$attempt_alias = trim((string)($attempt['attempt_alias'] ?? ''));

// identity source of truth (attempt snapshot)
$contact_name  = trim((string)($attempt['contact_name']  ?? ''));
$contact_email = trim((string)($attempt['contact_email'] ?? ''));
$contact_phone = normalize_phone_id(trim((string)($attempt['contact_phone'] ?? '')));

// fallback ke data student jika snapshot kosong
if ($contact_name === '')  $contact_name  = trim((string)($student['name']  ?? ''));
if ($contact_email === '') $contact_email = trim((string)($student['email'] ?? ''));
if ($contact_phone === '') $contact_phone = normalize_phone_id(trim((string)($student['phone'] ?? '')));


/* =========================
   3b) HARD GUARD: kalau sudah verified/in_progress, tidak boleh OTP lagi
   ========================= */
$st_now = (string)($attempt['status'] ?? '');
$alreadyVerified = !empty($attempt['email_verified_at']); // guard tambahan
if ($alreadyVerified || in_array($st_now, ['verified','in_progress','submitted'], true)) {
  // pastikan cookie alias ada
  if ($attempt_alias !== '') {
    setcookie('quiz_attempt', $attempt_alias, [
      'expires'  => time() + 86400 * 365,
      'path'     => '/',
      'secure'   => qz_cookie_secure(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  qz_public_enter_quiz($attempt_alias, $attempt['started_at'] ?? null);
}

/* =========================
   4) Messages
   ========================= */
$errors = [];
$msgs   = [];
if (!empty($_GET['err'])) $errors[] = (string)$_GET['err'];
if (!empty($_GET['msg'])) $msgs[]   = (string)$_GET['msg'];

$otp = '';

/* =========================
   5) POST OTP verify (max 10 attempts)
   ========================= */
$MAX_TRIES = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
  if (!qz_public_csrf_check(qz_public_csrf_from_request())) {
    http_response_code(419);
    exit('CSRF token mismatch');
  }

  $otp = trim((string)($_POST['otp'] ?? ''));

  if (!preg_match('/^\d{6}$/', $otp)) {
    $errors[] = 'Kode OTP harus 6 digit.';
  } else {
    try {
      $pdo->beginTransaction();

      // lock row attempt untuk anti double submit / multi tab
      $stmt = $pdo->prepare("
        SELECT id, status, email_verification_code, email_verified_at, otp_try_count, otp_locked_at, attempt_alias, started_at
        FROM quiz_attempts
        WHERE id = :id
        FOR UPDATE
      ");
      $stmt->execute([':id' => $attempt_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        $pdo->rollBack();
        $errors[] = 'Attempt not found. Please restart from Start.';
      } else {
        $status_db = (string)($row['status'] ?? '');
        $verified_db = !empty($row['email_verified_at']);
        $tries = (int)($row['otp_try_count'] ?? 0);
        $lockedAt = $row['otp_locked_at'] ?? null;

        // kalau sudah verified/in_progress, jangan proses OTP lagi
        if ($verified_db || in_array($status_db, ['verified','in_progress','submitted'], true)) {
          $pdo->commit();
          qz_public_enter_quiz(
            (string)($row['attempt_alias'] ?? $attempt_alias),
            $row['started_at'] ?? null
          );
        }

        // lock kalau sudah 10x
        if ($lockedAt || $tries >= $MAX_TRIES) {
          if (!$lockedAt) {
            $stmt = $pdo->prepare("UPDATE quiz_attempts SET otp_locked_at = NOW() WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $attempt_id]);
          }
          $pdo->commit();
          $errors[] = 'Too many OTP attempts. Please contact admin to reset attempts.';
        } else {

          $triesNew = $tries + 1;
          $code_db = (string)($row['email_verification_code'] ?? '');
          $ok = hash_equals($code_db, $otp);

          if (!$ok) {
            $lockNow = ($triesNew >= $MAX_TRIES) ? ", otp_locked_at = NOW()" : "";
            $stmt = $pdo->prepare("
              UPDATE quiz_attempts
              SET otp_try_count = :t,
                  last_activity_at = NOW()
                  {$lockNow}
              WHERE id = :id
              LIMIT 1
            ");
            $stmt->execute([':t' => $triesNew, ':id' => $attempt_id]);

            $pdo->commit();

            $left = max(0, $MAX_TRIES - $triesNew);
            $errors[] = ($left <= 0)
              ? 'Too many OTP attempts. Please contact admin to reset attempts.'
              : ('Verification failed. OTP is incorrect. Remaining attempts: ' . $left . 'x.');
          } else {
            // benar -> tandai verified; paket soal dan timer dimulai oleh take POST
            $stmt = $pdo->prepare("
              UPDATE quiz_attempts
              SET email_verified_at = NOW(),
                  verified_via = COALESCE(verified_via,'email'),
                  status = 'verified',
                  last_activity_at = NOW(),
                  otp_try_count = :t
              WHERE id = :id
                AND status = 'registered'
              LIMIT 1
            ");
            $stmt->execute([':id' => $attempt_id, ':t' => $triesNew]);

            $pdo->commit();

            // pastikan cookie attempt
            if ($attempt_alias !== '') {
              setcookie('quiz_attempt', $attempt_alias, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'secure'   => qz_cookie_secure(),
                'httponly' => true,
                'samesite' => 'Lax',
              ]);
            }

            qz_public_enter_quiz($attempt_alias);
          }
        }
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log("[quiz/verify] verification error: " . $e->getMessage());
      $errors[] = 'Failed to process verification. Please try again.';
    }
  }
}

/* =========================
   6) OTP channel state + attempt tries state
   ========================= */
$emailSent = !empty($attempt['otp_email_sent_at']);
$waSent    = !empty($attempt['otp_wa_sent_at']);
$emailEnabled = (settings_get($pdo, 'quiz_otp_email_enabled', '1') ?? '1') !== '0';
$waEnabled = (settings_get($pdo, 'quiz_otp_whatsapp_enabled', '0') ?? '0') !== '0';
$cooldownSec = 90;

$triesNow = (int)($attempt['otp_try_count'] ?? 0);
$lockedNow = !empty($attempt['otp_locked_at']);
$triesLeft = max(0, $MAX_TRIES - $triesNow);

// Admin link payload (alias only)
$baseAdmin = qz_current_origin($pdo) . rtrim(qz_admin_base_path($pdo), '/') . '/?page=admin/quiz/attempts/view';
$adminAttemptUrl = $baseAdmin . "&attempt=" . rawurlencode($attempt_alias);

$admin_wa = settings_get($pdo, 'quiz_admin_wa', '') ?? '';
$adminDigits = preg_replace('/\D+/', '', $admin_wa);
if (strpos($adminDigits, '0') === 0) $adminDigits = '62' . substr($adminDigits, 1);

$wa_text =
  "Hello Admin, I need an OTP for quiz verification.\n"
  . "Name: " . ($contact_name !== '' ? $contact_name : '-') . "\n"
  . "Email: " . ($contact_email !== '' ? $contact_email : '-') . "\n"
  . "Quiz ID: {$quiz_id}\n"
  . "Attempt: " . ($attempt_alias !== '' ? $attempt_alias : '-') . "\n"
  . "Admin link: {$adminAttemptUrl}\n\n"
  . "Please send my OTP via WhatsApp. Thank you.";

$wa_link = "https://wa.me/" . $adminDigits . "?text=" . urlencode($wa_text);

/* =========================
   7) UI (match screenshot)
   ========================= */
$quizTitle = h((string)$quiz['title']);
$quizDesc  = trim((string)($quiz['description'] ?? ''));
$quizDesc  = $quizDesc ? '<div class="st-desc">'.h($quizDesc).'</div>' : '';

$alertHtml = '';
if ($msgs) {
  $alertHtml .= '<div class="st-alert ok">'.implode('<br>', array_map('h', $msgs)).'</div>';
}
if ($errors) {
  $alertHtml .= '<div class="st-alert err">'.implode('<br>', array_map('h', $errors)).'</div>';
}

$triesBar = $lockedNow
  ? '<div class="qv-topbar qv-topbar-err">⚠️ OTP is locked due to too many attempts. Contact admin to reset attempts.</div>'
  : '<div class="qv-topbar">Remaining OTP attempts: <b>'.(int)$triesLeft.'x</b> from '.$MAX_TRIES.'x.</div>';

$identityNote = '
  <div class="qv-note">
    The identity for this attempt has been locked. If there is an email/WhatsApp error, please contact the admin to reset the attempt.
  </div>
';

$disableAll = $lockedNow ? 'disabled' : '';
$csrfField = '<input type="hidden" name="csrf_token" value="'.h(qz_public_csrf_token()).'">';
$emailPickHtml = $emailEnabled ? '
  <button type="button" class="qv-pick" data-pick="email" '.$disableAll.'>
    <div class="qv-pick-title">Email</div>
    <div class="qv-pick-sub">Send OTP to email</div>
  </button>' : '';
$waPickHtml = $waEnabled ? '
  <button type="button" class="qv-pick" data-pick="wa" '.$disableAll.'>
    <div class="qv-pick-title">WhatsApp</div>
    <div class="qv-pick-sub">Request OTP via admin</div>
  </button>' : '';
$otpSourceHtml = ($emailEnabled || $waEnabled) ? '
  <div class="qv-step">1) Select OTP source</div>
  <div class="qv-picks">'.$emailPickHtml.$waPickHtml.'</div>' : '
  <div class="qv-step">1) Request OTP from Admin</div>
  <div class="qv-note">Email and WhatsApp requests are currently disabled. Contact the admin directly to receive the OTP shown in your Attempt Detail.</div>';
$emailPanelHtml = $emailEnabled ? '
  <div class="qv-panel" id="panelEmail" style="display:none">
    <div class="qv-step">2) Send OTP via Email</div>
    <div class="qv-desc">Usually arrives within <b>30–60 seconds</b>. If delayed, wait up to <b>'.$cooldownSec.' seconds</b> and check your Spam folder.</div>
    <form method="post" action="'.h(quiz_public_url('send_otp.php')).'" style="margin-top:10px">
      '.$csrfField.'
      <input type="hidden" name="a" value="'.h($attempt_alias).'">
      <input type="hidden" name="via" value="email">
      <button class="st-btn primary" type="submit" id="btnSendEmail" '.($emailSent||$lockedNow?'disabled':'').'>
        '.($emailSent ? 'Email OTP: Already sent' : 'Send OTP to Email').'
      </button>
    </form>
    <div class="qv-mini">To prevent spam, the Email option can only be used <b>once</b>. The OTP code will not change.</div>
  </div>' : '';
$waPanelHtml = $waEnabled ? '
  <div class="qv-panel" id="panelWa" style="display:none">
    <div class="qv-step">2) Request OTP via Admin WhatsApp</div>
    <div class="qv-desc">You will send an automatic message to the admin. The admin will open your attempt link and reply with the OTP via WhatsApp.</div>
    <div class="qv-row">
      <a class="qv-wa" href="'.h($wa_link).'" target="_blank" rel="noopener" id="btnOpenWa">Open WhatsApp &amp; Send Request</a>
      <div class="qv-mini">Admin WhatsApp: <b>'.h($admin_wa).'</b></div>
    </div>
    <form method="post" action="'.h(quiz_public_url('send_otp.php')).'" style="margin-top:10px">
      '.$csrfField.'
      <input type="hidden" name="a" value="'.h($attempt_alias).'">
      <input type="hidden" name="via" value="whatsapp">
      <button class="st-btn" type="submit" id="btnMarkWa" '.($waSent||$lockedNow?'disabled':'').'>
        '.($waSent ? 'WhatsApp Request: Already submitted' : 'I have sent a message to the Admin').'
      </button>
    </form>
    <div class="qv-mini">To prevent spam, the WhatsApp option can only be used <b>once</b>. The OTP code will not change.</div>
  </div>' : '';
$enterOtpStep = ($emailEnabled || $waEnabled) ? '3)' : '2)';

$content_html = '
<section class="st">
  <div class="st-wrap">

    <div class="st-hero">
      <div class="st-badge">VERIFY OTP</div>
      <h1 class="st-h1">'.$quizTitle.'</h1>
      '.$quizDesc.'

      <div class="rz-steps" style="margin-top:12px">
        <div class="rz-step">1. Registration</div>
        <div class="rz-step">2. Select Quiz</div>
        <div class="rz-step active">3. OTP Verification</div>
        <div class="rz-step">4. Take Quiz</div>
      </div>
    </div>

    '.$alertHtml.'
    '.$triesBar.'

    <div class="qv-card">
      <div class="qv-title">Identity Confirmation (Locked)</div>

      <div class="qv-grid">
        <div><div class="qv-label">Name</div><div class="qv-val">'.h($contact_name !== '' ? $contact_name : '—').'</div></div>
        <div><div class="qv-label">Email</div><div class="qv-val">'.h($contact_email !== '' ? $contact_email : '—').'</div></div>
        <div><div class="qv-label">WhatsApp</div><div class="qv-val">'.h($contact_phone !== '' ? $contact_phone : '—').'</div></div>
      </div>

      '.$identityNote.'

      <div class="qv-hr"></div>

      '.$otpSourceHtml.$emailPanelHtml.$waPanelHtml.'

      <div class="qv-hr"></div>

      <div class="qv-step">'.$enterOtpStep.' Enter OTP Code</div>
      <form method="post" autocomplete="off" style="margin-top:10px">
        '.$csrfField.'
        <input class="qv-input" name="otp" inputmode="numeric" pattern="\\d{6}" maxlength="6"
               placeholder="Enter 6-digit OTP" value="'.h($otp).'" required '.($lockedNow?'disabled':'').'>
        <div class="qv-actions">
          <button class="st-btn primary" type="submit" '.($lockedNow?'disabled':'').'>Verify</button>
          <a class="st-btn ghost" href="'.h($startUrl).'">Back to Start</a>
        </div>
      </form>

      <div class="qv-tip">
        Tip: do not switch browsers/devices after starting. The system verifies your token & device.
      </div>

    </div>
  </div>

  <style>
    .qv-topbar{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:12px 14px;
      margin:12px 0 14px;
      font-weight:700;
    }
    .qv-topbar-err{
      border-color:#fecaca;
      background:#fff7ed;
      color:#7f1d1d;
    }
    .qv-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      padding:18px;
    }
    .qv-title{
      font-weight:900;
      font-size:18px;
      margin-bottom:10px;
    }
    .qv-grid{
      display:grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap:16px;
    }
    @media (max-width: 860px){
      .qv-grid{ grid-template-columns:1fr; }
    }
    .qv-label{ font-size:12px; opacity:.65; margin-bottom:6px; }
    .qv-val{ font-size:16px; font-weight:900; }
    .qv-note{
      margin-top:12px;
      border:1px dashed #cbd5e1;
      background:#f8fafc;
      padding:10px 12px;
      border-radius:14px;
      font-size:13px;
      opacity:.85;
    }
    .qv-hr{
      height:1px;
      background:#e5e7eb;
      margin:16px 0;
    }
    .qv-step{
      font-weight:900;
      margin-bottom:10px;
    }
    .qv-picks{
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap:12px;
      margin-bottom:10px;
    }
    @media (max-width: 860px){
      .qv-picks{ grid-template-columns:1fr; }
    }
    .qv-pick{
      text-align:left;
      border:1px solid #e5e7eb;
      background:#f8fafc;
      border-radius:14px;
      padding:14px 14px;
      cursor:pointer;
    }
    .qv-pick.active{
      border:2px solid #60a5fa;
      background:#eff6ff;
      box-shadow:0 0 0 4px rgba(59,130,246,.10);
    }
    .qv-pick-title{ font-weight:900; }
    .qv-pick-sub{ font-size:13px; opacity:.7; margin-top:2px; }

    .qv-panel{
      border:1px dashed #cbd5e1;
      background:#f8fafc;
      border-radius:14px;
      padding:14px;
      margin-top:10px;
    }
    .qv-desc{ font-size:13px; opacity:.85; }
    .qv-row{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:center;
      margin-top:10px;
    }
    .qv-wa{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:12px 14px;
      border-radius:12px;
      border:1px solid #22c55e;
      background:linear-gradient(135deg,#dcfce7,#f0fdf4);
      font-weight:900;
      text-decoration:none;
    }
    .qv-input{
      width:100%;
      max-width:520px;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:12px 12px;
      font-size:16px;
      outline:none;
      background:#fff;
    }
    .qv-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
      align-items:center;
    }
    .qv-mini{
      margin-top:10px;
      font-size:12px;
      opacity:.75;
    }
    .qv-tip{
      margin-top:12px;
      font-size:12px;
      opacity:.75;
    }
  </style>

  <script>
  (function(){
    const locked = '.($lockedNow ? 'true' : 'false').';
    const btns = document.querySelectorAll(".qv-pick");
    const pEmail = document.getElementById("panelEmail");
    const pWa    = document.getElementById("panelWa");

    function setPick(which){
      if (locked) return;
      btns.forEach(b => b.classList.toggle("active", b.dataset.pick === which));
      if (pEmail) pEmail.style.display = (which === "email") ? "block" : "none";
      if (pWa)    pWa.style.display    = (which === "wa") ? "block" : "none";
      try { localStorage.setItem("otp_pick", which); } catch(e){}
    }

    btns.forEach(b => b.addEventListener("click", ()=> setPick(b.dataset.pick)));

    const available = Array.from(btns, b => b.dataset.pick);
    let saved = available[0] || "";
    try { saved = localStorage.getItem("otp_pick") || saved; } catch(e){}
    if (!available.includes(saved)) saved = available[0] || "";
    if (saved) setPick(saved);
  })();
  </script>
</section>
';

require __DIR__ . '/../layout.php';
exit;
