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
    $where[] = '(q.title LIKE :q OR q.description LIKE :q OR CAST(q.id AS CHAR) = :qid)';
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
      q.id, q.title, q.description,
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
  $errors[] = "DB error saat load quiz. Cek error_log.";
}

if ($primaryCount > 1) {
  $errors[] = "Warning: terdeteksi {$primaryCount} quiz berstatus PRIMARY. PRIMARY seharusnya hanya 1 (global). Klik 'Jadikan Primary' pada salah satu quiz.";
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
?>
<section class="adam-card">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <?php qz_admin_breadcrumb([['label'=>'Quiz']]); ?>
      <h2 style="margin:0">Quiz Manager</h2>
      <div class="muted" style="margin-top:6px">Kelola quiz, set primary global, dan akses soal.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?php if ($user['can_manage_quizzes']): ?><a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/quizzes/add">+ Buat Quiz</a><?php endif; ?>
    </div>
  </div>

  <form method="get" class="filters">
    <input type="hidden" name="page" value="<?= h($pluginPage) ?>">
    <div>
      <label for="quizzes-q">Cari</label><br>
      <input class="adam-input" id="quizzes-q" name="q" value="<?= h($q) ?>" placeholder="ID / judul / deskripsi">
    </div>
    <div>
      <label for="quizzes-status">Status</label><br>
      <select class="adam-input" id="quizzes-status" name="status">
        <option value="">Semua status</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
      </select>
    </div>
    <div>
      <label for="quizzes-primary">Primary</label><br>
      <select class="adam-input" id="quizzes-primary" name="primary">
        <option value="">Semua</option>
        <option value="1" <?= $primary === '1' ? 'selected' : '' ?>>Primary</option>
        <option value="0" <?= $primary === '0' ? 'selected' : '' ?>>Bukan primary</option>
      </select>
    </div>
    <div>
      <label for="quizzes-per">Per halaman</label><br>
      <select class="adam-input" id="quizzes-per" name="per">
        <?php foreach ($perPageOptions as $option): ?><option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <button class="adam-button" type="submit">Filter</button>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=<?= h($pluginPage) ?>">Reset</a>
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
      <div style="margin-top:8px;font-size:12px;opacity:.85">
        Debug: session user_id = <b><?= (int)$uid ?></b>
      </div>
    </div>
  <?php endif; ?>

  <!-- ===== Quiz List ===== -->
  <div class="meta">
    <span>Menampilkan <?= $rangeStart ?>–<?= $rangeEnd ?> dari <?= $totalRows ?> quiz.</span>
    <?php if ($totalPages > 1): ?>
      <div class="pager" aria-label="Pagination quiz">
        <?php if ($pageNum > 1): ?><a class="pg" href="<?= h($pageUrl($pageNum - 1)) ?>">Sebelumnya</a><?php else: ?><span class="pg dis" aria-disabled="true">Sebelumnya</span><?php endif; ?>
        <span class="pg dis">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
        <?php if ($pageNum < $totalPages): ?><a class="pg" href="<?= h($pageUrl($pageNum + 1)) ?>">Berikutnya</a><?php else: ?><span class="pg dis" aria-disabled="true">Berikutnya</span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$quizzes): ?>
    <div class="empty">Belum ada quiz yang cocok.</div>
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
    <div class="qz-list">
    <?php foreach ($quizzes as $qz):
      $qid = (int)$qz['id'];
      $isPrimary = ((int)($qz['is_primary'] ?? 0) === 1);
      $isActive  = ((int)($qz['is_active'] ?? 0) === 1);
      $owner = trim((string)($qz['owner_name'] ?? ''));
      $oemail = trim((string)($qz['owner_email'] ?? ''));
      $ownerLine = ($owner !== '' || $oemail !== '')
        ? (($owner !== '' ? $owner : 'User#'.$qid) . ($oemail !== '' ? " · {$oemail}" : ''))
        : ('User#'.$qid);
      $ansTotal = (int)($qz['answer_count'] ?? 0);
      $badZero  = (int)($qz['choice_no_answer_count'] ?? 0);
      $missingAns = ($badZero > 0);

      $quizJson = h((string)json_encode([
        'id' => $qid,
        'title' => $qz['title'],
        'description' => $qz['description'] ?? '',
        'owner' => $ownerLine,
        'is_active' => $isActive,
        'is_primary' => $isPrimary,
        'can_set_primary' => $canManagePrimary,
        'can_manage_collaborators' => $user['can_manage_any'],
        'can_manage_quizzes' => $user['can_manage_quizzes'],
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
    <div class="qz-row">
      <?php if ($user['can_manage_quizzes']): ?><label class="qz-row-select" aria-label="Pilih quiz <?= h($qz['title']) ?>"><input type="checkbox" name="ids[]" value="<?= $qid ?>" data-bulk-item></label><?php endif; ?>
      <div class="qz-row-id">#<?= $qid ?></div>
      <div class="qz-row-body">
        <div class="qz-row-title"><?= h($qz['title']) ?></div>
        <div class="qz-row-sub">
          <span><?= h($ownerLine) ?></span>
          <?php if (!empty($qz['description'])): ?>
            <span class="sep">&bull;</span>
            <span class="desc-text"><?= h(cut($qz['description'], 100)) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="qz-row-stats">
        <div class="qz-stat-item" title="Jumlah soal">
          <span class="qz-stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
          </span>
          <span><?= (int)$qz['question_count'] ?></span>
          <?php if ($missingAns): ?>
            <span class="badge mini badge--bad" title="<?= $badZero ?> soal belum punya jawaban aktif">!<?= $badZero ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="qz-row-status">
        <span class="badge <?= $isActive ? 'badge--ok' : 'badge--muted' ?>"><?= $isActive ? 'Aktif' : 'Nonaktif' ?></span>
        <?php if ($isPrimary): ?><span class="badge badge--primary">PRIMARY</span><?php endif; ?>
      </div>
      <div class="qz-row-action">
        <button type="button" class="qz-detail-btn" data-quiz='<?= $quizJson ?>'>Detail</button>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  <?php if ($user['can_manage_quizzes']): ?></form><?php endif; ?>
  <?php endif; ?>
</section>

<!-- ===== Quiz Detail Modal ===== -->
<div id="quizDetailModal">
  <div class="modal-dialog">
    <div class="modal-head">
      <h3 id="qdm-title">Quiz</h3>
      <button type="button" class="modal-close">&times;</button>
    </div>
    <div class="modal-body" id="qdm-body"></div>
    <div class="modal-foot" id="qdm-foot"></div>
  </div>
</div>

<?php
