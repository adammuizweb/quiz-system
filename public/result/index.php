<?php
declare(strict_types=1);

require_once __DIR__ . '/../_security.php';
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
}

if (!function_exists('h')) {
  function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$alias = trim((string)($_GET['a'] ?? ''));
$cookieId = trim((string)($_COOKIE['quiz_cid'] ?? ''));
if (preg_match('/^[a-zA-Z0-9_-]{6,80}$/', $alias) !== 1 || preg_match('/^[a-f0-9-]{36}$/i', $cookieId) !== 1) {
  http_response_code(404); exit('Result not found');
}
$studentStmt = $pdo->prepare('SELECT id FROM students WHERE cookie_id=:cookie_id LIMIT 1');
$studentStmt->execute([':cookie_id' => $cookieId]);
$studentId = (int)$studentStmt->fetchColumn();
if ($studentId <= 0) { http_response_code(404); exit('Result not found'); }

$stmt = $pdo->prepare('SELECT a.id, a.quiz_id, a.status, a.token_hash, a.user_agent_hash, a.total_score,
    a.elapsed_seconds, a.submitted_at, q.title, q.result_visibility
  FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id
  WHERE a.attempt_alias=:alias AND a.student_id=:student_id LIMIT 1');
$stmt->execute([':alias' => $alias, ':student_id' => $studentId]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) { http_response_code(404); exit('Result not found'); }
$token = (string)($_COOKIE['quiz_token_' . (int)$attempt['quiz_id']] ?? '');
if ($token === '' || !hash_equals((string)$attempt['token_hash'], hash('sha256', $token))
    || !hash_equals((string)$attempt['user_agent_hash'], hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
  http_response_code(404); exit('Result not found');
}
$visibility = quiz_safe_result_visibility($attempt['result_visibility'] ?? 'hidden');
if ((string)$attempt['status'] !== 'submitted' || $visibility === 'hidden') {
  http_response_code(404); exit('Result not found');
}
if (!quiz_attempt_result_is_ready($pdo, (int)$attempt['id'])) {
  $page_title = 'Quiz Result';
  $content_html = '<section class="st"><div class="st-wrap"><div class="st-hero"><div class="st-badge">RESULT</div>'
    . '<h1 class="st-h1">Result Unavailable</h1><div class="st-desc">A complete verified result is not available for this submission.</div></div>'
    . '<div class="st-box"><a class="st-btn primary" href="/quiz/start/">Back to Quiz List</a></div></div></section>';
  require __DIR__ . '/../layout.php';
  return;
}

$scoreStmt = $pdo->prepare('SELECT category_name, question_count, answered_count, correct_count,
  earned_score, available_score, percent FROM attempt_category_scores WHERE attempt_id=:attempt_id ORDER BY id ASC');
$scoreStmt->execute([':attempt_id' => (int)$attempt['id']]);
$categories = $scoreStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalQuestions = array_sum(array_map(static fn(array $row): int => (int)$row['question_count'], $categories));
$answered = array_sum(array_map(static fn(array $row): int => (int)$row['answered_count'], $categories));
$available = array_sum(array_map(static fn(array $row): int => (int)$row['available_score'], $categories));
if ($totalQuestions === 0) {
  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM attempt_questions WHERE attempt_id=:attempt_id');
  $countStmt->execute([':attempt_id' => (int)$attempt['id']]);
  $totalQuestions = (int)$countStmt->fetchColumn();
  $answeredCondition = "(aa.selected_answer_id IS NOT NULL OR (aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'' AND TRIM(aa.answer_text)<>'[]'))";
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=:attempt_id AND {$answeredCondition}");
  $countStmt->execute([':attempt_id' => (int)$attempt['id']]);
  $answered = (int)$countStmt->fetchColumn();
}
$elapsed = $attempt['elapsed_seconds'] === null ? null : max(0, (int)$attempt['elapsed_seconds']);
$elapsedText = $elapsed === null ? '-' : sprintf('%d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60);
$overallPercent = round(((int)$attempt['total_score'] / $available) * 100, 2);

$categoryHtml = '';
if ($visibility === 'categories') {
  $rows = '';
  foreach ($categories as $category) {
    $percent = $category['percent'] === null ? '-' : number_format((float)$category['percent'], 2) . '%';
    $rows .= '<tr><td>' . h($category['category_name']) . '</td><td>' . (int)$category['answered_count'] . '/' . (int)$category['question_count']
      . '</td><td>' . (int)$category['correct_count'] . '</td><td>' . (int)$category['earned_score'] . '/' . (int)$category['available_score']
      . '</td><td>' . h($percent) . '</td></tr>';
  }
  if ($rows === '') $rows = '<tr><td colspan="5">Breakdown belum tersedia.</td></tr>';
  $categoryHtml = '<div class="st-box" style="margin-top:14px"><div class="st-box-title">Category Breakdown</div>'
    . '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse;margin-top:10px"><thead><tr><th align="left">Category</th><th>Answered</th><th>Correct</th><th>Score</th><th>Percent</th></tr></thead><tbody>'
    . $rows . '</tbody></table></div></div>';
}

$scoreText = (int)$attempt['total_score'] . ' / ' . $available . ' (' . number_format($overallPercent, 2) . '%)';
$page_title = 'Quiz Result';
$content_html = '<section class="st"><div class="st-wrap"><div class="st-hero"><div class="st-badge">RESULT</div>'
  . '<h1 class="st-h1">' . h($attempt['title']) . '</h1><div class="st-desc">Your submitted quiz summary.</div></div>'
  . '<div class="st-box ok"><div class="st-box-title">Overall Summary</div><p><strong>Score:</strong> ' . h($scoreText) . '</p>'
  . '<p><strong>Answered:</strong> ' . $answered . '/' . $totalQuestions . '</p><p><strong>Elapsed time:</strong> ' . h($elapsedText) . '</p>'
  . '<p><strong>Submitted:</strong> ' . h((string)$attempt['submitted_at']) . '</p></div>' . $categoryHtml
  . '<div style="margin-top:14px"><a class="st-btn primary" href="/quiz/start/">Back to Quiz List</a></div></div></section>';
require __DIR__ . '/../layout.php';
