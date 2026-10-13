<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/master';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$quiz_id = (int)($_GET['quiz_id'] ?? 0);

if ($quiz_id <= 0) {
  $user = qz_admin_user($pdo);
  $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160, 'UTF-8');
  $status = (string)($_GET['status'] ?? '');
  if (!in_array($status, ['', '1', '0'], true)) $status = '';
  $primary = (string)($_GET['primary'] ?? '');
  if (!in_array($primary, ['', '1', '0'], true)) $primary = '';
  $perOptions = [12, 24, 48];
  $perPage = (int)($_GET['per'] ?? 12);
  if (!in_array($perPage, $perOptions, true)) $perPage = 12;
  $pageNum = max(1, (int)($_GET['p'] ?? 1));

  $where = [];
  $params = [];
  if (!$user['can_manage_any']) {
    $where[] = qz_quiz_access_sql($pdo, $user['id'], $params, 'q', 'master_uid');
  }
  if ($q !== '') {
    $where[] = '(title LIKE :q OR description LIKE :q OR CAST(id AS CHAR) = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = $q;
  }
  if ($status !== '') {
    $where[] = 'is_active = :status';
    $params[':status'] = (int)$status;
  }
  if ($primary !== '') {
    $where[] = 'is_primary = :primary';
    $params[':primary'] = (int)$primary;
  }
  $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM quizzes q' . $whereSql);
  $countStmt->execute($params);
  $totalRows = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  $pageNum = min($pageNum, $totalPages);
  $offset = ($pageNum - 1) * $perPage;

  $sql = "SELECT q.id, q.title, q.description, q.is_primary, q.is_active FROM quizzes q{$whereSql} ORDER BY q.is_primary DESC, q.id DESC LIMIT {$perPage} OFFSET {$offset}";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $quizzes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

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
  ?>
  <section class="adam-card">
    <?php qz_admin_breadcrumb([['label'=>'Master Builder']]); ?>
    <h2>Master Builder</h2>
    <p style="opacity:.8;margin:.4rem 0 1rem">Select the quiz you want to manage.</p>

    <form method="get" class="filters">
      <input type="hidden" name="page" value="<?= h($pluginPage) ?>">
      <div>
        <label for="master-q">Search</label><br>
        <input class="adam-input" id="master-q" name="q" value="<?= h($q) ?>" placeholder="ID / title / description">
      </div>
      <div>
        <label for="master-status">Status</label><br>
        <select class="adam-input" id="master-status" name="status">
          <option value="">All statuses</option>
          <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div>
        <label for="master-primary">Primary</label><br>
        <select class="adam-input" id="master-primary" name="primary">
          <option value="">All</option>
          <option value="1" <?= $primary === '1' ? 'selected' : '' ?>>Primary</option>
          <option value="0" <?= $primary === '0' ? 'selected' : '' ?>>Not primary</option>
        </select>
      </div>
      <div>
        <label for="master-per">Per page</label><br>
        <select class="adam-input" id="master-per" name="per">
          <?php foreach ($perOptions as $option): ?><option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <button class="adam-button" type="submit">Filter</button>
        <a class="adam-button ghost" href="<?= h($base) ?>/?page=<?= h($pluginPage) ?>">Reset</a>
      </div>
    </form>

    <div class="meta">
      <span>Showing <?= $rangeStart ?>–<?= $rangeEnd ?> of <?= $totalRows ?> quizzes.</span>
      <?php if ($totalPages > 1): ?>
        <div class="pager" aria-label="Quiz pagination">
          <?php if ($pageNum > 1): ?><a class="pg" href="<?= h($pageUrl($pageNum - 1)) ?>">Previous</a><?php else: ?><span class="pg dis" aria-disabled="true">Previous</span><?php endif; ?>
          <span class="pg dis">Page <?= $pageNum ?> / <?= $totalPages ?></span>
          <?php if ($pageNum < $totalPages): ?><a class="pg" href="<?= h($pageUrl($pageNum + 1)) ?>">Next</a><?php else: ?><span class="pg dis" aria-disabled="true">Next</span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$quizzes): ?>
      <div style="padding:.8rem;border:1px dashed var(--adam-border-2,#cbd5e1);border-radius:12px;color:var(--adam-muted,#64748b)">No quizzes yet.</div>
      <p style="margin-top:1rem"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Manage Quizzes</a></p>
    <?php else: ?>
      <?php if ($user['can_manage_quizzes']): ?><form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/bulk_status" data-bulk-form>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="redirect" value="<?= h($redirectBack) ?>">
        <div class="qz-bulkbar">
          <label class="qz-bulkbar__all"><input type="checkbox" data-bulk-all> Select this page</label>
          <span data-bulk-count role="status" aria-live="polite">0 selected</span>
          <select class="adam-input" name="is_active" data-bulk-action aria-label="Bulk action">
            <option value="">Select action</option>
            <option value="1">Activate</option>
            <option value="0">Deactivate</option>
          </select>
          <button class="adam-button" type="submit" data-bulk-submit disabled>Apply</button>
        </div><?php endif; ?>
        <div class="quiz-pick-grid">
          <?php foreach ($quizzes as $quiz): ?>
            <article class="quiz-pick-card qz-select-card">
              <?php if ($user['can_manage_quizzes']): ?><label class="qz-card-select"><input type="checkbox" name="ids[]" value="<?= (int)$quiz['id'] ?>" data-bulk-item> Select</label><?php endif; ?>
              <a class="quiz-pick-link" href="<?=h($base)?>/?page=<?=$pluginPage?>&quiz_id=<?=(int)$quiz['id']?>&return=<?= rawurlencode($redirectBack) ?>">
                <div class="quiz-pick-title"><?=h($quiz['title'])?></div>
                <?php if (!empty($quiz['description'])): ?><div class="quiz-pick-desc"><?= h(mb_strimwidth((string)$quiz['description'], 0, 120, '…', 'UTF-8')) ?></div><?php endif; ?>
                <div class="quiz-pick-meta">
                  <?php if ((int)$quiz['is_primary']===1): ?><span class="tag tag-primary">primary</span><?php endif; ?>
                  <span class="tag"><?=(int)$quiz['is_active']===1?'Active':'Inactive'?></span>
                  <span class="tag">ID: <?=(int)$quiz['id']?></span>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php if ($user['can_manage_quizzes']): ?></form><?php endif; ?>
      <p style="margin-top:1rem"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Manage Quizzes</a></p>
    <?php endif; ?>
  </section>
  <?php return;
}

require __DIR__ . '/main.php';
