<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$bootstrap = trim((string)getenv('QUIZ_TEST_BOOTSTRAP'));
if ($bootstrap === '' || !is_file($bootstrap)) {
    fwrite(STDERR, "Set QUIZ_TEST_BOOTSTRAP to a trusted local bootstrap that provides a MySQL PDO connection.\n");
    exit(2);
}

require $bootstrap;
if (!isset($pdo) || !$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    fwrite(STDERR, "QUIZ_TEST_BOOTSTRAP must provide a MySQL PDO connection.\n");
    exit(2);
}

$originalDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'quiz_versioning_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
if (preg_match('/^[a-z0-9_]+$/', $testDatabase) !== 1 || $originalDatabase === '') exit(2);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $pdo->exec("CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$testDatabase}`");
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL) ENGINE=InnoDB');
    $schema = file_get_contents($pluginRoot . '/schema.sql');
    if (!is_string($schema) || trim($schema) === '' || $pdo->exec($schema) === false) {
        throw new RuntimeException('Quiz schema could not be installed.');
    }
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Owner')");
    $pdo->exec('DROP TABLE quiz_instrument_revisions');
    $pdo->exec('DROP TABLE quiz_instruments');
    $pdo->exec("INSERT INTO quizzes (id, title, created_by, is_active, questions_per_attempt)
        VALUES (9, 'Legacy Orphan', 999, 0, 1)");
    $pdo->exec("INSERT INTO quizzes (id, title, public_prefix, created_by, is_active, questions_per_attempt)
        VALUES (10, 'Versioned Quiz', 'versioned-quiz', 1, 1, 1)");
    $pdo->exec("INSERT INTO question_categories
        (quiz_id, created_by, name, shuffle_passages, shuffle_questions, is_active)
        VALUES (10, 1, 'Domain', 0, 0, 1)");
    $categoryId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO question_passages
        (quiz_id, created_by, category_id, title, passage_text, shuffle_questions, is_active)
        VALUES (10, 1, {$categoryId}, 'Passage', 'Text', 0, 1)");
    $passageId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO questions
        (quiz_id, created_by, question_text, question_type, is_active, passage_id, category_id)
        VALUES (10, 1, 'Question?', 'single_choice', 1, {$passageId}, {$categoryId})");
    $questionId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO question_answers (question_id, answer_text, score, sort_order, is_active)
        VALUES ({$questionId}, 'Answer', 1, 1, 1)");

    require_once $pluginRoot . '/plugin.php';
    quiz_upgrade_schema($pdo);
    $instrumentId = (int)$pdo->query('SELECT instrument_id FROM quiz_instrument_revisions WHERE quiz_id=10')->fetchColumn();
    $assert($instrumentId > 0, 'Legacy Quiz must be backfilled as Revision 1.');
    $assert($pdo->query('SELECT instrument.created_by FROM quiz_instruments instrument
        JOIN quiz_instrument_revisions revision ON revision.instrument_id=instrument.id
        WHERE revision.quiz_id=9')->fetchColumn() === null,
        'A dangling legacy owner must be normalized to nullable instrument audit metadata.');

    $pdo->exec("INSERT INTO students (id, cookie_id, name) VALUES
        (1, '11111111-1111-4111-8111-111111111111', 'Participant')");
    $pdo->exec("INSERT INTO quiz_attempts
        (attempt_alias, quiz_id, student_id, token_hash, user_agent_hash, status)
        VALUES ('a_historical', 10, 1, 'token', 'agent', 'registered')");
    $historicalAttemptId = (int)$pdo->lastInsertId();

    $pdo->beginTransaction();
    $draft = quiz_create_revision($pdo, 10, 1);
    $pdo->commit();
    $draftQuizId = (int)$draft['quiz_id'];
    $assert((int)$draft['revision_number'] === 2, 'Draft must be Revision 2.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM questions WHERE quiz_id={$draftQuizId}")->fetchColumn() === 1,
        'Draft must clone the Question bank.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id={$draftQuizId}")->fetchColumn() === 0,
        'Draft must not clone Attempts.');

    $pdo->beginTransaction();
    $published = quiz_publish_revision($pdo, $draftQuizId, 1);
    $pdo->commit();
    $assert((int)$published['retired_quiz_id'] === 10, 'Publication must retire the source revision.');
    $assert((int)$pdo->query("SELECT current_quiz_id FROM quiz_instruments WHERE id={$instrumentId}")->fetchColumn() === $draftQuizId,
        'Instrument must point to the published revision.');
    $assert((string)$pdo->query('SELECT state FROM quiz_instrument_revisions WHERE quiz_id=10')->fetchColumn() === 'retired',
        'Source revision must be retired.');
    $assert((string)$pdo->query("SELECT state FROM quiz_instrument_revisions WHERE quiz_id={$draftQuizId}")->fetchColumn() === 'current',
        'Draft must become current.');
    $assert((string)$pdo->query("SELECT public_prefix FROM quizzes WHERE id={$draftQuizId}")->fetchColumn() === 'versioned-quiz',
        'Stable prefix must transfer to the new revision.');
    $assert((int)$pdo->query("SELECT quiz_id FROM quiz_attempts WHERE id={$historicalAttemptId}")->fetchColumn() === 10,
        'Historical Attempt must remain on its original revision.');

    $pdo->beginTransaction();
    $currentRevision = quiz_revision_for_quiz($pdo, $draftQuizId, true);
    $prefixLocked = false;
    try {
        quiz_assert_instrument_prefix_mutable($pdo, $currentRevision ?? [], 'renamed-prefix');
    } catch (RuntimeException) {
        $prefixLocked = true;
    }
    $pdo->rollBack();
    $assert($prefixLocked, 'Stable prefix must lock after any instrument revision receives an Attempt.');

    $pdo->beginTransaction();
    quiz_lock_current_revision_for_attempt($pdo, $draftQuizId);
    $pdo->rollBack();
    $pdo->beginTransaction();
    $historicalRejected = false;
    try {
        quiz_lock_current_revision_for_attempt($pdo, 10);
    } catch (RuntimeException) {
        $historicalRejected = true;
    }
    $pdo->rollBack();
    $assert($historicalRejected, 'Retired revisions must reject new Attempts.');

    echo "Quiz versioning integration passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("USE `{$originalDatabase}`");
    $pdo->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
}
