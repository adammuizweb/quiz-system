<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/questions';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('quiz_redirect')) {
  function quiz_redirect(string $url): void {
    if (!headers_sent()) {
      while (ob_get_level() > 0) { @ob_end_clean(); }
      header("Location: $url", true, 303); exit;
    }
    echo '<script>location.replace('.json_encode($url).');</script>'; exit;
  }
}
if (!function_exists('qz_plain_excerpt')) {
  function qz_plain_excerpt(string $md, int $max=220): string {
    $s = $md;
    $s = preg_replace('/```.*?```/s',' ',$s);
    $s = preg_replace('/`([^`]+)`/','$1',$s);
    $s = preg_replace('/!\[([^\]]*)\]\([^)]+\)/','$1',$s);
    $s = preg_replace('/\[(.*?)\]\((.*?)\)/','$1',$s);
    $s = preg_replace('/\$\$(.*?)\$\$/s',' $1 ',$s);
    $s = preg_replace('/\\\\\[(.*?)\\\\\]/s',' $1 ',$s);
    $s = preg_replace('/\\\\\((.*?)\\\\\)/s',' $1 ',$s);
    $s = preg_replace('/\$(.+?)\$/s',' $1 ',$s);
    $s = str_replace(['**','__','~~','*','_'],'',$s);
    $s = trim(preg_replace('/\s+/',' ',$s));
    return mb_strlen($s,'UTF-8')<=$max ? $s : mb_substr($s,0,$max-1,'UTF-8').'…';
  }
}

$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) { echo '<p>Select a quiz first.</p>'; return; }
qz_require_quiz_access($pdo, $quiz_id);

$st = $pdo->prepare("SELECT q.*, u.name AS owner_name, u.email AS owner_email FROM quizzes q LEFT JOIN users u ON u.id=q.created_by WHERE q.id=:id LIMIT 1");
$st->execute([':id'=>$quiz_id]);
$quiz = $st->fetch(\PDO::FETCH_ASSOC);
if (!$quiz) { echo '<p>Quiz not found.</p>'; return; }
$user = qz_admin_user($pdo);
$canViewAllContentMetadata = $user['can_manage_any'] || (int)$quiz['created_by'] === (int)$user['id'];

$on = trim((string)($quiz['owner_name'] ?? ''));
$oe = trim((string)($quiz['owner_email'] ?? ''));
$ownerLine = ($on || $oe)
  ? 'Owner: '.($on ? h($on) : 'User#'.(int)$quiz['created_by']).($oe ? ' <span style="opacity:.75">('.h($oe).')</span>' : '')
  : 'Owner: User#'.(int)$quiz['created_by'];
$listUrl = "$base/?page=$pluginPage&quiz_id=$quiz_id";
$masterUrl = "$base/?page=admin/quiz/master&quiz_id=$quiz_id";

$do = trim((string)($_POST['do'] ?? $_GET['do'] ?? ''));
$isNewQuestion = $do === 'add';
$editResource = null;
$canEditQuestion = $isNewQuestion;
if ($do === 'edit') {
  $editId = (int)($_GET['id'] ?? 0);
  if ($editId <= 0) { http_response_code(400); exit('Missing ID.'); }
  $editResource = qz_require_question_access($pdo, $quiz_id, $editId);
  $canEditQuestion = qz_can_mutate_resource($pdo, (int)$user['id'], $quiz, $editResource);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canEditQuestion) { http_response_code(403); exit('Question is read-only.'); }
}

if ($do === 'delete') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
  $id = (int)($_POST['id'] ?? 0);
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if ($id > 0 && csrf_check($csrf)) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
      qz_lock_question_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
      qz_assert_question_not_used_by_attempt($pdo, $id);
      $pdo->prepare("DELETE FROM questions WHERE id=:id AND quiz_id=:qid LIMIT 1")->execute([':id'=>$id,':qid'=>$quiz_id]);
      $pdo->commit();
      quiz_redirect("$base/?page=$pluginPage&quiz_id=$quiz_id&msg=".urlencode("Soal dihapus."));
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
  }
  quiz_redirect("$base/?page=$pluginPage&quiz_id=$quiz_id&err=".urlencode("Request tidak valid."));
  return;
}

if ($do === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $errors = [];
  if (!hash_equals(csrf_token(),(string)($_POST['csrf_token']??''))) $errors[] = 'CSRF invalid.';
  $qt = trim((string)($_POST['question_text']??''));
  $qtype = (string)($_POST['question_type']??'single_choice');
  $is_active = (int)($_POST['is_active']??1);
  $allowed = ['single_choice','multiple_choice','yesno','text','number'];
  if ($qt === '') $errors[] = 'Question text is required.';
  if (!in_array($qtype,$allowed,true)) $errors[] = 'Invalid question type.';
  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
      $sortOrder = qz_append_sort_order($pdo, 'questions', $quiz_id);
      $st = $pdo->prepare("INSERT INTO questions (quiz_id,created_by,question_text,question_type,sort_order,is_active,image_media_id,file_id,youtube_url,passage_id,passage_seq) VALUES (:qid,:created_by,:qt,:qtype,:so,:act,:mid,:fid,:yt,:pid,:pseq)");
      $st->execute([':qid'=>$quiz_id,':created_by'=>(int)$user['id'],':qt'=>$qt,':qtype'=>$qtype,':so'=>$sortOrder,':act'=>$is_active?1:0,':mid'=>null,':fid'=>null,':yt'=>null,':pid'=>null,':pseq'=>null]);
      $pdo->commit();
      quiz_redirect("$base/?page=$pluginPage&quiz_id=$quiz_id&msg=".urlencode("Soal ditambahkan."));
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Question could not be saved or access is no longer valid.';
    }
  }
}

