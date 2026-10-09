<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.settings.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$self = $base . '/?page=admin/quiz/settings';

$errors = [];
$error_targets = [];
$field_errors = [];
$success_msg = '';
$add_error = static function (string $message, ?string $field_id = null) use (&$errors, &$error_targets, &$field_errors): void {
  $index = count($errors);
  $errors[] = $message;
  if ($field_id !== null) {
    $error_targets[$index] = $field_id;
    $field_errors[$field_id] = $message;
  }
};

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
    $add_error('Invalid CSRF token. Reload the page and try again.');
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

  if ($otp_email_enabled && $from_name === '') $add_error('Sender name is required when email verification is active.', 'quiz_email_from_name');
  if ($otp_email_enabled && ($from_email === '' || !filter_var($from_email, FILTER_VALIDATE_EMAIL))) $add_error('A valid sender email address is required when email verification is active.', 'quiz_email_from_address');
  if ($otp_wa_enabled && $admin_wa === '') $add_error('The administrator WhatsApp number is required when WhatsApp verification is active.', 'quiz_admin_wa');
  if (!function_exists('quiz_normalize_test_number_format') || quiz_normalize_test_number_format($test_number_format) === null) {
    $add_error('Invalid Test Number format. {attempt_id} or {attempt_id:6} is required for uniqueness; {quiz_id} is optional.', 'quiz_test_number_format');
  }

  $quiz_start_mode  = trim((string)($_POST['quiz_start_mode'] ?? 'all'));
  $quiz_start_ids_arr = isset($_POST['quiz_start_ids']) && is_array($_POST['quiz_start_ids'])
    ? array_filter(array_map('intval', $_POST['quiz_start_ids']), fn($v) => $v > 0)
    : [];
  $quiz_start_ids   = !empty($quiz_start_ids_arr) ? implode(',', $quiz_start_ids_arr) : '';
  $quiz_start_order = trim((string)($_POST['quiz_start_order'] ?? 'default'));
  $quiz_start_manual= trim((string)($_POST['quiz_start_manual'] ?? ''));
  $quiz_start_limit = trim((string)($_POST['quiz_start_limit'] ?? '50'));

  if (!in_array($quiz_start_mode, ['all','selected','primary'], true)) $add_error('Invalid display mode.', 'quiz_start_mode');
  if ($quiz_start_mode === 'selected' && $quiz_start_ids === '') $add_error('Select at least one quiz for Selected Quizzes mode.', 'quiz_start_ids_wrap');
  if (!in_array($quiz_start_order, ['default','id_asc','id_desc','title_asc','title_desc','manual'], true)) $add_error('Invalid display order.', 'quiz_start_order');
  $manual_ids = array_filter(array_map('trim', explode(',', $quiz_start_manual)), fn($v) => $v !== '');
  if ($quiz_start_order === 'manual' && empty($manual_ids)) $add_error('Enter quiz IDs for manual ordering.', 'quiz_start_manual');
  $limit_int = (int)$quiz_start_limit;
  if ($limit_int < 1 || $limit_int > 999) $add_error('The maximum number must be between 1 and 999.', 'quiz_start_limit');

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
        adiwira_redirect_with_flash($self, 'success', 'Quiz Settings saved successfully.');
        exit;
      }
      $success_msg = 'Quiz Settings saved successfully.';
    } else {
      $add_error('Quiz Settings could not be saved. Try again.');
    }
  }
}

$selected_quiz_ids = array_filter(array_map('trim', explode(',', $quiz_start_ids)), fn($v) => $v !== '');
$selected_quizzes = [];
foreach ($selected_quiz_ids as $selected_quiz_id) {
  $selected_quizzes[(int)$selected_quiz_id] = true;
}

$start_mode_labels = [
  'all' => 'All active quizzes',
  'selected' => 'Selected quizzes',
  'primary' => 'Primary Quiz only',
];
$enabled_methods = [];
if ($otp_email_enabled) $enabled_methods[] = 'Email';
if ($otp_wa_enabled) $enabled_methods[] = 'WhatsApp';
$verification_summary = $enabled_methods ? implode(' + ', $enabled_methods) : 'Code from administrator';
$test_number_preview = function_exists('quiz_format_test_number')
  ? quiz_format_test_number($test_number_format, 123, null, 8)
  : 'QUIZ/'.date('Y').'/000123';
