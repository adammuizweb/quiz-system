<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.students.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/students';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('quiz_redirect')) {
  function quiz_redirect(string $url): void {
    if (!headers_sent()) { while(ob_get_level()>0){@ob_end_clean();} header("Location: $url", true, 303); exit; }
    echo '<script>location.replace('.json_encode($url).');</script>'; exit;
  }
}

$do = trim((string)($_POST['do'] ?? $_GET['do'] ?? ''));

if ($do === 'delete') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
  $id = (int)($_POST['id'] ?? 0);
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if ($id > 0 && csrf_check($csrf)) {
    try {
      $pdo->prepare("DELETE FROM students WHERE id=:id LIMIT 1")->execute([':id'=>$id]);
      quiz_redirect("$base/?page=$pluginPage&msg=" . urlencode("Siswa dihapus."));
    } catch (\Throwable $e) {}
  }
  quiz_redirect("$base/?page=$pluginPage&err=" . urlencode("Request tidak valid."));
  return;
}

if ($do === 'add' || $do === 'edit') {
  $id = (int)($_GET['id'] ?? 0);
  $editing = $do === 'edit' && $id > 0;
  $student = ['name'=>'','email'=>'','phone'=>''];
  if ($editing) {
    $st = $pdo->prepare("SELECT * FROM students WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $student = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$student) { echo '<p>Student not found.</p>'; return; }
  }
  $errors = [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'CSRF invalid.';
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($name === '') $errors[] = 'Name is required.';
    if (!$errors) {
      if ($editing) {
        $pdo->prepare("UPDATE students SET name=:name,email=:email,phone=:phone WHERE id=:id")->execute([':name'=>$name,':email'=>$email?:null,':phone'=>$phone?:null,':id'=>$id]);
      } else {
        $cookieId = qz_unique_student_cookie_id($pdo);
        $pdo->prepare("INSERT INTO students (cookie_id,name,email,phone) VALUES (:cookie_id,:name,:email,:phone)")->execute([':cookie_id'=>$cookieId,':name'=>$name,':email'=>$email?:null,':phone'=>$phone?:null]);
      }
      quiz_redirect("$base/?page=$pluginPage&msg=" . urlencode($editing?"Siswa diupdate.":"Siswa ditambahkan."));
    }
    $student = ['name'=>$name,'email'=>$email,'phone'=>$phone];
  } ?>
  <section class="adam-card qz-card qz-editor-page qz-student-editor">
    <?php qz_admin_breadcrumb([['label'=>'Student','url'=>$base.'/?page='.$pluginPage],['label'=>$editing?'Edit Student':'Tambah Student']]); ?>
    <div class="qz-head"><div><div class="qz-kicker">Peserta quiz</div><h2 style="margin:0"><?=$editing?'Edit':'Tambah'?> Student</h2><p class="qz-context"><?=$editing?'Perbarui identitas dan kontak peserta.':'Tambahkan peserta yang akan mengikuti quiz.'?></p></div><div class="qz-toolbar"><a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Daftar Student</a></div></div>
    <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>
    <form method="post" class="qz-editor-section" novalidate>
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <div class="qz-student-form-grid">
        <label class="qz-student-form-name"><span>Nama lengkap</span><input class="adam-input" name="name" value="<?=h($student['name'])?>" required></label>
        <label><span>Email</span><input class="adam-input" name="email" value="<?=h($student['email']??'')?>" type="email" placeholder="nama@example.com"></label>
        <label><span>Nomor HP</span><input class="adam-input" name="phone" value="<?=h($student['phone']??'')?>" placeholder="628xxxxxxxxxx"></label>
      </div>
      <div class="qz-editor-actions">
        <button class="adam-button" type="submit">Simpan Student</button>
        <a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Batal</a>
      </div>
    </form>
  </section>
  <?php return;
}

$messages = []; $errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode((string)$_GET['err']);

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160, 'UTF-8');
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$params = []; $where = [];
if ($q !== '') {
  $where[] = '(s.name LIKE :q OR s.email LIKE :q OR s.phone LIKE :q)';
  $params[':q'] = '%'.$q.'%';
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$summaryStmt = $pdo->query("SELECT COUNT(DISTINCT s.id) AS total,COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN s.id END) AS with_attempts,COUNT(DISTINCT CASE WHEN a.status='submitted' THEN s.id END) AS with_submitted FROM students s LEFT JOIN quiz_attempts a ON a.student_id=s.id");
$summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: ['total'=>0,'with_attempts'=>0,'with_submitted'=>0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNum = min($pageNum, $totalPages);
$offset = ($pageNum - 1) * $perPage;

$sql = "SELECT s.*,
  (SELECT COUNT(*) FROM quiz_attempts a WHERE a.student_id = s.id) AS attempt_count,
  (SELECT COUNT(*) FROM quiz_attempts a WHERE a.student_id = s.id AND a.status='submitted') AS submitted_count,
  (SELECT MAX(a.last_activity_at) FROM quiz_attempts a WHERE a.student_id = s.id) AS last_activity_at,
  (SELECT a.id FROM quiz_attempts a WHERE a.student_id = s.id ORDER BY a.id DESC LIMIT 1) AS last_attempt_id,
  (SELECT a.attempt_alias FROM quiz_attempts a WHERE a.student_id = s.id ORDER BY a.id DESC LIMIT 1) AS last_attempt_alias
  FROM students s $whereSql ORDER BY s.id DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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
$formatDate = static function (mixed $value): string {
  $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
  return $timestamp !== false ? date('d/m/Y H:i', $timestamp) : '-';
};
?>
<section class="adam-card qz-card qz-student-page" data-column-scope>
  <?php qz_admin_breadcrumb([['label'=>'Student']]); ?>
  <div class="qz-head">
    <div><div class="qz-kicker">Peserta quiz</div><h2 style="margin:0">Student Manager</h2><div class="qz-meta">Kelola identitas peserta dan akses riwayat attempt mereka.</div></div>
    <div class="qz-toolbar"><a class="adam-button" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=add">+ Tambah Siswa</a></div>
  </div>

  <div class="qz-stats" aria-label="Ringkasan student">
    <div class="qz-stat"><span>Total Student</span><strong><?=(int)$summary['total']?></strong></div>
    <div class="qz-stat"><span>Punya Attempt</span><strong><?=(int)$summary['with_attempts']?></strong></div>
    <div class="qz-stat"><span>Pernah Submitted</span><strong><?=(int)$summary['with_submitted']?></strong></div>
    <div class="qz-stat qz-stat--result"><span>Hasil Filter</span><strong><?=$totalRows?></strong></div>
  </div>

  <form method="get" class="qz-filterbar qz-student-filterbar">
    <input type="hidden" name="page" value="<?=$pluginPage?>">
    <label><span>Cari student</span><input class="adam-input" id="students-q" name="q" value="<?=h($q)?>" placeholder="Nama, email, atau nomor HP..."></label>
    <label><span>Per halaman</span><select class="adam-input" id="students-per" name="per"><?php foreach ($perPageOptions as $option): ?><option value="<?=$option?>" <?=$perPage === $option ? 'selected':''?>><?=$option?></option><?php endforeach; ?></select></label>
    <div class="qz-filterbar__actions"><button class="adam-button" type="submit">Terapkan</button><?php if($q!==''||$perPage!==25):?><a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Reset</a><?php endif;?></div>
  </form>
  <?php if ($messages): ?><div class="adam-alert success"><?php foreach($messages as $m):?><div><?=h($m)?></div><?php endforeach;?></div><?php endif; ?>
  <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>

  <div class="qz-resultbar">
    <span>Menampilkan <b><?=$rangeStart?></b>-<b><?=$rangeEnd?></b> dari <b><?=$totalRows?></b> student</span>
    <div class="qz-view-controls"><details class="qz-column-picker" data-student-column-picker data-storage-key="quiz-student-columns-v1"><summary>Kolom</summary><div class="qz-column-picker__panel"><div class="qz-column-picker__title">Kolom yang ditampilkan</div><label><input type="checkbox" checked disabled> Student <small>Wajib</small></label><label><input type="checkbox" data-student-column="contact" checked> Kontak</label><label><input type="checkbox" data-student-column="attempts" checked> Attempt</label><label><input type="checkbox" data-student-column="submitted" checked> Submitted</label><label><input type="checkbox" data-student-column="activity" checked> Aktivitas Terakhir</label><label><input type="checkbox" checked disabled> Aksi <small>Wajib</small></label></div></details></div>
  </div>

  <table class="qz-table qz-student-table" data-student-column-table>
    <thead><tr><th>Student</th><th width="260" data-column="contact">Kontak</th><th width="100" data-column="attempts">Attempt</th><th width="110" data-column="submitted">Submitted</th><th width="160" data-column="activity">Aktivitas Terakhir</th><th width="80">Aksi</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="qz-empty-cell"><?=$q!==''?'Tidak ada student yang cocok dengan pencarian.':'Belum ada student.'?></td></tr>
    <?php else: foreach ($rows as $r): $sid = (int)$r['id'];
      $lastAlias = trim((string)($r['last_attempt_alias']??''));
      $lastAttemptId = (int)($r['last_attempt_id']??0);
      $lastAttemptUrl = $lastAlias !== '' ? $base.'/?page=admin/quiz/attempts/view&a='.rawurlencode($lastAlias) : ($lastAttemptId > 0 ? $base.'/?page=admin/quiz/attempts/view&attempt_id='.$lastAttemptId : '');
      $email = trim((string)($r['email']??''));
      $phone = trim((string)($r['phone']??''));
    ?>
      <tr>
        <td class="qz-student-cell" data-label="Student"><div class="qz-student-name"><?=h($r['name'])?></div><div class="qz-student-meta">Terdaftar <?=$formatDate($r['created_at']??null)?></div></td>
        <td data-label="Kontak" data-column="contact"><div class="qz-student-contact"><?php if($email!==''):?><span><?=h($email)?></span><?php endif;?><?php if($phone!==''):?><span><?=h($phone)?></span><?php endif;?><?php if($email===''&&$phone===''):?><span class="muted">Belum ada kontak</span><?php endif;?></div></td>
        <td data-label="Attempt" data-column="attempts"><span class="badge count"><?=(int)$r['attempt_count']?></span></td>
        <td data-label="Submitted" data-column="submitted"><span class="badge <?=((int)$r['submitted_count']>0)?'ok':'muted'?>"><?=(int)$r['submitted_count']?></span></td>
        <td data-label="Aktivitas Terakhir" data-column="activity"><span class="qz-student-activity"><?=$formatDate($r['last_activity_at']??null)?></span></td>
        <td class="qz-row-actions qz-student-actions" data-label="Aksi"><div class="qz-action-menu qz-action-menu--compact"><button type="button" class="qz-action-menu__toggle" id="student-actions-toggle-<?=$sid?>" aria-expanded="false" aria-controls="student-actions-<?=$sid?>" aria-label="Aksi untuk <?=h((string)$r['name'])?>"><span class="qz-action-menu__dots" aria-hidden="true">&#8942;</span></button><div class="qz-action-menu__panel" id="student-actions-<?=$sid?>" role="group" aria-labelledby="student-actions-toggle-<?=$sid?>" hidden><a class="qz-action-menu__item" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=edit&id=<?=$sid?>">Edit</a><a class="qz-action-menu__item" href="<?=h($base)?>/?page=admin/quiz/attempts&student_id=<?=$sid?>">Lihat Attempt</a><?php if ($lastAttemptUrl): ?><a class="qz-action-menu__item" href="<?=h($lastAttemptUrl)?>">Attempt Terakhir</a><?php endif; ?><form method="post" action="<?=h($base)?>/?page=<?=$pluginPage?>" class="qz-action-menu__form"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?=$sid?>"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><button type="submit" class="qz-action-menu__item qz-action-menu__item--danger" data-confirm="Hapus siswa ini?\n\nNama: <?=h($r['name'])?>\nAttempt: <?=(int)$r['attempt_count']?> (submitted: <?=(int)$r['submitted_count']?>)\n\nAksi ini akan menghapus SEMUA attempt siswa (jawaban, paket soal, proctor logs ikut terhapus via CASCADE).\nTindakan tidak bisa dibatalkan." data-confirm-variant="danger">Hapus</button></form></div></div></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if($totalPages>1):?><nav class="qz-pagination" aria-label="Navigasi halaman student"><?php if($pageNum>1):?><a href="<?=h($pageUrl($pageNum-1))?>">Sebelumnya</a><?php else:?><span class="disabled">Sebelumnya</span><?php endif;?><div class="qz-pagination__pages"><?php for($number=max(1,$pageNum-2);$number<=min($totalPages,$pageNum+2);$number++):?><a href="<?=h($pageUrl($number))?>" <?=$number===$pageNum?'aria-current="page"':''?>><?=$number?></a><?php endfor;?></div><span>Halaman <?=$pageNum?> / <?=$totalPages?></span><?php if($pageNum<$totalPages):?><a href="<?=h($pageUrl($pageNum+1))?>">Berikutnya</a><?php else:?><span class="disabled">Berikutnya</span><?php endif;?></nav><?php endif;?>
</section>
<?php
