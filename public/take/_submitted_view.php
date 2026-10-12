<?php
declare(strict_types=1);

$visibility = quiz_safe_result_visibility($quiz['result_visibility'] ?? 'hidden');
$resultReady = $visibility !== 'hidden' && quiz_attempt_result_is_ready($pdo, $attempt_id);
$themeClass = 'qs-theme-' . quiz_safe_visual_theme($quiz['visual_theme'] ?? 'default');
$faviconUrl = quiz_site_favicon_url($pdo);
$publicTitle = quiz_public_title($pdo);
$participantName = trim((string)($student['name'] ?? ''));
$testNumber = trim((string)($attempt['nomor_test'] ?? ''));
$backUrl = quiz_public_url('start');
$actionUrl = $resultReady ? quiz_public_url('result', ['a' => $alias]) : $backUrl;
$actionLabel = $resultReady ? 'View Result' : 'Back to ' . $publicTitle . ' List';
if ($visibility === 'hidden') {
  $resultTitle = 'Results are private';
  $resultCopy = 'This ' . $publicTitle . ' does not publish participant results. Your submission is complete, but no score or answer details are shown.';
} elseif ($resultReady) {
  $resultTitle = 'Your result is ready';
  $resultCopy = 'The verified result report is available for this submission.';
} else {
  $resultTitle = 'Result unavailable';
  $resultCopy = 'A complete verified result is not available for this submission.';
}
$page_title = $publicTitle . ' Submitted - ' . (string)$quiz['title'];

