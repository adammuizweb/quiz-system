<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/quizzes';
$uid  = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('cut')) {
  function cut($s, $n=160){
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (mb_strlen($s,'UTF-8') <= $n) return $s;
    return mb_substr($s,0,$n-1,'UTF-8') . '…';
  }
}

$messages = [];
$errors   = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode($_GET['err']);

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160, 'UTF-8');
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', '1', '0'], true)) $status = '';
$primary = (string)($_GET['primary'] ?? '');
if (!in_array($primary, ['', '1', '0'], true)) $primary = '';
$perPageOptions = [10, 25, 50];
$perPage = (int)($_GET['per'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 10;
$pageNum = max(1, (int)($_GET['p'] ?? 1));

$quizzes = [];
$primaryCount = 0;
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$user = qz_admin_user($pdo);
$canManagePrimary = $user['id'] > 0 && function_exists('user_can')
  && user_can($pdo, $user['id'], 'plugin.quiz.primary.manage');
$globalVisualTheme = quiz_safe_visual_theme(settings_get($pdo, 'quiz_visual_theme', 'default'));
$visualThemeLabels = ['default' => 'Default', 'studio' => 'Studio', 'navy' => 'Navy'];

try {
  $countSql = "SELECT COUNT(*) FROM quizzes WHERE is_primary=1";
  $countParams = [];
  if (!$user['can_manage_any']) {
    $countSql = "SELECT COUNT(*) FROM quizzes q WHERE q.is_primary=1 AND " . qz_quiz_access_sql($pdo, $user['id'], $countParams, 'q', 'primary_uid');
  }
  $stmt = $pdo->prepare($countSql);
  $stmt->execute($countParams);
  $primaryCount = (int)$stmt->fetchColumn();

  $params = [];
  $where = [];
  if (!$user['can_manage_any']) {
    $where[] = qz_quiz_access_sql($pdo, $user['id'], $params, 'q', 'list_uid');
  }
  if ($q !== '') {
    $where[] = '(q.title LIKE :q OR q.description LIKE :q OR q.public_prefix LIKE :q OR CAST(q.id AS CHAR) = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = $q;
  }
  if ($status !== '') {
    $where[] = 'q.is_active = :status';
    $params[':status'] = (int)$status;
  }
  if ($primary !== '') {
    $where[] = 'q.is_primary = :primary';
    $params[':primary'] = (int)$primary;
  }
  $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

  $listCount = $pdo->prepare('SELECT COUNT(*) FROM quizzes q' . $whereSql);
  $listCount->execute($params);
  $totalRows = (int)$listCount->fetchColumn();
  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  $pageNum = min($pageNum, $totalPages);
  $offset = ($pageNum - 1) * $perPage;

  $sql = "
    SELECT
      q.id, q.title, q.public_prefix, q.description, q.visual_theme_override,
      q.is_active, q.is_primary,
      q.questions_per_attempt, q.duration_minutes,
      q.start_at, q.end_at, q.created_at,
      q.created_by,
      u.name AS owner_name, u.email AS owner_email,
      (SELECT COUNT(*) FROM questions qq WHERE qq.quiz_id = q.id) AS question_count,
      (SELECT COUNT(*)
       FROM question_answers qa
       JOIN questions qq ON qq.id = qa.question_id
       WHERE qq.quiz_id = q.id
         AND qa.is_active = 1
      ) AS answer_count,
      (SELECT COUNT(*)
       FROM questions qq
       WHERE qq.quiz_id = q.id
         AND qq.is_active = 1
         AND qq.question_type IN ('single_choice','multiple_choice','yesno')
         AND NOT EXISTS (
           SELECT 1
           FROM question_answers qa
           WHERE qa.question_id = qq.id
             AND qa.is_active = 1
         )
      ) AS choice_no_answer_count
    FROM quizzes q
    LEFT JOIN users u ON u.id = q.created_by
    {$whereSql}
    ORDER BY q.id DESC
    LIMIT {$perPage} OFFSET {$offset}
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $quizzes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

} catch (\Throwable $e) {
  error_log("[admin/quiz/quizzes/index] " . $e->getMessage());
  $errors[] = "Database error while loading Quizzes. Check error_log.";
}

if ($primaryCount > 1) {
  $errors[] = "Warning: {$primaryCount} Primary Quizzes were found. Only one Primary Quiz is allowed globally. Set one Quiz as the Primary Quiz.";
}

$pageQuery = $_GET;
$pageQuery['page'] = $pluginPage;
unset($pageQuery['p'], $pageQuery['msg'], $pageQuery['err']);
$pageUrl = static function (int $target) use ($base, $pageQuery): string {
  $query = $pageQuery;
  $query['p'] = $target;
  return $base . '/?' . http_build_query($query);
};
$currentQuery = $pageQuery;
$currentQuery['p'] = $pageNum;
$redirectBack = $base . '/?' . http_build_query($currentQuery);
$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $totalRows);
$pageActiveCount = count(array_filter($quizzes, static fn(array $quiz): bool => (int)($quiz['is_active'] ?? 0) === 1));
$pageIssueCount = count(array_filter($quizzes, static fn(array $quiz): bool => (int)($quiz['choice_no_answer_count'] ?? 0) > 0));
$hasFilters = $q !== '' || $status !== '' || $primary !== '';
?>
<section class="adam-card qz-card qz-shell qz-question-page qz-quiz-list-page">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <?php qz_admin_breadcrumb([['label'=>'Quiz']]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Quiz administration</div>
      <h1 class="qz-page-title">Quiz List</h1>
      <p class="qz-page-description qz-meta">Search, review readiness, update status, and open Quiz details.</p>
    </div>
    <div class="qz-page-actions qz-toolbar">
      <?php if ($user['can_manage_quizzes']): ?><a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/quizzes/add">Create Quiz</a><?php endif; ?>
    </div>
  </header>

  <div class="qz-metric-grid qz-stats" aria-label="Quiz list summary">
    <div class="qz-metric qz-stat qz-stat--result"><span>Filtered Results</span><strong><?= $totalRows ?></strong></div>
    <div class="qz-metric qz-stat"><span>Active on This Page</span><strong><?= $pageActiveCount ?></strong></div>
    <div class="qz-metric qz-stat"><span>Need Active Answers</span><strong><?= $pageIssueCount ?></strong></div>
    <div class="qz-metric qz-stat"><span>Primary Quiz</span><strong><?= $primaryCount ?></strong></div>
  </div>

  <form method="get" class="qz-command-bar qz-filterbar">
    <input type="hidden" name="page" value="<?= h($pluginPage) ?>">
    <label class="qz-field qz-filterbar__search" for="quizzes-q"><span>Search Quizzes</span><input class="adam-input" type="search" id="quizzes-q" name="q" value="<?= h($q) ?>" placeholder="ID, title, prefix, or description..."></label>
    <label class="qz-field" for="quizzes-status"><span>Status</span>
      <select class="adam-input" id="quizzes-status" name="status">
        <option value="">All Statuses</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </label>
    <label class="qz-field" for="quizzes-primary"><span>Primary Quiz</span>
      <select class="adam-input" id="quizzes-primary" name="primary">
        <option value="">All</option>
        <option value="1" <?= $primary === '1' ? 'selected' : '' ?>>Primary Quiz</option>
        <option value="0" <?= $primary === '0' ? 'selected' : '' ?>>Not Primary</option>
      </select>
    </label>
    <label class="qz-field" for="quizzes-per"><span>Per Page</span>
      <select class="adam-input" id="quizzes-per" name="per">
        <?php foreach ($perPageOptions as $option): ?><option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="qz-filterbar__actions">
      <button class="adam-button" type="submit">Apply</button>
      <?php if ($hasFilters || $perPage !== 10): ?><a class="adam-button ghost" href="<?= h($base) ?>/?page=<?= h($pluginPage) ?>">Reset</a><?php endif; ?>
    </div>
  </form>

  <?php if ($messages): ?>
    <div class="adam-alert success">
      <?php foreach ($messages as $m): ?><div><?= h($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="adam-alert error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
      <div class="dbg">
        Debug: session user_id = <b><?= (int)$uid ?></b>
      </div>
    </div>
  <?php endif; ?>

  <div class="qz-resultbar">
    <span>Showing <strong><?= $rangeStart ?></strong>-<strong><?= $rangeEnd ?></strong> of <strong><?= $totalRows ?></strong> Quizzes</span>
  </div>

  <?php if (!$quizzes): ?>
    <div class="qz-empty-state empty">
      <h2><?= $hasFilters ? 'No matching Quizzes' : 'No Quizzes yet' ?></h2>
      <p><?= $hasFilters ? 'Change the search term or filters to broaden the results.' : 'Create the first Quiz to prepare an assessment.' ?></p>
      <div class="qz-page-actions qz-toolbar">
        <?php if ($hasFilters): ?><a class="adam-button ghost" href="<?= h($base) ?>/?page=<?= h($pluginPage) ?>">Clear Filters</a><?php endif; ?>
        <?php if ($user['can_manage_quizzes']): ?><a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/quizzes/add">Create Quiz</a><?php endif; ?>
      </div>
    </div>
  <?php else: ?>
  <?php if ($user['can_manage_quizzes']): ?><form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/bulk_status" data-bulk-form>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="redirect" value="<?= h($redirectBack) ?>">
    <div class="qz-bulkbar">
      <label class="qz-bulkbar__all"><input type="checkbox" data-bulk-all> Select This Page</label>
      <span data-bulk-count role="status" aria-live="polite">0 selected</span>
      <select class="adam-input" name="is_active" data-bulk-action aria-label="Bulk action">
        <option value="">Select Action</option>
        <option value="1">Activate</option>
        <option value="0">Deactivate</option>
      </select>
      <button class="adam-button" type="submit" data-bulk-submit disabled>Apply</button>
    </div><?php endif; ?>
    <div class="table-scroll" role="region" aria-label="Quiz list" tabindex="0">
    <table class="qz-data-table qz-table qz-management-table qz-management-table--actions">
      <thead>
        <tr>
          <?php if ($user['can_manage_quizzes']): ?><th><span class="visually-hidden">Select</span></th><?php endif; ?>
          <th>ID</th>
          <th>Quiz</th>
          <th>Questions</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
    <?php foreach ($quizzes as $qz):
      $qid = (int)$qz['id'];
      $isPrimary = ((int)($qz['is_primary'] ?? 0) === 1);
      $isActive  = ((int)($qz['is_active'] ?? 0) === 1);
      $owner = trim((string)($qz['owner_name'] ?? ''));
      $oemail = trim((string)($qz['owner_email'] ?? ''));
      $ownerLine = ($owner !== '' || $oemail !== '')
        ? (($owner !== '' ? $owner : 'User#'.(int)$qz['created_by']) . ($oemail !== '' ? " · {$oemail}" : ''))
        : ('User#'.(int)$qz['created_by']);
      $ansTotal = (int)($qz['answer_count'] ?? 0);
      $badZero  = (int)($qz['choice_no_answer_count'] ?? 0);
      $missingAns = ($badZero > 0);
      $publicPrefix = trim((string)($qz['public_prefix'] ?? ''));
      $publicUrl = $publicPrefix !== '' ? '/' . $publicPrefix . '/register/' : '';
      $visualThemeOverride = quiz_safe_visual_theme_override($qz['visual_theme_override'] ?? null);
      $visualTheme = $visualThemeOverride ?? $globalVisualTheme;
      $visualThemeLabel = $visualThemeLabels[$visualTheme] ?? 'Default';
      $visualThemeSource = $visualThemeOverride === null ? 'Global' : 'Override';
      $canDelete = $user['can_manage_quizzes'] && ($user['can_manage_any'] || (int)$qz['created_by'] === (int)$user['id']);

      $quizJson = h((string)json_encode([
        'id' => $qid,
        'title' => $qz['title'],
        'description' => $qz['description'] ?? '',
        'public_url' => $publicUrl,
        'owner' => $ownerLine,
        'is_active' => $isActive,
        'is_primary' => $isPrimary,
        'can_set_primary' => $canManagePrimary,
        'can_manage_collaborators' => $user['can_manage_any'],
        'can_manage_quizzes' => $user['can_manage_quizzes'],
        'can_delete' => $canDelete,
        'visual_theme' => $visualThemeLabel,
        'visual_theme_source' => $visualThemeSource,
        'questions' => (int)$qz['question_count'],
        'answers' => $ansTotal,
        'badzero' => $badZero,
        'per_attempt' => (int)$qz['questions_per_attempt'],
        'duration' => $qz['duration_minutes'],
        'start_at' => $qz['start_at'] ?? '',
        'end_at' => $qz['end_at'] ?? '',
        'created_at' => $qz['created_at'],
      ]));
    ?>
    <tr class="qz-select-card">
      <?php if ($user['can_manage_quizzes']): ?><td class="qz-question-select" data-label="Select"><input type="checkbox" name="ids[]" value="<?= $qid ?>" data-bulk-item aria-label="Select Quiz <?= h($qz['title']) ?>"></td><?php endif; ?>
      <td class="qz-row-id" data-label="ID">#<?= $qid ?></td>
      <td class="qz-row-body qz-question-cell" data-label="Quiz">
        <div class="qz-row-title"><?= h($qz['title']) ?></div>
        <div class="qz-row-sub">
          <span class="qz-row-owner"><?= h($ownerLine) ?></span>
          <span class="qz-row-theme" data-theme="<?= h($visualTheme) ?>" title="<?= h($visualThemeLabel . ' visual theme, ' . strtolower($visualThemeSource)) ?>">
            <i aria-hidden="true"></i><span><?= h($visualThemeLabel) ?></span><small><?= h($visualThemeSource) ?></small>
          </span>
          <?php if ($publicUrl !== ''): ?><span class="sep">&bull;</span><a href="<?= h($publicUrl) ?>" target="_blank" rel="noopener"><?= h($publicUrl) ?></a><?php endif; ?>
          <?php if (!empty($qz['description'])): ?>
            <span class="sep">&bull;</span>
            <span class="desc-text"><?= h(cut($qz['description'], 100)) ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td class="qz-quiz-questions-cell" data-label="Questions">
        <div class="qz-table-cell-stack qz-table-cell-stack--questions">
          <strong><?= (int)$qz['question_count'] ?></strong>
          <?php if ($missingAns): ?><span class="badge mini badge--bad" title="<?= $badZero ?> Questions have no Active Answers"><?= $badZero ?> need Answers</span><?php endif; ?>
        </div>
      </td>
      <td class="qz-quiz-status-cell" data-label="Status">
        <div class="qz-table-cell-stack qz-table-cell-stack--status">
          <span class="badge <?= $isActive ? 'badge--ok' : 'badge--muted' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
          <?php if ($isPrimary): ?><span class="badge badge--primary">PRIMARY QUIZ</span><?php endif; ?>
        </div>
      </td>
      <td class="qz-row-action qz-row-actions qz-question-actions" data-label="Actions">
        <button type="button" class="qz-detail-btn" data-quiz='<?= $quizJson ?>' aria-label="View details for Quiz <?= h($qz['title']) ?>">Details</button>
      </td>
    </tr>
    <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php if ($user['can_manage_quizzes']): ?></form><?php endif; ?>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="qz-pagination" aria-label="Quiz list pagination">
      <?php if ($pageNum > 1): ?><a href="<?= h($pageUrl($pageNum - 1)) ?>">Previous</a><?php else: ?><span class="disabled" aria-disabled="true">Previous</span><?php endif; ?>
      <span>Page <?= $pageNum ?> of <?= $totalPages ?></span>
      <?php if ($pageNum < $totalPages): ?><a href="<?= h($pageUrl($pageNum + 1)) ?>">Next</a><?php else: ?><span class="disabled" aria-disabled="true">Next</span><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<div id="quizDetailModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="qdm-title">
  <div class="modal-dialog qz-detail-grid">
    <div class="modal-head">
      <h3 id="qdm-title">Quiz</h3>
      <button type="button" class="modal-close" aria-label="Close Quiz details">&times;</button>
    </div>
    <div class="modal-body" id="qdm-body"></div>
    <div class="modal-foot" id="qdm-foot"></div>
  </div>
</div>

<?php
