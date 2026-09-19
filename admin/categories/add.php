<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) { http_response_code(400); exit('Missing or invalid quiz_id'); }
$quiz = qz_require_quiz_access($pdo, $quiz_id);
$user = qz_admin_user($pdo);

$errors = [];
$name = ''; $description = ''; $shuffle_passages = 1; $shuffle_questions = 1;
$min_questions = '0'; $max_questions = ''; $is_active = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'CSRF token tidak valid.';
  $name = trim((string)($_POST['name'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $shuffle_passages = (int)($_POST['shuffle_passages'] ?? 1);
  $shuffle_questions = (int)($_POST['shuffle_questions'] ?? 1);
  $is_active = (int)($_POST['is_active'] ?? 1);
  $min_questions = trim((string)($_POST['min_questions'] ?? '0'));
  $max_questions = trim((string)($_POST['max_questions'] ?? ''));
  if ($name === '') $errors[] = 'Nama kategori wajib diisi.';
  if (mb_strlen($name, 'UTF-8') > 120) $errors[] = 'Nama kategori maksimal 120 karakter.';
  $minqVal = 0;
  if ($min_questions !== '') {
    if (!ctype_digit($min_questions)) $errors[] = 'Min questions harus angka (>= 0).';
    else { $minqVal = (int)$min_questions; if ($minqVal < 0) $errors[] = 'Min questions harus >= 0.'; }
  }
  $maxqVal = null;
  if ($max_questions !== '') {
    if (!ctype_digit($max_questions)) $errors[] = 'Max questions harus angka.';
    else { $maxqVal = (int)$max_questions; if ($maxqVal < 1) $errors[] = 'Max questions harus >= 1.'; }
  }
  if ($maxqVal !== null && $minqVal > $maxqVal) $errors[] = 'Min tidak boleh > Max.';
  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
      $sortVal = qz_append_sort_order($pdo, 'categories', $quiz_id);
      $ins = $pdo->prepare("INSERT INTO question_categories (quiz_id,created_by,name,description,shuffle_passages,shuffle_questions,min_questions,max_questions,sort_order,is_active) VALUES (:qid,:created_by,:name,:descr,:sp,:sq,:minq,:maxq,:sort,:act)");
      $ins->execute([':qid'=>$quiz_id,':created_by'=>(int)$user['id'],':name'=>$name,':descr'=>($description===''?null:$description),':sp'=>$shuffle_passages?1:0,':sq'=>$shuffle_questions?1:0,':minq'=>$minqVal,':maxq'=>$maxqVal,':sort'=>$sortVal,':act'=>$is_active?1:0]);
      $pdo->commit();
      qz_redirect("$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id&msg=".urlencode("Kategori berhasil ditambahkan."));
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Kategori gagal disimpan atau akses sudah tidak berlaku.';
    }
  }
}
$backUrl = "$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id";
$masterUrl = "$base/?page=admin/quiz/master&quiz_id=$quiz_id";
?>
<section class="adam-card qz-card qz-editor-page">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title'],'url'=>$masterUrl],['label'=>'Kategori','url'=>$backUrl],['label'=>'Tambah']]); ?>
  <div class="qz-head"><div><div class="qz-kicker">Bank soal</div><h2 style="margin:0">Tambah Kategori</h2><p class="qz-context"><?= h($quiz['title']) ?> · Kategori baru otomatis berada di urutan terakhir.</p></div><div class="qz-toolbar"><a class="adam-button ghost" href="<?=h($backUrl)?>">Daftar Kategori</a><a class="adam-button ghost" href="<?=h($masterUrl)?>">Master Builder</a></div></div>
  <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <div style="display:grid;gap:.5rem;margin-bottom:1rem"><label><strong>Nama</strong></label><input class="adam-input" name="name" value="<?=h($name)?>" maxlength="120" required><div class="small-hint">Maksimal 120 karakter.</div></div>
    <div style="display:grid;gap:.5rem;margin-bottom:1rem"><label><strong>Deskripsi</strong> (opsional)</label><textarea class="adam-input" name="description" rows="4"><?=h($description)?></textarea></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="display:grid;gap:.5rem"><label><strong>Shuffle Passages</strong></label><select class="adam-input" name="shuffle_passages"><option value="1" <?=$shuffle_passages===1?'selected':''?>>Ya</option><option value="0">Tidak</option></select></div>
      <div style="display:grid;gap:.5rem"><label><strong>Shuffle Questions</strong></label><select class="adam-input" name="shuffle_questions"><option value="1" <?=$shuffle_questions===1?'selected':''?>>Ya</option><option value="0">Tidak</option></select></div>
    </div>
    <div style="display:grid;gap:.5rem;margin-bottom:1rem"><label><strong>Min Questions</strong> (opsional)</label><input class="adam-input" name="min_questions" value="<?=h($min_questions)?>" placeholder="0/kosong = tidak wajib"><div class="small-hint">Harus angka >= 0. Jika Max diisi, Min tidak boleh lebih besar.</div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="display:grid;gap:.5rem"><label><strong>Max Questions</strong> (opsional)</label><input class="adam-input" name="max_questions" value="<?=h($max_questions)?>" placeholder="kosong = tidak dibatasi"><div class="small-hint">Harus angka >= 1 atau kosong.</div></div>
      <div style="display:grid;gap:.5rem"><label><strong>Urutan</strong></label><div class="qz-order-note">Kategori baru ditempatkan di akhir. Atur urutan melalui Master Builder.</div></div>
    </div>
    <div style="margin-top:.2rem"><label><strong>Status</strong></label><br><select class="adam-input" name="is_active"><option value="1" <?=$is_active===1?'selected':''?>>Aktif</option><option value="0">Nonaktif</option></select></div>
    <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap"><button class="adam-button" type="submit">Simpan Kategori</button><a class="adam-button ghost" href="<?=h($backUrl)?>">Batal</a></div>
  </form>
</section>

<?php
