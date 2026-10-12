<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.view');
require __DIR__ . '/../../_init.php';
require_once __DIR__ . '/_monitoring.php';
require_once __DIR__ . '/_export.php';
qz_attempt_no_store();

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/attempts';

$messages = [];
$errors   = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode($_GET['err']);

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fmt_dt')) {
  function fmt_dt(?string $dt, bool $withDay=false, bool $withTime=true): string {
    if (empty($dt)) return '-';
    $timestamp = strtotime($dt);
    if ($timestamp === false) return (string)$dt;
    return date($withTime ? 'M j, Y H:i' : 'M j, Y', $timestamp);
  }
}

function badge_status($status){
  $status = (string)$status;
  $map = [
    'registered'  => 'Registered',
    'verified'    => 'Verified',
    'in_progress' => 'In progress',
    'submitted'   => 'Submitted',
    'expired'     => 'Expired',
  ];
  $label = $map[$status] ?? $status;
  $cls = str_replace('_', '-', 'badge badge--' . $status);
  return '<span class="'.h($cls).'">'.h($label).'</span>';
}

function fmt_score100(float $v): string {
  $v = max(0.0, min(100.0, $v));
  $r = round($v, 2);
  if (abs($r - round($r)) < 0.000001) return (string)(int)round($r);
  return rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');
}

$filterQuery = qz_attempt_filters($_GET);
$filters = $filterQuery['values'];
$params = $filterQuery['params'];
$whereSql = $filterQuery['where_sql'];
$orderBy = $filterQuery['order_sql'];

$student_id = $filters['student_id'];
$quiz_id = $filters['quiz_id'];
$status = $filters['status'];
$deliveryMode = $filters['delivery_mode'];
$q = $filters['q'];
$date_field = $filters['date_field'];
$date_from = $filters['date_from'];
$date_to = $filters['date_to'];
$sort = $filters['sort'];

$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$user = qz_admin_user($pdo);
$xlsxSupport = qz_attempt_xlsx_support();

$quizStmt = $pdo->query('SELECT id, title FROM quizzes ORDER BY id DESC');
$quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "
  SELECT COUNT(*)
  FROM quiz_attempts a
  JOIN students s ON s.id = a.student_id
  JOIN quizzes q ON q.id = a.quiz_id
  {$whereSql}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNum = min($pageNum, $totalPages);
$offset = ($pageNum - 1) * $perPage;

$stmt = $pdo->prepare(qz_attempt_monitoring_sql($whereSql, $orderBy, $perPage, $offset));
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageQuery = $_GET;
$pageQuery['page'] = $pluginPage;
unset($pageQuery['p'], $pageQuery['msg'], $pageQuery['err']);
$pageUrl = static function (int $target) use ($base, $pageQuery): string {
  $query = $pageQuery;
  $query['p'] = $target;
  return $base . '/?' . http_build_query($query);
};
$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $totalRows);
$pageSubmitted = 0;
$pageInProgress = 0;
foreach ($rows as $row) {
  if ((string)($row['status'] ?? '') === 'submitted') $pageSubmitted++;
  if ((string)($row['status'] ?? '') === 'in_progress') $pageInProgress++;
}

$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$redirectBack = $base . '/?' . ($qs !== '' ? $qs : 'page=admin/quiz/attempts');

$today = date('Y-m-d');
$todayUrl = static function (string $field, string $status = '') use ($base, $today, $student_id): string {
  $query = [
    'page' => 'admin/quiz/attempts',
    'date_field' => $field,
    'date_from' => $today,
    'date_to' => $today,
  ];
  if ($status !== '') $query['status'] = $status;
  if ($student_id > 0) $query['student_id'] = $student_id;
  return $base . '/?' . http_build_query($query);
};
?>


