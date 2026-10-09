<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.dashboard.access');

require __DIR__ . '/_init.php';

$base = ADMIN_BASE_PATH;
$pluginPage = 'admin/quiz';
$primaryQuiz = null;
$user = qz_admin_user($pdo);
$canAccessWorkspace = qz_user_can($pdo, $user['id'], 'plugin.quiz.workspace.access');
$canManageStudents = $user['id'] > 0 && function_exists('user_can')
  && user_can($pdo, $user['id'], 'plugin.quiz.students.manage');
$canViewAttempts = $user['can_view_attempts'];
$canManageSettings = qz_user_can($pdo, $user['id'], 'plugin.quiz.settings.manage');

$stats = ['quizzes'=>0,'categories'=>0,'passages'=>0,'questions'=>0,'answers'=>0,'students'=>0,'attempts'=>0,'submitted'=>0];
try {
  if ($canAccessWorkspace) {
    if ($user['can_manage_any']) {
      $stats['quizzes'] = (int)$pdo->query("SELECT COUNT(*) FROM quizzes")->fetchColumn();
      $stats['categories'] = (int)$pdo->query("SELECT COUNT(*) FROM question_categories")->fetchColumn();
      $stats['passages'] = (int)$pdo->query("SELECT COUNT(*) FROM question_passages")->fetchColumn();
      $stats['questions'] = (int)$pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
      $stats['answers'] = (int)$pdo->query("SELECT COUNT(*) FROM question_answers")->fetchColumn();
    } else {
      $access = static function (string $alias, string $parameter) use ($pdo, $user): array {
        $params = [];
        return [qz_quiz_access_sql($pdo, $user['id'], $params, $alias, $parameter), $params];
      };
      $ownedCounts = [
        'quizzes' => "SELECT COUNT(*) FROM quizzes q WHERE %s",
        'categories' => "SELECT COUNT(*) FROM question_categories c JOIN quizzes q ON q.id=c.quiz_id WHERE %s",
        'passages' => "SELECT COUNT(*) FROM question_passages p JOIN quizzes q ON q.id=p.quiz_id WHERE %s",
        'questions' => "SELECT COUNT(*) FROM questions qu JOIN quizzes q ON q.id=qu.quiz_id WHERE %s",
        'answers' => "SELECT COUNT(*) FROM question_answers a JOIN questions qu ON qu.id=a.question_id JOIN quizzes q ON q.id=qu.quiz_id WHERE %s",
      ];
      foreach ($ownedCounts as $key => $template) {
        [$condition, $params] = $access('q', 'dashboard_' . $key . '_uid');
        $stmt = $pdo->prepare(sprintf($template, $condition));
        $stmt->execute($params);
        $stats[$key] = (int)$stmt->fetchColumn();
      }
    }
  }
  if ($canViewAttempts) {
    $stmt = $pdo->query("SELECT COUNT(DISTINCT student_id), COUNT(*), COALESCE(SUM(status='submitted'), 0) FROM quiz_attempts");
    [$stats['students'], $stats['attempts'], $stats['submitted']] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);
  }
} catch (\PDOException $e) {}
if ($canAccessWorkspace) try {
  $sql = "SELECT id, title FROM quizzes WHERE is_primary=1";
  $params = [];
  if (!$user['can_manage_any']) {
    $sql = "SELECT q.id, q.title FROM quizzes q WHERE q.is_primary=1 AND " . qz_quiz_access_sql($pdo, $user['id'], $params, 'q', 'dashboard_primary_uid');
  }
  $st = $pdo->prepare($sql . " ORDER BY id ASC LIMIT 1");
  $st->execute($params);
  $primaryQuiz = $st ? ($st->fetch(\PDO::FETCH_ASSOC) ?: null) : null;
} catch (\Throwable $e) {}
?>
<section class="adam-card qz-card qz-shell">
  <?php qz_admin_breadcrumb([['label'=>'Dashboard']]); ?>
  <header class="qz-page-head qz-head">
    <div class="qz-page-head__copy">
      <div class="qz-eyebrow qz-kicker">Quiz operations</div>
      <h1 class="qz-page-title">Quiz Dashboard</h1>
      <p class="qz-page-description qz-meta">Manage Quizzes, Students, Attempts, and public access in one workflow.</p>
    </div>
    <div class="qz-page-actions qz-toolbar">
      <?php if ($canAccessWorkspace): ?>
        <a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Quiz List</a>
      <?php endif; ?>
      <?php if ($canAccessWorkspace && $user['can_manage_quizzes']): ?>
        <a class="adam-button ghost" href="<?=h($base)?>/?page=admin/quiz/quizzes/add">Create Quiz</a>
      <?php endif; ?>
    </div>
  </header>

  <div class="qz-metric-grid qz-stats" aria-label="Quiz operations summary">
    <?php if ($canAccessWorkspace): ?>
      <div class="qz-metric qz-stat"><span>Available Quizzes</span><strong><?=$stats['quizzes']?></strong></div>
    <?php endif; ?>
    <?php if ($canViewAttempts): ?>
      <div class="qz-metric qz-stat"><span>Students with Attempts</span><strong><?=$stats['students']?></strong></div>
      <div class="qz-metric qz-stat"><span>Total Attempts</span><strong><?=$stats['attempts']?></strong></div>
      <div class="qz-metric qz-stat qz-stat--result"><span>Submitted</span><strong><?=$stats['submitted']?></strong></div>
    <?php endif; ?>
  </div>

  <div class="qz-detail-grid adam-grid">
    <?php if ($canAccessWorkspace): ?>
    <section class="qz-section adam-col-6" aria-labelledby="qz-dashboard-quiz">
      <div class="qz-section__head">
        <div>
          <div class="qz-eyebrow qz-kicker">Step 1</div>
          <h2 id="qz-dashboard-quiz">Prepare the Quiz</h2>
        </div>
      </div>
      <div class="qz-section__body">
        <p>Set the Quiz details, Questions per Attempt, duration, schedule, and status.</p>
      <?php if ($primaryQuiz && !empty($primaryQuiz['id'])): ?>
          <p class="qz-list-context">Current Primary Quiz: <strong><?=h($primaryQuiz['title'])?></strong> (ID <?=(int)$primaryQuiz['id']?>).</p>
      <?php else: ?>
          <p class="qz-list-context">No Primary Quiz is set. Set one from the Quiz details when it is ready for public access.</p>
      <?php endif; ?>
        <div class="qz-page-actions qz-toolbar"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Manage Quizzes</a></div>
      </div>
    </section>
    <?php endif; ?>
    <?php if ($canManageStudents): ?>
    <section class="qz-section adam-col-6" aria-labelledby="qz-dashboard-student">
      <div class="qz-section__head"><div><div class="qz-eyebrow qz-kicker">Step 2</div><h2 id="qz-dashboard-student">Manage Students</h2></div></div>
      <div class="qz-section__body"><p>Confirm each Student's identity and contact details before the Quiz begins.</p><div class="qz-page-actions qz-toolbar"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/students">Open Students</a></div></div>
    </section>
    <?php endif; ?>
    <?php if ($canViewAttempts): ?>
    <section class="qz-section adam-col-6" aria-labelledby="qz-dashboard-attempt">
      <div class="qz-section__head"><div><div class="qz-eyebrow qz-kicker">Step 3</div><h2 id="qz-dashboard-attempt">Monitor Attempts</h2></div></div>
      <div class="qz-section__body"><p>Review Student status, timing, progress, and results. Available actions depend on account permissions.</p><div class="qz-page-actions qz-toolbar"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/attempts">Open Attempts</a></div></div>
    </section>
    <?php endif; ?>
    <?php if ($canAccessWorkspace || $canManageSettings): ?>
    <section class="qz-section adam-col-12" aria-labelledby="qz-dashboard-public">
      <div class="qz-section__head"><div><div class="qz-eyebrow qz-kicker">Distribution</div><h2 id="qz-dashboard-public">Settings and Public Access</h2></div></div>
      <div class="qz-section__body">
        <p>Check the displayed Quiz, verification method, and Student links before the schedule opens.</p>
        <div class="qz-page-actions qz-toolbar">
          <?php if ($canManageSettings): ?><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/settings">Open Settings</a><?php endif; ?>
          <?php if ($canAccessWorkspace): ?>
            <a class="adam-button ghost" href="/quiz/start/" target="_blank" rel="noopener">Preview Start Page</a>
            <a class="adam-button ghost" href="/quiz/register/" target="_blank" rel="noopener">Open Registration</a>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </div>
</section>
