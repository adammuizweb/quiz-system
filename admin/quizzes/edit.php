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

$errors = [];

$title = (string)($quiz['title'] ?? '');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    $errors[] = 'Invalid CSRF token.';
  }

  $title = trim((string)($_POST['title'] ?? ''));
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
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
    $timerWarningSeconds = [];
  }

  if ($title === '') $errors[] = 'Title is required.';
  if ($questions_per_attempt <= 0 || $questions_per_attempt > 200) $errors[] = 'Questions per Attempt must be between 1 and 200.';
  if ($duration_minutes !== '' && (!ctype_digit($duration_minutes) || (int)$duration_minutes <= 0)) $errors[] = 'Duration must be a positive number of minutes.';
  if ($timerWarningSeconds !== [] && ($duration_minutes === '' || max($timerWarningSeconds) > (int)$duration_minutes * 60)) $errors[] = 'Timer warnings must fall within the Quiz duration.';
  if (!is_valid_dt($start_at)) $errors[] = 'Start time format: YYYY-MM-DD HH:MM:SS';
  if (!is_valid_dt($end_at)) $errors[] = 'End time format: YYYY-MM-DD HH:MM:SS';

  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $id, (int)$routeUser['id']);

      $stmt = $pdo->prepare("
        UPDATE quizzes
        SET title = :title,
            description = :desc,
            questions_per_attempt = :qpa,
            duration_minutes = :dur,
            shuffle_answers = :shuffle_answers,
            timer_warning_seconds = :timer_warnings,
            allow_mark_for_review = :allow_review,
            result_visibility = :result_visibility,
            is_active = :act,
            start_at = :start_at,
            end_at = :end_at
        WHERE id = :id
        LIMIT 1
      ");
      $params = [
        ':title' => $title,
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
        ':id' => $id,
      ];
      $stmt->execute($params);
      if ($stmt->rowCount() > 1) throw new RuntimeException('Quiz update affected an unexpected number of rows.');
      $pdo->commit();

      qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode("Quiz updated."));
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
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
<section class="adam-card qz-card qz-shell qz-editor-page">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>(string)$quiz['title']]]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Quiz settings</div>
      <h1 class="qz-page-title">Edit Quiz</h1>
      <p class="qz-page-description qz-context">Update the Attempt and publication settings for <strong><?= h($title) ?></strong>.</p>
      <div class="qz-meta">ID: <strong><?= (int)$id ?></strong><span class="dot">&bull;</span>Owner: <strong><?= h($ownerLine) ?></strong><?php if ((int)($quiz['is_primary'] ?? 0) === 1): ?><span class="dot">&bull;</span><span class="badge ok">PRIMARY QUIZ</span><?php endif; ?></div>
    </div>
    <div class="qz-page-actions qz-toolbar">
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/questions&amp;quiz_id=<?= (int)$id ?>">Manage Questions</a>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/master&amp;quiz_id=<?= (int)$id ?>">Master Builder</a>
    </div>
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
      <button class="adam-button" type="submit">Save Changes</button>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes">Cancel</a>
    </div>
  </form>
</section>

<?php
