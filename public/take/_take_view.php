<?php
// /quiz/take/_take_view.php
declare(strict_types=1);

// kalau _take_ctx sudah bikin error page
if (isset($content_html) && is_string($content_html) && trim($content_html) !== '') {
  return;
}
if (!isset($ctx) || !is_array($ctx)) {
  $publicTitle = isset($publicTitle) && is_string($publicTitle) ? $publicTitle : quiz_public_title($pdo);
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">Invalid ' . quiz_h($publicTitle) . ' context.</div>';
  return;
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$publicTitle = isset($publicTitle) && is_string($publicTitle) ? $publicTitle : quiz_public_title($pdo);
$publicTitleHtml = h($publicTitle);
$quizTitle = h((string)($ctx['quiz']['title'] ?? $publicTitle));
$quizDescRaw  = trim((string)($ctx['quiz']['description'] ?? ''));
$quizDesc  = $quizDescRaw ? '<div class="qt-sub">'.h($quizDescRaw).'</div>' : '';

$total = (int)($ctx['total'] ?? 0);
$answeredCount = (int)($ctx['answered_count'] ?? 0);

$navSections = (isset($ctx['nav_sections']) && is_array($ctx['nav_sections'])) ? $ctx['nav_sections'] : [];
$navCatMap   = (isset($ctx['nav_cat_map']) && is_array($ctx['nav_cat_map'])) ? $ctx['nav_cat_map'] : [];
$allowReview = !empty($ctx['allow_mark_for_review']);
$visualTheme = quiz_safe_visual_theme($ctx['quiz']['visual_theme'] ?? 'default');
$visualThemeClass = h('qt-theme-' . $visualTheme);

// build sections buttons
$sectionsHtml = '';
if ($navSections) {
  foreach ($navSections as $s) {
    $nm = h((string)($s['name'] ?? 'Other'));
    $st = (int)($s['start'] ?? 1);
    $en = (int)($s['end'] ?? $st);
    $sectionDone = true;
    for ($seq = $st; $seq <= $en; $seq++) {
      if (empty($ctx['answered_map'][$seq])) { $sectionDone = false; break; }
    }
    $sectionsHtml .= '<button type="button" class="qt-sec'.($sectionDone?' done':'').'" data-start="'.$st.'" data-end="'.$en.'" data-seq="'.$st.'">'
      .$nm.' <span class="qt-sec-range">'.$st.'–'.$en.'</span>'
      .'</button>';
  }
}

$durMin = (int)($ctx['quiz']['duration_minutes'] ?? 0);
$durationHtml = ($durMin > 0)
  ? '<div class="qt-mini">Duration: <b>'.(int)$durMin.' minutes</b></div>'
  : '';

// nav dots (with category tooltip)
$grid = '';
for ($i=1; $i<=$total; $i++) {
  $done = !empty($ctx['answered_map'][$i]);

  $cname = '';
  if (!empty($navCatMap[$i]['name'])) $cname = (string)$navCatMap[$i]['name'];
  $cname = trim($cname);
  if ($cname === '') $cname = 'Other';

  $title = 'Question '.$i.' • '.$cname;
  $reviewed = $allowReview && !empty($ctx['review_map'][$i]);
  $grid .= '<button type="button" class="qt-dot '.($done?'done':'todo').($reviewed?' reviewed':'').'" data-seq="'.$i.'" data-cat="'.h($cname).'" title="'.h($title).'">'.$i.'</button>';
}
$reviewLegend = $allowReview ? ' • Amber ring: marked for review' : '';
$reviewButton = $allowReview ? '<button class="qt-btn ghost" id="btnReview" type="button" aria-pressed="false">Mark for review</button>' : '';

// Optional WhatsApp help link, configured by the site administrator.
$adminWa = settings_get($pdo, 'quiz_admin_wa', '') ?? '';
$adminDigits = preg_replace('/\D+/', '', $adminWa);
if (str_starts_with($adminDigits, '0')) $adminDigits = '62' . substr($adminDigits, 1);
$waText = rawurlencode(
  "Hello Admin, I need assistance during the {$publicTitle}.\n"
  .$publicTitle.": ".(string)($ctx['quiz']['title'] ?? '')."\n"
  ."Attempt: ".(string)($ctx['attempt_alias'] ?? '')."\n"
  ."Name: ".(string)($ctx['student']['name'] ?? '')."\n"
);
$helpLink = $adminDigits !== ''
  ? '<a class="qt-help" href="' . h("https://wa.me/{$adminDigits}?text={$waText}") . '" target="_blank" rel="noopener">Need help? Chat with Admin</a>'
  : '';

$content_html = <<<HTML
<!-- Markdown + Math (KaTeX) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>

<section class="qt {$visualThemeClass}">
  <div class="qt-wrap">

    <div class="qt-head">
      <div>
        <div class="qt-badge">TAKE {$publicTitleHtml}</div>
        <h1 class="qt-title">{$quizTitle}</h1>
        {$quizDesc}
      </div>

<div class="qt-meta">
  <div class="qt-meta-left">
    <div class="qt-mini">Remaining time: <b id="timerText">--:--</b></div>
    <div class="qt-prog">Progress: <b id="progressText">{$answeredCount}/{$total}</b></div>
    {$durationHtml}
  </div>

  <button
    type="button"
    class="qt-navtoggle"
    id="qtNavToggle"
    aria-controls="qtSide"
    aria-expanded="false"
  >
    Navigation
  </button>
</div>

    </div>

    <div id="alertBox" class="qt-alert" role="status" aria-live="polite" style="display:none"></div>

    <div class="qt-layout">
<aside class="qt-side" id="qtSide" aria-label="Question Navigation">
  <div class="qt-side-card">
    <div class="qt-side-top">
      <strong>Question Navigation</strong>
      <button type="button" class="qt-x" id="qtNavClose" aria-label="Close navigation">✕</button>
    </div>

    <div class="qt-side-body">
      <div class="qt-side-title">Question Navigation</div>
      <div class="qt-side-sub">Grey: unanswered • Green: answered{$reviewLegend}</div>

      <div id="navSections" class="qt-sections">
        {$sectionsHtml}
      </div>

      <div id="navGrid" class="qt-grid">
        {$grid}
      </div>

      <div class="qt-side-note">
        Click the number to jump to the question.<br>
        {$helpLink}
      </div>
    </div>
  </div>
</aside>


      <main class="qt-main">
        <div class="qt-card">

          <!-- category pill -->
          <div id="categoryPill" class="qt-catpill" style="display:none">-</div>

          <!-- passage (stimulus) -->
          <div id="passageBox" class="qt-pass" style="display:none">
            <div class="qt-pass-title" id="passageTitle"></div>
            <div id="passageText" class="qt-pass-text"></div>
            <div id="passageMedia" class="qt-qmedia" style="display:none"></div>
          </div>

          <div id="questionMeta" class="qt-qmeta">-</div>
          <div id="questionText" class="qt-qtext">Loading questions...</div>

          <!-- media/file/youtube (soal) -->
          <div id="questionMedia" class="qt-qmedia" style="display:none"></div>

          <div id="answersBox" class="qt-answers"></div>

          <div class="qt-actions">
            <button class="qt-btn ghost" id="btnPrev" type="button">Previous</button>
             <button class="qt-btn primary" id="btnNext" type="button">Next</button>
            {$reviewButton}
            <button class="qt-btn danger" id="btnSubmit" type="button">Submit</button>
          </div>

          <div class="qt-footnote">
            Answers are automatically saved. Do not change browsers/devices.
          </div>

        </div>
      </main>
    </div>

  </div>

<style>
.qt{ padding:26px 16px; }
.qt-wrap{ max-width:1180px; margin:0 auto; padding: 20px; }
.qt-head{ display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:flex-start; }
.qt-badge{ font-weight:900; letter-spacing:.14em; font-size:12px; opacity:.7; }
.qt-title{ margin:6px 0 0; }
.qt-sub{ margin-top:6px; opacity:.75; max-width:720px; }
.qt-meta{ text-align:right; opacity:.85; }
.qt-mini{ font-size:13px; margin-top:4px; }

.qt-alert{
  margin-top:12px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.12);
}

