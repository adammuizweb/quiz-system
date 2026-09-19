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
    if (function_exists('format_datetime_indo')) {
      return format_datetime_indo($dt, $withDay, $withTime);
    }
    if (function_exists('format_datetime_id')) {
      $x = format_datetime_id($dt, $withDay, $withTime);
      return $x !== '' ? $x : '-';
    }
    return (string)$dt;
  }
}

function badge_status($status){
  $status = (string)$status;
  $map = [
    'registered'  => 'Registered',
    'verified'    => 'Verified',
    'in_progress' => 'In Progress',
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


<section class="adam-card qz-attempt-page" data-column-scope>
  <h2>Attempt Manager</h2>
  <?php qz_admin_breadcrumb([['label'=>'Attempt']]); ?>

  <p class="muted">Monitoring global attempt quiz. Gunakan filter tanggal, identitas, alias, atau nomor test.</p>

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

  <form method="get" class="filters">
    <input type="hidden" name="page" value="admin/quiz/attempts">
    <?php if ($student_id > 0): ?><input type="hidden" name="student_id" value="<?= $student_id ?>"><?php endif; ?>

    <div>
      <label>Quiz</label><br>
      <select name="quiz_id" class="adam-input">
        <option value="">— Semua Quiz —</option>
        <?php foreach ($quizzes as $qz): ?>
          <option value="<?= (int)$qz['id'] ?>" <?= $quiz_id===(int)$qz['id']?'selected':'' ?>>
            <?= h($qz['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Status</label><br>
      <select name="status" class="adam-input" style="min-width:160px">
        <option value="">— Semua —</option>
        <?php foreach (['registered','verified','in_progress','submitted','expired'] as $st): ?>
          <option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Mode</label><br>
      <select name="delivery_mode" class="adam-input" style="min-width:150px">
        <option value="">— Semua —</option>
        <option value="online" <?= $deliveryMode==='online'?'selected':'' ?>>Online</option>
        <option value="onsite" <?= $deliveryMode==='onsite'?'selected':'' ?>>Onsite</option>
        <option value="unset" <?= $deliveryMode==='unset'?'selected':'' ?>>Belum diisi</option>
      </select>
    </div>

    <div>
      <label>Cari</label><br>
      <input class="adam-input" type="text" name="q" value="<?= h($q) ?>" placeholder="Nama / Email / HP / Alias / Nomor Test">
    </div>

    <div>
      <label>Filter Tanggal (Field)</label><br>
      <select name="date_field" class="adam-input" style="min-width:180px">
        <option value="submitted_at" <?= $date_field==='submitted_at'?'selected':'' ?>>Submitted At</option>
        <option value="started_at" <?= $date_field==='started_at'?'selected':'' ?>>Started At</option>
        <option value="created_at" <?= $date_field==='created_at'?'selected':'' ?>>Created At</option>
        <option value="last_activity_at" <?= $date_field==='last_activity_at'?'selected':'' ?>>Last Activity</option>
      </select>
    </div>

    <div>
      <label>Dari</label><br>
      <input class="adam-input" type="date" name="date_from" value="<?= h($date_from) ?>" style="min-width:160px">
    </div>

    <div>
      <label>Sampai</label><br>
      <input class="adam-input" type="date" name="date_to" value="<?= h($date_to) ?>" style="min-width:160px">
    </div>

    <div>
      <label>Urutkan</label><br>
      <select name="sort" class="adam-input" style="min-width:190px">
        <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Terbaru</option>
        <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Terlama</option>
        <option value="score100_desc" <?= $sort==='score100_desc'?'selected':'' ?>>Nilai 100 (Tertinggi)</option>
        <option value="score100_asc" <?= $sort==='score100_asc'?'selected':'' ?>>Nilai 100 (Terendah)</option>
        <option value="score_desc" <?= $sort==='score_desc'?'selected':'' ?>>Total Score (Legacy) Tertinggi</option>
        <option value="score_asc" <?= $sort==='score_asc'?'selected':'' ?>>Total Score (Legacy) Terendah</option>
        <option value="activity_desc" <?= $sort==='activity_desc'?'selected':'' ?>>Aktivitas Terakhir</option>
      </select>
    </div>

    <div>
      <label for="attempts-per">Per halaman</label><br>
      <select id="attempts-per" name="per" class="adam-input" style="min-width:120px">
        <?php foreach ($perPageOptions as $option): ?>
          <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="adam-button" type="submit">Filter</button>

      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/attempts">Reset</a>

      <a class="btn-soft" href="<?= h($todayUrl('started_at')) ?>">Mulai hari ini</a>
      <a class="btn-soft" href="<?= h($todayUrl('submitted_at', 'submitted')) ?>">Submit hari ini</a>
    </div>
  </form>

  <?php if ($user['can_export_attempts']): ?>
    <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/export" data-attempt-export-form style="margin:0 0 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
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
      <span class="muted">Semua hasil sesuai filter dan kolom aktif.</span>
      <?php if (!$xlsxSupport['available']): ?>
        <span class="muted" title="<?= h($xlsxSupport['message']) ?>">Excel tidak tersedia di hosting ini; gunakan CSV.</span>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <div class="qz-resultbar">
    <span>Menampilkan <b><?= $rangeStart ?></b>-<b><?= $rangeEnd ?></b> dari <b><?= $totalRows ?></b> attempt</span>
    <div class="qz-view-controls"><details class="qz-column-picker" data-attempt-column-picker data-storage-key="quiz-attempt-columns-v1"><summary>Kolom</summary><div class="qz-column-picker__panel"><div class="qz-column-picker__title">Kolom tabel &amp; export</div><label><input type="checkbox" data-attempt-column="rank"> No.</label><label><input type="checkbox" checked disabled> Siswa <small>Wajib</small></label><label><input type="checkbox" data-attempt-column="quiz" checked> Quiz</label><label><input type="checkbox" checked disabled> Status <small>Wajib</small></label><label><input type="checkbox" data-attempt-column="mode" checked> Mode</label><label><input type="checkbox" data-attempt-column="verification" checked> Verifikasi</label><label><input type="checkbox" data-attempt-column="progress" checked> Progress</label><label><input type="checkbox" data-attempt-column="legacy_score"> Nilai Legacy</label><label><input type="checkbox" data-attempt-column="score100" checked> Nilai 100</label><label><input type="checkbox" data-attempt-column="reset"> Reset</label><label><input type="checkbox" data-attempt-column="activity" checked> Aktivitas</label><label><input type="checkbox" checked disabled> Aksi <small>Wajib</small></label></div></details></div>
  </div>

  <div class="table-scroll" role="region" aria-label="Tabel attempt" tabindex="0">
    <table class="table qz-attempt-table" data-attempt-column-table>
      <thead>
        <tr>
          <th data-column="rank" hidden>No.</th>
          <th>Siswa</th>
          <th data-column="quiz">Quiz</th>
          <th>Status</th>
          <th data-column="mode" title="Mode pelaksanaan dipilih sendiri oleh peserta.">Mode</th>
          <th data-column="verification" title="Metode yang tercatat setelah attempt diverifikasi. Bukan bukti bahwa OTP telah dikirim atau diterima.">Verifikasi</th>
          <th data-column="progress">Progress</th>
          <th data-column="legacy_score" hidden>Nilai Legacy</th>
          <th data-column="score100">Nilai 100</th>
          <th data-column="reset" hidden>Reset</th>
          <th data-column="activity">Aktivitas</th>
          <th width="160">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" style="padding:1rem">Belum ada attempt.</td></tr>
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
            <td class="muted" style="font-weight:800" data-column="rank" hidden><?= (int)$rank ?></td>

            <td class="qz-attempt-student-cell">
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
                <?php if ((string)($r['display_phone'] ?? '') !== ''): ?><span><small>HP</small><?= h((string)$r['display_phone']) ?></span><?php endif; ?>
              </div>
              <div class="qz-attempt-student__ids">
                <span><small>Nomor Test</small><code><?= h((string)($r['nomor_test'] ?: '—')) ?></code></span>
                <span><small>Alias</small><code><?= h((string)($r['attempt_alias'] ?: '—')) ?></code></span>
              </div>
            </td>

            <td data-column="quiz">
              <div style="font-weight:700"><?= h($r['quiz_title']) ?></div>
              <div class="muted"><?= $r['submitted_at'] ? 'Sudah test ✅' : 'Belum submit' ?></div>
            </td>

            <td><?= badge_status($r['status']) ?></td>

            <td data-column="mode">
              <?php if (in_array((string)($r['delivery_mode'] ?? ''), ['online', 'onsite'], true)): ?>
                <span class="badge mini badge--verified"><?= h(ucfirst((string)$r['delivery_mode'])) ?></span>
              <?php else: ?>
                <span class="muted">Belum diisi</span>
              <?php endif; ?>
            </td>

            <td data-column="verification">
              <?php if ($verified !== '—'): ?>
                <span class="badge mini badge--verified"><?= h($verified) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>

            <td data-column="progress">
              <div class="kpi">
                <span class="pill"><?= (int)$ansC ?>/<?= (int)$totalQ ?> dijawab</span>
                <span class="pill" title="Jumlah benar (computed_score > 0)"><?= (int)$correct ?>/<?= (int)$totalQ ?> benar</span>
              </div>
            </td>

            <td data-column="legacy_score" hidden>
              <span class="pill-score" title="Total score (legacy)">
                <?= (abs($score - round($score)) < 0.000001) ? (int)round($score) : rtrim(rtrim(number_format($score,2,'.',''),'0'),'.') ?>
              </span>
            </td>

            <td data-column="score100">
              <span class="pill-100" title="(Jumlah benar / total soal) x 100">
                <?= h(fmt_score100($nilai100)) ?>
              </span>
            </td>

            <td data-column="reset" hidden><?= (int)($r['reset_count'] ?? 0) ?></td>

            <td class="muted" data-column="activity"><?= h($lastFmt) ?></td>

            <td class="qz-row-actions qz-attempt-actions">
              <div class="qz-attempt-actions__inner">
                <a class="adam-button ghost qz-attempt-detail" href="<?= h($base) ?>/?page=admin/quiz/attempts/view&amp;<?= $alias !== '' ? 'a=' . rawurlencode($alias) : 'attempt_id=' . (int)$r['attempt_id'] ?>">Detail</a>

                <?php if ($user['can_delete_attempts']): ?>
                <div class="qz-action-menu qz-action-menu--compact">
                  <button type="button" class="qz-action-menu__toggle" id="attempt-actions-toggle-<?= (int)$r['attempt_id'] ?>" aria-expanded="false" aria-controls="attempt-actions-<?= (int)$r['attempt_id'] ?>" aria-label="Aksi lainnya untuk <?= h((string)$r['display_name']) ?>">
                    <span class="qz-action-menu__dots" aria-hidden="true">&#8942;</span>
                  </button>
                  <div class="qz-action-menu__panel" id="attempt-actions-<?= (int)$r['attempt_id'] ?>" role="group" aria-labelledby="attempt-actions-toggle-<?= (int)$r['attempt_id'] ?>" hidden>
                    <form method="post" action="<?= h($base) ?>/?page=admin/quiz/attempts/action_delete" class="qz-action-menu__form">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="attempt_id" value="<?= (int)$r['attempt_id'] ?>">
                      <input type="hidden" name="redirect" value="<?= h($redirectBack) ?>">
                      <button type="submit" class="qz-action-menu__item qz-action-menu__item--danger"
                        data-confirm="Hapus attempt <?= h($alias !== '' ? $alias : '#' . (int)$r['attempt_id']) ?> ?\n\nIni akan menghapus: paket soal, jawaban, dan proctor logs (CASCADE).\nTindakan tidak bisa dibatalkan." data-confirm-variant="danger">Hapus attempt</button>
                    </form>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($totalPages>1):?><nav class="qz-pagination" aria-label="Navigasi halaman attempt"><?php if($pageNum>1):?><a href="<?=h($pageUrl($pageNum-1))?>">Sebelumnya</a><?php else:?><span class="disabled">Sebelumnya</span><?php endif;?><div class="qz-pagination__pages"><?php for($number=max(1,$pageNum-2);$number<=min($totalPages,$pageNum+2);$number++):?><a href="<?=h($pageUrl($number))?>" <?=$number===$pageNum?'aria-current="page"':''?>><?=$number?></a><?php endfor;?></div><span>Halaman <?=$pageNum?> / <?=$totalPages?></span><?php if($pageNum<$totalPages):?><a href="<?=h($pageUrl($pageNum+1))?>">Berikutnya</a><?php else:?><span class="disabled">Berikutnya</span><?php endif;?></nav><?php endif;?>

  <p class="muted" style="margin-top:12px">
    Progress hanya menghitung jawaban bernilai; teks kosong dan pilihan ganda kosong (<code>[]</code>) tidak dihitung.
  </p>
</section>
<?php