if ($do === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $qid = (int)($_GET['id'] ?? 0);
  if ($qid <= 0) { echo '<p>Missing ID.</p>'; return; }
  $errors = [];
  if (!hash_equals(csrf_token(),(string)($_POST['csrf_token']??''))) $errors[] = 'CSRF invalid.';
  $qt = trim((string)($_POST['question_text']??''));
  $qtype = (string)($_POST['question_type']??'single_choice');
  $is_active = (int)($_POST['is_active']??1);
  $imageMediaId = trim((string)($_POST['image_media_id'] ?? ''));
  $fileId = trim((string)($_POST['file_id'] ?? ''));
  $youtubeUrl = trim((string)($_POST['youtube_url'] ?? ''));
  $passageId = trim((string)($_POST['passage_id'] ?? ''));
  $passageSeq = trim((string)($_POST['passage_seq'] ?? ''));
  $categoryId = trim((string)($_POST['category_id'] ?? ''));
  $allowed = ['single_choice','multiple_choice','yesno','text','number'];
  if ($qt === '') $errors[] = 'Question text is required.';
  if (!in_array($qtype,$allowed,true)) $errors[] = 'Invalid question type.';
  foreach ([['Gambar',$imageMediaId], ['File',$fileId], ['Passage',$passageId], ['Urutan passage',$passageSeq], ['Kategori',$categoryId]] as [$label,$value]) {
    if ($value !== '' && (!ctype_digit($value) || (int)$value <= 0)) $errors[] = $label . ' tidak valid.';
  }
  if ($youtubeUrl !== '') {
    $youtubeHost = strtolower((string)parse_url($youtubeUrl, PHP_URL_HOST));
    if (!filter_var($youtubeUrl, FILTER_VALIDATE_URL) || !in_array($youtubeHost, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true)) $errors[] = 'Link YouTube tidak valid.';
  }
  $imageMediaId = $imageMediaId === '' ? null : (int)$imageMediaId;
  $fileId = $fileId === '' ? null : (int)$fileId;
  $passageId = $passageId === '' ? null : (int)$passageId;
  $passageSeq = $passageSeq === '' ? null : (int)$passageSeq;
  $categoryId = $categoryId === '' ? null : (int)$categoryId;
  if ($passageId === null) $passageSeq = null;
  else $categoryId = null;
  $editResource = array_merge($editResource, [
    'question_text'=>$qt, 'question_type'=>$qtype, 'is_active'=>$is_active?1:0,
    'image_media_id'=>$imageMediaId, 'file_id'=>$fileId, 'youtube_url'=>$youtubeUrl,
    'passage_id'=>$passageId, 'passage_seq'=>$passageSeq, 'category_id'=>$categoryId,
  ]);
  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
      $currentQuestion = qz_lock_question_for_mutation($pdo, $quiz_id, $qid, (int)$user['id']);
      qz_assert_question_snapshots_complete($pdo, $qid, true, true);
      qz_assert_question_type_change_safe($pdo, $qid, (string)$currentQuestion['question_type'], $qtype);
      $currentQuizStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1');
      $currentQuizStmt->execute([':id'=>$quiz_id]);
      $currentQuiz = $currentQuizStmt->fetch(PDO::FETCH_ASSOC);
      if (!$currentQuiz) throw new RuntimeException('Quiz tidak lagi tersedia.');
      if ($passageId !== null) {
        $ref = $pdo->prepare('SELECT * FROM question_passages WHERE id=:id AND quiz_id=:quiz_id LIMIT 1 FOR UPDATE');
        $ref->execute([':id'=>$passageId, ':quiz_id'=>$quiz_id]);
        $resource = $ref->fetch(PDO::FETCH_ASSOC);
        if (!$resource || !qz_can_mutate_resource($pdo, (int)$user['id'], $currentQuiz, $resource)) throw new RuntimeException('Passage tidak tersedia untuk contributor ini.');
      }
      if ($categoryId !== null) {
        $ref = $pdo->prepare('SELECT * FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1 FOR UPDATE');
        $ref->execute([':id'=>$categoryId, ':quiz_id'=>$quiz_id]);
        $resource = $ref->fetch(PDO::FETCH_ASSOC);
        if (!$resource || !qz_can_mutate_resource($pdo, (int)$user['id'], $currentQuiz, $resource)) throw new RuntimeException('Kategori tidak tersedia untuk contributor ini.');
      }
      if ($imageMediaId !== null) {
        $ref = $pdo->prepare("SELECT id FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1");
        $ref->execute([':id'=>$imageMediaId]);
        if (!$ref->fetchColumn()) throw new RuntimeException('Media gambar tidak ditemukan.');
      }
      if ($fileId !== null) {
        $ref = $pdo->prepare("SELECT id FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
        $ref->execute([':id'=>$fileId]);
        if (!$ref->fetchColumn()) throw new RuntimeException('File publik tidak ditemukan.');
      }
      $st = $pdo->prepare("UPDATE questions SET question_text=:qt,question_type=:qtype,is_active=:act,image_media_id=:mid,file_id=:fid,youtube_url=:yt,passage_id=:pid,passage_seq=:pseq,category_id=:cid WHERE id=:id AND quiz_id=:qid");
      $st->execute([':qt'=>$qt,':qtype'=>$qtype,':act'=>$is_active?1:0,':mid'=>$imageMediaId,':fid'=>$fileId,':yt'=>$youtubeUrl===''?null:$youtubeUrl,':pid'=>$passageId,':pseq'=>$passageSeq,':cid'=>$categoryId,':id'=>$qid,':qid'=>$quiz_id]);
      $pdo->commit();
      quiz_redirect("$base/?page=$pluginPage&quiz_id=$quiz_id&msg=".urlencode("Soal diupdate."));
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = $error instanceof QuizAssignedContentException
        ? $error->getMessage()
        : 'Question could not be saved or access is no longer valid.';
    }
  }
}

