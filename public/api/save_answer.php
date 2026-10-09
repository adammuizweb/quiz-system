<?php
declare(strict_types=1);

if (ob_get_level() === 0) { ob_start(); }
require_once __DIR__ . '/_timer_helper.php';
require_once __DIR__ . '/../_security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_fail(int $code, string $msg, ?array $data = null): never {
  http_response_code($code);
  if (ob_get_length() !== false && ob_get_length() > 0) @ob_clean();
  echo json_encode(['ok' => false, 'error' => $msg, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) json_fail(419, 'CSRF token mismatch');

try {
  $input = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($input)) json_fail(400, 'Bad JSON');
  $quizId = (int)($input['quiz_id'] ?? 0);
  $questionId = (int)($input['question_id'] ?? 0);
  $alias = trim((string)($input['attempt'] ?? ''));
  if ($quizId <= 0 || $questionId <= 0 || preg_match('/^[a-zA-Z0-9_-]{6,80}$/', $alias) !== 1) json_fail(400, 'Invalid params');
  if (!quiz_public_route_accepts_quiz($quizId)) json_fail(404, 'Not found');

  $cookieId = trim((string)($_COOKIE['quiz_cid'] ?? ''));
  $token = (string)($_COOKIE['quiz_token_' . $quizId] ?? '');
  if (preg_match('/^[a-f0-9-]{36}$/i', $cookieId) !== 1 || $token === '') json_fail(401, 'Not registered');
  $studentStmt = $pdo->prepare('SELECT id FROM students WHERE cookie_id=:cookie_id LIMIT 1');
  $studentStmt->execute([':cookie_id' => $cookieId]);
  $studentId = (int)$studentStmt->fetchColumn();
  if ($studentId <= 0) json_fail(401, 'Student not found');

  $pdo->beginTransaction();
  $attemptStmt = $pdo->prepare('SELECT a.*, q.duration_minutes, q.timer_warning_seconds
    FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id
    WHERE a.attempt_alias=:alias AND a.student_id=:student_id LIMIT 1 FOR UPDATE');
  $attemptStmt->execute([':alias' => $alias, ':student_id' => $studentId]);
  $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt || (int)$attempt['quiz_id'] !== $quizId) { $pdo->rollBack(); json_fail(403, 'Attempt not found'); }
  if (!hash_equals((string)$attempt['token_hash'], hash('sha256', $token))
      || !hash_equals((string)$attempt['user_agent_hash'], hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
    $pdo->rollBack(); json_fail(403, 'Access not valid');
  }
  if (empty($attempt['email_verified_at']) || (string)$attempt['status'] === 'registered') { $pdo->rollBack(); json_fail(403, 'Not verified'); }
  if ((string)$attempt['status'] === 'submitted') { $pdo->rollBack(); json_fail(403, 'Already submitted'); }
  if (!quiz_attempt_package_is_complete($pdo, (int)$attempt['id'])) {
    $pdo->rollBack(); json_fail(409, 'Quiz preparation required.', ['preparation_required' => true]);
  }

  $questionStmt = $pdo->prepare('SELECT id, delivery_snapshot_json FROM attempt_questions
    WHERE attempt_id=:attempt_id AND question_id=:question_id LIMIT 1 FOR UPDATE');
  $questionStmt->execute([':attempt_id' => (int)$attempt['id'], ':question_id' => $questionId]);
  $attemptQuestion = $questionStmt->fetch(PDO::FETCH_ASSOC);
  $attemptQuestionId = (int)($attemptQuestion['id'] ?? 0);
  if ($attemptQuestionId <= 0) { $pdo->rollBack(); json_fail(403, 'Question not in this attempt'); }

  $delivery = json_decode((string)($attemptQuestion['delivery_snapshot_json'] ?? ''), true);
  $questionType = is_array($delivery) && (int)($delivery['version'] ?? 0) === 1
    ? (string)($delivery['question']['type'] ?? '')
    : '';
  if ($questionType === '') {
    $legacyType = $pdo->prepare('SELECT question_type FROM questions WHERE id=:question_id AND quiz_id=:quiz_id LIMIT 1 FOR UPDATE');
    $legacyType->execute([':question_id' => $questionId, ':quiz_id' => $quizId]);
    $questionType = (string)($legacyType->fetchColumn() ?: '');
  }
  if ($questionType === '') { $pdo->rollBack(); json_fail(403, 'Question not found'); }

  $timer = qz_timer_enforce($pdo, (int)$attempt['id'], (int)($attempt['duration_minutes'] ?? 0), true, true, $attempt['timer_warning_seconds'] ?? null);
  if (!empty($timer['time_up'])) {
    $pdo->commit();
    if (!empty($timer['finalized'])) quiz_extension_emit('attempt.submitted', [
      'attempt_id' => (int)$attempt['id'], 'quiz_id' => $quizId, 'reason' => 'timer_expired', 'status' => 'submitted'
    ]);
    json_fail(403, 'Waktu ujian sudah habis.', ['time_up' => true, 'timer' => $timer]);
  }

  $selectedAnswerId = null;
  $answerText = null;
  $computedScore = 0;
  if (in_array($questionType, ['single_choice', 'yesno'], true)) {
    $selectedSourceId = (int)($input['selected_answer_id'] ?? 0);
    if ($selectedSourceId <= 0) { $pdo->rollBack(); json_fail(400, 'No answer selected'); }
    $option = $pdo->prepare('SELECT score FROM attempt_question_options
      WHERE attempt_question_id=:attempt_question_id AND source_answer_id=:source_answer_id LIMIT 1 FOR UPDATE');
    $option->execute([':attempt_question_id' => $attemptQuestionId, ':source_answer_id' => $selectedSourceId]);
    $score = $option->fetchColumn();
    if ($score === false) { $pdo->rollBack(); json_fail(400, 'Invalid answer option'); }
    $computedScore = (int)$score;
    $liveOption = $pdo->prepare('SELECT id FROM question_answers WHERE id=:id AND question_id=:question_id LIMIT 1 FOR UPDATE');
    $liveOption->execute([':id' => $selectedSourceId, ':question_id' => $questionId]);
    if ($liveOption->fetchColumn() !== false) {
      $selectedAnswerId = $selectedSourceId;
    } else {
      // Keep the snapshotted source ID without violating the retained legacy FK.
      $answerText = (string)$selectedSourceId;
    }
  } elseif ($questionType === 'multiple_choice') {
    $rawIds = $input['selected_answer_ids'] ?? null;
    if (!is_array($rawIds)) { $pdo->rollBack(); json_fail(400, 'selected_answer_ids required'); }
    $ids = [];
    foreach ($rawIds as $rawId) {
      $id = (int)$rawId;
      if ($id > 0) $ids[$id] = $id;
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    if (count($ids) > 100) { $pdo->rollBack(); json_fail(400, 'Too many answer options'); }
    if ($ids !== []) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $options = $pdo->prepare("SELECT source_answer_id AS id, score FROM attempt_question_options
        WHERE attempt_question_id=? AND source_answer_id IN ({$placeholders}) ORDER BY source_answer_id ASC FOR UPDATE");
      $options->execute(array_merge([$attemptQuestionId], $ids));
      $rows = $options->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if (count($rows) !== count($ids)) { $pdo->rollBack(); json_fail(400, 'Invalid answer option'); }
      foreach ($rows as $row) $computedScore += (int)$row['score'];
    }
    $answerText = json_encode($ids, JSON_UNESCAPED_SLASHES);
  } else {
    if (!array_key_exists('answer_text', $input)) { $pdo->rollBack(); json_fail(400, 'answer_text required'); }
    $answerText = trim((string)$input['answer_text']);
    if (strlen($answerText) > 20000) { $pdo->rollBack(); json_fail(413, 'answer_text too large'); }
  }

  $existingStmt = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id=:attempt_id AND question_id=:question_id LIMIT 1 FOR UPDATE');
  $existingStmt->execute([':attempt_id' => (int)$attempt['id'], ':question_id' => $questionId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
  $changed = false;
  $revisionCount = 0;
  if (!$existing) {
    $insert = $pdo->prepare('INSERT INTO attempt_answers
      (attempt_id, question_id, selected_answer_id, answer_text, computed_score, revision_count, answered_at)
      VALUES (:attempt_id, :question_id, :selected_answer_id, :answer_text, :computed_score, 0, NOW())');
    $insert->execute([':attempt_id' => (int)$attempt['id'], ':question_id' => $questionId,
      ':selected_answer_id' => $selectedAnswerId, ':answer_text' => $answerText, ':computed_score' => $computedScore]);
    $changed = true;
  } else {
    $same = ($existing['selected_answer_id'] === null ? null : (int)$existing['selected_answer_id']) === $selectedAnswerId
      && ($existing['answer_text'] === null ? null : (string)$existing['answer_text']) === $answerText
      && (int)$existing['computed_score'] === $computedScore;
    $revisionCount = (int)($existing['revision_count'] ?? 0);
    if (!$same) {
      $revision = $pdo->prepare('INSERT INTO attempt_answer_revisions
        (attempt_answer_id, attempt_id, question_id, revision_number, selected_answer_id, answer_text, computed_score)
        VALUES (:answer_id, :attempt_id, :question_id, :revision, :selected_answer_id, :answer_text, :computed_score)');
      $revision->execute([':answer_id' => (int)$existing['id'], ':attempt_id' => (int)$attempt['id'], ':question_id' => $questionId,
        ':revision' => $revisionCount,
        ':selected_answer_id' => $existing['selected_answer_id'] === null ? null : (int)$existing['selected_answer_id'],
        ':answer_text' => $existing['answer_text'] === null ? null : (string)$existing['answer_text'],
        ':computed_score' => (int)$existing['computed_score']]);
      $revisionCount++;
      $update = $pdo->prepare('UPDATE attempt_answers SET selected_answer_id=:selected_answer_id, answer_text=:answer_text,
        computed_score=:computed_score, revision_count=:revision_count, answered_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE id=:id LIMIT 1');
      $update->execute([':selected_answer_id' => $selectedAnswerId, ':answer_text' => $answerText, ':computed_score' => $computedScore,
        ':revision_count' => $revisionCount, ':id' => (int)$existing['id']]);
      $changed = true;
    }
  }

  $pdo->commit();
  if ($changed) quiz_extension_emit('answer.saved', [
    'attempt_id' => (int)$attempt['id'], 'quiz_id' => $quizId, 'question_id' => $questionId,
    'question_type' => $questionType, 'revision_count' => $revisionCount
  ]);
  echo json_encode(['ok' => true, 'timer' => $timer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz/api/save_answer] ' . $error->getMessage());
  json_fail(500, 'Server error');
}