.qt-layout{ display:grid; grid-template-columns: 280px 1fr; gap:14px; margin-top:14px; }
@media (max-width: 900px){ .qt-layout{ grid-template-columns: 1fr; } }

.qt-side{
  position: sticky; top: 14px;
  align-self: start;
  background: rgba(0,0,0,.03);
  border:1px solid rgba(0,0,0,.08);
  border-radius:14px;
  padding:14px;
}
.qt-side-title{ font-weight:900; }
.qt-side-sub{ margin-top:4px; opacity:.7; font-size:13px; }
.qt-side-note{ margin-top:12px; opacity:.7; font-size:13px; line-height:1.45; }
.qt-help{ display:inline-block; margin-top:8px; font-weight:900; text-decoration:none; }

/* sections (kategori + range) */
.qt-sections{
  margin-top:12px;
  display:grid;
  gap:8px;
}
.qt-sec{
  width:100%;
  text-align:left;
  border:1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.75);
  border-radius:12px;
  padding:10px 10px;
  cursor:pointer;
  font-weight:900;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.qt-sec-range{
  opacity:.7;
  font-weight:800;
  font-size:12px;
  white-space:nowrap;
}
.qt-sec.active{
  outline: 3px solid rgba(99,102,241,.18);
  border-color: rgba(99,102,241,.35);
}
.qt-sec.done{
  background: rgba(34,197,94,.18);
  border-color: rgba(34,197,94,.35);
}
.qt-sec.done .qt-sec-range{ opacity:.85; }

