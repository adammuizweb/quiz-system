<?php
// /quiz/api/proctor_event.php
declare(strict_types=1);

require_once __DIR__ . '/../_security.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail(int $code, string $msg) {
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) json_fail(419, 'CSRF token mismatch');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) json_fail(400, 'Bad JSON');

$quiz_id = (int)($in['quiz_id'] ?? 0);
$attemptAlias = trim((string)($in['attempt'] ?? ''));
$event_type = trim((string)($in['event_type'] ?? ''));
$event_meta = $in['event_meta'] ?? null;

if ($quiz_id <= 0) json_fail(400, 'quiz_id invalid');
if ($attemptAlias === '' || !preg_match('/^[a-zA-Z0-9_\-]{6,80}$/', $attemptAlias)) json_fail(400, 'attempt invalid');
if ($event_type === '' || strlen($event_type) > 40) json_fail(400, 'event_type invalid');

// whitelist event_type -> kolom counter
$colMap = [
  'right_click'        => 'proctor_right_clicks',
  'exit_fullscreen'    => 'proctor_exit_fullscreen',
  'tab_hidden'         => 'proctor_tab_hidden',
  'window_blur'        => 'proctor_window_blur',
  'shortcut_blocked'   => 'proctor_shortcut_blocked',
  'copy_blocked'       => 'proctor_copy_blocked',
  'paste_blocked'      => 'proctor_paste_blocked',
  'devtools_flag'      => 'proctor_devtools_flag',
  'printscreen_flag'   => 'proctor_printscreen_flag',
];

if (!isset($colMap[$event_type])) {
  json_fail(400, 'event_type not allowed');
}

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

// attempt
$stmt = $pdo->prepare("
  SELECT *
  FROM quiz_attempts
  WHERE attempt_alias = :a AND student_id = :sid
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
// kalau sudah submitted, tetap boleh log? biasanya tidak perlu
if ((string)$attempt['status'] === 'submitted') json_fail(403, 'Already submitted');

$attempt_id = (int)$attempt['id'];
$col = $colMap[$event_type];

// meta -> JSON string (dibatasi)
$metaStr = null;
if ($event_meta !== null) {
  $metaStr = json_encode($event_meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if ($metaStr !== null && strlen($metaStr) > 5000) {
    $metaStr = substr($metaStr, 0, 5000);
  }
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

try {
  $pdo->beginTransaction();

  $lock = $pdo->prepare('SELECT id FROM quiz_attempts WHERE id = :id LIMIT 1 FOR UPDATE');
  $lock->execute([':id' => $attempt_id]);
  if (!$lock->fetchColumn()) throw new RuntimeException('Attempt no longer exists');
  $recent = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempt_proctor_logs WHERE attempt_id = :id AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
  $recent->execute([':id' => $attempt_id]);
  if ((int)$recent->fetchColumn() >= 120) {
    $pdo->rollBack();
    json_fail(429, 'Too many proctor events');
  }

  // insert log detail
  $ins = $pdo->prepare("
    INSERT INTO quiz_attempt_proctor_logs
      (attempt_id, quiz_id, student_id, event_type, event_meta, ip_address, user_agent_hash)
    VALUES
      (:attempt_id, :quiz_id, :student_id, :event_type, :event_meta, :ip, :ua)
  ");
  $ins->execute([
    ':attempt_id' => $attempt_id,
    ':quiz_id' => $quiz_id,
    ':student_id' => $student_id,
    ':event_type' => $event_type,
    ':event_meta' => $metaStr,
    ':ip' => $ip,
    ':ua' => $user_agent_hash,
  ]);

  // update counter + last event
  // flags: devtools/printscreen -> minimal set 1 (anti spam)
  $isFlag = in_array($event_type, ['devtools_flag','printscreen_flag'], true);

  if ($isFlag) {
    $sql = "UPDATE quiz_attempts
            SET {$col} = GREATEST({$col}, 1),
                proctor_last_event_at = NOW()
            WHERE id = :id LIMIT 1";
    $pdo->prepare($sql)->execute([':id'=>$attempt_id]);
  } else {
    $sql = "UPDATE quiz_attempts
            SET {$col} = {$col} + 1,
                proctor_last_event_at = NOW()
            WHERE id = :id LIMIT 1";
    $pdo->prepare($sql)->execute([':id'=>$attempt_id]);
  }

  $pdo->commit();

  echo json_encode(['ok'=>true], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[quiz/api/proctor_event] error: " . $e->getMessage());
  json_fail(500, 'Server error');
}
