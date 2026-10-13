<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$ordered = $in['ordered_ids'] ?? null;
$expectedRevision = required_order_revision($in['order_revision'] ?? null);
if ($quiz_id<=0) jfail('Missing quiz_id', 400);
if (!is_array($ordered) || !$ordered) jfail('ordered_ids must be a non-empty array', 422);
require_quiz_exists($quiz_id);
$ids = ordered_positive_ids($ordered);
try {
  $userId = (int)qz_api_user()['id'];
  $pdo->beginTransaction();
  $quiz = qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, $userId);
  qz_assert_related_delivery_snapshots_complete($pdo, 'quiz', $quiz_id);
  $rows = lock_order_collection($pdo, 'categories', $quiz_id);
  if (!hash_equals(order_revision($rows), $expectedRevision) || !order_members_match($ids, $rows)) order_conflict($pdo);
  foreach ($rows as $row) {
    if (!qz_can_mutate_resource($pdo, $userId, $quiz, $row)) throw new RuntimeException('Category order is read-only.');
  }
  $up = $pdo->prepare("UPDATE question_categories SET sort_order=:s WHERE quiz_id=:quiz_id AND id=:id LIMIT 1");
  $s = 1;
  foreach ($ids as $cid) { $up->execute([':s'=>$s, ':quiz_id'=>$quiz_id, ':id'=>$cid]); $s++; }
  $pdo->commit();
  jok(['order_revision' => order_revision(array_map(
    static fn(int $id, int $index): array => ['id' => $id, 'sort_order' => $index + 1],
    $ids,
    array_keys($ids)
  ))]);
} catch (QuizAssignedContentException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($e->getMessage(), 409, ['code' => 'assigned_content_locked']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
