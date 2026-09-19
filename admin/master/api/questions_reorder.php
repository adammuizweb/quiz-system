<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$ordered = $in['ordered_ids'] ?? null;
$expectedRevision = required_order_revision($in['order_revision'] ?? null);
if ($quiz_id<=0) jfail('Invalid payload', 400);
$ordered = ordered_positive_ids($ordered, 2);
require_quiz_exists($quiz_id);
try {
  $userId = (int)qz_api_user()['id'];
  $pdo->beginTransaction();
  $quiz = qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, $userId);
  $rows = lock_order_collection($pdo, 'questions', $quiz_id);
  if (!hash_equals(order_revision($rows), $expectedRevision) || !order_members_match($ordered, $rows)) order_conflict($pdo);
  foreach ($rows as $row) {
    if (!qz_can_mutate_resource($pdo, $userId, $quiz, $row)) throw new RuntimeException('Question order is read-only.');
  }
  $upd = $pdo->prepare("UPDATE questions SET sort_order=:sort WHERE id=:id AND quiz_id=:quiz_id");
  $i=1;
  foreach ($ordered as $id) { $upd->execute([':sort'=>$i, ':id'=>$id, ':quiz_id'=>$quiz_id]); $i++; }
  $pdo->commit();
  jok(['order_revision' => order_revision(array_map(
    static fn(int $id, int $index): array => ['id' => $id, 'sort_order' => $index + 1],
    $ordered,
    array_keys($ordered)
  ))]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
