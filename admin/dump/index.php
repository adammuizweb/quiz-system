<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('cut')) {
  function cut($s, $n=220){
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (mb_strlen($s,'UTF-8') <= $n) return $s;
    return mb_substr($s,0,$n-1,'UTF-8') . '…';
  }
}

if (!function_exists('alpha_label')) {
  function alpha_label(int $idx0): string {
    $n = $idx0 + 1;
    $s = '';
    while ($n > 0) {
      $mod = ($n - 1) % 26;
      $s = chr(65 + $mod) . $s;
      $n = intdiv($n - 1, 26);
    }
    return $s;
  }
}

if (!function_exists('qz_rich_html')) {
  function qz_rich_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    return function_exists('cms_sanitize_restricted_html')
      ? cms_sanitize_restricted_html($html)
      : nl2br(h($html));
  }
}

if (!function_exists('qz_pick_url')) {
  function qz_pick_url(array $row, string $base): ?string {
    $cands = ['url','src','link','public_url'];
    foreach ($cands as $k) {
      if (!empty($row[$k]) && is_string($row[$k])) {
        $u = trim($row[$k]);
        if ($u === '') continue;
        if (preg_match('~^https?://~i', $u)) return $u;
        if (substr($u,0,1) === '/' && !preg_match('~^/(?:home|var|etc|srv)/~i', $u)) return $u;
        return rtrim($base,'/') . '/' . ltrim($u,'/');
      }
    }
    return null;
  }
}

if (!function_exists('qz_is_pdf_row')) {
  function qz_is_pdf_row(array $row): bool {
    $mime = strtolower((string)($row['mime'] ?? $row['mime_type'] ?? $row['file_mime'] ?? ''));
    if ($mime === 'application/pdf') return true;
    $name = strtolower((string)($row['name'] ?? $row['filename'] ?? $row['original_name'] ?? $row['file_name'] ?? ''));
    $path = strtolower((string)($row['path'] ?? $row['file_path'] ?? $row['url'] ?? ''));
    return (substr($name, -4) === '.pdf' || substr($path, -4) === '.pdf');
  }
}

if (!function_exists('qz_youtube_embed_url')) {
  function qz_youtube_embed_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $u = @parse_url($url);
    if (!$u) return '';
    $host = strtolower((string)($u['host'] ?? ''));
    $path = (string)($u['path'] ?? '');
    $query = (string)($u['query'] ?? '');
    if (strpos($host, 'youtu.be') !== false) {
      $id = trim($path, '/');
      return $id ? "https://www.youtube.com/embed/{$id}" : '';
    }
    if (strpos($host, 'youtube.com') !== false) {
      if (strpos($path, '/embed/') === 0) {
        $id = trim(substr($path, 7), '/');
        return $id ? "https://www.youtube.com/embed/{$id}" : '';
      }
      parse_str($query, $qs);
      $id = (string)($qs['v'] ?? '');
      return $id ? "https://www.youtube.com/embed/{$id}" : '';
    }
    return '';
  }
}

