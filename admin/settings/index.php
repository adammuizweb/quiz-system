<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.settings.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$self = $base . '/?page=admin/quiz/settings';

$errors = [];
$success_msg = '';

$default_from_email = function_exists('quiz_default_sender_email') ? quiz_default_sender_email() : 'no-reply@example.com';
$current_from_name  = settings_get($pdo, 'quiz_email_from_name', 'Quiz') ?? 'Quiz';
$current_from_email = settings_get($pdo, 'quiz_email_from_address', $default_from_email) ?? $default_from_email;
$current_admin_wa   = settings_get($pdo, 'quiz_admin_wa', '') ?? '';
$otp_email_enabled  = (settings_get($pdo, 'quiz_otp_email_enabled', '1') ?? '1') !== '0';
$otp_wa_enabled     = (settings_get($pdo, 'quiz_otp_whatsapp_enabled', '0') ?? '0') !== '0';
$test_number_format = settings_get($pdo, 'quiz_test_number_format', 'QUIZ/{year}/{attempt_id:6}') ?? 'QUIZ/{year}/{attempt_id:6}';
$test_number_format = preg_replace('/\{id(?=[:}])/', '{attempt_id', $test_number_format) ?? $test_number_format;

$quiz_start_mode  = settings_get($pdo, 'quiz_start_mode', 'all') ?? 'all';
$quiz_start_ids   = settings_get($pdo, 'quiz_start_ids', '') ?? '';
$quiz_start_order = settings_get($pdo, 'quiz_start_order', 'default') ?? 'default';
$quiz_start_manual= settings_get($pdo, 'quiz_start_manual', '') ?? '';
$quiz_start_limit = settings_get($pdo, 'quiz_start_limit', '50') ?? '50';
$user = qz_admin_user($pdo);

