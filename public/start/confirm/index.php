<?php
// /quiz/start/confirm/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../_security.php';

$debug = function_exists('app_debug_enabled') && app_debug_enabled();

require_once __DIR__ . '/../../_guard_resume.php';
qz_guard_resume($pdo);

// Anti-cache (back-button)
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
}

$page_title = 'Konfirmasi Start Quiz';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

function normalize_phone_id(string $phone): string {
  $p = trim($phone);
  if ($p === '') return '';
  $p = preg_replace('/[^\d\+]/', '', $p);
  if (strpos($p, '+62') === 0) $p = '0' . substr($p, 3);
  elseif (strpos($p, '62') === 0) $p = '0' . substr($p, 2);
  return $p;
}

function quiz_is_open(array $quiz, string $now): bool {
  if ((int)($quiz['is_active'] ?? 0) !== 1) return false;
  if (!empty($quiz['start_at']) && $now < (string)$quiz['start_at']) return false;
  if (!empty($quiz['end_at']) && $now > (string)$quiz['end_at']) return false;
  return true;
}

function ua_hash(): string {
  return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function set_cookie(string $name, string $value, int $ttlSeconds): void {
  setcookie($name, $value, [
    'expires'  => time() + $ttlSeconds,
    'path'     => '/',
    'secure'   => qz_cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

/** Cek kolom DB untuk cegah fatal 500 karena prepare() false */
function require_attempt_columns(PDO $pdo, array $cols): array {
  $missing = [];
  foreach ($cols as $c) {
    $st = $pdo->prepare("SHOW COLUMNS FROM `quiz_attempts` LIKE :c");
    if (!$st) { $missing[] = $c; continue; }
    $st->execute([':c'=>$c]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) $missing[] = $c;
  }
  return $missing;
}

/** Attempt alias generator (butuh UNIQUE uq_attempt_alias) */
function generate_attempt_alias(PDO $pdo): string {
  do {
    $alias = 'a_' . bin2hex(random_bytes(8)); // 16 hex
    $stmt = $pdo->prepare("SELECT 1 FROM quiz_attempts WHERE attempt_alias = :a LIMIT 1");
    $stmt->execute([':a'=>$alias]);
    $exists = (bool)$stmt->fetchColumn();
  } while ($exists);
  return $alias;
}

/** Generate a unique test number from the administrator-defined format. */
function generate_nomor_test(PDO $pdo, int $attemptId, ?int $year = null): string {
  $format = settings_get($pdo, 'quiz_test_number_format', 'QUIZ/{year}/{attempt_id:6}') ?? 'QUIZ/{year}/{attempt_id:6}';
  $quizId = null;
  if (str_contains($format, '{quiz_id')) {
    $stmt = $pdo->prepare('SELECT quiz_id FROM quiz_attempts WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$attemptId]);
    $quizId = (int)$stmt->fetchColumn();
    if ($quizId <= 0) throw new RuntimeException('Quiz ID untuk nomor test tidak ditemukan.');
  }
  return function_exists('quiz_format_test_number')
    ? quiz_format_test_number($format, $attemptId, $year, $quizId)
    : 'QUIZ/'.($year ?? (int)date('Y')).'/'.str_pad((string)$attemptId, 6, '0', STR_PAD_LEFT);
}

/** Pastikan nomor_test terisi (untuk attempt lama / recovery) */
function ensure_nomor_test(PDO $pdo, int $attemptId, ?string $current): string {
  $cur = trim((string)$current);
  if ($cur !== '') return $cur;

  $nt = generate_nomor_test($pdo, $attemptId);
  $pdo->prepare("
    UPDATE quiz_attempts
    SET nomor_test = :nt
    WHERE id = :id
      AND (nomor_test IS NULL OR nomor_test = '')
    LIMIT 1
  ")->execute([
    ':nt' => $nt,
    ':id' => $attemptId,
  ]);
  return $nt;
}

/** Issue token baru (plain->cookie, hash->DB) tanpa reset status */
function issue_new_token(PDO $pdo, int $attemptId, string $uaHash, string $ip): string {
  $token_plain = bin2hex(random_bytes(24));
  $token_hash  = hash('sha256', $token_plain);

  $st = $pdo->prepare("
    UPDATE quiz_attempts
    SET token_hash = :th,
        user_agent_hash = COALESCE(NULLIF(user_agent_hash,''), :ua),
        last_activity_at = NOW(),
        ip_address = :ip
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([
    ':th'=>$token_hash,
    ':ua'=>$uaHash,
    ':ip'=>$ip,
    ':id'=>$attemptId,
  ]);
  return $token_plain;
}

function csrf_check_or_fail(int $ttl = 3600): void {
  if (!qz_public_csrf_check(qz_public_csrf_from_request(), $ttl)) {
    http_response_code(419);
    exit('CSRF token mismatch');
  }
}

/* ===============================
   0) Cek schema agar tidak 500
   =============================== */
$requiredCols = [
  'attempt_alias',
  'nomor_test', // <<< NEW
  'identity_locked',
  'contact_name','contact_email','contact_phone',
  'delivery_mode',
  'token_hash','user_agent_hash','email_verification_code','email_verified_at','status',
  'otp_try_count','otp_locked_at','otp_email_sent_at','otp_wa_sent_at',
  'last_activity_at','ip_address'
];

try {
  $missing = require_attempt_columns($pdo, $requiredCols);
  if ($missing) {
    $content_html = '
    <section class="st"><div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">DB MIGRATION</div>
        <h1 class="st-h1">Kolom quiz_attempts belum lengkap</h1>
        <div class="st-desc">Halaman confirm butuh kolom berikut:</div>
      </div>
      <div class="st-box warn">
        <div style="font-weight:800;margin-bottom:8px">Missing:</div>
        <div style="font-family:ui-monospace,monospace;white-space:pre-wrap">'.h(implode(", ", $missing)).'</div>
        <div style="margin-top:10px;opacity:.8">Dev: run ALTER TABLE to add those columns (or run your migration script).</div>
      </div>
      <div class="st-box ok" style="margin-top:12px">
        <a class="st-btn primary" href="/quiz/start/">Kembali</a>
      </div>
    </div></section>';
    require __DIR__ . '/../../layout.php';
    exit;
  }
} catch (Throwable $e) {
  error_log("[confirm] schema check failed: ".$e->getMessage());
  // Continue; the normal error path below remains safe outside APP_DEBUG.
}

/* ===============================
   1) Validasi quiz
   =============================== */
$now = date('Y-m-d H:i:s');
$quiz_id = (int)($_GET['quiz'] ?? 0);
if ($quiz_id <= 0) go('/quiz/start/');

$stmt = $pdo->prepare("
  SELECT id, title, description, is_active, is_primary, start_at, end_at, questions_per_attempt, duration_minutes
  FROM quizzes
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([':id'=>$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz || !quiz_is_open($quiz, $now)) {
  $content_html = '
  <section class="st">
    <div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">START</div>
        <h1 class="st-h1">Quiz Not Available</h1>
        <div class="st-desc">This quiz is not yet open / has been closed / or is inactive.</div>
      </div>
      <div class="st-box warn">
        <a class="st-btn primary" href="/quiz/start/">Back to Select Quiz</a>
      </div>
    </div>
  </section>';
  require __DIR__ . '/../../layout.php';
  exit;
}

/* ===============================
   2) Ambil student dari cookie
   =============================== */
$studentCookieName = 'quiz_cid';
$cookie_id = isset($_COOKIE[$studentCookieName]) ? trim((string)$_COOKIE[$studentCookieName]) : '';
$nextSelf  = '/quiz/start/confirm/?quiz=' . (int)$quiz_id;
$regUrl    = '/quiz/register/?next=' . urlencode($nextSelf);

if ($cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $cookie_id)) {
  go($regUrl);
}

$stmt = $pdo->prepare("SELECT id, name, email, phone FROM students WHERE cookie_id = :cid LIMIT 1");
$stmt->execute([':cid'=>$cookie_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) go($regUrl);

$student['email'] = trim((string)($student['email'] ?? ''));
$student['phone'] = normalize_phone_id((string)($student['phone'] ?? ''));

if ($student['email'] === '' || !filter_var($student['email'], FILTER_VALIDATE_EMAIL) || $student['phone'] === '') {
  go($regUrl);
}

$student_id = (int)$student['id'];

/* ===============================
   3) Load attempt (1 per quiz per student)
   =============================== */
$stmt = $pdo->prepare("
SELECT
  id, attempt_alias, nomor_test, status, email_verified_at,
  token_hash, user_agent_hash,
  otp_email_sent_at, otp_wa_sent_at,
  otp_try_count, otp_locked_at,
  identity_locked, contact_name, contact_email, contact_phone,
  delivery_mode,
  email_verification_code,
  created_at
FROM quiz_attempts
  WHERE quiz_id = :qid AND student_id = :sid
  ORDER BY id DESC
  LIMIT 1
");
$stmt->execute([':qid'=>$quiz_id, ':sid'=>$student_id]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

/* ===============================
   3b) HARD GUARD anti exploit
   - submitted => locked
   - verified/in_progress => lanjut take + pastikan token cookie ada (reissue bila hilang)
   =============================== */
if ($attempt) {
  $st  = (string)($attempt['status'] ?? '');
  $ver = !empty($attempt['email_verified_at']);
  $alias = trim((string)($attempt['attempt_alias'] ?? ''));

  if ($st === 'submitted') {
    $content_html = '
    <section class="st">
      <div class="st-wrap">
        <div class="st-hero">
          <div class="st-badge">QUIZ</div>
          <h1 class="st-h1">Quiz sudah disubmit</h1>
          <div class="st-desc">Kamu tidak bisa mengulang quiz ini.</div>
        </div>
        <div class="st-box ok">
          <a class="st-btn primary" href="/quiz/start/">Kembali</a>
        </div>
      </div>
    </section>';
    require __DIR__ . '/../../layout.php';
    exit;
  }

  if ($ver || in_array($st, ['verified','in_progress'], true)) {
    $attempt_id = (int)$attempt['id'];

    // ensure alias ada
    if ($alias === '') {
      try {
        $alias = generate_attempt_alias($pdo);

        $pdo->prepare("
          UPDATE quiz_attempts
          SET attempt_alias   = :alias,
              identity_locked = 1,
              contact_name    = COALESCE(NULLIF(contact_name,''), :nm),
              contact_email   = COALESCE(NULLIF(contact_email,''), :em),
              contact_phone   = COALESCE(NULLIF(contact_phone,''), :ph)
          WHERE id = :id
          LIMIT 1
        ")->execute([
          ':alias' => $alias,
          ':nm'    => (string)$student['name'],
          ':em'    => (string)$student['email'],
          ':ph'    => (string)$student['phone'],
          ':id'    => $attempt_id,
        ]);
      } catch (Throwable $e) {
        error_log("[confirm] failed ensure alias: ".$e->getMessage());
        $alias = '';
      }
    }

    // ensure nomor_test ada (kalau kosong, isi otomatis)
    try {
      ensure_nomor_test($pdo, $attempt_id, (string)($attempt['nomor_test'] ?? ''));
    } catch (Throwable $e) {
      error_log("[confirm] ensure nomor_test failed: ".$e->getMessage());
    }

    if ($alias === '') {
      go($nextSelf . '&err=' . rawurlencode('Invalid attempt. Restart from Start.'));
    }

    // UA must match
    $ua = ua_hash();
    if (!empty($attempt['user_agent_hash']) && !hash_equals((string)$attempt['user_agent_hash'], $ua)) {
      $content_html = '
      <section class="st"><div class="st-wrap">
        <div class="st-hero">
          <div class="st-badge">SECURITY</div>
          <h1 class="st-h1">Access is not Valid</h1>
          <div class="st-desc">Use the same browser/device when starting the quiz.</div>
        </div>
        <div class="st-box warn"><a class="st-btn primary" href="/quiz/start/">Back</a></div>
      </div></section>';
      require __DIR__ . '/../../layout.php';
      exit;
    }

    // ensure token cookie ada & sesuai. Kalau hilang/mismatch -> issue token baru (tanpa reset status)
    $tokenCookieName = 'quiz_token_' . (int)$quiz_id;
    $token_plain_cookie = isset($_COOKIE[$tokenCookieName]) ? (string)$_COOKIE[$tokenCookieName] : '';
    $token_hash_cookie  = $token_plain_cookie !== '' ? hash('sha256', $token_plain_cookie) : '';
    $needToken = ($token_hash_cookie === '' || !hash_equals((string)$attempt['token_hash'], $token_hash_cookie));

    if ($needToken) {
      try {
        $pdo->beginTransaction();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $token_plain = issue_new_token($pdo, (int)$attempt['id'], $ua, $ip);
        $pdo->commit();

        set_cookie($tokenCookieName, $token_plain, 86400); // 1 hari
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[confirm] issue token failed: ".$e->getMessage());
      }
    }

    if ($alias !== '') set_cookie('quiz_attempt', $alias, 86400*365);

    go('/quiz/take/?a=' . rawurlencode($alias));
  }
}

/* ===============================
   4) POST: Mulai / Lanjut
   - hanya untuk attempt kosong atau status registered
   =============================== */
$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));
$postErrors = []; // collect POST validation errors to display on page
$deliveryMode = trim((string)($_POST['delivery_mode'] ?? ($attempt['delivery_mode'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_or_fail();

  if (!in_array($deliveryMode, ['online', 'onsite'], true)) {
    $postErrors[] = 'Please select Online or Onsite before continuing.';
  }

  $uaH = ua_hash();
  $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');

  $tokenCookieName = 'quiz_token_' . (int)$quiz_id;
  $token_plain_cookie = isset($_COOKIE[$tokenCookieName]) ? (string)$_COOKIE[$tokenCookieName] : '';
  $token_hash_cookie  = $token_plain_cookie !== '' ? hash('sha256', $token_plain_cookie) : '';

  if (!$postErrors) try {
    $pdo->beginTransaction();

    // lock row (re-check)
    $A = null;
    if ($attempt) {
      $stx = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=:id FOR UPDATE");
      $stx->execute([':id'=>(int)$attempt['id']]);
      $A = $stx->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
      $stx = $pdo->prepare("
        SELECT * FROM quiz_attempts
        WHERE quiz_id=:qid AND student_id=:sid
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
      ");
      $stx->execute([':qid'=>$quiz_id, ':sid'=>$student_id]);
      $A = $stx->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($A) {
      $st = (string)($A['status'] ?? '');
      $ver = !empty($A['email_verified_at']);

      if ($st === 'submitted') {
        $pdo->commit();
        go($nextSelf . '&err=' . rawurlencode('The quiz has been submitted. Cannot be retaken.'));
      }

      if ($ver || in_array($st, ['verified','in_progress'], true)) {
        $pdo->commit();
        $alias = trim((string)($A['attempt_alias'] ?? ''));
        if ($alias !== '') set_cookie('quiz_attempt', $alias, 86400*365);
        go('/quiz/take/?a=' . rawurlencode($alias));
      }

      if ($st !== 'registered') {
        $pdo->commit();
        go($nextSelf . '&err=' . rawurlencode('Tidak bisa mulai pada status saat ini.'));
      }

      // UA konsisten sejak start
      if (!empty($A['user_agent_hash']) && !hash_equals((string)$A['user_agent_hash'], $uaH)) {
        $pdo->commit();
        go($nextSelf . '&err=' . rawurlencode('Invalid access. Use the same browser/device.'));
      }

      $attempt_id = (int)$A['id'];

      // ensure alias
      $alias = trim((string)($A['attempt_alias'] ?? ''));
      if ($alias === '') {
        $alias = generate_attempt_alias($pdo);
        $pdo->prepare("UPDATE quiz_attempts SET attempt_alias=:a WHERE id=:id LIMIT 1")
            ->execute([':a'=>$alias, ':id'=>$attempt_id]);
      }

      // ensure nomor_test
      ensure_nomor_test($pdo, $attempt_id, (string)($A['nomor_test'] ?? ''));

      // ensure OTP (jangan ganti kalau sudah ada)
      $otp = (string)($A['email_verification_code'] ?? '');
      if (!preg_match('/^\d{6}$/', $otp)) {
        $otp = (string)random_int(100000, 999999);
        $pdo->prepare("UPDATE quiz_attempts SET email_verification_code=:o WHERE id=:id LIMIT 1")
            ->execute([':o'=>$otp, ':id'=>$attempt_id]);
      }

      // token: kalau cookie tidak ada/mismatch -> issue token baru
      $needNewToken = true;
      if ($token_hash_cookie !== '') {
        $needNewToken = !hash_equals((string)$A['token_hash'], $token_hash_cookie);
      }

      if ($needNewToken) {
        $token_plain = bin2hex(random_bytes(24));
        $token_hash  = hash('sha256', $token_plain);

        $pdo->prepare("
          UPDATE quiz_attempts
          SET token_hash = :th,
              user_agent_hash = COALESCE(NULLIF(user_agent_hash,''), :ua),
              last_activity_at = NOW(),
              ip_address = :ip
          WHERE id = :id
          LIMIT 1
        ")->execute([
          ':th'=>$token_hash,
          ':ua'=>$uaH,
          ':ip'=>$ip,
          ':id'=>$attempt_id,
        ]);
      } else {
        $token_plain = $token_plain_cookie;
        $pdo->prepare("UPDATE quiz_attempts SET last_activity_at=NOW(), ip_address=:ip WHERE id=:id LIMIT 1")
            ->execute([':ip'=>$ip, ':id'=>$attempt_id]);
      }

      // lock identity snapshot (sekali)
      $locked = (int)($A['identity_locked'] ?? 0);
      if ($locked === 0) {
        $pdo->prepare("
          UPDATE quiz_attempts
          SET identity_locked = 1,
              contact_email = COALESCE(NULLIF(contact_email,''), :em),
              contact_phone = COALESCE(NULLIF(contact_phone,''), :ph)
          WHERE id = :id
          LIMIT 1
        ")->execute([
          ':em'=>(string)$student['email'],
          ':ph'=>(string)$student['phone'],
          ':id'=>$attempt_id,
        ]);
      }

      $pdo->prepare("UPDATE quiz_attempts SET delivery_mode=:mode WHERE id=:id LIMIT 1")
          ->execute([':mode'=>$deliveryMode, ':id'=>$attempt_id]);

      $pdo->commit();

      // cookies
      set_cookie($tokenCookieName, $token_plain, 86400);
      set_cookie('quiz_attempt', $alias, 86400*365);

      go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('Enter the OTP code to continue.'));
    }

    // ===== ENFORCE: hanya 1 attempt AKTIF (verified/in_progress) lintas quiz =====
    try {
      $stAct = $pdo->prepare("
        SELECT id, quiz_id, attempt_alias, nomor_test, status, token_hash, user_agent_hash
        FROM quiz_attempts
        WHERE student_id = :sid
          AND status IN ('verified','in_progress')
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
      ");
      $stAct->execute([':sid' => $student_id]);
      $active = $stAct->fetch(PDO::FETCH_ASSOC);

      if ($active) {
        $activeQuizId = (int)$active['quiz_id'];

        if ($activeQuizId !== $quiz_id) {

          if (!empty($active['user_agent_hash']) && !hash_equals((string)$active['user_agent_hash'], $uaH)) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            go($nextSelf . '&err=' . rawurlencode('There is still an active exam. Use the same device/browser when starting.'));
          }

          $activeAlias = trim((string)($active['attempt_alias'] ?? ''));
          if ($activeAlias === '') {
            $activeAlias = generate_attempt_alias($pdo);
            $pdo->prepare("UPDATE quiz_attempts SET attempt_alias=:a WHERE id=:id LIMIT 1")
                ->execute([':a'=>$activeAlias, ':id'=>(int)$active['id']]);
          }

          // ensure nomor_test untuk active attempt juga
          ensure_nomor_test($pdo, (int)$active['id'], (string)($active['nomor_test'] ?? ''));

          $activeTokName = 'quiz_token_' . $activeQuizId;
          $plain = isset($_COOKIE[$activeTokName]) ? (string)$_COOKIE[$activeTokName] : '';
          $hash  = $plain !== '' ? hash('sha256', $plain) : '';

          if ($hash === '' || !hash_equals((string)$active['token_hash'], $hash)) {
            $plain = issue_new_token($pdo, (int)$active['id'], $uaH, $ip);
            set_cookie($activeTokName, $plain, 86400);
          }

          if ($pdo->inTransaction()) $pdo->commit();

          set_cookie('quiz_attempt', $activeAlias, 86400*365);
          go('/quiz/take/?a=' . rawurlencode($activeAlias));
        }
      }
    } catch (Throwable $e) {
      error_log("[confirm] active attempt check failed: " . $e->getMessage());
    }

    // === validate email/phone not used by another student ===
    if (empty($postErrors) && !empty($student['email'])) {
      $st = $pdo->prepare("SELECT 1 FROM quiz_attempts WHERE quiz_id=:qid AND contact_email=:em AND student_id!=:sid LIMIT 1");
      $st->execute([':qid'=>$quiz_id, ':em'=>(string)$student['email'], ':sid'=>$student_id]);
      if ($st->fetchColumn()) $postErrors[] = 'Email ini sudah terdaftar untuk quiz ini.';
    }
    if (empty($postErrors) && !empty($student['phone'])) {
      $st = $pdo->prepare("SELECT 1 FROM quiz_attempts WHERE quiz_id=:qid AND contact_phone=:ph AND student_id!=:sid LIMIT 1");
      $st->execute([':qid'=>$quiz_id, ':ph'=>(string)$student['phone'], ':sid'=>$student_id]);
      if ($st->fetchColumn()) $postErrors[] = 'Nomor WhatsApp ini sudah terdaftar untuk quiz ini.';
    }
    if ($postErrors) {
      if ($pdo->inTransaction()) $pdo->rollBack();
    } else {
    // === create attempt baru ===
    $attempt_alias = generate_attempt_alias($pdo);
    $token_plain   = bin2hex(random_bytes(24));
    $token_hash    = hash('sha256', $token_plain);
    $otp           = (string)random_int(100000, 999999);

    try {
      $stmt = $pdo->prepare("
        INSERT INTO quiz_attempts
          (attempt_alias, nomor_test, quiz_id, student_id,
           identity_locked, contact_name, contact_email, contact_phone,
           token_hash, user_agent_hash,
           email_verification_code, email_verified_at,
           delivery_mode,
           status, started_at, last_activity_at, ip_address,
           otp_email_sent_at, otp_wa_sent_at, otp_try_count, otp_locked_at,
           created_at, updated_at)
        VALUES
          (:alias, NULL, :qid, :sid,
           1, :nm, :em, :ph,
           :th, :ua,
           :otp, NULL,
           :delivery_mode,
           'registered', NULL, NOW(), :ip,
           NULL, NULL, 0, NULL,
           NOW(), NOW())
      ");
      $stmt->execute([
        ':alias'=>$attempt_alias,
        ':qid'=>$quiz_id,
        ':sid'=>$student_id,
        ':nm'=>(string)$student['name'],
        ':em'=>(string)$student['email'],
        ':ph'=>(string)$student['phone'],
        ':th'=>$token_hash,
        ':ua'=>$uaH,
        ':otp'=>$otp,
        ':delivery_mode'=>$deliveryMode,
        ':ip'=>$ip,
      ]);

      $newAttemptId = (int)$pdo->lastInsertId();
      if ($newAttemptId > 0) {
        ensure_nomor_test($pdo, $newAttemptId, '');
      }

    } catch (Throwable $e) {
      if (!($e instanceof PDOException) || (string)$e->getCode() !== '23000') throw $e;

      $A = null;
      // coba cari by (quiz, student)
      $st = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id=:qid AND student_id=:sid ORDER BY id DESC LIMIT 1 FOR UPDATE");
      $st->execute([':qid'=>$quiz_id, ':sid'=>$student_id]);
      $A = $st->fetch(PDO::FETCH_ASSOC);
      // fallback: cari by (quiz, email) — uq_quiz_email conflict
      if (!$A && !empty($student['email'])) {
        $st = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id=:qid AND contact_email=:em ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([':qid'=>$quiz_id, ':em'=>(string)$student['email']]);
        $A = $st->fetch(PDO::FETCH_ASSOC);
      }
      // fallback: cari by (quiz, phone) — uq_quiz_phone conflict
      if (!$A && !empty($student['phone'])) {
        $st = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id=:qid AND contact_phone=:ph ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([':qid'=>$quiz_id, ':ph'=>(string)$student['phone']]);
        $A = $st->fetch(PDO::FETCH_ASSOC);
      }
      if (!$A) throw $e;

      // Jika attempt milik student lain (conflict email/phone) → jangan redirect ke verify
      if ((int)$A['student_id'] !== $student_id) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $postErrors[] = 'Email/nomor WhatsApp sudah terdaftar untuk quiz ini oleh peserta lain.';
      } else {
      $st = (string)($A['status'] ?? '');
      $ver = !empty($A['email_verified_at']);

      if ($st === 'submitted') {
        $pdo->commit();
        go($nextSelf . '&err=' . rawurlencode('The quiz has been submitted. Cannot be retaken..'));
      }
      if ($ver || in_array($st, ['verified','in_progress'], true)) {
        $pdo->commit();
        $alias = trim((string)($A['attempt_alias'] ?? ''));
        if ($alias !== '') set_cookie('quiz_attempt', $alias, 86400*365);
        go('/quiz/take/?a=' . rawurlencode($alias));
      }

      // registered: issue token baru supaya cookie valid
      $attempt_id = (int)$A['id'];
      $pdo->prepare("UPDATE quiz_attempts SET delivery_mode=:mode WHERE id=:id LIMIT 1")
          ->execute([':mode'=>$deliveryMode, ':id'=>$attempt_id]);
      $attempt_alias = trim((string)($A['attempt_alias'] ?? ''));
      if ($attempt_alias === '') {
        $attempt_alias = generate_attempt_alias($pdo);
        $pdo->prepare("UPDATE quiz_attempts SET attempt_alias=:a WHERE id=:id LIMIT 1")
            ->execute([':a'=>$attempt_alias, ':id'=>$attempt_id]);
      }

      // ensure nomor_test
      ensure_nomor_test($pdo, $attempt_id, (string)($A['nomor_test'] ?? ''));

      $token_plain = issue_new_token($pdo, $attempt_id, $uaH, $ip);
    }

    }

    if (empty($postErrors)) {
    $pdo->commit();

    set_cookie('quiz_token_' . (int)$quiz_id, $token_plain, 86400);
    set_cookie('quiz_attempt', $attempt_alias, 86400*365);

    go('/quiz/verify/?quiz='.(int)$quiz_id.'&msg=' . rawurlencode('Enter the OTP code to continue.'));
    }
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log("[quiz/start/confirm] error: " . $msg);
    if ($debug) {
      http_response_code(500);
      echo '<pre style="white-space:pre-wrap;padding:16px;font:14px/1.5 monospace">';
      echo 'ERROR: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "\n";
      echo htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
      echo '</pre>';
      exit;
    }
    go($nextSelf . '&err=' . rawurlencode('Failed to start quiz. Please try again.'));
  }
}

/* ===============================
   5) Render UI
   =============================== */
$quizTitle = h((string)$quiz['title']);
$quizDesc  = trim((string)($quiz['description'] ?? ''));
$quizDesc  = $quizDesc ? '<div class="st-desc">'.h($quizDesc).'</div>' : '';

$csrfField = '<input type="hidden" name="csrf_token" value="'.h(qz_public_csrf_token()).'">';
$onlineChecked = $deliveryMode === 'online' ? ' checked' : '';
$onsiteChecked = $deliveryMode === 'onsite' ? ' checked' : '';
$deliveryModeField = '
  <fieldset style="border:0;padding:0;margin:0 0 14px">
    <legend class="st-label" style="margin-bottom:8px">How will you take this quiz?</legend>
    <div class="st-grid">
      <label class="st-note" style="display:block;cursor:pointer">
        <input type="radio" name="delivery_mode" value="online" required'.$onlineChecked.'>
        <strong>Online</strong><br>
        <span style="margin-left:22px">Take the quiz remotely using this device.</span>
      </label>
      <label class="st-note" style="display:block;cursor:pointer">
        <input type="radio" name="delivery_mode" value="onsite" required'.$onsiteChecked.'>
        <strong>Onsite</strong><br>
        <span style="margin-left:22px">Take the quiz at the designated location.</span>
      </label>
    </div>
  </fieldset>';

$attemptInfo = '';
$btnLabel = 'Start &amp; Get OTP';
if ($attempt) {
  $lockedNow = !empty($attempt['otp_locked_at']) || ((int)($attempt['otp_try_count'] ?? 0) >= 10);
  $attemptInfo = '
    <div class="st-note" style="margin-top:10px">
      An attempt has been created (status: <b>'.h((string)$attempt['status']).'</b>).
      '.($lockedNow ? '<div style="margin-top:6px;color:#7f1d1d">⚠️ OTP is locked. Contact the admin to reset the attempt.</div>' : '').'
    </div>
  ';
  $btnLabel = 'Continue to OTP Verification';
}

$alert = '';
if ($msg !== '') $alert .= '<div class="st-alert ok">'.h($msg).'</div>';
if ($err !== '') $alert .= '<div class="st-alert err">'.h($err).'</div>';
if ($postErrors) {
  $alert .= '<div class="st-alert err">'.implode('<br>', array_map('h', $postErrors)).'</div>';
}

// display attempt snapshot if available (fallback to student)
$displayName  = (string)($student['name'] ?? '');
$displayEmail = (string)($student['email'] ?? '');
$displayPhone = (string)($student['phone'] ?? '');

if ($attempt) {
  $an = trim((string)($attempt['contact_name'] ?? ''));
  $ae = trim((string)($attempt['contact_email'] ?? ''));
  $ap = trim((string)($attempt['contact_phone'] ?? ''));

  if ($an !== '') $displayName = $an;
  if ($ae !== '') $displayEmail = $ae;
  if ($ap !== '') $displayPhone = $ap;
}

$content_html = '
<section class="st">
  <div class="st-wrap">

    <div class="st-hero">
      <div class="st-badge">START CONFIRM</div>
      <h1 class="st-h1">'.$quizTitle.'</h1>
      '.$quizDesc.'

      <div class="rz-steps" style="margin-top:12px">
        <div class="rz-step">1. Registration</div>
        <div class="rz-step">2. Select Quiz</div>
        <div class="rz-step active">3. Confirmation</div>
        <div class="rz-step">4. OTP Verification</div>
        <div class="rz-step">5. Take Quiz</div>
      </div>
    </div>

    '.$alert.'

    <div class="st-box ok">
      <div class="st-box-title">Identity Confirmation</div>

<div class="st-grid" style="margin-top:10px">
  <div>
    <div class="st-label">Name</div>
    <div class="st-val">'.h($displayName).'</div>
  </div>

  <div>
    <div class="st-label">Email</div>
    <div class="st-val">'.h($displayEmail).'</div>
  </div>

  <div>
    <div class="st-label">WhatsApp</div>
    <div class="st-val">'.h($displayPhone).'</div>
  </div>
</div>

      '.$attemptInfo.'

      <div class="st-note" style="margin-top:12px">
        Once you proceed, the system will:
        <ul style="margin:8px 0 0 18px;line-height:1.6">
          <li>create an attempt for this quiz (one-time)</li>
          <li>generate an OTP and a device token</li>
          <li>lock the attempt identity (prevents changing email/WhatsApp after start)</li>
        </ul>
        <div style="margin-top:8px">
          <b>Important note:</b> once the status becomes <b>in_progress</b>, you cannot return to the Start/Verify steps to "reset" the OTP/state.
        </div>
      </div>

      <form method="post" style="margin:14px 0 0">
        '.$csrfField.'
        '.$deliveryModeField.'
        <div class="st-actions">
          <button class="st-btn primary" type="submit">'.$btnLabel.'</button>
          <a class="st-btn ghost" href="/quiz/register/?next='.h(urlencode($nextSelf)).'">Edit Details</a>
          <a class="st-btn" href="/quiz/start/">Change Quiz</a>
        </div>
      </form>
    </div>

  </div>
</section>
';

require __DIR__ . '/../../layout.php';
exit;
