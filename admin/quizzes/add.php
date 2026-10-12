<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$user = qz_admin_user($pdo);
if (!$user['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }
$uid = (int)$user['id'];

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$errors = [];
$title = '';
$public_prefix = '';
$description = '';
$questions_per_attempt = 25;
$duration_minutes = '';
$shuffle_answers = 1;
$timer_warning_minutes = '';
$allow_mark_for_review = 0;
$result_visibility = 'hidden';
$is_active = 1;
$start_at = '';
$end_at = '';

function is_valid_dt(string $s): bool {
  if ($s === '') return true;
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s);
}

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

  try {
    $is_active = quiz_normalize_bool($_POST['is_active'] ?? null, 1);
    $shuffle_answers = quiz_normalize_bool($_POST['shuffle_answers'] ?? null, 1);
    $allow_mark_for_review = quiz_normalize_bool($_POST['allow_mark_for_review'] ?? null, 0);
    $result_visibility = quiz_normalize_result_visibility($_POST['result_visibility'] ?? '');
    $timerWarningSeconds = quiz_timer_warning_minutes_to_seconds($timer_warning_minutes);
    $normalizedPublicPrefix = quiz_validate_public_prefix($pdo, $public_prefix);
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
      qz_require_workspace_mutation_access($pdo, $uid);
      $normalizedPublicPrefix = quiz_validate_public_prefix($pdo, $public_prefix);
      $stmt = $pdo->prepare("
        INSERT INTO quizzes (created_by, title, public_prefix, description, questions_per_attempt, duration_minutes, shuffle_answers,
          timer_warning_seconds, allow_mark_for_review, result_visibility, is_active, is_primary, start_at, end_at)
        VALUES (:uid, :title, :public_prefix, :desc, :qpa, :dur, :shuffle_answers, :timer_warnings, :allow_review, :result_visibility, :act, 0, :start_at, :end_at)
      ");
      $stmt->execute([
        ':uid' => $uid,
        ':title' => $title,
        ':public_prefix' => $normalizedPublicPrefix,
        ':desc' => ($description === '' ? null : $description),
        ':qpa' => $questions_per_attempt,
        ':dur' => ($duration_minutes === '' ? null : (int)$duration_minutes),
        ':shuffle_answers' => $shuffle_answers,
        ':timer_warnings' => quiz_serialize_timer_warning_seconds($timerWarningSeconds),
        ':allow_review' => $allow_mark_for_review,
        ':result_visibility' => $result_visibility,
        ':act' => ($is_active ? 1 : 0),
        ':start_at' => ($start_at === '' ? null : $start_at),
        ':end_at' => ($end_at === '' ? null : $end_at),
      ]);
      $pdo->commit();
      quiz_public_prefix_unlock($pdo);
      $prefixLocked = false;
      qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode("Quiz created."));
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($prefixLocked) quiz_public_prefix_unlock($pdo);
      $errors[] = 'Could not create the Quiz, or access has expired.';
    }
  }
}
?>
<section class="adam-card qz-card qz-shell qz-editor-page qz-quiz-editor">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>'Create Quiz']]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">New Quiz</div>
      <h1 class="qz-page-title">Create Quiz</h1>
      <p class="qz-page-description qz-context">Set the Quiz details, Attempt rules, and publication period.</p>
    </div>
    <div class="qz-page-actions qz-toolbar"><a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes">Back to List</a></div>
  </header>

  <?php if ($errors): ?>
    <div class="adam-alert error" role="alert">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

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
          <input class="adam-input" id="quiz-public-prefix" name="public_prefix" value="<?= h($public_prefix) ?>" placeholder="Example: example-quiz" maxlength="63" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" aria-describedby="quiz-public-prefix-help">
          <div class="small-hint" id="quiz-public-prefix-help">Creates a dedicated namespace such as <code>/example-quiz/register/</code>. Use lowercase letters, numbers, and hyphens only.</div>
        </div>
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
      <select class="adam-input" id="quiz-status" name="is_active" aria-describedby="quiz-status-help">
        <option value="1" <?= ((int)$is_active===1?'selected':'') ?>>Active</option>
        <option value="0" <?= ((int)$is_active===0?'selected':'') ?>>Inactive</option>
      </select>
      <div class="small-hint" id="quiz-status-help">Inactive Quizzes are unavailable for new Attempts.</div>
      </div>
      </div>
    </fieldset>

    <div class="qz-form-actions qz-editor-actions">
      <button class="adam-button" type="submit">Save Quiz</button>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes">Cancel</a>
    </div>
  </form>
</section>

<?php
