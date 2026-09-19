<?php
// /quiz/api/save_answer.php
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
  error_log('[quiz/api/save_answer] UNCAUGHT: '.$e->getMessage());
  json_fail(500, 'Server error');
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) json_fail(419, 'CSRF token mismatch');

try {

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) json_fail(400, 'Bad JSON');

$quiz_id = (int)($in['quiz_id'] ?? 0);
$attemptAlias = trim((string)($in['attempt'] ?? ''));
$question_id = (int)($in['question_id'] ?? 0);

$selected_answer_id = isset($in['selected_answer_id']) ? (int)$in['selected_answer_id'] : 0;
$selected_answer_ids = $in['selected_answer_ids'] ?? null;
$answer_text = array_key_exists('answer_text', $in) ? (string)$in['answer_text'] : null;

if ($quiz_id <= 0 || $question_id <= 0) json_fail(400, 'Invalid params');
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

// attempt + duration
$stmt = $pdo->prepare("
  SELECT a.*, q.duration_minutes
  FROM quiz_attempts a
  JOIN quizzes q ON q.id = a.quiz_id
  WHERE a.attempt_alias = :a AND a.student_id = :sid
  LIMIT 1
");
$stmt->execute([':a'=>$attemptAlias, ':sid'=>$student_id]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) json_fail(403, 'Attempt not found');

if ((int)$attempt['quiz_id'] !== $quiz_id) json_fail(403, 'Attempt mismatch');
if (!hash_equals((string)$attempt['token_hash'], $token_hash) || !hash_equals((string)$attempt['user_agent_hash'], $user_agent_hash)) {
  json_fail(403, 'Access not valid');
}
if (empty($attempt['email_verified_at']) || (string)$attempt['status'] === 'registered') json_fail(403, 'Not verified');
if ((string)$attempt['status'] === 'submitted') json_fail(403, 'Already submitted');

$attempt_id = (int)$attempt['id'];

/* ===== TIMER ENFORCE (MySQL based) ===== */
$durMin = (int)($attempt['duration_minutes'] ?? 0);

try {
  $timer = qz_timer_enforce($pdo, $attempt_id, $durMin, true, true);
  if (!empty($timer['time_up'])) {
    json_fail(403, 'Waktu ujian sudah habis.', ['time_up'=>true, 'timer'=>$timer]);
  }
} catch (Throwable $e) {
  error_log("[quiz/api/save_answer] timer error: ".$e->getMessage());
  json_fail(500, 'Server error');
}
/* ===== END TIMER ===== */

// question type + ensure belongs to attempt
$stmt = $pdo->prepare("
  SELECT q.question_type
  FROM attempt_questions aq
  JOIN questions q ON q.id = aq.question_id
  WHERE aq.attempt_id = :aid AND aq.question_id = :qid
  LIMIT 1
");
$stmt->execute([':aid'=>$attempt_id, ':qid'=>$question_id]);
$qType = (string)($stmt->fetchColumn() ?: '');
if ($qType === '') json_fail(403, 'Question not in this attempt');

$computed_score = 0;
$sel_id = null;
$txt = null;

if (in_array($qType, ['single_choice','yesno'], true)) {
  if ($selected_answer_id <= 0) json_fail(400, 'No answer selected');
  $stmt = $pdo->prepare("
    SELECT score
    FROM question_answers
    WHERE id = :aid AND question_id = :qid AND is_active = 1
    LIMIT 1
  ");
  $stmt->execute([':aid'=>$selected_answer_id, ':qid'=>$question_id]);
  $score = $stmt->fetchColumn();
  if ($score === false) json_fail(400, 'Invalid answer option');
  $computed_score = (int)$score;
  $sel_id = $selected_answer_id;
}
elseif ($qType === 'multiple_choice') {
  if (!is_array($selected_answer_ids)) json_fail(400, 'selected_answer_ids required');

  $ids = [];
  foreach ($selected_answer_ids as $x) {
    $v = (int)$x;
    if ($v > 0) $ids[] = $v;
  }
  $ids = array_values(array_unique($ids));
  if (count($ids) > 100) json_fail(400, 'Too many answer options');

  if (!$ids) {
    $txt = '[]';
    $computed_score = 0;
  } else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id, score FROM question_answers WHERE question_id = ? AND is_active = 1 AND id IN ($placeholders)";
    $params = array_merge([$question_id], $ids);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($ids)) json_fail(400, 'Invalid answer option');

    $sum = 0;
    foreach ($rows as $r) $sum += (int)$r['score'];

    $txt = json_encode($ids, JSON_UNESCAPED_SLASHES);
    $computed_score = $sum;
  }
}
else {
  if ($answer_text === null) json_fail(400, 'answer_text required');
  $txt = trim((string)$answer_text);
  if (strlen($txt) > 20000) json_fail(413, 'answer_text too large');
  $computed_score = 0;
}

// upsert
$stmt = $pdo->prepare("
  INSERT INTO attempt_answers (attempt_id, question_id, selected_answer_id, answer_text, computed_score, answered_at)
  VALUES (:attempt_id, :question_id, :selected_answer_id, :answer_text, :computed_score, NOW())
  ON DUPLICATE KEY UPDATE
    selected_answer_id = VALUES(selected_answer_id),
    answer_text = VALUES(answer_text),
    computed_score = VALUES(computed_score),
    answered_at = VALUES(answered_at),
    updated_at = CURRENT_TIMESTAMP
");
$stmt->execute([
  ':attempt_id' => $attempt_id,
  ':question_id' => $question_id,
  ':selected_answer_id' => $sel_id,
  ':answer_text' => $txt,
  ':computed_score' => $computed_score,
]);

echo json_encode(['ok'=>true, 'timer'=>$timer], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
exit;

} catch (Throwable $e) {
  error_log("[quiz/api/save_answer] FAIL: ".$e->getMessage());
  json_fail(500, 'Server error');
}
