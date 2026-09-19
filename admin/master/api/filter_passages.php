<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jfail('Method not allowed', 405);

$quizId = (int)($_GET['quiz_id'] ?? 0);
$categoryRaw = trim((string)($_GET['category_id'] ?? ''));
if ($quizId <= 0 || ($categoryRaw !== '' && (!ctype_digit($categoryRaw) || (int)$categoryRaw <= 0))) jfail('Invalid filter scope', 400);
$categoryId = $categoryRaw === '' ? null : (int)$categoryRaw;
$quiz = require_quiz_exists($quizId);
$user = qz_api_user();
$userId = (int)$user['id'];
$canViewAll = qz_can_manage_any($pdo, $userId) || (int)$quiz['created_by'] === $userId;

try {
  if ($categoryId !== null) {
    $sql = 'SELECT id FROM question_categories WHERE id=:id AND quiz_id=:quiz_id';
    $params = [':id'=>$categoryId, ':quiz_id'=>$quizId];
    if (!$canViewAll) {
      $sql .= ' AND created_by=:created_by';
      $params[':created_by'] = $userId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) jfail('Category not found', 404);
  }

  $sql = 'SELECT id,title FROM question_passages WHERE quiz_id=:quiz_id AND ';
  $params = [':quiz_id'=>$quizId];
  if ($categoryId === null) $sql .= 'category_id IS NULL';
  else {
    $sql .= 'category_id=:category_id';
    $params[':category_id'] = $categoryId;
  }
  if (!$canViewAll) {
    $sql .= ' AND created_by=:created_by';
    $params[':created_by'] = $userId;
  }
  $sql .= ' ORDER BY (sort_order IS NULL) ASC,sort_order ASC,id ASC';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $passages = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $passage) {
    $id = (int)$passage['id'];
    $title = trim((string)($passage['title'] ?? ''));
    $passages[] = ['id'=>$id, 'title'=>$title !== '' ? $title : 'Passage #' . $id];
  }
  jok(['passages'=>$passages]);
} catch (Throwable $error) {
  jfail('DB error', 500);
}
