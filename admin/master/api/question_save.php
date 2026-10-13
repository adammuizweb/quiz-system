<?php
declare(strict_types=1);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'FATAL: '.($e['message']??'Unknown')], JSON_UNESCAPED_UNICODE);
  }
});
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
if ($quiz_id <= 0) jfail('Missing quiz_id', 400);
try {
  require_quiz_exists($quiz_id);
  $id = (int)($in['id'] ?? 0);
  $question_text = trim((string)($in['question_text'] ?? ''));
  $question_type = (string)($in['question_type'] ?? 'single_choice');
  $is_active     = (int)($in['is_active'] ?? 1);
  $allowed = ['single_choice','multiple_choice','yesno','text','number'];
  if (!in_array($question_type, $allowed, true)) jfail('Invalid question_type', 400);
  if ($is_active !== 0 && $is_active !== 1) $is_active = 1;
  if ($question_text === '') jfail('Question text is required.', 422);
  $image_media_id = $in['image_media_id'] ?? null;
  $file_id        = $in['file_id'] ?? null;
  $youtube_url    = trim((string)($in['youtube_url'] ?? ''));
  if ($image_media_id === '' || $image_media_id === null) $image_media_id = null;
  else $image_media_id = (int)$image_media_id;
  if ($image_media_id !== null && $image_media_id <= 0) $image_media_id = null;
  if ($file_id === '' || $file_id === null) $file_id = null;
  else $file_id = (int)$file_id;
  if ($file_id !== null && $file_id <= 0) $file_id = null;
  if ($youtube_url === '') $youtube_url = null;
  $passage_id = $in['passage_id'] ?? null;
  if ($passage_id === '' || $passage_id === null) $passage_id = null;
  else $passage_id = (int)$passage_id;
  if ($passage_id !== null && $passage_id <= 0) $passage_id = null;
  $passage_seq = $in['passage_seq'] ?? null;
  if ($passage_seq === '' || $passage_seq === null) $passage_seq = null;
  else $passage_seq = (int)$passage_seq;
  if ($passage_seq !== null && $passage_seq <= 0) $passage_seq = null;
  if ($passage_id === null) $passage_seq = null;
  if ($passage_id !== null) {
    $st = $pdo->prepare("SELECT id FROM question_passages WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $st->execute([':id'=>$passage_id, ':quiz_id'=>$quiz_id]);
    if (!$st->fetch()) jfail('Passage not found in this quiz.', 422);
  }
  $category_id_present = array_key_exists('category_id', $in);
  $category_id_in = null;
  if ($category_id_present) {
    $category_id_in = $in['category_id'];
    if ($category_id_in === '' || $category_id_in === null) $category_id_in = null;
    else $category_id_in = (int)$category_id_in;
    if ($category_id_in !== null && $category_id_in <= 0) $category_id_in = null;
  }
  if ($youtube_url !== null) {
    if (!filter_var($youtube_url, FILTER_VALIDATE_URL)) jfail('Invalid YouTube URL.', 422);
    $host = strtolower((string)parse_url($youtube_url, PHP_URL_HOST));
    if (!in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true)) jfail('The YouTube URL must use youtube.com or youtu.be.', 422);
  }
  if ($image_media_id !== null) {
    $st = $pdo->prepare("SELECT id FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1");
    $st->execute([':id'=>$image_media_id]);
    if (!$st->fetch()) jfail('Image media not found.', 422);
  }
  if ($file_id !== null) {
    $st = $pdo->prepare("SELECT id FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
    $st->execute([':id'=>$file_id]);
    if (!$st->fetch()) jfail('Public file not found.', 422);
  }
  $clone_from = (int)($in['clone_from_question_id'] ?? 0);
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
  if ($id > 0) {
    $cur = qz_lock_question_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
    qz_assert_question_snapshots_complete($pdo, $id, true, true);
    qz_assert_question_type_change_safe($pdo, $id, (string)$cur['question_type'], $question_type);
    $curCat = isset($cur['category_id']) ? (int)$cur['category_id'] : null;
    if ($curCat !== null && $curCat <= 0) $curCat = null;
    $final_category_id = null;
    if ($passage_id !== null) { $final_category_id = null; }
    else { $final_category_id = $category_id_present ? $category_id_in : $curCat; }
    if ($final_category_id !== null) {
      $st = $pdo->prepare("SELECT id FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
      $st->execute([':id'=>$final_category_id, ':quiz_id'=>$quiz_id]);
      if (!$st->fetch()) { $pdo->rollBack(); jfail('Category not found in this quiz.', 422); }
    }
    $stmt = $pdo->prepare("UPDATE questions SET passage_id=:pid, passage_seq=:pseq, category_id=:cid, question_text=:qt, question_type=:type, is_active=:act, image_media_id=:mid, file_id=:fid, youtube_url=:yt WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $stmt->execute([':pid'=>$passage_id, ':pseq'=>$passage_seq, ':cid'=>$final_category_id, ':qt'=>$question_text, ':type'=>$question_type, ':act'=>$is_active, ':mid'=>$image_media_id, ':fid'=>$file_id, ':yt'=>$youtube_url, ':id'=>$id, ':quiz_id'=>$quiz_id]);
    $pdo->commit();
    jok(['id'=>$id]);
  }
  $final_category_id = null;
  if ($passage_id !== null) { $final_category_id = null; }
  else { $final_category_id = $category_id_present ? $category_id_in : null; }
  if ($final_category_id !== null) {
    $st = $pdo->prepare("SELECT id FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $st->execute([':id'=>$final_category_id, ':quiz_id'=>$quiz_id]);
    if (!$st->fetch()) { $pdo->rollBack(); jfail('Category not found in this quiz.', 422); }
  }
  if ($clone_from > 0) qz_lock_question_for_mutation($pdo, $quiz_id, $clone_from, (int)$user['id']);
  $sort_order = qz_append_sort_order($pdo, 'questions', $quiz_id);
  $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, created_by, passage_id, passage_seq, category_id, question_text, question_type, sort_order, is_active, image_media_id, file_id, youtube_url) VALUES (:quiz_id, :created_by, :pid, :pseq, :cid, :qt, :type, :sort, :act, :mid, :fid, :yt)");
  $stmt->execute([':quiz_id'=>$quiz_id, ':created_by'=>(int)$user['id'], ':pid'=>$passage_id, ':pseq'=>$passage_seq, ':cid'=>$final_category_id, ':qt'=>$question_text, ':type'=>$question_type, ':sort'=>$sort_order, ':act'=>$is_active, ':mid'=>$image_media_id, ':fid'=>$file_id, ':yt'=>$youtube_url]);
  $id = (int)$pdo->lastInsertId();
  if ($clone_from > 0) {
    $stmt = $pdo->prepare("SELECT answer_text, score, sort_order, is_active FROM question_answers WHERE question_id=:qid ORDER BY sort_order ASC, id ASC");
    $stmt->execute([':qid'=>$clone_from]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($answers) {
      $ins = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, score, sort_order, is_active) VALUES (:question_id, :answer_text, :score, :sort_order, :is_active)");
      foreach ($answers as $a) { $ins->execute([':question_id'=>$id, ':answer_text'=>(string)$a['answer_text'], ':score'=>(int)$a['score'], ':sort_order'=>(int)$a['sort_order'], ':is_active'=>(int)$a['is_active']]); }
    }
  }
  $pdo->commit();
  jok(['id'=>$id, 'sort_order'=>$sort_order]);
} catch (QuizAssignedContentException $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  jfail($e->getMessage(), 409, ['code' => 'assigned_content_locked']);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
