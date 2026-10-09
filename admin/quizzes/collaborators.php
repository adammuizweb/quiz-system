<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.content.manage-any');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quizId = (int)($_REQUEST['quiz_id'] ?? 0);
if ($quizId <= 0) { http_response_code(400); exit('quiz_id is missing or invalid'); }
$actorId = (int)qz_admin_user($pdo)['id'];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }
    $selected = $_POST['collaborator_ids'] ?? [];
    if (!is_array($selected)) { http_response_code(400); exit('Invalid collaborator data'); }
    $selectedIds = [];
    foreach ($selected as $value) {
        if (!is_scalar($value)) continue;
        $id = (int)$value;
        if ($id > 0) $selectedIds[$id] = $id;
    }
    $selectedIds = array_values($selectedIds);
    if (count($selectedIds) > 1000) { http_response_code(400); exit('Too many collaborators'); }

    try {
        $pdo->beginTransaction();
        if (!function_exists('user_can') || !user_can($pdo, $actorId, 'plugin.quiz.content.manage-any')) {
            throw new RuntimeException('Collaborator management authorization has expired.');
        }
        if ($selectedIds !== []) {
            sort($selectedIds, SORT_NUMERIC);
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $users = $pdo->prepare("SELECT id, is_deleted, is_locked FROM users WHERE id IN ({$placeholders}) FOR UPDATE");
            $users->execute($selectedIds);
            $lockedUsers = [];
            foreach ($users->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $lockedUsers[(int)$row['id']] = $row;
            foreach ($selectedIds as $id) {
                $target = $lockedUsers[$id] ?? null;
                if (!$target || (int)$target['is_deleted'] !== 0 || (int)$target['is_locked'] !== 0) {
                    throw new RuntimeException('Each collaborator must be an active, unlocked user.');
                }
                if (!user_can($pdo, $id, 'plugin.quiz.workspace.access')) {
                    throw new RuntimeException('Each collaborator must have Quiz workspace access.');
                }
            }
        }

        $quizLock = $pdo->prepare('SELECT id, created_by FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
        $quizLock->execute([':id' => $quizId]);
        $lockedQuiz = $quizLock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedQuiz) throw new RuntimeException('Quiz not found.');
        if (in_array((int)$lockedQuiz['created_by'], $selectedIds, true)) throw new RuntimeException('The Quiz owner cannot be assigned as a collaborator.');

        $existingLock = $pdo->prepare('SELECT user_id FROM quiz_collaborators WHERE quiz_id=:quiz_id FOR UPDATE');
        $existingLock->execute([':quiz_id' => $quizId]);
        $existingLock->fetchAll(PDO::FETCH_COLUMN);

        if ($selectedIds === []) {
            $pdo->prepare('DELETE FROM quiz_collaborators WHERE quiz_id=:quiz_id')->execute([':quiz_id' => $quizId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $delete = $pdo->prepare("DELETE FROM quiz_collaborators WHERE quiz_id=? AND user_id NOT IN ({$placeholders})");
            $delete->execute(array_merge([$quizId], $selectedIds));
            $insert = $pdo->prepare('INSERT INTO quiz_collaborators (quiz_id, user_id, assigned_by) VALUES (:quiz_id, :user_id, :assigned_by) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by), updated_at=CURRENT_TIMESTAMP');
            foreach ($selectedIds as $id) $insert->execute([':quiz_id' => $quizId, ':user_id' => $id, ':assigned_by' => $actorId]);
        }
        $pdo->commit();
        qz_redirect($base . '/?page=admin/quiz/quizzes/collaborators&quiz_id=' . $quizId . '&msg=' . rawurlencode('Collaborators updated.'));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $error->getMessage();
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); exit('Method not allowed');
}

$quizStmt = $pdo->prepare('SELECT q.id, q.title, q.created_by, u.name AS owner_name, u.email AS owner_email FROM quizzes q LEFT JOIN users u ON u.id=q.created_by WHERE q.id=:id LIMIT 1');
$quizStmt->execute([':id' => $quizId]);
$quiz = $quizStmt->fetch(PDO::FETCH_ASSOC);
if (!$quiz) { http_response_code(404); exit('Quiz not found'); }
$currentStmt = $pdo->prepare('SELECT qc.user_id, u.name, u.email, u.is_deleted, u.is_locked FROM quiz_collaborators qc JOIN users u ON u.id=qc.user_id WHERE qc.quiz_id=:quiz_id ORDER BY u.name, u.email');
$currentStmt->execute([':quiz_id' => $quizId]);
$current = $currentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$currentIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['user_id'], $current), true);
$eligible = [];
$usersStmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE is_deleted=0 AND is_locked=0 AND id<>:owner_id ORDER BY name, email');
$usersStmt->execute([':owner_id' => (int)$quiz['created_by']]);
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
    if (user_can($pdo, (int)$candidate['id'], 'plugin.quiz.workspace.access')) $eligible[] = $candidate;
}
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="adam-card qz-card qz-shell qz-question-page">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>(string)$quiz['title'],'url'=>$base.'/?page=admin/quiz/quizzes/edit&id='.$quizId],['label'=>'Collaborators']]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Per-Quiz access</div>
      <h1 class="qz-page-title">Quiz Collaborators</h1>
      <p class="qz-page-description qz-context">Assign workspace users who can work on the Quiz <strong><?= $h($quiz['title']) ?></strong>.</p>
      <div class="qz-meta">Quiz ID: <strong><?= (int)$quiz['id'] ?></strong><span class="dot">&bull;</span>Owner: <strong><?= $h($quiz['owner_name'] ?: ('User#' . $quiz['created_by'])) ?></strong><?= $quiz['owner_email'] ? ' (' . $h($quiz['owner_email']) . ')' : '' ?></div>
    </div>
    <div class="qz-page-actions qz-toolbar"><a class="adam-button ghost" href="<?= $h($base) ?>/?page=admin/quiz/quizzes">Back to List</a></div>
  </header>

  <?php if (isset($_GET['msg'])): ?><div class="adam-alert success"><?= $h($_GET['msg']) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="adam-alert error" role="alert"><?= $h($error) ?></div><?php endforeach; ?>

  <section class="qz-section" aria-labelledby="collaborator-scope-title">
    <div class="qz-section__head"><div><div class="qz-eyebrow qz-kicker">Scope</div><h2 id="collaborator-scope-title">What does this access allow?</h2></div></div>
    <div class="qz-section__body qz-list-context">
      <p>This assignment applies only to this Quiz. Collaborators can open shared resources but can only edit resources they created.</p>
      <p>The Quiz owner and users with permission to manage all content can still manage every resource. This assignment does not transfer ownership, grant Attempt access, or change the user's global permissions.</p>
      <p>The owner's access is always retained, so the owner does not need to be selected as a collaborator.</p>
    </div>
  </section>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

    <section class="qz-section" aria-labelledby="eligible-collaborators-title">
      <div class="qz-section__head"><div><h2 id="eligible-collaborators-title">Eligible Users</h2><p class="qz-page-description">Only active, unlocked users with Quiz workspace access are shown.</p></div></div>
      <div class="qz-section__body">
      <?php if ($eligible === []): ?>
        <div class="qz-empty-state empty"><h3>No eligible users</h3><p>Grant Quiz workspace access to an active user before assigning them as a collaborator.</p></div>
      <?php else: ?>
      <div class="table-scroll" role="region" aria-label="Eligible users" tabindex="0">
      <table class="qz-data-table qz-table qz-management-table">
        <thead><tr><th>Select</th><th>User</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
      <?php foreach ($eligible as $candidate): $candidateId = (int)$candidate['id']; ?>
        <tr class="qz-select-card">
          <td class="qz-question-select" data-label="Select"><input id="collaborator-<?= $candidateId ?>" type="checkbox" name="collaborator_ids[]" value="<?= $candidateId ?>" <?= isset($currentIds[$candidateId]) ? 'checked' : '' ?> aria-label="Select <?= $h($candidate['name'] ?: ('User#' . $candidateId)) ?> as a collaborator"></td>
          <td class="qz-question-cell" data-label="User"><label for="collaborator-<?= $candidateId ?>"><strong><?= $h($candidate['name'] ?: ('User#' . $candidateId)) ?></strong></label></td>
          <td data-label="Email"><?= $h($candidate['email']) ?></td>
          <td data-label="Role"><span class="badge muted"><?= $h($candidate['role']) ?></span></td>
        </tr>
      <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
      </div>
    </section>

    <section class="qz-section" aria-labelledby="current-collaborators-title">
      <div class="qz-section__head"><div><h2 id="current-collaborators-title">Current Assignments</h2><p class="qz-page-description">Collaborator access currently saved for this Quiz.</p></div></div>
      <div class="qz-section__body">
      <?php if ($current === []): ?>
        <div class="qz-empty-state empty"><h3>No collaborators yet</h3><p>Select one or more users above if this Quiz is managed collaboratively.</p></div>
      <?php else: ?>
        <div class="table-scroll" role="region" aria-label="Current collaborators" tabindex="0">
        <table class="qz-data-table qz-table qz-management-table">
          <thead><tr><th>Collaborator</th><th>Email</th><th>Account Status</th></tr></thead>
          <tbody>
          <?php foreach ($current as $assigned): ?>
            <tr>
              <td class="qz-question-cell" data-label="Collaborator"><strong><?= $h($assigned['name'] ?: ('User#' . $assigned['user_id'])) ?></strong></td>
              <td data-label="Email"><?= $h($assigned['email']) ?></td>
              <td data-label="Account Status"><?php if ((int)$assigned['is_deleted'] || (int)$assigned['is_locked']): ?><span class="badge warn">Unavailable; will be removed when saved</span><?php else: ?><span class="badge ok">Active</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      </div>
    </section>

    <div class="qz-form-actions qz-editor-actions"><button class="adam-button" type="submit">Save Collaborators</button><a class="adam-button ghost" href="<?= $h($base) ?>/?page=admin/quiz/quizzes">Cancel</a></div>
  </form>
</section>
