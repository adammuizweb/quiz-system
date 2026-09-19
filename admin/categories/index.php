<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) { echo '<div class="adam-alert error">Missing or invalid quiz_id</div>'; return; }
$quizAccess = qz_require_quiz_access($pdo, $quiz_id);
$user = qz_admin_user($pdo);

$stmt = $pdo->prepare("
  SELECT q.id, q.title, q.created_by, u.name AS owner_name, u.email AS owner_email
  FROM quizzes q
  LEFT JOIN users u ON u.id = q.created_by
  WHERE q.id = :id
  LIMIT 1
");
$stmt->execute([':id'=>$quiz_id]);
$quiz = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$quiz) { http_response_code(404); exit('Quiz not found / no access'); }

$on = trim((string)($quiz['owner_name'] ?? ''));
$oe = trim((string)($quiz['owner_email'] ?? ''));
$ownerLine = ($on || $oe)
  ? ('Owner: ' . ($on ? h($on) : 'User#'.(int)$quiz['created_by']) . ($oe ? (' <span style="opacity:.75">(' . h($oe) . ')</span>') : ''))
  : ('Owner: User#'.(int)$quiz['created_by']);

$catStmt = $pdo->prepare("
  SELECT c.*,
    (SELECT COUNT(*) FROM question_passages p WHERE p.quiz_id=c.quiz_id AND p.category_id=c.id) AS passage_count,
    (SELECT COUNT(*) FROM questions q JOIN question_passages p ON p.id=q.passage_id WHERE p.quiz_id=c.quiz_id AND p.category_id=c.id) AS question_count_passage,
    (SELECT COUNT(*) FROM questions q WHERE q.quiz_id=c.quiz_id AND q.category_id=c.id) AS question_count_legacy,
    (SELECT COUNT(*) FROM questions q LEFT JOIN question_passages p ON p.id=q.passage_id WHERE q.quiz_id=c.quiz_id AND q.category_id=c.id AND (q.passage_id IS NULL OR p.id IS NULL OR p.category_id <> c.id)) AS legacy_unmapped_count
  FROM question_categories c
  WHERE c.quiz_id = :qid
  ORDER BY CASE WHEN c.sort_order IS NULL THEN 1 ELSE 0 END, c.sort_order ASC, c.id ASC
");
$catStmt->execute([':qid'=>$quiz_id]);
$categories = $catStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$messages = []; $errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode((string)$_GET['err']);

$passUrl = "$base/?page=admin/quiz/passages/index&quiz_id=$quiz_id";
$qUrl = "$base/?page=admin/quiz/questions&quiz_id=$quiz_id";
$masterUrl = "$base/?page=admin/quiz/master&quiz_id=$quiz_id";
$addUrl = "$base/?page=admin/quiz/categories/add&quiz_id=$quiz_id";
$backQuizzes = "$base/?page=admin/quiz/quizzes";
?>
<section class="adam-card qz-card">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title'],'url'=>$masterUrl],['label'=>'Kategori']]); ?>
  <div class="qz-head">
    <div>
      <h2 style="margin:0">Kategori — <?= h($quiz['title']) ?></h2>
      <div class="qz-meta">Quiz ID: <b><?= $quiz_id ?></b><span class="dot">•</span><?= $ownerLine ?></div>
    </div>
    <div class="qz-toolbar">
      <a class="adam-button" href="<?= h($addUrl) ?>">+ Tambah Kategori</a>
      <a class="adam-button ghost" href="<?= h($masterUrl) ?>">← Master</a>
      <a class="adam-button ghost" href="<?= h($backQuizzes) ?>">← Quiz</a>
    </div>
  </div>
  <?php if ($messages): ?><div class="adam-alert success"><?php foreach($messages as $m):?><div><?=h($m)?></div><?php endforeach;?></div><?php endif; ?>
  <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>
  <table class="qz-table">
    <thead><tr><th width="70">ID</th><th>Nama</th><th width="120">Passages</th><th width="220">Soal</th><th width="140">Min Soal</th><th width="140">Max Soal</th><th width="120">Shuffle</th><th width="120">Sort</th><th width="120">Status</th><th width="220">Aksi</th></tr></thead>
    <tbody>
      <?php if (!$categories): ?><tr><td colspan="10" style="padding:.9rem">Belum ada kategori.</td></tr>
      <?php else: foreach ($categories as $c):
        $canMutate = qz_can_mutate_resource($pdo, (int)$user['id'], $quizAccess, $c);
        $isActive = (int)($c['is_active']??0)===1;
        $sp = (int)($c['shuffle_passages']??1)===1;
        $sq = (int)($c['shuffle_questions']??1)===1;
        $sort = ($c['sort_order'] !== null && $c['sort_order'] !== '') ? (int)$c['sort_order'] : null;
        $minq = max(0, (int)($c['min_questions']??0));
        $maxq = ($c['max_questions'] !== null && $c['max_questions'] !== '') ? (int)$c['max_questions'] : null;
        $passageCount = (int)($c['passage_count']??0);
        $cntPassage = (int)($c['question_count_passage']??0);
        $cntLegacy = (int)($c['question_count_legacy']??0);
        $cntUnmapped = (int)($c['legacy_unmapped_count']??0);
        $warnMin = ($minq > 0 && $minq > $cntPassage);
        $warnMax = ($maxq !== null && $maxq > $cntPassage);
        $editUrl = "$base/?page=admin/quiz/categories/edit&id=".(int)$c['id']."&quiz_id=$quiz_id";
      ?><tr>
        <td class="col-id"><?=(int)$c['id']?></td>
        <td><div class="qtext"><?=h((string)$c['name'])?></div><?php if(!empty($c['description'])):?><div class="qsub"><span class="chip muted"><?=h(mb_strimwidth((string)$c['description'],0,140,'…','UTF-8'))?></span></div><?php endif;?></td>
        <td><span class="badge count"><?=$passageCount?></span></td>
        <td><div class="qsub"><span class="badge count">Dipakai: <?=$cntPassage?></span><span class="badge muted">Total: <?=$cntLegacy?></span><?php if($cntUnmapped>0):?><span class="badge warn">Belum: <?=$cntUnmapped?></span><?php endif;?></div></td>
        <td><?php if($minq>0):?><span class="badge <?=$warnMin?'warn':''?>"><?=$minq?></span><?php else:?><span class="badge muted">0</span><?php endif;?></td>
        <td><?php if($maxq!==null):?><span class="badge <?=$warnMax?'warn':''?>"><?=$maxq?></span><?php else:?><span class="badge muted">∞</span><?php endif;?></td>
        <td><div class="qsub"><span class="chip"><?=$sp?'🔀 P':'— P'?></span><span class="chip"><?=$sq?'🔀 Q':'— Q'?></span></div></td>
        <td><?php if($sort!==null):?><span class="badge"><?=$sort?></span><?php else:?><span class="badge muted">-</span><?php endif;?></td>
        <td><?php if($isActive):?><span class="badge ok">Aktif</span><?php else:?><span class="badge muted">Nonaktif</span><?php endif;?></td>
        <td class="actions"><?php if($canMutate):?><a class="link" href="<?=h($editUrl)?>">Edit</a><span class="sep">•</span><form method="post" action="<?=h($base)?>/?page=admin/quiz/categories/delete" style="display:inline"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><input type="hidden" name="quiz_id" value="<?=$quiz_id?>"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><button type="submit" class="link danger" data-confirm="Hapus kategori? Semua passage akan lepas (category_id jadi NULL)." data-confirm-variant="danger">Hapus</button></form><?php else:?><span class="badge muted">Read only</span><?php endif;?></td>
      </tr><?php endforeach; endif; ?>
    </tbody>
  </table>
  <div class="qz-footnote">
    <span class="dot">•</span><b>Dipakai</b> = soal /take (via <code>question_passages.category_id</code>)
    <span class="dot">•</span><b>Total</b> = soal via <code>questions.category_id</code> (legacy)
    <span class="dot">•</span><b>Belum</b> = Total yang belum nyantol ke passage kategori ini
  </div>
</section>
<?php