if (!function_exists('qz_render_media_block')) {
  function qz_render_media_block(
    ?string $yt,
    ?int $imgId,
    ?int $fileId,
    array $mediaMap,
    array $fileMap,
    string $base
  ): string {
    $htmlParts = [];
    $yt = trim((string)$yt);
    if ($yt !== '') {
      $embed = qz_youtube_embed_url($yt);
      if ($embed !== '') {
        $htmlParts[] = '<div class="qt-yt"><iframe src="'.h($embed).'" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
      } else {
        $htmlParts[] = '<div class="qt-file"><div><b>YouTube</b></div><small>'.h($yt).'</small></div>';
      }
    }
    if ($imgId) {
      $row = $mediaMap[$imgId] ?? null;
      $url = $row ? qz_pick_url($row, $base) : null;
      if ($url) {
        $htmlParts[] = '<img src="'.h($url).'" alt="Image">';
      } else {
        $htmlParts[] = '<div class="qt-file"><div><b>Image</b> #'.(int)$imgId.'</div><small>URL not found (check the media.url/path columns)</small></div>';
      }
    }
    if ($fileId) {
      $row = $fileMap[$fileId] ?? null;
      $url = $row ? qz_pick_url($row, $base) : null;
      $nm  = (string)($row['name'] ?? $row['filename'] ?? $row['original_name'] ?? $row['file_name'] ?? ('File #'.$fileId));
      if ($url) {
        if ($row && qz_is_pdf_row($row)) {
          $htmlParts[] = '<div class="qz-pdf"><iframe src="'.h($url).'"></iframe></div>';
        } else {
          $htmlParts[] = '<div class="qt-file"><a href="'.h($url).'" target="_blank" rel="noopener">Download File</a><small>'.h($nm).'</small></div>';
        }
      } else {
        $htmlParts[] = '<div class="qt-file"><div><b>File</b> #'.(int)$fileId.'</div><small>URL not found (check the file.url/path columns)</small></div>';
      }
    }
    if (!$htmlParts) return '';
    $out = '<div class="qt-qmedia">';
    foreach ($htmlParts as $p) $out .= '<div class="m-item">'.$p.'</div>';
    $out .= '</div>';
    return $out;
  }
}

function build_url(array $overrides = []): string {
  $qs = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return '?' . http_build_query($qs);
}

function badge(string $text, string $type='muted'): string {
  $cls = 'badge';
  if ($type === 'ok') $cls .= ' badge--ok';
  else if ($type === 'bad') $cls .= ' badge--bad';
  else if ($type === 'warn') $cls .= ' badge--warn';
  else if ($type === 'primary') $cls .= ' badge--primary';
  else $cls .= ' badge--muted';
  return '<span class="'.$cls.'">'.h($text).'</span>';
}

$base = rtrim(ADMIN_BASE_PATH, '/');
$uid  = (int)($_SESSION['user_id'] ?? 0);

$messages = [];
$errors   = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode($_GET['err']);

if ($uid <= 0) {
  $errors[] = 'Session user_id was not found. Please sign in again.';
}

$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) {
  echo '<div class="adam-alert error">Missing or invalid quiz ID.</div>';
  return;
}
qz_require_quiz_access($pdo, $quiz_id);

$q        = trim((string)($_GET['q'] ?? ''));
$cat_id   = (string)($_GET['cat_id'] ?? '');
$pass_id  = (string)($_GET['pass_id'] ?? '');
$q_status = (string)($_GET['q_status'] ?? 'all');
$a_status = (string)($_GET['a_status'] ?? 'all');
$q_type   = (string)($_GET['q_type'] ?? 'all');
$only_issue = ((int)($_GET['issue'] ?? 0) === 1);
$show_passage_text = ((int)($_GET['show_passage'] ?? 0) === 1);

$order_mode = (string)($_GET['order'] ?? 'master');
if (!in_array($order_mode, ['master','cat'], true)) $order_mode = 'master';

$per_raw = (string)($_GET['per'] ?? '100');
$per = ($per_raw === 'all') ? 0 : max(0, (int)$per_raw);
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($per > 0) ? (($page - 1) * $per) : 0;

$quiz = null;
$categories = [];
$passages = [];
$totalFiltered = 0;
$rows = [];
$answersByQ = [];
$mediaMap = [];
$fileMap  = [];

