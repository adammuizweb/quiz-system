<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.workspace.access');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quizId = (int)($_POST['quiz_id'] ?? 0);
$search = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 120, 'UTF-8');
$type = trim((string)($_POST['type'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$categoryId = max(0, (int)($_POST['category_id'] ?? 0));
$passageId = max(0, (int)($_POST['passage_id'] ?? 0));
$authorId = max(0, (int)($_POST['author_id'] ?? 0));
$page = max(1, (int)($_POST['p'] ?? 1));
if (!in_array($type, ['single_choice','multiple_choice','yesno','text','number'], true)) $type = '';
if (!in_array($status, ['active','inactive'], true)) $status = '';
$redirect = $base . '/?' . http_build_query(array_filter([
  'page' => 'admin/quiz/questions',
  'quiz_id' => $quizId,
  'q' => $search,
  'type' => $type,
  'status' => $status,
  'category_id' => $categoryId ?: '',
  'passage_id' => $passageId ?: '',
  'author_id' => $authorId ?: '',
  'p' => $page,
], static fn(mixed $value): bool => $value !== ''));
$go = static function (string $key, string $message) use ($redirect): never {
  qz_redirect($redirect . '&' . $key . '=' . rawurlencode($message));
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}
if ($quizId <= 0 || !csrf_check((string)($_POST['csrf_token'] ?? ''))) $go('err', 'Request bulk tidak valid.');

$action = trim((string)($_POST['bulk_action'] ?? ''));
if (!in_array($action, ['activate', 'deactivate', 'delete'], true)) $go('err', 'Pilih bulk action yang valid.');
$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) $go('err', 'Pilihan soal tidak valid.');
$ids = [];
foreach ($rawIds as $rawId) {
  if (!is_scalar($rawId)) continue;
  $id = (int)$rawId;
  if ($id > 0) $ids[$id] = $id;
}
$ids = array_values($ids);
if ($ids === [] || count($ids) > 100) $go('err', 'Pilih 1 sampai 100 soal.');

$userId = (int)qz_admin_user($pdo)['id'];
try {
  $pdo->beginTransaction();
  sort($ids, SORT_NUMERIC);
  foreach ($ids as $id) qz_lock_question_for_mutation($pdo, $quizId, $id, $userId);

  $changed = 0;
  if ($action === 'delete') {
    $stmt = $pdo->prepare('DELETE FROM questions WHERE id=:id AND quiz_id=:quiz_id LIMIT 1');
    foreach ($ids as $id) {
      $stmt->execute([':id' => $id, ':quiz_id' => $quizId]);
      $changed += $stmt->rowCount();
    }
  } else {
    $active = $action === 'activate' ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE questions SET is_active=:active WHERE id=:id AND quiz_id=:quiz_id LIMIT 1');
    foreach ($ids as $id) {
      $stmt->execute([':active' => $active, ':id' => $id, ':quiz_id' => $quizId]);
      $changed += $stmt->rowCount();
    }
  }
  if ($changed > count($ids)) throw new RuntimeException('Bulk mutation affected an unexpected number of rows.');
  $pdo->commit();

  $labels = ['activate' => 'diaktifkan', 'deactivate' => 'dinonaktifkan', 'delete' => 'dihapus'];
  $go('msg', count($ids) . ' soal diproses; ' . $changed . ' baris ' . $labels[$action] . '.');
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz question bulk] ' . $error->getMessage());
  $go('err', 'Bulk action gagal. Pastikan semua soal terpilih masih tersedia dan dapat Anda kelola.');
}
