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
<section class="adam-card">
  <?php qz_admin_breadcrumb([['label'=>'Dashboard']]); ?>
  <h2>Dashboard Quiz</h2>
  <p class="muted" style="margin:.6rem 0">Mulai dari daftar quiz, susun konten di Master Builder, lalu pantau student dan attempt.</p>

  <div style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($canAccessWorkspace): ?>
    <span class="adam-stat">Quiz: <strong><?=$stats['quizzes']?></strong></span>
    <span class="adam-stat">Kategori: <strong><?=$stats['categories']?></strong></span>
    <span class="adam-stat">Passage: <strong><?=$stats['passages']?></strong></span>
    <span class="adam-stat">Soal: <strong><?=$stats['questions']?></strong></span>
    <span class="adam-stat">Jawaban: <strong><?=$stats['answers']?></strong></span>
    <?php endif; ?>
    <?php if ($canViewAttempts): ?>
      <span class="adam-stat">Siswa: <strong><?=$stats['students']?></strong></span>
      <span class="adam-stat">Attempt: <strong><?=$stats['attempts']?></strong></span>
      <span class="adam-stat">Submitted: <strong><?=$stats['submitted']?></strong></span>
    <?php endif; ?>
  </div>

  <div class="adam-grid" style="margin-top:14px">
    <?php if ($canAccessWorkspace): ?>
    <div class="adam-card-mini adam-col-6">
      <h3>Quizzes</h3>
      <p>Buat dan kelola quiz. Atur jumlah soal per attempt, durasi, jadwal aktif, dll.</p>
      <p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Buka Quizzes</a>
        <?php if ($user['can_manage_quizzes']): ?>
        <a class="adam-button ghost" href="<?=h($base)?>/?page=admin/quiz/quizzes/add">+ Tambah Quiz</a>
        <?php endif; ?>
      </p>
    </div>
    <div class="adam-card-mini adam-col-6">
      <h3>Konten Quiz (Kategori • Passage • Soal)</h3>
      <p>Kategori dan passage itu <b>per quiz</b>, jadi aksesnya dari dalam quiz.</p>
      <?php if ($primaryQuiz && !empty($primaryQuiz['id'])): ?>
        <p style="margin-top:10px;color:var(--adam-text,#0f172a)">Primary Quiz: <b><?=h($primaryQuiz['title'])?></b> <span style="opacity:.6">(ID: <?=(int)$primaryQuiz['id']?>)</span></p>
        <p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <a class="adam-button soft" href="<?=h($base)?>/?page=admin/quiz/categories/index&quiz_id=<?=(int)$primaryQuiz['id']?>">Buka Kategori</a>
          <a class="adam-button soft" href="<?=h($base)?>/?page=admin/quiz/passages/index&quiz_id=<?=(int)$primaryQuiz['id']?>">Buka Passage</a>
          <a class="adam-button soft" href="<?=h($base)?>/?page=admin/quiz/questions&quiz_id=<?=(int)$primaryQuiz['id']?>">Buka Soal</a>
        </p>
      <?php else: ?>
        <p style="margin-top:12px"><a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/quizzes">Pilih Quiz dulu</a></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($canManageStudents): ?>
    <div class="adam-card-mini adam-col-6">
      <h3>Student Manager</h3>
      <p>Kelola data siswa yang registrasi (nama/email/HP), serta akses ke attempt mereka.</p>
      <p style="margin-top:12px">
        <a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/students">Buka Siswa</a>
      </p>
    </div>
    <?php endif; ?>
    <?php if ($canViewAttempts): ?>
    <div class="adam-card-mini adam-col-6">
      <h3>Attempt Manager</h3>
      <p>Monitoring attempt siswa: identitas, status, waktu, dan progress. Aksi tambahan mengikuti kapabilitas akun.</p>
      <p style="margin-top:12px">
        <a class="adam-button" href="<?=h($base)?>/?page=admin/quiz/attempts">Buka Attempt Manager</a>
      </p>
    </div>
    <?php endif; ?>
    <?php if ($canAccessWorkspace): ?>
    <div class="adam-card-mini adam-col-12">
      <h3>Link Public</h3>
      <p>Start quiz untuk siswa: <code>/quiz/start/</code></p>
      <p style="margin-top:12px">
        <a class="adam-button ghost" href="/quiz/register/" target="_blank" rel="noopener">Buka Register</a>
      </p>
    </div>
    <?php endif; ?>
  </div>
</section>
