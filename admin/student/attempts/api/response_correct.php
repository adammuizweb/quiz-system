<?php
declare(strict_types=1);

define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempt-responses.correct');
require_once __DIR__ . '/../../../_init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

final class QuizResponseCorrectionException extends RuntimeException {}

function quiz_response_correction_json(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function quiz_response_correction_fail(string $message, int $status): never {
  quiz_response_correction_json(['ok' => false, 'error' => $message], $status);
}

function quiz_response_correction_int(array $input, string $key, bool $allowZero = false): int {
  $value = $input[$key] ?? null;
  if (is_int($value)) {
    $number = $value;
  } elseif (is_string($value) && ctype_digit($value)) {
    $number = (int)$value;
  } else {
    throw new QuizResponseCorrectionException('Invalid request.', 400);
  }
  if ($number < ($allowZero ? 0 : 1)) throw new QuizResponseCorrectionException('Invalid request.', 400);
  return $number;
}

function quiz_response_correction_answered(mixed $selectedAnswerId, mixed $answerText): bool {
  $text = trim((string)($answerText ?? ''));
  return $selectedAnswerId !== null || ($text !== '' && $text !== '[]');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') quiz_response_correction_fail('Method not allowed.', 405);
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') quiz_response_correction_fail('JSON request required.', 415);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) quiz_response_correction_fail('Request too large.', 413);

try {
  $raw = file_get_contents('php://input');
  if (!is_string($raw) || strlen($raw) > 65536) throw new QuizResponseCorrectionException('Request too large.', 413);
  try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
  } catch (JsonException) {
    throw new QuizResponseCorrectionException('Invalid JSON.', 400);
  }
  if (!is_array($input)) throw new QuizResponseCorrectionException('Invalid request.', 400);

  $csrf = $input['csrf_token'] ?? null;
  if (!is_string($csrf) || !hash_equals(csrf_token(), $csrf)) {
    throw new QuizResponseCorrectionException('CSRF token mismatch.', 419);
  }

  $attemptId = quiz_response_correction_int($input, 'attempt_id');
  $questionId = quiz_response_correction_int($input, 'question_id');
  $expectedAnswerId = quiz_response_correction_int($input, 'attempt_answer_id', true);
  $expectedRevisionCount = quiz_response_correction_int($input, 'expected_revision_count', true);
  if (!is_string($input['reason'] ?? null)) throw new QuizResponseCorrectionException('Invalid correction reason.', 400);
  $reason = trim($input['reason']);
  if (preg_match('//u', $reason) !== 1) throw new QuizResponseCorrectionException('Invalid correction reason.', 400);
  $reasonLength = mb_strlen($reason, 'UTF-8');
  if ($reasonLength < 5) throw new QuizResponseCorrectionException('Correction reason must be at least 5 characters.', 400);
  if ($reasonLength > 500) throw new QuizResponseCorrectionException('Correction reason is too large.', 413);

  $actor = qz_admin_user($pdo);
  $actorId = (int)($actor['id'] ?? 0);
  if ($actorId <= 0 || empty($actor['can_view_attempt_responses']) || empty($actor['can_correct_attempt_responses'])) {
    throw new QuizResponseCorrectionException('Correction is not available.', 409);
  }

  $pdo->beginTransaction();
  try {
    try {
      $attempt = qz_lock_attempt_for_mutation(
        $pdo,
        $attemptId,
        $actorId,
        'plugin.quiz.attempt-responses.correct'
      );
    } catch (RuntimeException) {
      throw new QuizResponseCorrectionException('Attempt cannot be corrected.', 409);
    }
    $quizId = (int)($attempt['quiz_id'] ?? 0);
    if ((string)($attempt['status'] ?? '') !== 'submitted' || empty($attempt['submitted_at']) || $quizId <= 0) {
      throw new QuizResponseCorrectionException('Only submitted attempts can be corrected.', 409);
    }
    if (!quiz_attempt_package_is_complete($pdo, $attemptId)) {
      throw new QuizResponseCorrectionException('Attempt package is incomplete.', 409);
    }
    if (!quiz_attempt_snapshots_predate_submission($pdo, $attemptId, (string)$attempt['submitted_at'])) {
      throw new QuizResponseCorrectionException('Attempt snapshots are not eligible for correction.', 409);
    }

    $questionStmt = $pdo->prepare('SELECT id, question_id, delivery_snapshot_json, options_snapshotted_at FROM attempt_questions
      WHERE attempt_id=:attempt_id AND question_id=:question_id LIMIT 1 FOR UPDATE');
    $questionStmt->execute([':attempt_id' => $attemptId, ':question_id' => $questionId]);
    $attemptQuestion = $questionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$attemptQuestion) throw new QuizResponseCorrectionException('Assigned question was not found.', 409);
    $attemptQuestionId = (int)$attemptQuestion['id'];

    $delivery = json_decode((string)($attemptQuestion['delivery_snapshot_json'] ?? ''), true);
    if (!is_array($delivery) || (int)($delivery['version'] ?? 0) !== 1
        || !is_array($delivery['question'] ?? null)) {
      throw new QuizResponseCorrectionException('Question snapshot is invalid.', 409);
    }
    $questionType = (string)($delivery['question']['type'] ?? '');
    if (!in_array($questionType, ['single_choice', 'multiple_choice', 'yesno', 'text', 'number'], true)) {
      throw new QuizResponseCorrectionException('Question snapshot is invalid.', 409);
    }

    if (empty($attemptQuestion['options_snapshotted_at'])
        || (string)$attemptQuestion['options_snapshotted_at'] >= (string)$attempt['submitted_at']) {
      throw new QuizResponseCorrectionException('Question snapshot is not eligible for correction.', 409);
    }

    try {
      $normalized = quiz_normalize_attempt_response(
        $pdo,
        $attemptQuestionId,
        $questionId,
        $questionType,
        $input
      );
    } catch (InvalidArgumentException $error) {
      throw new QuizResponseCorrectionException(
        $error->getCode() === 413 ? 'Corrected response is too large.' : 'Corrected response is invalid.',
        $error->getCode() === 413 ? 413 : 400
      );
    }

    $answerStmt = $pdo->prepare('SELECT * FROM attempt_answers
      WHERE attempt_id=:attempt_id AND question_id=:question_id LIMIT 1 FOR UPDATE');
    $answerStmt->execute([':attempt_id' => $attemptId, ':question_id' => $questionId]);
    $existing = $answerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $currentAnswerId = $existing === null ? 0 : (int)$existing['id'];
    $currentRevisionCount = $existing === null ? 0 : (int)($existing['revision_count'] ?? 0);
    if ($currentAnswerId !== $expectedAnswerId || $currentRevisionCount !== $expectedRevisionCount) {
      throw new QuizResponseCorrectionException('Response changed. Refresh and try again.', 409);
    }

    if ($existing !== null) {
      $revisionLock = $pdo->prepare('SELECT attempt_id, question_id, revision_number
        FROM attempt_answer_revisions WHERE attempt_answer_id=:answer_id
        ORDER BY revision_number ASC FOR UPDATE');
      $revisionLock->execute([':answer_id' => $currentAnswerId]);
      $revisionRows = $revisionLock->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if (count($revisionRows) !== $currentRevisionCount) {
        throw new QuizResponseCorrectionException('Response history is inconsistent.', 409);
      }
      foreach ($revisionRows as $index => $revisionRow) {
        if ((int)$revisionRow['attempt_id'] !== $attemptId || (int)$revisionRow['question_id'] !== $questionId
            || (int)$revisionRow['revision_number'] !== $index) {
          throw new QuizResponseCorrectionException('Response history is inconsistent.', 409);
        }
      }
    }

    $optionsById = [];
    foreach ($normalized['options'] as $option) $optionsById[(int)$option['id']] = (string)$option['label'];
    $answerLabel = static function (mixed $selectedAnswerId, mixed $answerText) use ($questionType, $optionsById): string {
      if ($questionType === 'multiple_choice') {
        $ids = json_decode((string)($answerText ?? ''), true);
        if (!is_array($ids) || $ids === []) return '—';
        $labels = [];
        foreach ($ids as $id) {
          $sourceId = (int)$id;
          if ($sourceId > 0) $labels[] = $optionsById[$sourceId] ?? ('Option #' . $sourceId);
        }
        return $labels === [] ? '—' : implode('; ', $labels);
      }
      if (in_array($questionType, ['single_choice', 'yesno'], true)) {
        $sourceId = $selectedAnswerId === null ? 0 : (int)$selectedAnswerId;
        if ($sourceId <= 0) {
          $storedSourceId = trim((string)($answerText ?? ''));
          if (ctype_digit($storedSourceId)) $sourceId = (int)$storedSourceId;
        }
        return $sourceId > 0 ? ($optionsById[$sourceId] ?? ('Option #' . $sourceId)) : '—';
      }
      $text = trim((string)($answerText ?? ''));
      return $text === '' ? '—' : (string)$answerText;
    };

    $normalizedAnswered = quiz_response_correction_answered(
      $normalized['selected_answer_id'],
      $normalized['answer_text']
    );
    $same = $existing === null
      ? !$normalizedAnswered && (int)$normalized['computed_score'] === 0
      : ($existing['selected_answer_id'] === null ? null : (int)$existing['selected_answer_id']) === $normalized['selected_answer_id']
        && ($existing['answer_text'] === null ? null : (string)$existing['answer_text']) === $normalized['answer_text']
        && (int)$existing['computed_score'] === (int)$normalized['computed_score'];
    if ($same) {
      $answeredStmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_answers
        WHERE attempt_id=:attempt_id AND (selected_answer_id IS NOT NULL
          OR (TRIM(COALESCE(answer_text,''))<>'' AND TRIM(COALESCE(answer_text,''))<>'[]'))");
      $answeredStmt->execute([':attempt_id' => $attemptId]);
      $answeredCount = (int)$answeredStmt->fetchColumn();
      $pdo->commit();
      quiz_response_correction_json(['ok' => true, 'data' => [
        'changed' => false,
        'attempt_answer_id' => $currentAnswerId,
        'revision_count' => $currentRevisionCount,
        'answer_label' => $answerLabel(
          $existing['selected_answer_id'] ?? $normalized['selected_answer_id'],
          $existing['answer_text'] ?? $normalized['answer_text']
        ),
        'computed_score' => $existing === null ? 0 : (int)$existing['computed_score'],
        'total_score' => (int)$attempt['total_score'],
        'answered_count' => $answeredCount,
        'history' => null,
      ]]);
    }

    $beforeSelectedAnswerId = $existing['selected_answer_id'] ?? null;
    $beforeAnswerText = $existing['answer_text'] ?? null;
    $beforeScore = $existing === null ? 0 : (int)$existing['computed_score'];
    $beforeAnswered = $existing !== null
      && quiz_response_correction_answered($existing['selected_answer_id'], $existing['answer_text']);

    if ($existing !== null) {
      $revisionNumber = $currentRevisionCount;
      $revisionStmt = $pdo->prepare("INSERT INTO attempt_answer_revisions
        (attempt_answer_id, attempt_id, question_id, revision_number, selected_answer_id, answer_text,
         computed_score, change_source, changed_by_user_id, change_reason, answered_at)
        VALUES (:answer_id, :attempt_id, :question_id, :revision_number, :selected_answer_id, :answer_text,
          :computed_score, 'admin', :actor_id, :reason, :answered_at)");
      $revisionStmt->execute([
        ':answer_id' => $currentAnswerId,
        ':attempt_id' => $attemptId,
        ':question_id' => $questionId,
        ':revision_number' => $revisionNumber,
        ':selected_answer_id' => $existing['selected_answer_id'] === null ? null : (int)$existing['selected_answer_id'],
        ':answer_text' => $existing['answer_text'] === null ? null : (string)$existing['answer_text'],
        ':computed_score' => $beforeScore,
        ':actor_id' => $actorId,
        ':reason' => $reason,
        ':answered_at' => $existing['answered_at'] === null ? null : (string)$existing['answered_at'],
      ]);
      $newRevisionCount = $currentRevisionCount + 1;
      $updateStmt = $pdo->prepare('UPDATE attempt_answers
        SET selected_answer_id=:selected_answer_id, answer_text=:answer_text,
          computed_score=:computed_score, revision_count=:revision_count
        WHERE id=:answer_id LIMIT 1');
      $updateStmt->execute([
        ':selected_answer_id' => $normalized['selected_answer_id'],
        ':answer_text' => $normalized['answer_text'],
        ':computed_score' => (int)$normalized['computed_score'],
        ':revision_count' => $newRevisionCount,
        ':answer_id' => $currentAnswerId,
      ]);
    } else {
      $insertStmt = $pdo->prepare('INSERT INTO attempt_answers
        (attempt_id, question_id, selected_answer_id, answer_text, computed_score, revision_count, answered_at)
        VALUES (:attempt_id, :question_id, :selected_answer_id, :answer_text, :computed_score, 1, NULL)');
      $insertStmt->execute([
        ':attempt_id' => $attemptId,
        ':question_id' => $questionId,
        ':selected_answer_id' => $normalized['selected_answer_id'],
        ':answer_text' => $normalized['answer_text'],
        ':computed_score' => (int)$normalized['computed_score'],
      ]);
      $currentAnswerId = (int)$pdo->lastInsertId();
      if ($currentAnswerId <= 0) throw new RuntimeException('Corrected response was not created.');
      $revisionNumber = 0;
      $newRevisionCount = 1;
      $revisionStmt = $pdo->prepare("INSERT INTO attempt_answer_revisions
        (attempt_answer_id, attempt_id, question_id, revision_number, selected_answer_id, answer_text,
         computed_score, change_source, changed_by_user_id, change_reason, answered_at)
        VALUES (:answer_id, :attempt_id, :question_id, 0, NULL, NULL, 0, 'admin', :actor_id, :reason, NULL)");
      $revisionStmt->execute([
        ':answer_id' => $currentAnswerId,
        ':attempt_id' => $attemptId,
        ':question_id' => $questionId,
        ':actor_id' => $actorId,
        ':reason' => $reason,
      ]);
    }

    $aggregates = quiz_rebuild_attempt_aggregates($pdo, $attemptId, $quizId);
    $afterAnswered = $normalizedAnswered;
    $auditMetadata = [
      'attempt_id' => $attemptId,
      'quiz_id' => $quizId,
      'question_id' => $questionId,
      'attempt_answer_id' => $currentAnswerId,
      'reason' => $reason,
      'before_score' => $beforeScore,
      'after_score' => (int)$normalized['computed_score'],
      'before_answered' => $beforeAnswered,
      'after_answered' => $afterAnswered,
      'before_revision_count' => $currentRevisionCount,
      'after_revision_count' => $newRevisionCount,
    ];
    if (!function_exists('authorization_audit') || !authorization_audit(
      $pdo,
      'quiz.attempt_response.corrected',
      $actorId,
      null,
      'quiz_attempt_response',
      (string)$currentAnswerId,
      $auditMetadata
    )) {
      throw new RuntimeException('Correction audit failed.');
    }

    $historyStmt = $pdo->prepare('SELECT r.revision_number, r.selected_answer_id, r.answer_text,
        r.computed_score, r.created_at, r.change_source, r.changed_by_user_id, r.change_reason,
        u.name AS changed_by_name, u.email AS changed_by_email
      FROM attempt_answer_revisions r LEFT JOIN users u ON u.id=r.changed_by_user_id
      WHERE r.attempt_answer_id=:answer_id AND r.revision_number=:revision_number LIMIT 1');
    $historyStmt->execute([':answer_id' => $currentAnswerId, ':revision_number' => $revisionNumber]);
    $historyRow = $historyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$historyRow) throw new RuntimeException('Correction history was not created.');
    $changedByName = trim((string)($historyRow['changed_by_name'] ?? ''));
    $changedByEmail = trim((string)($historyRow['changed_by_email'] ?? ''));
    $changedByLabel = $changedByName !== ''
      ? $changedByName . ($changedByEmail !== '' ? ' (' . $changedByEmail . ')' : '')
      : ($changedByEmail !== '' ? $changedByEmail : 'User #' . $actorId);
    $history = [
      'revision_number' => (int)$historyRow['revision_number'],
      'answer_label' => $answerLabel($beforeSelectedAnswerId, $beforeAnswerText),
      'computed_score' => (int)$historyRow['computed_score'],
      'created_at' => (string)$historyRow['created_at'],
      'change_source' => (string)$historyRow['change_source'],
      'changed_by_label' => $changedByLabel,
      'change_reason' => (string)$historyRow['change_reason'],
    ];

    $response = ['ok' => true, 'data' => [
      'changed' => true,
      'attempt_answer_id' => $currentAnswerId,
      'revision_count' => $newRevisionCount,
      'answer_label' => $answerLabel($normalized['selected_answer_id'], $normalized['answer_text']),
      'computed_score' => (int)$normalized['computed_score'],
      'total_score' => (int)$aggregates['total_score'],
      'answered_count' => (int)$aggregates['answered_count'],
      'history' => $history,
    ]];
    $pdo->commit();
    quiz_response_correction_json($response);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
  }
} catch (QuizResponseCorrectionException $error) {
  quiz_response_correction_fail($error->getMessage(), (int)$error->getCode());
} catch (Throwable $error) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[quiz/admin/response_correct] ' . get_class($error) . ': ' . $error->getMessage());
  quiz_response_correction_fail('The response correction could not be saved.', 500);
}
