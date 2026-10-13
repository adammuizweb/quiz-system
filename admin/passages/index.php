<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) { echo '<div class="adam-alert error">Missing or invalid quiz_id.</div>'; return; }
$quizAccess = qz_require_quiz_access($pdo, $quiz_id);
$user = qz_admin_user($pdo);

function qz_plain_excerpt(string $md, int $max = 220): string {
  $s = $md;
  $s = preg_replace('/```.*?```/s', ' ', $s);
  $s = preg_replace('/`([^`]+)`/', '$1', $s);
  $s = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', $s);
  $s = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $s);
  $s = preg_replace('/\$\$(.*?)\$\$/s', ' $1 ', $s);
  $s = preg_replace('/\\\\\[(.*?)\\\\\]/s', ' $1 ', $s);
  $s = preg_replace('/\\\\\((.*?)\\\\\)/s', ' $1 ', $s);
  $s = preg_replace('/\$(.+?)\$/s', ' $1 ', $s);
  $s = str_replace(['**','__','~~','*','_'], '', $s);
  $s = preg_replace('/^[\s>*#-]+/m', '', $s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return mb_strlen($s,'UTF-8') <= $max ? $s : mb_substr($s,0,$max-1,'UTF-8') . '…';
}

$stmt = $pdo->prepare("SELECT q.id, q.title, q.created_by, u.name AS owner_name, u.email AS owner_email FROM quizzes q LEFT JOIN users u ON u.id=q.created_by WHERE q.id=:id LIMIT 1");
$stmt->execute([':id'=>$quiz_id]);
$quiz = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$quiz) { http_response_code(404); exit('Quiz not found'); }

