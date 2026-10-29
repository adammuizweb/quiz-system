<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.workspace.access');
require __DIR__ . '/../_init.php';
require_once __DIR__ . '/_export.php';
require_once __DIR__ . '/../student/attempts/_export.php';
qz_question_export_no_store();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
quiz_csrf_check();

$quizIdInput = trim((string)($_POST['quiz_id'] ?? ''));
if (preg_match('/^[1-9][0-9]*$/D', $quizIdInput) !== 1) {
    http_response_code(422);
    exit('Invalid quiz ID.');
}
$quizId = (int)$quizIdInput;
$quiz = qz_require_quiz_access($pdo, $quizId);

$categoryInput = trim((string)($_POST['category_id'] ?? ''));
$categoryId = null;
$category = null;
if ($categoryInput !== '') {
    if (preg_match('/^[1-9][0-9]*$/D', $categoryInput) !== 1) {
        http_response_code(422);
        exit('Invalid category ID.');
    }
    $categoryId = (int)$categoryInput;
    $category = qz_require_category_access($pdo, $quizId, $categoryId);
}

$xlsxSupport = qz_attempt_xlsx_support();
if (!$xlsxSupport['available']) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Excel export is unavailable. ' . $xlsxSupport['message']);
}
require_once $xlsxSupport['autoload'];
if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Excel export is unavailable because PhpSpreadsheet could not be loaded.');
}

try {
    $params = [':quiz_id' => $quizId];
    if ($categoryId !== null) $params[':category_id'] = $categoryId;

    $pdo->beginTransaction();
    $questionStatement = $pdo->prepare(qz_question_export_questions_sql($categoryId !== null));
    $questionStatement->execute($params);
    $questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $answerStatement = $pdo->prepare(qz_question_export_answers_sql($categoryId !== null));
    $answerStatement->execute($params);
    $answers = $answerStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pdo->commit();

    $spreadsheet = qz_question_export_workbook($questions, $answers, $quiz, $category);
    $exportStream = tmpfile();
    if ($exportStream === false) throw new RuntimeException('Could not allocate the export stream.');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save($exportStream);
    $size = fstat($exportStream)['size'] ?? null;
    rewind($exportStream);
    $spreadsheet->disconnectWorksheets();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($exportStream) && is_resource($exportStream)) fclose($exportStream);
    error_log('[admin/quiz/dump/export] ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Could not create the question bank export.');
}

while (ob_get_level() > 0) @ob_end_clean();
$scope = $categoryId !== null ? '-category-' . $categoryId : '-all-categories';
$filename = 'quiz-' . $quizId . '-question-bank' . $scope . '-' . date('Ymd-His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
if (is_int($size) && $size >= 0) header('Content-Length: ' . $size);
qz_question_export_no_store();

fpassthru($exportStream);
fclose($exportStream);
exit;