try {
  $st = $pdo->prepare("SELECT id,title,description,is_active,is_primary,created_at FROM quizzes WHERE id=:id LIMIT 1");
  $st->execute([':id' => $quiz_id]);
  $quiz = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$quiz) {
    http_response_code(404);
    exit('Quiz not found');
  }

  $st = $pdo->prepare("
    SELECT id, name, sort_order, is_active
    FROM question_categories
    WHERE quiz_id = :qid
    ORDER BY COALESCE(sort_order, 999999), id
  ");
  $st->execute([':qid' => $quiz_id]);
  $categories = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $st = $pdo->prepare("
    SELECT id, title, sort_order, is_active, category_id, passage_text
    FROM question_passages
    WHERE quiz_id = :qid
      AND (passage_text IS NULL OR passage_text <> '__CATEGORY_CONTAINER__')
    ORDER BY COALESCE(sort_order, 999999), id
  ");
  $st->execute([':qid' => $quiz_id]);
  $passages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $from = "
    FROM questions q
    LEFT JOIN question_passages p
      ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
    LEFT JOIN question_categories c
      ON c.id = COALESCE(q.category_id, p.category_id) AND c.quiz_id = q.quiz_id
    WHERE q.quiz_id = :qid
      AND (p.passage_text IS NULL OR p.passage_text <> '__CATEGORY_CONTAINER__')
  ";

  $where = [];
  $params = [':qid' => $quiz_id];

  if ($q !== '') {
    $where[] = "(
      q.question_text LIKE :q
      OR CAST(q.id AS CHAR) = :qid_txt
      OR (p.title LIKE :q)
      OR (p.passage_text LIKE :q)
      OR (c.name LIKE :q)
      OR EXISTS (
        SELECT 1 FROM question_answers qa
        WHERE qa.question_id = q.id
          AND qa.answer_text LIKE :q
      )
    )";
    $params[':q'] = '%' . $q . '%';
    $params[':qid_txt'] = $q;
  }

  if ($cat_id !== '' && ctype_digit($cat_id)) {
    $where[] = "COALESCE(q.category_id, p.category_id) = :cat";
    $params[':cat'] = (int)$cat_id;
  }

  if ($pass_id !== '' && ctype_digit($pass_id)) {
    $where[] = "q.passage_id = :pid";
    $params[':pid'] = (int)$pass_id;
  }

  if ($q_status === '1' || $q_status === '0') {
    $where[] = "q.is_active = :qact";
    $params[':qact'] = (int)$q_status;
  }

  if ($q_type !== '' && $q_type !== 'all') {
    $allowed = ['single_choice','multiple_choice','yesno','text','number'];
    if (in_array($q_type, $allowed, true)) {
      $where[] = "q.question_type = :qt";
      $params[':qt'] = $q_type;
    }
  }

  if ($only_issue) {
    $where[] = "(
      q.question_type IN ('single_choice','multiple_choice','yesno')
      AND NOT EXISTS (
        SELECT 1 FROM question_answers qa
        WHERE qa.question_id = q.id
          AND qa.is_active = 1
      )
    )";
  }

  $whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";

  $st = $pdo->prepare("SELECT COUNT(*) " . $from . $whereSql);
  $st->execute($params);
  $totalFiltered = (int)$st->fetchColumn();

  $select = "
    SELECT
      q.id, q.question_text, q.question_type, q.sort_order, q.is_active,
      q.image_media_id, q.file_id, q.youtube_url,
      q.passage_id, q.passage_seq, q.created_at,
      q.category_id AS question_category_id,

      p.title AS passage_title,
      p.passage_text,
      p.image_media_id AS passage_image_media_id,
      p.file_id        AS passage_file_id,
      p.youtube_url    AS passage_youtube_url,
      p.sort_order AS passage_sort_order,
      p.is_active AS passage_is_active,
      p.category_id AS passage_category_id,

      c.name AS category_name,
      c.sort_order AS category_sort_order,
      c.is_active AS category_is_active,
      c.id AS category_id
  ";

  if ($order_mode === 'master') {
    $order = "
      ORDER BY
        (q.sort_order IS NULL) ASC,
        q.sort_order ASC,
        q.id ASC
    ";
  } else {
    $order = "
      ORDER BY
        COALESCE(c.sort_order, 999999), COALESCE(c.id, 999999),
        COALESCE(p.sort_order, 999999), COALESCE(p.id, 999999),
        COALESCE(q.passage_seq, q.sort_order, q.id), q.id
    ";
  }

  $limitSql = "";
  if ($per > 0) $limitSql = " LIMIT :lim OFFSET :ofs ";

  $sql = $select . $from . $whereSql . $order . $limitSql;

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) {
    if (is_int($v)) $st->bindValue($k, $v, PDO::PARAM_INT);
    else $st->bindValue($k, $v, PDO::PARAM_STR);
  }
  if ($per > 0) {
    $st->bindValue(':lim', $per, PDO::PARAM_INT);
    $st->bindValue(':ofs', $offset, PDO::PARAM_INT);
  }
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $qids = [];
  foreach ($rows as $r) $qids[] = (int)$r['id'];
  $qids = array_values(array_unique(array_filter($qids)));

  if ($qids) {
    $in = implode(',', array_fill(0, count($qids), '?'));
    $ansWhere = " WHERE qa.question_id IN ($in) ";
    if ($a_status === '1' || $a_status === '0') {
      $ansWhere .= " AND qa.is_active = " . ((int)$a_status) . " ";
    }

    $ansSql = "
      SELECT qa.id, qa.question_id, qa.answer_text, qa.score, qa.sort_order, qa.is_active
      FROM question_answers qa
      $ansWhere
      ORDER BY qa.question_id ASC, qa.sort_order ASC, qa.id ASC
    ";

    $st = $pdo->prepare($ansSql);
    foreach ($qids as $i => $idv) $st->bindValue($i+1, $idv, PDO::PARAM_INT);
    $st->execute();
    $ansRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($ansRows as $a) {
      $qid = (int)$a['question_id'];
      if (!isset($answersByQ[$qid])) $answersByQ[$qid] = [];
      $answersByQ[$qid][] = $a;
    }
  }

  $mediaIds = [];
  $fileIds  = [];
  foreach ($rows as $r) {
    foreach (['image_media_id','passage_image_media_id'] as $k) {
      if (!empty($r[$k])) $mediaIds[(int)$r[$k]] = true;
    }
    foreach (['file_id','passage_file_id'] as $k) {
      if (!empty($r[$k])) $fileIds[(int)$r[$k]] = true;
    }
  }
  $mediaIds = array_keys($mediaIds);
  $fileIds  = array_keys($fileIds);

  if ($mediaIds) {
    $in = implode(',', array_fill(0, count($mediaIds), '?'));
    $st = $pdo->prepare("SELECT * FROM media WHERE id IN ($in) AND visibility='public' AND storage_disk='public' AND access_scope='public'");
    foreach ($mediaIds as $i => $idv) $st->bindValue($i+1, $idv, PDO::PARAM_INT);
    $st->execute();
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $m) {
      $mid = (int)($m['id'] ?? 0);
      if ($mid > 0) $mediaMap[$mid] = $m;
    }
  }

  if ($fileIds) {
    $in = implode(',', array_fill(0, count($fileIds), '?'));
    $st = $pdo->prepare("SELECT * FROM `file` WHERE id IN ($in) AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1");
    foreach ($fileIds as $i => $idv) $st->bindValue($i+1, $idv, PDO::PARAM_INT);
    $st->execute();
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $f) {
      $fid = (int)($f['id'] ?? 0);
      if ($fid > 0) $fileMap[$fid] = $f;
    }
  }
} catch (Throwable $e) {
  error_log("[admin/quiz/dump] " . $e->getMessage());
  $errors[] = "Database error while loading the quality check. Check error_log.";
}

