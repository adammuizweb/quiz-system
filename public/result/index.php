<?php
declare(strict_types=1);

require_once __DIR__ . '/../_security.php';
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('Referrer-Policy: same-origin');
}

if (!function_exists('h')) {
  function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$alias = trim((string)($_GET['a'] ?? ''));
$cookieId = trim((string)($_COOKIE['quiz_cid'] ?? ''));
if (preg_match('/^[a-zA-Z0-9_-]{6,80}$/', $alias) !== 1 || preg_match('/^[a-f0-9-]{36}$/i', $cookieId) !== 1) {
  http_response_code(404); exit('Result not found');
}

$studentStmt = $pdo->prepare('SELECT id, name FROM students WHERE cookie_id=:cookie_id LIMIT 1');
$studentStmt->execute([':cookie_id' => $cookieId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);
$studentId = (int)($student['id'] ?? 0);
if ($studentId <= 0) { http_response_code(404); exit('Result not found'); }

$stmt = $pdo->prepare('SELECT a.id, a.quiz_id, a.nomor_test, a.status, a.token_hash, a.user_agent_hash, a.total_score,
    a.elapsed_seconds, a.submitted_at, q.title, q.result_visibility, q.visual_theme_override
  FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id
  WHERE a.attempt_alias=:alias AND a.student_id=:student_id LIMIT 1');
$stmt->execute([':alias' => $alias, ':student_id' => $studentId]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) { http_response_code(404); exit('Result not found'); }

$quizId = (int)$attempt['quiz_id'];
quiz_public_require_quiz($quizId);
$backUrl = quiz_public_url_for_quiz($pdo, $quizId, 'start');
$token = (string)($_COOKIE['quiz_token_' . $quizId] ?? '');
if ($token === '' || !hash_equals((string)$attempt['token_hash'], hash('sha256', $token))
    || !hash_equals((string)$attempt['user_agent_hash'], hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
  http_response_code(404); exit('Result not found');
}

$visibility = quiz_safe_result_visibility($attempt['result_visibility'] ?? 'hidden');
if ((string)$attempt['status'] !== 'submitted' || $visibility === 'hidden') {
  http_response_code(404); exit('Result not found');
}

$visualTheme = quiz_safe_visual_theme_override($attempt['visual_theme_override'] ?? null)
  ?? quiz_safe_visual_theme(settings_get($pdo, 'quiz_visual_theme', 'default'));
$themeClass = 'qr-theme-' . $visualTheme;
$faviconUrl = quiz_site_favicon_url($pdo);
$siteTitle = trim((string)(settings_get($pdo, 'site_title', 'Quiz') ?? 'Quiz'));
if ($siteTitle === '') $siteTitle = 'Quiz';
$resultStyles = <<<'HTML'
<style>
  .qr{
    --qr-bg:#edf2f7;
    --qr-paper:#fff;
    --qr-ink:#0f172a;
    --qr-muted:#64748b;
    --qr-line:#d7e0ea;
    --qr-accent:#4f46e5;
    --qr-accent-soft:#eef2ff;
    --qr-highlight:#06b6d4;
    --qr-positive:#16a34a;
    --qr-score:#a5f3fc;
    --qr-coral:#fb923c;
    --qr-hero:#111827;
    --qr-hero-soft:#263247;
    --qr-on-hero:#fff;
    --qr-on-hero-muted:#cbd5e1;
    --qr-track:#e2e8f0;
    min-height:100vh;
    padding:clamp(20px,4vw,48px) 16px 56px;
    background:var(--qr-bg);
    color:var(--qr-ink);
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  }
  .qr.qr-theme-studio{
    --qr-bg:#f2f4ee;
    --qr-paper:#fff;
    --qr-ink:#171a24;
    --qr-muted:#596174;
    --qr-line:#ccd2ca;
    --qr-accent:#7783ff;
    --qr-accent-soft:#e8eaff;
    --qr-highlight:#8de8d1;
    --qr-positive:#16865b;
    --qr-score:#d9ff63;
    --qr-coral:#ff826b;
    --qr-hero:#10162f;
    --qr-hero-soft:#1b2447;
    --qr-on-hero:#fff;
    --qr-on-hero-muted:#cbd1e6;
    --qr-track:#dfe3dc;
  }
  .qr-shell{ width:min(1120px,100%); margin:0 auto; }
  .qr-topbar{ display:flex; align-items:center; justify-content:space-between; gap:20px; margin-bottom:18px; }
  .qr-brand{ display:flex; align-items:center; gap:12px; min-width:0; }
  .qr-mark{ width:38px; height:38px; flex:0 0 38px; display:grid; place-items:center; overflow:hidden; padding:3px; border:1px solid var(--qr-line); border-radius:11px; background:#fff; }
  .qr-mark img{ display:block; width:100%; height:100%; object-fit:contain; border-radius:8px; }
  .qr-brand-copy{ min-width:0; }
  .qr-brand-copy strong{ max-width:min(52vw,420px); display:block; overflow:hidden; color:var(--qr-ink); font-size:.8rem; letter-spacing:.08em; text-overflow:ellipsis; text-transform:uppercase; white-space:nowrap; }
  .qr-brand-copy span{ display:block; margin-top:2px; color:var(--qr-muted); font-size:.76rem; }
  .qr-status{ display:inline-flex; align-items:center; gap:8px; padding:8px 11px; border:1px solid var(--qr-line); border-radius:999px; background:rgba(255,255,255,.72); color:var(--qr-muted); font-size:.76rem; font-weight:800; white-space:nowrap; }
  .qr-status i{ width:8px; height:8px; border-radius:50%; background:var(--qr-positive); box-shadow:0 0 0 4px color-mix(in srgb,var(--qr-positive) 14%,transparent); }

  .qr-hero{ position:relative; isolation:isolate; overflow:hidden; display:grid; grid-template-columns:minmax(0,1fr) auto; gap:clamp(28px,6vw,74px); align-items:center; padding:clamp(30px,6vw,68px); border-radius:28px; background:var(--qr-hero); color:var(--qr-on-hero); }
  .qr-hero::before{ content:""; position:absolute; z-index:-1; width:330px; height:330px; right:-180px; top:-190px; border:70px solid var(--qr-accent); border-radius:50%; opacity:.34; }
  .qr-hero::after{ content:""; position:absolute; z-index:-1; width:120px; height:120px; left:44%; bottom:-82px; border-radius:50%; background:var(--qr-coral); opacity:.9; }
  .qr-eyebrow{ margin:0 0 13px; color:var(--qr-score); font-size:.72rem; font-weight:900; letter-spacing:.18em; text-transform:uppercase; }
  .qr-title{ max-width:720px; margin:0; font-size:clamp(2.2rem,5.4vw,5rem); font-weight:900; line-height:.94; letter-spacing:-.055em; }
  .qr-lead{ max-width:650px; margin:20px 0 0; color:var(--qr-on-hero-muted); font-size:clamp(.94rem,1.7vw,1.08rem); line-height:1.65; }
  .qr-identifiers{ display:flex; flex-wrap:wrap; gap:8px; margin-top:24px; }
  .qr-identifiers span{ padding:7px 10px; border:1px solid rgba(255,255,255,.18); border-radius:8px; background:rgba(255,255,255,.07); color:var(--qr-on-hero-muted); font-size:.75rem; font-weight:750; }
  .qr-identifiers b{ color:var(--qr-on-hero); }

  .qr-score-ring{ --qr-angle:0deg; position:relative; width:clamp(164px,20vw,216px); aspect-ratio:1; display:grid; place-items:center; flex:0 0 auto; border-radius:50%; background:conic-gradient(var(--qr-score) 0 var(--qr-angle),rgba(255,255,255,.14) var(--qr-angle) 360deg); }
  .qr-score-ring::before{ content:""; position:absolute; inset:16px; border-radius:50%; background:var(--qr-hero-soft); }
  .qr-score-ring > div{ position:relative; text-align:center; }
  .qr-score-ring strong{ display:block; color:var(--qr-score); font-size:clamp(2rem,5vw,3.7rem); line-height:1; letter-spacing:-.055em; }
  .qr-score-ring span{ display:block; margin-top:7px; color:var(--qr-on-hero-muted); font-size:.68rem; font-weight:850; letter-spacing:.12em; text-transform:uppercase; }

  .qr-metrics{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:12px; }
  .qr-metric{ min-height:162px; display:flex; flex-direction:column; padding:22px; border:1px solid var(--qr-line); border-radius:18px; background:var(--qr-paper); }
  .qr-metric-label{ color:var(--qr-muted); font-size:.7rem; font-weight:900; letter-spacing:.12em; text-transform:uppercase; }
  .qr-metric strong{ margin-top:13px; color:var(--qr-ink); font-size:clamp(1.55rem,3vw,2.25rem); line-height:1.05; letter-spacing:-.04em; }
  .qr-metric small{ margin-top:auto; padding-top:12px; color:var(--qr-muted); font-size:.76rem; line-height:1.45; }
  .qr-progress{ height:8px; margin-top:auto; overflow:hidden; border-radius:99px; background:var(--qr-track); }
  .qr-progress > i{ width:var(--qr-progress,0%); height:100%; display:block; border-radius:inherit; background:linear-gradient(90deg,var(--qr-accent),var(--qr-highlight)); }

  .qr-section{ margin-top:clamp(38px,7vw,70px); }
  .qr-section-head{ display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,.65fr); gap:30px; align-items:end; margin-bottom:20px; }
  .qr-section-kicker{ margin:0 0 8px; color:var(--qr-accent); font-size:.7rem; font-weight:900; letter-spacing:.15em; text-transform:uppercase; }
  .qr-section-title{ margin:0; color:var(--qr-ink); font-size:clamp(1.8rem,4vw,3.1rem); line-height:1; letter-spacing:-.045em; }
  .qr-section-copy{ margin:0; color:var(--qr-muted); font-size:.9rem; line-height:1.6; }
  .qr-category-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
  .qr-category{ display:grid; grid-template-columns:48px minmax(0,1fr) auto; gap:16px; align-items:start; padding:22px; border:1px solid var(--qr-line); border-radius:18px; background:var(--qr-paper); }
  .qr-category-index{ width:42px; height:42px; display:grid; place-items:center; border-radius:12px; background:var(--qr-accent-soft); color:var(--qr-accent); font-size:.72rem; font-weight:950; }
  .qr-category-body{ min-width:0; }
  .qr-category h3{ margin:1px 0 0; color:var(--qr-ink); font-size:1rem; line-height:1.3; }
  .qr-category-score{ color:var(--qr-ink); font-size:1.35rem; font-weight:900; letter-spacing:-.04em; white-space:nowrap; }
  .qr-category .qr-progress{ grid-column:2/-1; width:100%; margin-top:2px; }
  .qr-category-stats{ grid-column:2/-1; display:flex; flex-wrap:wrap; gap:8px 18px; margin:0; }
  .qr-category-stats div{ display:flex; gap:6px; align-items:baseline; }
  .qr-category-stats dt{ color:var(--qr-muted); font-size:.7rem; font-weight:800; text-transform:uppercase; }
  .qr-category-stats dd{ margin:0; color:var(--qr-ink); font-size:.78rem; font-weight:850; }

  .qr-footer{ display:flex; align-items:center; justify-content:space-between; gap:22px; margin-top:28px; padding:22px; border:1px solid var(--qr-line); border-radius:18px; background:color-mix(in srgb,var(--qr-paper) 90%,var(--qr-accent-soft)); }
  .qr-footer p{ max-width:680px; margin:0; color:var(--qr-muted); font-size:.82rem; line-height:1.55; }
  .qr-button{ min-height:46px; display:inline-flex; align-items:center; justify-content:center; padding:11px 17px; border:1px solid var(--qr-hero); border-radius:12px; background:var(--qr-hero); color:#fff; font-weight:850; text-decoration:none; white-space:nowrap; }
  .qr-button:hover{ filter:brightness(1.08); }
  .qr-button:focus-visible{ outline:4px solid var(--qr-accent); outline-offset:3px; }

  .qr-empty{ max-width:720px; margin:8vh auto 0; padding:clamp(28px,6vw,58px); border:1px solid var(--qr-line); border-radius:26px; background:var(--qr-paper); }
  .qr-empty-mark{ width:54px; height:54px; display:grid; place-items:center; margin-bottom:32px; border-radius:16px; background:var(--qr-accent-soft); color:var(--qr-accent); font-size:1.4rem; font-weight:900; }
  .qr-empty h1{ margin:0; color:var(--qr-ink); font-size:clamp(2rem,6vw,4rem); line-height:.98; letter-spacing:-.05em; }
  .qr-empty p{ margin:20px 0 28px; color:var(--qr-muted); line-height:1.65; }

  @media (max-width:760px){
    .qr{ padding:16px 12px 36px; }
    .qr-topbar{ align-items:flex-start; }
    .qr-brand-copy span{ display:none; }
    .qr-status{ font-size:.68rem; }
    .qr-hero{ grid-template-columns:1fr; border-radius:22px; }
    .qr-score-ring{ width:174px; grid-row:1; }
    .qr-hero-copy{ grid-row:2; }
    .qr-title{ font-size:clamp(2.15rem,12vw,3.7rem); }
    .qr-metrics{ grid-template-columns:1fr; }
    .qr-metric{ min-height:138px; }
    .qr-section-head{ grid-template-columns:1fr; gap:13px; }
    .qr-category-grid{ grid-template-columns:1fr; }
    .qr-footer{ align-items:stretch; flex-direction:column; }
    .qr-button{ width:100%; }
  }
  @media (max-width:420px){
    .qr-hero{ padding:26px 20px; }
    .qr-category{ grid-template-columns:40px minmax(0,1fr); padding:18px; gap:13px; }
    .qr-category-index{ width:36px; height:36px; }
    .qr-category-score{ grid-column:2; }
    .qr-category .qr-progress,.qr-category-stats{ grid-column:1/-1; }
  }
  @media print{
    .qr{ padding:0; background:#fff; }
    .qr-shell{ width:100%; }
    .qr-button{ display:none; }
    .qr-hero,.qr-metric,.qr-category,.qr-footer{ break-inside:avoid; }
  }
</style>
HTML;

if (!quiz_attempt_result_is_ready($pdo, (int)$attempt['id'])) {
  $page_title = 'Quiz Result';
  $content_html = $resultStyles . '<main class="qr ' . h($themeClass) . '"><div class="qr-shell"><section class="qr-empty">'
    . '<div class="qr-empty-mark" aria-hidden="true">!</div><p class="qr-section-kicker">Result report</p>'
    . '<h1>Result Unavailable</h1><p>A complete verified result is not available for this submission.</p>'
    . '<a class="qr-button" href="' . h($backUrl) . '">Back to Quiz List</a></section></div></main>';
  require __DIR__ . '/../layout.php';
  return;
}

$scoreStmt = $pdo->prepare('SELECT category_name, question_count, answered_count, correct_count,
  earned_score, available_score, percent FROM attempt_category_scores WHERE attempt_id=:attempt_id ORDER BY id ASC');
$scoreStmt->execute([':attempt_id' => (int)$attempt['id']]);
$categories = $scoreStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalQuestions = array_sum(array_map(static fn(array $row): int => (int)$row['question_count'], $categories));
$answered = array_sum(array_map(static fn(array $row): int => (int)$row['answered_count'], $categories));
$available = array_sum(array_map(static fn(array $row): int => (int)$row['available_score'], $categories));
if ($totalQuestions === 0) {
  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM attempt_questions WHERE attempt_id=:attempt_id');
  $countStmt->execute([':attempt_id' => (int)$attempt['id']]);
  $totalQuestions = (int)$countStmt->fetchColumn();
  $answeredCondition = "(aa.selected_answer_id IS NOT NULL OR (aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'' AND TRIM(aa.answer_text)<>'[]'))";
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=:attempt_id AND {$answeredCondition}");
  $countStmt->execute([':attempt_id' => (int)$attempt['id']]);
  $answered = (int)$countStmt->fetchColumn();
}

$totalScore = (int)$attempt['total_score'];
$overallPercent = $available > 0 ? max(0.0, min(100.0, ($totalScore / $available) * 100)) : null;
$completionPercent = $totalQuestions > 0 ? max(0.0, min(100.0, ($answered / $totalQuestions) * 100)) : 0.0;
$scoreAngle = ($overallPercent ?? 0.0) * 3.6;
$formatPercent = static function (?float $percent): string {
  if ($percent === null) return '-';
  return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%';
};

$elapsed = $attempt['elapsed_seconds'] === null ? null : max(0, (int)$attempt['elapsed_seconds']);
$elapsedClock = $elapsed === null ? '-' : sprintf('%d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60);
if ($elapsed === null) {
  $elapsedReadable = 'Not recorded';
} elseif ($elapsed >= 3600) {
  $elapsedReadable = intdiv($elapsed, 3600) . 'h ' . intdiv($elapsed % 3600, 60) . 'm';
} else {
  $elapsedReadable = intdiv($elapsed, 60) . 'm ' . ($elapsed % 60) . 's';
}
$submittedRaw = trim((string)($attempt['submitted_at'] ?? ''));
$submittedText = $submittedRaw !== '' && function_exists('app_display_datetime')
  ? app_display_datetime($submittedRaw)
  : ($submittedRaw !== '' ? $submittedRaw : '-');
$participantName = trim((string)($student['name'] ?? ''));
$testNumber = trim((string)($attempt['nomor_test'] ?? ''));

$page_title = 'Quiz Result - ' . (string)$attempt['title'];
ob_start();
?>
<?= $resultStyles ?>
<main class="qr <?= h($themeClass) ?>">
  <div class="qr-shell">
    <header class="qr-topbar">
      <div class="qr-brand">
        <span class="qr-mark"><img src="<?= h($faviconUrl) ?>" alt="" referrerpolicy="no-referrer"></span>
        <span class="qr-brand-copy"><strong><?= h($siteTitle) ?></strong><span>Private participant result</span></span>
      </div>
      <span class="qr-status"><i aria-hidden="true"></i>Submitted and verified</span>
    </header>

    <section class="qr-hero" aria-labelledby="qr-result-title">
      <div class="qr-hero-copy">
        <p class="qr-eyebrow">Quiz result</p>
        <h1 class="qr-title" id="qr-result-title"><?= h($attempt['title']) ?></h1>
        <p class="qr-lead">Your submitted result is ready. This report summarizes the final score and completion data without exposing answer keys or individual responses.</p>
        <?php if ($participantName !== '' || $testNumber !== ''): ?>
          <div class="qr-identifiers">
            <?php if ($participantName !== ''): ?><span>Participant: <b><?= h($participantName) ?></b></span><?php endif; ?>
            <?php if ($testNumber !== ''): ?><span>Test number: <b><?= h($testNumber) ?></b></span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="qr-score-ring" style="--qr-angle:<?= h(number_format($scoreAngle, 2, '.', '')) ?>deg" role="img" aria-label="Overall score <?= h($formatPercent($overallPercent)) ?>">
        <div><strong><?= h($formatPercent($overallPercent)) ?></strong><span>Overall score</span></div>
      </div>
    </section>

    <section class="qr-metrics" aria-label="Overall summary">
      <article class="qr-metric">
        <span class="qr-metric-label">Score earned</span>
        <strong><?= $totalScore ?> / <?= $available ?></strong>
        <small><?= $available > 0 ? 'Final points from the verified submission.' : 'This Quiz has no scored points.' ?></small>
      </article>
      <article class="qr-metric">
        <span class="qr-metric-label">Completion</span>
        <strong><?= $answered ?> / <?= $totalQuestions ?></strong>
        <div class="qr-progress" role="progressbar" aria-label="Answered questions" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= h(number_format($completionPercent, 2, '.', '')) ?>" style="--qr-progress:<?= h(number_format($completionPercent, 2, '.', '')) ?>%"><i></i></div>
        <small><?= h($formatPercent($completionPercent)) ?> of questions answered.</small>
      </article>
      <article class="qr-metric">
        <span class="qr-metric-label">Time used</span>
        <strong><?= h($elapsedReadable) ?></strong>
        <small><?= h($elapsedClock) ?> total &middot; Submitted <?= h($submittedText) ?></small>
      </article>
    </section>

    <?php if ($visibility === 'categories'): ?>
      <section class="qr-section" aria-labelledby="qr-category-title">
        <header class="qr-section-head">
          <div>
            <p class="qr-section-kicker">Performance map</p>
            <h2 class="qr-section-title" id="qr-category-title">Category breakdown</h2>
          </div>
          <p class="qr-section-copy">Each category shows answered questions, correct responses, earned points, and the percentage of available points.</p>
        </header>
        <div class="qr-category-grid">
          <?php if (!$categories): ?>
            <article class="qr-category"><div class="qr-category-body"><h3>Breakdown unavailable</h3></div></article>
          <?php else: foreach ($categories as $index => $category):
            $categoryPercent = $category['percent'] === null ? null : max(0.0, min(100.0, (float)$category['percent']));
          ?>
            <article class="qr-category">
              <span class="qr-category-index" aria-hidden="true"><?= h(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
              <div class="qr-category-body"><h3><?= h($category['category_name']) ?></h3></div>
              <strong class="qr-category-score"><?= h($formatPercent($categoryPercent)) ?></strong>
              <div class="qr-progress" role="progressbar" aria-label="<?= h($category['category_name']) ?> score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= h(number_format($categoryPercent ?? 0.0, 2, '.', '')) ?>" style="--qr-progress:<?= h(number_format($categoryPercent ?? 0.0, 2, '.', '')) ?>%"><i></i></div>
              <dl class="qr-category-stats">
                <div><dt>Answered</dt><dd><?= (int)$category['answered_count'] ?>/<?= (int)$category['question_count'] ?></dd></div>
                <div><dt>Correct</dt><dd><?= (int)$category['correct_count'] ?></dd></div>
                <div><dt>Points</dt><dd><?= (int)$category['earned_score'] ?>/<?= (int)$category['available_score'] ?></dd></div>
              </dl>
            </article>
          <?php endforeach; endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <footer class="qr-footer">
      <p>This private report is tied to your current browser and device. It contains summary data only and does not include answer keys or individual responses.</p>
      <a class="qr-button" href="<?= h($backUrl) ?>">Back to Quiz List</a>
    </footer>
  </div>
</main>
<?php
$content_html = (string)ob_get_clean();
require __DIR__ . '/../layout.php';