ob_start();
?>
<style>
  .qs{
    --qs-bg:#edf2f7;
    --qs-paper:#fff;
    --qs-ink:#0f172a;
    --qs-muted:#64748b;
    --qs-line:#d7e0ea;
    --qs-accent:#4f46e5;
    --qs-accent-soft:#eef2ff;
    --qs-highlight:#06b6d4;
    --qs-positive:#16a34a;
    --qs-hero:#111827;
    --qs-hero-muted:#cbd5e1;
    min-height:100vh;
    padding:clamp(20px,4vw,48px) 16px 56px;
    background:var(--qs-bg);
    color:var(--qs-ink);
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  }
  .qs.qs-theme-studio{
    --qs-bg:#f2f4ee;
    --qs-paper:#fff;
    --qs-ink:#171a24;
    --qs-muted:#596174;
    --qs-line:#ccd2ca;
    --qs-accent:#7783ff;
    --qs-accent-soft:#e8eaff;
    --qs-highlight:#8de8d1;
    --qs-positive:#16865b;
    --qs-hero:#10162f;
    --qs-hero-muted:#cbd1e6;
  }
  .qs.qs-theme-studio .qs-hero{ box-shadow:0 24px 70px rgba(16,22,47,.18); }
  .qs.qs-theme-studio .qs-result-note{ box-shadow:7px 7px 0 #10162f; }
  .qs.qs-theme-studio .qs-button:hover{ transform:translateY(-2px); box-shadow:0 8px 0 rgba(16,22,47,.16); }
  .qs-shell{ width:min(960px,100%); margin:0 auto; }
  .qs-topbar{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:18px; }
  .qs-brand{ display:flex; align-items:center; gap:11px; min-width:0; }
  .qs-brand-mark{ width:38px; height:38px; flex:0 0 38px; display:grid; place-items:center; overflow:hidden; padding:3px; border:1px solid var(--qs-line); border-radius:11px; background:#fff; }
  .qs-brand-mark img{ display:block; width:100%; height:100%; object-fit:contain; border-radius:8px; }
  .qs-brand-copy{ min-width:0; }
  .qs-brand-copy strong{ max-width:min(52vw,420px); display:block; overflow:hidden; font-size:.8rem; letter-spacing:.08em; text-overflow:ellipsis; text-transform:uppercase; white-space:nowrap; }
  .qs-brand-copy span{ display:block; margin-top:2px; color:var(--qs-muted); font-size:.75rem; }
  .qs-status{ display:inline-flex; align-items:center; gap:8px; padding:8px 11px; border:1px solid var(--qs-line); border-radius:999px; background:rgba(255,255,255,.74); color:var(--qs-muted); font-size:.74rem; font-weight:850; white-space:nowrap; }
  .qs-status i{ width:8px; height:8px; border-radius:50%; background:var(--qs-positive); }
  .qs-hero{ position:relative; isolation:isolate; overflow:hidden; display:grid; grid-template-columns:minmax(0,1fr) auto; gap:clamp(28px,6vw,70px); align-items:center; padding:clamp(30px,6vw,64px); border-radius:28px; background:var(--qs-hero); color:#fff; }
  .qs-hero::before{ content:""; position:absolute; z-index:-1; width:300px; height:300px; right:-160px; top:-175px; border:66px solid var(--qs-accent); border-radius:50%; opacity:.36; }
  .qs-kicker{ margin:0 0 12px; color:var(--qs-highlight); font-size:.7rem; font-weight:900; letter-spacing:.18em; text-transform:uppercase; }
  .qs-title{ max-width:620px; margin:0; font-size:clamp(2.35rem,6vw,5rem); font-weight:900; line-height:.94; letter-spacing:-.055em; }
  .qs-lead{ max-width:620px; margin:19px 0 0; color:var(--qs-hero-muted); font-size:clamp(.94rem,1.7vw,1.08rem); line-height:1.65; }
  .qs-identifiers{ display:flex; flex-wrap:wrap; gap:8px; margin-top:22px; }
  .qs-identifiers span{ padding:7px 10px; border:1px solid rgba(255,255,255,.18); border-radius:8px; background:rgba(255,255,255,.07); color:var(--qs-hero-muted); font-size:.74rem; font-weight:750; }
  .qs-identifiers b{ color:#fff; }
  .qs-check{ width:clamp(142px,18vw,190px); aspect-ratio:1; display:grid; place-items:center; border-radius:50%; background:var(--qs-highlight); color:var(--qs-hero); font-size:clamp(3.4rem,8vw,6rem); font-weight:350; box-shadow:inset 0 0 0 14px rgba(255,255,255,.34); }
  .qs-metrics{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:12px; }
  .qs-metric{ min-height:132px; display:flex; flex-direction:column; padding:20px; border:1px solid var(--qs-line); border-radius:18px; background:var(--qs-paper); }
  .qs-metric span{ color:var(--qs-muted); font-size:.68rem; font-weight:900; letter-spacing:.12em; text-transform:uppercase; }
  .qs-metric strong{ margin-top:auto; padding-top:18px; color:var(--qs-ink); font-size:clamp(1.05rem,2.3vw,1.45rem); line-height:1.25; letter-spacing:-.025em; }
  .qs-result-note{ display:grid; grid-template-columns:44px minmax(0,1fr); gap:16px; margin-top:12px; padding:22px; border:1px solid var(--qs-line); border-radius:18px; background:var(--qs-accent-soft); }
  .qs-note-icon{ width:44px; height:44px; display:grid; place-items:center; border-radius:13px; background:var(--qs-accent); color:#fff; font-size:1.15rem; font-weight:900; }
  .qs-result-note h2{ margin:1px 0 6px; color:var(--qs-ink); font-size:1.08rem; }
  .qs-result-note p{ margin:0; color:var(--qs-muted); font-size:.86rem; line-height:1.6; }
  .qs-actions{ display:flex; align-items:center; justify-content:space-between; gap:20px; margin-top:24px; }
  .qs-actions p{ max-width:620px; margin:0; color:var(--qs-muted); font-size:.79rem; line-height:1.55; }
  .qs-button{ min-height:48px; display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border:1px solid var(--qs-hero); border-radius:12px; background:var(--qs-hero); color:#fff; font-weight:850; text-decoration:none; white-space:nowrap; transition:transform .18s ease,box-shadow .18s ease,filter .18s ease; }
  .qs-button:hover{ filter:brightness(1.08); }
  .qs-button:focus-visible{ outline:4px solid var(--qs-accent); outline-offset:3px; }
  @media (max-width:700px){
    .qs{ padding:16px 12px 36px; }
    .qs-brand-copy span{ display:none; }
    .qs-status{ font-size:.66rem; }
    .qs-hero{ grid-template-columns:1fr; border-radius:22px; }
    .qs-check{ width:146px; grid-row:1; }
    .qs-copy{ grid-row:2; }
    .qs-metrics{ grid-template-columns:1fr; }
    .qs-metric{ min-height:110px; }
    .qs-actions{ align-items:stretch; flex-direction:column; }
    .qs-button{ width:100%; }
    .qs.qs-theme-studio .qs-hero{ box-shadow:0 18px 44px rgba(16,22,47,.16); }
    .qs.qs-theme-studio .qs-result-note{ box-shadow:5px 5px 0 #10162f; }
  }
  @media (max-width:420px){
    .qs-hero{ padding:26px 20px; }
    .qs-title{ font-size:clamp(2.15rem,13vw,3.4rem); }
    .qs-result-note{ grid-template-columns:1fr; }
  }
</style>
<main class="qs <?= h($themeClass) ?>">
  <div class="qs-shell">
    <header class="qs-topbar">
      <div class="qs-brand">
        <span class="qs-brand-mark"><img src="<?= h($faviconUrl) ?>" alt="" referrerpolicy="no-referrer"></span>
        <span class="qs-brand-copy"><strong><?= h($publicTitle) ?></strong><span>Private participant submission</span></span>
      </div>
      <span class="qs-status"><i aria-hidden="true"></i>Submission received</span>
    </header>
    <section class="qs-hero" aria-labelledby="qs-title">
      <div class="qs-copy">
        <p class="qs-kicker"><?= h($publicTitle) ?> complete</p>
        <h1 class="qs-title" id="qs-title">Submission complete.</h1>
        <p class="qs-lead">Your responses for <strong><?= h($quiz['title']) ?></strong> have been securely submitted. This attempt is closed and cannot be taken again.</p>
        <?php if ($participantName !== '' || $testNumber !== ''): ?>
          <div class="qs-identifiers">
            <?php if ($participantName !== ''): ?><span>Participant: <b><?= h($participantName) ?></b></span><?php endif; ?>
            <?php if ($testNumber !== ''): ?><span>Test number: <b><?= h($testNumber) ?></b></span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="qs-check" aria-hidden="true">&#10003;</div>
    </section>
    <section class="qs-metrics" aria-label="Submission details">
      <article class="qs-metric"><span>Status</span><strong>Submitted</strong></article>
      <article class="qs-metric"><span>Result visibility</span><strong><?= $visibility === 'hidden' ? 'Private' : 'Available when verified' ?></strong></article>
      <article class="qs-metric"><span>Attempt</span><strong>Closed</strong></article>
    </section>
    <section class="qs-result-note" aria-labelledby="qs-result-title">
      <span class="qs-note-icon" aria-hidden="true"><?= $resultReady ? '&#8599;' : '&#128274;' ?></span>
      <div><h2 id="qs-result-title"><?= h($resultTitle) ?></h2><p><?= h($resultCopy) ?></p></div>
    </section>
    <footer class="qs-actions">
      <p>This confirmation is private and tied to the browser and device used for the <?= h($publicTitle) ?>.</p>
      <a class="qs-button" href="<?= h($actionUrl) ?>"><?= h($actionLabel) ?></a>
    </footer>
  </div>
</main>
<?php
$content_html = (string)ob_get_clean();
