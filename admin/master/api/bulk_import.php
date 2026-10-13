<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail('Method not allowed', 405);
$in = jin();
require_csrf((string)($in['csrf_token'] ?? ''));
$quiz_id = (int)($in['quiz_id'] ?? 0);
$qs = $in['questions'] ?? null;
if ($quiz_id<=0 || !is_array($qs) || !count($qs) || count($qs) > 500) jfail('Invalid payload', 400);
$strict = (int)($in['strict'] ?? 1) === 1;
require_quiz_exists($quiz_id);
$allowedTypes = ['single_choice','multiple_choice','yesno','text','number'];
function is_valid_youtube($url): bool {
  $url = trim((string)$url);
  if ($url === '') return true;
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  return in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true);
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
  $st->execute([':c' => $col]);
  return (bool)$st->fetch(PDO::FETCH_ASSOC);
}
function norm_name(?string $s): string {
  $s = trim((string)$s);
  return preg_replace('/\s+/u', ' ', $s) ?? '';
}
try {
  $san = []; $mids = []; $fids = []; $catIds = []; $catNames = []; $passIds = []; $passTitles = [];
  foreach ($qs as $i => $q) {
    if (!is_array($q)) continue;
    $qt = trim((string)($q['question_text'] ?? ''));
    if ($qt === '') continue;
    $type = (string)($q['question_type'] ?? 'single_choice');
    if (!in_array($type, $allowedTypes, true)) $type = 'single_choice';
    $act = (int)($q['is_active'] ?? 1);
    if ($act !== 0 && $act !== 1) $act = 1;
    $mid = $q['image_media_id'] ?? null;
    if ($mid === '' || $mid === null) $mid = null; else $mid = (int)$mid;
    if ($mid !== null && $mid <= 0) $mid = null;
    $fid = $q['file_id'] ?? null;
    if ($fid === '' || $fid === null) $fid = null; else $fid = (int)$fid;
    if ($fid !== null && $fid <= 0) $fid = null;
    $yt = trim((string)($q['youtube_url'] ?? ''));
    if ($yt === '') $yt = null;
    if ($yt !== null && !is_valid_youtube($yt)) jfail("Bulk import: invalid YouTube URL in item #".($i+1), 422);
    if ($mid !== null) $mids[] = $mid;
    if ($fid !== null) $fids[] = $fid;
    $rawCatId = $q['category_id'] ?? null;
    $rawCatName = $q['category_name'] ?? ($q['category'] ?? null);
    $cat_id = null; $cat_name = '';
    if ($rawCatId !== null && $rawCatId !== '') { $cat_id = (int)$rawCatId; if ($cat_id > 0) $catIds[] = $cat_id; else $cat_id = null; }
    else { $cat_name = norm_name($rawCatName); if ($cat_name !== '') $catNames[] = $cat_name; }
    $rawPassId = $q['passage_id'] ?? null;
    $rawPassTitle = $q['passage_title'] ?? ($q['passage'] ?? null);
    $pass_id = null; $pass_title = '';
    if ($rawPassId !== null && $rawPassId !== '') { $pass_id = (int)$rawPassId; if ($pass_id > 0) $passIds[] = $pass_id; else $pass_id = null; }
    else { $pass_title = norm_name($rawPassTitle); if ($pass_title !== '') $passTitles[] = $pass_title; }
    $pass_seq = $q['passage_seq'] ?? null;
    if ($pass_seq === '' || $pass_seq === null) $pass_seq = null; else { $pass_seq = (int)$pass_seq; if ($pass_seq <= 0) $pass_seq = null; }
    $answers = $q['answers'] ?? []; $ansSan = [];
    if (is_array($answers)) {
      foreach ($answers as $a) {
        if (!is_array($a)) continue;
        $txt = trim((string)($a['answer_text'] ?? '')); if ($txt === '') continue;
        $score = (int)($a['score'] ?? 0); $score = max(0, min(100, $score));
        $aact = (int)($a['is_active'] ?? 1); if ($aact !== 0 && $aact !== 1) $aact = 1;
        $ansSan[] = ['answer_text'=>$txt, 'score'=>$score, 'is_active'=>$aact];
      }
    }
    $san[] = ['question_text'=>$qt, 'question_type'=>$type, 'is_active'=>$act, 'image_media_id'=>$mid, 'file_id'=>$fid, 'youtube_url'=>$yt, 'category_id'=>$cat_id, 'category_name'=>$cat_name, 'passage_id'=>$pass_id, 'passage_title'=>$pass_title, 'passage_seq'=>$pass_seq, 'answers'=>$ansSan];
  }
  if (!count($san)) jfail('Bulk import: no valid data found.', 422);
  $mids = array_values(array_unique(array_filter($mids, fn($x)=>$x>0)));
  if ($mids) {
    $place = implode(',', array_fill(0, count($mids), '?')); $st = $pdo->prepare("SELECT id FROM media WHERE id IN ($place) AND visibility='public' AND storage_disk='public' AND access_scope='public'"); $st->execute($mids);
    $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []); $miss = array_values(array_diff($mids, $found));
    if ($miss) jfail('Bulk import: media not found: '.implode(',',$miss), 422);
  }
  $fids = array_values(array_unique(array_filter($fids, fn($x)=>$x>0)));
  if ($fids) {
    $place = implode(',', array_fill(0, count($fids), '?')); $st = $pdo->prepare("SELECT id FROM `file` WHERE id IN ($place) AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1"); $st->execute($fids); $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []); $miss = array_values(array_diff($fids, $found)); if ($miss) jfail('Bulk import: public files not found: '.implode(',',$miss), 422);
  }
  $catIds = array_values(array_unique(array_filter($catIds, fn($x)=>$x>0)));
  $passIds = array_values(array_unique(array_filter($passIds, fn($x)=>$x>0)));
  $catNames = array_values(array_unique(array_filter(array_map('norm_name', $catNames), fn($x)=>$x!=='')));
  $passTitles = array_values(array_unique(array_filter(array_map('norm_name', $passTitles), fn($x)=>$x!=='')));
  $validCatIds = [];
  if ($catIds) { $place = implode(',', array_fill(0, count($catIds), '?')); $st = $pdo->prepare("SELECT id FROM question_categories WHERE quiz_id=? AND id IN ($place)"); $st->execute(array_merge([$quiz_id], $catIds)); $validCatIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []); $miss = array_values(array_diff($catIds, $validCatIds)); if ($miss && $strict) jfail('Bulk import: category_id does not belong to this quiz: '.implode(',',$miss), 422); }
  $catNameToId = [];
  if ($catNames) { $place = implode(',', array_fill(0, count($catNames), '?')); $st = $pdo->prepare("SELECT id, name FROM question_categories WHERE quiz_id=? AND name IN ($place)"); $st->execute(array_merge([$quiz_id], $catNames)); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; foreach ($rows as $r) { $catNameToId[norm_name($r['name'])] = (int)$r['id']; } if ($strict) { $miss = []; foreach ($catNames as $nm) if (!isset($catNameToId[$nm])) $miss[] = $nm; if ($miss) jfail('Bulk import: category_name was not found in this quiz: '.implode(' | ', $miss), 422); } }
  $validPassIds = [];
  if ($passIds) { $place = implode(',', array_fill(0, count($passIds), '?')); $st = $pdo->prepare("SELECT id FROM question_passages WHERE quiz_id=? AND id IN ($place)"); $st->execute(array_merge([$quiz_id], $passIds)); $validPassIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []); $miss = array_values(array_diff($passIds, $validPassIds)); if ($miss && $strict) jfail('Bulk import: passage_id does not belong to this quiz: '.implode(',',$miss), 422); }
  $passTitleToId = [];
  if ($passTitles) { $place = implode(',', array_fill(0, count($passTitles), '?')); $st = $pdo->prepare("SELECT id, title FROM question_passages WHERE quiz_id=? AND title IN ($place)"); $st->execute(array_merge([$quiz_id], $passTitles)); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; foreach ($rows as $r) { $passTitleToId[norm_name($r['title'])] = (int)$r['id']; } if ($strict) { $miss = []; foreach ($passTitles as $tt) if (!isset($passTitleToId[$tt])) $miss[] = $tt; if ($miss) jfail('Bulk import: passage_title was not found in this quiz: '.implode(' | ', $miss), 422); } }
  foreach ($san as &$q) {
    if (!empty($q['category_id'])) { $cid = (int)$q['category_id']; if (!in_array($cid, $validCatIds, true)) $q['category_id'] = null; }
    elseif (!empty($q['category_name'])) { $nm = norm_name($q['category_name']); $q['category_id'] = $catNameToId[$nm] ?? null; }
    if (!empty($q['passage_id'])) { $pid = (int)$q['passage_id']; if (!in_array($pid, $validPassIds, true)) $q['passage_id'] = null; }
    elseif (!empty($q['passage_title'])) { $tt = norm_name($q['passage_title']); $q['passage_id'] = $passTitleToId[$tt] ?? null; }
    if ($q['passage_seq'] !== null) { $ps = (int)$q['passage_seq']; $q['passage_seq'] = ($ps > 0) ? $ps : null; }
  }
  unset($q);
  $hasPassageId = column_exists($pdo, 'questions', 'passage_id');
  $hasPassageSeq = column_exists($pdo, 'questions', 'passage_seq');
  $hasLegacyCategory = column_exists($pdo, 'questions', 'category_id');
  $user = qz_api_user();
  $pdo->beginTransaction();
  qz_lock_quiz_for_workspace_mutation($pdo, $quiz_id, (int)$user['id']);
  $sort = qz_append_sort_order($pdo, 'questions', $quiz_id);
  $cols = ['quiz_id','created_by','question_text','question_type','sort_order','is_active','image_media_id','file_id','youtube_url'];
  $vals = [':quiz_id',':created_by',':qt',':type',':sort',':act',':mid',':fid',':yt'];
  if ($hasPassageId) { $cols[] = 'passage_id'; $vals[] = ':passage_id'; }
  if ($hasPassageSeq) { $cols[] = 'passage_seq'; $vals[] = ':passage_seq'; }
  if ($hasLegacyCategory) { $cols[] = 'category_id'; $vals[] = ':category_id'; }
  $qIns = $pdo->prepare("INSERT INTO questions (".implode(',',$cols).") VALUES (".implode(',',$vals).")");
  $aIns = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, score, sort_order, is_active) VALUES (:qid, :txt, :score, :sort, :act)");
  $inserted = 0;
  foreach ($san as $q) {
    $params = [':quiz_id'=>$quiz_id, ':created_by'=>(int)$user['id'], ':qt'=>$q['question_text'], ':type'=>$q['question_type'], ':sort'=>$sort, ':act'=>$q['is_active'], ':mid'=>$q['image_media_id'], ':fid'=>$q['file_id'], ':yt'=>$q['youtube_url']];
    if ($hasPassageId) $params[':passage_id'] = $q['passage_id'] ?? null;
    if ($hasPassageSeq) $params[':passage_seq'] = $q['passage_seq'] ?? null;
    if ($hasLegacyCategory) $params[':category_id'] = $q['category_id'] ?? null;
    $qIns->execute($params);
    $qid = (int)$pdo->lastInsertId();
    $asort = 1;
    foreach (($q['answers'] ?? []) as $a) { $aIns->execute([':qid'=>$qid, ':txt'=>$a['answer_text'], ':score'=>$a['score'], ':sort'=>$asort, ':act'=>$a['is_active']]); $asort++; }
    $inserted++;
  }
  $pdo->commit();
  jok(['inserted'=>$inserted, 'strict'=>$strict]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jfail('DB error: '.$e->getMessage(), 500);
}
