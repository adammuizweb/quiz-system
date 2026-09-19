<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.view');
require __DIR__ . '/../../_init.php';
require_once __DIR__ . '/_monitoring.php';
qz_attempt_no_store();

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/attempts/view';
$user = qz_admin_user($pdo);

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// === accept alias or id (transition-safe) ===
$alias = trim((string)($_GET['a'] ?? $_GET['attempt_alias'] ?? $_GET['attempt'] ?? ''));
$attempt_id = (int)($_GET['attempt_id'] ?? 0);

if ($alias === '' && $attempt_id <= 0) {
  echo '<div class="adam-alert error">Missing attempt alias or attempt_id</div>';
  return;
}

$messages = [];
$errors   = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode($_GET['err']);
$flashMessage = qz_flash_take('msg');
if ($flashMessage !== '') $messages[] = $flashMessage;

// Resolve and authorize the attempt before loading participant PII.
if ($alias !== '') {
  $stmt = $pdo->prepare('SELECT id FROM quiz_attempts WHERE attempt_alias = :a LIMIT 1');
  $stmt->execute([':a'=>$alias]);
  $attempt_id = (int)$stmt->fetchColumn();
}
qz_require_attempt_access($pdo, $attempt_id);

$otpColumns = $user['can_view_attempt_otp']
  ? 'a.email_verification_code, a.otp_email_sent_at, a.otp_wa_sent_at,'
  : 'NULL AS email_verification_code, NULL AS otp_email_sent_at, NULL AS otp_wa_sent_at,';
$stmt = $pdo->prepare("
  SELECT
    a.id, a.attempt_alias, a.nomor_test, a.status, a.delivery_mode,
    a.identity_locked, a.contact_name, a.contact_email, a.contact_phone,
    a.email_verified_at, a.verified_via, a.reset_count,
    a.created_at, a.started_at, a.submitted_at, a.last_activity_at,
    {$otpColumns}
    s.name AS student_name, s.email AS student_email, s.phone AS student_phone,
    q.title AS quiz_title
  FROM quiz_attempts a
  JOIN students s ON s.id = a.student_id
  JOIN quizzes q ON q.id = a.quiz_id
  WHERE a.id = :id
  LIMIT 1
");
$stmt->execute([':id'=>$attempt_id]);
$A = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$A) {
  http_response_code(404);
  exit('Attempt not found');
}
$attempt_id = (int)$A['id'];
$alias = (string)($A['attempt_alias'] ?? '');
$attemptLabel = $alias !== '' ? $alias : '#' . $attempt_id;

/* =========================
   Identity (source of truth)
   - snapshot attempt: contact_*
   - fallback student (current)
   ========================= */
$dispName  = trim((string)($A['contact_name']  ?? ''));
$dispEmail = trim((string)($A['contact_email'] ?? ''));
$dispPhone = trim((string)($A['contact_phone'] ?? ''));

$studentName  = trim((string)($A['student_name']  ?? ''));
$studentEmail = trim((string)($A['student_email'] ?? ''));
$studentPhone = trim((string)($A['student_phone'] ?? ''));

if ($dispName === '')  $dispName  = $studentName;
if ($dispEmail === '') $dispEmail = $studentEmail;
if ($dispPhone === '') $dispPhone = $studentPhone;

$identityLocked = ((int)($A['identity_locked'] ?? 0) === 1);
$nomorTest = trim((string)($A['nomor_test'] ?? ''));
$deliveryMode = in_array((string)($A['delivery_mode'] ?? ''), ['online', 'onsite'], true)
  ? (string)$A['delivery_mode']
  : '';

// totals
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_questions WHERE attempt_id=:id");
$stmt->execute([':id'=>$attempt_id]);
$totalQ = (int)$stmt->fetchColumn();

$answeredCondition = qz_attempt_answered_condition('aa');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=:id AND {$answeredCondition}");
$stmt->execute([':id'=>$attempt_id]);
$answered = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(computed_score),0) FROM attempt_answers WHERE attempt_id=:id");
$stmt->execute([':id'=>$attempt_id]);
$totalScore = (int)$stmt->fetchColumn();

