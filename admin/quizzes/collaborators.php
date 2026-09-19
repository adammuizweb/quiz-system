<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.content.manage-any');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quizId = (int)($_REQUEST['quiz_id'] ?? 0);
if ($quizId <= 0) { http_response_code(400); exit('Missing or invalid quiz_id'); }
$actorId = (int)qz_admin_user($pdo)['id'];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid CSRF'); }
    $selected = $_POST['collaborator_ids'] ?? [];
    if (!is_array($selected)) { http_response_code(400); exit('Invalid collaborators'); }
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
            throw new RuntimeException('Collaborator management authorization is no longer valid.');
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
                    throw new RuntimeException('Every collaborator must be an active, unlocked user.');
                }
                if (!user_can($pdo, $id, 'plugin.quiz.workspace.access')) {
                    throw new RuntimeException('Every collaborator must effectively have Quiz workspace access.');
                }
            }
        }

        $quizLock = $pdo->prepare('SELECT id, created_by FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
        $quizLock->execute([':id' => $quizId]);
        $lockedQuiz = $quizLock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedQuiz) throw new RuntimeException('Quiz not found.');
        if (in_array((int)$lockedQuiz['created_by'], $selectedIds, true)) throw new RuntimeException('The quiz owner must not be assigned as a collaborator.');

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
<section class="adam-card">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>(string)$quiz['title'],'url'=>$base.'/?page=admin/quiz/quizzes/edit&id='.$quizId],['label'=>'Collaborators']]); ?>
  <h2>Quiz Collaborators</h2>
  <p><strong><?= $h($quiz['title']) ?></strong> (ID <?= (int)$quiz['id'] ?>)</p>
  <p class="muted">Owner: <?= $h($quiz['owner_name'] ?: ('User#' . $quiz['created_by'])) ?><?= $quiz['owner_email'] ? ' (' . $h($quiz['owner_email']) . ')' : '' ?>. Owner access is always preserved.</p>
  <?php if (isset($_GET['msg'])): ?><div class="adam-alert success"><?= $h($_GET['msg']) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="adam-alert error"><?= $h($error) ?></div><?php endforeach; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
    <h3>Eligible workspace users</h3>
    <?php if ($eligible === []): ?><p class="muted">No active, unlocked users currently have Quiz workspace access.</p><?php endif; ?>
    <div style="display:grid;gap:8px;margin:12px 0">
      <?php foreach ($eligible as $candidate): $candidateId = (int)$candidate['id']; ?>
        <label class="qz-card" style="display:flex;gap:10px;align-items:center;padding:10px">
          <input type="checkbox" name="collaborator_ids[]" value="<?= $candidateId ?>" <?= isset($currentIds[$candidateId]) ? 'checked' : '' ?>>
          <span><strong><?= $h($candidate['name'] ?: ('User#' . $candidateId)) ?></strong> <?= $h($candidate['email']) ?> <span class="muted"><?= $h($candidate['role']) ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>
    <h3>Current assignments</h3>
    <?php if ($current === []): ?><p class="muted">No collaborators assigned.</p><?php else: ?>
      <ul><?php foreach ($current as $assigned): ?><li><?= $h($assigned['name'] ?: ('User#' . $assigned['user_id'])) ?> (<?= $h($assigned['email']) ?>)<?= ((int)$assigned['is_deleted'] || (int)$assigned['is_locked']) ? ' - unavailable; saving removes it unless eligible again' : '' ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:16px"><button class="adam-button" type="submit">Save collaborators</button><a class="adam-button ghost" href="<?= $h($base) ?>/?page=admin/quiz/quizzes">Back</a></div>
  </form>
</section>
