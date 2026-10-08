<?php
// /quiz/start/index.php
// UX: Pilih Quiz
// - Jika ?quiz= ada: redirect ke /quiz/start/confirm/?quiz=ID
// - Jika tidak: tampilkan daftar quiz open (+ status attempt bila student sudah terdaftar)

declare(strict_types=1);

// Guard: kalau ada attempt aktif (verified/in_progress) dan token valid -> lempar ke take
require_once __DIR__ . '/../_guard_resume.php';
qz_guard_resume($pdo);

// Anti-cache
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
}

$page_title = 'Pilih Quiz';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

/**
 * Cek apakah kolom ada, supaya query tidak bikin 500.
 */
function has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :t
        AND COLUMN_NAME = :c
      LIMIT 1
    ");
    $st->execute([':t'=>$table, ':c'=>$column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

$now = date('Y-m-d H:i:s');
$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

// 1) Jika quiz dipilih via query param
$quiz_id = (int)($_GET['quiz'] ?? 0);
if ($quiz_id > 0) {
  go('/quiz/start/confirm/?quiz=' . $quiz_id);
}

// pastikan pdo valid
if (!($pdo instanceof PDO)) {
  http_response_code(500);
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">
    <h1 style="margin:0 0 8px">Koneksi DB tidak tersedia</h1>
    <p style="margin:0;opacity:.8">PDO tidak terinisialisasi. Cek bootstrap_core.php</p>
  </div>';
  require __DIR__ . '/../layout.php';
  exit;
}

$hasPrimary = has_column($pdo, 'quizzes', 'is_primary');

/* ============== student context (opsional) ============== */
$student_id = 0;
$student_cookie = qz_cookie_get('quiz_cid');

if ($student_cookie !== '' && preg_match('/^[a-f0-9\-]{36}$/i', $student_cookie)) {
  try {
    $st = $pdo->prepare("SELECT id FROM students WHERE cookie_id = :cid LIMIT 1");
    $st->execute([':cid'=>$student_cookie]);
    $student_id = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $student_id = 0;
  }
}

/* ============== 2) Auto primary hanya jika diminta ============== */
$auto = ((string)($_GET['auto'] ?? '') === '1');

if ($auto && $hasPrimary) {
  try {
    $stmt = $pdo->query("
      SELECT id, title, description, is_active, is_primary, start_at, end_at
      FROM quizzes
      WHERE is_primary = 1
      ORDER BY id DESC
      LIMIT 1
    ");
    $primary = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    if ($primary) {
      $open = ((int)($primary['is_active'] ?? 0) === 1)
        && (empty($primary['start_at']) || $now >= (string)$primary['start_at'])
        && (empty($primary['end_at'])   || $now <= (string)$primary['end_at']);

      if ($open) go('/quiz/start/confirm/?quiz=' . (int)$primary['id']);
    }
  } catch (Throwable $e) {
    error_log("[quiz/start] primary check error: " . $e->getMessage());
  }
}

/* ============== 3) Read quiz display settings ============== */
if (!function_exists('settings_get')) {
  $start_mode  = 'all';
  $start_ids   = '';
  $start_order = 'default';
  $start_manual= '';
  $start_limit = 50;
} else {
  $start_mode  = settings_get($pdo, 'quiz_start_mode', 'all') ?? 'all';
  $start_ids   = settings_get($pdo, 'quiz_start_ids', '') ?? '';
  $start_order = settings_get($pdo, 'quiz_start_order', 'default') ?? 'default';
  $start_manual= settings_get($pdo, 'quiz_start_manual', '') ?? '';
  $start_limit = max(1, min(999, (int)(settings_get($pdo, 'quiz_start_limit', '50') ?? '50')));
}

$whereExtra = '';
$params = [':now' => $now];

if ($start_mode === 'primary' && $hasPrimary) {
  $whereExtra = 'AND q.is_primary = 1';
} elseif ($start_mode === 'selected') {
  $selIds = [];
  foreach (explode(',', $start_ids) as $seg) {
    $v = trim($seg);
    if ($v !== '' && ctype_digit($v)) $selIds[] = (int)$v;
  }
  if (!empty($selIds)) {
    $in = [];
    foreach ($selIds as $i => $id) {
      $k = ':sel_' . $i;
      $in[] = $k;
      $params[$k] = $id;
    }
    $whereExtra = 'AND q.id IN (' . implode(',', $in) . ')';
  }
}

switch ($start_order) {
  case 'id_asc': $orderBy = 'q.id ASC'; break;
  case 'id_desc': $orderBy = 'q.id DESC'; break;
  case 'title_asc': $orderBy = 'q.title ASC'; break;
  case 'title_desc': $orderBy = 'q.title DESC'; break;
  case 'manual':
    $mIds = [];
    foreach (explode(',', $start_manual) as $seg) {
      $v = trim($seg);
      if ($v !== '' && ctype_digit($v)) $mIds[] = (int)$v;
    }
    $orderBy = !empty($mIds)
      ? 'FIELD(q.id,' . implode(',', $mIds) . ')'
      : ($hasPrimary ? 'q.is_primary DESC, q.id DESC' : 'q.id DESC');
    break;
  default:
    $orderBy = $hasPrimary ? 'q.is_primary DESC, q.id DESC' : 'q.id DESC';
}

/* ============== 4) List quiz ============== */
try {
  $selectCols = $hasPrimary
    ? 'q.id, q.title, q.description, q.is_primary, q.start_at, q.end_at, q.result_visibility'
    : 'q.id, q.title, q.description, q.start_at, q.end_at, q.result_visibility';

  if ($student_id > 0) {
    $selectCols .= ', a.id AS attempt_id, a.attempt_alias, a.status, a.submitted_at';
    $sql = "
      SELECT {$selectCols}
      FROM quizzes q
      LEFT JOIN quiz_attempts a ON a.quiz_id = q.id AND a.student_id = :sid
      WHERE q.is_active = 1
        AND (q.start_at IS NULL OR q.start_at <= :now)
        AND (q.end_at   IS NULL OR q.end_at   >= :now)
        {$whereExtra}
      ORDER BY {$orderBy}
      LIMIT {$start_limit}
    ";
    $params[':sid'] = $student_id;
  } else {
    $sql = "
      SELECT {$selectCols}
      FROM quizzes q
      WHERE q.is_active = 1
        AND (q.start_at IS NULL OR q.start_at <= :now)
        AND (q.end_at   IS NULL OR q.end_at   >= :now)
        {$whereExtra}
      ORDER BY {$orderBy}
      LIMIT {$start_limit}
    ";
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  error_log("[quiz/start] list error: " . $e->getMessage());
  http_response_code(500);
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">
    <h1 style="margin:0 0 8px">There was a problem</h1>
    <p style="margin:0;opacity:.8">Failed to load quiz list.</p>
  </div>';
  require __DIR__ . '/../layout.php';
  exit;
}

$list = '';
if (!empty($rows)) {
  foreach ($rows as $r) {
    $qid  = (int)$r['id'];
    $title= (string)$r['title'];
    $desc = trim((string)($r['description'] ?? ''));

    $badge = ($hasPrimary && (int)($r['is_primary'] ?? 0) === 1)
      ? ' <span class="qz-pill">PRIMARY</span>'
      : '';

    $descHtml = $desc ? '<div class="qz-item-desc">'.h($desc).'</div>' : '';

    // default action
    $btnText = 'Open';
    $btnHref = '/quiz/start/confirm/?quiz=' . $qid;
    $btnCls  = 'qz-btn';
    $btnAttr = '';

    // if student is detected, show attempt status
    if ($student_id > 0 && isset($r['status'])) {
      $st = (string)($r['status'] ?? '');
      $alias = (string)($r['attempt_alias'] ?? '');

      if ($st === 'submitted') {
        if (quiz_safe_result_visibility($r['result_visibility'] ?? 'hidden') !== 'hidden' && $alias !== ''
            && quiz_attempt_result_is_ready($pdo, (int)($r['attempt_id'] ?? 0))) {
          $btnText = 'View result';
          $btnHref = '/quiz/result/?a=' . rawurlencode($alias);
        } else {
          $btnText = 'Already submitted';
          $btnHref = '#';
          $btnCls  = 'qz-btn disabled';
          $btnAttr = ' aria-disabled="true" onclick="return false"';
        }
      } elseif (in_array($st, ['verified','in_progress'], true) && $alias !== '') {
        $btnText = 'Continue';
        $btnHref = '/quiz/take/?a=' . rawurlencode($alias);
      } else {
        // registered / expired / unknown -> back to confirm
        $btnText = 'Open';
        $btnHref = '/quiz/start/confirm/?quiz=' . $qid;
      }
    }

    $list .= '
      <div class="qz-item">
        <div class="qz-item-head">
          <div class="qz-item-title">'.h($title).$badge.'</div>
          <a class="'.h($btnCls).'" href="'.h($btnHref).'"'.$btnAttr.'>'.h($btnText).'</a>
        </div>
        '.$descHtml.'
      </div>
    ';
  }
} else {
  $list = '<div class="qz-empty">No quizzes are currently open.</div>';
}

$alert = '';
if ($msg !== '') $alert .= '<div class="st-alert ok">'.h($msg).'</div>';
if ($err !== '') $alert .= '<div class="st-alert err">'.h($err).'</div>';

$content_html = '
<section class="st">
  <div class="st-wrap">

    <div class="st-hero">
      <div class="st-badge">START</div>
      <h1 class="st-h1">Select Quiz</h1>
      <div class="st-desc">Choose a quiz that is currently open. After that you will be taken to the <b>confirmation</b> page.</div>

      <div class="rz-steps" style="margin-top:12px">
        <div class="rz-step">1. Registration</div>
        <div class="rz-step active">2. Select Quiz</div>
        <div class="rz-step">3. Confirmation</div>
        <div class="rz-step">4. OTP Verification</div>
        <div class="rz-step">5. Take Quiz</div>
      </div>
    </div>

    '.$alert.'

    <div class="st-box ok">
      <div class="st-box-title">Quiz List</div>
      <div style="margin-top:12px">
        '.$list.'
      </div>
      <div class="st-note" style="margin-top:12px">
        Note: If you are not registered or your information is incomplete, the system will prompt you to register during confirmation.
      </div>
    </div>

  </div>
</section>
';

require __DIR__ . '/../layout.php';
exit;