?>
<section class="adam-card qz-shell">
  <?php qz_admin_breadcrumb([['label'=>'Quiz Settings']]); ?>

  <header class="qz-page-head">
    <div class="qz-page-head__copy">
      <p class="qz-eyebrow">Operational configuration</p>
      <h1 class="qz-page-title">Quiz Settings</h1>
      <p class="qz-page-description">Manage the Test Number format, Participant Verification channels, and quizzes available on the Start Page. Saved changes apply to subsequent sessions.</p>
    </div>
    <div class="qz-page-actions">
      <button type="submit" class="adam-button" form="qz-settings-form">Save changes</button>
    </div>
  </header>

  <aside class="qz-settings-summary" aria-label="Current form values">
    <dl>
      <div>
        <dt>Participant Verification</dt>
        <dd data-qz-settings-summary="verification"><?= h($verification_summary) ?></dd>
      </div>
      <div>
        <dt>Start Page display</dt>
        <dd data-qz-settings-summary="start"><?= h($start_mode_labels[$quiz_start_mode] ?? 'Invalid configuration') ?>, up to <?= (int)$quiz_start_limit ?> quizzes</dd>
      </div>
      <div>
        <dt>Sample Test Number</dt>
        <dd data-qz-settings-summary="number"><?= h($test_number_preview) ?></dd>
      </div>
    </dl>
  </aside>

  <?php if ($success_msg): ?>
    <div class="adam-success qz-alert" role="status"><?= h($success_msg) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="adam-error qz-alert" role="alert" aria-labelledby="qz-settings-errors-title" tabindex="-1">
      <h2 id="qz-settings-errors-title">Quiz Settings could not be saved</h2>
      <ul>
        <?php foreach ($errors as $index => $error): ?>
          <li>
            <?php if (isset($error_targets[$index])): ?>
              <a href="#<?= h($error_targets[$index]) ?>"><?= h($error) ?></a>
            <?php else: ?>
              <?= h($error) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" id="qz-settings-form" class="qz-settings-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <section class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true" aria-controls="qz-number-section">
        <span class="qz-section-heading">
          <span class="qz-section-title">Test Number</span>
          <span class="qz-section-description">Set a unique Test Number format for each attempt.</span>
        </span>
        <span class="chevron" aria-hidden="true">&#8250;</span>
      </button>
      <div class="settings-section-body" id="qz-number-section">
        <div class="qz-field">
          <label for="quiz_test_number_format">Test Number format</label>
          <input type="text" name="quiz_test_number_format" id="quiz_test_number_format"
            value="<?= h($test_number_format) ?>" class="inp inp-w100"
            maxlength="60" placeholder="QUIZ/{year}/{attempt_id:6}"
            aria-describedby="quiz_test_number_format_help<?= isset($field_errors['quiz_test_number_format']) ? ' quiz_test_number_format_error' : '' ?>"
            <?= isset($field_errors['quiz_test_number_format']) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($field_errors['quiz_test_number_format'])): ?>
            <p class="qz-field-error" id="quiz_test_number_format_error"><?= h($field_errors['quiz_test_number_format']) ?></p>
          <?php endif; ?>
          <div class="qz-format-guide" id="quiz_test_number_format_help">
            <p>Test Numbers are generated automatically from this format. Existing Test Numbers do not change when the format is updated.</p>
            <div class="qz-format-tokens">
              <div><div class="qz-format-token-names"><code>{year}</code></div><strong>Year created</strong><span>The year the number is generated, for example <b><?= date('Y') ?></b>.</span></div>
              <div><div class="qz-format-token-names"><code>{quiz_id}</code><code>{quiz_id:3}</code></div><strong>Quiz ID, optional</strong><span>Quiz ID 8 produces <b>8</b>, or <b>008</b> with the <code>:3</code> format.</span></div>
              <div><div class="qz-format-token-names"><code>{attempt_id}</code><code>{attempt_id:6}</code></div><strong>Attempt ID, required</strong><span>Attempt ID 123 produces <b>123</b>, or <b>000123</b> with the <code>:6</code> format.</span></div>
            </div>
            <div class="qz-format-preview"><div><span>Test Number preview</span><small>Quiz ID 8, Attempt ID 123</small></div><strong><?= h($test_number_preview) ?></strong></div>
            <p><code>{attempt_id}</code> is required to ensure uniqueness. The number after the colon sets the minimum number of digits.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true" aria-controls="qz-verification-section">
        <span class="qz-section-heading">
          <span class="qz-section-title">Participant Verification</span>
          <span class="qz-section-description">Choose OTP channels and provide a recognizable sender identity.</span>
        </span>
        <span class="chevron" aria-hidden="true">&#8250;</span>
      </button>
      <div class="settings-section-body" id="qz-verification-section">
        <fieldset class="qz-field qz-fieldset" aria-describedby="qz-method-help">
          <legend>Available OTP channels</legend>
          <div class="qz-method-grid">
            <label class="qz-method-option">
              <input type="checkbox" name="quiz_otp_email_enabled" value="1" <?= $otp_email_enabled ? 'checked' : '' ?>>
              <span class="qz-method-copy">
                <strong>Email</strong>
                <small>Send the OTP directly to the participant's email address.</small>
              </span>
            </label>
            <label class="qz-method-option">
              <input type="checkbox" name="quiz_otp_whatsapp_enabled" value="1" <?= $otp_wa_enabled ? 'checked' : '' ?>>
              <span class="qz-method-copy">
                <strong>Administrator WhatsApp</strong>
                <small>Participants request the OTP through the administrator's number.</small>
              </span>
            </label>
          </div>
          <p class="qz-operational-note" id="qz-method-help">If both channels are inactive, participants must request the code directly from an administrator. The OTP remains available in Attempt Details.</p>
        </fieldset>

        <div class="qz-subsection">
          <h3>Sender identity and contact</h3>
          <p>These values can be configured even when the related channel is inactive.</p>
        </div>
        <div class="qz-form-grid">
          <div class="qz-field">
            <label for="quiz_email_from_name">Email sender name</label>
            <input type="text" name="quiz_email_from_name" id="quiz_email_from_name"
              value="<?= h($current_from_name) ?>" class="inp inp-w100" placeholder="Quiz"
              aria-describedby="quiz_email_from_name_help<?= isset($field_errors['quiz_email_from_name']) ? ' quiz_email_from_name_error' : '' ?>"
              <?= isset($field_errors['quiz_email_from_name']) ? 'aria-invalid="true"' : '' ?>>
            <p class="qz-field-help" id="quiz_email_from_name_help">The name shown in the participant's inbox.</p>
            <?php if (isset($field_errors['quiz_email_from_name'])): ?>
              <p class="qz-field-error" id="quiz_email_from_name_error"><?= h($field_errors['quiz_email_from_name']) ?></p>
            <?php endif; ?>
          </div>

          <div class="qz-field">
            <label for="quiz_email_from_address">Sender email address</label>
            <input type="email" name="quiz_email_from_address" id="quiz_email_from_address"
              value="<?= h($current_from_email) ?>" class="inp inp-w100" placeholder="no-reply@example.com"
              aria-describedby="quiz_email_from_address_help<?= isset($field_errors['quiz_email_from_address']) ? ' quiz_email_from_address_error' : '' ?>"
              <?= isset($field_errors['quiz_email_from_address']) ? 'aria-invalid="true"' : '' ?>>
            <p class="qz-field-help" id="quiz_email_from_address_help">Use a valid sender address managed by your organization.</p>
            <?php if (isset($field_errors['quiz_email_from_address'])): ?>
              <p class="qz-field-error" id="quiz_email_from_address_error"><?= h($field_errors['quiz_email_from_address']) ?></p>
            <?php endif; ?>
          </div>

          <div class="qz-field">
            <label for="quiz_admin_wa">Administrator WhatsApp number</label>
            <input type="text" name="quiz_admin_wa" id="quiz_admin_wa"
              value="<?= h($current_admin_wa) ?>" class="inp inp-w100" placeholder="15551234567"
              aria-describedby="quiz_admin_wa_help<?= isset($field_errors['quiz_admin_wa']) ? ' quiz_admin_wa_error' : '' ?>"
              <?= isset($field_errors['quiz_admin_wa']) ? 'aria-invalid="true"' : '' ?>>
            <p class="qz-field-help" id="quiz_admin_wa_help">Use the international format without a plus sign, spaces, or hyphens.</p>
            <?php if (isset($field_errors['quiz_admin_wa'])): ?>
              <p class="qz-field-error" id="quiz_admin_wa_error"><?= h($field_errors['quiz_admin_wa']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true" aria-controls="qz-start-section">
        <span class="qz-section-heading">
          <span class="qz-section-title">Start Page</span>
          <span class="qz-section-description">Choose which quizzes are visible and set their display order.</span>
        </span>
        <span class="chevron" aria-hidden="true">&#8250;</span>
      </button>
      <div class="settings-section-body" id="qz-start-section">
        <div class="qz-form-grid">
          <div class="qz-field">
            <label for="quiz_start_mode">Quizzes to display</label>
            <select name="quiz_start_mode" id="quiz_start_mode" class="inp inp-w100"
              data-qz-controls="quiz_start_ids_wrap" data-qz-show-value="selected"
              aria-describedby="quiz_start_mode_help<?= isset($field_errors['quiz_start_mode']) ? ' quiz_start_mode_error' : '' ?>"
              <?= isset($field_errors['quiz_start_mode']) ? 'aria-invalid="true"' : '' ?>>
              <option value="all" <?= $quiz_start_mode === 'all' ? 'selected' : '' ?>>All active quizzes</option>
              <option value="selected" <?= $quiz_start_mode === 'selected' ? 'selected' : '' ?>>Selected quizzes only</option>
              <option value="primary" <?= $quiz_start_mode === 'primary' ? 'selected' : '' ?>>Primary Quiz only</option>
            </select>
            <p class="qz-field-help" id="quiz_start_mode_help">Inactive quizzes cannot be started even when selected.</p>
            <?php if (isset($field_errors['quiz_start_mode'])): ?>
              <p class="qz-field-error" id="quiz_start_mode_error"><?= h($field_errors['quiz_start_mode']) ?></p>
            <?php endif; ?>
          </div>

          <div class="qz-field">
            <label for="quiz_start_order">Display order</label>
            <select name="quiz_start_order" id="quiz_start_order" class="inp inp-w100"
              data-qz-controls="quiz_start_manual_wrap" data-qz-show-value="manual"
              aria-describedby="quiz_start_order_help<?= isset($field_errors['quiz_start_order']) ? ' quiz_start_order_error' : '' ?>"
              <?= isset($field_errors['quiz_start_order']) ? 'aria-invalid="true"' : '' ?>>
              <option value="default" <?= $quiz_start_order === 'default' ? 'selected' : '' ?>>Default: Primary Quiz, then newest ID</option>
              <option value="id_asc" <?= $quiz_start_order === 'id_asc' ? 'selected' : '' ?>>Smallest to largest ID</option>
              <option value="id_desc" <?= $quiz_start_order === 'id_desc' ? 'selected' : '' ?>>Largest to smallest ID</option>
              <option value="title_asc" <?= $quiz_start_order === 'title_asc' ? 'selected' : '' ?>>Title A-Z</option>
              <option value="title_desc" <?= $quiz_start_order === 'title_desc' ? 'selected' : '' ?>>Title Z-A</option>
              <option value="manual" <?= $quiz_start_order === 'manual' ? 'selected' : '' ?>>Manual by ID list</option>
            </select>
            <p class="qz-field-help" id="quiz_start_order_help">The order is applied before the quiz limit.</p>
            <?php if (isset($field_errors['quiz_start_order'])): ?>
              <p class="qz-field-error" id="quiz_start_order_error"><?= h($field_errors['quiz_start_order']) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <fieldset class="qz-field qz-fieldset qz-start-selection" id="quiz_start_ids_wrap"
          data-qz-conditional <?= $quiz_start_mode === 'selected' ? '' : 'hidden' ?>
          aria-describedby="quiz_start_ids_help<?= isset($field_errors['quiz_start_ids_wrap']) ? ' quiz_start_ids_error' : '' ?>"
          <?= isset($field_errors['quiz_start_ids_wrap']) ? 'aria-invalid="true"' : '' ?>>
          <legend>Select quizzes to display</legend>
          <p class="qz-field-help" id="quiz_start_ids_help">Inactive selections remain saved but are unavailable to participants until activated.</p>
          <?php if (isset($field_errors['quiz_start_ids_wrap'])): ?>
            <p class="qz-field-error" id="quiz_start_ids_error"><?= h($field_errors['quiz_start_ids_wrap']) ?></p>
          <?php endif; ?>
          <div class="qz-check-grid">
            <?php if (empty($allQuizzes)): ?>
              <div class="qz-empty-state" role="status">No quizzes are available for selection.</div>
            <?php else: foreach ($allQuizzes as $qz):
              $qid = (int)$qz['id'];
              $checked = isset($selected_quizzes[$qid]) ? ' checked' : '';
              $pri = (int)($qz['is_primary'] ?? 0) === 1;
              $act = (int)($qz['is_active'] ?? 0) === 1;
              $cls = $act ? 'badge--ok' : 'badge--muted';
            ?>
              <label class="qz-check-item<?= $checked ? ' active' : '' ?>">
                <input type="checkbox" name="quiz_start_ids[]" value="<?= $qid ?>"<?= $checked ?>>
                <span class="qz-check-title"><?= h($qz['title'] ?: "Quiz #{$qid}") ?></span>
                <span class="qz-check-meta">
                  <span class="badge mini <?= $cls ?>"><?= $act ? 'Active' : 'Inactive' ?></span>
                  <?php if ($pri): ?><span class="badge mini badge--primary">Primary Quiz</span><?php endif; ?>
                  <span class="text-muted">#<?= $qid ?></span>
                </span>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </fieldset>

        <div class="qz-form-grid qz-start-options">
          <div class="qz-field" id="quiz_start_manual_wrap" data-qz-conditional <?= $quiz_start_order === 'manual' ? '' : 'hidden' ?>>
            <label for="quiz_start_manual">ID list for manual ordering</label>
            <input type="text" name="quiz_start_manual" id="quiz_start_manual"
              value="<?= h($quiz_start_manual) ?>" class="inp inp-w100" placeholder="3,1,5"
              aria-describedby="quiz_start_manual_help<?= isset($field_errors['quiz_start_manual']) ? ' quiz_start_manual_error' : '' ?>"
              <?= isset($field_errors['quiz_start_manual']) ? 'aria-invalid="true"' : '' ?>>
            <p class="qz-field-help" id="quiz_start_manual_help">Separate quiz IDs with commas. The order is read from left to right.</p>
            <?php if (isset($field_errors['quiz_start_manual'])): ?>
              <p class="qz-field-error" id="quiz_start_manual_error"><?= h($field_errors['quiz_start_manual']) ?></p>
            <?php endif; ?>
          </div>

          <div class="qz-field">
            <label for="quiz_start_limit">Maximum number of quizzes</label>
            <input type="number" name="quiz_start_limit" id="quiz_start_limit"
              value="<?= (int)$quiz_start_limit ?>" class="inp inp-w100" min="1" max="999" placeholder="50"
              aria-describedby="quiz_start_limit_help<?= isset($field_errors['quiz_start_limit']) ? ' quiz_start_limit_error' : '' ?>"
              <?= isset($field_errors['quiz_start_limit']) ? 'aria-invalid="true"' : '' ?>>
            <p class="qz-field-help" id="quiz_start_limit_help">Enter a value from 1 to 999 to limit the list length.</p>
            <?php if (isset($field_errors['quiz_start_limit'])): ?>
              <p class="qz-field-error" id="quiz_start_limit_error"><?= h($field_errors['quiz_start_limit']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <div class="qz-form-actions">
      <p>Review Participant Verification channels and the Start Page display before applying changes.</p>
      <button type="submit" class="adam-button">Save changes</button>
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