$stmt = $pdo->prepare("
  SELECT p.*, c.name AS category_name,
    (SELECT COUNT(*) FROM questions q WHERE q.quiz_id=p.quiz_id AND q.passage_id=p.id) AS question_count
  FROM question_passages p
  LEFT JOIN question_categories c ON c.id=p.category_id AND c.quiz_id=p.quiz_id
  WHERE p.quiz_id=:qid
  ORDER BY CASE WHEN p.sort_order IS NULL THEN 1 ELSE 0 END, p.sort_order ASC, p.id ASC
");
$stmt->execute([':qid'=>$quiz_id]);
$passages = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$messages = []; $errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode((string)$_GET['err']);

$on = trim((string)($quiz['owner_name'] ?? ''));
$oe = trim((string)($quiz['owner_email'] ?? ''));
$ownerLine = ($on || $oe)
  ? ('Owner: ' . ($on ? h($on) : 'User #'.(int)$quiz['created_by']) . ($oe ? (' <span class="text-muted">(' . h($oe) . ')</span>') : ''))
  : ('Owner: User #'.(int)$quiz['created_by']);

$catUrl = "$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id";
$qUrl = "$base/?page=admin/quiz/questions&quiz_id=$quiz_id";
$addUrl = "$base/?page=admin/quiz/passages/add&quiz_id=$quiz_id";
$masterUrl = "$base/?page=admin/quiz/master&quiz_id=$quiz_id";
$backUrl = "$base/?page=admin/quiz/quizzes";
?>
<section class="adam-card qz-card">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title'],'url'=>$masterUrl],['label'=>'Passages']]); ?>
  <div class="qz-head">
    <div>
      <h2 style="margin:0">Passages — <?=h($quiz['title'])?></h2>
      <div class="qz-meta">Quiz ID: <b><?=$quiz_id?></b><span class="dot">•</span><?=$ownerLine?></div>
      <div class="qz-subnav"><a class="pill" href="<?=h($qUrl)?>">Questions</a><a class="pill active" href="<?=h($base)?>/?page=admin/quiz/passages/index&quiz_id=<?=$quiz_id?>">Passages</a><a class="pill" href="<?=h($catUrl)?>">Categories</a></div>
    </div>
    <div class="qz-toolbar"><a class="adam-button" href="<?=h($addUrl)?>">+ Add Passage</a><a class="adam-button ghost" href="<?=h($masterUrl)?>">Master Builder</a><a class="adam-button ghost" href="<?=h($backUrl)?>">Back to Quizzes</a></div>
  </div>
  <?php if ($messages):?><div class="adam-alert success"><?php foreach($messages as $m):?><div><?=h($m)?></div><?php endforeach;?></div><?php endif;?>
  <?php if ($errors):?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif;?>
  <table class="qz-table">
    <thead><tr><th width="70">ID</th><th>Passage</th><th width="180">Category</th><th width="110">Questions</th><th width="180">Shuffle / Min / Max</th><th width="120">Status</th><th width="230">Actions</th></tr></thead>
    <tbody>
      <?php if (!$passages):?><tr><td colspan="7" style="padding:.9rem">No passages yet.</td></tr>
      <?php else: foreach ($passages as $p):
        $canMutate = qz_can_mutate_resource($pdo, (int)$user['id'], $quizAccess, $p);
        $ptitle = trim((string)($p['title'] ?? ''));
        $ptitle = $ptitle !== '' ? $ptitle : ('Passage #'.(int)$p['id']);
        $excerpt = qz_plain_excerpt((string)($p['passage_text'] ?? ''), 220);
        $chips = [];
        $catName = trim((string)($p['category_name'] ?? ''));
        if ($catName !== '') $chips[] = '<span class="chip" title="Category">🏷 '.h($catName).'</span>';
        if (!empty($p['image_media_id'])) $chips[] = '<span class="chip" title="Includes an image">🖼 Image</span>';
        if (!empty($p['file_id'])) $chips[] = '<span class="chip" title="Includes a file">📎 File</span>';
        if (!empty($p['youtube_url'])) $chips[] = '<span class="chip" title="Includes a video">▶ Video</span>';
        if ($p['sort_order'] !== null && $p['sort_order'] !== '') $chips[] = '<span class="chip muted" title="Order">#'.(int)$p['sort_order'].'</span>';
        $isActive = (int)($p['is_active']??0)===1;
        $shuffle = (int)($p['shuffle_questions']??1)===1;
        $minq = max(0, (int)($p['min_questions']??0));
        $maxq = ($p['max_questions'] !== null && $p['max_questions'] !== '') ? (int)$p['max_questions'] : null;
        $editUrl = "$base/?page=admin/quiz/passages/edit&id=".(int)$p['id']."&quiz_id=$quiz_id";
      ?><tr>
        <td class="col-id"><?=(int)$p['id']?></td>
        <td><div class="qtext"><?=h($ptitle)?></div><div class="ptext"><?=nl2br(h($excerpt))?></div><?php if($chips):?><div class="qsub"><?=implode(' ',$chips)?></div><?php endif;?></td>
        <td><?php if($catName!==''):?><span class="badge"><?=h($catName)?></span><?php else:?><span class="badge muted">(No category)</span><?php endif;?></td>
        <td><span class="badge count"><?=(int)($p['question_count']??0)?></span></td>
        <td><span class="badge <?=$shuffle?'ok':'muted'?>"><?=$shuffle?'Shuffle':'No shuffle'?></span><div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap"><span class="badge <?=$minq>0?'':'muted'?>">Min <?=$minq?:0?></span><span class="badge <?=$maxq?'':'muted'?>"><?=$maxq?'Max '.(int)$maxq:'Max ∞'?></span></div></td>
        <td><?php if($isActive):?><span class="badge ok">Active</span><?php else:?><span class="badge muted">Inactive</span><?php endif;?></td>
        <td class="actions"><?php if($canMutate):?><a class="link" href="<?=h($editUrl)?>">Edit</a><span class="sep">•</span><?php endif;?><a class="link" href="<?=h($qUrl)?>">Questions</a><?php if($canMutate):?><span class="sep">•</span><form method="post" action="<?=h($base)?>/?page=admin/quiz/passages/delete" style="display:inline"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><input type="hidden" name="quiz_id" value="<?=$quiz_id?>"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><button type="submit" class="link danger" data-confirm="Delete this passage? Linked questions will be detached." data-confirm-variant="danger">Delete</button></form><?php else:?><span class="sep">•</span><span class="badge muted">Read only</span><?php endif;?></td>
      </tr><?php endforeach; endif;?>
    </tbody>
  </table>
</section>
<?php
