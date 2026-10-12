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
  echo '<div class="adam-alert error">Attempt alias or ID is required.</div>';
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
    a.created_at, a.started_at, a.submitted_at, a.elapsed_seconds, a.last_activity_at,
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
$manualVerificationEligible = in_array((string)($A['status'] ?? ''), ['registered', 'verified'], true)
  && empty($A['started_at']) && empty($A['submitted_at']);

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
$canCorrectResponses = $user['can_view_attempt_responses']
  && $user['can_correct_attempt_responses']
  && (string)($A['status'] ?? '') === 'submitted'
  && !empty($A['submitted_at'])
  && quiz_attempt_package_is_complete($pdo, $attempt_id)
  && quiz_attempt_snapshots_predate_submission($pdo, $attempt_id, (string)$A['submitted_at']);

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
$revisionsByAnswer = [];
$optionLabels = [];
$snapshotOptions = [];
$legacyOptionLabels = [];
if ($user['can_view_attempt_responses']) {
  $stmt = $pdo->prepare("
    SELECT
      aq.seq,
      aq.delivery_snapshot_json,
      aq.options_snapshotted_at,
      q.id AS question_id,
      q.question_text,
      q.question_type,
      aa.selected_answer_id,
      aa.id AS attempt_answer_id,
      aa.revision_count,
      aa.answer_text,
      aa.computed_score,
      aa.answered_at,
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
  foreach ($items as &$item) {
    $delivery = json_decode((string)($item['delivery_snapshot_json'] ?? ''), true);
    if (is_array($delivery) && (int)($delivery['version'] ?? 0) === 1 && is_array($delivery['question'] ?? null)) {
      $item['question_text'] = (string)($delivery['question']['text'] ?? '');
      $item['question_type'] = (string)($delivery['question']['type'] ?? '');
    }
  }
  unset($item);
  $optionStmt = $pdo->prepare('SELECT question_id, source_answer_id, answer_text, score, delivery_seq FROM attempt_question_options
    WHERE attempt_id=:id ORDER BY attempt_question_id ASC, delivery_seq ASC');
  $optionStmt->execute([':id' => $attempt_id]);
  foreach ($optionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $option) {
    $questionId = (int)$option['question_id'];
    $sourceAnswerId = (int)$option['source_answer_id'];
    $optionLabels[$questionId][$sourceAnswerId] = (string)$option['answer_text'];
    $snapshotOptions[$questionId][] = [
      'id' => $sourceAnswerId,
      'label' => (string)$option['answer_text'],
      'score' => (int)$option['score'],
      'delivery_order' => (int)$option['delivery_seq'],
    ];
  }
  $legacyOptionStmt = $pdo->prepare('SELECT qa.question_id, qa.id, qa.answer_text FROM question_answers qa
    JOIN attempt_questions aq ON aq.question_id=qa.question_id AND aq.attempt_id=:id ORDER BY qa.id ASC');
  $legacyOptionStmt->execute([':id' => $attempt_id]);
  foreach ($legacyOptionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $option) {
    $legacyOptionLabels[(int)$option['question_id']][(int)$option['id']] = (string)$option['answer_text'];
  }
  $revisionStmt = $pdo->prepare("SELECT r.attempt_answer_id, r.question_id, r.revision_number,
      r.selected_answer_id, r.answer_text, r.computed_score,
      r.change_source, r.change_reason, r.answered_at, r.created_at,
      u.name AS changed_by_name, u.email AS changed_by_email,
      qa.answer_text AS selected_answer_text
    FROM attempt_answer_revisions r
    LEFT JOIN question_answers qa ON qa.id=r.selected_answer_id
    LEFT JOIN users u ON u.id=r.changed_by_user_id
    WHERE r.attempt_id=:id ORDER BY r.attempt_answer_id ASC, r.revision_number ASC");
  $revisionStmt->execute([':id' => $attempt_id]);
  foreach ($revisionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $revision) {
    $revisionsByAnswer[(int)$revision['attempt_answer_id']][] = $revision;
  }
}

$formatAttemptResponse = static function (int $questionId, string $type, mixed $selectedId, mixed $answerText, mixed $liveLabel = null)
    use ($optionLabels, $legacyOptionLabels): string {
  $labels = $optionLabels[$questionId] ?? $legacyOptionLabels[$questionId] ?? [];
  if ($type === 'multiple_choice') {
    $ids = json_decode((string)$answerText, true);
    if (!is_array($ids) || $ids === []) return '—';
    $resolved = [];
    foreach ($ids as $id) {
      $sourceId = (int)$id;
      if ($sourceId > 0) $resolved[] = $labels[$sourceId] ?? ('Option #' . $sourceId);
    }
    return $resolved === [] ? '—' : implode('; ', $resolved);
  }
  if (in_array($type, ['single_choice', 'yesno'], true)) {
    $sourceId = (int)$selectedId;
    if ($sourceId <= 0) {
      $snapshottedSourceId = filter_var(trim((string)$answerText), FILTER_VALIDATE_INT);
      if ($snapshottedSourceId !== false && $snapshottedSourceId > 0) $sourceId = $snapshottedSourceId;
    }
    if ($sourceId <= 0) return '—';
    return (string)($labels[$sourceId] ?? (is_string($liveLabel) && $liveLabel !== '' ? $liveLabel : 'Option #' . $sourceId));
  }
  return trim((string)$answerText) !== '' ? (string)$answerText : '—';
};

$formatQuestionType = static fn(string $type): string => match ($type) {
  'single_choice' => 'Single choice',
  'multiple_choice' => 'Multiple choice',
  'yesno' => 'Yes/No',
  'text' => 'Text',
  'number' => 'Number',
  default => ucfirst(str_replace('_', ' ', $type)),
};

$correctionPayload = ['attempt_id' => $attempt_id, 'total_questions' => $totalQ, 'questions' => []];
if ($canCorrectResponses) {
  foreach ($items as $item) {
    $questionId = (int)$item['question_id'];
    $type = (string)$item['question_type'];
    $selectedAnswerId = (int)($item['selected_answer_id'] ?? 0);
    if ($selectedAnswerId <= 0 && in_array($type, ['single_choice', 'yesno'], true)) {
      $storedId = filter_var(trim((string)($item['answer_text'] ?? '')), FILTER_VALIDATE_INT);
      if ($storedId !== false && $storedId > 0) $selectedAnswerId = (int)$storedId;
    }
    $selectedAnswerIds = [];
    if ($type === 'multiple_choice') {
      $storedIds = json_decode((string)($item['answer_text'] ?? ''), true);
      if (is_array($storedIds)) {
        foreach ($storedIds as $storedId) {
          $storedId = (int)$storedId;
          if ($storedId > 0 && !in_array($storedId, $selectedAnswerIds, true)) $selectedAnswerIds[] = $storedId;
        }
      }
    }
    $answerLabel = $formatAttemptResponse($questionId, $type, $item['selected_answer_id'], $item['answer_text'], $item['selected_answer_text']);
    $correctionPayload['questions'][(string)$questionId] = [
      'question_id' => $questionId,
      'question_text' => (string)$item['question_text'],
      'question_type' => $type,
      'correction_eligible' => !empty($item['options_snapshotted_at'])
        && (string)$item['options_snapshotted_at'] < (string)$A['submitted_at'],
      'attempt_answer_id' => (int)($item['attempt_answer_id'] ?? 0),
      'revision_count' => (int)($item['revision_count'] ?? 0),
      'current_response' => [
        'selected_answer_id' => $selectedAnswerId > 0 ? $selectedAnswerId : null,
        'selected_answer_ids' => $selectedAnswerIds,
        'answer_text' => in_array($type, ['text', 'number'], true) ? (string)($item['answer_text'] ?? '') : null,
        'answer_label' => $answerLabel,
        'computed_score' => (int)($item['computed_score'] ?? 0),
        'answered_at' => $item['answered_at'] === null ? null : (string)$item['answered_at'],
      ],
      'options' => $snapshotOptions[$questionId] ?? [],
    ];
  }
}

function status_badge($st){
  $st = (string)$st;
  $map = [
    'registered'  => 'Registered',
    'verified'    => 'Verified',
    'in_progress' => 'In progress',
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
$waName = $dispName !== '' ? $dispName : 'Student';

$waMsg = "Hello {$waName}, your quiz verification OTP is {$otp_now}. \n"
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
$showAttemptOperations = $user['can_operate_attempts'] || $user['can_delete_attempts'] || $hasMeaningfulOtp;
?>


<section class="adam-card qz-card qz-shell qz-attempt-detail-page">
  <?php qz_admin_breadcrumb([['label'=>'Attempts','url'=>$base.'/?page=admin/quiz/attempts'],['label'=>$attemptLabel]]); ?>

  <header class="qz-head qz-page-head">
    <div class="qz-page-head__copy">
      <div class="qz-kicker qz-eyebrow">Attempt details</div>
      <h1 class="qz-page-title"><?= h($dispName) ?></h1>
      <p class="qz-meta qz-page-description"><?= h($A['quiz_title']) ?>. Review the identity, progress, timeline, Answers, and administrative actions for this Attempt.</p>
    </div>
    <div class="qz-toolbar qz-page-actions">
      <?= status_badge((string)$A['status']) ?>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/attempts">Back to list</a>
    </div>
  </header>

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

  <div class="qz-stats qz-metric-grid" aria-label="Attempt summary">
    <div class="qz-stat qz-metric"><span>Answers provided</span><strong data-qz-answered-count data-total-questions="<?= (int)$totalQ ?>"><?= (int)$answered ?>/<?= (int)$totalQ ?></strong></div>
    <div class="qz-stat qz-metric"><span>Total score</span><strong data-qz-total-score><?= (int)$totalScore ?></strong></div>
    <div class="qz-stat qz-metric"><span>Reset count</span><strong><?= (int)$A['reset_count'] ?></strong></div>
    <div class="qz-stat qz-metric"><span>Mode</span><strong><?= $deliveryMode !== '' ? h(ucfirst($deliveryMode)) : '-' ?></strong></div>
  </div>

  <div class="grid qz-detail-layout<?= $showAttemptOperations ? '' : ' qz-detail-layout--single' ?>">
    <main class="panel qz-section">
      <section class="qz-section" aria-labelledby="attempt-identity-title">
        <div class="qz-editor-section__head qz-section__head">
          <div><h2 id="attempt-identity-title">Attempt identity</h2><p>Contact details are a snapshot from when the Attempt was created.</p></div>
          <?php if ($identityLocked): ?><span class="badge badge--expired">Identity locked</span><?php endif; ?>
        </div>
        <dl class="qz-detail-grid qz-section__body">
          <div><dt>Student name</dt><dd><?= h($dispName !== '' ? $dispName : '-') ?></dd></div>
          <div><dt>Email</dt><dd><?= h($dispEmail !== '' ? $dispEmail : '-') ?></dd></div>
          <div><dt>Phone number</dt><dd><?= h($dispPhone !== '' ? $dispPhone : '-') ?></dd></div>
          <div><dt>Test number</dt><dd><code><?= h($nomorTest !== '' ? $nomorTest : '-') ?></code></dd></div>
          <div><dt>Alias</dt><dd><code><?= h($alias !== '' ? $alias : '-') ?></code></dd></div>
          <div><dt>Attempt ID</dt><dd>#<?= (int)$attempt_id ?></dd></div>
          <div><dt>Verification</dt><dd><?= $A['email_verified_at'] ? h((string)($A['verified_via'] ?: 'email')) : 'Not Verified' ?></dd></div>
          <div><dt>Quiz</dt><dd><?= h($A['quiz_title']) ?></dd></div>
        </dl>

        <?php if ($studentName !== '' || $studentEmail !== '' || $studentPhone !== ''): ?>
          <div class="helpbox qz-detail-note">
            <div class="title">Current Student profile</div>
            <div class="muted"><?= h($studentName ?: '-') ?> / <?= h($studentEmail ?: '-') ?><?= $studentPhone ? ' / '.h($studentPhone) : '' ?></div>
            <div class="muted">The Student profile may change, but the Attempt identity snapshot is retained.</div>
          </div>
        <?php endif; ?>
      </section>

      <section class="qz-section" aria-labelledby="attempt-timeline-title">
        <div class="qz-editor-section__head qz-section__head">
          <div><h2 id="attempt-timeline-title">Attempt timeline</h2><p>Key times for reviewing the Student's progress and activity.</p></div>
        </div>
        <ol class="qz-attempt-timeline qz-section__body">
          <li><strong>Created</strong><span><?= h((string)($A['created_at'] ?? '-')) ?></span></li>
          <li><strong>Started</strong><span><?= h((string)($A['started_at'] ?: '-')) ?></span></li>
          <li><strong>Last activity</strong><span><?= h((string)($A['last_activity_at'] ?: '-')) ?></span></li>
          <li><strong>Submitted</strong><span><?= h((string)($A['submitted_at'] ?: '-')) ?></span></li>
          <li><strong>Elapsed time</strong><span><?= $A['elapsed_seconds'] === null ? '-' : h(sprintf('%d:%02d:%02d', intdiv((int)$A['elapsed_seconds'], 3600), intdiv((int)$A['elapsed_seconds'] % 3600, 60), (int)$A['elapsed_seconds'] % 60)) ?></span></li>
        </ol>
      </section>

      <section class="helpbox qz-section" aria-labelledby="attempt-link-title">
        <div class="qz-section__head">
          <div><div class="title" id="attempt-link-title">Admin support link</div><div class="muted">This alias-based link requires a dashboard login and can be used to assist with OTP verification.</div></div>
        </div>
        <div class="qz-field qz-section__body">
          <label for="attemptLink">Attempt details link</label>
          <div class="qz-support-link-row">
            <input id="attemptLink" type="text" value="<?= h($adminAttemptUrl) ?>" readonly>
            <button class="smallbtn" type="button" id="btnCopyLink" data-qz-copy-target="attemptLink" data-qz-copy-label="Link copied">Copy link</button>
          </div>
        </div>
      </section>

    </main>

    <?php if ($showAttemptOperations): ?>
    <aside class="panel qz-section qz-attempt-operations" aria-labelledby="attempt-actions-title">
      <div class="qz-editor-section__head qz-section__head">
        <div><h2 id="attempt-actions-title">Admin actions</h2><p>Available actions depend on your account permissions.</p></div>
      </div>

      <div class="qz-form-actions qz-attempt-action-stack">
      <?php if ($user['can_operate_attempts']): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_update_delivery_mode" class="helpbox qz-section qz-delivery-mode-form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
          <div class="title">Delivery mode</div>
          <div class="qz-field">
            <label for="attempt-delivery-mode">Select mode</label>
            <select id="attempt-delivery-mode" name="delivery_mode" class="adam-input">
              <option value="" <?= $deliveryMode===''?'selected':'' ?>>Not set</option>
              <option value="online" <?= $deliveryMode==='online'?'selected':'' ?>>Online</option>
              <option value="onsite" <?= $deliveryMode==='onsite'?'selected':'' ?>>Onsite</option>
            </select>
          </div>
          <button class="adam-button" type="submit">Save mode</button>
        </form>
      <?php endif; ?>

      <?php if ($hasMeaningfulOtp): ?>
        <section class="helpbox qz-section" aria-labelledby="attempt-otp-send-title">
          <div class="title" id="attempt-otp-send-title">Send OTP via WhatsApp</div>
          <p class="muted">The message uses the name and phone number from the Attempt snapshot.</p>
          <div class="row qz-form-actions">
            <?php if ($waSendUrl): ?>
              <a class="smallbtn" href="<?= h($waSendUrl) ?>" target="_blank" rel="noopener">Open WhatsApp</a>
              <span class="muted">Recipient: <strong><?= h($dispPhone) ?></strong></span>
            <?php else: ?>
              <button class="smallbtn" type="button" disabled>Phone number unavailable</button>
              <span class="muted">Check the phone number in the snapshot or Student profile.</span>
            <?php endif; ?>
          </div>
        </section>

        <section class="helpbox qz-section" aria-labelledby="attempt-otp-current-title">
          <div class="title" id="attempt-otp-current-title">Active OTP</div>
          <p class="muted">This code changes only after a reset or when a new OTP is generated.</p>
          <div class="row qz-form-actions">
            <span class="otpbox" id="otpNow"><?= h($otp_now) ?></span>
            <button class="smallbtn" type="button" id="btnCopyOtp" data-qz-copy-target="otpNow" data-qz-copy-label="OTP copied">Copy OTP</button>
          </div>
          <div class="muted">OTP email: <strong><?= $emailSentAt ? 'sent' : '-' ?></strong> / WhatsApp request: <strong><?= $waSentAt ? 'recorded' : '-' ?></strong></div>
        </section>
      <?php endif; ?>

      <?php if ($user['can_operate_attempts']): ?>
        <section class="qz-section" aria-labelledby="attempt-verification-actions-title">
          <div class="qz-section__head"><div><h3 id="attempt-verification-actions-title">Verification and access</h3><p class="muted">Use only when a Student needs verification assistance.</p></div></div>
          <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_generate_otp" class="qz-form-actions">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
            <button class="adam-button ghost" type="submit" data-confirm="Generate a new OTP? The Attempt status will return to Registered.">Generate new OTP</button>
          </form>

          <?php if ($manualVerificationEligible): ?>
          <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_verify_manual" class="qz-form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
            <div class="qz-field">
              <label for="attempt-verified-via">Verification method</label>
              <select id="attempt-verified-via" name="verified_via" class="adam-input">
                <option value="manual">Manual</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
              </select>
            </div>
            <div class="qz-field">
              <label for="attempt-verified-note">Note</label>
              <input class="adam-input" id="attempt-verified-note" type="text" name="verified_note" placeholder="Example: OTP sent via admin WhatsApp">
            </div>
            <button class="adam-button" type="submit" data-confirm="Verify manually? The Attempt status will change to Verified.">Verify manually</button>
          </form>
          <?php endif; ?>
        </section>

        <section class="qz-danger-zone" aria-labelledby="attempt-reset-title">
          <div class="qz-section__head"><div><h3 id="attempt-reset-title">Reset Attempt</h3><p class="muted">Answers and the Question set will be deleted. The Student must start again with a new OTP.</p></div></div>
          <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_reset" class="qz-form-grid qz-attempt-reset-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
            <div class="qz-field">
              <label for="attempt-reset-reason">Reset reason</label>
              <input class="adam-input" id="attempt-reset-reason" type="text" name="reset_reason" placeholder="Example: wrong device or email not received" required>
            </div>
            <button class="adam-button btn-danger qz-danger-action" type="submit" data-confirm="Reset this Attempt? The Answers and Question set will be deleted, and a new OTP will be generated." data-confirm-variant="danger">Reset Attempt</button>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($user['can_delete_attempts']): ?>
        <section class="qz-danger-zone" aria-labelledby="attempt-delete-title">
          <div class="qz-section__head"><div><h3 id="attempt-delete-title">Permanently delete</h3><p class="muted">The Question set, Answers, and related monitoring logs will also be deleted. This action cannot be undone.</p></div></div>
          <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_delete" class="qz-form-actions qz-attempt-delete-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
            <input type="hidden" name="redirect" value="<?= h($base) ?>/?page=admin/quiz/attempts">
            <button class="adam-button btn-danger qz-danger-action" type="submit" data-confirm="Delete Attempt <?= h($attemptLabel) ?>?\n\nThe Question set, Answers, and related monitoring logs will also be deleted.\nThis action cannot be undone." data-confirm-variant="danger">Delete Attempt</button>
          </form>
        </section>
      <?php endif; ?>
      </div>
    </aside>
    <?php endif; ?>

    <?php if ($user['can_view_attempt_responses']): ?>
    <section class="qz-section qz-attempt-responses" aria-labelledby="attempt-responses-title">
      <div class="qz-editor-section__head qz-section__head">
        <div>
          <h2 id="attempt-responses-title">Student Answers</h2>
          <?php if ($canCorrectResponses): ?>
            <p>Responses are shown as submitted. Prior saved versions are retained, and authorized corrections are audited.</p>
          <?php else: ?>
            <p>Responses are shown with any retained prior saved versions.</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="table-scroll qz-section__body" role="region" aria-label="Student Answers" tabindex="0">
        <table class="table qz-data-table qz-response-table">
          <thead><tr><th>No.</th><th>Question and Answer</th><th>Score</th></tr></thead>
          <tbody>
          <?php if (!$items): ?>
            <tr><td colspan="3" class="qz-empty-cell"><div class="qz-empty-state"><strong>No Question set</strong><div>A Question set has not been generated for this Attempt.</div></div></td></tr>
          <?php else: foreach ($items as $it): ?>
            <?php
              $ans = $formatAttemptResponse((int)$it['question_id'], (string)$it['question_type'],
                $it['selected_answer_id'], $it['answer_text'], $it['selected_answer_text']);
              $revisionCount = (int)($it['revision_count'] ?? 0);
              $correctionEligible = $canCorrectResponses && !empty($it['options_snapshotted_at'])
                && (string)$it['options_snapshotted_at'] < (string)$A['submitted_at'];
            ?>
            <tr data-qz-response-row data-question-id="<?= (int)$it['question_id'] ?>">
              <td data-label="No."><?= (int)$it['seq'] ?></td>
              <td data-label="Question and Answer">
                <div class="qz-response-question"><strong><?= nl2br(h($it['question_text'])) ?></strong></div>
                <div class="qz-response-answer"><strong>Answer:</strong> <span data-qz-response-answer><?= nl2br(h($ans)) ?></span></div>
                <div class="qz-response-meta">
                  <span class="muted">Type: <?= h($formatQuestionType((string)$it['question_type'])) ?></span>
                  <?php if ($correctionEligible): ?>
                    <button class="smallbtn qz-response-correct" type="button" data-qz-correction-open data-question-id="<?= (int)$it['question_id'] ?>">Correct response</button>
                  <?php elseif ($canCorrectResponses): ?>
                    <span class="muted" title="The answer-key snapshot was captured after submission.">Correction unavailable</span>
                  <?php endif; ?>
                </div>
                <details class="muted qz-response-revisions" data-qz-response-history<?= $revisionCount > 0 ? '' : ' hidden' ?>>
                  <summary><span data-qz-history-summary>Prior saved versions (<?= $revisionCount ?>)</span></summary>
                  <div class="qz-response-revision-list" data-qz-history-list>
                    <?php foreach ($revisionsByAnswer[(int)($it['attempt_answer_id'] ?? 0)] ?? [] as $revision): ?>
                      <?php
                        $revisionAnswer = $formatAttemptResponse((int)$revision['question_id'], (string)$it['question_type'],
                          $revision['selected_answer_id'], $revision['answer_text'], $revision['selected_answer_text']);
                        $isAdminRevision = (string)($revision['change_source'] ?? 'participant') === 'admin';
                        $actorName = trim((string)($revision['changed_by_name'] ?? ''));
                        $actorEmail = trim((string)($revision['changed_by_email'] ?? ''));
                        $actorLabel = $actorName !== '' ? $actorName . ($actorEmail !== '' ? ' (' . $actorEmail . ')' : '') : $actorEmail;
                      ?>
                      <div class="qz-response-revision" data-qz-history-entry>
                        <div class="qz-response-revision__head">
                          <strong><?= $isAdminRevision ? 'Before administrative correction' : 'Before participant change' ?></strong>
                          <span>Version <?= (int)$revision['revision_number'] + 1 ?></span>
                        </div>
                        <div class="qz-response-revision__answer"><?= nl2br(h($revisionAnswer)) ?></div>
                        <div class="qz-response-revision__meta">
                          <span>Score <?= (int)$revision['computed_score'] ?></span>
                          <span>Changed at <?= h((string)$revision['created_at']) ?></span>
                          <span>Answered <?= h((string)($revision['answered_at'] ?: '-')) ?></span>
                          <?php if ($actorLabel !== ''): ?><span>By <?= h($actorLabel) ?></span><?php endif; ?>
                        </div>
                        <?php if ($isAdminRevision && trim((string)($revision['change_reason'] ?? '')) !== ''): ?>
                          <div class="qz-response-revision__reason"><strong>Reason:</strong> <?= nl2br(h((string)$revision['change_reason'])) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
              </td>
              <td data-label="Score"><strong data-qz-response-score><?= (int)$it['computed_score'] ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <?php if ($canCorrectResponses): ?>
    <div id="qz-response-correction-modal" class="qz-correction-modal" role="dialog" aria-modal="true" aria-labelledby="qz-correction-title" aria-describedby="qz-correction-description" aria-hidden="true" hidden>
      <div class="qz-correction-modal__dialog" data-qz-correction-dialog tabindex="-1">
        <form class="qz-correction-form" data-qz-correction-form data-endpoint="<?= h($base) ?>/?page=admin/quiz/attempts/api/response_correct&amp;action=quiz_api" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <header class="qz-correction-modal__head">
            <div>
              <div class="qz-kicker">Audited change</div>
              <h2 id="qz-correction-title">Correct submitted response</h2>
              <p id="qz-correction-description">Select the intended response and record why this administrative correction is required.</p>
            </div>
            <button type="button" class="qz-correction-modal__close" data-qz-correction-close aria-label="Close correction dialog">&times;</button>
          </header>
          <div class="qz-correction-modal__body">
            <section class="qz-correction-context" aria-labelledby="qz-correction-question-label">
              <h3 id="qz-correction-question-label">Question</h3>
              <div data-qz-correction-question></div>
            </section>
            <div class="qz-correction-review-grid">
              <section class="qz-correction-panel" aria-labelledby="qz-correction-current-label">
                <h3 id="qz-correction-current-label">Current response</h3>
                <div data-qz-correction-current></div>
              </section>
              <section class="qz-correction-panel qz-correction-key" aria-labelledby="qz-correction-key-label">
                <h3 id="qz-correction-key-label">Answer key from attempt snapshot</h3>
                <div data-qz-correction-key></div>
              </section>
            </div>
            <fieldset class="qz-correction-controls" data-qz-correction-controls>
              <legend>Corrected response</legend>
            </fieldset>
            <div class="qz-field qz-correction-reason">
              <label for="qz-correction-reason">Correction reason</label>
              <textarea class="adam-input" id="qz-correction-reason" name="reason" minlength="5" maxlength="500" required aria-describedby="qz-correction-reason-help qz-correction-error"></textarea>
              <p class="qz-field-help" id="qz-correction-reason-help">Required, 5 to 500 characters. This note is retained in the audit history.</p>
            </div>
            <div class="qz-correction-message" id="qz-correction-error" data-qz-correction-message role="alert" hidden></div>
            <div class="qz-correction-status" data-qz-correction-status role="status" aria-live="polite"></div>
          </div>
          <footer class="qz-correction-modal__foot">
            <button type="button" class="adam-button ghost" data-qz-correction-close>Cancel</button>
            <button type="submit" class="adam-button" data-qz-correction-submit>Apply correction</button>
          </footer>
        </form>
      </div>
    </div>
    <script type="application/json" id="qz-response-correction-data"><?= json_encode($correctionPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
  <?php endif; ?>
</section>
