<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.operate');
require __DIR__ . '/../../_init.php';

$base = ADMIN_BASE_PATH;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
quiz_csrf_check();

$attemptId = (int)($_POST['attempt_id'] ?? 0);
if ($attemptId <= 0) { http_response_code(400); exit('Missing attempt_id'); }
qz_require_attempt_access($pdo, $attemptId);

$deliveryMode = trim((string)($_POST['delivery_mode'] ?? ''));
if (!in_array($deliveryMode, ['', 'online', 'onsite'], true)) {
    http_response_code(400);
    exit('Invalid delivery mode');
}

try {
    $pdo->beginTransaction();
    qz_lock_attempt_for_mutation($pdo, $attemptId, (int)qz_admin_user($pdo)['id'], 'plugin.quiz.attempts.operate');
    $stmt = $pdo->prepare('UPDATE quiz_attempts SET delivery_mode = :mode WHERE id = :id LIMIT 1');
    $stmt->execute([
        ':mode' => $deliveryMode === '' ? null : $deliveryMode,
        ':id' => $attemptId,
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(403); exit('Attempt is no longer authorized.');
}

$label = $deliveryMode === '' ? 'Belum diisi' : ucfirst($deliveryMode);
$loc = $base . '/?page=admin/quiz/attempts/view&attempt_id=' . $attemptId
     . '&msg=' . rawurlencode('Mode pengerjaan diubah menjadi ' . $label . '.');
qz_redirect($loc);
