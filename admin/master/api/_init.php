<?php
declare(strict_types=1);
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.workspace.access');
require_once dirname(__DIR__, 2) . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

function jfail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra);
  exit;
}

function jok(array $extra = []): void {
  echo json_encode(['ok' => true] + $extra);
  exit;
}

function jin(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function require_csrf(string $token): void {
  if (!function_exists('csrf_token') || !hash_equals(csrf_token(), $token)) {
    jfail('CSRF invalid', 419);
  }
}

function require_quiz_exists(int $quiz_id): array {
  global $pdo;
  if ($quiz_id <= 0) jfail('Missing quiz_id', 400);
  return qz_require_quiz_access($pdo, $quiz_id);
}

function qz_api_user(): array {
  global $pdo;
  return qz_admin_user($pdo);
}

function qz_api_can_mutate(array $quiz, array $resource): bool {
  global $pdo;
  $user = qz_api_user();
  return qz_can_mutate_resource($pdo, (int)$user['id'], $quiz, $resource);
}

function ordered_positive_ids(mixed $value, int $minimum = 1): array {
  if (!is_array($value) || count($value) < $minimum) jfail('Invalid ordered_ids', 400);
  $ids = [];
  foreach ($value as $raw) {
    if (!is_int($raw) && !is_string($raw)) jfail('Invalid ordered_ids', 400);
    $text = (string)$raw;
    if ($text === '' || !ctype_digit($text) || (int)$text <= 0) jfail('Invalid ordered_ids', 400);
    $ids[] = (int)$text;
  }
  if (count($ids) !== count(array_unique($ids))) jfail('Duplicate ids are not allowed', 422);
  return $ids;
}

function order_revision(array $rows): string {
  usort($rows, static fn(array $a, array $b): int => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
  $parts = [];
  foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $sort = isset($row['sort_order']) ? (int)$row['sort_order'] : 'null';
    $parts[] = $id . ':' . $sort;
  }
  return hash('sha256', implode('|', $parts));
}

function required_order_revision(mixed $value): string {
  $revision = is_string($value) ? strtolower(trim($value)) : '';
  if (preg_match('/^[a-f0-9]{64}$/', $revision) !== 1) jfail('Order revision wajib diisi', 422);
  return $revision;
}

function lock_order_collection(PDO $pdo, string $resource, int $parentId): array {
  $collections = [
    'categories' => ['question_categories', 'quiz_id', true],
    'passages' => ['question_passages', 'quiz_id', true],
    'questions' => ['questions', 'quiz_id', true],
    'answers' => ['question_answers', 'question_id', false],
  ];
  if (!isset($collections[$resource]) || $parentId <= 0 || !$pdo->inTransaction()) {
    throw new RuntimeException('Invalid order collection.');
  }
  [$table, $parentColumn, $hasOwner] = $collections[$resource];
  $columns = $hasOwner ? 'id, created_by, sort_order' : 'id, sort_order';
  $stmt = $pdo->prepare("SELECT {$columns} FROM {$table} WHERE {$parentColumn}=:parent_id ORDER BY id ASC FOR UPDATE");
  $stmt->execute([':parent_id' => $parentId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function order_members_match(array $orderedIds, array $rows): bool {
  $currentIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
  $submittedIds = $orderedIds;
  sort($currentIds, SORT_NUMERIC);
  sort($submittedIds, SORT_NUMERIC);
  return $currentIds === $submittedIds;
}

function order_conflict(PDO $pdo): never {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('Urutan sudah berubah. Muat ulang data sebelum mencoba lagi.', 409, ['code' => 'order_conflict']);
}

function require_question_in_quiz(int $quiz_id, int $question_id): void {
  global $pdo;
  if ($quiz_id <= 0 || $question_id <= 0) jfail('Missing quiz_id/question_id', 400);
  $st = $pdo->prepare("SELECT id FROM questions WHERE id=:qid AND quiz_id=:quiz_id LIMIT 1");
  $st->execute([':qid' => $question_id, ':quiz_id' => $quiz_id]);
  if (!$st->fetch()) jfail('Not found', 404);
}

function require_answer_in_question(int $question_id, int $answer_id): void {
  global $pdo;
  if ($question_id <= 0 || $answer_id <= 0) jfail('Missing question_id/id', 400);
  $st = $pdo->prepare("SELECT id FROM question_answers WHERE id=:id AND question_id=:qid LIMIT 1");
  $st->execute([':id' => $answer_id, ':qid' => $question_id]);
  if (!$st->fetch()) jfail('Answer not found', 404);
}

function require_category_in_quiz(int $quiz_id, int $category_id): void {
  global $pdo;
  if ($quiz_id <= 0 || $category_id <= 0) jfail('Missing quiz_id/category_id', 400);
  $st = $pdo->prepare("SELECT id FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
  $st->execute([':id' => $category_id, ':quiz_id' => $quiz_id]);
  if (!$st->fetch()) jfail('Category not found', 404);
}

function require_passage_in_quiz(int $quiz_id, int $passage_id): void {
  global $pdo;
  if ($quiz_id <= 0 || $passage_id <= 0) jfail('Missing quiz_id/passage_id', 400);
  $st = $pdo->prepare("SELECT id FROM question_passages WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
  $st->execute([':id' => $passage_id, ':quiz_id' => $quiz_id]);
  if (!$st->fetch()) jfail('Passage not found', 404);
}
