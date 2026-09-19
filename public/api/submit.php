<?php
// /quiz/api/submit.php
declare(strict_types=1);

if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/_timer_helper.php';
require_once __DIR__ . '/../_security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_fail(int $code, string $msg, ?array $data=null): void {
  http_response_code($code);
  if (ob_get_length() !== false && ob_get_length() > 0) { @ob_clean(); }
  echo json_encode(['ok'=>false, 'error'=>$msg, 'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

set_exception_handler(function($e){
  error_log('[quiz/api/submit] UNCAUGHT: '.$e->getMessage());
  json_fail(500, 'Server error');
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) json_fail(419, 'CSRF token mismatch');

try {

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) json_fail(400, 'Bad JSON');

$isAuto = !empty($in['auto']);
$confirmIncomplete = !empty($in['confirm_incomplete']) || !empty($in['force_incomplete']) || !empty($in['allow_incomplete']);

$quiz_id = (int)($in['quiz_id'] ?? 0);
$attemptAlias = trim((string)($in['attempt'] ?? ''));

if ($quiz_id <= 0) json_fail(400, 'quiz_id invalid');
if ($attemptAlias === '' || !preg_match('/^[a-zA-Z0-9_\-]{6,80}$/', $attemptAlias)) json_fail(400, 'attempt invalid');

// cookies
$student_cookie_id = isset($_COOKIE['quiz_cid']) ? trim((string)$_COOKIE['quiz_cid']) : '';
if ($student_cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $student_cookie_id)) json_fail(401, 'Not registered');

$tokenCookieName = 'quiz_token_' . $quiz_id;
$token_plain = (string)($_COOKIE[$tokenCookieName] ?? '');
if ($token_plain === '') json_fail(401, 'Missing token');

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$user_agent_hash = hash('sha256', $ua);
$token_hash = hash('sha256', $token_plain);

// student id
$stmt = $pdo->prepare("SELECT id FROM students WHERE cookie_id=:cid LIMIT 1");
$stmt->execute([':cid'=>$student_cookie_id]);
$student_id = (int)$stmt->fetchColumn();
if ($student_id <= 0) json_fail(401, 'Student not found');

try {
  $pdo->beginTransaction();

  // lock attempt (+ duration)
  $stmt = $pdo->prepare("
    SELECT a.*, q.duration_minutes
    FROM quiz_attempts a
    JOIN quizzes q ON q.id = a.quiz_id
    WHERE a.attempt_alias = :a AND a.student_id = :sid
    FOR UPDATE
  ");
  $stmt->execute([':a'=>$attemptAlias, ':sid'=>$student_id]);
  $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$attempt) { $pdo->rollBack(); json_fail(403, 'Attempt not found'); }
  if ((int)$attempt['quiz_id'] !== $quiz_id) { $pdo->rollBack(); json_fail(403, 'Attempt mismatch'); }

  if (!hash_equals((string)$attempt['token_hash'], $token_hash) || !hash_equals((string)$attempt['user_agent_hash'], $user_agent_hash)) {
    $pdo->rollBack(); json_fail(403, 'Access not valid');
  }
  if (empty($attempt['email_verified_at']) || (string)$attempt['status'] === 'registered') {
    $pdo->rollBack(); json_fail(403, 'Not verified');
  }

  if ((string)$attempt['status'] === 'submitted') {
    // idempotent
    $pdo->commit();
    echo json_encode(['ok'=>true,'data'=>['already_submitted'=>true]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  $attempt_id = (int)$attempt['id'];

  /* ===== TIMER (MySQL based) ===== */
  $durMin = (int)($attempt['duration_minutes'] ?? 0);

  // jangan finalize di helper; submit yang finalize.
  $timer = qz_timer_enforce($pdo, $attempt_id, $durMin, true, false);
  $timeUp = !empty($timer['time_up']);

  if ($timeUp) {
    $isAuto = true; // time up => boleh submit meski belum lengkap
  }
  /* ===== END TIMER ===== */

  // total questions
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_questions WHERE attempt_id=:aid");
  $stmt->execute([':aid'=>$attempt_id]);
  $total = (int)$stmt->fetchColumn();
  if ($total <= 0) { $pdo->rollBack(); json_fail(500, 'No questions assigned', ['timer'=>$timer]); }

  // answered count (valid by type)
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id
    LEFT JOIN attempt_answers aa
      ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
    WHERE aq.attempt_id = :aid
      AND (
        (q.question_type IN ('single_choice','yesno') AND aa.selected_answer_id IS NOT NULL)
        OR
        (q.question_type = 'multiple_choice' AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '' AND TRIM(aa.answer_text) <> '[]')
        OR
        (q.question_type IN ('text','number') AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '')
      )
  ");
  $stmt->execute([':aid'=>$attempt_id]);
  $answered = (int)$stmt->fetchColumn();

if ($answered < $total && !$isAuto && !$confirmIncomplete) {
  $stmt = $pdo->prepare("
    SELECT aq.seq
    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id
    LEFT JOIN attempt_answers aa
      ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
    WHERE aq.attempt_id = :aid
      AND NOT (
        (q.question_type IN ('single_choice','yesno') AND aa.selected_answer_id IS NOT NULL)
        OR
        (q.question_type = 'multiple_choice' AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '' AND TRIM(aa.answer_text) <> '[]')
        OR
        (q.question_type IN ('text','number') AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '')
      )
    ORDER BY aq.seq ASC
    LIMIT 50
  ");
  $stmt->execute([':aid'=>$attempt_id]);
  $missing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

  $pdo->rollBack();
  json_fail(400, "Belum semua soal dijawab ({$answered}/{$total}).", ['missing_seq'=>$missing, 'timer'=>$timer]);
}

  // compute total score
  $stmt = $pdo->prepare("SELECT COALESCE(SUM(computed_score),0) FROM attempt_answers WHERE attempt_id = :aid");
  $stmt->execute([':aid'=>$attempt_id]);
  $total_score = (int)$stmt->fetchColumn();

  // mark submitted (idempotent)
  $stmt = $pdo->prepare("
    UPDATE quiz_attempts
    SET total_score = :score,
        status = 'submitted',
        submitted_at = COALESCE(submitted_at, NOW()),
        last_activity_at = NOW()
    WHERE id = :id AND status <> 'submitted'
    LIMIT 1
  ");
  $stmt->execute([':score'=>$total_score, ':id'=>$attempt_id]);

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'data' => [
      'attempt_id' => $attempt_id,
      'total_questions' => $total,
      'answered_count' => $answered,
      'total_score' => $total_score,
      'time_up' => $timeUp,
    ],
    'timer' => $timer,
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[quiz/api/submit] error: " . $e->getMessage());
  json_fail(500, 'Server error');
}
} catch (Throwable $e) {
  error_log("[quiz/api/submit] FAIL: ".$e->getMessage());
  json_fail(500, 'Server error');
}
