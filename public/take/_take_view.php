<?php
// /quiz/take/_take_view.php
declare(strict_types=1);

// kalau _take_ctx sudah bikin error page
if (isset($content_html) && is_string($content_html) && trim($content_html) !== '') {
  return;
}
if (!isset($ctx) || !is_array($ctx)) {
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">Konteks quiz tidak valid.</div>';
  return;
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$quizTitle = h((string)($ctx['quiz']['title'] ?? 'Quiz'));
$quizDescRaw  = trim((string)($ctx['quiz']['description'] ?? ''));
$quizDesc  = $quizDescRaw ? '<div class="qt-sub">'.h($quizDescRaw).'</div>' : '';

$total = (int)($ctx['total'] ?? 0);
$answeredCount = (int)($ctx['answered_count'] ?? 0);

$navSections = (isset($ctx['nav_sections']) && is_array($ctx['nav_sections'])) ? $ctx['nav_sections'] : [];
$navCatMap   = (isset($ctx['nav_cat_map']) && is_array($ctx['nav_cat_map'])) ? $ctx['nav_cat_map'] : [];
$allowReview = !empty($ctx['allow_mark_for_review']);

// build sections buttons
$sectionsHtml = '';
if ($navSections) {
  foreach ($navSections as $s) {
    $nm = h((string)($s['name'] ?? 'Lainnya'));
    $st = (int)($s['start'] ?? 1);
    $en = (int)($s['end'] ?? $st);
    $sectionsHtml .= '<button type="button" class="qt-sec" data-start="'.$st.'" data-end="'.$en.'" data-seq="'.$st.'">'
      .$nm.' <span class="qt-sec-range">'.$st.'–'.$en.'</span>'
      .'</button>';
  }
}

$durMin = (int)($ctx['quiz']['duration_minutes'] ?? 0);
$durationHtml = ($durMin > 0)
  ? '<div class="qt-mini">Duration: <b>'.(int)$durMin.' minute</b></div>'
  : '';

// nav dots (with category tooltip)
$grid = '';
for ($i=1; $i<=$total; $i++) {
  $done = !empty($ctx['answered_map'][$i]);

  $cname = '';
  if (!empty($navCatMap[$i]['name'])) $cname = (string)$navCatMap[$i]['name'];
  $cname = trim($cname);
  if ($cname === '') $cname = 'Lainnya';

  $title = 'Soal '.$i.' • '.$cname;
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
  "Hello Admin, I need assistance during the exam.\n"
  ."Quiz: ".(string)($ctx['quiz']['title'] ?? '')."\n"
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

<section class="qt">
  <div class="qt-wrap">

    <div class="qt-head">
      <div>
        <div class="qt-badge">TAKE QUIZ</div>
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

</style>

</section>
HTML;

// append JS as string (no premature output)
require_once __DIR__ . '/_take_js.php';
if (isset($take_js_html) && is_string($take_js_html)) {
  $content_html .= $take_js_html;
}
