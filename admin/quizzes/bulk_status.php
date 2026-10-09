<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$fallback = $base . '/?page=admin/quiz/quizzes';
$safeRedirect = static function (string $value) use ($base, $fallback): string {
  $parts = parse_url($value);
  if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || ($parts['path'] ?? '') !== $base . '/') return $fallback;
  parse_str((string)($parts['query'] ?? ''), $query);
  if (!in_array((string)($query['page'] ?? ''), ['admin/quiz/quizzes', 'admin/quiz/master'], true)) return $fallback;
  return $value;
};
$redirect = $safeRedirect(trim((string)($_POST['redirect'] ?? '')));
$go = static function (string $url, string $key, string $message): never {
  $separator = str_contains($url, '?') ? '&' : '?';
  header('Location: ' . $url . $separator . $key . '=' . rawurlencode($message), true, 303);
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}
if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) $go($redirect, 'err', 'Invalid request.');

$activeRaw = (string)($_POST['is_active'] ?? '');
if (!in_array($activeRaw, ['0', '1'], true)) $go($redirect, 'err', 'Select a valid bulk action.');
$active = (int)$activeRaw;

$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) $go($redirect, 'err', 'Invalid Quiz selection.');
$ids = [];
foreach ($rawIds as $rawId) {
  if (!is_scalar($rawId)) continue;
  $id = (int)$rawId;
  if ($id > 0) $ids[$id] = $id;
}
$ids = array_values($ids);
if ($ids === [] || count($ids) > 100) $go($redirect, 'err', 'Select 1 to 100 Quizzes.');

$user = qz_admin_user($pdo);
if (!$user['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }
$placeholders = [];
$params = [];
foreach ($ids as $index => $id) {
  $key = ':id' . $index;
  $placeholders[] = $key;
  $params[$key] = $id;
}
$idSql = implode(',', $placeholders);

try {
  $pdo->beginTransaction();
  sort($ids, SORT_NUMERIC);
  foreach ($ids as $id) qz_lock_quiz_for_workspace_mutation($pdo, $id, $user['id']);
  $select = $pdo->prepare("SELECT id, is_primary FROM quizzes WHERE id IN ({$idSql}) FOR UPDATE");
  $select->execute($params);
  $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (count($rows) !== count($ids)) throw new RuntimeException('Quiz selection is missing or unauthorized.');
  if ($active === 0) {
    foreach ($rows as $row) {
      if ((int)$row['is_primary'] === 1) throw new RuntimeException('The Primary Quiz cannot be deactivated with a bulk action.');
    }
  }

  $updateParams = $params;
  $updateParams[':active'] = $active;
  $update = $pdo->prepare("UPDATE quizzes SET is_active = :active WHERE id IN ({$idSql})");
  $update->execute($updateParams);
  $changed = $update->rowCount();
  if ($changed > count($ids)) throw new RuntimeException('Bulk update affected an unexpected number of rows.');
  $pdo->commit();

  $go($redirect, 'msg', count($ids) . ' Quiz records processed; ' . $changed . ' rows changed.');
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz bulk status] ' . $error->getMessage());
  $go($redirect, 'err', $error->getMessage() === 'The Primary Quiz cannot be deactivated with a bulk action.'
    ? $error->getMessage()
    : 'Bulk update failed. Confirm that all selected Quizzes are still available and accessible.');
}
