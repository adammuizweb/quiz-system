<?php
declare(strict_types=1);

function quiz_revision_for_quiz(PDO $pdo, int $quizId, bool $lock = false): ?array {
  if ($quizId <= 0) return null;
  $sql = "SELECT revision.*, instrument.public_prefix AS instrument_public_prefix,
      instrument.current_quiz_id, instrument.created_by AS instrument_created_by
    FROM quiz_instrument_revisions revision
    JOIN quiz_instruments instrument ON instrument.id=revision.instrument_id
    WHERE revision.quiz_id=:quiz_id LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':quiz_id' => $quizId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function quiz_instrument_current_revision(PDO $pdo, int $instrumentId, bool $lock = false): ?array {
  if ($instrumentId <= 0) return null;
  $sql = "SELECT revision.*, instrument.public_prefix AS instrument_public_prefix,
      instrument.current_quiz_id, instrument.created_by AS instrument_created_by
    FROM quiz_instruments instrument
    JOIN quiz_instrument_revisions revision
      ON revision.instrument_id=instrument.id AND revision.quiz_id=instrument.current_quiz_id
    WHERE instrument.id=:instrument_id AND revision.state='current' AND revision.current_marker=1
    LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':instrument_id' => $instrumentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function quiz_instrument_revision_quiz_ids(PDO $pdo, int $instrumentId): array {
  if ($instrumentId <= 0) return [];
  $stmt = $pdo->prepare('SELECT quiz_id FROM quiz_instrument_revisions WHERE instrument_id=:instrument_id ORDER BY revision_number');
  $stmt->execute([':instrument_id' => $instrumentId]);
  return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function quiz_assert_revision_mutable(PDO $pdo, int $quizId): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision mutability validation requires a transaction.');
  $revision = quiz_revision_for_quiz($pdo, $quizId, true);
  if (!$revision) throw new RuntimeException('Quiz revision metadata is unavailable.');
  if ((string)$revision['state'] === 'retired') {
    throw new RuntimeException('Retired Quiz revisions are read-only.');
  }
  if ((string)$revision['state'] === 'current') {
    if ((int)$revision['revision_number'] > 1) {
      throw new RuntimeException('Published Quiz revisions are read-only. Create a new revision.');
    }
    $attempt = $pdo->prepare('SELECT id FROM quiz_attempts WHERE quiz_id=:quiz_id LIMIT 1 FOR UPDATE');
    $attempt->execute([':quiz_id' => $quizId]);
    if ($attempt->fetchColumn() !== false) {
      throw new RuntimeException('Published Quiz revisions with Attempts are read-only. Create a new revision.');
    }
  }
  return $revision;
}

function quiz_lock_current_revision_for_attempt(PDO $pdo, int $quizId): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz attempt revision validation requires a transaction.');
  $revision = quiz_revision_for_quiz($pdo, $quizId, true);
  if (!$revision || (string)$revision['state'] !== 'current'
      || (int)$revision['current_quiz_id'] !== $quizId || (int)$revision['current_marker'] !== 1) {
    throw new RuntimeException('The Quiz revision changed before the Attempt was created.');
  }
  return $revision;
}

function quiz_assert_instrument_prefix_mutable(PDO $pdo, array $revision, ?string $newPrefix): void {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz instrument prefix validation requires a transaction.');
  $currentPrefix = $revision['instrument_public_prefix'] === null
    ? null
    : (string)$revision['instrument_public_prefix'];
  if ($currentPrefix === $newPrefix) return;

  $attempt = $pdo->prepare('SELECT attempt.id FROM quiz_attempts attempt
    JOIN quiz_instrument_revisions family ON family.quiz_id=attempt.quiz_id
    WHERE family.instrument_id=:instrument_id LIMIT 1 FOR UPDATE');
  $attempt->execute([':instrument_id' => (int)$revision['instrument_id']]);
  if ($attempt->fetchColumn() !== false) {
    throw new RuntimeException('The public URL prefix is locked after this Quiz instrument receives an Attempt.');
  }
}

function quiz_create_initial_instrument(PDO $pdo, int $quizId, int $actorId): int {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz instrument creation requires a transaction.');
  if ($quizId <= 0 || $actorId <= 0) throw new InvalidArgumentException('Invalid Quiz instrument identity.');

  $quizStmt = $pdo->prepare('SELECT id, created_by, public_prefix FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
  $quizStmt->execute([':id' => $quizId]);
  $quiz = $quizStmt->fetch(PDO::FETCH_ASSOC);
  if (!$quiz || (int)$quiz['created_by'] !== $actorId) throw new RuntimeException('Quiz instrument source is unavailable.');
  if (quiz_revision_for_quiz($pdo, $quizId, true) !== null) throw new RuntimeException('Quiz already belongs to an instrument.');

  $insertInstrument = $pdo->prepare('INSERT INTO quiz_instruments
    (created_by, public_prefix, current_quiz_id) VALUES (:created_by, :public_prefix, :quiz_id)');
  $insertInstrument->execute([
    ':created_by' => $actorId,
    ':public_prefix' => $quiz['public_prefix'],
    ':quiz_id' => $quizId,
  ]);
  $instrumentId = (int)$pdo->lastInsertId();

  $insertRevision = $pdo->prepare("INSERT INTO quiz_instrument_revisions
    (instrument_id, quiz_id, revision_number, state, current_marker, created_by, published_at)
    VALUES (:instrument_id, :quiz_id, 1, 'current', 1, :created_by, NOW())");
  $insertRevision->execute([
    ':instrument_id' => $instrumentId,
    ':quiz_id' => $quizId,
    ':created_by' => $actorId,
  ]);
  return $instrumentId;
}

function quiz_clone_revision_bank(PDO $pdo, int $sourceQuizId, int $targetQuizId): void {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision cloning requires a transaction.');
  if ($sourceQuizId <= 0 || $targetQuizId <= 0 || $sourceQuizId === $targetQuizId) {
    throw new InvalidArgumentException('Invalid Quiz revision clone scope.');
  }

  $categoryMap = [];
  $categories = $pdo->prepare('SELECT * FROM question_categories WHERE quiz_id=:quiz_id ORDER BY id');
  $categories->execute([':quiz_id' => $sourceQuizId]);
  $insertCategory = $pdo->prepare('INSERT INTO question_categories
    (quiz_id, created_by, name, description, shuffle_passages, shuffle_questions, min_questions,
     max_questions, sort_order, is_active, created_at, updated_at)
    VALUES (:quiz_id, :created_by, :name, :description, :shuffle_passages, :shuffle_questions,
     :min_questions, :max_questions, :sort_order, :is_active, NOW(), NOW())');
  foreach ($categories->fetchAll(PDO::FETCH_ASSOC) ?: [] as $category) {
    $insertCategory->execute([
      ':quiz_id' => $targetQuizId,
      ':created_by' => (int)$category['created_by'],
      ':name' => $category['name'],
      ':description' => $category['description'],
      ':shuffle_passages' => (int)$category['shuffle_passages'],
      ':shuffle_questions' => (int)$category['shuffle_questions'],
      ':min_questions' => $category['min_questions'],
      ':max_questions' => $category['max_questions'],
      ':sort_order' => $category['sort_order'],
      ':is_active' => (int)$category['is_active'],
    ]);
    $categoryMap[(int)$category['id']] = (int)$pdo->lastInsertId();
  }

  $passageMap = [];
  $passages = $pdo->prepare('SELECT * FROM question_passages WHERE quiz_id=:quiz_id ORDER BY id');
  $passages->execute([':quiz_id' => $sourceQuizId]);
  $insertPassage = $pdo->prepare('INSERT INTO question_passages
    (quiz_id, created_by, category_id, title, passage_text, image_media_id, file_id, youtube_url,
     shuffle_questions, min_questions, max_questions, sort_order, is_active, created_at, updated_at)
    VALUES (:quiz_id, :created_by, :category_id, :title, :passage_text, :image_media_id, :file_id,
     :youtube_url, :shuffle_questions, :min_questions, :max_questions, :sort_order, :is_active, NOW(), NOW())');
  foreach ($passages->fetchAll(PDO::FETCH_ASSOC) ?: [] as $passage) {
    $sourceCategoryId = (int)($passage['category_id'] ?? 0);
    $insertPassage->execute([
      ':quiz_id' => $targetQuizId,
      ':created_by' => (int)$passage['created_by'],
      ':category_id' => $sourceCategoryId > 0 ? ($categoryMap[$sourceCategoryId] ?? null) : null,
      ':title' => $passage['title'],
      ':passage_text' => $passage['passage_text'],
      ':image_media_id' => $passage['image_media_id'],
      ':file_id' => $passage['file_id'],
      ':youtube_url' => $passage['youtube_url'],
      ':shuffle_questions' => (int)$passage['shuffle_questions'],
      ':min_questions' => $passage['min_questions'],
      ':max_questions' => $passage['max_questions'],
      ':sort_order' => $passage['sort_order'],
      ':is_active' => (int)$passage['is_active'],
    ]);
    $passageMap[(int)$passage['id']] = (int)$pdo->lastInsertId();
  }

  $questionMap = [];
  $questions = $pdo->prepare('SELECT * FROM questions WHERE quiz_id=:quiz_id ORDER BY id');
  $questions->execute([':quiz_id' => $sourceQuizId]);
  $insertQuestion = $pdo->prepare('INSERT INTO questions
    (quiz_id, created_by, question_text, question_type, sort_order, is_active, image_media_id, file_id,
     youtube_url, passage_id, passage_seq, category_id, created_at, updated_at)
    VALUES (:quiz_id, :created_by, :question_text, :question_type, :sort_order, :is_active,
     :image_media_id, :file_id, :youtube_url, :passage_id, :passage_seq, :category_id, NOW(), NOW())');
  foreach ($questions->fetchAll(PDO::FETCH_ASSOC) ?: [] as $question) {
    $sourcePassageId = (int)($question['passage_id'] ?? 0);
    $sourceCategoryId = (int)($question['category_id'] ?? 0);
    $insertQuestion->execute([
      ':quiz_id' => $targetQuizId,
      ':created_by' => (int)$question['created_by'],
      ':question_text' => $question['question_text'],
      ':question_type' => $question['question_type'],
      ':sort_order' => $question['sort_order'],
      ':is_active' => (int)$question['is_active'],
      ':image_media_id' => $question['image_media_id'],
      ':file_id' => $question['file_id'],
      ':youtube_url' => $question['youtube_url'],
      ':passage_id' => $sourcePassageId > 0 ? ($passageMap[$sourcePassageId] ?? null) : null,
      ':passage_seq' => $question['passage_seq'],
      ':category_id' => $sourceCategoryId > 0 ? ($categoryMap[$sourceCategoryId] ?? null) : null,
    ]);
    $questionMap[(int)$question['id']] = (int)$pdo->lastInsertId();
  }

  $answers = $pdo->prepare('SELECT answer.* FROM question_answers answer
    JOIN questions question ON question.id=answer.question_id
    WHERE question.quiz_id=:quiz_id ORDER BY answer.question_id, answer.sort_order, answer.id');
  $answers->execute([':quiz_id' => $sourceQuizId]);
  $insertAnswer = $pdo->prepare('INSERT INTO question_answers
    (question_id, answer_text, score, sort_order, is_active, created_at, updated_at)
    VALUES (:question_id, :answer_text, :score, :sort_order, :is_active, NOW(), NOW())');
  foreach ($answers->fetchAll(PDO::FETCH_ASSOC) ?: [] as $answer) {
    $targetQuestionId = $questionMap[(int)$answer['question_id']] ?? 0;
    if ($targetQuestionId <= 0) throw new RuntimeException('Quiz revision answer mapping is incomplete.');
    $insertAnswer->execute([
      ':question_id' => $targetQuestionId,
      ':answer_text' => $answer['answer_text'],
      ':score' => (int)$answer['score'],
      ':sort_order' => (int)$answer['sort_order'],
      ':is_active' => (int)$answer['is_active'],
    ]);
  }

  $count = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id=:quiz_id');
  $count->execute([':quiz_id' => $targetQuizId]);
  $pdo->prepare('UPDATE quizzes SET question_pool_size=:question_count WHERE id=:quiz_id')
    ->execute([':question_count' => (int)$count->fetchColumn(), ':quiz_id' => $targetQuizId]);
}

function quiz_lock_next_revision_number(PDO $pdo, int $instrumentId): int {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision creation requires a transaction.');
  $revisionRows = $pdo->prepare('SELECT revision_number, state FROM quiz_instrument_revisions
    WHERE instrument_id=:instrument_id ORDER BY revision_number FOR UPDATE');
  $revisionRows->execute([':instrument_id' => $instrumentId]);
  $rows = $revisionRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $row) {
    if ((string)$row['state'] === 'draft') throw new RuntimeException('This Quiz already has a draft revision.');
  }
  return $rows === [] ? 1 : max(array_map(static fn(array $row): int => (int)$row['revision_number'], $rows)) + 1;
}

function quiz_clone_revision_as_draft(
  PDO $pdo,
  array $sourceQuiz,
  array $sourceRevision,
  int $supersedesQuizId,
  int $collaboratorSourceQuizId,
  int $actorId,
  int $nextRevision
): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision creation requires a transaction.');
  $sourceQuizId = (int)$sourceQuiz['id'];
  $insertQuiz = $pdo->prepare('INSERT INTO quizzes
    (created_by, title, public_prefix, verification_required_override, require_fullscreen_override,
     pause_when_blur_override, block_context_menu_override, block_shortcuts_override,
     devtools_detect_override, show_exam_badge_override, visual_theme_override, description,
     is_active, is_primary, questions_per_attempt, duration_minutes, shuffle_answers,
     timer_warning_seconds, allow_mark_for_review, result_visibility, question_pool_size,
     start_at, end_at, created_at, updated_at)
    VALUES (:created_by, :title, NULL, :verification_required_override, :require_fullscreen_override,
     :pause_when_blur_override, :block_context_menu_override, :block_shortcuts_override,
     :devtools_detect_override, :show_exam_badge_override, :visual_theme_override, :description,
     0, 0, :questions_per_attempt, :duration_minutes, :shuffle_answers,
     :timer_warning_seconds, :allow_mark_for_review, :result_visibility, 0,
     :start_at, :end_at, NOW(), NOW())');
  $insertQuiz->execute([
    ':created_by' => (int)$sourceQuiz['created_by'],
    ':title' => $sourceQuiz['title'],
    ':verification_required_override' => $sourceQuiz['verification_required_override'],
    ':require_fullscreen_override' => $sourceQuiz['require_fullscreen_override'],
    ':pause_when_blur_override' => $sourceQuiz['pause_when_blur_override'],
    ':block_context_menu_override' => $sourceQuiz['block_context_menu_override'],
    ':block_shortcuts_override' => $sourceQuiz['block_shortcuts_override'],
    ':devtools_detect_override' => $sourceQuiz['devtools_detect_override'],
    ':show_exam_badge_override' => $sourceQuiz['show_exam_badge_override'],
    ':visual_theme_override' => $sourceQuiz['visual_theme_override'],
    ':description' => $sourceQuiz['description'],
    ':questions_per_attempt' => (int)$sourceQuiz['questions_per_attempt'],
    ':duration_minutes' => $sourceQuiz['duration_minutes'],
    ':shuffle_answers' => (int)$sourceQuiz['shuffle_answers'],
    ':timer_warning_seconds' => $sourceQuiz['timer_warning_seconds'],
    ':allow_mark_for_review' => (int)$sourceQuiz['allow_mark_for_review'],
    ':result_visibility' => $sourceQuiz['result_visibility'],
    ':start_at' => $sourceQuiz['start_at'],
    ':end_at' => $sourceQuiz['end_at'],
  ]);
  $targetQuizId = (int)$pdo->lastInsertId();

  quiz_clone_revision_bank($pdo, $sourceQuizId, $targetQuizId);
  $pdo->prepare('INSERT INTO quiz_collaborators (quiz_id, user_id, assigned_by)
      SELECT :target_quiz_id, user_id, :actor_id FROM quiz_collaborators WHERE quiz_id=:source_quiz_id')
    ->execute([':target_quiz_id' => $targetQuizId, ':actor_id' => $actorId, ':source_quiz_id' => $collaboratorSourceQuizId]);

  $insertRevision = $pdo->prepare("INSERT INTO quiz_instrument_revisions
    (instrument_id, quiz_id, revision_number, state, current_marker, supersedes_quiz_id, created_by)
    VALUES (:instrument_id, :quiz_id, :revision_number, 'draft', NULL, :supersedes_quiz_id, :created_by)");
  $insertRevision->execute([
    ':instrument_id' => (int)$sourceRevision['instrument_id'],
    ':quiz_id' => $targetQuizId,
    ':revision_number' => $nextRevision,
    ':supersedes_quiz_id' => $supersedesQuizId,
    ':created_by' => $actorId,
  ]);

  return ['quiz_id' => $targetQuizId, 'instrument_id' => (int)$sourceRevision['instrument_id'], 'revision_number' => $nextRevision];
}

function quiz_create_revision(PDO $pdo, int $sourceQuizId, int $actorId, bool $canManageAny = false): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision creation requires a transaction.');
  $source = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
  $source->execute([':id' => $sourceQuizId]);
  $sourceQuiz = $source->fetch(PDO::FETCH_ASSOC);
  if (!$sourceQuiz) throw new RuntimeException('Quiz revision source was not found.');

  $sourceRevision = quiz_revision_for_quiz($pdo, $sourceQuizId, true);
  if (!$sourceRevision || (string)$sourceRevision['state'] !== 'current'
      || (int)$sourceRevision['current_quiz_id'] !== $sourceQuizId) {
    throw new RuntimeException('Only the current Quiz revision can be revised.');
  }
  if ((int)$sourceRevision['instrument_created_by'] !== $actorId && !$canManageAny) {
    throw new RuntimeException('Only the Quiz instrument owner may create a revision.');
  }

  $nextRevision = quiz_lock_next_revision_number($pdo, (int)$sourceRevision['instrument_id']);
  return quiz_clone_revision_as_draft(
    $pdo,
    $sourceQuiz,
    $sourceRevision,
    $sourceQuizId,
    $sourceQuizId,
    $actorId,
    $nextRevision
  );
}

function quiz_restore_revision(PDO $pdo, int $sourceQuizId, int $actorId, bool $canManageAny = false): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision restoration requires a transaction.');
  $source = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
  $source->execute([':id' => $sourceQuizId]);
  $sourceQuiz = $source->fetch(PDO::FETCH_ASSOC);
  if (!$sourceQuiz) throw new RuntimeException('Quiz revision source was not found.');

  $sourceRevision = quiz_revision_for_quiz($pdo, $sourceQuizId, true);
  if (!$sourceRevision || !in_array((string)$sourceRevision['state'], ['retired', 'current'], true)) {
    throw new RuntimeException('Only a published Quiz revision can be restored.');
  }
  if ((int)$sourceRevision['instrument_created_by'] !== $actorId && !$canManageAny) {
    throw new RuntimeException('Only the Quiz instrument owner may restore a revision.');
  }

  $currentRevision = quiz_instrument_current_revision($pdo, (int)$sourceRevision['instrument_id'], true);
  if (!$currentRevision || (int)$currentRevision['instrument_id'] !== (int)$sourceRevision['instrument_id']) {
    throw new RuntimeException('The current Quiz revision is invalid.');
  }

  $nextRevision = quiz_lock_next_revision_number($pdo, (int)$sourceRevision['instrument_id']);
  return quiz_clone_revision_as_draft(
    $pdo,
    $sourceQuiz,
    $sourceRevision,
    (int)$currentRevision['quiz_id'],
    (int)$currentRevision['quiz_id'],
    $actorId,
    $nextRevision
  );
}

function quiz_publish_revision(PDO $pdo, int $targetQuizId, int $actorId, bool $canManageAny = false): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz revision publication requires a transaction.');
  $targetRevision = quiz_revision_for_quiz($pdo, $targetQuizId, true);
  if (!$targetRevision || (string)$targetRevision['state'] !== 'draft') {
    throw new RuntimeException('Only a draft Quiz revision can be published.');
  }
  if ((int)$targetRevision['instrument_created_by'] !== $actorId && !$canManageAny) {
    throw new RuntimeException('Only the Quiz instrument owner may publish a revision.');
  }

  $instrument = $pdo->prepare('SELECT * FROM quiz_instruments WHERE id=:id LIMIT 1 FOR UPDATE');
  $instrument->execute([':id' => (int)$targetRevision['instrument_id']]);
  $instrumentRow = $instrument->fetch(PDO::FETCH_ASSOC);
  if (!$instrumentRow) throw new RuntimeException('Quiz instrument was not found.');
  $sourceQuizId = (int)$instrumentRow['current_quiz_id'];
  if ($sourceQuizId <= 0 || $sourceQuizId !== (int)$targetRevision['supersedes_quiz_id']) {
    throw new RuntimeException('The current Quiz revision changed before publication.');
  }

  $sourceRevision = quiz_revision_for_quiz($pdo, $sourceQuizId, true);
  if (!$sourceRevision || (string)$sourceRevision['state'] !== 'current') {
    throw new RuntimeException('The current Quiz revision is invalid.');
  }
  $sourceQuizStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
  $sourceQuizStmt->execute([':id' => $sourceQuizId]);
  $sourceQuiz = $sourceQuizStmt->fetch(PDO::FETCH_ASSOC);
  $targetQuizStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
  $targetQuizStmt->execute([':id' => $targetQuizId]);
  $targetQuiz = $targetQuizStmt->fetch(PDO::FETCH_ASSOC);
  if (!$sourceQuiz || !$targetQuiz) throw new RuntimeException('Quiz revision rows are unavailable.');

  $activeQuestions = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id=:quiz_id AND is_active=1');
  $activeQuestions->execute([':quiz_id' => $targetQuizId]);
  $activeQuestionCount = (int)$activeQuestions->fetchColumn();
  if ($activeQuestionCount <= 0 || (int)$targetQuiz['questions_per_attempt'] <= 0
      || (int)$targetQuiz['questions_per_attempt'] > $activeQuestionCount) {
    throw new RuntimeException('Draft revision does not have enough active Questions for publication.');
  }

  $prefix = $instrumentRow['public_prefix'];
  $pdo->prepare('UPDATE quizzes SET public_prefix=NULL, is_active=0, is_primary=0 WHERE id=:id')
    ->execute([':id' => $sourceQuizId]);
  $pdo->prepare('UPDATE quizzes SET public_prefix=:public_prefix, is_active=:is_active,
      is_primary=:is_primary WHERE id=:id')
    ->execute([
      ':public_prefix' => $prefix,
      ':is_active' => (int)$sourceQuiz['is_active'],
      ':is_primary' => (int)$sourceQuiz['is_primary'],
      ':id' => $targetQuizId,
    ]);
  $pdo->prepare("UPDATE quiz_instrument_revisions SET state='retired', current_marker=NULL,
      retired_at=NOW() WHERE id=:id AND state='current' AND current_marker=1")
    ->execute([':id' => (int)$sourceRevision['id']]);
  $pdo->prepare("UPDATE quiz_instrument_revisions SET state='current', current_marker=1,
      published_at=NOW(), retired_at=NULL WHERE id=:id AND state='draft' AND current_marker IS NULL")
    ->execute([':id' => (int)$targetRevision['id']]);
  $pdo->prepare('UPDATE quiz_instruments SET current_quiz_id=:quiz_id WHERE id=:instrument_id')
    ->execute([':quiz_id' => $targetQuizId, ':instrument_id' => (int)$targetRevision['instrument_id']]);

  return [
    'instrument_id' => (int)$targetRevision['instrument_id'],
    'quiz_id' => $targetQuizId,
    'revision_number' => (int)$targetRevision['revision_number'],
    'retired_quiz_id' => $sourceQuizId,
  ];
}

function quiz_assert_quiz_deletable(PDO $pdo, int $quizId): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Quiz deletion validation requires a transaction.');
  $revision = quiz_revision_for_quiz($pdo, $quizId, true);
  if (!$revision) throw new RuntimeException('Quiz revision metadata is unavailable.');
  $attempts = $pdo->prepare('SELECT id FROM quiz_attempts WHERE quiz_id=:quiz_id LIMIT 1 FOR UPDATE');
  $attempts->execute([':quiz_id' => $quizId]);
  if ($attempts->fetchColumn() !== false) throw new RuntimeException('Quiz revisions with Attempts cannot be deleted.');
  if ((string)$revision['state'] === 'retired') throw new RuntimeException('Published Quiz revisions are historical records and cannot be deleted.');
  if ((string)$revision['state'] === 'current') {
    $count = $pdo->prepare('SELECT COUNT(*) FROM quiz_instrument_revisions WHERE instrument_id=:instrument_id');
    $count->execute([':instrument_id' => (int)$revision['instrument_id']]);
    if ((int)$count->fetchColumn() !== 1) throw new RuntimeException('The current Quiz revision cannot be deleted.');
  }
  return $revision;
}