$allQuizzes = [];
try {
  $sql = "SELECT q.id, q.title, q.is_active, q.is_primary FROM quizzes q";
  $params = [];
  if (!$user['can_manage_any']) {
    $sql .= " WHERE " . qz_quiz_access_sql($pdo, $user['id'], $params, 'q', 'settings_all_uid');
  }
  $st = $pdo->prepare($sql . " ORDER BY is_primary DESC, id ASC");
  $st->execute($params);
  $allQuizzes = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!adiwira_csrf_validate($token)) {
    $errors[] = 'Invalid CSRF token.';
  }

  $from_name  = trim((string)($_POST['quiz_email_from_name'] ?? ''));
  $from_email = trim((string)($_POST['quiz_email_from_address'] ?? ''));
  $admin_wa   = trim((string)($_POST['quiz_admin_wa'] ?? ''));
  $otp_email_enabled = isset($_POST['quiz_otp_email_enabled']);
  $otp_wa_enabled = isset($_POST['quiz_otp_whatsapp_enabled']);
  $test_number_format = trim((string)($_POST['quiz_test_number_format'] ?? ''));

  $current_from_name  = $from_name;
  $current_from_email = $from_email;
  $current_admin_wa   = $admin_wa;

  if ($otp_email_enabled && $from_name === '') $errors[] = 'Sender name cannot be empty when Email verification is enabled.';
  if ($otp_email_enabled && ($from_email === '' || !filter_var($from_email, FILTER_VALIDATE_EMAIL))) $errors[] = 'Valid sender email is required when Email verification is enabled.';
  if ($otp_wa_enabled && $admin_wa === '') $errors[] = 'Admin WhatsApp number cannot be empty when WhatsApp verification is enabled.';
  if (!function_exists('quiz_normalize_test_number_format') || quiz_normalize_test_number_format($test_number_format) === null) {
    $errors[] = 'Pola nomor test tidak valid. {attempt_id} atau {attempt_id:6} wajib ada agar nomor unik; {quiz_id} bersifat opsional.';
  }

  $quiz_start_mode  = trim((string)($_POST['quiz_start_mode'] ?? 'all'));
  $quiz_start_ids_arr = isset($_POST['quiz_start_ids']) && is_array($_POST['quiz_start_ids'])
    ? array_filter(array_map('intval', $_POST['quiz_start_ids']), fn($v) => $v > 0)
    : [];
  $quiz_start_ids   = !empty($quiz_start_ids_arr) ? implode(',', $quiz_start_ids_arr) : '';
  $quiz_start_order = trim((string)($_POST['quiz_start_order'] ?? 'default'));
  $quiz_start_manual= trim((string)($_POST['quiz_start_manual'] ?? ''));
  $quiz_start_limit = trim((string)($_POST['quiz_start_limit'] ?? '50'));

  if (!in_array($quiz_start_mode, ['all','selected','primary'], true)) $errors[] = 'Display mode tidak valid.';
  if ($quiz_start_mode === 'selected' && $quiz_start_ids === '') $errors[] = 'Pilih minimal satu quiz untuk mode Selected.';
  if (!in_array($quiz_start_order, ['default','id_asc','id_desc','title_asc','title_desc','manual'], true)) $errors[] = 'Urutan tidak valid.';
  $manual_ids = array_filter(array_map('trim', explode(',', $quiz_start_manual)), fn($v) => $v !== '');
  if ($quiz_start_order === 'manual' && empty($manual_ids)) $errors[] = 'Masukkan ID quiz untuk urutan manual.';
  $limit_int = (int)$quiz_start_limit;
  if ($limit_int < 1 || $limit_int > 999) $errors[] = 'Limit harus 1-999.';

  if (!$errors) {
    $ok1 = settings_set($pdo, 'quiz_email_from_name', $from_name, 1);
    $ok2 = settings_set($pdo, 'quiz_email_from_address', $from_email, 1);
    $ok3 = settings_set($pdo, 'quiz_admin_wa', $admin_wa, 1);
    $okEmailEnabled = settings_set($pdo, 'quiz_otp_email_enabled', $otp_email_enabled ? '1' : '0', 1);
    $okWaEnabled = settings_set($pdo, 'quiz_otp_whatsapp_enabled', $otp_wa_enabled ? '1' : '0', 1);
    $okTestNumber = settings_set($pdo, 'quiz_test_number_format', $test_number_format, 1);

    $ok4 = settings_set($pdo, 'quiz_start_mode', $quiz_start_mode, 1);
    $ok5 = settings_set($pdo, 'quiz_start_ids', $quiz_start_ids, 1);
    $ok6 = settings_set($pdo, 'quiz_start_order', $quiz_start_order, 1);
    $ok7 = settings_set($pdo, 'quiz_start_manual', $quiz_start_manual, 1);
    $ok8 = settings_set($pdo, 'quiz_start_limit', (string)$limit_int, 1);

    if ($ok1 && $ok2 && $ok3 && $okEmailEnabled && $okWaEnabled && $okTestNumber && $ok4 && $ok5 && $ok6 && $ok7 && $ok8) {
      if (function_exists('adiwira_redirect_with_flash')) {
        adiwira_redirect_with_flash($self, 'success', 'Quiz settings saved successfully.');
        exit;
      }
      $success_msg = 'Quiz settings saved successfully.';
    } else {
      $errors[] = 'Failed to save settings.';
    }
  }
}
?>
<section class="adam-card">
  <?php qz_admin_breadcrumb([['label'=>'Config']]); ?>
  <h2 style="margin:0 0 .4rem">Config</h2>
  <p class="muted" style="margin:0 0 1rem">Atur nomor test, metode OTP, kontak admin, dan tampilan halaman mulai quiz.</p>

  <?php if ($success_msg): ?>
    <div class="adam-success"><?= h($success_msg) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="adam-error"><ul><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="qz-settings-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        Nomor Test
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="form-group">
          <label for="quiz_test_number_format">Pola Nomor Test</label>
          <input type="text" name="quiz_test_number_format" id="quiz_test_number_format"
            value="<?= h($test_number_format) ?>" class="inp inp-w100"
            maxlength="60" placeholder="QUIZ/{year}/{attempt_id:6}">
          <div class="qz-format-guide">
            <p>Nomor test disusun otomatis dari pola berikut. Admin hanya menentukan polanya, bukan mengisi nomor setiap peserta satu per satu.</p>
            <div class="qz-format-tokens">
              <div><div class="qz-format-token-names"><code>{year}</code></div><strong>Tahun pembuatan</strong><span>Tahun saat nomor test dibuat, misalnya <b><?=date('Y')?></b>.</span></div>
              <div><div class="qz-format-token-names"><code>{quiz_id}</code><code>{quiz_id:3}</code></div><strong>ID quiz, opsional</strong><span>ID quiz yang sedang dikerjakan. Quiz ID 8 menjadi <b>8</b>, atau <b>008</b> jika memakai <code>:3</code>.</span></div>
              <div><div class="qz-format-token-names"><code>{attempt_id}</code><code>{attempt_id:6}</code></div><strong>Nomor pengerjaan, wajib</strong><span>ID unik untuk setiap sesi pengerjaan peserta. Attempt ID 123 menjadi <b>123</b>, atau <b>000123</b> jika memakai <code>:6</code>. Ini bukan ID quiz.</span></div>
            </div>
            <div class="qz-format-preview"><div><span>Contoh hasil</span><small>Quiz ID = 8, Attempt ID = 123</small></div><strong><?= h(function_exists('quiz_format_test_number') ? quiz_format_test_number($test_number_format, 123, null, 8) : 'QUIZ/'.date('Y').'/000123') ?></strong></div>
            <p class="muted"><code>{attempt_id}</code> wajib ada agar nomor tetap unik. <code>{quiz_id}</code> boleh ditambahkan jika dibutuhkan. Angka setelah titik dua menentukan jumlah digit minimum. Nomor test yang sudah tersimpan tidak ikut berubah.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        Metode Verifikasi OTP
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="qz-method-grid">
          <label class="qz-method-option">
            <input type="checkbox" name="quiz_otp_email_enabled" value="1" <?= $otp_email_enabled ? 'checked' : '' ?>>
            <span class="qz-method-copy">
              <strong>Email</strong>
              <small>Peserta dapat meminta OTP dikirim ke email.</small>
            </span>
          </label>
          <label class="qz-method-option">
            <input type="checkbox" name="quiz_otp_whatsapp_enabled" value="1" <?= $otp_wa_enabled ? 'checked' : '' ?>>
            <span class="qz-method-copy">
              <strong>WhatsApp</strong>
              <small>Peserta dapat meminta OTP melalui admin WhatsApp.</small>
            </span>
          </label>
        </div>
        <p class="muted" style="margin:10px 0 0">Jika keduanya dimatikan, peserta harus meminta kode OTP langsung kepada admin. OTP tetap tersedia di Attempt Detail.</p>
      </div>
    </div>

    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        OTP Email
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="form-group">
          <label for="quiz_email_from_name">Sender Name</label>
          <input type="text" name="quiz_email_from_name" id="quiz_email_from_name"
            value="<?= h($current_from_name) ?>"
            class="inp inp-w100" placeholder="Quiz">
        </div>

        <div class="form-group">
          <label for="quiz_email_from_address">Sender Email Address</label>
          <input type="email" name="quiz_email_from_address" id="quiz_email_from_address"
            value="<?= h($current_from_email) ?>"
            class="inp inp-w100" placeholder="no-reply@example.com">
        </div>
      </div>
    </div>

    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        Admin WhatsApp
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="form-group">
          <label for="quiz_admin_wa">Admin WhatsApp Number</label>
          <input type="text" name="quiz_admin_wa" id="quiz_admin_wa"
            value="<?= h($current_admin_wa) ?>"
            class="inp inp-w100" placeholder="628xxxxxxxxxx">
        </div>
      </div>
    </div>

    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        Tampilan Quiz (Start Page)
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="form-group">
          <label for="quiz_start_mode">Mode Tampilan</label>
          <select name="quiz_start_mode" id="quiz_start_mode" class="inp inp-w100"
            onchange="document.getElementById('quiz_start_ids_wrap').style.display=this.value==='selected'?'':'none'">
            <option value="all" <?= $quiz_start_mode === 'all' ? 'selected' : '' ?>>Semua Quiz Aktif</option>
            <option value="selected" <?= $quiz_start_mode === 'selected' ? 'selected' : '' ?>>Quiz Terpilih Saja</option>
            <option value="primary" <?= $quiz_start_mode === 'primary' ? 'selected' : '' ?>>Hanya Primary Quiz</option>
          </select>
        </div>
        <div class="form-group" id="quiz_start_ids_wrap" style="display:<?= $quiz_start_mode === 'selected' ? '' : 'none' ?>">
          <label>Pilih Quiz yang Ditampilkan</label>
          <?php
            $selIds = array_filter(array_map('trim', explode(',', $quiz_start_ids)), fn($v) => $v !== '');
            $selArr = [];
            foreach ($selIds as $sid) { $selArr[(int)$sid] = true; }
          ?>
          <div class="qz-check-grid">
            <?php if (empty($allQuizzes)): ?>
              <div class="muted" style="padding:8px">Belum ada quiz.</div>
            <?php else: foreach ($allQuizzes as $qz):
              $qid = (int)$qz['id'];
              $checked = isset($selArr[$qid]) ? ' checked' : '';
              $pri = (int)($qz['is_primary'] ?? 0) === 1;
              $act = (int)($qz['is_active'] ?? 0) === 1;
              $cls = $act ? 'badge--ok' : 'badge--muted';
            ?>
              <label class="qz-check-item<?= $checked ? ' active' : '' ?>">
                <input type="checkbox" name="quiz_start_ids[]" value="<?= $qid ?>"<?= $checked ?>>
                <span class="qz-check-title"><?= h($qz['title'] ?: "Quiz #{$qid}") ?></span>
                <span class="qz-check-meta">
                  <span class="badge mini <?= $cls ?>"><?= $act ? 'Aktif' : 'Nonaktif' ?></span>
                  <?php if ($pri): ?><span class="badge mini badge--primary">PRIMARY</span><?php endif; ?>
                  <span class="text-muted">#<?= $qid ?></span>
                </span>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="form-group">
          <label for="quiz_start_order">Urutan</label>
          <select name="quiz_start_order" id="quiz_start_order" class="inp inp-w100"
            onchange="document.getElementById('quiz_start_manual_wrap').style.display=this.value==='manual'?'':'none'">
            <option value="default" <?= $quiz_start_order === 'default' ? 'selected' : '' ?>>Default (Primary &bull; ID Desc)</option>
            <option value="id_asc" <?= $quiz_start_order === 'id_asc' ? 'selected' : '' ?>>ID Ascending</option>
            <option value="id_desc" <?= $quiz_start_order === 'id_desc' ? 'selected' : '' ?>>ID Descending</option>
            <option value="title_asc" <?= $quiz_start_order === 'title_asc' ? 'selected' : '' ?>>Judul A-Z</option>
            <option value="title_desc" <?= $quiz_start_order === 'title_desc' ? 'selected' : '' ?>>Judul Z-A</option>
            <option value="manual" <?= $quiz_start_order === 'manual' ? 'selected' : '' ?>>Manual (sesuai urutan ID)</option>
          </select>
        </div>
        <div class="form-group" id="quiz_start_manual_wrap" style="display:<?= $quiz_start_order === 'manual' ? '' : 'none' ?>">
          <label for="quiz_start_manual">Urutan Manual (ID quiz, pisahkan koma)</label>
          <input type="text" name="quiz_start_manual" id="quiz_start_manual"
            value="<?= h($quiz_start_manual) ?>"
            class="inp inp-w100" placeholder="3,1,5">
        </div>
        <div class="form-group">
          <label for="quiz_start_limit">Jumlah Maksimal Quiz Ditampilkan</label>
          <input type="number" name="quiz_start_limit" id="quiz_start_limit"
            value="<?= (int)$quiz_start_limit ?>"
            class="inp inp-w100" min="1" max="999" placeholder="50">
        </div>
      </div>
    </div>

    <div class="btn-row" style="margin-top:14px;margin-bottom:0;">
      <button type="submit" class="adam-button">
        Simpan
      </button>
    </div>
  </form>
</section>

<?php if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')): ?>
  <?php
    $items = [];
    foreach ($errors as $m) $items[] = ['type' => 'error', 'message' => (string)$m];
    echo adiwira_bootstrap_toasts_script($items);
  ?>
<?php endif; ?>
