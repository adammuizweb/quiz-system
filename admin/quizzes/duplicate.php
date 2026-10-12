<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$user = qz_admin_user($pdo);
$uid  = (int)$user['id'];
if ($uid <= 0) { http_response_code(401); exit('Unauthorized'); }
if (!$user['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$id   = (int)($_POST['id'] ?? 0);
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($id <= 0) { http_response_code(400); exit('Missing id'); }
if (!csrf_check($csrf)) {
  http_response_code(403); exit('CSRF invalid');
}

function columnExists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
  $st->execute([':c'=>$col]);
  return (bool)$st->fetch(\PDO::FETCH_ASSOC);
}

try {
  $pdo->beginTransaction();

  $srcQuiz = qz_lock_quiz_for_workspace_mutation($pdo, $id, $uid);

  $newTitle = (string)$srcQuiz['title'];
  $newTitle = mb_substr($newTitle, 0, 180, 'UTF-8') . " (Copy)";
  if (mb_strlen($newTitle,'UTF-8') > 200) $newTitle = mb_substr($newTitle, 0, 200, 'UTF-8');

  $insQuiz = $pdo->prepare("
    INSERT INTO quizzes
      (created_by, title, public_prefix, description, question_pool_size, questions_per_attempt, duration_minutes,
       shuffle_answers, timer_warning_seconds, allow_mark_for_review, result_visibility,
       verification_required_override, require_fullscreen_override, pause_when_blur_override,
        block_context_menu_override, block_shortcuts_override, devtools_detect_override, show_exam_badge_override,
       is_active, is_primary, start_at, end_at, created_at, updated_at)
    VALUES
      (:created_by, :title, NULL, :description, 0, :qpa, :dur, :shuffle_answers, :timer_warnings, :allow_review, :result_visibility,
       :verification_required_override, :require_fullscreen_override, :pause_when_blur_override,
        :block_context_menu_override, :block_shortcuts_override, :devtools_detect_override, :show_exam_badge_override,
       0, 0, NULL, NULL, NOW(), NOW())
  ");
  $insQuiz->execute([
    ':created_by' => $uid,
    ':title'      => $newTitle,
    ':description'=> $srcQuiz['description'],
    ':qpa'        => (int)$srcQuiz['questions_per_attempt'],
    ':dur'        => $srcQuiz['duration_minutes'] !== null ? (int)$srcQuiz['duration_minutes'] : null,
    ':shuffle_answers' => quiz_safe_bool($srcQuiz['shuffle_answers'] ?? 1, 1),
    ':timer_warnings' => quiz_serialize_timer_warning_seconds(quiz_safe_timer_warning_seconds($srcQuiz['timer_warning_seconds'] ?? null)),
    ':allow_review' => quiz_safe_bool($srcQuiz['allow_mark_for_review'] ?? 0, 0),
    ':result_visibility' => quiz_safe_result_visibility($srcQuiz['result_visibility'] ?? 'hidden'),
    ':verification_required_override' => $srcQuiz['verification_required_override'],
    ':require_fullscreen_override' => $srcQuiz['require_fullscreen_override'],
    ':pause_when_blur_override' => $srcQuiz['pause_when_blur_override'],
    ':block_context_menu_override' => $srcQuiz['block_context_menu_override'],
    ':block_shortcuts_override' => $srcQuiz['block_shortcuts_override'],
    ':devtools_detect_override' => $srcQuiz['devtools_detect_override'],
    ':show_exam_badge_override' => $srcQuiz['show_exam_badge_override'],
  ]);
  if ($insQuiz->rowCount() !== 1) throw new RuntimeException('Quiz copy was not created.');
  $newQuizId = (int)$pdo->lastInsertId();

  $catMap = [];
  $passMap = [];
  $qMap = [];

  $cats = $pdo->prepare("SELECT * FROM question_categories WHERE quiz_id=:qid ORDER BY id ASC");
  $cats->execute([':qid'=>$id]);
  $rows = $cats->fetchAll(\PDO::FETCH_ASSOC) ?: [];

  $insCat = $pdo->prepare("
    INSERT INTO question_categories
      (quiz_id, created_by, name, description, shuffle_passages, shuffle_questions, min_questions, max_questions,
       sort_order, is_active, created_at, updated_at)
    VALUES
      (:quiz_id, :created_by, :name, :description, :sp, :sq, :minq, :maxq, :sort, :active, NOW(), NOW())
  ");

  foreach ($rows as $r) {
    $insCat->execute([
      ':quiz_id' => $newQuizId,
      ':created_by' => $uid,
      ':name' => $r['name'],
      ':description' => $r['description'],
      ':sp' => (int)($r['shuffle_passages'] ?? 1),
      ':sq' => (int)($r['shuffle_questions'] ?? 1),
      ':minq' => (int)($r['min_questions'] ?? 0),
      ':maxq' => ($r['max_questions'] !== null && $r['max_questions'] !== '') ? (int)$r['max_questions'] : null,
      ':sort' => ($r['sort_order'] !== null && $r['sort_order'] !== '') ? (int)$r['sort_order'] : null,
      ':active' => (int)($r['is_active'] ?? 1),
    ]);
    $catMap[(int)$r['id']] = (int)$pdo->lastInsertId();
  }

  $pass = $pdo->prepare("SELECT * FROM question_passages WHERE quiz_id=:qid ORDER BY id ASC");
  $pass->execute([':qid'=>$id]);
  $pRows = $pass->fetchAll(\PDO::FETCH_ASSOC) ?: [];

  $insPass = $pdo->prepare("
    INSERT INTO question_passages
      (quiz_id, created_by, category_id, title, passage_text,
       image_media_id, file_id, youtube_url,
       shuffle_questions, min_questions, max_questions,
       sort_order, is_active, created_at, updated_at)
    VALUES
      (:quiz_id, :created_by, :cat_id, :title, :text,
       :img, :file, :yt,
       :sq, :minq, :maxq,
       :sort, :active, NOW(), NOW())
  ");

  foreach ($pRows as $p) {
    $oldCat = (int)($p['category_id'] ?? 0);
    $newCat = $oldCat > 0 && isset($catMap[$oldCat]) ? $catMap[$oldCat] : null;

    $insPass->execute([
      ':quiz_id' => $newQuizId,
      ':created_by' => $uid,
      ':cat_id'  => $newCat,
      ':title'   => $p['title'],
      ':text'    => $p['passage_text'],
      ':img'     => $p['image_media_id'] !== null ? (int)$p['image_media_id'] : null,
      ':file'    => $p['file_id'] !== null ? (int)$p['file_id'] : null,
      ':yt'      => $p['youtube_url'],
      ':sq'      => (int)($p['shuffle_questions'] ?? 1),
      ':minq'    => (int)($p['min_questions'] ?? 0),
      ':maxq'    => ($p['max_questions'] !== null && $p['max_questions'] !== '') ? (int)$p['max_questions'] : null,
      ':sort'    => ($p['sort_order'] !== null && $p['sort_order'] !== '') ? (int)$p['sort_order'] : null,
      ':active'  => (int)($p['is_active'] ?? 1),
    ]);
    $passMap[(int)$p['id']] = (int)$pdo->lastInsertId();
  }

  $hasLegacyCategory = columnExists($pdo, 'questions', 'category_id');
  $qSql = "SELECT * FROM questions WHERE quiz_id=:qid ORDER BY id ASC";

  $qs = $pdo->prepare($qSql);
  $qs->execute([':qid'=>$id]);
  $qRows = $qs->fetchAll(\PDO::FETCH_ASSOC) ?: [];

  if ($hasLegacyCategory) {
    $insQ = $pdo->prepare("
      INSERT INTO questions
        (quiz_id, created_by, question_text, question_type, sort_order, is_active,
         image_media_id, file_id, youtube_url,
         passage_id, passage_seq, category_id,
         created_at, updated_at)
      VALUES
        (:quiz_id, :created_by, :text, :type, :sort, :active,
         :img, :file, :yt,
         :pass_id, :pass_seq, :cat_legacy,
         NOW(), NOW())
    ");
  } else {
    $insQ = $pdo->prepare("
      INSERT INTO questions
        (quiz_id, created_by, question_text, question_type, sort_order, is_active,
         image_media_id, file_id, youtube_url,
         passage_id, passage_seq,
         created_at, updated_at)
      VALUES
        (:quiz_id, :created_by, :text, :type, :sort, :active,
         :img, :file, :yt,
         :pass_id, :pass_seq,
         NOW(), NOW())
    ");
  }

  foreach ($qRows as $q) {
    $oldPass = (int)($q['passage_id'] ?? 0);
    $newPass = $oldPass > 0 && isset($passMap[$oldPass]) ? $passMap[$oldPass] : null;
    $oldCategory = (int)($q['category_id'] ?? 0);
    $newCategory = $oldCategory > 0 && isset($catMap[$oldCategory]) ? $catMap[$oldCategory] : null;

    $payload = [
      ':quiz_id' => $newQuizId,
      ':created_by' => $uid,
      ':text'    => $q['question_text'],
      ':type'    => $q['question_type'],
      ':sort'    => ($q['sort_order'] !== null && $q['sort_order'] !== '') ? (int)$q['sort_order'] : null,
      ':active'  => (int)($q['is_active'] ?? 1),
      ':img'     => $q['image_media_id'] !== null ? (int)$q['image_media_id'] : null,
      ':file'    => $q['file_id'] !== null ? (int)$q['file_id'] : null,
      ':yt'      => $q['youtube_url'],
      ':pass_id' => $newPass,
      ':pass_seq'=> ($q['passage_seq'] !== null && $q['passage_seq'] !== '') ? (int)$q['passage_seq'] : null,
    ];

    if ($hasLegacyCategory) {
      $payload[':cat_legacy'] = $newCategory;
    }

    $insQ->execute($payload);
    $qMap[(int)$q['id']] = (int)$pdo->lastInsertId();
  }

  $ans = $pdo->prepare("
    SELECT a.*
    FROM question_answers a
    JOIN questions q ON q.id=a.question_id
    WHERE q.quiz_id=:qid
    ORDER BY a.question_id ASC, a.sort_order ASC, a.id ASC
  ");
  $ans->execute([':qid'=>$id]);
  $aRows = $ans->fetchAll(\PDO::FETCH_ASSOC) ?: [];

  $insA = $pdo->prepare("
    INSERT INTO question_answers
      (question_id, answer_text, score, sort_order, is_active)
    VALUES
      (:qid, :text, :score, :sort, :active)
  ");

  foreach ($aRows as $a) {
    $oldQ = (int)$a['question_id'];
    if (!isset($qMap[$oldQ])) continue;
    $insA->execute([
      ':qid' => $qMap[$oldQ],
      ':text' => $a['answer_text'],
      ':score'=> (int)($a['score'] ?? 0),
      ':sort' => (int)($a['sort_order'] ?? 0),
      ':active' => (int)($a['is_active'] ?? 1),
    ]);
  }

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id=:qid");
  $cnt->execute([':qid'=>$newQuizId]);
  $pool = (int)$cnt->fetchColumn();

  $up = $pdo->prepare("UPDATE quizzes SET question_pool_size=:n WHERE id=:id");
  $up->execute([':n'=>$pool, ':id'=>$newQuizId]);

  $pdo->commit();

  qz_redirect($base."/?page=admin/quiz/master&quiz_id=".$newQuizId."&msg=".urlencode("Quiz duplicated. Set the category minimum and maximum values."));

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[quiz/duplicate] ".$e->getMessage());
  qz_redirect($base."/?page=admin/quiz/quizzes&err=".urlencode("Could not duplicate the Quiz. Check error_log."));
}