.qt-grid{
  margin-top:12px;
  display:grid;
  grid-template-columns: repeat(6, 1fr);
  gap:8px;
}
.qt-dot{
  border:1px solid rgba(0,0,0,.10);
  border-radius:10px;
  padding:10px 0;
  font-weight:900;
  cursor:pointer;
  background:#f1f5f9;
  color:#0f172a;
}
.qt-dot.todo{ background:#f1f5f9; }
.qt-dot.done{ background: rgba(34,197,94,.18); border-color: rgba(34,197,94,.35); }
.qt-dot.reviewed{ box-shadow: inset 0 0 0 3px #f59e0b; }
.qt-dot.active{ outline: 3px solid rgba(99,102,241,.25); border-color: rgba(99,102,241,.45); }

.qt-main .qt-card{
  background: rgba(0,0,0,.03);
  border:1px solid rgba(0,0,0,.08);
  border-radius:14px;
  padding:16px;
}

/* category pill + passage block */
.qt-catpill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 10px;
  border-radius:999px;
  font-weight:900;
  font-size:12px;
  letter-spacing:.06em;
  background: rgba(79,70,229,.10);
  color:#3730a3;
  border:1px solid rgba(79,70,229,.18);
  margin-bottom:10px;
}
.qt-pass{
  margin-bottom:12px;
  padding:12px;
  border-radius:14px;
  background: rgba(255,255,255,.70);
  border:1px solid rgba(0,0,0,.10);
}
.qt-pass-title{
  font-weight:900;
  opacity:.85;
}
.qt-pass-text{
  margin-top:8px;
  line-height:1.55;
}

.qt-qmeta{ opacity:.75; margin-bottom:8px; }
.qt-qtext{ font-size:1.05rem; line-height:1.55; }
.qt-answers{ margin-top:14px; display:grid; gap:10px; }
.qt-answers .qt-choice-input{
  flex:0 0 18px;
  width:18px;
  height:18px;
  margin:5px 0 0;
  accent-color:#4f46e5;
}

.qt-btn{
  border:0;
  border-radius:12px;
  padding:11px 14px;
  font-weight:900;
  cursor:pointer;
  transition: transform .08s ease, filter .12s ease;
}
.qt-btn:active{ transform: scale(.99); }
.qt-btn.primary{ background: linear-gradient(135deg,#4f46e5,#2563eb,#06b6d4); color:#fff; }
.qt-btn.ghost{ background:#f1f5f9; color:#0f172a; border:1px solid rgba(15,23,42,.12); }
.qt-btn.danger{ background:#111827; color:#fff; margin-left:auto; }
.qt-btn[disabled]{ opacity:.55; cursor:not-allowed; }

/* SUBMIT READY (mencolok) */
.qt-btn.danger.ready{
  background: linear-gradient(135deg,#ef4444,#f97316,#f59e0b);
  box-shadow: 0 14px 40px rgba(239,68,68,.22);
  animation: qzPulse 1.15s infinite;
}
@keyframes qzPulse{
  0%{ transform: translateY(0); filter: brightness(1); }
  50%{ transform: translateY(-1px); filter: brightness(1.05); }
  100%{ transform: translateY(0); filter: brightness(1); }
}

.qt-input{
  width:100%;
  border-radius: 12px;
  border:1px solid rgba(0,0,0,.15);
  padding: 12px 12px;
  outline:none;
  background: rgba(255,255,255,.9);
}
.qt-input:focus{
  border-color: rgba(99,102,241,.55);
  box-shadow: 0 0 0 4px rgba(99,102,241,.15);
}

.qt-actions{ margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; }
.qt-footnote{ margin-top:10px; opacity:.7; font-size:.95rem; }

/* media/file/youtube block */
.qt-qmedia{ margin-top:12px; display:grid; gap:10px; }
.qt-qmedia img{
  max-width:100%;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.10);
  background:#fff;
}
.qt-file{
  display:flex;
  gap:10px;
  align-items:center;
  padding:10px 12px;
  border-radius:12px;
  background: rgba(255,255,255,.75);
  border:1px solid rgba(0,0,0,.10);
}
.qt-file a{ font-weight:900; text-decoration:none; }
.qt-file small{ opacity:.7; }

.qt-yt{
  position:relative;
  width:100%;
  padding-top:56.25%;
  border-radius:12px;
  overflow:hidden;
  border:1px solid rgba(0,0,0,.10);
  background:#000;
}
.qt-yt iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; }

/* Fix paragraph spacing for HTML (<p>) coming from WYSIWYG/Passage */
#passageText p,
#questionText p {
  margin: 0 0 12px;
}

#passageText p:last-child,
#questionText p:last-child {
  margin-bottom: 0;
}

/* Optional: list spacing */
#passageText ul, #passageText ol,
#questionText ul, #questionText ol {
  margin: 0 0 12px;
  padding-left: 20px;
}

#passageText li, #questionText li {
  margin: 6px 0;
}