<section class="adam-card qz-card qz-shell qz-attempt-page" data-column-scope>
  <?php qz_admin_breadcrumb([['label'=>'Attempts']]); ?>
  <header class="qz-head qz-page-head">
    <div class="qz-page-head__copy">
      <div class="qz-kicker qz-eyebrow">Quiz monitoring</div>
      <h1 class="qz-page-title">Student Attempts</h1>
      <p class="qz-meta qz-page-description">Monitor the progress, verification status, score, and activity of each Attempt. Filter results by identity, quiz, status, or date range.</p>
    </div>
  </header>

  <?php if ($messages): ?>
    <div class="adam-alert success">
      <?php foreach ($messages as $m): ?><div><?= h($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="adam-alert error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="qz-stats qz-metric-grid" aria-label="Attempt results summary">
    <div class="qz-stat qz-stat--result qz-metric"><span>Total results</span><strong><?= $totalRows ?></strong></div>
    <div class="qz-stat qz-metric"><span>On this page</span><strong><?= count($rows) ?></strong></div>
    <div class="qz-stat qz-metric"><span>Submitted on this page</span><strong><?= $pageSubmitted ?></strong></div>
    <div class="qz-stat qz-metric"><span>In progress</span><strong><?= $pageInProgress ?></strong></div>
  </div>

  <section class="qz-section" aria-labelledby="attempt-filter-title">
  <div class="qz-section__head">
    <div><h2 id="attempt-filter-title">Filter and sort</h2><p class="muted">Combine filters to find Attempts that need review.</p></div>
  </div>
  <form method="get" class="filters qz-command-bar qz-form-grid" role="search" aria-label="Filter Attempts">
    <input type="hidden" name="page" value="admin/quiz/attempts">
    <?php if ($student_id > 0): ?><input type="hidden" name="student_id" value="<?= $student_id ?>"><?php endif; ?>

    <div class="qz-field">
      <label for="attempts-quiz">Quiz</label>
      <select id="attempts-quiz" name="quiz_id" class="adam-input">
        <option value="">All quizzes</option>
        <?php foreach ($quizzes as $qz): ?>
          <option value="<?= (int)$qz['id'] ?>" <?= $quiz_id===(int)$qz['id']?'selected':'' ?>>
            <?= h($qz['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="qz-field">
      <label for="attempts-status">Status</label>
      <select id="attempts-status" name="status" class="adam-input">
        <option value="">All statuses</option>
        <?php foreach (['registered','verified','in_progress','submitted','expired'] as $st): ?>
          <option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h(['registered'=>'Registered','verified'=>'Verified','in_progress'=>'In progress','submitted'=>'Submitted','expired'=>'Expired'][$st]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="qz-field">
      <label for="attempts-mode">Delivery mode</label>
      <select id="attempts-mode" name="delivery_mode" class="adam-input">
        <option value="">All modes</option>
        <option value="online" <?= $deliveryMode==='online'?'selected':'' ?>>Online</option>
        <option value="onsite" <?= $deliveryMode==='onsite'?'selected':'' ?>>Onsite</option>
        <option value="unset" <?= $deliveryMode==='unset'?'selected':'' ?>>Not set</option>
      </select>
    </div>

    <div class="qz-field">
      <label for="attempts-q">Search identity</label>
      <input class="adam-input" id="attempts-q" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, email, phone, alias, or Test number">
    </div>

    <div class="qz-field">
      <label for="attempts-date-field">Date field</label>
      <select id="attempts-date-field" name="date_field" class="adam-input">
        <option value="submitted_at" <?= $date_field==='submitted_at'?'selected':'' ?>>Submitted time</option>
        <option value="started_at" <?= $date_field==='started_at'?'selected':'' ?>>Start time</option>
        <option value="created_at" <?= $date_field==='created_at'?'selected':'' ?>>Created time</option>
        <option value="last_activity_at" <?= $date_field==='last_activity_at'?'selected':'' ?>>Last activity</option>
      </select>
    </div>

    <div class="qz-field">
      <label for="attempts-date-from">From date</label>
      <input class="adam-input" id="attempts-date-from" type="date" name="date_from" value="<?= h($date_from) ?>">
    </div>

    <div class="qz-field">
      <label for="attempts-date-to">To date</label>
      <input class="adam-input" id="attempts-date-to" type="date" name="date_to" value="<?= h($date_to) ?>">
    </div>

    <div class="qz-field">
      <label for="attempts-sort">Sort by</label>
      <select id="attempts-sort" name="sort" class="adam-input">
        <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option>
        <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest</option>
        <option value="score100_desc" <?= $sort==='score100_desc'?'selected':'' ?>>Score out of 100 (highest)</option>
        <option value="score100_asc" <?= $sort==='score100_asc'?'selected':'' ?>>Score out of 100 (lowest)</option>
        <option value="score_desc" <?= $sort==='score_desc'?'selected':'' ?>>Legacy score (highest)</option>
        <option value="score_asc" <?= $sort==='score_asc'?'selected':'' ?>>Legacy score (lowest)</option>
        <option value="activity_desc" <?= $sort==='activity_desc'?'selected':'' ?>>Last activity</option>
      </select>
    </div>

    <div class="qz-field">
      <label for="attempts-per">Rows per page</label>
      <select id="attempts-per" name="per" class="adam-input">
        <?php foreach ($perPageOptions as $option): ?>
          <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="qz-filterbar__actions qz-form-actions">
      <button class="adam-button" type="submit">Apply filters</button>

      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/attempts">Clear filters</a>

      <a class="btn-soft" href="<?= h($todayUrl('started_at')) ?>">Started today</a>
      <a class="btn-soft" href="<?= h($todayUrl('submitted_at', 'submitted')) ?>">Submitted today</a>
    </div>
  </form>
  </section>

  <?php if ($user['can_export_attempts']): ?>
    <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/export" class="qz-command-bar qz-form-actions" data-attempt-export-form aria-label="Export Attempt results">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="export">
      <?php foreach (['student','quiz','status','mode','verification','progress','score100','activity'] as $column): ?>
        <input type="hidden" name="columns[]" value="<?= h($column) ?>" data-attempt-export-column>
      <?php endforeach; ?>
      <?php foreach ($filters as $name => $value): ?>
        <input type="hidden" name="<?= h($name) ?>" value="<?= h((string)$value) ?>">
      <?php endforeach; ?>
      <button class="adam-button" type="submit" name="format" value="xlsx" <?= $xlsxSupport['available'] ? '' : 'disabled' ?>>Export Excel</button>
      <button class="adam-button ghost" type="submit" name="format" value="csv">Export CSV</button>
      <span class="muted">The export uses the active filters and table columns.</span>
      <?php if (!$xlsxSupport['available']): ?>
        <span class="muted" title="<?= h($xlsxSupport['message']) ?>">Excel is not available on this server. Use CSV instead.</span>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <section class="qz-section" aria-labelledby="attempt-results-title">
  <div class="qz-resultbar qz-section__head">
    <span id="attempt-results-title">Showing <b><?= $rangeStart ?></b>-<b><?= $rangeEnd ?></b> of <b><?= $totalRows ?></b> Attempts</span>
    <div class="qz-view-controls"><details class="qz-column-picker" data-attempt-column-picker data-storage-key="quiz-attempt-columns-v1"><summary>Configure columns</summary><div class="qz-column-picker__panel"><div class="qz-column-picker__title">Table and export columns</div><label><input type="checkbox" data-attempt-column="rank"> No.</label><label><input type="checkbox" checked disabled> Student <small>Required</small></label><label><input type="checkbox" data-attempt-column="quiz" checked> Quiz</label><label><input type="checkbox" checked disabled> Status <small>Required</small></label><label><input type="checkbox" data-attempt-column="mode" checked> Mode</label><label><input type="checkbox" data-attempt-column="verification" checked> Verification</label><label><input type="checkbox" data-attempt-column="progress" checked> Progress</label><label><input type="checkbox" data-attempt-column="legacy_score"> Legacy score</label><label><input type="checkbox" data-attempt-column="score100" checked> Score out of 100</label><label><input type="checkbox" data-attempt-column="reset"> Reset</label><label><input type="checkbox" data-attempt-column="activity" checked> Activity</label><label><input type="checkbox" checked disabled> Actions <small>Required</small></label></div></details></div>
  </div>

  <div class="table-scroll qz-section__body" role="region" aria-label="Attempts table" tabindex="0">
    <table class="table qz-data-table qz-attempt-table" data-attempt-column-table>
      <thead>
        <tr>
          <th data-column="rank" hidden>No.</th>
          <th>Student</th>
          <th data-column="quiz">Quiz</th>
          <th>Status</th>
          <th data-column="mode" title="The participant selects the delivery mode.">Mode</th>
          <th data-column="verification" title="The method recorded after the Attempt is Verified. This does not prove that an OTP was sent or received.">Verification</th>
          <th data-column="progress">Progress</th>
          <th data-column="legacy_score" hidden>Legacy score</th>
          <th data-column="score100">Score out of 100</th>
          <th data-column="reset" hidden>Reset</th>
          <th data-column="activity">Activity</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" class="qz-empty-cell"><div class="qz-empty-state"><strong>No Attempts found</strong><div>No data matches the current filters.</div><a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/attempts">Clear filters</a></div></td></tr>
      <?php else: ?>
        <?php $rank = $offset; foreach ($rows as $r): ?>
          <?php
            $rank++;

            $totalQ = (int)($r['total_questions'] ?? 0);
            $ansC   = (int)($r['answered_count'] ?? 0);

            $score    = (float)($r['total_score'] ?? 0);
            $correct  = (int)($r['correct_count'] ?? 0);
            $nilai100 = (float)($r['score100'] ?? 0.0);

            $verified = $r['email_verified_at'] ? ($r['verified_via'] ?: 'email') : '—';
            $lastRaw  = $r['last_activity_at'] ?: $r['created_at'];
            $lastFmt  = fmt_dt($lastRaw, false, true);
            $alias    = (string)($r['attempt_alias'] ?? '');
          ?>
          <tr>
            <td class="muted" data-label="No." data-column="rank" hidden><strong><?= (int)$rank ?></strong></td>

            <td class="qz-attempt-student-cell" data-label="Student">
              <div class="qz-attempt-student__head">
                <strong class="qz-attempt-student__name">
                <?= h($r['display_name']) ?>
                </strong>
                <?php if ((int)($r['identity_locked'] ?? 0) === 1): ?>
                  <span class="badge mini badge--expired">Locked</span>
                <?php endif; ?>
              </div>
              <div class="qz-attempt-student__contact">
                <?php if ((string)($r['display_email'] ?? '') !== ''): ?><span><small>Email</small><?= h((string)$r['display_email']) ?></span><?php endif; ?>
                <?php if ((string)($r['display_phone'] ?? '') !== ''): ?><span><small>Phone</small><?= h((string)$r['display_phone']) ?></span><?php endif; ?>
              </div>
              <div class="qz-attempt-student__ids">
                <span><small>Test number</small><code><?= h((string)($r['nomor_test'] ?: '-')) ?></code></span>
                <span><small>Alias</small><code><?= h((string)($r['attempt_alias'] ?: '-')) ?></code></span>
              </div>
            </td>

            <td data-label="Quiz" data-column="quiz">
              <strong><?= h($r['quiz_title']) ?></strong>
              <div class="muted"><?= $r['submitted_at'] ? 'Submitted' : 'Not submitted' ?></div>
            </td>

            <td data-label="Status"><?= badge_status($r['status']) ?></td>

            <td data-label="Mode" data-column="mode">
              <?php if (in_array((string)($r['delivery_mode'] ?? ''), ['online', 'onsite'], true)): ?>
                <span class="badge mini badge--verified"><?= h(ucfirst((string)$r['delivery_mode'])) ?></span>
              <?php else: ?>
                <span class="muted">Not set</span>
              <?php endif; ?>
            </td>

            <td data-label="Verification" data-column="verification">
              <?php if ($verified !== '—'): ?>
                <span class="badge mini badge--verified"><?= h($verified) ?></span>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>

            <td data-label="Progress" data-column="progress">
              <div class="kpi">
                <span class="pill"><?= (int)$ansC ?>/<?= (int)$totalQ ?> answered</span>
                <span class="pill" title="Correct Answers (computed_score > 0)"><?= (int)$correct ?>/<?= (int)$totalQ ?> correct</span>
              </div>
            </td>

            <td data-label="Legacy score" data-column="legacy_score" hidden>
              <span class="pill-score" title="Total score (legacy)">
                <?= (abs($score - round($score)) < 0.000001) ? (int)round($score) : rtrim(rtrim(number_format($score,2,'.',''),'0'),'.') ?>
              </span>
            </td>

            <td data-label="Score out of 100" data-column="score100">
              <span class="pill-100" title="(Correct Answers / total Questions) x 100">
                <?= h(fmt_score100($nilai100)) ?>
              </span>
            </td>

            <td data-label="Reset" data-column="reset" hidden><?= (int)($r['reset_count'] ?? 0) ?></td>

            <td class="muted" data-label="Activity" data-column="activity"><?= h($lastFmt) ?></td>

            <td class="qz-row-actions qz-attempt-actions" data-label="Actions">
              <div class="user-actions" data-qz-action-menu>
                <button type="button" class="user-actions-toggle" id="attempt-actions-toggle-<?= (int)$r['attempt_id'] ?>" data-qz-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="attempt-actions-<?= (int)$r['attempt_id'] ?>" aria-label="Actions for <?= h((string)$r['display_name']) ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                </button>
                <div class="user-actions-menu" id="attempt-actions-<?= (int)$r['attempt_id'] ?>" data-qz-action-panel role="menu" aria-labelledby="attempt-actions-toggle-<?= (int)$r['attempt_id'] ?>" hidden>
                  <a role="menuitem" data-qz-action-item href="<?= h($base) ?>/?page=admin/quiz/attempts/view&amp;<?= $alias !== '' ? 'a=' . rawurlencode($alias) : 'attempt_id=' . (int)$r['attempt_id'] ?>"><?= svg_ico('eye') ?> <span>View Details</span></a>
                  <?php if ($user['can_delete_attempts']): ?>
                    <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_delete" class="qz-action-menu__form">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="attempt_id" value="<?= (int)$r['attempt_id'] ?>">
                      <input type="hidden" name="redirect" value="<?= h($redirectBack) ?>">
                      <button type="submit" role="menuitem" class="is-danger" data-qz-action-item
                         data-confirm="Delete Attempt <?= h($alias !== '' ? $alias : '#' . (int)$r['attempt_id']) ?>?\n\nThe Question set, Answers, and related monitoring logs will also be deleted.\nThis action cannot be undone." data-confirm-variant="danger"><?= svg_ico('trash-2') ?> <span>Delete Attempt</span></button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($totalPages>1):?><nav class="qz-pagination" aria-label="Attempt list pagination"><?php if($pageNum>1):?><a href="<?=h($pageUrl($pageNum-1))?>">Previous</a><?php else:?><span class="disabled">Previous</span><?php endif;?><div class="qz-pagination__pages"><?php for($number=max(1,$pageNum-2);$number<=min($totalPages,$pageNum+2);$number++):?><a href="<?=h($pageUrl($number))?>" <?=$number===$pageNum?'aria-current="page"':''?>><?=$number?></a><?php endfor;?></div><span>Page <?=$pageNum?> of <?=$totalPages?></span><?php if($pageNum<$totalPages):?><a href="<?=h($pageUrl($pageNum+1))?>">Next</a><?php else:?><span class="disabled">Next</span><?php endif;?></nav><?php endif;?>

  <p class="muted qz-footnote">
    Progress includes only Answers with a value. Blank text and empty multiple-choice selections (<code>[]</code>) are not counted.
  </p>
  </section>
</section>
<?php