$quizActive = ((int)($quiz['is_active'] ?? 0) === 1);
$isPrimary  = ((int)($quiz['is_primary'] ?? 0) === 1);

$showFrom = ($per > 0 && $totalFiltered > 0) ? ($offset + 1) : 1;
$showTo   = ($per > 0) ? min($offset + $per, $totalFiltered) : $totalFiltered;
$totalPages = ($per > 0) ? max(1, (int)ceil($totalFiltered / $per)) : 1;
?>
<section class="adam-card">
  <div class="head">
    <div>
      <div class="crumbs">
        <a href="<?= h($base) ?>/?page=admin/quiz/quizzes">Quiz</a>
        <span class="sep">›</span>
        <span>Quality Check</span>
      </div>
      <h2 style="margin:6px 0 0">
        Quality Check — Quiz #<?= (int)$quiz_id ?> · <?= h($quiz['title'] ?? '') ?>
      </h2>
      <div class="sub">
        <?= $quizActive ? badge('Active Quiz','ok') : badge('Inactive Quiz','bad') ?>
        <?= $isPrimary ? badge('PRIMARY QUIZ','primary') : '' ?>
        <?php if (!empty($quiz['description'])): ?>
          <span class="desc" title="<?= h((string)($quiz['description'] ?? '')) ?>"><?= h(cut((string)($quiz['description'] ?? ''), 140)) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="right">
      <form method="post" action="<?= h($base) ?>/?page=admin/quiz/dump/export" class="qz-form-actions" aria-label="Export questions and answers">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="quiz_id" value="<?= (int)$quiz_id ?>">
        <select class="adam-input" name="category_id" aria-label="Export category">
          <option value="">Export all categories (grouped)</option>
          <?php foreach ($categories as $category): ?>
            <?php $exportCategoryId = (int)($category['id'] ?? 0); ?>
            <option value="<?= $exportCategoryId ?>" <?= ($cat_id !== '' && (int)$cat_id === $exportCategoryId) ? 'selected' : '' ?>>
              #<?= $exportCategoryId ?> · <?= h((string)($category['name'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="adam-button" type="submit">Export Questions &amp; Answers</button>
      </form>
      <a class="adam-button" href="<?= h($base) ?>/?page=admin/quiz/master&quiz_id=<?= (int)$quiz_id ?>">← Back to Master Builder</a>
    </div>
  </div>

  <div class="meta">
    Excel export includes active and inactive questions and answers, scores, answer keys, passages, and public attachment URLs. It is ordered by category and passage; choose one category above to create a category-specific file.
  </div>

  <?php if ($messages): ?>
    <div class="adam-alert success">
      <?php foreach ($messages as $m): ?><div><?= h($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="adam-alert error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
      <div class="dbg">Debug: session user_id = <b><?= (int)$uid ?></b></div>
    </div>
  <?php endif; ?>

  <form class="filters" method="get" action="<?= h($base) ?>/">
    <input type="hidden" name="page" value="admin/quiz/dump">
    <input type="hidden" name="quiz_id" value="<?= (int)$quiz_id ?>">

    <div class="frow">
       <input class="adam-input" name="q" value="<?= h($q) ?>" placeholder="Search question ID, question text, answers, category name, or passage title/text..." />

      <select class="adam-input" name="cat_id">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
          <?php
            $cid = (int)$c['id'];
            $nm = (string)$c['name'];
            $act = ((int)$c['is_active'] === 1) ? 'Active' : 'Inactive';
            $sel = ($cat_id !== '' && (int)$cat_id === $cid) ? 'selected' : '';
          ?>
          <option value="<?= $cid ?>" <?= $sel ?>>#<?= $cid ?> · <?= h($nm) ?> (<?= $act ?>)</option>
        <?php endforeach; ?>
      </select>

      <select class="adam-input" name="pass_id">
        <option value="">All Passages</option>
        <?php foreach ($passages as $p): ?>
          <?php
            $pid = (int)$p['id'];
            $ttl = trim((string)($p['title'] ?? ''));
            $ttl = $ttl !== '' ? $ttl : ('Passage #' . $pid);
            $act = ((int)$p['is_active'] === 1) ? 'Active' : 'Inactive';
            $sel = ($pass_id !== '' && (int)$pass_id === $pid) ? 'selected' : '';
          ?>
          <option value="<?= $pid ?>" <?= $sel ?>>#<?= $pid ?> · <?= h(cut($ttl, 70)) ?> (<?= $act ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="frow">
      <select class="adam-input" name="q_status">
        <option value="all" <?= $q_status==='all'?'selected':'' ?>>Questions: All Statuses</option>
        <option value="1" <?= $q_status==='1'?'selected':'' ?>>Questions: Active</option>
        <option value="0" <?= $q_status==='0'?'selected':'' ?>>Questions: Inactive</option>
      </select>

      <select class="adam-input" name="a_status">
        <option value="all" <?= $a_status==='all'?'selected':'' ?>>Answers: All Statuses</option>
        <option value="1" <?= $a_status==='1'?'selected':'' ?>>Answers: Active</option>
        <option value="0" <?= $a_status==='0'?'selected':'' ?>>Answers: Inactive</option>
      </select>

      <select class="adam-input" name="q_type">
        <option value="all" <?= $q_type==='all'?'selected':'' ?>>Type: All</option>
        <option value="single_choice" <?= $q_type==='single_choice'?'selected':'' ?>>Single Choice</option>
        <option value="multiple_choice" <?= $q_type==='multiple_choice'?'selected':'' ?>>Multiple Choice</option>
        <option value="yesno" <?= $q_type==='yesno'?'selected':'' ?>>Yes / No</option>
        <option value="text" <?= $q_type==='text'?'selected':'' ?>>Text</option>
        <option value="number" <?= $q_type==='number'?'selected':'' ?>>Number</option>
      </select>

      <select class="adam-input" name="order" title="List order">
        <option value="master" <?= $order_mode==='master'?'selected':'' ?>>Order: Master (Position)</option>
        <option value="cat" <?= $order_mode==='cat'?'selected':'' ?>>Order: Category → Passage</option>
      </select>

      <select class="adam-input" name="per">
        <?php $opts = ['25'=>'25/page','50'=>'50/page','100'=>'100/page','200'=>'200/page','all'=>'All (may be slow)']; ?>
        <?php foreach ($opts as $k => $lbl): ?>
          <option value="<?= h((string)$k) ?>" <?= ($per_raw===(string)$k)?'selected':'' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="ck">
        <input type="checkbox" name="issue" value="1" <?= $only_issue?'checked':'' ?>>
        Issues only (choice questions without active answers)
      </label>

      <label class="ck">
        <input type="checkbox" name="show_passage" value="1" <?= $show_passage_text?'checked':'' ?>>
        Show passage text
      </label>

      <button class="adam-button" type="submit">Filter</button>
      <a class="adam-button ghost" href="<?= h($base) ?>/?page=admin/quiz/dump&quiz_id=<?= (int)$quiz_id ?>">Reset</a>
    </div>
  </form>

  <div class="meta">
    <div>
      <b><?= (int)$totalFiltered ?></b> questions found
      <?php if ($per > 0): ?>
        · showing <b><?= (int)$showFrom ?></b>-<b><?= (int)$showTo ?></b>
        · page <b><?= (int)$page ?></b>/<b><?= (int)$totalPages ?></b>
      <?php endif; ?>
      · order: <b><?= $order_mode==='master' ? 'Master (Position)' : 'Category → Passage' ?></b>
    </div>

    <?php if ($per > 0 && $totalPages > 1): ?>
      <div class="pager">
        <?php $prev = max(1, $page - 1); $next = min($totalPages, $page + 1); ?>
        <a class="pg <?= ($page<=1?'dis':'') ?>" href="<?= ($page<=1?'#':h(build_url(['p'=>$prev]))) ?>">‹ Previous</a>
        <a class="pg <?= ($page>=$totalPages?'dis':'') ?>" href="<?= ($page>=$totalPages?'#':h(build_url(['p'=>$next]))) ?>">Next ›</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No questions match these filters.</div>
  <?php else: ?>

    <?php
      $curCat = null;
      $curPass = null;
      $no = ($per > 0) ? $offset : 0;

      foreach ($rows as $r):
        $no++;

        $qid = (int)$r['id'];

        $catName = trim((string)($r['category_name'] ?? ''));
        $catId   = (int)($r['category_id'] ?? 0);
        $catAct  = (int)($r['category_is_active'] ?? 0);

        $passId  = (int)($r['passage_id'] ?? 0);
        $passTitle = trim((string)($r['passage_title'] ?? ''));
        $passTitle = $passTitle !== '' ? $passTitle : ($passId > 0 ? 'Passage #'.$passId : 'No Passage');
        $passAct = (int)($r['passage_is_active'] ?? 0);

        $qAct = (int)($r['is_active'] ?? 0);
        $qType = (string)($r['question_type'] ?? '');
        $pseq = $r['passage_seq'] !== null ? (int)$r['passage_seq'] : null;

        $pos = $r['sort_order'] !== null ? (int)$r['sort_order'] : null;

        $isChoice = in_array($qType, ['single_choice','multiple_choice','yesno'], true);

        $ans = $answersByQ[$qid] ?? [];
        $activeAnsCount = 0;
        foreach ($ans as $a) if ((int)$a['is_active'] === 1) $activeAnsCount++;
        $isProblem = ($isChoice && $activeAnsCount <= 0);

        $catKey  = ($catId > 0) ? $catId : 0;
        $passKey = ($passId > 0) ? $passId : 0;

        if ($curCat !== $catKey):
          if ($curCat !== null) {
            if ($curPass !== null) echo "</div></div>";
          }
          $curCat = $catKey;
          $curPass = null;

          $catLabel = ($catId > 0 && $catName !== '') ? ("#{$catId} · {$catName}") : "No Category";
          $catBadge = ($catId > 0)
            ? (((int)$catAct===1) ? badge('Active Category','ok') : badge('Inactive Category','bad'))
            : badge('—','muted');
    ?>
      <div class="block cat">
        <div class="block-head">
          <div class="title">Category: <?= h($catLabel) ?></div>
          <div class="tags"><?= $catBadge ?></div>
        </div>
    <?php endif; ?>

    <?php
        if ($curPass !== $passKey):
          if ($curPass !== null) echo "</div></div>";
          $curPass = $passKey;

          $passBadge = ($passId > 0)
            ? (((int)$passAct===1) ? badge('Active Passage','ok') : badge('Inactive Passage','bad'))
            : badge('—','muted');

          $ptext = (string)($r['passage_text'] ?? '');
          $passImg  = !empty($r['passage_image_media_id']) ? (int)$r['passage_image_media_id'] : null;
          $passFile = !empty($r['passage_file_id']) ? (int)$r['passage_file_id'] : null;
          $passYt   = (string)($r['passage_youtube_url'] ?? '');
    ?>
      <div class="block pass">
        <div class="block-head">
          <div class="title">
            Passage: <?= h($passTitle) ?>
            <?= ($passId>0 ? '<span class="muted">(#'.(int)$passId.')</span>' : '') ?>
          </div>
          <div class="tags"><?= $passBadge ?></div>
        </div>

        <?php if (trim($ptext) !== ''): ?>
          <div class="passage-text rich <?= $show_passage_text ? '' : 'clamp' ?>">
            <?= qz_rich_html($ptext) ?>
          </div>
        <?php endif; ?>

        <?php
          $passMediaHtml = qz_render_media_block($passYt, $passImg, $passFile, $mediaMap, $fileMap, $base);
          if ($passMediaHtml !== '') echo $passMediaHtml;
        ?>

        <div class="q-list">
    <?php endif; ?>

      <?php
        $questionEditorUrl = $base . '/?page=admin/quiz/questions&do=edit&id=' . (int)$qid . '&quiz_id=' . (int)$quiz_id;
      ?>

      <div class="q-card <?= $isProblem ? 'problem' : '' ?>">
        <div class="q-top">
          <div class="q-left">
            <div class="q-no">
              Position <?= $pos !== null ? (int)$pos : '—' ?>
              <span class="muted" style="margin-left:8px">(#<?= (int)$no ?>)</span>
            </div>
            <div class="q-meta">
              <span class="muted">QID:</span> <b><?= (int)$qid ?></b>
              <span class="dot">•</span>
               <span class="muted">Type:</span> <b><?= h(str_replace('_', ' ', ucwords($qType, '_'))) ?></b>
              <?php if ($pseq !== null): ?>
                <span class="dot">•</span><span class="muted">passage_seq:</span> <b><?= (int)$pseq ?></b>
              <?php endif; ?>
            </div>
            <div class="q-links">
              <a class="link" href="<?= h($questionEditorUrl) ?>" target="_blank" rel="noopener">Edit Question &amp; Answers</a>
            </div>
          </div>

          <div class="q-right">
            <?= ($qAct===1) ? badge('Active Question','ok') : badge('Inactive Question','bad') ?>
            <?php if ($isProblem): ?>
              <?= badge('⚠ No active answers','warn') ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="q-text rich" id="q<?= (int)$qid ?>"><?= qz_rich_html((string)$r['question_text']) ?></div>

        <?php
          $qImg  = !empty($r['image_media_id']) ? (int)$r['image_media_id'] : null;
          $qFile = !empty($r['file_id']) ? (int)$r['file_id'] : null;
          $qYt   = (string)($r['youtube_url'] ?? '');
          $qMediaHtml = qz_render_media_block($qYt, $qImg, $qFile, $mediaMap, $fileMap, $base);
          if ($qMediaHtml !== '') echo $qMediaHtml;
        ?>

        <div class="ans">
          <div class="ans-head">
            <b>Answers</b>
            <span class="muted">· total: <?= (int)count($ans) ?> · active: <?= (int)$activeAnsCount ?></span>
          </div>

          <?php if (!$ans): ?>
            <div class="ans-empty"><?= badge('No answers','warn') ?></div>
          <?php else: ?>
            <ol class="ans-list">
              <?php foreach ($ans as $i => $a): ?>
                <?php
                  $lbl = alpha_label((int)$i);
                  $aAct = ((int)$a['is_active'] === 1);
                  $score = (int)($a['score'] ?? 0);
                  $aText = (string)($a['answer_text'] ?? '');
                  $aid = (int)($a['id'] ?? 0);
                ?>
                <li class="ans-item <?= $aAct ? 'on' : 'off' ?> <?= ($score>0?'correct':'') ?>">
                  <div class="ans-line">
                    <span class="ans-lbl"><?= h($lbl) ?>.</span>
                    <span class="ans-txt rich"><?= qz_rich_html((string)$aText) ?></span>
                  </div>

                  <div class="ans-meta">
                    <span class="muted">ID:</span> <b><?= $aid ?></b>
                    <span class="dot">•</span>
                    <?= $aAct ? badge('Active','ok') : badge('Inactive','bad') ?>
                    <span class="dot">•</span>
                    <span class="muted">Score:</span> <b><?= (int)$score ?></b>
                    <span class="dot">•</span>
                    <span class="muted">Order:</span> <b><?= (int)($a['sort_order'] ?? 0) ?></b>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>

    <?php endforeach; ?>

    <?php
      if ($curPass !== null) echo "</div></div>";
      if ($curCat !== null) echo "</div>";
    ?>

  <?php endif; ?>
</section>

<script>
  window.MathJax = {
    tex: {
      inlineMath: [['\\(','\\)'], ['$', '$']],
      displayMath: [['\\[','\\]'], ['$$','$$']]
    },
    options: { skipHtmlTags: ['script','noscript','style','textarea','pre','code'] }
  };
</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js"></script>

<?php
