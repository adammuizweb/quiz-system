<?php
// /quiz/api/get_question.php
declare(strict_types=1);

if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/_timer_helper.php';
require_once __DIR__ . '/../_security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_out(int $code, array $payload): void {
  http_response_code($code);
  if (ob_get_length() !== false && ob_get_length() > 0) { @ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail(int $code, string $msg, ?array $data = null): void {
  json_out($code, ['ok'=>false, 'error'=>$msg, 'data'=>$data]);
}

set_exception_handler(function($e){
  error_log('[quiz/api/get_question] UNCAUGHT: '.$e->getMessage());
  json_fail(500, 'Server error');
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail(405, 'Method Not Allowed');
if (!qz_public_csrf_check(qz_public_csrf_from_request())) json_fail(419, 'CSRF token mismatch');

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = :t
    LIMIT 1
  ");
  $st->execute([':t'=>$table]);
  return (bool)$st->fetchColumn();
}
function table_columns(PDO $pdo, string $table): array {
  $st = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = :t
  ");
  $st->execute([':t'=>$table]);
  $cols = $st->fetchAll(PDO::FETCH_COLUMN);
  return is_array($cols) ? $cols : [];
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = :t
      AND column_name = :c
    LIMIT 1
  ");
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (bool)$st->fetchColumn();
}

function normalize_public_url(string $u): string {
  $u = trim($u);
  if ($u === '') return '';
  if (preg_match('~^https?://~i', $u)) return $u;

  // hindari path server absolut
  if (preg_match('~^/home/|^/var/|^[A-Z]:\\\\~i', $u)) return '';

  if ($u[0] !== '/') $u = '/' . $u;
  return $u;
}

/**
 * Kalau content tersimpan sudah ter-escape (mis: &lt;p&gt;),
 * decode agar <p> benar-benar jadi HTML.
 */
function maybe_decode_entities(string $s): string {
  if ($s === '') return '';
  if (preg_match('/&lt;|&gt;|&#0*60;|&#0*62;|&quot;|&#0*34;|&#0*39;|&apos;/i', $s)) {
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  return $s;
}

/**
 * Resolve info file secara aman tanpa bikin SQL error
 */
function resolve_file_info(PDO $pdo, int $fileId): array {
  if ($fileId <= 0) return ['file_label'=>'', 'file_url'=>''];
  $st = $pdo->prepare("SELECT filename, title, url FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
  $st->execute([':id'=>$fileId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return ['file_label'=>'', 'file_url'=>''];
  $label = trim((string)($row['filename'] ?? $row['title'] ?? '')) ?: 'File #' . $fileId;
  return ['file_label'=>$label, 'file_url'=>normalize_public_url((string)($row['url'] ?? ''))];
}

try {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw ?: '[]', true);
  if (!is_array($in)) json_fail(400, 'Bad JSON');

  $quiz_id = (int)($in['quiz_id'] ?? 0);
  $seq = isset($in['seq']) ? (int)$in['seq'] : 0;
  $attemptAlias = trim((string)($in['attempt'] ?? ''));

  if ($quiz_id <= 0) json_fail(400, 'quiz_id invalid');
  if ($attemptAlias !== '' && !preg_match('/^[a-zA-Z0-9_\-]{6,80}$/', $attemptAlias)) json_fail(400, 'attempt invalid');

  $student_cookie_id = isset($_COOKIE['quiz_cid']) ? trim((string)$_COOKIE['quiz_cid']) : '';
  if ($student_cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $student_cookie_id)) json_fail(401, 'Not registered');

  $tokenCookieName = 'quiz_token_' . $quiz_id;
  $token_plain = (string)($_COOKIE[$tokenCookieName] ?? '');
  if ($token_plain === '') json_fail(401, 'Missing token');

  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $user_agent_hash = hash('sha256', $ua);
  $token_hash = hash('sha256', $token_plain);

  $stmt = $pdo->prepare("SELECT id FROM students WHERE cookie_id=:cid LIMIT 1");
  $stmt->execute([':cid'=>$student_cookie_id]);
  $student_id = (int)$stmt->fetchColumn();
  if ($student_id <= 0) json_fail(401, 'Student not found');

  if ($attemptAlias !== '') {
    $stmt = $pdo->prepare("
      SELECT a.*, q.duration_minutes
      FROM quiz_attempts a
      JOIN quizzes q ON q.id = a.quiz_id
      WHERE a.attempt_alias = :a AND a.student_id = :sid
      LIMIT 1
    ");
    $stmt->execute([':a'=>$attemptAlias, ':sid'=>$student_id]);
  } else {
    $stmt = $pdo->prepare("
      SELECT a.*, q.duration_minutes
      FROM quiz_attempts a
      JOIN quizzes q ON q.id = a.quiz_id
      WHERE a.quiz_id=:quiz_id AND a.student_id=:student_id
      ORDER BY a.id DESC
      LIMIT 1
    ");
    $stmt->execute([':quiz_id'=>$quiz_id, ':student_id'=>$student_id]);
  }

  $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) json_fail(403, 'Attempt not found');

  if ((int)$attempt['quiz_id'] !== $quiz_id) json_fail(403, 'Attempt mismatch');

  if (
    !hash_equals((string)$attempt['token_hash'], $token_hash) ||
    !hash_equals((string)$attempt['user_agent_hash'], $user_agent_hash)
  ) {
    json_fail(403, 'Access not valid');
  }

  if (empty($attempt['email_verified_at']) || (string)$attempt['status'] === 'registered') json_fail(403, 'Not verified');
  if ((string)$attempt['status'] === 'submitted') json_fail(403, 'Already submitted');

  $attempt_id = (int)$attempt['id'];

  /* ===== TIMER ENFORCE (MySQL based) ===== */
  $durMin = (int)($attempt['duration_minutes'] ?? 0);
  $timer = qz_timer_enforce($pdo, $attempt_id, $durMin, true, true);
  if (!empty($timer['time_up'])) {
    json_fail(403, 'The exam time is over.', ['time_up'=>true, 'timer'=>$timer]);
  }
  /* ===== END TIMER ===== */

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_questions WHERE attempt_id=:aid");
  $stmt->execute([':aid'=>$attempt_id]);
  $total = (int)$stmt->fetchColumn();
  if ($total <= 0) json_fail(500, 'No questions assigned');

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
  $answered_count = (int)$stmt->fetchColumn();

  if ($seq <= 0) {
    $stmt = $pdo->prepare("
      SELECT aq.seq
      FROM attempt_questions aq
      JOIN questions q ON q.id = aq.question_id
      LEFT JOIN attempt_answers aa
        ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
      WHERE aq.attempt_id = :aid
      ORDER BY
        CASE
          WHEN (
            (q.question_type IN ('single_choice','yesno') AND aa.selected_answer_id IS NOT NULL)
            OR
            (q.question_type = 'multiple_choice' AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '' AND TRIM(aa.answer_text) <> '[]')
            OR
            (q.question_type IN ('text','number') AND aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '')
          ) THEN 1 ELSE 0 END ASC,
        aq.seq ASC
      LIMIT 1
    ");
    $stmt->execute([':aid'=>$attempt_id]);
    $seq = (int)$stmt->fetchColumn();
    if ($seq <= 0) $seq = 1;
  }

  // ===== schema detect for min/max =====
  $hasQCat     = column_exists($pdo, 'questions', 'category_id');
  $hasCatMin   = column_exists($pdo, 'question_categories', 'min_questions');
  $hasCatMax   = column_exists($pdo, 'question_categories', 'max_questions');
  $hasPassMin  = column_exists($pdo, 'question_passages', 'min_questions');
  $hasPassMax  = column_exists($pdo, 'question_passages', 'max_questions');

  $selectQCat = $hasQCat
    ? ",
      q.category_id AS question_category_id,
      qc.name AS question_category_name,
      ".($hasCatMin ? "qc.min_questions" : "0")." AS question_category_min_questions,
      ".($hasCatMax ? "qc.max_questions" : "NULL")." AS question_category_max_questions
    "
    : ",
      NULL AS question_category_id,
      '' AS question_category_name,
      0 AS question_category_min_questions,
      NULL AS question_category_max_questions
    ";

  $joinQCat = $hasQCat
    ? "LEFT JOIN question_categories qc ON qc.id = q.category_id AND qc.quiz_id = q.quiz_id"
    : "";

  $stmt = $pdo->prepare("
    SELECT
      aq.seq,
      q.id AS question_id,
      q.question_text,
      q.question_type,

      q.image_media_id,
      qm.url AS image_url,
      q.file_id,
      q.youtube_url,

      q.passage_id,
      p.title AS passage_title,
      p.passage_text,
      p.image_media_id AS passage_image_media_id,
      pm.url AS passage_image_url,
      p.file_id AS passage_file_id,
      p.youtube_url AS passage_youtube_url,

      ".($hasPassMin ? "p.min_questions" : "0")." AS passage_min_questions,
      ".($hasPassMax ? "p.max_questions" : "NULL")." AS passage_max_questions,

      p.category_id AS passage_category_id,
      pc.name AS passage_category_name,
      ".($hasCatMin ? "pc.min_questions" : "0")." AS passage_category_min_questions,
      ".($hasCatMax ? "pc.max_questions" : "NULL")." AS passage_category_max_questions

      {$selectQCat}

    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id

    LEFT JOIN media qm ON qm.id = q.image_media_id AND qm.visibility='public' AND qm.storage_disk='public' AND qm.access_scope='public'

    LEFT JOIN question_passages p
      ON p.id = q.passage_id AND p.quiz_id = q.quiz_id

    LEFT JOIN media pm ON pm.id = p.image_media_id AND pm.visibility='public' AND pm.storage_disk='public' AND pm.access_scope='public'

    LEFT JOIN question_categories pc
      ON pc.id = p.category_id AND pc.quiz_id = q.quiz_id

    {$joinQCat}

    WHERE aq.attempt_id = :aid AND aq.seq = :seq
    LIMIT 1
  ");
  $stmt->execute([':aid'=>$attempt_id, ':seq'=>$seq]);
  $q = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$q) json_fail(404, 'Question not found');

  // decode entity tags
  $q['question_text'] = maybe_decode_entities((string)($q['question_text'] ?? ''));
  $q['passage_text']  = maybe_decode_entities((string)($q['passage_text'] ?? ''));

  // existing answer row
  $stmt = $pdo->prepare("
    SELECT selected_answer_id, answer_text
    FROM attempt_answers
    WHERE attempt_id = :aid AND question_id = :qid
    LIMIT 1
  ");
  $stmt->execute([':aid'=>$attempt_id, ':qid'=>(int)$q['question_id']]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['selected_answer_id'=>null,'answer_text'=>null];

  $answers = [];
  if (!in_array((string)$q['question_type'], ['text','number'], true)) {
// === ANSWER SHUFFLE (stable per attempt+question) ===
// ganti salt ini dengan string random milikmu (jangan umum)
if (!defined('QZ_ANSWER_SHUFFLE_SALT')) {
  define('QZ_ANSWER_SHUFFLE_SALT', 'CHANGE_ME_to_random_long_string');
}

$answers = [];
if (!in_array((string)$q['question_type'], ['text','number'], true)) {

  // seed unik per siswa(attempt) + per soal
  $seed = hash('sha256',
    QZ_ANSWER_SHUFFLE_SALT.'|'.$attempt_id.'|'.(int)$q['question_id'].'|'.(string)($attempt['token_hash'] ?? '')
  );

  $stmt = $pdo->prepare("
    SELECT id, answer_text
    FROM question_answers
    WHERE question_id = :qid AND is_active = 1
    ORDER BY MD5(CONCAT(:seed, ':', id)) ASC, id ASC
  ");
  $stmt->execute([
    ':qid'  => (int)$q['question_id'],
    ':seed' => $seed,
  ]);

  $answers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($answers as &$a) {
    $a['answer_text'] = maybe_decode_entities((string)($a['answer_text'] ?? ''));
  }
  unset($a);
}

    foreach ($answers as &$a) {
      $a['answer_text'] = maybe_decode_entities((string)($a['answer_text'] ?? ''));
    }
    unset($a);
  }

  $existing_selected_answer_id = $existing['selected_answer_id'] ?? null;
  $existing_selected_answer_ids = null;

  if ((string)$q['question_type'] === 'multiple_choice') {
    $arr = [];
    $raw2 = (string)($existing['answer_text'] ?? '');
    if ($raw2 !== '') {
      $tmp = json_decode($raw2, true);
      if (is_array($tmp)) {
        foreach ($tmp as $x) {
          $v = (int)$x;
          if ($v > 0) $arr[] = $v;
        }
      }
    }
    $existing_selected_answer_ids = array_values(array_unique($arr));
    $existing_selected_answer_id = null;
  }

  // resolve passage "real stimulus"?
  $passageId = (int)($q['passage_id'] ?? 0);
  $passageText = (string)($q['passage_text'] ?? '');
  $passageTextTrim = trim($passageText);
  $isRealPassage = ($passageId > 0 && $passageTextTrim !== '' && $passageTextTrim !== '__CATEGORY_CONTAINER__');

  // ✅ FIX category label:
  // - jika real passage punya category => pakai category itu
  // - jika real passage TANPA category => label pakai passage_title + min/max pakai passage min/max
  // - selain itu fallback ke question.category (jika ada)
  $catId = (int)($q['passage_category_id'] ?? 0);
  $catName = trim((string)($q['passage_category_name'] ?? ''));

  $catMin = (int)($q['passage_category_min_questions'] ?? 0);
  $catMaxRaw = $q['passage_category_max_questions'] ?? null;

  if ($isRealPassage && ($catId <= 0 || $catName === '')) {
    $ptitle = trim((string)($q['passage_title'] ?? ''));
    if ($ptitle !== '') {
      $catId = 0; // kirim null di output
      $catName = $ptitle;

      // gunakan min/max passage sebagai label min/max (ini yang kamu butuh)
      $catMin = (int)($q['passage_min_questions'] ?? 0);
      $catMaxRaw = $q['passage_max_questions'] ?? null;
    }
  }

  if (($catId <= 0 || $catName === '') && $hasQCat) {
    $catId = (int)($q['question_category_id'] ?? 0);
    $catName = trim((string)($q['question_category_name'] ?? ''));

    $catMin = (int)($q['question_category_min_questions'] ?? 0);
    $catMaxRaw = $q['question_category_max_questions'] ?? null;
  }

  if ($catName === '') $catName = 'Lainnya';
  $catMax = ($catMaxRaw === null || $catMaxRaw === '') ? null : (int)$catMaxRaw;

  // resolve file info question + passage
  $fileId = (int)($q['file_id'] ?? 0);
  $fileInfo = resolve_file_info($pdo, $fileId);

  $pFileId = (int)($q['passage_file_id'] ?? 0);
  $pFileInfo = resolve_file_info($pdo, $pFileId);

  $passage = null;

  if ($isRealPassage) {
    $pMaxRaw = $q['passage_max_questions'] ?? null;
    $pMax = ($pMaxRaw === null || $pMaxRaw === '') ? null : (int)$pMaxRaw;

    $passage = [
      'id' => $passageId,
      'title' => (string)($q['passage_title'] ?? ''),
      'passage_text' => $passageText,

      'min_questions' => (int)($q['passage_min_questions'] ?? 0),
      'max_questions' => $pMax,

      'image_media_id' => !empty($q['passage_image_url']) ? (int)$q['passage_image_media_id'] : null,
      'image_url'      => normalize_public_url((string)($q['passage_image_url'] ?? '')),

      'file_id'    => $pFileId > 0 && $pFileInfo['file_url'] !== '' ? $pFileId : null,
      'file_label' => (string)($pFileInfo['file_label'] ?? ''),
      'file_url'   => (string)($pFileInfo['file_url'] ?? ''),

      'youtube_url' => (string)($q['passage_youtube_url'] ?? ''),
    ];
  }

  json_out(200, [
    'ok' => true,
    'data' => [
      'seq' => (int)$q['seq'],
      'total' => $total,
      'answered_count' => $answered_count,

      'question_id' => (int)$q['question_id'],
      'question_text' => (string)$q['question_text'],
      'question_type' => (string)$q['question_type'],

      'answers' => $answers,

      'existing_selected_answer_id' => $existing_selected_answer_id,
      'existing_selected_answer_ids' => $existing_selected_answer_ids,
      'existing_answer_text' => (
        $q['question_type']==='text' || $q['question_type']==='number'
      ) ? (string)($existing['answer_text'] ?? '') : null,

      // ✅ category label (bisa jadi judul passage jika passage tak punya category)
      'category_id'   => ($catId > 0) ? $catId : null,
      'category_name' => (string)$catName,
      'category_min_questions' => $catMin,
      'category_max_questions' => $catMax,

      'passage' => $passage,

      'image_media_id' => !empty($q['image_url']) ? (int)$q['image_media_id'] : null,
      'image_url'      => normalize_public_url((string)($q['image_url'] ?? '')),

      'file_id'        => $fileId > 0 && $fileInfo['file_url'] !== '' ? $fileId : null,
      'file_label'     => (string)($fileInfo['file_label'] ?? ''),
      'file_url'       => (string)($fileInfo['file_url'] ?? ''),

      'youtube_url'    => (string)($q['youtube_url'] ?? ''),
    ],
    'timer' => $timer,
  ]);

} catch (Throwable $e) {
  error_log("[quiz/api/get_question] FAIL: ".$e->getMessage());
  json_fail(500, 'Server error');
}
