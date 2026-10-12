<?php
// /quiz/register/index.php
// UX:
// - GET  : form registrasi (nama, email, whatsapp)
// - POST : simpan/update students + set cookie quiz_cid -> redirect ke next atau /quiz/start/
// NOTE: Verifikasi OTP TIDAK ada di sini. OTP mulai setelah start/confirm membuat attempt.

declare(strict_types=1);

require_once __DIR__ . '/../_security.php';

// IMPORTANT: guard resume (jika sudah in_progress/submitted, jangan boleh balik ke register)
require_once __DIR__ . '/../_guard_resume.php';
qz_guard_resume($pdo);

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$boundQuizId = (int)(quiz_public_route_context()['quiz_id'] ?? 0);
$registrationQuizTitle = '';
if ($boundQuizId > 0) {
  $stmt = $pdo->prepare('SELECT title FROM quizzes WHERE id=:id LIMIT 1');
  $stmt->execute([':id' => $boundQuizId]);
  $registrationQuizTitle = trim((string)($stmt->fetchColumn() ?: ''));
  if ($registrationQuizTitle === '') quiz_render_core_404();
}
$publicTitle = quiz_public_title($pdo);
$page_title = $registrationQuizTitle !== '' ? $registrationQuizTitle . ' Registration' : $publicTitle . ' Registration';

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

function uuid_v4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function normalize_phone_id(string $phone): string {
  $p = trim($phone);
  if ($p === '') return '';
  $p = preg_replace('/[^\d\+]/', '', $p);
  if (strpos($p, '+62') === 0) $p = '0' . substr($p, 3);
  elseif (strpos($p, '62') === 0) $p = '0' . substr($p, 2);
  return $p;
}

$next = quiz_public_safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''), 'start');

$cookieName = 'quiz_cid';
$cookie_id = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
if ($cookie_id !== '' && !preg_match('/^[a-f0-9\-]{36}$/i', $cookie_id)) {
  $cookie_id = '';
}

$errors = [];
$student = null;