$items = [];
if ($user['can_view_attempt_responses']) {
  $stmt = $pdo->prepare("
    SELECT
      aq.seq,
      q.id AS question_id,
      q.question_text,
      q.question_type,
      aa.selected_answer_id,
      aa.answer_text,
      aa.computed_score,
      qa.answer_text AS selected_answer_text
    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id
    LEFT JOIN attempt_answers aa
      ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
    LEFT JOIN question_answers qa
      ON qa.id = aa.selected_answer_id
    WHERE aq.attempt_id = :id
    ORDER BY aq.seq ASC
  ");
  $stmt->execute([':id'=>$attempt_id]);
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function status_badge($st){
  $st = (string)$st;
  $map = [
    'registered'  => 'Registered',
    'verified'    => 'Verified',
    'in_progress' => 'In Progress',
    'submitted'   => 'Submitted',
    'expired'     => 'Expired',
  ];
  $label = $map[$st] ?? $st;
  $cls = str_replace('_', '-', 'badge badge--' . $st);
  return '<span class="'.h($cls).'">'.h($label).'</span>';
}

// ===== helpers: normalize phone -> 62xxxx =====
function phone_to_62($raw){
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  $p = preg_replace('/\D+/', '', $raw);
  if ($p === '') return '';
  if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
  if (strpos($p, '62') === 0) return $p;
  if (strpos($p, '8') === 0) return '62' . $p;
  return $p;
}

// ===== WA Reply Button (open WhatsApp) =====
$otp_now = trim((string)($A['email_verification_code'] ?? ''));
$hasMeaningfulOtp = $user['can_view_attempt_otp']
  && (string)($A['status'] ?? '') === 'registered'
  && empty($A['email_verified_at'])
  && preg_match('/^\d{6}$/', $otp_now) === 1;

$dispPhone62 = phone_to_62($dispPhone);
$waName = $dispName !== '' ? $dispName : 'Peserta';

$waMsg = "Hello {$waName}, This is your quiz verification OTP: {$otp_now}. \n"
       . "Please enter this OTP on the verification page. (Ref: {$attemptLabel})";

$waSendUrl = '';
if ($hasMeaningfulOtp && $dispPhone62 !== '') {
  $waSendUrl = "https://wa.me/" . $dispPhone62 . "?text=" . urlencode($waMsg);
}

// Link dashboard attempt (yang dikirim siswa ke admin) -> pakai alias
$adminAttemptUrl = $base . '/?page=' . $pluginPage
  . ($alias !== '' ? '&a=' . rawurlencode($alias) : '&attempt_id=' . $attempt_id);

// Status flags
$emailSentAt = $A['otp_email_sent_at'] ?? null;
$waSentAt    = $A['otp_wa_sent_at'] ?? null;
?>


<section class="adam-card">
  <h2>Attempt Detail</h2>
  <?php qz_admin_breadcrumb([['label'=>'Attempt','url'=>$base.'/?page=admin/quiz/attempts'],['label'=>$attemptLabel]]); ?>

  <?php if ($messages): ?>
    <div class="adam-alert success">
      <?php foreach ($messages as $m): ?><div><?= h($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="adam-alert error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div class="panel <?= ($user['can_operate_attempts'] || $user['can_delete_attempts'] || $hasMeaningfulOtp) ? 'col-8' : 'col-12' ?>">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
        <div>
          <div style="font-size:1.1rem;font-weight:800">
            <?= h($dispName) ?>
            <?php if ($identityLocked): ?>
              <span class="badge badge--expired">Locked</span>
            <?php endif; ?>
          </div>

          <div class="muted">
            <?= h($dispEmail !== '' ? $dispEmail : '—') ?>
            <?= $dispPhone !== '' ? ' • '.h($dispPhone) : '' ?>
          </div>
          
          <div class="muted">
            <strong>Nomor Test:</strong> <?= h($nomorTest !== '' ? $nomorTest : '—') ?>
        </div>

          <div style="margin-top:.35rem;font-weight:700"><?= h($A['quiz_title']) ?></div>
          <div class="muted"><strong>Alias:</strong> <?= h($alias !== '' ? $alias : '—') ?></div>

          <?php if ($studentName !== '' || $studentEmail !== '' || $studentPhone !== ''): ?>
            <div class="helpbox" style="margin-top:10px">
              <div class="title">Student Profile (Current)</div>
              <div class="muted">
                <?= h($studentName ?: '—') ?> • <?= h($studentEmail ?: '—') ?><?= $studentPhone ? ' • '.h($studentPhone) : '' ?>
              </div>
              <div class="muted">
                Catatan: Attempt pakai snapshot (contact_*). Student bisa berubah, tapi attempt tetap.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div style="text-align:right">
          <?= status_badge((string)$A['status']) ?>
          <div class="muted">Attempt ID #<?= (int)$attempt_id ?></div>
        </div>
      </div>

      <div class="kpi">
        <span class="pill"><strong><?= (int)$answered ?></strong>/<?= (int)$totalQ ?> dijawab</span>
        <span class="pill pill-score"><strong>Nilai:</strong> <?= (int)$totalScore ?></span>
        <span class="pill"><strong>Verified:</strong> <?= $A['email_verified_at'] ? h(($A['verified_via'] ?: 'email')) : '—' ?></span>
        <span class="pill"><strong>Reset:</strong> <?= (int)$A['reset_count'] ?>x</span>
        <span class="pill"><strong>Mode:</strong> <?= $deliveryMode !== '' ? h(ucfirst($deliveryMode)) : 'Belum diisi' ?></span>
        <?php if ($hasMeaningfulOtp): ?>
          <span class="pill"><strong>Email OTP:</strong> <?= $emailSentAt ? 'sent' : '—' ?></span>
          <span class="pill"><strong>WA Request:</strong> <?= $waSentAt ? 'yes' : '—' ?></span>
        <?php endif; ?>
      </div>

      <div class="helpbox">
        <div class="title">Waktu Attempt</div>
        <div class="muted">Dibuat: <?= h((string)($A['created_at'] ?? '—')) ?></div>
        <div class="muted">Mulai: <?= h((string)($A['started_at'] ?: '—')) ?></div>
        <div class="muted">Submit: <?= h((string)($A['submitted_at'] ?: '—')) ?></div>
        <div class="muted">Aktivitas terakhir: <?= h((string)($A['last_activity_at'] ?: '—')) ?></div>
      </div>

      <div class="helpbox">
        <div class="title">Link yang dikirim siswa ke Admin (anti-ID, pakai alias)</div>
        <div class="muted">Admin buka link ini (butuh login dashboard), lalu bantu kirim OTP via WhatsApp.</div>
        <div style="margin-top:8px">
          <input id="attemptLink" type="text" value="<?= h($adminAttemptUrl) ?>" readonly>
        </div>
        <div class="row">
          <button class="smallbtn" type="button" id="btnCopyLink">Copy Link</button>
        </div>
      </div>

      <?php if ($user['can_view_attempt_responses']): ?>
      <hr class="sep">
      <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>No</th>
            <th>Soal & Jawaban (Read-only)</th>
            <th>Skor</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="3" style="padding:1rem">Belum ada paket soal untuk attempt ini.</td></tr>
        <?php else: foreach ($items as $it): ?>
          <?php
            $ans = '—';
            if (($it['question_type'] === 'text' || $it['question_type'] === 'number')) {
              $ans = ($it['answer_text'] !== null && $it['answer_text'] !== '') ? $it['answer_text'] : '—';
            } else {
              $ans = ($it['selected_answer_text'] !== null && $it['selected_answer_text'] !== '') ? $it['selected_answer_text'] : '—';
            }
          ?>
          <tr>
            <td><?= (int)$it['seq'] ?></td>
            <td>
              <div style="font-weight:800;line-height:1.45"><?= nl2br(h($it['question_text'])) ?></div>
              <div class="muted"><strong>Jawab:</strong> <?= nl2br(h($ans)) ?></div>
              <div class="muted">Type: <?= h($it['question_type']) ?></div>
            </td>
            <td style="font-weight:900"><?= (int)$it['computed_score'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($user['can_operate_attempts'] || $user['can_delete_attempts'] || $hasMeaningfulOtp): ?>
    <div class="panel col-4">
      <h3 style="margin-top:0">Aksi Admin</h3>
      <p class="muted">Aksi yang tersedia mengikuti kapabilitas akun Anda.</p>

      <div class="form-actions" style="flex-direction:column">
      <?php if ($user['can_operate_attempts']): ?>
      <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_update_delivery_mode" class="helpbox">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
        <div class="title">Mode Pengerjaan</div>
        <select name="delivery_mode" class="adam-input" style="width:100%;margin-top:8px">
          <option value="" <?= $deliveryMode===''?'selected':'' ?>>Belum diisi</option>
          <option value="online" <?= $deliveryMode==='online'?'selected':'' ?>>Online</option>
          <option value="onsite" <?= $deliveryMode==='onsite'?'selected':'' ?>>Onsite</option>
        </select>
        <button class="adam-button" type="submit" style="margin-top:10px">Simpan Mode</button>
      </form>
      <?php endif; ?>

      <?php if ($hasMeaningfulOtp): ?>
      <div class="helpbox">
        <div class="title">Kirim OTP via WhatsApp ke Peserta (snapshot attempt)</div>
        <div class="muted">Klik tombol di bawah untuk buka WA dengan pesan siap kirim.</div>

        <div class="row" style="margin-top:10px">
          <?php if ($waSendUrl): ?>
            <a class="smallbtn" href="<?= h($waSendUrl) ?>" target="_blank" rel="noopener">
              Kirim OTP ke WA
            </a>
            <span class="muted">Tujuan: <strong><?= h($dispPhone) ?></strong></span>
          <?php else: ?>
            <button class="smallbtn" type="button" disabled>Nomor kosong / invalid</button>
            <span class="muted">Cek contact_phone / student_phone.</span>
          <?php endif; ?>
        </div>

        <div class="muted">
          OTP ada di DB kolom <code>quiz_attempts.email_verification_code</code>.
          OTP tidak berubah kecuali <strong>reset</strong> / <strong>generate OTP baru</strong>.
        </div>
      </div>

      <div class="helpbox">
        <div class="title">OTP Aktif Saat Ini</div>
        <div class="muted">Ini OTP yang sedang tersimpan di DB untuk attempt ini.</div>

        <div class="row" style="margin-top:10px;align-items:center">
          <span class="otpbox" id="otpNow"><?= h($otp_now) ?></span>
          <button class="smallbtn" type="button" id="btnCopyOtp">
            Copy OTP
          </button>
        </div>

        <div class="muted">
          Email OTP: <strong><?= $emailSentAt ? 'sent' : '—' ?></strong>
          • WA Request: <strong><?= $waSentAt ? 'yes' : '—' ?></strong>
        </div>

        <div class="muted">
          OTP berubah hanya jika <strong>Reset</strong> atau <strong>Generate OTP Baru</strong>.
        </div>
      </div>
      <?php endif; ?>

      <?php if ($user['can_operate_attempts']): ?>
      <hr class="sep">

      <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_generate_otp" style="margin-bottom:12px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
        <button class="adam-button" type="submit" data-confirm="Generate OTP baru? Status akan kembali ke registered.">
          Generate OTP Baru
        </button>
      </form>

      <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_verify_manual" style="margin-bottom:12px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">

        <label style="font-size:.9rem;font-weight:700">Verify via</label><br>
        <select name="verified_via" class="adam-input" style="width:100%;margin-top:6px">
          <option value="manual">manual</option>
          <option value="whatsapp">whatsapp</option>
          <option value="email">email</option>
        </select>

        <label style="font-size:.9rem;font-weight:700;margin-top:10px;display:block">Catatan</label>
        <input class="adam-input" type="text" name="verified_note" placeholder="Contoh: OTP dikirim via WA admin"
               style="width:100%;margin-top:6px">

        <button class="adam-button" type="submit" style="margin-top:10px"
          data-confirm="Verifikasi manual? Attempt akan menjadi verified.">
          Verify Manual
        </button>
      </form>

  <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_reset" style="flex:1">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">

    <label style="font-size:.9rem;font-weight:700">Alasan Reset</label>
    <input class="adam-input" type="text" name="reset_reason" placeholder="Contoh: salah device / email tidak masuk"
           style="width:100%;margin-top:6px" required>

    <button class="adam-button btn-danger" type="submit" style="margin-top:10px"
      data-confirm="Reset attempt? Jawaban &amp; paket soal akan dihapus." data-confirm-variant="danger">
      Reset Attempt
    </button>
  </form>

  <?php endif; ?>
  <?php if ($user['can_delete_attempts']): ?>
  <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_delete" style="flex:1;margin-bottom:12px">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
    <input type="hidden" name="redirect" value="<?= h($base) ?>/?page=admin/quiz/attempts">

    <button class="adam-button btn-danger" type="submit"
      data-confirm="Hapus attempt <?= h($attemptLabel) ?> ?\n\nIni akan menghapus: paket soal, jawaban, dan proctor logs (CASCADE).\nTindakan tidak bisa dibatalkan." data-confirm-variant="danger">
      Delete Attempt
    </button>

    <div class="muted">
      Catatan: delete attempt akan menghapus anak tabel via FK CASCADE.
    </div>
  </form>
  <?php endif; ?>
</div>

      <hr class="sep">
      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/attempts">← Kembali ke List</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<script>
(function(){
  function copyFromInput(id, btnId){
    const inp = document.getElementById(id);
    const btn = document.getElementById(btnId);
    if(!inp || !btn) return;

    btn.addEventListener('click', async function(){
      try{
        inp.focus();
        inp.select();
        inp.setSelectionRange(0, 99999);
        await navigator.clipboard.writeText(inp.value);
        const old = btn.textContent;
        btn.textContent = '✅ Tercopy';
        setTimeout(()=>btn.textContent=old, 1100);
      }catch(e){
        try{ document.execCommand('copy'); }catch(_){}
        const old = btn.textContent;
        btn.textContent = '✅ Tercopy';
        setTimeout(()=>btn.textContent=old, 1100);
      }
    });
  }

  copyFromInput('attemptLink', 'btnCopyLink');

  const btnOtp = document.getElementById('btnCopyOtp');
  const otpEl = document.getElementById('otpNow');
  if(btnOtp && otpEl && !btnOtp.disabled){
    btnOtp.addEventListener('click', async function(){
      const txt = (otpEl.textContent || '').trim();
      if(!txt || txt === '______') return;
      try{
        await navigator.clipboard.writeText(txt);
      }catch(e){
        const ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        try{ document.execCommand('copy'); }catch(_){}
        document.body.removeChild(ta);
      }
      const old = btnOtp.textContent;
      btnOtp.textContent = '✅ Tercopy';
      setTimeout(()=>btnOtp.textContent=old, 1100);
    });
  }
})();
</script>
