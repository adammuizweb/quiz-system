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
require_quiz_exists($quiz_id);
$id = (int)($in['id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$passage_text = trim((string)($in['passage_text'] ?? ''));
$is_active = (int)($in['is_active'] ?? 1);
$shuffle   = (int)($in['shuffle_questions'] ?? 1);
if ($is_active !== 0 && $is_active !== 1) $is_active = 1;
if ($shuffle !== 0 && $shuffle !== 1) $shuffle = 1;
if ($passage_text === '') jfail('Passage text wajib diisi', 422);
$category_id = $in['category_id'] ?? null;
if ($category_id === '' || $category_id === null) $category_id = null;
else $category_id = (int)$category_id;
if ($category_id !== null && $category_id <= 0) $category_id = null;
$min_questions = $in['min_questions'] ?? 0;
if ($min_questions === '' || $min_questions === null) $min_questions = 0;
$min_questions = (int)$min_questions;
if ($min_questions < 0) jfail('min_questions harus >= 0', 422);
if ($min_questions > 5000) jfail('min_questions terlalu besar', 422);
$max_questions = $in['max_questions'] ?? null;
if ($max_questions === '' || $max_questions === null) $max_questions = null;
else { $max_questions = (int)$max_questions; if ($max_questions <= 0) jfail('max_questions harus >= 1 atau kosong', 422); if ($max_questions > 5000) jfail('max_questions terlalu besar', 422); }
if ($max_questions !== null && $min_questions > $max_questions) jfail('min_questions tidak boleh lebih besar dari max_questions', 422);
$image_media_id = $in['image_media_id'] ?? null;
$file_id        = $in['file_id'] ?? null;
$youtube_url    = trim((string)($in['youtube_url'] ?? ''));
$image_media_id = ($image_media_id === '' || $image_media_id === null) ? null : (int)$image_media_id;
if ($image_media_id !== null && $image_media_id <= 0) $image_media_id = null;
$file_id = ($file_id === '' || $file_id === null) ? null : (int)$file_id;
if ($file_id !== null && $file_id <= 0) $file_id = null;
$youtube_url = ($youtube_url === '') ? null : $youtube_url;
if ($youtube_url !== null) {
  if (!filter_var($youtube_url, FILTER_VALIDATE_URL)) jfail('Link YouTube tidak valid', 422);
  $host = strtolower((string)parse_url($youtube_url, PHP_URL_HOST));
  if (!in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true)) jfail('Link YouTube harus domain youtube.com atau youtu.be', 422);
}
try {
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
  if ($category_id !== null) {
    $st = $pdo->prepare("SELECT id FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $st->execute([':id'=>$category_id, ':quiz_id'=>$quiz_id]);
    if (!$st->fetchColumn()) { $pdo->rollBack(); jfail('Kategori tidak ditemukan untuk quiz ini', 422); }
  }
  if ($image_media_id !== null) {
    $st = $pdo->prepare("SELECT id FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1"); $st->execute([':id'=>$image_media_id]);
    if (!$st->fetchColumn()) { $pdo->rollBack(); jfail('Media gambar tidak ditemukan', 422); }
  }
  if ($file_id !== null) {
    $st = $pdo->prepare("SELECT id FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1"); $st->execute([':id'=>$file_id]);
    if (!$st->fetchColumn()) { $pdo->rollBack(); jfail('File tidak ditemukan', 422); }
  }
  if ($id > 0) {
    qz_lock_passage_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
    qz_assert_related_delivery_snapshots_complete($pdo, 'passage', $id);
    $up = $pdo->prepare("UPDATE question_passages SET category_id=:cid, title=:title, passage_text=:txt, image_media_id=:mid, file_id=:fid, youtube_url=:yt, shuffle_questions=:sh, min_questions=:mn, max_questions=:mq, is_active=:act WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $up->execute([':cid'=>$category_id, ':title'=>$title, ':txt'=>$passage_text, ':mid'=>$image_media_id, ':fid'=>$file_id, ':yt'=>$youtube_url, ':sh'=>$shuffle, ':mn'=>$min_questions, ':mq'=>$max_questions, ':act'=>$is_active, ':id'=>$id, ':quiz_id'=>$quiz_id]);
    $pdo->commit();
    jok(['id'=>$id]);
  }
  $sort_order = qz_append_sort_order($pdo, 'passages', $quiz_id);
  $ins = $pdo->prepare("INSERT INTO question_passages (quiz_id, created_by, category_id, title, passage_text, image_media_id, file_id, youtube_url, shuffle_questions, min_questions, max_questions, sort_order, is_active) VALUES (:quiz_id, :created_by, :cid, :title, :txt, :mid, :fid, :yt, :sh, :mn, :mq, :sort, :act)");
  $ins->execute([':quiz_id'=>$quiz_id, ':created_by'=>(int)$user['id'], ':cid'=>$category_id, ':title'=>$title, ':txt'=>$passage_text, ':mid'=>$image_media_id, ':fid'=>$file_id, ':yt'=>$youtube_url, ':sh'=>$shuffle, ':mn'=>$min_questions, ':mq'=>$max_questions, ':sort'=>$sort_order, ':act'=>$is_active]);
  $newId = (int)$pdo->lastInsertId();
  $pdo->commit();
  jok(['id'=>$newId, 'sort_order'=>$sort_order]);
} catch (QuizAssignedContentException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($e->getMessage(), 409, ['code' => 'assigned_content_locked']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
