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
$name = trim((string)($in['name'] ?? ''));
$description = (string)($in['description'] ?? '');
$shuffle_passages  = (int)($in['shuffle_passages'] ?? 1);
$shuffle_questions = (int)($in['shuffle_questions'] ?? 1);
$is_active         = (int)($in['is_active'] ?? 1);
if ($shuffle_passages !== 0 && $shuffle_passages !== 1) $shuffle_passages = 1;
if ($shuffle_questions !== 0 && $shuffle_questions !== 1) $shuffle_questions = 1;
if ($is_active !== 0 && $is_active !== 1) $is_active = 1;
if ($name === '') jfail('Nama kategori wajib diisi', 422);
$max_questions = $in['max_questions'] ?? null;
if ($max_questions === '' || $max_questions === null) $max_questions = null;
else { $max_questions = (int)$max_questions; if ($max_questions <= 0) jfail('max_questions harus >= 1 atau kosong', 422); if ($max_questions > 5000) jfail('max_questions terlalu besar', 422); }
$min_questions = $in['min_questions'] ?? 0;
if ($min_questions === '' || $min_questions === null) $min_questions = 0;
$min_questions = (int)$min_questions;
if ($min_questions < 0) jfail('min_questions harus >= 0', 422);
if ($min_questions > 5000) jfail('min_questions terlalu besar', 422);
if ($max_questions !== null && $min_questions > $max_questions) jfail('min_questions tidak boleh lebih besar dari max_questions', 422);
try {
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
  if ($id > 0) {
    qz_lock_category_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
    qz_assert_related_delivery_snapshots_complete($pdo, 'category', $id);
    $up = $pdo->prepare("UPDATE question_categories SET name=:name, description=:descr, shuffle_passages=:sp, shuffle_questions=:sq, max_questions=:mx, min_questions=:mn, is_active=:act WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
    $up->execute([':name'=>$name, ':descr'=>($description===''?null:$description), ':sp'=>$shuffle_passages, ':sq'=>$shuffle_questions, ':mx'=>$max_questions, ':mn'=>$min_questions, ':act'=>$is_active, ':id'=>$id, ':quiz_id'=>$quiz_id]);
    $pdo->commit();
    jok(['id'=>$id]);
  }
  $sort_order = qz_append_sort_order($pdo, 'categories', $quiz_id);
  $ins = $pdo->prepare("INSERT INTO question_categories (quiz_id, created_by, name, description, shuffle_passages, shuffle_questions, max_questions, min_questions, sort_order, is_active) VALUES (:quiz_id, :created_by, :name, :descr, :sp, :sq, :mx, :mn, :sort, :act)");
  $ins->execute([':quiz_id'=>$quiz_id, ':created_by'=>(int)$user['id'], ':name'=>$name, ':descr'=>($description===''?null:$description), ':sp'=>$shuffle_passages, ':sq'=>$shuffle_questions, ':mx'=>$max_questions, ':mn'=>$min_questions, ':sort'=>$sort_order, ':act'=>$is_active]);
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
