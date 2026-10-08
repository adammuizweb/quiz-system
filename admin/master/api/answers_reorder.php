<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$qid     = (int)($in['question_id'] ?? 0);
$ordered = $in['ordered_ids'] ?? null;
$expectedRevision = required_order_revision($in['order_revision'] ?? null);
if ($quiz_id<=0 || $qid<=0) jfail('Invalid payload', 400);
$ordered = ordered_positive_ids($ordered, 2);
require_quiz_exists($quiz_id);
require_question_in_quiz($quiz_id, $qid);
try {
  $userId = (int)qz_api_user()['id'];
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, $userId);
  qz_lock_answer_for_mutation($pdo, $quiz_id, $qid, $ordered[0], $userId);
  qz_assert_question_snapshots_complete($pdo, $qid, false, true);
  $rows = lock_order_collection($pdo, 'answers', $qid);
  if (!hash_equals(order_revision($rows), $expectedRevision) || !order_members_match($ordered, $rows)) order_conflict($pdo);
  $upd = $pdo->prepare("UPDATE question_answers SET sort_order=:sort WHERE id=:id AND question_id=:qid");
  $i=1;
  foreach ($ordered as $id) { $upd->execute([':sort'=>$i, ':id'=>$id, ':qid'=>$qid]); $i++; }
  $pdo->commit();
  jok(['order_revision' => order_revision(array_map(
    static fn(int $id, int $index): array => ['id' => $id, 'sort_order' => $index + 1],
    $ordered,
    array_keys($ordered)
  ))]);
} catch (QuizAssignedContentException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail($e->getMessage(), 409, ['code' => 'assigned_content_locked']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
