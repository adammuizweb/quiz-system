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
    <p style="opacity:.8;margin:.4rem 0 1rem">Pilih quiz yang ingin kamu kelola.</p>

    <form method="get" class="filters">
      <input type="hidden" name="page" value="<?= h($pluginPage) ?>">
      <div>
        <label for="master-q">Cari</label><br>
        <input class="adam-input" id="master-q" name="q" value="<?= h($q) ?>" placeholder="ID / judul / deskripsi">
      </div>
      <div>
        <label for="master-status">Status</label><br>
        <select class="adam-input" id="master-status" name="status">
          <option value="">Semua status</option>
          <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
          <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
      </div>
      <div>
        <label for="master-primary">Primary</label><br>
        <select class="adam-input" id="master-primary" name="primary">
          <option value="">Semua</option>
          <option value="1" <?= $primary === '1' ? 'selected' : '' ?>>Primary</option>
          <option value="0" <?= $primary === '0' ? 'selected' : '' ?>>Bukan primary</option>
        </select>
      </div>
      <div>
        <label for="master-per">Per halaman</label><br>
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
      <span>Menampilkan <?= $rangeStart ?>–<?= $rangeEnd ?> dari <?= $totalRows ?> quiz.</span>
      <?php if ($totalPages > 1): ?>
        <div class="pager" aria-label="Pagination master quiz">
          <?php if ($pageNum > 1): ?><a class="pg" href="<?= h($pageUrl($pageNum - 1)) ?>">Sebelumnya</a><?php else: ?><span class="pg dis" aria-disabled="true">Sebelumnya</span><?php endif; ?>
          <span class="pg dis">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
          <?php if ($pageNum < $totalPages): ?><a class="pg" href="<?= h($pageUrl($pageNum + 1)) ?>">Berikutnya</a><?php else: ?><span class="pg dis" aria-disabled="true">Berikutnya</span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$quizzes): ?>
      <div style="padding:.8rem;border:1px dashed var(--adam-border-2,#cbd5e1);border-radius:12px;color:var(--adam-muted,#64748b)">Belum ada quiz.</div>
      <p style="margin-top:1rem"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Kelola Quiz</a></p>
    <?php else: ?>
      <?php if ($user['can_manage_quizzes']): ?><form method="post" action="<?= h($base) ?>/?page=admin/quiz/quizzes/bulk_status" data-bulk-form>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="redirect" value="<?= h($redirectBack) ?>">
        <div class="qz-bulkbar">
          <label class="qz-bulkbar__all"><input type="checkbox" data-bulk-all> Pilih halaman ini</label>
          <span data-bulk-count role="status" aria-live="polite">0 dipilih</span>
          <select class="adam-input" name="is_active" data-bulk-action aria-label="Aksi bulk">
            <option value="">Pilih aksi</option>
            <option value="1">Aktifkan</option>
            <option value="0">Nonaktifkan</option>
          </select>
          <button class="adam-button" type="submit" data-bulk-submit disabled>Terapkan</button>
        </div><?php endif; ?>
        <div class="quiz-pick-grid">
          <?php foreach ($quizzes as $quiz): ?>
            <article class="quiz-pick-card qz-select-card">
              <?php if ($user['can_manage_quizzes']): ?><label class="qz-card-select"><input type="checkbox" name="ids[]" value="<?= (int)$quiz['id'] ?>" data-bulk-item> Pilih</label><?php endif; ?>
              <a class="quiz-pick-link" href="<?=h($base)?>/?page=<?=$pluginPage?>&quiz_id=<?=(int)$quiz['id']?>&return=<?= rawurlencode($redirectBack) ?>">
                <div class="quiz-pick-title"><?=h($quiz['title'])?></div>
                <?php if (!empty($quiz['description'])): ?><div class="quiz-pick-desc"><?= h(mb_strimwidth((string)$quiz['description'], 0, 120, '…', 'UTF-8')) ?></div><?php endif; ?>
                <div class="quiz-pick-meta">
                  <?php if ((int)$quiz['is_primary']===1): ?><span class="tag tag-primary">primary</span><?php endif; ?>
                  <span class="tag"><?=(int)$quiz['is_active']===1?'aktif':'nonaktif'?></span>
                  <span class="tag">ID: <?=(int)$quiz['id']?></span>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php if ($user['can_manage_quizzes']): ?></form><?php endif; ?>
      <p style="margin-top:1rem"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Kelola Quiz</a></p>
    <?php endif; ?>
  </section>
  <?php return;
}

require __DIR__ . '/main.php';
