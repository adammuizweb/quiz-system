<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.students.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz/students';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('quiz_redirect')) {
  function quiz_redirect(string $url): void {
    if (!headers_sent()) { while(ob_get_level()>0){@ob_end_clean();} header("Location: $url", true, 303); exit; }
    echo '<script>location.replace('.json_encode($url).');</script>'; exit;
  }
}

$do = trim((string)($_POST['do'] ?? $_GET['do'] ?? ''));

if ($do === 'delete') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
  $id = (int)($_POST['id'] ?? 0);
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if ($id > 0 && csrf_check($csrf)) {
    try {
      $pdo->prepare("DELETE FROM students WHERE id=:id LIMIT 1")->execute([':id'=>$id]);
      quiz_redirect("$base/?page=$pluginPage&msg=" . urlencode("Student deleted."));
    } catch (\Throwable $e) {}
  }
  quiz_redirect("$base/?page=$pluginPage&err=" . urlencode("Invalid request."));
  return;
}

if ($do === 'add' || $do === 'edit') {
  $id = (int)($_GET['id'] ?? 0);
  $editing = $do === 'edit' && $id > 0;
  $student = ['name'=>'','email'=>'','phone'=>''];
  if ($editing) {
    $st = $pdo->prepare("SELECT * FROM students WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $student = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$student) { echo '<div class="adam-alert error">Student not found.</div>'; return; }
  }
  $errors = [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'Invalid security token.';
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($name === '') $errors[] = 'Name is required.';
    if (!$errors) {
      if ($editing) {
        $pdo->prepare("UPDATE students SET name=:name,email=:email,phone=:phone WHERE id=:id")->execute([':name'=>$name,':email'=>$email?:null,':phone'=>$phone?:null,':id'=>$id]);
      } else {
        $cookieId = qz_unique_student_cookie_id($pdo);
        $pdo->prepare("INSERT INTO students (cookie_id,name,email,phone) VALUES (:cookie_id,:name,:email,:phone)")->execute([':cookie_id'=>$cookieId,':name'=>$name,':email'=>$email?:null,':phone'=>$phone?:null]);
      }
      quiz_redirect("$base/?page=$pluginPage&msg=" . urlencode($editing?"Student updated.":"Student added."));
    }
    $student = ['name'=>$name,'email'=>$email,'phone'=>$phone];
  } ?>
  <section class="adam-card qz-card qz-shell qz-editor-page qz-student-editor">
    <?php qz_admin_breadcrumb([['label'=>'Students','url'=>$base.'/?page='.$pluginPage],['label'=>$editing?'Edit Student':'Add Student']]); ?>
    <header class="qz-head qz-page-head">
      <div class="qz-page-head__copy">
        <div class="qz-kicker qz-eyebrow">Student details</div>
        <h1 class="qz-page-title"><?=$editing?'Edit Student':'Add Student'?></h1>
        <p class="qz-context qz-page-description"><?=$editing?'Update the Student identity and contact details without changing Attempt history.':'Register a Student to manage their identity and Attempt history in one place.'?></p>
      </div>
      <div class="qz-toolbar qz-page-actions"><a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Back to list</a></div>
    </header>
    <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>
    <form method="post" class="qz-editor-section qz-section" novalidate>
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <div class="qz-editor-section__head qz-section__head">
        <div><h2>Student identity</h2><p>Name is required. Email and phone number help with search and verification.</p></div>
      </div>
      <div class="qz-student-form-grid qz-form-grid qz-section__body">
        <label class="qz-student-form-name qz-field" for="student-name"><span>Full name</span><input class="adam-input" id="student-name" name="name" value="<?=h($student['name'])?>" autocomplete="name" required></label>
        <label class="qz-field" for="student-email"><span>Email</span><input class="adam-input" id="student-email" name="email" value="<?=h($student['email']??'')?>" type="email" autocomplete="email" placeholder="name@example.com"></label>
        <label class="qz-field" for="student-phone"><span>Phone number</span><input class="adam-input" id="student-phone" name="phone" value="<?=h($student['phone']??'')?>" type="tel" autocomplete="tel" inputmode="tel" placeholder="15551234567"></label>
      </div>
      <div class="qz-editor-actions qz-form-actions">
        <button class="adam-button" type="submit">Save Student</button>
        <a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Cancel</a>
      </div>
    </form>
  </section>
  <?php return;
}

$messages = []; $errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode((string)$_GET['err']);

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160, 'UTF-8');
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$params = []; $where = [];
if ($q !== '') {
  $where[] = '(s.name LIKE :q OR s.email LIKE :q OR s.phone LIKE :q)';
  $params[':q'] = '%'.$q.'%';
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$summaryStmt = $pdo->query("SELECT COUNT(DISTINCT s.id) AS total,COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN s.id END) AS with_attempts,COUNT(DISTINCT CASE WHEN a.status='submitted' THEN s.id END) AS with_submitted FROM students s LEFT JOIN quiz_attempts a ON a.student_id=s.id");
$summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: ['total'=>0,'with_attempts'=>0,'with_submitted'=>0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNum = min($pageNum, $totalPages);
$offset = ($pageNum - 1) * $perPage;

$sql = "SELECT s.*,
  (SELECT COUNT(*) FROM quiz_attempts a WHERE a.student_id = s.id) AS attempt_count,
  (SELECT COUNT(*) FROM quiz_attempts a WHERE a.student_id = s.id AND a.status='submitted') AS submitted_count,
  (SELECT MAX(a.last_activity_at) FROM quiz_attempts a WHERE a.student_id = s.id) AS last_activity_at,
  (SELECT a.id FROM quiz_attempts a WHERE a.student_id = s.id ORDER BY a.id DESC LIMIT 1) AS last_attempt_id,
  (SELECT a.attempt_alias FROM quiz_attempts a WHERE a.student_id = s.id ORDER BY a.id DESC LIMIT 1) AS last_attempt_alias
  FROM students s $whereSql ORDER BY s.id DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
$pageQuery = $_GET;
$pageQuery['page'] = $pluginPage;
unset($pageQuery['p'], $pageQuery['msg'], $pageQuery['err']);
$pageUrl = static function (int $target) use ($base, $pageQuery): string {
  $query = $pageQuery;
  $query['p'] = $target;
  return $base . '/?' . http_build_query($query);
};
$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $totalRows);
$formatDate = static function (mixed $value): string {
  $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
  return $timestamp !== false ? date('d/m/Y H:i', $timestamp) : '-';
};
?>
<section class="adam-card qz-card qz-shell qz-student-page" data-column-scope>
  <?php qz_admin_breadcrumb([['label'=>'Students']]); ?>
  <header class="qz-head qz-page-head">
    <div class="qz-page-head__copy">
      <div class="qz-kicker qz-eyebrow">Quiz participants</div>
      <h1 class="qz-page-title">Manage Students</h1>
      <p class="qz-meta qz-page-description">Manage participant identities, monitor engagement, and review Attempt history from one list.</p>
    </div>
    <div class="qz-toolbar qz-page-actions"><a class="adam-button" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=add">Add Student</a></div>
  </header>

  <div class="qz-stats qz-metric-grid" aria-label="Student summary">
    <div class="qz-stat qz-metric"><span>Total Students</span><strong><?=(int)$summary['total']?></strong></div>
    <div class="qz-stat qz-metric"><span>With Attempts</span><strong><?=(int)$summary['with_attempts']?></strong></div>
    <div class="qz-stat qz-metric"><span>With Submitted Attempts</span><strong><?=(int)$summary['with_submitted']?></strong></div>
    <div class="qz-stat qz-stat--result qz-metric"><span>Current results</span><strong><?=$totalRows?></strong></div>
  </div>

  <form method="get" class="qz-filterbar qz-student-filterbar qz-command-bar" role="search" aria-label="Search and configure the Student list">
    <input type="hidden" name="page" value="<?=$pluginPage?>">
    <label class="qz-field" for="students-q"><span>Search Students</span><input class="adam-input" id="students-q" name="q" value="<?=h($q)?>" placeholder="Name, email, or phone number"></label>
    <label class="qz-field" for="students-per"><span>Rows per page</span><select class="adam-input" id="students-per" name="per"><?php foreach ($perPageOptions as $option): ?><option value="<?=$option?>" <?=$perPage === $option ? 'selected':''?>><?=$option?></option><?php endforeach; ?></select></label>
    <div class="qz-filterbar__actions qz-form-actions"><button class="adam-button" type="submit">Apply</button><?php if($q!==''||$perPage!==25):?><a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Clear filters</a><?php endif;?></div>
  </form>
  <?php if ($messages): ?><div class="adam-alert success"><?php foreach($messages as $m):?><div><?=h($m)?></div><?php endforeach;?></div><?php endif; ?>
  <?php if ($errors): ?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif; ?>

  <div class="qz-resultbar qz-section__head">
    <span>Showing <b><?=$rangeStart?></b>-<b><?=$rangeEnd?></b> of <b><?=$totalRows?></b> Students</span>
    <div class="qz-view-controls"><details class="qz-column-picker" data-student-column-picker data-storage-key="quiz-student-columns-v1"><summary>Configure columns</summary><div class="qz-column-picker__panel"><div class="qz-column-picker__title">Visible columns</div><label><input type="checkbox" checked disabled> Student <small>Required</small></label><label><input type="checkbox" data-student-column="contact" checked> Contact</label><label><input type="checkbox" data-student-column="attempts" checked> Attempts</label><label><input type="checkbox" data-student-column="submitted" checked> Submitted</label><label><input type="checkbox" data-student-column="activity" checked> Last activity</label><label><input type="checkbox" checked disabled> Actions <small>Required</small></label></div></details></div>
  </div>

  <div class="table-scroll" role="region" aria-label="Student list" tabindex="0">
  <table class="qz-table qz-data-table qz-student-table" data-student-column-table>
    <thead><tr><th>Student</th><th data-column="contact">Contact</th><th data-column="attempts">Attempts</th><th data-column="submitted">Submitted</th><th data-column="activity">Last activity</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="qz-empty-cell"><div class="qz-empty-state"><strong><?=$q!==''?'No Students found':'No Students yet'?></strong><div><?=$q!==''?'Try another name, email, or phone number, then search again.':'Add the first Student to start managing participants and Attempt history.'?></div><?php if($q!==''):?><a class="adam-button ghost" href="<?=h($base)?>/?page=<?=$pluginPage?>">Clear search</a><?php else:?><a class="adam-button" href="<?=h($base)?>/?page=<?=$pluginPage?>&do=add">Add Student</a><?php endif;?></div></td></tr>
    <?php else: foreach ($rows as $r): $sid = (int)$r['id'];
      $lastAlias = trim((string)($r['last_attempt_alias']??''));
      $lastAttemptId = (int)($r['last_attempt_id']??0);
      $lastAttemptUrl = $lastAlias !== '' ? $base.'/?page=admin/quiz/attempts/view&a='.rawurlencode($lastAlias) : ($lastAttemptId > 0 ? $base.'/?page=admin/quiz/attempts/view&attempt_id='.$lastAttemptId : '');
      $email = trim((string)($r['email']??''));
      $phone = trim((string)($r['phone']??''));
    ?>
      <tr>
        <td class="qz-student-cell" data-label="Student"><div class="qz-student-name"><?=h($r['name'])?></div><div class="qz-student-meta">Registered <?=$formatDate($r['created_at']??null)?></div></td>
        <td data-label="Contact" data-column="contact"><div class="qz-student-contact"><?php if($email!==''):?><span><?=h($email)?></span><?php endif;?><?php if($phone!==''):?><span><?=h($phone)?></span><?php endif;?><?php if($email===''&&$phone===''):?><span class="muted">No contact details</span><?php endif;?></div></td>
        <td data-label="Attempts" data-column="attempts"><span class="badge count"><?=(int)$r['attempt_count']?></span></td>
        <td data-label="Submitted" data-column="submitted"><span class="badge <?=((int)$r['submitted_count']>0)?'ok':'muted'?>"><?=(int)$r['submitted_count']?></span></td>
        <td data-label="Last activity" data-column="activity"><span class="qz-student-activity"><?=$formatDate($r['last_activity_at']??null)?></span></td>
        <td class="qz-row-actions qz-student-actions" data-label="Actions">
          <div class="user-actions" data-qz-action-menu>
            <button type="button" class="user-actions-toggle" id="student-actions-toggle-<?=$sid?>" data-qz-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="student-actions-<?=$sid?>" aria-label="Actions for <?=h((string)$r['name'])?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
            </button>
            <div class="user-actions-menu" id="student-actions-<?=$sid?>" data-qz-action-panel role="menu" aria-labelledby="student-actions-toggle-<?=$sid?>" hidden>
              <a role="menuitem" data-qz-action-item href="<?=h($base)?>/?page=<?=$pluginPage?>&do=edit&id=<?=$sid?>"><?=svg_ico('pen')?> <span>Edit Student</span></a>
              <a role="menuitem" data-qz-action-item href="<?=h($base)?>/?page=admin/quiz/attempts&student_id=<?=$sid?>"><?=svg_ico('clipboard-list')?> <span>View Attempts</span></a>
              <?php if ($lastAttemptUrl): ?><a role="menuitem" data-qz-action-item href="<?=h($lastAttemptUrl)?>"><?=svg_ico('eye')?> <span>Latest Attempt</span></a><?php endif; ?>
              <form method="post" action="<?=h($base)?>/?page=<?=$pluginPage?>">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?=$sid?>">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <button type="submit" role="menuitem" class="is-danger" data-qz-action-item data-confirm="Delete this Student?\n\nName: <?=h($r['name'])?>\nAttempts: <?=(int)$r['attempt_count']?> (Submitted: <?=(int)$r['submitted_count']?>)\n\nAll of this Student's Attempts, Answers, Question sets, and related monitoring logs will also be deleted.\nThis action cannot be undone." data-confirm-variant="danger"><?=svg_ico('trash-2')?> <span>Delete Student</span></button>
              </form>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <?php if($totalPages>1):?><nav class="qz-pagination" aria-label="Student list pagination"><?php if($pageNum>1):?><a href="<?=h($pageUrl($pageNum-1))?>">Previous</a><?php else:?><span class="disabled">Previous</span><?php endif;?><div class="qz-pagination__pages"><?php for($number=max(1,$pageNum-2);$number<=min($totalPages,$pageNum+2);$number++):?><a href="<?=h($pageUrl($number))?>" <?=$number===$pageNum?'aria-current="page"':''?>><?=$number?></a><?php endfor;?></div><span>Page <?=$pageNum?> of <?=$totalPages?></span><?php if($pageNum<$totalPages):?><a href="<?=h($pageUrl($pageNum+1))?>">Next</a><?php else:?><span class="disabled">Next</span><?php endif;?></nav><?php endif;?>
</section>
<?php