if (in_array($do, ['add', 'edit'], true)) {
  $qid = $isNewQuestion ? 0 : (int)($_GET['id'] ?? 0);
  if (!$isNewQuestion && $qid <= 0) { echo '<p>Missing ID.</p>'; return; }
  $qq = $isNewQuestion ? [
    'question_text'=>(string)($_POST['question_text']??''),
    'question_type'=>(string)($_POST['question_type']??'single_choice'),
    'is_active'=>(int)($_POST['is_active']??1),
    'image_media_id'=>$_POST['image_media_id']??null,
    'file_id'=>$_POST['file_id']??null,
    'youtube_url'=>(string)($_POST['youtube_url']??''),
    'passage_id'=>$_POST['passage_id']??null,
    'passage_seq'=>$_POST['passage_seq']??null,
    'category_id'=>$_POST['category_id']??null,
    'sort_order'=>null,
  ] : $editResource;
  if (!$qq) { echo '<p>Question not found.</p>'; return; }
  $editorResourceParams = [':quiz_id'=>$quiz_id];
  $editorOwnerSql = '';
  if (!$canViewAllContentMetadata) {
    $editorOwnerSql = ' AND created_by=:created_by';
    $editorResourceParams[':created_by'] = (int)$user['id'];
  }
  $categoriesStmt = $pdo->prepare('SELECT id,name FROM question_categories WHERE quiz_id=:quiz_id'.$editorOwnerSql.' ORDER BY (sort_order IS NULL) ASC,sort_order ASC,id ASC');
  $categoriesStmt->execute($editorResourceParams);
  $editorCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $passagesStmt = $pdo->prepare('SELECT id,title FROM question_passages WHERE quiz_id=:quiz_id'.$editorOwnerSql.' ORDER BY (sort_order IS NULL) ASC,sort_order ASC,id ASC');
  $passagesStmt->execute($editorResourceParams);
  $editorPassages = $passagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $currentCategoryId = (int)($qq['category_id']??0);
  if ($currentCategoryId > 0 && !in_array($currentCategoryId, array_map('intval', array_column($editorCategories, 'id')), true)) {
    array_unshift($editorCategories, ['id'=>$currentCategoryId, 'name'=>'Kategori terpasang (detail dibatasi)']);
  }
  $currentPassageId = (int)($qq['passage_id']??0);
  if ($currentPassageId > 0 && !in_array($currentPassageId, array_map('intval', array_column($editorPassages, 'id')), true)) {
    array_unshift($editorPassages, ['id'=>$currentPassageId, 'title'=>'Passage terpasang (detail dibatasi)']);
  }
  $editorAnswers = [];
  if ($qid > 0) {
    $answersStmt = $pdo->prepare('SELECT id,answer_text,score,sort_order,is_active FROM question_answers WHERE question_id=:question_id ORDER BY (sort_order<=0) ASC,sort_order ASC,id ASC');
    $answersStmt->execute([':question_id'=>$qid]);
    $editorAnswers = $answersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  $imageUrl = '';
  if ((int)($qq['image_media_id']??0)>0) {
    $assetStmt = $pdo->prepare("SELECT url FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1");
    $assetStmt->execute([':id'=>(int)$qq['image_media_id']]);
    $imageUrl = (string)($assetStmt->fetchColumn() ?: '');
  }
  $fileLabel = '';
  if ((int)($qq['file_id']??0)>0) {
    $assetStmt = $pdo->prepare("SELECT filename,title FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
    $assetStmt->execute([':id'=>(int)$qq['file_id']]);
    $fileRow = $assetStmt->fetch(PDO::FETCH_ASSOC);
    if ($fileRow) $fileLabel = (string)($fileRow['filename'] ?: $fileRow['title']);
  }
  $attachmentCount = ((int)($qq['image_media_id']??0)>0 ? 1 : 0)
    + ((int)($qq['file_id']??0)>0 ? 1 : 0)
    + (trim((string)($qq['youtube_url']??'')) !== '' ? 1 : 0);
  $disabled = $canEditQuestion ? '' : 'disabled';
  ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
  <script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>
  <section class="adam-card qz-card qz-editor-page qz-question-editor" data-question-aggregate-editor data-question-is-new="<?=$isNewQuestion?'1':'0'?>" data-quiz-id="<?=$quiz_id?>" data-question-id="<?=$qid?>" data-can-edit="<?=$canEditQuestion?'1':'0'?>" data-csrf="<?=h(csrf_token())?>" data-question-save-url="<?=h($base)?>/?page=admin/quiz/master/api/question_save&action=quiz_api" data-question-edit-url="<?=h($base)?>/?page=<?=$pluginPage?>&do=edit&id=__QUESTION_ID__&quiz_id=<?=$quiz_id?>" data-answer-save-url="<?=h($base)?>/?page=admin/quiz/master/api/answer_save&action=quiz_api" data-answer-delete-url="<?=h($base)?>/?page=admin/quiz/master/api/answer_delete&action=quiz_api" data-media-url="<?=h($base)?>/admin/modal_img/list_modal.php?embedded=1" data-file-url="<?=h($base)?>/admin/modal_file/list_modal.php?embedded=1">
    <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title'],'url'=>$masterUrl],['label'=>'Soal','url'=>$listUrl],['label'=>$isNewQuestion?'Tambah':($canEditQuestion?'Edit':'Lihat')]]); ?>
    <div class="qz-head"><div><div class="qz-kicker">Bank soal</div><h2 style="margin:0" data-question-editor-title><?=$isNewQuestion?'Tambah':($canEditQuestion?'Edit':'Lihat')?> Soal</h2><p class="qz-context"><?=h($quiz['title'])?><?php if($isNewQuestion):?> · Soal dibuat saat autosave pertama dan ditempatkan di urutan terakhir.<?php elseif(!$canEditQuestion):?> · Mode baca saja, resource dimiliki contributor lain.<?php endif;?></p></div><div class="qz-toolbar"><a class="adam-button ghost" href="<?=h($listUrl)?>">Daftar Soal</a><a class="adam-button ghost" href="<?=h($masterUrl)?>">Master Builder</a></div></div>
    <?php if (!empty($errors)): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>
    <?php if($canEditQuestion):?><div class="qz-save-guide" role="note"><div><strong>Autosave aktif</strong><small data-question-save-status data-state="<?=$isNewQuestion?'new':'saved'?>" aria-live="polite"><?=$isNewQuestion?'Mulai tulis soal untuk membuat record baru.':'Semua perubahan soal tersimpan.'?></small></div><span><b>Soal, lampiran, dan pengaturan</b> disimpan otomatis setelah Anda berhenti mengetik.</span><span><b>Jawaban</b> disimpan otomatis per baris melalui AJAX.</span><button class="adam-button ghost qz-save-retry" type="button" data-question-save-retry aria-label="Coba simpan lagi" title="Coba simpan lagi" hidden><?=svg_ico('save')?></button></div><?php endif;?>
    <form method="post" novalidate data-question-main-form>
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="image_media_id" value="<?=h((string)($qq['image_media_id']??''))?>" data-question-image-id>
      <input type="hidden" name="file_id" value="<?=h((string)($qq['file_id']??''))?>" data-question-file-id>
      <div class="qz-question-editor__layout">
        <div class="qz-question-editor__main">
          <div class="qz-editor-section"><div class="qz-editor-section__head"><div><h3>Konten Soal</h3><p>Markdown dan rumus matematika dirender langsung pada preview.</p></div></div><textarea class="adam-input qz-question-editor__textarea" name="question_text" rows="10" required <?=$disabled?> data-markdown-source><?=h((string)$qq['question_text'])?></textarea><div class="qz-live-preview qz-live-preview--question"><div class="qz-live-preview__head"><span>Preview soal</span><small>Tampilan peserta</small></div><div class="md-preview qz-question-editor__preview" data-markdown-preview></div></div><div class="small-hint">Gunakan <code>**tebal**</code>, <code>$x^2$</code>, atau <code>$$\frac{a}{b}$$</code>.</div></div>
          <details class="qz-editor-section qz-editor-disclosure" data-question-attachments <?=$attachmentCount>0?'open':''?>><summary class="qz-editor-section__head qz-editor-disclosure__summary"><div><h3>Lampiran</h3><p>Tambahkan gambar, file unduhan, atau video YouTube.</p></div><small class="qz-editor-disclosure__state" data-question-attachment-state><?=$attachmentCount>0?$attachmentCount.' terisi':'Belum ada'?></small></summary><div class="qz-editor-disclosure__body"><div class="qz-question-attachments"><div class="qz-question-attachment"><div class="qz-question-image-preview"><img src="<?=h($imageUrl)?>" alt="Preview gambar soal" data-question-image-preview <?=$imageUrl===''?'hidden':''?>><span data-question-image-empty <?=$imageUrl!==''?'hidden':''?>>Belum ada gambar</span></div><div class="qz-question-attachment__actions"><button class="adam-button" type="button" data-question-pick-image <?=$disabled?>>Pilih Gambar</button><button class="adam-button ghost" type="button" data-question-clear-image <?=$disabled?>>Hapus</button></div><small>Media ID: <span data-question-image-label><?=($qq['image_media_id']??null)!==null?(int)$qq['image_media_id']:'-'?></span></small></div><div class="qz-question-attachment"><div class="qz-question-file-preview"><strong data-question-file-label><?=h($fileLabel!==''?$fileLabel:'Belum ada file')?></strong><small>File ID: <span data-question-file-id-label><?=($qq['file_id']??null)!==null?(int)$qq['file_id']:'-'?></span></small></div><div class="qz-question-attachment__actions"><button class="adam-button" type="button" data-question-pick-file <?=$disabled?>>Pilih File</button><button class="adam-button ghost" type="button" data-question-clear-file <?=$disabled?>>Hapus</button></div><label><span>Link YouTube</span><input class="adam-input" name="youtube_url" type="url" value="<?=h((string)($qq['youtube_url']??''))?>" placeholder="https://youtu.be/..." <?=$disabled?>></label></div></div></div></details>
        </div>
        <aside class="qz-editor-section qz-question-editor__settings"><div class="qz-editor-section__head"><div><h3>Pengaturan</h3><p>Konteks dan perilaku soal.</p></div></div><label><span>Tipe soal</span><select class="adam-input" name="question_type" <?=$disabled?>><?php foreach(['single_choice','multiple_choice','yesno','text','number'] as $type):?><option value="<?=$type?>" <?=$qq['question_type']===$type?'selected':''?>><?=h(ucwords(str_replace('_',' ',$type)))?></option><?php endforeach;?></select></label><label><span>Status</span><select class="adam-input" name="is_active" <?=$disabled?>><option value="1" <?=(int)$qq['is_active']===1?'selected':''?>>Aktif</option><option value="0" <?=(int)$qq['is_active']===0?'selected':''?>>Nonaktif</option></select></label><label><span>Passage</span><select class="adam-input" name="passage_id" data-question-passage <?=$disabled?>><option value="">Tanpa passage</option><?php foreach($editorPassages as $passage):?><option value="<?=(int)$passage['id']?>" <?=(int)($qq['passage_id']??0)===(int)$passage['id']?'selected':''?>><?=h(trim((string)$passage['title'])!==''?(string)$passage['title']:'Passage #'.(int)$passage['id'])?></option><?php endforeach;?></select></label><label><span>Urutan dalam passage</span><input class="adam-input" type="number" min="1" name="passage_seq" value="<?=h((string)($qq['passage_seq']??''))?>" data-question-passage-seq <?=$disabled?>></label><label><span>Kategori langsung</span><select class="adam-input" name="category_id" data-question-category <?=$disabled?>><option value="">Tanpa kategori</option><?php foreach($editorCategories as $category):?><option value="<?=(int)$category['id']?>" <?=(int)($qq['category_id']??0)===(int)$category['id']?'selected':''?>><?=h((string)$category['name'])?></option><?php endforeach;?></select><small>Dinonaktifkan ketika passage dipilih.</small></label><div><span class="qz-editor-label">Urutan global</span><div class="qz-order-note"><?=($qq['sort_order']??null)!==null?'#'.(int)$qq['sort_order']:'Posisi akhir'?> · Ubah melalui Master Builder.</div></div></aside>
      </div>
      <?php if($canEditQuestion):?><div class="qz-editor-actions"><a class="adam-button ghost" href="<?=h($listUrl)?>">Kembali ke Daftar Soal</a></div><?php endif;?>
    </form>

    <div class="qz-editor-section qz-answer-editor"><div class="qz-editor-section__head"><div><h3>Jawaban</h3><p>Teks, skor, dan status setiap jawaban disimpan otomatis setelah Anda berhenti mengubahnya.</p><small data-question-create-note <?=$isNewQuestion?'':'hidden'?>>Selesaikan isi soal terlebih dahulu. Jawaban aktif setelah autosave pertama berhasil.</small></div><?php if($canEditQuestion):?><button class="adam-button" type="button" data-answer-add <?=$isNewQuestion?'disabled':''?>>+ Tambah Jawaban</button><?php endif;?></div><div data-answer-list><?php foreach($editorAnswers as $answer):?><div class="qz-inline-answer" data-answer-row data-answer-id="<?=(int)$answer['id']?>"><div class="qz-inline-answer__content"><textarea class="adam-input" rows="2" data-answer-text <?=$disabled?>><?=h((string)$answer['answer_text'])?></textarea><div class="qz-live-preview qz-live-preview--answer"><div class="qz-live-preview__head"><span>Preview jawaban</span><small>Tampilan peserta</small></div><div class="md-preview qz-inline-answer__preview" data-markdown-preview></div></div></div><label><span>Skor</span><input class="adam-input" type="number" min="0" max="100" value="<?=(int)$answer['score']?>" data-answer-score <?=$disabled?>></label><label><span>Status</span><select class="adam-input" data-answer-active <?=$disabled?>><option value="1" <?=(int)$answer['is_active']===1?'selected':''?>>Aktif</option><option value="0" <?=(int)$answer['is_active']===0?'selected':''?>>Nonaktif</option></select></label><?php if($canEditQuestion):?><div class="qz-inline-answer__actions"><button class="adam-button ghost qz-save-retry" type="button" data-answer-save hidden aria-label="Coba simpan lagi" title="Coba simpan lagi"><?=svg_ico('save')?></button><button class="adam-button ghost qz-danger-text" type="button" data-answer-delete>Hapus</button></div><?php endif;?><div class="qz-inline-answer__status" data-answer-status></div></div><?php endforeach;?></div><?php if(!$editorAnswers):?><div class="empty" data-answer-empty>Belum ada jawaban untuk soal ini.</div><?php endif;?></div>
    <?php if($canEditQuestion):?><template data-answer-template><div class="qz-inline-answer" data-answer-row data-answer-id="0"><div class="qz-inline-answer__content"><textarea class="adam-input" rows="2" data-answer-text placeholder="Tulis jawaban baru..."></textarea><div class="qz-live-preview qz-live-preview--answer"><div class="qz-live-preview__head"><span>Preview jawaban</span><small>Tampilan peserta</small></div><div class="md-preview qz-inline-answer__preview" data-markdown-preview></div></div></div><label><span>Skor</span><input class="adam-input" type="number" min="0" max="100" value="0" data-answer-score></label><label><span>Status</span><select class="adam-input" data-answer-active><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label><div class="qz-inline-answer__actions"><button class="adam-button ghost qz-save-retry" type="button" data-answer-save hidden aria-label="Coba simpan lagi" title="Coba simpan lagi"><?=svg_ico('save')?></button><button class="adam-button ghost qz-danger-text" type="button" data-answer-delete>Hapus</button></div><div class="qz-inline-answer__status" data-answer-status></div></div></template><?php endif;?>
  </section>
  <?php return;
}

$messages = []; $errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode((string)$_GET['err']);

$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120, 'UTF-8');
$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$categoryFilter = max(0, (int)($_GET['category_id'] ?? 0));
$passageFilter = max(0, (int)($_GET['passage_id'] ?? 0));
$canFilterAuthor = $canViewAllContentMetadata;
$authorFilter = $canFilterAuthor ? max(0, (int)($_GET['author_id'] ?? 0)) : 0;
$allowedTypes = ['single_choice','multiple_choice','yesno','text','number'];
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = '';
if (!in_array($statusFilter, ['active','inactive'], true)) $statusFilter = '';

$filterResourceParams = [':quiz_id'=>$quiz_id];
$filterOwnerSql = '';
if (!$canViewAllContentMetadata) {
  $filterOwnerSql = ' AND created_by=:filter_created_by';
  $filterResourceParams[':filter_created_by'] = (int)$user['id'];
}
$filterStmt = $pdo->prepare('SELECT id,name FROM question_categories WHERE quiz_id=:quiz_id'.$filterOwnerSql.' ORDER BY (sort_order IS NULL) ASC,sort_order ASC,id ASC');
$filterStmt->execute($filterResourceParams);
$filterCategories = $filterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$categoryIds = array_map('intval', array_column($filterCategories, 'id'));
if ($categoryFilter > 0 && !in_array($categoryFilter, $categoryIds, true)) $categoryFilter = 0;
$selectedPassage = null;
if ($passageFilter > 0) {
  $selectedPassageParams = $filterResourceParams;
  $selectedPassageParams[':passage_id'] = $passageFilter;
  $filterStmt = $pdo->prepare('SELECT id,category_id,title FROM question_passages WHERE quiz_id=:quiz_id AND id=:passage_id'.$filterOwnerSql.' LIMIT 1');
  $filterStmt->execute($selectedPassageParams);
  $selectedPassage = $filterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$selectedPassage) $passageFilter = 0;
  else {
    $selectedCategoryId = (int)($selectedPassage['category_id']??0);
    if ($selectedCategoryId > 0 && $categoryFilter === 0 && in_array($selectedCategoryId, $categoryIds, true)) $categoryFilter = $selectedCategoryId;
    if ($selectedCategoryId !== $categoryFilter) {
      $passageFilter = 0;
      $selectedPassage = null;
    }
  }
}
$passageScopeParams = $filterResourceParams;
$passageCategorySql = ' AND category_id IS NULL';
if ($categoryFilter > 0) {
  $passageCategorySql = ' AND category_id=:filter_category_id';
  $passageScopeParams[':filter_category_id'] = $categoryFilter;
}
$filterStmt = $pdo->prepare('SELECT id,category_id,title FROM question_passages WHERE quiz_id=:quiz_id'.$passageCategorySql.$filterOwnerSql.' ORDER BY (sort_order IS NULL) ASC,sort_order ASC,id ASC');
$filterStmt->execute($passageScopeParams);
$filterPassages = $filterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$filterAuthors = [];
if ($canFilterAuthor) {
  $filterStmt = $pdo->prepare("SELECT q.created_by AS id,COALESCE(NULLIF(u.name,''),NULLIF(u.email,''),CONCAT('User #',q.created_by)) AS label FROM questions q LEFT JOIN users u ON u.id=q.created_by WHERE q.quiz_id=:quiz_id GROUP BY q.created_by,u.name,u.email ORDER BY label ASC");
  $filterStmt->execute([':quiz_id'=>$quiz_id]);
  $filterAuthors = $filterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $authorIds = array_map('intval', array_column($filterAuthors, 'id'));
  if ($authorFilter > 0 && !in_array($authorFilter, $authorIds, true)) $authorFilter = 0;
}

