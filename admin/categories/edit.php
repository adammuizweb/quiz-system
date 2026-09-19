<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$id = (int)($_GET['id'] ?? 0);
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($id <= 0 || $quiz_id <= 0) { http_response_code(400); exit('Missing id/quiz_id'); }
$quiz = qz_require_quiz_access($pdo, $quiz_id);
qz_require_category_access($pdo, $quiz_id, $id);

$stmt = $pdo->prepare("SELECT c.*, q.title AS quiz_title FROM question_categories c JOIN quizzes q ON q.id=c.quiz_id WHERE c.id=:id AND c.quiz_id=:qid LIMIT 1");
$stmt->execute([':id'=>$id,':qid'=>$quiz_id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Category not found / no access'); }
$user = qz_admin_user($pdo);
if (!qz_can_mutate_resource($pdo, (int)$user['id'], $quiz, $row)) { http_response_code(403); exit('Category is read-only.'); }

$cntStmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM question_passages p WHERE p.quiz_id=:qid AND p.category_id=:cid) AS passage_count, (SELECT COUNT(*) FROM questions qu JOIN question_passages p2 ON p2.id=qu.passage_id WHERE p2.quiz_id=:qid AND p2.category_id=:cid) AS question_count");
$cntStmt->execute([':qid'=>$quiz_id,':cid'=>$id]);
$cnt = $cntStmt->fetch(\PDO::FETCH_ASSOC) ?: ['passage_count'=>0,'question_count'=>0];

$errors = [];
$name = (string)($row['name'] ?? '');
$description = (string)($row['description'] ?? '');
$shuffle_passages = (int)($row['shuffle_passages'] ?? 1);
$shuffle_questions = (int)($row['shuffle_questions'] ?? 1);
$min_questions = isset($row['min_questions']) ? (string)$row['min_questions'] : '0';
$max_questions = ($row['max_questions'] !== null) ? (string)$row['max_questions'] : '';
$is_active = (int)($row['is_active'] ?? 1);

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
      qz_lock_category_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
      $up = $pdo->prepare("UPDATE question_categories SET name=:name,description=:descr,shuffle_passages=:sp,shuffle_questions=:sq,min_questions=:minq,max_questions=:maxq,is_active=:act WHERE id=:id AND quiz_id=:qid LIMIT 1");
      $up->execute([':name'=>$name,':descr'=>($description===''?null:$description),':sp'=>$shuffle_passages?1:0,':sq'=>$shuffle_questions?1:0,':minq'=>$minqVal,':maxq'=>$maxqVal,':act'=>$is_active?1:0,':id'=>$id,':qid'=>$quiz_id]);
      $pdo->commit();
      qz_redirect("$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id&msg=".urlencode("Kategori berhasil diperbarui."));
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
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$row['quiz_title'],'url'=>$masterUrl],['label'=>'Kategori','url'=>$backUrl],['label'=>'Edit']]); ?>
  <div class="qz-head"><div><div class="qz-kicker">Bank soal</div><h2 style="margin:0">Edit Kategori</h2><p class="qz-context"><?= h((string)$row['quiz_title']) ?> · <b><?=(int)($cnt['passage_count']??0)?></b> passage · <b><?=(int)($cnt['question_count']??0)?></b> soal</p></div><div class="qz-toolbar"><a class="adam-button ghost" href="<?=h($backUrl)?>">Daftar Kategori</a><a class="adam-button ghost" href="<?=h($masterUrl)?>">Master Builder</a></div></div>
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
      <div style="display:grid;gap:.5rem"><label><strong>Urutan saat ini</strong></label><div class="qz-order-note">#<?= (int)($row['sort_order'] ?? 0) ?> · Ubah melalui Master Builder agar tidak menimpa urutan editor lain.</div></div>
    </div>
    <div style="margin-top:.2rem"><label><strong>Status</strong></label><br><select class="adam-input" name="is_active"><option value="1" <?=$is_active===1?'selected':''?>>Aktif</option><option value="0">Nonaktif</option></select></div>
    <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap"><button class="adam-button" type="submit">Simpan Perubahan</button><a class="adam-button ghost" href="<?=h($backUrl)?>">Batal</a></div>
  </form>
</section>

<?php
