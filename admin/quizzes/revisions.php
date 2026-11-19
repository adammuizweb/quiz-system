<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$user = qz_admin_user($pdo);
if (!$user['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }

if (!function_exists('h')) {
  function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$quizId = (int)($_GET['id'] ?? 0);
if ($quizId <= 0) { http_response_code(400); exit('Missing or invalid id'); }

$selectedRevision = quiz_revision_for_quiz($pdo, $quizId);
if (!$selectedRevision) { http_response_code(404); exit('Quiz revision not found'); }
if (!$user['can_manage_any']
    && (int)($selectedRevision['instrument_created_by'] ?? 0) !== (int)$user['id']) {
  http_response_code(403);
  exit('Only the Quiz instrument owner may view its revision history.');
}
$instrumentId = (int)$selectedRevision['instrument_id'];

$stmt = $pdo->prepare("SELECT revision.*, q.title, q.description, q.created_at AS quiz_created_at,
    q.questions_per_attempt, q.duration_minutes, q.is_active,
    instrument.public_prefix, instrument.current_quiz_id,
    instrument.created_by AS instrument_created_by,
    owner.name AS owner_name, owner.email AS owner_email,
    creator.name AS revision_creator_name,
    (SELECT COUNT(*) FROM questions question WHERE question.quiz_id=q.id) AS question_count,
    (SELECT COUNT(*) FROM quiz_attempts attempt WHERE attempt.quiz_id=q.id) AS attempt_count
  FROM quiz_instrument_revisions revision
  JOIN quizzes q ON q.id=revision.quiz_id
  JOIN quiz_instruments instrument ON instrument.id=revision.instrument_id
  LEFT JOIN users owner ON owner.id=instrument.created_by
  LEFT JOIN users creator ON creator.id=revision.created_by
  WHERE revision.instrument_id=:instrument_id
  ORDER BY revision.revision_number DESC");
$stmt->execute([':instrument_id' => $instrumentId]);
$revisions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($revisions === []) { http_response_code(404); exit('Quiz revision history not found'); }

$current = null;
$draft = null;
foreach ($revisions as $revision) {
  if ((string)$revision['state'] === 'current') $current = $revision;
  if ((string)$revision['state'] === 'draft') $draft = $revision;
}
if (!$current) { http_response_code(503); exit('Current Quiz revision is unavailable'); }

$ownsInstrument = $user['can_manage_any']
  || (int)($current['instrument_created_by'] ?? 0) === (int)$user['id'];
$canManageRevisions = $user['can_manage_quizzes'] && $ownsInstrument;
$ownerName = trim((string)($current['owner_name'] ?? ''));
$ownerEmail = trim((string)($current['owner_email'] ?? ''));
$ownerLine = $ownerName !== '' ? $ownerName : ('User#' . (int)($current['instrument_created_by'] ?? 0));
if ($ownerEmail !== '') $ownerLine .= ' · ' . $ownerEmail;
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));
$publicPrefix = trim((string)($current['public_prefix'] ?? ''));
?>
<section class="adam-card qz-card qz-shell qz-revision-page">
  <?php qz_admin_breadcrumb([
    ['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],
    ['label'=>(string)$current['title'],'url'=>$base.'/?page=admin/quiz/quizzes/edit&id='.(int)$current['quiz_id']],
    ['label'=>'Revision History'],
  ]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Instrument #<?= $instrumentId ?></div>
      <h1 class="qz-page-title">Revision History</h1>
      <p class="qz-page-description">Manage the publication lineage for <strong><?= h($current['title']) ?></strong>. Published revisions remain immutable for audit integrity.</p>
      <div class="qz-meta">Current: <strong>Revision <?= (int)$current['revision_number'] ?></strong><span class="dot">&bull;</span>Owner: <strong><?= h($ownerLine) ?></strong><?php if ($publicPrefix !== ''): ?><span class="dot">&bull;</span>Public URL: <strong>/<?= h($publicPrefix) ?>/</strong><?php endif; ?></div>
    </div>
    <div class="qz-page-actions qz-toolbar">
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes">Quiz List</a>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes/edit&amp;id=<?= (int)$current['quiz_id'] ?>">Current Settings</a>
      <?php if ($canManageRevisions && !$draft): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/revise">
          <input type="hidden" name="id" value="<?= (int)$current['quiz_id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <button class="adam-button" type="submit" data-confirm="Create a new draft from the current revision? Settings and Question content will be copied without Attempts.">Create Draft</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($message !== ''): ?><div class="adam-alert success" role="status"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="adam-alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$canManageRevisions): ?><div class="adam-alert warning">Revision actions are read-only. Only the instrument owner or an administrator with manage-any access can create, publish, or restore revisions.</div><?php endif; ?>
  <?php if ($draft): ?><div class="qz-revision-draft-callout"><div><strong>Revision <?= (int)$draft['revision_number'] ?> is in progress</strong><span>Finish editing and publish this draft before creating or restoring another revision.</span></div><a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/quizzes/edit&amp;id=<?= (int)$draft['quiz_id'] ?>">Continue Draft</a></div><?php endif; ?>

  <div class="qz-metric-grid qz-stats" aria-label="Revision summary">
    <div class="qz-metric qz-stat qz-stat--result"><span>Total Revisions</span><strong><?= count($revisions) ?></strong></div>
    <div class="qz-metric qz-stat"><span>Current Revision</span><strong><?= (int)$current['revision_number'] ?></strong></div>
    <div class="qz-metric qz-stat"><span>Open Drafts</span><strong><?= $draft ? '1' : '0' ?></strong></div>
    <div class="qz-metric qz-stat"><span>Total Attempts</span><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['attempt_count'], $revisions)) ?></strong></div>
  </div>

  <section class="qz-section qz-revision-ledger">
    <div class="qz-section__head"><h2>Publication Ledger</h2><p>Newest first. Restoring a historical revision creates a new draft and never rewrites this ledger.</p></div>
    <div class="qz-revision-list qz-section__body">
      <?php foreach ($revisions as $revision):
        $state = (string)$revision['state'];
        $rowQuizId = (int)$revision['quiz_id'];
        $revisionNumber = (int)$revision['revision_number'];
        $rowMutable = $state === 'draft'
          || ($state === 'current' && $revisionNumber === 1 && (int)$revision['attempt_count'] === 0);
        $createdBy = trim((string)($revision['revision_creator_name'] ?? ''));
        $createdLabel = $createdBy !== '' ? $createdBy : ('User#' . (int)($revision['created_by'] ?? 0));
        $timestamp = $state === 'current' ? $revision['published_at'] : ($state === 'retired' ? $revision['retired_at'] : $revision['quiz_created_at']);
      ?>
      <article id="revision-<?= $revisionNumber ?>" class="qz-revision-entry qz-revision-entry--<?= h($state) ?>"<?= $rowQuizId === $quizId ? ' aria-current="true"' : '' ?>>
        <div class="qz-revision-marker" aria-hidden="true"><span><?= $revisionNumber ?></span></div>
        <div class="qz-revision-copy">
          <div class="qz-revision-title-row"><div><span class="badge qz-revision-state qz-revision-state--<?= h($state) ?>"><?= h(strtoupper($state)) ?></span><h3>Revision <?= $revisionNumber ?> <small>Quiz #<?= $rowQuizId ?></small></h3></div><time><?= h((string)($timestamp ?? '')) ?></time></div>
          <p><?= h((string)($revision['description'] ?? 'No description.')) ?></p>
          <dl class="qz-revision-facts">
            <div><dt>Questions</dt><dd><?= (int)$revision['question_count'] ?></dd></div>
            <div><dt>Per Attempt</dt><dd><?= (int)$revision['questions_per_attempt'] ?></dd></div>
            <div><dt>Duration</dt><dd><?= $revision['duration_minutes'] === null ? 'No limit' : (int)$revision['duration_minutes'] . ' min' ?></dd></div>
            <div><dt>Attempts</dt><dd><?= (int)$revision['attempt_count'] ?></dd></div>
            <div><dt>Created By</dt><dd><?= h($createdLabel) ?></dd></div>
            <div><dt>Supersedes</dt><dd><?= empty($revision['supersedes_quiz_id']) ? 'Initial revision' : 'Quiz #' . (int)$revision['supersedes_quiz_id'] ?></dd></div>
          </dl>
        </div>
        <div class="qz-revision-actions">
          <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/quizzes/edit&amp;id=<?= $rowQuizId ?>"><?= $rowMutable ? 'Edit Settings' : 'View Settings' ?></a>
          <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/questions&amp;quiz_id=<?= $rowQuizId ?>">View Questions</a>
          <?php if ($canManageRevisions && $state === 'draft'): ?>
            <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/publish_revision">
              <input type="hidden" name="id" value="<?= $rowQuizId ?>">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <button class="adam-button" type="submit" data-confirm="Publish Revision <?= $revisionNumber ?>? New participants will use it while historical Attempts remain linked to their original revision.">Publish Revision</button>
            </form>
          <?php elseif ($canManageRevisions && $state === 'retired' && !$draft): ?>
            <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/restore_revision">
              <input type="hidden" name="id" value="<?= $rowQuizId ?>">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <button class="adam-button ghost" type="submit" data-confirm="Restore Revision <?= $revisionNumber ?> as a new draft? The historical revision will remain unchanged.">Restore as Draft</button>
            </form>
          <?php elseif ($state === 'retired' && $draft): ?>
            <span class="qz-revision-action-note">Restore unavailable while Revision <?= (int)$draft['revision_number'] ?> is a draft.</span>
          <?php elseif ($state === 'current' && !$draft && $canManageRevisions): ?>
            <form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/revise">
              <input type="hidden" name="id" value="<?= $rowQuizId ?>">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <button class="adam-button" type="submit" data-confirm="Create a new draft from Revision <?= $revisionNumber ?>?">Create Draft</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
</section>
