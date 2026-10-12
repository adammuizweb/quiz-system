<?php
declare(strict_types=1);

if (ob_get_level() === 0) { ob_start(); }
require_once __DIR__ . '/_timer_helper.php';
require_once __DIR__ . '/../_security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $message, ?array $data = null): never {
  http_response_code($code);
  if (ob_get_length() !== false && ob_get_length() > 0) @ob_clean();
  echo json_encode(['ok' => false, 'error' => $message, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) $fail(419, 'CSRF token mismatch');

try {
  $input = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($input)) $fail(400, 'Bad JSON');
  $quizId = (int)($input['quiz_id'] ?? 0);
  $questionId = (int)($input['question_id'] ?? 0);
  $alias = trim((string)($input['attempt'] ?? ''));
  try {
    $marked = quiz_normalize_bool($input['marked'] ?? null);
  } catch (InvalidArgumentException) {
    $fail(400, 'Invalid review state');
  }
  if ($quizId <= 0 || $questionId <= 0 || preg_match('/^[a-zA-Z0-9_-]{6,80}$/', $alias) !== 1) $fail(400, 'Invalid params');
  if (!quiz_public_route_accepts_quiz($quizId)) $fail(404, 'Not found');

  $cookieId = trim((string)($_COOKIE['quiz_cid'] ?? ''));
  $token = (string)($_COOKIE['quiz_token_' . $quizId] ?? '');
  if (preg_match('/^[a-f0-9-]{36}$/i', $cookieId) !== 1 || $token === '') $fail(401, 'Not registered');

  $studentStmt = $pdo->prepare('SELECT id FROM students WHERE cookie_id=:cookie_id LIMIT 1');
  $studentStmt->execute([':cookie_id' => $cookieId]);
  $studentId = (int)$studentStmt->fetchColumn();
  if ($studentId <= 0) $fail(401, 'Student not found');

  $pdo->beginTransaction();
  $attemptStmt = $pdo->prepare('SELECT a.*, q.duration_minutes, q.timer_warning_seconds, q.allow_mark_for_review
    FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id
    WHERE a.attempt_alias=:alias AND a.student_id=:student_id LIMIT 1 FOR UPDATE');
  $attemptStmt->execute([':alias' => $alias, ':student_id' => $studentId]);
  $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt || (int)$attempt['quiz_id'] !== $quizId) { $pdo->rollBack(); $fail(403, 'Attempt not found'); }
  if (!hash_equals((string)$attempt['token_hash'], hash('sha256', $token))
      || !hash_equals((string)$attempt['user_agent_hash'], hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
    $pdo->rollBack(); $fail(403, 'Access not valid');
  }
  if (empty($attempt['email_verified_at']) || (string)$attempt['status'] === 'registered') { $pdo->rollBack(); $fail(403, 'Not verified'); }
  if ((string)$attempt['status'] === 'submitted') { $pdo->rollBack(); $fail(403, 'Already submitted'); }
  if (quiz_safe_bool($attempt['allow_mark_for_review'] ?? 0) !== 1) { $pdo->rollBack(); $fail(403, 'Review marking is disabled'); }
  if (!quiz_attempt_package_is_complete($pdo, (int)$attempt['id'])) {
    $pdo->rollBack(); $fail(409, quiz_public_title($pdo) . ' preparation required.', ['preparation_required' => true]);
  }

  $timer = qz_timer_enforce($pdo, (int)$attempt['id'], (int)($attempt['duration_minutes'] ?? 0), true, true, $attempt['timer_warning_seconds'] ?? null);
  if (!empty($timer['time_up'])) {
    $pdo->commit();
    if (!empty($timer['finalized'])) quiz_extension_emit('attempt.submitted', [
      'attempt_id' => (int)$attempt['id'], 'quiz_id' => $quizId, 'reason' => 'timer_expired', 'status' => 'submitted'
    ]);
    $fail(403, 'The time limit has expired.', ['time_up' => true, 'timer' => $timer]);
  }

  $assigned = $pdo->prepare('SELECT id, marked_for_review FROM attempt_questions
    WHERE attempt_id=:attempt_id AND question_id=:question_id LIMIT 1 FOR UPDATE');
  $assigned->execute([':attempt_id' => (int)$attempt['id'], ':question_id' => $questionId]);
  $question = $assigned->fetch(PDO::FETCH_ASSOC);
  if (!$question) { $pdo->rollBack(); $fail(403, 'Question not in this attempt'); }

  $changed = (int)$question['marked_for_review'] !== $marked;
  if ($changed) {
    $update = $pdo->prepare('UPDATE attempt_questions SET marked_for_review=:marked,
      marked_at=CASE WHEN :marked_at=1 THEN NOW() ELSE NULL END WHERE id=:id LIMIT 1');
    $update->execute([':marked' => $marked, ':marked_at' => $marked, ':id' => (int)$question['id']]);
  }
  $pdo->commit();

  if ($changed) quiz_extension_emit('question.review_changed', [
    'attempt_id' => (int)$attempt['id'], 'quiz_id' => $quizId, 'question_id' => $questionId, 'marked' => (bool)$marked
  ]);
  echo json_encode(['ok' => true, 'data' => ['marked_for_review' => (bool)$marked], 'timer' => $timer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz/api/set_review] ' . $error->getMessage());
  $fail(500, 'Server error');
}