:root{ --qz-top-offset: 0px; }

/* override padding agar aman kalau layout punya header fixed/sticky */
.qt{
  padding: calc(26px + var(--qz-top-offset)) 16px;
}

/* meta + tombol nav */
.qt-meta{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.qt-meta-left{ text-align:right; }
.qt-navtoggle{
  display:none;            /* desktop: sembunyikan */
  border:1px solid rgba(0,0,0,.12);
  background: rgba(255,255,255,.75);
  border-radius:12px;
  padding:10px 12px;
  font-weight:900;
  cursor:pointer;
  white-space:nowrap;
}

/* tombol close sheet (default: hidden, muncul di mobile) */
.qt-side-top{ display:none; }
.qt-x{
  border:1px solid rgba(0,0,0,.12);
  background:#f1f5f9;
  border-radius:12px;
  padding:8px 10px;
  font-weight:900;
  cursor:pointer;
}

/* ===== MOBILE: ubah sidebar jadi sheet overlay ===== */
@media (max-width: 900px){
  .qt{
    padding: calc(14px + var(--qz-top-offset)) 12px 18px;
  }
  .qt-wrap{ padding: 12px; }

  .qt-meta{
    width:100%;
    align-items:center;
  }
  .qt-meta-left{ text-align:left; }
  .qt-navtoggle{ display:inline-flex; align-items:center; gap:8px; }

  /* overlay container */
  .qt-side{
    position: fixed;
    inset: 0;
    z-index: 40000;
    display:none;
    padding: calc(16px + env(safe-area-inset-top)) 12px calc(16px + env(safe-area-inset-bottom));
    background: rgba(15,23,42,.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    border:0;
    border-radius:0;
  }
  .qt-side.open{ display:block; }

  /* card */
  .qt-side-card{
    max-width: 560px;
    height: calc(100dvh - 32px - env(safe-area-inset-top) - env(safe-area-inset-bottom));
    margin: 0 auto;
    background:#fff;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.12);
    box-shadow: 0 18px 60px rgba(0,0,0,.25);
    display:flex;
    flex-direction:column;
    overflow:hidden;
  }

  .qt-side-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding: 12px 12px 10px;
    border-bottom:1px solid rgba(0,0,0,.08);
  }

  .qt-side-body{
    padding: 12px;
    overflow:auto;
    min-height:0;
  }

  /* biar tidak dobel judul (opsional) */
  /* .qt-side-title, .qt-side-sub{ display:none; } */

  .qt-grid{ grid-template-columns: repeat(8, 1fr); }
}

/* layar kecil banget */
@media (max-width: 420px){
  .qt-grid{ grid-template-columns: repeat(6, 1fr); }
  .qt-dot{ padding:10px 0; font-size:13px; }

  /* actions sticky bottom biar UX enak */
  .qt-actions{
    position: sticky;
    bottom: 10px;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    padding: 10px;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.08);
    z-index: 5;
  }
  .qt-btn{ flex: 1 1 120px; }
  .qt-btn.danger{ margin-left:0; }
}