$where = ['q.quiz_id = :qid'];
$params = [':qid' => $quiz_id];
if ($search !== '') {
  $where[] = 'q.question_text LIKE :search';
  $params[':search'] = '%' . $search . '%';
}
if ($typeFilter !== '') {
  $where[] = 'q.question_type = :question_type';
  $params[':question_type'] = $typeFilter;
}
if ($statusFilter !== '') {
  $where[] = 'q.is_active = :is_active';
  $params[':is_active'] = $statusFilter === 'active' ? 1 : 0;
}
if ($categoryFilter > 0) {
  $where[] = '((q.passage_id IS NULL AND q.category_id=:direct_category_id) OR EXISTS (SELECT 1 FROM question_passages filter_passage WHERE filter_passage.id=q.passage_id AND filter_passage.quiz_id=q.quiz_id AND filter_passage.category_id=:passage_category_id))';
  $params[':direct_category_id'] = $categoryFilter;
  $params[':passage_category_id'] = $categoryFilter;
}
if ($passageFilter > 0) {
  $where[] = 'q.passage_id = :passage_id';
  $params[':passage_id'] = $passageFilter;
}
if ($authorFilter > 0) {
  $where[] = 'q.created_by = :author_id';
  $params[':author_id'] = $authorFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE {$whereSql}");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$perPage = 30;
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;

$statsStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(is_active=1) AS active_count, SUM(is_active=0) AS inactive_count FROM questions WHERE quiz_id=:qid");
$statsStmt->execute([':qid' => $quiz_id]);
$stats = $statsStmt->fetch(\PDO::FETCH_ASSOC) ?: ['total'=>0, 'active_count'=>0, 'inactive_count'=>0];

$st = $pdo->prepare("SELECT q.*, p.title AS passage_title, c.name AS category_name, u.name AS contributor_name, u.email AS contributor_email,
  (SELECT COUNT(*) FROM question_answers a WHERE a.question_id = q.id AND a.is_active = 1) AS answer_count
  FROM questions q
  LEFT JOIN question_passages p ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
  LEFT JOIN question_categories c ON c.id = CASE WHEN p.id IS NOT NULL THEN p.category_id ELSE q.category_id END AND c.quiz_id = q.quiz_id
  LEFT JOIN users u ON u.id = q.created_by
  WHERE {$whereSql}
  ORDER BY CASE WHEN q.sort_order IS NULL THEN 1 ELSE 0 END, q.sort_order ASC, q.id ASC
  LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$questions = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
$typeLabels = [
  'single_choice' => 'Pilihan Tunggal',
  'multiple_choice' => 'Pilihan Ganda',
  'yesno' => 'Ya / Tidak',
  'text' => 'Teks',
  'number' => 'Angka',
];
$pageUrl = static function (int $target) use ($base, $pluginPage, $quiz_id, $search, $typeFilter, $statusFilter, $categoryFilter, $passageFilter, $authorFilter): string {
  return $base . '/?' . http_build_query(array_filter([
    'page' => $pluginPage,
    'quiz_id' => $quiz_id,
    'q' => $search,
    'type' => $typeFilter,
    'status' => $statusFilter,
    'category_id' => $categoryFilter ?: '',
    'passage_id' => $passageFilter ?: '',
    'author_id' => $authorFilter ?: '',
    'p' => $target,
  ], static fn(mixed $value): bool => $value !== ''));
};
?>
<section class="qz-card qz-question-page">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title'],'url'=>$masterUrl],['label'=>'Soal']]); ?>
  <div class="qz-head">
    <div>
      <div class="qz-kicker">Bank soal</div>
      <h2 style="margin:0"><?=h($quiz['title'])?></h2>
      <div class="qz-meta">Kelola soal, jawaban, status, dan contributor<span class="dot">•</span>Quiz ID: <b><?=$quiz_id?></b><span class="dot">•</span><?=$ownerLine?></div>
    </div>
    <div class="qz-toolbar">
      <a class="adam-button" href="<?=h($masterUrl)?>">Buka Master Builder</a>
      <a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=add&quiz_id=<?=$quiz_id?>">+ Tambah Soal</a>
      <a class="adam-button ghost" href="<?=h($base)?>/?page=admin/quiz/quizzes">Daftar Quiz</a>
    </div>
  </div>
  <?php if ($messages): ?><div class="adam-alert success"><?php foreach($messages as $m):?><div><?=h($m)?></div><?php endforeach;?></div><?php endif; ?>
  <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>

  <div class="qz-stats" aria-label="Ringkasan bank soal">
    <div class="qz-stat"><span>Total Soal</span><strong><?=(int)($stats['total']??0)?></strong></div>
    <div class="qz-stat"><span>Aktif</span><strong><?=(int)($stats['active_count']??0)?></strong></div>
    <div class="qz-stat"><span>Nonaktif</span><strong><?=(int)($stats['inactive_count']??0)?></strong></div>
    <div class="qz-stat qz-stat--result"><span>Hasil Filter</span><strong><?=$totalFiltered?></strong></div>
  </div>

  <form class="qz-filterbar" method="get" action="<?=h($base)?>/">
    <input type="hidden" name="page" value="<?=$pluginPage?>">
    <input type="hidden" name="quiz_id" value="<?=$quiz_id?>">
    <label class="qz-filterbar__search"><span>Cari soal</span><input class="adam-input" type="search" name="q" value="<?=h($search)?>" placeholder="Ketik isi atau kata kunci soal..."></label>
    <label><span>Kategori</span><select class="adam-input" name="category_id" data-question-category-filter><option value="">Semua kategori</option><?php foreach($filterCategories as $category):?><option value="<?=(int)$category['id']?>" <?=$categoryFilter===(int)$category['id']?'selected':''?>><?=h((string)$category['name'])?></option><?php endforeach;?></select></label>
    <label><span>Passage</span><select class="adam-input" name="passage_id" data-question-passage-filter data-quiz-id="<?=$quiz_id?>" data-passages-url="<?=h($base)?>/?page=admin/quiz/master/api/filter_passages&amp;action=quiz_api"><option value=""><?=$categoryFilter>0?'Semua passage kategori ini':'Semua passage tanpa kategori'?></option><?php foreach($filterPassages as $passage):?><option value="<?=(int)$passage['id']?>" <?=$passageFilter===(int)$passage['id']?'selected':''?>><?=h(trim((string)$passage['title'])!==''?(string)$passage['title']:'Passage #'.(int)$passage['id'])?></option><?php endforeach;?></select></label>
    <?php if($canFilterAuthor):?><label><span>Author / Contributor</span><select class="adam-input" name="author_id"><option value="">Semua author</option><?php foreach($filterAuthors as $author):?><option value="<?=(int)$author['id']?>" <?=$authorFilter===(int)$author['id']?'selected':''?>><?=h((string)$author['label'])?></option><?php endforeach;?></select></label><?php endif;?>
    <label><span>Tipe</span><select class="adam-input" name="type"><option value="">Semua tipe</option><?php foreach($typeLabels as $value=>$label):?><option value="<?=h($value)?>" <?=$typeFilter===$value?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
    <label><span>Status</span><select class="adam-input" name="status"><option value="">Semua status</option><option value="active" <?=$statusFilter==='active'?'selected':''?>>Aktif</option><option value="inactive" <?=$statusFilter==='inactive'?'selected':''?>>Nonaktif</option></select></label>
    <div class="qz-filterbar__actions"><button class="adam-button" type="submit">Terapkan</button><?php if($search!==''||$typeFilter!==''||$statusFilter!==''||$categoryFilter>0||$passageFilter>0||$authorFilter>0):?><a class="adam-button ghost" href="<?=h($listUrl)?>">Reset</a><?php endif;?></div>
  </form>

  <form id="question-bulk-form" class="qz-bulkbar qz-question-bulkbar" method="post" action="<?=h($base)?>/?page=admin/quiz/questions/bulk" data-question-bulk-form>
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="quiz_id" value="<?=$quiz_id?>">
    <input type="hidden" name="q" value="<?=h($search)?>">
    <input type="hidden" name="type" value="<?=h($typeFilter)?>">
    <input type="hidden" name="status" value="<?=h($statusFilter)?>">
    <input type="hidden" name="category_id" value="<?=$categoryFilter?:''?>">
    <input type="hidden" name="passage_id" value="<?=$passageFilter?:''?>">
    <input type="hidden" name="author_id" value="<?=$authorFilter?:''?>">
    <input type="hidden" name="p" value="<?=$currentPage?>">
    <label class="qz-bulkbar__all"><input type="checkbox" data-question-bulk-all> Pilih halaman ini</label>
    <span data-question-bulk-count>0 dipilih</span>
    <select class="adam-input" name="bulk_action" data-question-bulk-action>
      <option value="">Bulk action...</option>
      <option value="activate">Aktifkan</option>
      <option value="deactivate">Nonaktifkan</option>
      <option value="delete">Hapus</option>
    </select>
    <button class="adam-button" type="submit" data-question-bulk-submit disabled>Terapkan</button>
  </form>

  <div class="qz-resultbar">
    <span>Menampilkan <b><?=$totalFiltered>0?$offset+1:0?></b>-<b><?=min($offset+$perPage,$totalFiltered)?></b> dari <b><?=$totalFiltered?></b> soal</span>
    <div class="qz-view-controls"><span>Urutan global diatur melalui Master Builder</span><details class="qz-column-picker" data-question-column-picker data-storage-key="quiz-question-columns-v1"><summary>Kolom</summary><div class="qz-column-picker__panel"><div class="qz-column-picker__title">Kolom yang ditampilkan</div><label><input type="checkbox" checked disabled> Pilih <small>Wajib</small></label><label><input type="checkbox" checked disabled> Soal <small>Wajib</small></label><label><input type="checkbox" data-question-column="type"> Tipe</label><label><input type="checkbox" data-question-column="answers" checked> Jawaban</label><label><input type="checkbox" data-question-column="status" checked> Status</label><label><input type="checkbox" checked disabled> Aksi <small>Wajib</small></label></div></details></div>
  </div>

  <table class="qz-table qz-question-table" data-question-column-table>
    <thead><tr>
      <th width="54"><span class="visually-hidden">Pilih</span></th>
      <th>Soal</th>
      <th width="140" data-column="type" hidden>Tipe</th>
      <th width="90" data-column="answers">Jawaban</th>
      <th width="120" data-column="status">Status</th>
      <th width="80">Aksi</th>
    </tr></thead>
    <tbody>
    <?php if (!$questions): ?><tr><td colspan="6" class="qz-empty-cell"><?=$totalFiltered===0&&($search!==''||$typeFilter!==''||$statusFilter!=='')?'Tidak ada soal yang cocok dengan filter.':'Belum ada soal.'?></td></tr>
    <?php else: foreach ($questions as $qq):
      $canMutate = qz_can_mutate_resource($pdo, (int)$user['id'], $quiz, $qq);
      $canShowContext = $canViewAllContentMetadata || $canMutate;
      $excerpt = qz_plain_excerpt((string)($qq['question_text']??''),200);
      $chips = [];
      if ($canShowContext && !empty($qq['category_name'])) $chips[] = '<span class="chip" title="Kategori">🏷 '.h($qq['category_name']).'</span>';
      if ($canShowContext && !empty($qq['passage_title'])) $chips[] = '<span class="chip" title="Passage">📄 '.h($qq['passage_title']).'</span>';
      $contributor = trim((string)($qq['contributor_name']??''));
      if ($contributor === '') $contributor = trim((string)($qq['contributor_email']??''));
      if ($contributor === '') $contributor = 'User #'.(int)($qq['created_by']??0);
      $chips[] = '<span class="chip muted" title="Contributor">'.h($contributor).'</span>';
      $chips[] = '<span class="chip muted" title="Urutan global">'.(($qq['sort_order']??null)!==null?'#'.(int)$qq['sort_order']:'Akhir').'</span>';
      $isActive = (int)($qq['is_active']??0)===1;
    ?>
      <tr>
        <td class="qz-question-select" data-label="Pilih"><?php if($canMutate):?><input type="checkbox" name="ids[]" value="<?=(int)$qq['id']?>" form="question-bulk-form" data-question-bulk-item aria-label="Pilih soal oleh <?=h($contributor)?>"><?php else:?><span class="muted" title="Soal ini read-only">—</span><?php endif;?></td>
        <td class="qz-question-cell" data-label="Soal">
          <div class="qtext"><?=h($excerpt)?></div>
          <div class="qsub"><?=implode(' ',$chips)?></div>
        </td>
        <td data-label="Tipe" data-column="type" hidden><span class="badge"><?=h($typeLabels[(string)$qq['question_type']]??(string)$qq['question_type'])?></span></td>
        <td data-label="Jawaban" data-column="answers"><span class="badge count"><?=(int)$qq['answer_count']?></span></td>
        <td data-label="Status" data-column="status"><?php if($isActive):?><span class="badge ok">Aktif</span><?php else:?><span class="badge muted">Nonaktif</span><?php endif;?></td>
        <td class="qz-row-actions qz-question-actions" data-label="Aksi">
          <div class="qz-action-menu qz-action-menu--compact">
            <button type="button" class="qz-action-menu__toggle" id="question-actions-toggle-<?=(int)$qq['id']?>" aria-expanded="false" aria-controls="question-actions-<?=(int)$qq['id']?>" aria-label="Aksi soal #<?=(int)$qq['id']?>">
              <span class="qz-action-menu__dots" aria-hidden="true">&#8942;</span>
            </button>
            <div class="qz-action-menu__panel" id="question-actions-<?=(int)$qq['id']?>" role="group" aria-labelledby="question-actions-toggle-<?=(int)$qq['id']?>" hidden>
              <a class="qz-action-menu__item" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=edit&id=<?=(int)$qq['id']?>&quiz_id=<?=$quiz_id?>"><?=$canMutate?'Edit':'Lihat'?></a>
              <?php if($canMutate):?>
              <form method="post" action="<?=h($base)?>/?page=<?=$pluginPage?>&quiz_id=<?=$quiz_id?>" class="qz-action-menu__form">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?=(int)$qq['id']?>">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <button type="submit" class="qz-action-menu__item qz-action-menu__item--danger" data-confirm="Hapus soal ini?" data-confirm-variant="danger">Hapus</button>
              </form>
              <?php endif;?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if($totalPages>1):?>
  <nav class="qz-pagination" aria-label="Navigasi halaman soal">
    <?php if($currentPage>1):?><a href="<?=h($pageUrl($currentPage-1))?>">Sebelumnya</a><?php else:?><span class="disabled">Sebelumnya</span><?php endif;?>
    <div class="qz-pagination__pages">
      <?php for($pageNumber=max(1,$currentPage-2);$pageNumber<=min($totalPages,$currentPage+2);$pageNumber++):?>
        <a href="<?=h($pageUrl($pageNumber))?>" <?=$pageNumber===$currentPage?'aria-current="page"':''?>><?=$pageNumber?></a>
      <?php endfor;?>
    </div>
    <span>Halaman <?=$currentPage?> / <?=$totalPages?></span>
    <?php if($currentPage<$totalPages):?><a href="<?=h($pageUrl($currentPage+1))?>">Berikutnya</a><?php else:?><span class="disabled">Berikutnya</span><?php endif;?>
  </nav>
  <?php endif;?>
</section>
<?php
