<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$routeUser = qz_admin_user($pdo);
if (!$routeUser['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing or invalid id');
}
qz_require_quiz_access($pdo, $id);

function is_valid_dt(string $s): bool {
  if ($s === '') return true;
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s);
}

$stmt = $pdo->prepare("
  SELECT q.*, u.name AS owner_name, u.email AS owner_email
  FROM quizzes q
  LEFT JOIN users u ON u.id = q.created_by
  WHERE q.id = :id
  LIMIT 1
");
$stmt->execute([':id' => $id]);
$quiz = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$quiz) {
  http_response_code(404);
  exit('Quiz not found');
}
$revisionMeta = quiz_revision_for_quiz($pdo, $id);
if (!$revisionMeta) {
  http_response_code(503);
  exit('Quiz revision metadata is unavailable.');
}
$revisionState = (string)$revisionMeta['state'];
$editRevisionToken = static function (array $quizRow, array $revisionRow): string {
  $fields = [
    'title', 'public_prefix', 'description', 'questions_per_attempt', 'duration_minutes',
    'shuffle_answers', 'timer_warning_seconds', 'allow_mark_for_review', 'result_visibility',
    'is_active', 'start_at', 'end_at', 'verification_required_override',
    'require_fullscreen_override', 'pause_when_blur_override', 'block_context_menu_override',
    'block_shortcuts_override', 'devtools_detect_override', 'show_exam_badge_override',
    'visual_theme_override', 'updated_at',
  ];
  $values = [];
  foreach ($fields as $field) $values[$field] = $quizRow[$field] ?? null;
  return hash('sha256', json_encode([
    'revision_id' => (int)($revisionRow['id'] ?? 0),
    'revision_number' => (int)($revisionRow['revision_number'] ?? 0),
    'state' => (string)($revisionRow['state'] ?? ''),
    'quiz' => $values,
  ], JSON_THROW_ON_ERROR));
};
$expectedEditToken = $editRevisionToken($quiz, $revisionMeta);
$attemptStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=:quiz_id');
$attemptStmt->execute([':quiz_id' => $id]);
$attemptCount = (int)$attemptStmt->fetchColumn();
$draftStmt = $pdo->prepare("SELECT quiz_id, revision_number FROM quiz_instrument_revisions
  WHERE instrument_id=:instrument_id AND state='draft' LIMIT 1");
$draftStmt->execute([':instrument_id' => (int)$revisionMeta['instrument_id']]);
$familyDraft = $draftStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$ownsInstrument = $routeUser['can_manage_any']
  || (int)($revisionMeta['instrument_created_by'] ?? 0) === (int)$routeUser['id'];
$canManageRevisions = $routeUser['can_manage_quizzes'] && $ownsInstrument;
$isReadOnly = $revisionState === 'retired'
  || ($revisionState === 'current' && ((int)$revisionMeta['revision_number'] > 1 || $attemptCount > 0));
$readOnlyReason = $revisionState === 'retired'
  ? 'Retired revisions are immutable historical records. Restore this revision as a new draft to reuse its settings and Questions.'
  : ($isReadOnly ? 'Published revisions are read-only. Create a draft to make changes safely.' : '');

$errors = [];
$message = trim((string)($_GET['msg'] ?? ''));

$title = (string)($quiz['title'] ?? '');
$public_prefix = (string)($revisionMeta['instrument_public_prefix'] ?? '');
$description = (string)($quiz['description'] ?? '');
$questions_per_attempt = (int)($quiz['questions_per_attempt'] ?? 25);
$duration_minutes = ($quiz['duration_minutes'] !== null) ? (string)$quiz['duration_minutes'] : '';
$shuffle_answers = quiz_safe_bool($quiz['shuffle_answers'] ?? 1, 1);
$timer_warning_minutes = quiz_timer_warning_seconds_to_minutes(quiz_safe_timer_warning_seconds($quiz['timer_warning_seconds'] ?? null));
$allow_mark_for_review = quiz_safe_bool($quiz['allow_mark_for_review'] ?? 0, 0);
$result_visibility = quiz_safe_result_visibility($quiz['result_visibility'] ?? 'hidden');
$is_active = (int)($quiz['is_active'] ?? 1);
$start_at = (string)($quiz['start_at'] ?? '');
$end_at   = (string)($quiz['end_at'] ?? '');
$visual_theme_override = quiz_safe_visual_theme_override($quiz['visual_theme_override'] ?? null) ?? '';
$overrideFields = ['verification_required_override','require_fullscreen_override','pause_when_blur_override','block_context_menu_override','block_shortcuts_override','devtools_detect_override','show_exam_badge_override'];
$overrides = [];
foreach ($overrideFields as $field) $overrides[$field] = $quiz[$field] === null ? '' : (string)(int)$quiz[$field];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    $errors[] = 'Invalid CSRF token.';
  }

  $title = trim((string)($_POST['title'] ?? ''));
  $public_prefix = trim((string)($_POST['public_prefix'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $questions_per_attempt = (int)($_POST['questions_per_attempt'] ?? 25);
  $duration_minutes = trim((string)($_POST['duration_minutes'] ?? ''));
  $timer_warning_minutes = trim((string)($_POST['timer_warning_minutes'] ?? ''));
  $is_active = (int)($_POST['is_active'] ?? 1);
  $start_at = trim((string)($_POST['start_at'] ?? ''));
  $end_at = trim((string)($_POST['end_at'] ?? ''));
  $visual_theme_override = trim((string)($_POST['visual_theme_override'] ?? ''));
  if ($visual_theme_override !== '') {
    try {
      $visual_theme_override = quiz_normalize_visual_theme($visual_theme_override);
    } catch (InvalidArgumentException $error) {
      $visual_theme_override = '';
      $errors[] = $error->getMessage();
    }
  }
  foreach ($overrideFields as $field) {
    $value = (string)($_POST[$field] ?? '');
    if (!in_array($value, ['', '0', '1'], true)) $errors[] = 'Invalid per-Quiz override.';
    $overrides[$field] = $value;
  }

  try {
    $is_active = quiz_normalize_bool($_POST['is_active'] ?? null, 1);
    $shuffle_answers = quiz_normalize_bool($_POST['shuffle_answers'] ?? null, 1);
    $allow_mark_for_review = quiz_normalize_bool($_POST['allow_mark_for_review'] ?? null, 0);
    $result_visibility = quiz_normalize_result_visibility($_POST['result_visibility'] ?? '');
    $timerWarningSeconds = quiz_timer_warning_minutes_to_seconds($timer_warning_minutes);
    $normalizedPublicPrefix = $revisionState === 'draft' ? null : quiz_validate_public_prefix(
      $pdo,
      $public_prefix,
      $id,
      quiz_normalize_public_prefix($revisionMeta['instrument_public_prefix'] ?? null)
    );
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
    $timerWarningSeconds = [];
    $normalizedPublicPrefix = null;
  }

  if ($title === '') $errors[] = 'Title is required.';
  if ($questions_per_attempt <= 0 || $questions_per_attempt > 200) $errors[] = 'Questions per Attempt must be between 1 and 200.';
  if ($duration_minutes !== '' && (!ctype_digit($duration_minutes) || (int)$duration_minutes <= 0)) $errors[] = 'Duration must be a positive number of minutes.';
  if ($timerWarningSeconds !== [] && ($duration_minutes === '' || max($timerWarningSeconds) > (int)$duration_minutes * 60)) $errors[] = 'Timer warnings must fall within the Quiz duration.';
  if (!is_valid_dt($start_at)) $errors[] = 'Start time format: YYYY-MM-DD HH:MM:SS';
  if (!is_valid_dt($end_at)) $errors[] = 'End time format: YYYY-MM-DD HH:MM:SS';

  if (!$errors) {
    $prefixLocked = false;
    try {
      if (!quiz_public_prefix_lock($pdo)) throw new RuntimeException('Public URL namespace is busy.');
      $prefixLocked = true;
      $pdo->beginTransaction();
      $lockedQuiz = qz_lock_quiz_for_workspace_mutation($pdo, $id, (int)$routeUser['id']);
      $lockedRevision = quiz_revision_for_quiz($pdo, $id, true);
      if (!$lockedRevision) throw new RuntimeException('Quiz revision metadata is unavailable.');
      if ((string)($_POST['expected_revision_state'] ?? '') !== (string)$lockedRevision['state']
          || (int)($_POST['expected_revision_number'] ?? 0) !== (int)$lockedRevision['revision_number']) {
        throw new RuntimeException('Quiz revision state changed. Reload before editing.');
      }
      $submittedEditToken = (string)($_POST['expected_edit_token'] ?? '');
      if ($submittedEditToken === '' || !hash_equals($editRevisionToken($lockedQuiz, $lockedRevision), $submittedEditToken)) {
        throw new RuntimeException('Quiz settings changed. Reload before editing.');
      }
      $normalizedPublicPrefix = (string)$lockedRevision['state'] === 'draft' ? null : quiz_validate_public_prefix(
        $pdo,
        $public_prefix,
        $id,
        quiz_normalize_public_prefix($lockedRevision['instrument_public_prefix'] ?? null)
      );
      if ((string)$lockedRevision['state'] === 'current') {
        quiz_assert_instrument_prefix_mutable($pdo, $lockedRevision, $normalizedPublicPrefix);
      }

      $stmt = $pdo->prepare("
        UPDATE quizzes
        SET title = :title,
            public_prefix = :public_prefix,
            description = :desc,
            questions_per_attempt = :qpa,
            duration_minutes = :dur,
            shuffle_answers = :shuffle_answers,
            timer_warning_seconds = :timer_warnings,
            allow_mark_for_review = :allow_review,
            result_visibility = :result_visibility,
            is_active = :act,
            start_at = :start_at,
            end_at = :end_at,
            verification_required_override = :verification_required_override,
            require_fullscreen_override = :require_fullscreen_override,
            pause_when_blur_override = :pause_when_blur_override,
            block_context_menu_override = :block_context_menu_override,
            block_shortcuts_override = :block_shortcuts_override,
            devtools_detect_override = :devtools_detect_override,
            show_exam_badge_override = :show_exam_badge_override,
            visual_theme_override = :visual_theme_override
        WHERE id = :id
        LIMIT 1
      ");
      $params = [
        ':title' => $title,
        ':public_prefix' => $normalizedPublicPrefix,
        ':desc' => ($description === '' ? null : $description),
        ':qpa' => $questions_per_attempt,
        ':dur' => ($duration_minutes === '' ? null : (int)$duration_minutes),
        ':shuffle_answers' => $shuffle_answers,
        ':timer_warnings' => quiz_serialize_timer_warning_seconds($timerWarningSeconds),
        ':allow_review' => $allow_mark_for_review,
        ':result_visibility' => $result_visibility,
        ':act' => (string)$lockedRevision['state'] === 'draft' ? 0 : ($is_active ? 1 : 0),
        ':start_at' => ($start_at === '' ? null : $start_at),
        ':end_at' => ($end_at === '' ? null : $end_at),
        ':verification_required_override' => $overrides['verification_required_override'] === '' ? null : (int)$overrides['verification_required_override'],
        ':require_fullscreen_override' => $overrides['require_fullscreen_override'] === '' ? null : (int)$overrides['require_fullscreen_override'],
        ':pause_when_blur_override' => $overrides['pause_when_blur_override'] === '' ? null : (int)$overrides['pause_when_blur_override'],
        ':block_context_menu_override' => $overrides['block_context_menu_override'] === '' ? null : (int)$overrides['block_context_menu_override'],
        ':block_shortcuts_override' => $overrides['block_shortcuts_override'] === '' ? null : (int)$overrides['block_shortcuts_override'],
        ':devtools_detect_override' => $overrides['devtools_detect_override'] === '' ? null : (int)$overrides['devtools_detect_override'],
        ':show_exam_badge_override' => $overrides['show_exam_badge_override'] === '' ? null : (int)$overrides['show_exam_badge_override'],
        ':visual_theme_override' => $visual_theme_override === '' ? null : $visual_theme_override,
        ':id' => $id,
      ];
      $stmt->execute($params);
      if ($stmt->rowCount() > 1) throw new RuntimeException('Quiz update affected an unexpected number of rows.');
      if ((string)$lockedRevision['state'] === 'current') {
        $updateInstrument = $pdo->prepare('UPDATE quiz_instruments SET public_prefix=:public_prefix
          WHERE id=:instrument_id AND current_quiz_id=:quiz_id');
        $updateInstrument->execute([
          ':public_prefix' => $normalizedPublicPrefix,
          ':instrument_id' => (int)$lockedRevision['instrument_id'],
          ':quiz_id' => $id,
        ]);
        if ($updateInstrument->rowCount() > 1) throw new RuntimeException('Quiz instrument update affected an unexpected number of rows.');
      }
      $pdo->commit();
      quiz_public_prefix_unlock($pdo);
      $prefixLocked = false;

      qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode("Quiz updated."));
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($prefixLocked) quiz_public_prefix_unlock($pdo);
      error_log('[admin/quiz/quizzes/edit] ' . $e->getMessage());
      $errors[] = 'The Quiz was not updated, or access has expired.';
    }
  }
}

$owner = trim((string)($quiz['owner_name'] ?? ''));
$oemail = trim((string)($quiz['owner_email'] ?? ''));
$ownerLine = ($owner !== '' || $oemail !== '')
  ? (($owner !== '' ? $owner : 'User#'.(int)$quiz['created_by']) . ($oemail !== '' ? " · {$oemail}" : ''))
  : ('User#'.(int)$quiz['created_by']);
?>
<section class="adam-card qz-card qz-shell qz-editor-page qz-quiz-editor">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>(string)$quiz['title']]]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Quiz settings</div>
      <h1 class="qz-page-title">Edit Quiz</h1>
      <p class="qz-page-description qz-context">Update the Attempt and publication settings for <strong><?= h($title) ?></strong>.</p>
      <div class="qz-meta">ID: <strong><?= (int)$id ?></strong><span class="dot">&bull;</span>Revision: <strong><?= (int)$revisionMeta['revision_number'] ?></strong> <span class="badge"><?= h(strtoupper($revisionState)) ?></span><span class="dot">&bull;</span>Owner: <strong><?= h($ownerLine) ?></strong><?php if ((int)($quiz['is_primary'] ?? 0) === 1): ?><span class="dot">&bull;</span><span class="badge ok">PRIMARY QUIZ</span><?php endif; ?></div>
    </div>
    <div class="qz-page-actions qz-toolbar">
      <?php if ($canManageRevisions): ?><a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes/revisions&amp;id=<?= (int)$id ?>">Revision History</a><?php endif; ?>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/questions&amp;quiz_id=<?= (int)$id ?>"><?= $isReadOnly ? 'View Questions' : 'Manage Questions' ?></a>
      <?php if (!$isReadOnly): ?><a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/master&amp;quiz_id=<?= (int)$id ?>">Master Builder</a><?php endif; ?>
      <?php if ($canManageRevisions && $revisionState === 'draft'): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/publish_revision">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="adam-button" type="submit" data-confirm="Publish Revision <?= (int)$revisionMeta['revision_number'] ?>? New participants will use it while historical Attempts remain on their original revision.">Publish Revision</button>
        </form>
      <?php elseif ($canManageRevisions && $revisionState === 'current' && !$familyDraft): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/revise">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="adam-button" type="submit" data-confirm="Create a draft revision? Settings and Question content will be copied without Attempts.">Create Draft</button>
        </form>
      <?php elseif ($canManageRevisions && $revisionState === 'retired' && !$familyDraft): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/restore_revision">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="adam-button" type="submit" data-confirm="Restore Revision <?= (int)$revisionMeta['revision_number'] ?> as a new draft? The historical revision will remain unchanged.">Restore as Draft</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($message !== ''): ?><div class="adam-alert success" role="status"><?= h($message) ?></div><?php endif; ?>
  <?php if ($isReadOnly): ?><div class="qz-revision-readonly-notice" role="note"><strong>Read-only revision</strong><span><?= h($readOnlyReason) ?></span></div><?php endif; ?>
  <?php if ($familyDraft && $revisionState !== 'draft'): ?><div class="qz-revision-draft-callout"><div><strong>Revision <?= (int)$familyDraft['revision_number'] ?> is already in progress</strong><span>Publish or delete the existing draft before creating another.</span></div><a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/quizzes/edit&amp;id=<?= (int)$familyDraft['quiz_id'] ?>">Continue Draft</a></div><?php endif; ?>

  <?php if ($errors): ?>
    <div class="adam-alert error" role="alert">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="expected_revision_state" value="<?= h($revisionState) ?>">
    <input type="hidden" name="expected_revision_number" value="<?= (int)$revisionMeta['revision_number'] ?>">
    <input type="hidden" name="expected_edit_token" value="<?= h($expectedEditToken) ?>">
    <fieldset class="qz-revision-form-lock"<?= $isReadOnly ? ' disabled' : '' ?>>

    <fieldset class="qz-section group-box">
      <legend class="qz-section__head"><strong>Quiz Details</strong></legend>
      <div class="qz-section__body">
        <div class="qz-field">
          <label for="quiz-title"><strong>Title</strong></label>
          <input class="adam-input" id="quiz-title" name="title" value="<?= h($title) ?>" aria-describedby="quiz-title-help" required>
          <div class="small-hint" id="quiz-title-help">Use a title that administrators and Students can easily recognize.</div>
        </div>
        <div class="qz-field">
          <label for="quiz-description"><strong>Description</strong> <span class="muted">(optional)</span></label>
          <textarea class="adam-input" id="quiz-description" name="description" rows="4" aria-describedby="quiz-description-help"><?= h($description) ?></textarea>
          <div class="small-hint" id="quiz-description-help">Briefly explain the purpose or context of the Quiz.</div>
        </div>
        <div class="qz-field">
          <label for="quiz-public-prefix"><strong>Public URL Prefix</strong> <span class="muted">(optional)</span></label>
          <input class="adam-input" id="quiz-public-prefix" <?= $revisionState === 'draft' ? 'disabled' : 'name="public_prefix"' ?> value="<?= h($public_prefix) ?>" placeholder="Example: example-quiz" maxlength="63" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" aria-describedby="quiz-public-prefix-help">
          <?php if ($revisionState === 'draft'): ?><input type="hidden" name="public_prefix" value=""><?php endif; ?>
          <div class="small-hint" id="quiz-public-prefix-help"><?= $revisionState === 'draft' ? 'The stable public URL belongs to the Quiz instrument and transfers when this draft is published.' : 'Creates a stable namespace such as /example-quiz/register/ for every revision.' ?></div>
        </div>
      </div>
    </fieldset>

    <fieldset class="qz-section group-box">
      <legend class="qz-section__head"><strong>Per-Quiz Overrides</strong></legend>
      <div class="qz-section__body qz-form-grid qz-student-form-grid">
        <div class="qz-field">
          <label for="visual_theme_override"><strong>Visual theme</strong></label>
          <select class="adam-input" id="visual_theme_override" name="visual_theme_override">
            <option value="" <?= $visual_theme_override===''?'selected':'' ?>>Use global setting</option>
            <option value="default" <?= $visual_theme_override==='default'?'selected':'' ?>>Default</option>
            <option value="studio" <?= $visual_theme_override==='studio'?'selected':'' ?>>Studio</option>
            <option value="navy" <?= $visual_theme_override==='navy'?'selected':'' ?>>Navy</option>
          </select>
        </div>
      <?php $labels = ['verification_required_override'=>'OTP verification','require_fullscreen_override'=>'Require fullscreen','pause_when_blur_override'=>'Pause when tab/window loses focus','block_context_menu_override'=>'Block right-click','block_shortcuts_override'=>'Block copy, paste, shortcuts','devtools_detect_override'=>'Detect DevTools','show_exam_badge_override'=>'Show exam mode badge']; foreach ($labels as $field=>$label): ?>
        <div class="qz-field">
          <label for="<?= h($field) ?>"><strong><?= h($label) ?></strong></label>
          <select class="adam-input" id="<?= h($field) ?>" name="<?= h($field) ?>">
            <option value="" <?= $overrides[$field]===''?'selected':'' ?>>Use default</option>
            <option value="1" <?= $overrides[$field]==='1'?'selected':'' ?>>Force on</option>
            <option value="0" <?= $overrides[$field]==='0'?'selected':'' ?>>Force off</option>
          </select>
        </div>
      <?php endforeach; ?>
        <div class="small-hint qz-student-form-name">Use global/default behavior unless this Quiz needs a specific visual, verification, or proctor policy.</div>
      </div>
    </fieldset>

    <fieldset class="qz-section group-box">
      <legend class="qz-section__head"><strong>Attempt Rules</strong></legend>
      <div class="qz-section__body qz-form-grid qz-student-form-grid">
      <div class="qz-field">
        <label for="quiz-questions-per-attempt"><strong>Questions per Attempt</strong></label>
        <input class="adam-input" id="quiz-questions-per-attempt" type="number" min="1" max="200" name="questions_per_attempt" value="<?= (int)$questions_per_attempt ?>" aria-describedby="quiz-questions-help">
        <div class="small-hint" id="quiz-questions-help">Number of Questions given to each Student, from 1 to 200.</div>
      </div>
      <div class="qz-field">
        <label for="quiz-duration"><strong>Duration</strong> <span class="muted">(minutes, optional)</span></label>
        <input class="adam-input" id="quiz-duration" name="duration_minutes" value="<?= h($duration_minutes) ?>" placeholder="Example: 30" inputmode="numeric" aria-describedby="quiz-duration-help">
        <div class="small-hint" id="quiz-duration-help">Leave blank for Attempts with no time limit.</div>
      </div>
      <div class="qz-field">
        <label for="quiz-shuffle-answers"><strong>Shuffle Answers</strong></label>
        <select class="adam-input" id="quiz-shuffle-answers" name="shuffle_answers" aria-describedby="quiz-shuffle-help">
          <option value="1" <?= $shuffle_answers===1?'selected':'' ?>>Active (legacy default)</option>
          <option value="0" <?= $shuffle_answers===0?'selected':'' ?>>Inactive</option>
        </select>
        <div class="small-hint" id="quiz-shuffle-help">Randomizes the Answer choices for each Student.</div>
      </div>
      <div class="qz-field">
        <label for="quiz-timer-warning"><strong>Timer Warnings</strong> <span class="muted">(minutes, optional)</span></label>
        <input class="adam-input" id="quiz-timer-warning" name="timer_warning_minutes" value="<?= h($timer_warning_minutes) ?>" placeholder="Example: 10, 5, 1" aria-describedby="quiz-timer-warning-help">
        <div class="small-hint" id="quiz-timer-warning-help">Separate thresholds with commas. Each value must fall within the Quiz duration.</div>
      </div>
      <div class="qz-field">
        <label for="quiz-allow-review"><strong>Mark for Review</strong></label>
        <select class="adam-input" id="quiz-allow-review" name="allow_mark_for_review" aria-describedby="quiz-allow-review-help">
          <option value="0" <?= $allow_mark_for_review===0?'selected':'' ?>>Inactive (default)</option>
          <option value="1" <?= $allow_mark_for_review===1?'selected':'' ?>>Active</option>
        </select>
        <div class="small-hint" id="quiz-allow-review-help">Allows Students to mark Questions for review.</div>
      </div>
      <div class="qz-field">
        <label for="quiz-result-visibility"><strong>Student Results</strong></label>
        <select class="adam-input" id="quiz-result-visibility" name="result_visibility" aria-describedby="quiz-result-help">
          <option value="hidden" <?= $result_visibility==='hidden'?'selected':'' ?>>Hidden (default)</option>
          <option value="summary" <?= $result_visibility==='summary'?'selected':'' ?>>Summary</option>
          <option value="categories" <?= $result_visibility==='categories'?'selected':'' ?>>Summary + Categories</option>
        </select>
        <div class="small-hint" id="quiz-result-help">Hidden disables the Student results page. Summary shows the total; Categories adds a breakdown.</div>
      </div>
      </div>
    </fieldset>

    <fieldset class="qz-section group-box">
      <legend class="qz-section__head"><strong>Publication and Schedule</strong></legend>
      <div class="qz-section__body qz-form-grid qz-student-form-grid">
      <div class="qz-field">
        <label for="quiz-start-at"><strong>Starts At</strong> <span class="muted">(optional)</span></label>
        <input class="adam-input" id="quiz-start-at" name="start_at" value="<?= h($start_at) ?>" placeholder="YYYY-MM-DD HH:MM:SS" aria-describedby="quiz-schedule-help">
      </div>
      <div class="qz-field">
        <label for="quiz-end-at"><strong>Ends At</strong> <span class="muted">(optional)</span></label>
        <input class="adam-input" id="quiz-end-at" name="end_at" value="<?= h($end_at) ?>" placeholder="YYYY-MM-DD HH:MM:SS" aria-describedby="quiz-schedule-help">
      </div>
      <div class="small-hint qz-student-form-name" id="quiz-schedule-help">Use server time in YYYY-MM-DD HH:MM:SS format. Leave blank for no schedule limit.</div>
      <div class="qz-field qz-student-form-name">
      <label for="quiz-status"><strong>Quiz Status</strong></label>
      <select class="adam-input" id="quiz-status" name="is_active" aria-describedby="quiz-status-help" <?= $revisionState === 'draft' ? 'disabled' : '' ?>>
        <option value="1" <?= ((int)$is_active===1?'selected':'') ?>>Active</option>
        <option value="0" <?= ((int)$is_active===0?'selected':'') ?>>Inactive</option>
      </select>
      <?php if ($revisionState === 'draft'): ?><input type="hidden" name="is_active" value="0"><?php endif; ?>
      <div class="small-hint" id="quiz-status-help"><?= $revisionState === 'draft' ? 'Draft revisions remain inactive until publication.' : 'Inactive Quizzes are unavailable for new Attempts.' ?></div>
      </div>
      </div>
    </fieldset>

    </fieldset>
    <div class="qz-form-actions qz-editor-actions">
      <?php if (!$isReadOnly): ?><button class="adam-button" type="submit">Save Changes</button><?php endif; ?>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes">Cancel</a>
    </div>
  </form>
</section>

<?php