body.qz-nav-open{
  overflow:hidden;
  touch-action:none;
}

/* Studio changes only the palette; layout and dimensions remain shared with Default. */
.qt-theme-studio{
  background:#f2f4ee;
  color:#171a24;
}
.qt-theme-studio .qt-title,
.qt-theme-studio .qt-side-title,
.qt-theme-studio .qt-qtext,
.qt-theme-studio .qt-pass-title{
  color:#10162f;
}
.qt-theme-studio .qt-badge,
.qt-theme-studio .qt-help,
.qt-theme-studio .qt-file a{
  color:#4d5bd5;
}
.qt-theme-studio .qt-sub,
.qt-theme-studio .qt-meta,
.qt-theme-studio .qt-side-sub,
.qt-theme-studio .qt-side-note,
.qt-theme-studio .qt-qmeta,
.qt-theme-studio .qt-footnote{
  color:#596174;
  opacity:1;
}
.qt-theme-studio .qt-side,
.qt-theme-studio .qt-main .qt-card{
  background:rgba(255,255,255,.94);
  border-color:#ccd2ca;
}
.qt-theme-studio .qt-side{ box-shadow:6px 6px 0 #10162f; }
.qt-theme-studio .qt-main .qt-card{ box-shadow:10px 10px 0 #10162f; }
.qt-theme-studio .qt-sec{
  background:#fff;
  border-color:#ccd2ca;
  color:#10162f;
}
.qt-theme-studio .qt-sec.active{
  background:#e8eaff;
  border-color:#7783ff;
  outline-color:rgba(119,131,255,.24);
}
.qt-theme-studio .qt-sec.done{
  background:#d9ff63;
  border-color:#a8c84c;
}
.qt-theme-studio .qt-dot,
.qt-theme-studio .qt-dot.todo{
  background:#fff;
  border-color:#ccd2ca;
  color:#10162f;
}
.qt-theme-studio .qt-dot.done{
  background:#d9ff63;
  border-color:#a8c84c;
}
.qt-theme-studio .qt-dot.reviewed{ box-shadow:inset 0 0 0 3px #ff826b; }
.qt-theme-studio .qt-dot.active{
  border-color:#7783ff;
  outline-color:rgba(119,131,255,.28);
}
.qt-theme-studio .qt-catpill{
  background:#d9ff63;
  border-color:#a8c84c;
  color:#10162f;
}
.qt-theme-studio .qt-pass{
  background:#f0f1ff;
  border-color:#c4c9ff;
}
.qt-theme-studio .qt-answers .qt-choice-input{ accent-color:#10162f; }
.qt-theme-studio .qt-answers label{
  background:#fff !important;
  border-color:#ccd2ca !important;
  color:#171a24;
}
.qt-theme-studio .qt-answers label > span{
  background:#e8eaff !important;
  border-color:#c4c9ff !important;
  color:#10162f;
}
.qt-theme-studio .qt-answers label:has(.qt-choice-input:checked){
  background:#d9ff63 !important;
  border-color:#10162f !important;
  box-shadow:6px 6px 0 #10162f;
}
.qt-theme-studio .qt-answers label:has(.qt-choice-input:checked) > span{
  background:#10162f !important;
  border-color:#10162f !important;
  color:#fff;
}
.qt-theme-studio .qt-answers label:has(.qt-choice-input:focus-visible){
  outline:3px solid #7783ff;
  outline-offset:2px;
}
.qt-theme-studio .qt-btn.primary{
  background:#10162f;
  color:#fff;
}
.qt-theme-studio .qt-btn.ghost,
.qt-theme-studio .qt-navtoggle,
.qt-theme-studio .qt-x{
  background:#fff;
  border-color:#ccd2ca;
  color:#10162f;
}
.qt-theme-studio .qt-btn.danger{
  background:#ff826b;
  color:#10162f;
}
.qt-theme-studio .qt-btn.danger.ready,
.qt-theme-studio .qt-btn.danger.qz-submit-ready{
  background:#ff826b;
  color:#10162f;
}
.qt-theme-studio .qt-btn:hover:not([disabled]){
  transform:translateY(-2px);
  box-shadow:0 7px 0 rgba(16,22,47,.16);
}
.qt-theme-studio .qt-input{
  background:#fff;
  border-color:#aeb6ae;
  color:#171a24;
}
.qt-theme-studio .qt-input:focus{
  border-color:#7783ff;
  box-shadow:0 0 0 4px rgba(119,131,255,.18);
}
.qt-theme-studio .qt-file{
  background:#fff;
  border-color:#ccd2ca;
}
.qt-theme-studio .qt-qmedia img{ border-color:#ccd2ca; }
@media (max-width:900px){
  .qt-theme-studio .qt-side{ background:rgba(16,22,47,.64); box-shadow:none; }
  .qt-theme-studio .qt-side-card{ background:#fff; border-color:#ccd2ca; box-shadow:7px 7px 0 #10162f; }
  .qt-theme-studio .qt-side-top{ border-bottom-color:#ccd2ca; }
  .qt-theme-studio .qt-main .qt-card{ box-shadow:6px 6px 0 #10162f; }
}
@media (max-width:420px){
  .qt-theme-studio .qt-actions{
    background:rgba(255,255,255,.96);
    border-color:#ccd2ca;
  }
  .qt-theme-studio .qt-answers label:has(.qt-choice-input:checked){ box-shadow:4px 4px 0 #10162f; }
}

/* Navy uses a restrained navy, warm-gold, and ivory system with soft depth. */
.qt-theme-navy{
  min-height:100vh;
  background:
    radial-gradient(760px 500px at 6% 2%,rgba(252,188,85,.16),transparent 58%),
    radial-gradient(720px 480px at 96% 8%,rgba(39,73,119,.13),transparent 58%),
    linear-gradient(180deg,#fffaf0 0%,#fff 56%,#f7f8f4 100%);
  color:#132842;
  font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.qt-theme-navy .qt-title,
.qt-theme-navy .qt-side-title,
.qt-theme-navy .qt-qtext,
.qt-theme-navy .qt-pass-title{ color:#132842; letter-spacing:-.02em; }
.qt-theme-navy .qt-badge{
  display:inline-flex;
  padding:7px 11px;
  border:1px solid #132842;
  border-radius:999px;
  background:#132842;
  color:#ffd992;
  opacity:1;
}
.qt-theme-navy .qt-help,
.qt-theme-navy .qt-file a{ color:#274977; }
.qt-theme-navy .qt-sub,
.qt-theme-navy .qt-meta,
.qt-theme-navy .qt-side-sub,
.qt-theme-navy .qt-side-note,
.qt-theme-navy .qt-qmeta,
.qt-theme-navy .qt-footnote{ color:#5f6f82; opacity:1; }
.qt-theme-navy .qt-side,
.qt-theme-navy .qt-main .qt-card{
  border-color:rgba(19,40,66,.14);
  border-radius:24px;
  background:rgba(255,255,255,.94);
  box-shadow:0 18px 50px rgba(11,31,53,.08);
  backdrop-filter:blur(12px);
}
.qt-theme-navy .qt-main .qt-card{ padding:clamp(18px,3vw,28px); }
.qt-theme-navy .qt-sec{
  border-color:rgba(19,40,66,.14);
  background:#fff;
  color:#132842;
}
.qt-theme-navy .qt-sec.active{
  border-color:#fcbc55;
  background:#fff1d4;
  outline-color:rgba(252,188,85,.24);
}
.qt-theme-navy .qt-sec.done{
  border-color:rgba(22,134,91,.34);
  background:#e7f6ef;
}
.qt-theme-navy .qt-dot,
.qt-theme-navy .qt-dot.todo{
  border-color:rgba(19,40,66,.14);
  background:#fff;
  color:#132842;
}
.qt-theme-navy .qt-dot.done{
  border-color:rgba(22,134,91,.34);
  background:#e7f6ef;
  color:#0f6545;
}
.qt-theme-navy .qt-dot.reviewed{ box-shadow:inset 0 0 0 3px #d78a18; }
.qt-theme-navy .qt-dot.active{
  border-color:#fcbc55;
  outline-color:rgba(252,188,85,.28);
}
.qt-theme-navy .qt-catpill{
  border-color:#132842;
  background:#132842;
  color:#ffd992;
}
.qt-theme-navy .qt-pass{
  border-color:rgba(19,40,66,.14);
  background:#f7f8f4;
}
.qt-theme-navy .qt-answers .qt-choice-input{ accent-color:#132842; }
.qt-theme-navy .qt-answers label{
  border-color:rgba(19,40,66,.14) !important;
  border-radius:16px !important;
  background:#fff !important;
  color:#132842;
}
.qt-theme-navy .qt-answers label > span{
  border-color:rgba(19,40,66,.16) !important;
  background:#f7f8f4 !important;
  color:#132842;
}
.qt-theme-navy .qt-answers label:has(.qt-choice-input:checked){
  border-color:#132842 !important;
  background:#fff1d4 !important;
  box-shadow:0 14px 32px rgba(11,31,53,.13);
}
.qt-theme-navy .qt-answers label:has(.qt-choice-input:checked) > span{
  border-color:#132842 !important;
  background:#132842 !important;
  color:#ffd992;
}
.qt-theme-navy .qt-answers label:has(.qt-choice-input:focus-visible){
  outline:3px solid #fcbc55;
  outline-offset:3px;
}
.qt-theme-navy .qt-btn.primary{
  background:#132842;
  color:#fffaf0;
  box-shadow:0 12px 24px rgba(11,31,53,.24);
}
.qt-theme-navy .qt-btn.primary:hover:not([disabled]){
  background:#fcbc55;
  color:#132842;
  transform:translateY(-2px);
}
.qt-theme-navy .qt-btn.ghost,
.qt-theme-navy .qt-navtoggle,
.qt-theme-navy .qt-x{
  border-color:rgba(19,40,66,.16);
  background:#fff;
  color:#132842;
}
.qt-theme-navy .qt-btn.danger,
.qt-theme-navy .qt-btn.danger.ready,
.qt-theme-navy .qt-btn.danger.qz-submit-ready{
  background:#b42318;
  color:#fff;
}
.qt-theme-navy .qt-btn:hover:not([disabled]){ box-shadow:0 12px 24px rgba(11,31,53,.14); }
.qt-theme-navy .qt-input{
  border-color:rgba(19,40,66,.18);
  background:#fff;
  color:#132842;
}
.qt-theme-navy .qt-input:focus{
  border-color:#fcbc55;
  box-shadow:0 0 0 4px rgba(252,188,85,.30);
}
.qt-theme-navy .qt-file{
  border-color:rgba(19,40,66,.14);
  background:#fff;
}
.qt-theme-navy .qt-qmedia img{ border-color:rgba(19,40,66,.14); }
@media (max-width:900px){
  .qt-theme-navy .qt-side{ background:rgba(11,31,53,.68); box-shadow:none; }
  .qt-theme-navy .qt-side-card{
    border-color:rgba(19,40,66,.16);
    border-radius:24px;
    background:#fff;
    box-shadow:0 28px 70px rgba(11,31,53,.24);
  }
  .qt-theme-navy .qt-side-top{ border-bottom-color:rgba(19,40,66,.12); }
}
@media (max-width:420px){
  .qt-theme-navy .qt-actions{
    border-color:rgba(19,40,66,.14);
    background:rgba(255,255,255,.96);
  }
}
@media (prefers-reduced-motion:reduce){
  .qt-theme-navy .qt-btn,
  .qt-theme-navy .qt-sec,
  .qt-theme-navy .qt-dot,
  .qt-theme-navy .qt-answers label{ animation:none !important; transition:none !important; }
}

</style>

</section>
HTML;

// append JS as string (no premature output)
require_once __DIR__ . '/_take_js.php';
if (isset($take_js_html) && is_string($take_js_html)) {
  $content_html .= $take_js_html;
}