if ($cookie_id !== '') {
  $stmt = $pdo->prepare("SELECT id, name, email, phone FROM students WHERE cookie_id=:cid LIMIT 1");
  $stmt->execute([':cid'=>$cookie_id]);
  $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$name  = $student ? (string)($student['name'] ?? '') : '';
$email = $student ? (string)($student['email'] ?? '') : '';
$phone = $student ? (string)($student['phone'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!qz_public_csrf_check(qz_public_csrf_from_request())) {
    http_response_code(419);
    exit('CSRF token mismatch');
  }

  $name  = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = normalize_phone_id((string)($_POST['phone'] ?? ''));

  if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Name is required (min 2 characters).';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
  if ($phone === '') $errors[] = 'WhatsApp number is required.';

  if (!$errors) {
    try {
      if ($cookie_id === '') $cookie_id = uuid_v4();

      $stmt = $pdo->prepare("SELECT id FROM students WHERE cookie_id=:cid LIMIT 1");
      $stmt->execute([':cid'=>$cookie_id]);
      $id = (int)$stmt->fetchColumn();

      if ($id > 0) {
        $stmt = $pdo->prepare("
          UPDATE students
          SET name=:name, email=:email, phone=:phone, updated_at=NOW()
          WHERE id=:id
          LIMIT 1
        ");
        $stmt->execute([
          ':name'=>$name,
          ':email'=>$email,
          ':phone'=>$phone,
          ':id'=>$id,
        ]);
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO students (cookie_id, name, email, phone, created_at, updated_at)
          VALUES (:cid, :name, :email, :phone, NOW(), NOW())
        ");
        $stmt->execute([
          ':cid'=>$cookie_id,
          ':name'=>$name,
          ':email'=>$email,
          ':phone'=>$phone,
        ]);
      }

      // set cookie (1 tahun)
      setcookie($cookieName, $cookie_id, [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'secure'   => qz_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);

      go($next);

    } catch (Throwable $e) {
      error_log('[quiz/register] error: ' . $e->getMessage());
      $errors[] = 'Failed to save data. Please try again.';
    }
  }
}

$alert = '';
if ($errors) {
  $alert = '<div class="qz-alert err">'.implode('<br>', array_map('h', $errors)).'</div>';
} elseif (!empty($_GET['msg'])) {
  $alert = '<div class="qz-alert ok">'.h((string)$_GET['msg']).'</div>';
}

$publicTitleHtml = h($publicTitle);
$publicTitleUpper = mb_strtoupper($publicTitle, 'UTF-8');
$registrationBadge = $registrationQuizTitle !== '' ? 'DEDICATED ' . $publicTitleUpper . ' ACCESS' : $publicTitleUpper . ' ACCESS';
$registrationHeading = $registrationQuizTitle !== ''
  ? 'Register for ' . h($registrationQuizTitle)
  : 'Participant Registration';
$registrationIntro = $registrationQuizTitle !== ''
  ? 'Fill in your details to continue directly to this ' . $publicTitleHtml . ' confirmation and verification flow.'
  : 'Fill in your details for OTP verification. After submitting, you will be redirected to the <b>Start ' . $publicTitleHtml . '</b> page.';
$registrationSteps = $registrationQuizTitle !== ''
  ? '<div class="qz-step active">1. Registration</div>
        <div class="qz-step">2. Confirmation</div>
        <div class="qz-step">3. OTP Verification</div>
        <div class="qz-step">4. Start</div>'
  : '<div class="qz-step active">1. Registration</div>
        <div class="qz-step">2. Select '.$publicTitleHtml.'</div>
        <div class="qz-step">3. Confirmation</div>
        <div class="qz-step">4. OTP Verification</div>
        <div class="qz-step">5. Start</div>';

$content_html = '
<section class="qz-page">
  <div class="qz-wrap">

    <div class="qz-hero">
      <div class="qz-badge">'.h($registrationBadge).'</div>
      <h1 class="qz-h1">'.$registrationHeading.'</h1>
      <div class="qz-sub">
        '.$registrationIntro.'
      </div>

      <div class="qz-steps">
        '.$registrationSteps.'
      </div>
    </div>

    '.$alert.'

    <div class="qz-card">
      <form method="post" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="'.h(qz_public_csrf_token()).'">
        <input type="hidden" name="next" value="'.h($next).'">

        <div class="qz-field">
          <label for="name">Full Name</label>
          <input id="name" name="name" type="text" required minlength="2"
                 value="'.h($name).'" placeholder="Example: John Doe">
          <div class="qz-help">Name is used to identify the attempt.</div>
        </div>

        <div class="qz-grid2">
          <div class="qz-field">
            <label for="email">Active Email</label>
            <input id="email" name="email" type="email" required
                   value="'.h($email).'" placeholder="name@email.com">
            <div class="qz-help">OTP can be sent via email.</div>
          </div>

          <div class="qz-field">
            <label for="phone">Phone Number / WhatsApp</label>
            <input id="phone" name="phone" type="tel" required
                   value="'.h($phone).'" placeholder="08xxxxxxxxxx">
            <div class="qz-help">Required for OTP option via admin WhatsApp.</div>
          </div>
        </div>

        <div class="qz-actions">
          <button class="qz-btn primary" type="submit">Save &amp; Continue</button>
        </div>

        <div class="qz-note">
          Data is stored using <b>browser cookies</b>. Do not switch browsers or devices while the '.$publicTitleHtml.' is in progress.
        </div>
      </form>
    </div>

  </div>

  <style>
    /* Wrapper */
    .qz-page{ padding: 10px 0 30px; }
    .qz-wrap{ max-width: 980px; margin: 0 auto; padding: 0 16px; }

    /* Hero */
    .qz-hero{ margin: 8px 0 14px; }
    .qz-badge{
      display:inline-block;
      padding:6px 10px;
      border-radius: 999px;
      border:1px solid rgba(15,23,42,.08);
      background: rgba(255,255,255,.65);
      font-weight: 900;
      font-size: 12px;
      letter-spacing: .08em;
    }
    .qz-h1{ margin: 10px 0 6px; font-size: 44px; line-height: 1.05; font-weight: 1000; }
    .qz-sub{ opacity: .85; font-size: 15px; }

    /* Steps (pill) */
    .qz-steps{
      display:flex; gap:10px; flex-wrap:wrap;
      margin-top: 14px;
    }
    .qz-step{
      padding:8px 12px;
      border-radius: 999px;
      border:1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.55);
      font-weight: 900;
      font-size: 13px;
      white-space: nowrap;
    }
    .qz-step.active{
      border-color: rgba(59,130,246,.35);
      background: rgba(59,130,246,.10);
      box-shadow: 0 0 0 4px rgba(59,130,246,.08);
    }

    /* Alert */
    .qz-alert{
      border-radius: 14px;
      padding: 12px 14px;
      margin: 12px 0 14px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.75);
      font-weight: 700;
    }
    .qz-alert.err{ border-color:#fecaca; background:#fff7ed; color:#7f1d1d; }
    .qz-alert.ok{ border-color:#bbf7d0; background:#f0fdf4; color:#14532d; }

    /* Card */
    .qz-card{
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.82);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 10px 30px rgba(15,23,42,.06);
    }

    /* Fields */
    .qz-field{ margin-bottom: 14px; }
    .qz-field label{
      display:block;
      font-weight: 900;
      font-size: 13px;
      margin-bottom: 8px;
      opacity: .9;
    }
    .qz-field input{
      width: 100%;
      border-radius: 12px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.92);
      padding: 12px 12px;
      font-size: 15px;
      outline: none;
    }
    .qz-field input:focus{
      border-color: rgba(59,130,246,.55);
      box-shadow: 0 0 0 4px rgba(59,130,246,.12);
    }
    .qz-help{ margin-top: 8px; font-size: 12px; opacity: .65; }

    /* Grid 2 cols for email/phone */
    .qz-grid2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 4px;
    }
    @media (max-width: 860px){
      .qz-h1{ font-size: 34px; }
      .qz-grid2{ grid-template-columns: 1fr; }
    }

    /* Actions */
    .qz-actions{ margin-top: 8px; }
    .qz-btn{
      display:inline-flex; align-items:center; justify-content:center;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.80);
      font-weight: 900;
      cursor: pointer;
    }
    .qz-btn.primary{
      border-color: rgba(37,99,235,.35);
      background: linear-gradient(135deg, rgba(37,99,235,.95), rgba(59,130,246,.85));
      color:#fff;
      box-shadow: 0 10px 22px rgba(37,99,235,.20);
    }

    /* Bottom note dashed */
    .qz-note{
      margin-top: 14px;
      border: 1px dashed rgba(148,163,184,.9);
      background: rgba(248,250,252,.7);
      padding: 12px 12px;
      border-radius: 14px;
      font-size: 13px;
      opacity: .85;
    }
  </style>
</section>
';

require __DIR__ . '/../layout.php';
exit;
