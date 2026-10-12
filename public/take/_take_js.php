<?php
// /quiz/take/_take_js.php
declare(strict_types=1);

$quizId       = (int)($ctx['quiz_id'] ?? 0);
$attemptAlias = (string)($ctx['attempt_alias'] ?? '');
$total        = (int)($ctx['total'] ?? 0);
$csrfToken    = qz_public_csrf_token();

$scriptJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$attemptJson     = json_encode($attemptAlias, $scriptJsonFlags);
$answeredMapJson = json_encode(($ctx['answered_map'] ?? []), $scriptJsonFlags);
$reviewMapJson   = json_encode(($ctx['review_map'] ?? []), $scriptJsonFlags);
$timerJson       = json_encode([
  'server_now_ts' => (int)($ctx['server_now_ts'] ?? 0),
  'duration_sec' => (int)($ctx['duration_sec'] ?? 0),
  'ends_at_ts' => (int)($ctx['ends_at_ts'] ?? 0),
  'warning_seconds' => $ctx['warning_seconds'] ?? [],
], $scriptJsonFlags);
$csrfTokenJson   = json_encode($csrfToken, $scriptJsonFlags);
$visualThemeJson = json_encode(quiz_safe_visual_theme($ctx['quiz']['visual_theme'] ?? 'default'), $scriptJsonFlags);

// OPTIONAL (kalau sudah kamu isi di _take_ctx.php)
$navSectionsJson = json_encode(($ctx['nav_sections'] ?? []), $scriptJsonFlags);
$navCatMapJson   = json_encode(($ctx['nav_cat_map'] ?? []), $scriptJsonFlags);
$apiGetJson      = json_encode(quiz_public_url('api/get_question.php'), $scriptJsonFlags);
$apiSaveJson     = json_encode(quiz_public_url('api/save_answer.php'), $scriptJsonFlags);
$apiSubmitJson   = json_encode(quiz_public_url('api/submit.php'), $scriptJsonFlags);
$apiReviewJson   = json_encode(quiz_public_url('api/set_review.php'), $scriptJsonFlags);
$proctorApiJson  = json_encode(quiz_public_url('api/proctor_event.php'), $scriptJsonFlags);
$requireFullscreenJson = !empty($quiz['proctor']['requireFullscreen']) ? 'true' : 'false';
$pauseWhenBlurJson = !empty($quiz['proctor']['pauseWhenBlur']) ? 'true' : 'false';
$blockContextMenuJson = !empty($quiz['proctor']['blockContextMenu']) ? 'true' : 'false';
$blockShortcutsJson = !empty($quiz['proctor']['blockShortcuts']) ? 'true' : 'false';
$devtoolsDetectJson = !empty($quiz['proctor']['devtoolsDetect']) ? 'true' : 'false';
$showExamBadgeJson = !empty($quiz['proctor']['showBadge']) ? 'true' : 'false';

if ($attemptJson === false) $attemptJson = '""';
if ($answeredMapJson === false) $answeredMapJson = '{}';
if ($reviewMapJson === false) $reviewMapJson = '{}';
if ($timerJson === false) $timerJson = '{}';
if ($csrfTokenJson === false) $csrfTokenJson = '""';
if ($visualThemeJson === false) $visualThemeJson = '"default"';
if ($navSectionsJson === false) $navSectionsJson = '[]';
if ($navCatMapJson === false) $navCatMapJson = '{}';
if ($apiGetJson === false) $apiGetJson = '""';
if ($apiSaveJson === false) $apiSaveJson = '""';
if ($apiSubmitJson === false) $apiSubmitJson = '""';
if ($apiReviewJson === false) $apiReviewJson = '""';
if ($proctorApiJson === false) $proctorApiJson = '""';

$take_js_html = <<<HTML
<script>
(function(){
  "use strict";
  
    // --- Hindari overlap dengan header global yang fixed/sticky (jika ada) ---
  (function setTopOffset(){
    function apply(){
      try{
        const hdr = document.querySelector("header.site-header, .site-header, #siteHeader, header");
        if (!hdr) return;
        const cs = getComputedStyle(hdr);
        if (cs.position !== "fixed" && cs.position !== "sticky") return;
        const h = Math.ceil(hdr.getBoundingClientRect().height || 0);
        if (h > 0 && h < 220) document.documentElement.style.setProperty("--qz-top-offset", h + "px");
      }catch(e){}
    }
    apply();
    window.addEventListener("resize", apply, {passive:true});
  })();
  
  function optLetter(i){
  return String.fromCharCode(65 + i); // 0->A, 1->B, dst
}
function makeOptBadge(i){
  const b = document.createElement("span");
  b.textContent = optLetter(i) + ".";
  b.style.display = "inline-flex";
  b.style.alignItems = "center";
  b.style.justifyContent = "center";
  b.style.minWidth = "30px";
  b.style.height = "28px";
  b.style.borderRadius = "10px";
  b.style.border = "1px solid rgba(0,0,0,.10)";
  b.style.background = "rgba(99,102,241,.10)";
  b.style.fontWeight = "900";
  b.style.fontSize = "12px";
  b.style.flex = "0 0 auto";
  return b;
}

  const quizId  = {$quizId};
  const attempt = {$attemptJson};
  const total   = {$total};
  const CSRF_TOKEN = {$csrfTokenJson};
  const VISUAL_THEME = {$visualThemeJson};
  document.body.classList.add("qz-theme-" + VISUAL_THEME);

  // optional (untuk UI kategori/range)
  const NAV_SECTIONS = {$navSectionsJson} || [];
  const NAV_CAT_MAP  = {$navCatMapJson} || {};

  let answeredMap = {$answeredMapJson} || {};
  let reviewMap = {$reviewMapJson} || {};
  let currentSeq = null;
  let currentQuestionId = null;

  let qLoadBusy = false;
  let qLoadAbort = null;

  const elProgress = document.getElementById("progressText");
  const elMeta = document.getElementById("questionMeta");
  if (elMeta) elMeta.style.display = "none";
  const elText = document.getElementById("questionText");
  const elMedia = document.getElementById("questionMedia");

  // OPTIONAL UI (kalau elemen ada di _take_view.php)
  const elCatPill = document.getElementById("categoryPill");

  const elPassBox   = document.getElementById("passageBox");
  const elPassTitle = document.getElementById("passageTitle");
  const elPassText  = document.getElementById("passageText");
  const elPassMedia = document.getElementById("passageMedia");

  const elAnswers = document.getElementById("answersBox");
  const btnPrev = document.getElementById("btnPrev");
  const btnNext = document.getElementById("btnNext");
  const btnSubmit = document.getElementById("btnSubmit");
  const btnReview = document.getElementById("btnReview");
  const alertBox = document.getElementById("alertBox");
  const navGrid = document.getElementById("navGrid");
  const navSections = document.getElementById("navSections");
  const elTimer = document.getElementById("timerText");
  
    // --- Mobile Navigation Sheet ---
  const qtSide = document.getElementById("qtSide");
  const btnNavToggle = document.getElementById("qtNavToggle");
  const btnNavClose  = document.getElementById("qtNavClose");

  function isMobileNav(){
    return !!(window.matchMedia && window.matchMedia("(max-width: 900px)").matches);
  }
  function openNav(){
    if (!qtSide) return;
    qtSide.classList.add("open");
    document.body.classList.add("qz-nav-open");
    if (btnNavToggle) btnNavToggle.setAttribute("aria-expanded","true");
  }
  function closeNav(){
    if (!qtSide) return;
    qtSide.classList.remove("open");
    document.body.classList.remove("qz-nav-open");
    if (btnNavToggle) btnNavToggle.setAttribute("aria-expanded","false");
  }

  if (btnNavToggle) btnNavToggle.addEventListener("click", openNav);
  if (btnNavClose) btnNavClose.addEventListener("click", closeNav);

  // tap area gelap untuk close
  if (qtSide) qtSide.addEventListener("click", (e)=>{ if (e.target === qtSide) closeNav(); });

  // ESC untuk close
  document.addEventListener("keydown", (e)=>{ if (e.key === "Escape") closeNav(); }, {capture:true});

  // auto-close setelah klik dot/section (mobile)
  function maybeCloseNavAfterPick(e){
    if (!isMobileNav()) return;
    const hitDot = e.target && e.target.closest ? e.target.closest(".qt-dot") : null;
    const hitSec = e.target && e.target.closest ? e.target.closest(".qt-sec") : null;
    if (hitDot || hitSec) closeNav();
  }
  if (navGrid) navGrid.addEventListener("click", maybeCloseNavAfterPick);
  if (navSections) navSections.addEventListener("click", maybeCloseNavAfterPick);


  function renderMDWithMath(md, targetEl){
    if (!targetEl) return;
    const raw = String(md || "");

    if (!window.marked || !window.DOMPurify){
      targetEl.textContent = raw;
      return;
    }

    let html = "";
    try{
      html = marked.parse(raw, { mangle:false, headerIds:false });
    }catch(e){
      html = "<pre>Markdown error</pre>";
    }

    const clean = DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
    targetEl.innerHTML = clean;

    if (typeof window.renderMathInElement === "function"){
      try{
        renderMathInElement(targetEl, {
          delimiters: [
            {left:"$$", right:"$$", display:true},
            {left:"$",  right:"$",  display:false},
            {left:"\\\\(", right:"\\\\)", display:false},
            {left:"\\\\[", right:"\\\\]", display:true},
          ],
          throwOnError:false,
          strict:"ignore",
          ignoredTags:["script","noscript","style","textarea","pre","code"],
        });
      }catch(e){}
    }
  }

  function youtubeId(url){
    const u = String(url||"").trim();
    if (!u) return "";
    try{
      const parsed = new URL(u);
      const host = (parsed.hostname||"").toLowerCase();
      if (host.includes("youtu.be")) return parsed.pathname.replace("/","");
      if (host.includes("youtube.com")) return parsed.searchParams.get("v") || "";
    }catch(e){}
    const m = u.match(/(?:v=|youtu\\.be\\/)([a-zA-Z0-9_-]{6,})/);
    return m ? m[1] : "";
  }

  function isPdfUrl(u){
    const s = String(u||"").trim();
    if (!s) return false;
    const clean = s.split("#")[0].split("?")[0].toLowerCase();
    return clean.endsWith(".pdf");
  }
  
    function labelOther(name){
    const t = String(name || "").trim();
    if (!t) return "Other";
    if (t.toLowerCase() === "lainnya") return "Other";
    return t;
  }


  // render media into ANY container (question / passage)
  function renderMediaInto(targetEl, payload, imageAlt){
    if (!targetEl) return;
    targetEl.innerHTML = "";
    let has = false;

    const imageUrl = String(payload.image_url || "");
    const fileId   = payload.file_id ? Number(payload.file_id) : 0;
    const fileLabel= String(payload.file_label || "");
    const fileUrl  = String(payload.file_url || "");
    const ytUrl    = String(payload.youtube_url || "");

    // image
    if (imageUrl){
      const img = document.createElement("img");
      img.src = imageUrl;
      img.alt = imageAlt || "Gambar";
      targetEl.appendChild(img);
      has = true;
    }

    // file: hanya embed PDF, selain itu tidak ditampilkan
    if (fileId && fileUrl && isPdfUrl(fileUrl)){
      const box = document.createElement("div");
      box.className = "qt-file";

      const left = document.createElement("div");
      const title = document.createElement("b");
      title.textContent = "PDF attached";
      const label = document.createElement("small");
      label.textContent = fileLabel || ("File #" + fileId);
      left.append(title, document.createElement("br"), label);
      box.appendChild(left);

      const a = document.createElement("a");
      a.href = fileUrl;
      a.target = "_blank";
      a.rel = "noopener";
      a.textContent = "Buka";
      box.appendChild(a);

      targetEl.appendChild(box);

      const iframe = document.createElement("iframe");
      iframe.src = fileUrl;
      iframe.setAttribute("loading","lazy");
      iframe.style.width = "100%";
      iframe.style.height = "520px";
      iframe.style.border = "0";
      iframe.style.borderRadius = "12px";
      iframe.style.background = "#fff";
      targetEl.appendChild(iframe);

      has = true;
    }

    // youtube
    if (ytUrl){
      const vid = youtubeId(ytUrl);
      if (vid){
        const yt = document.createElement("div");
        yt.className = "qt-yt";
        yt.innerHTML = '<iframe allowfullscreen src="https://www.youtube-nocookie.com/embed/'+encodeURIComponent(vid)+'"></iframe>';
        targetEl.appendChild(yt);
        has = true;
      }
    }

    targetEl.style.display = has ? "grid" : "none";
  }

  function setNavBusy(busy){
    const cur = Number(currentSeq || 0);
    if (btnPrev) btnPrev.disabled = busy || !(cur > 1);
    if (btnNext) btnNext.disabled = busy || !(cur < total);
    if (navGrid) navGrid.style.pointerEvents = busy ? "none" : "";
    if (navSections) navSections.style.pointerEvents = busy ? "none" : "";
  }

  // ===== API PATH =====
  const API_GET    = {$apiGetJson};
  const API_SAVE   = {$apiSaveJson};
  const API_SUBMIT = {$apiSubmitJson};
  const API_REVIEW = {$apiReviewJson};
  const PROCTOR_API = {$proctorApiJson};

  // ===== TIMER STATE =====
  let serverOffsetSec = 0;
  let endsAtTs = 0;
  let durationSec = 0;
  let timerTrusted = false;

  let timerTicking = false;
  let timerHandle = null;
  let timeUpTriggered = false;
  let warningSeconds = [];
  const shownTimerWarnings = new Set();
  const timerWarningKey = "take_timer_warnings_" + attempt;
  try {
    JSON.parse(sessionStorage.getItem(timerWarningKey) || "[]").forEach(value=>shownTimerWarnings.add(Number(value)));
  } catch(e) {}

  function nowServerTs(){
    return Math.floor(Date.now()/1000) + serverOffsetSec;
  }

  function pad2(n){ n = Math.floor(Math.abs(n)); return (n<10 ? "0"+n : ""+n); }
  function fmtMMSS(sec){
    sec = Math.max(0, Math.floor(sec));
    const m = Math.floor(sec/60);
    const s = sec % 60;
    return pad2(m) + ":" + pad2(s);
  }

  function syncTimerFrom(resp){
    const t = resp && resp.timer ? resp.timer : null;
    if (!t) return;

    const sn = Number(t.server_now_ts || 0);
    const ea = Number(t.ends_at_ts || 0);
    const du = Number(t.duration_sec || 0);
    if (Array.isArray(t.warning_seconds)) {
      warningSeconds = Array.from(new Set(t.warning_seconds.map(Number).filter(n=>n>0))).sort((a,b)=>b-a);
    }

    if (sn > 0) {
      const clientNow = Math.floor(Date.now()/1000);
      serverOffsetSec = sn - clientNow;
    }
    if (du > 0) durationSec = du;

    if (ea > 0) {
      endsAtTs = ea;
      timerTrusted = true;
      startTimerLoop();
    }
  }

  function renderTimer(){
    if (!elTimer) return;

    if (!timerTrusted || durationSec <= 0 || endsAtTs <= 0) {
      elTimer.textContent = "--:--";
      return;
    }

    const remain = endsAtTs - nowServerTs();
    if (remain <= 0) {
      elTimer.textContent = "00:00";
      if (!timeUpTriggered) {
        timeUpTriggered = true;
        onTimeUp();
      }
      return;
    }

    warningSeconds.forEach(threshold=>{
      if (remain <= threshold && !shownTimerWarnings.has(threshold)) {
        shownTimerWarnings.add(threshold);
        try { sessionStorage.setItem(timerWarningKey, JSON.stringify(Array.from(shownTimerWarnings))); } catch(e) {}
        showAlert("error", "Time warning: " + fmtMMSS(remain) + " remaining.");
      }
    });

    elTimer.textContent = fmtMMSS(remain);
  }

  function startTimerLoop(){
    if (timerTicking) return;
    timerTicking = true;
    timerHandle = setInterval(renderTimer, 250);
    renderTimer();
  }

  async function apiRaw(url, data, signal){
    const res = await fetch(url, {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF-Token": CSRF_TOKEN},
      body: JSON.stringify(data || {}),
      signal,
      credentials: "same-origin"
    });

    const txt = await res.text();
    let json = null;
    try{
      json = JSON.parse(txt);
    }catch(e){
      const snip = String(txt || "").replace(/\s+/g," ").slice(0, 180);
      throw new Error("Response bukan JSON ("+res.status+"): " + (snip || "EMPTY"));
    }

    if (json && (json.timer || (json.data && json.data.timer))) {
      if (json.timer) syncTimerFrom(json);
      else syncTimerFrom({timer: json.data.timer});
    }

    if (!res.ok || !json.ok) {
      const timeUp = !!(json.data && json.data.time_up);
      if (timeUp && !timeUpTriggered) {
        timeUpTriggered = true;
        onTimeUp();
      }
      throw new Error(json.error || ("Request gagal ("+res.status+")"));
    }

    return json;
  }

  /* ==========================================================
     PROCTOR / EXAM MODE (tetap)
     ========================================================== */
  const PROCTOR = {
    requireFullscreen: {$requireFullscreenJson},
    pauseWhenBlur: {$pauseWhenBlurJson},
    blockContextMenu: {$blockContextMenuJson},
    blockShortcuts: {$blockShortcutsJson},
    warnLimit: 8,
    devtoolsDetect: {$devtoolsDetectJson},
    devtoolsThreshold: 160,
    showBadge: {$showExamBadgeJson},
  };

  const WARN_KEY = "take_warn_" + attempt;
  let warnCount = 0;
  try { warnCount = Number(localStorage.getItem(WARN_KEY) || "0") || 0; } catch(e){ warnCount = 0; }

  let submitting = false;

  const proctorLastSent = Object.create(null);
  async function logProctor(eventType, meta){
    const now = Date.now();
    const last = proctorLastSent[eventType] || 0;
    if (now - last < 1200) return;
    proctorLastSent[eventType] = now;

    try{
      await fetch(PROCTOR_API, {
        method: "POST",
        headers: {"Content-Type":"application/json", "X-CSRF-Token": CSRF_TOKEN},
        body: JSON.stringify({
          quiz_id: quizId,
          attempt: attempt,
          event_type: eventType,
          event_meta: meta || null
        }),
        credentials: "same-origin"
      });
    }catch(e){}
  }

  function isTypingTarget(el){
    if (!el) return false;
    const tag = (el.tagName || "").toLowerCase();
    if (tag === "textarea") return true;
    if (tag === "input") {
      const t = (el.getAttribute("type") || "text").toLowerCase();
      return ["text","number","email","tel","search","url","password"].includes(t);
    }
    if (el.isContentEditable) return true;
    return false;
  }

  // inject style + overlay (sama seperti punyamu)
  const css = document.createElement("style");
  css.textContent = `
    html, body { height: 100%; }
    body.qz-exam-lock { overflow: hidden; }
    body.qz-exam-nosel { user-select: none; -webkit-user-select:none; }
    .qt-input { user-select: text !important; -webkit-user-select:text !important; }

    .qt-btn.danger.qz-submit-ready{
      background: linear-gradient(135deg,#ef4444,#f97316,#f59e0b);
      box-shadow: 0 14px 40px rgba(0,0,0,.22);
      animation: qzPulse 1.1s ease-in-out infinite;
    }
    @keyframes qzPulse{
      0%{ transform: translateY(0); filter: brightness(1); }
      50%{ transform: translateY(-1px); filter: brightness(1.07); }
      100%{ transform: translateY(0); filter: brightness(1); }
    }

    #qzProctorOverlay{
      position: fixed; inset: 0; z-index: 99999;
      display: none;
      background: rgba(15, 23, 42, .72);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      padding: 18px;
    }
    #qzProctorCard{
      max-width: 520px;
      margin: 8vh auto 0;
      background: #fff;
      border-radius: 16px;
      padding: 16px;
      border: 1px solid rgba(0,0,0,.12);
      box-shadow: 0 18px 60px rgba(0,0,0,.25);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    #qzProctorTop{ display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
    #qzProctorTitle{ font-weight: 900; font-size: 16px; margin: 0; }
    #qzProctorBadge{
      font-weight: 900; font-size: 12px; letter-spacing:.12em;
      padding: 6px 10px; border-radius: 999px;
      background: rgba(79,70,229,.10); color: #3730a3;
      border:1px solid rgba(79,70,229,.18);
      white-space: nowrap;
    }
    #qzProctorMsg{ margin: 10px 0 0; line-height: 1.45; color: rgba(15,23,42,.88); }
    #qzProctorMini{ margin-top: 10px; font-size: 13px; color: rgba(15,23,42,.70); line-height: 1.45; }
    #qzProctorActions{ margin-top: 14px; display:flex; gap:10px; flex-wrap: wrap; align-items:center; }
    .qzBtn{ border:0; border-radius:12px; padding: 10px 12px; font-weight: 900; cursor: pointer; }
    .qzBtnPrimary{ background: linear-gradient(135deg,#4f46e5,#2563eb,#06b6d4); color:#fff; }
    .qzLine{ margin-top: 12px; height: 1px; background: rgba(15,23,42,.10); }
    #qzProctorRules{
      margin-top: 12px;
      padding-left: 18px;
      color: rgba(15,23,42,.80);
      font-size: 13px;
      line-height: 1.55;
    }

    #qzExamBadge{
      position: fixed;
      left: 10px; bottom: 10px;
      z-index: 9999;
      display: none;
      padding: 8px 10px;
      border-radius: 999px;
      background: rgba(15,23,42,.92);
      color: #fff;
      font-weight: 900;
      font-size: 12px;
      letter-spacing: .10em;
      box-shadow: 0 10px 30px rgba(0,0,0,.18);
    }
    #qzExamBadge small{
      display:inline-block;
      opacity: .75;
      font-weight: 700;
      letter-spacing: normal;
      margin-left: 8px;
      font-size: 12px;
    }

    #qzDoneModal{
      position: fixed; inset: 0; z-index: 99998;
      display:none;
      background: rgba(15,23,42,.55);
      padding: 18px;
    }
    #qzDoneCard{
      max-width: 520px;
      margin: 10vh auto 0;
      background:#fff;
      border-radius: 16px;
      padding: 16px;
      border: 1px solid rgba(0,0,0,.12);
      box-shadow: 0 18px 60px rgba(0,0,0,.25);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    
    #qzSubmitWarn1, #qzSubmitWarn2{
  position: fixed; inset: 0; z-index: 99998;
  display:none;
  background: rgba(15,23,42,.55);
  padding: 18px;
}
#qzSubmitWarn1Card, #qzSubmitWarn2Card{
  max-width: 560px;
  margin: 10vh auto 0;
  background:#fff;
  border-radius: 16px;
  padding: 16px;
  border: 1px solid rgba(0,0,0,.12);
  box-shadow: 0 18px 60px rgba(0,0,0,.25);
  font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
}
#qzWarn1Title, #qzWarn2Title{ font-weight: 900; margin:0; }
#qzWarn1Msg, #qzWarn2Msg{ margin:10px 0 0; color: rgba(15,23,42,.82); line-height:1.45; }
#qzWarn1Actions, #qzWarn2Actions{
  margin-top: 14px;
  display:flex;
  gap:10px;
  justify-content:flex-end;
  flex-wrap:wrap;
}
#qzWarnList{
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(0,0,0,.04);
  border: 1px solid rgba(0,0,0,.08);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
  line-height: 1.5;
  color: rgba(15,23,42,.85);
  white-space: pre-wrap;
}

    
    #qzDoneTitle{ font-weight: 900; margin:0; }
    #qzDoneMsg{ margin:10px 0 0; color: rgba(15,23,42,.82); line-height:1.45; }
    #qzDoneActions{ margin-top: 14px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; }
    .qzBtnGhost{ background:#f1f5f9; color:#0f172a; border:1px solid rgba(15,23,42,.12); }

    body.qz-theme-studio #qzProctorOverlay,
    body.qz-theme-studio #qzDoneModal,
    body.qz-theme-studio #qzSubmitWarn1,
    body.qz-theme-studio #qzSubmitWarn2{ background:rgba(16,22,47,.64); }
    body.qz-theme-studio #qzProctorCard,
    body.qz-theme-studio #qzDoneCard,
    body.qz-theme-studio #qzSubmitWarn1Card,
    body.qz-theme-studio #qzSubmitWarn2Card{
      background:#fff;
      border-color:#10162f;
      color:#171a24;
      box-shadow:10px 10px 0 #10162f;
    }
    body.qz-theme-studio #qzProctorBadge{
      background:#d9ff63;
      border-color:#a8c84c;
      color:#10162f;
    }
    body.qz-theme-studio #qzProctorMsg,
    body.qz-theme-studio #qzProctorMini,
    body.qz-theme-studio #qzProctorRules,
    body.qz-theme-studio #qzDoneMsg,
    body.qz-theme-studio #qzWarn1Msg,
    body.qz-theme-studio #qzWarn2Msg{ color:#596174; }
    body.qz-theme-studio .qzLine{ background:#ccd2ca; }
    body.qz-theme-studio .qzBtnPrimary{ background:#10162f; color:#fff; }
    body.qz-theme-studio .qzBtnGhost{ background:#f2f4ee; border-color:#ccd2ca; color:#10162f; }
    body.qz-theme-studio #qzWarnList{ background:#f2f4ee; border-color:#ccd2ca; color:#10162f; }
    body.qz-theme-studio #qzExamBadge{ background:#10162f; color:#d9ff63; }

  `;
  document.head.appendChild(css);

  const overlay = document.createElement("div");
  overlay.id = "qzProctorOverlay";
  overlay.innerHTML = `
    <div id="qzProctorCard" role="dialog" aria-modal="true">
      <div id="qzProctorTop">
        <div>
          <p id="qzProctorTitle">Exam Mode</p>
          <div id="qzProctorMsg">Please enable fullscreen to continue.</div>
        </div>
        <div id="qzProctorBadge">EXAM MODE</div>
      </div>

      <div class="qzLine"></div>

      <ul id="qzProctorRules">
        <li><b>Fullscreen</b> is required during the exam.</li>
        <li>Avoid <b>switching tabs</b> or opening other applications.</li>
        <li>Right-click & certain shortcuts will be restricted.</li>
      </ul>

      <div id="qzProctorMini"></div>

      <div id="qzProctorActions">
        <button type="button" class="qzBtn qzBtnPrimary" id="qzProctorBtn">Enable Fullscreen</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  const examBadge = document.createElement("div");
  examBadge.id = "qzExamBadge";
  examBadge.innerHTML = 'EXAM MODE <small id="qzWarnText">Warn: 0</small>';
  document.body.appendChild(examBadge);

  const doneModal = document.createElement("div");
  doneModal.id = "qzDoneModal";
  doneModal.innerHTML = `
    <div id="qzDoneCard" role="dialog" aria-modal="true">
      <p id="qzDoneTitle">All answers have been completed ✅</p>
      <div id="qzDoneMsg">Click <b>Submit</b> to send your answers. Once submitted, you cannot retake the quiz.</div>
      <div id="qzDoneActions">
        <button type="button" class="qzBtn qzBtnGhost" id="qzDoneClose">Later</button>
        <button type="button" class="qzBtn qzBtnPrimary" id="qzDoneGo">Submit</button>
      </div>
    </div>
  `;
  document.body.appendChild(doneModal);
  
  let lastMissingSeq = [];

const warn1Modal = document.createElement("div");
warn1Modal.id = "qzSubmitWarn1";
warn1Modal.innerHTML = `
  <div id="qzSubmitWarn1Card" role="dialog" aria-modal="true">
    <p id="qzWarn1Title">Unanswered Questions</p>
    <div id="qzWarn1Msg"></div>
    <div id="qzWarnList" style="display:none"></div>
    <div id="qzWarn1Actions">
      <button type="button" class="qzBtn qzBtnGhost" id="qzWarn1Back">Back</button>
      <button type="button" class="qzBtn qzBtnPrimary" id="qzWarn1Go">Submit anyway</button>
    </div>
  </div>
`;
document.body.appendChild(warn1Modal);

const warn2Modal = document.createElement("div");
warn2Modal.id = "qzSubmitWarn2";
warn2Modal.innerHTML = `
  <div id="qzSubmitWarn2Card" role="dialog" aria-modal="true">
    <p id="qzWarn2Title">Confirm Submission</p>
    <div id="qzWarn2Msg">
      Are you sure you want to submit your answers even though you have not completed all questions?
    </div>
    <div id="qzWarn2Actions">
      <button type="button" class="qzBtn qzBtnGhost" id="qzWarn2Back">Back</button>
      <button type="button" class="qzBtn qzBtnPrimary" id="qzWarn2Yes">Yes, Confirm</button>
    </div>
  </div>
`;
document.body.appendChild(warn2Modal);

const elWarn1Msg  = warn1Modal.querySelector("#qzWarn1Msg");
const elWarnList  = warn1Modal.querySelector("#qzWarnList");
const btnWarn1Back= warn1Modal.querySelector("#qzWarn1Back");
const btnWarn1Go  = warn1Modal.querySelector("#qzWarn1Go");

const btnWarn2Back= warn2Modal.querySelector("#qzWarn2Back");
const btnWarn2Yes = warn2Modal.querySelector("#qzWarn2Yes");

function showWarn1(missing){
  lastMissingSeq = Array.isArray(missing) ? missing.slice() : [];
  const n = lastMissingSeq.length;

  if (elWarn1Msg){
    elWarn1Msg.innerHTML =
      "There are questions you haven't answered yet. " +
      "Please review and complete them first while there is remaining exam time.<br><br>" +
      "<b>Unanswered:</b> " + n + " question(s).";
  }

  if (elWarnList){
    if (n > 0){
      const preview = lastMissingSeq.slice(0, 24).join(", ");
      elWarnList.style.display = "block";
      elWarnList.textContent =
        "Missing ansewer on questions numbers: " + preview + (n > 24 ? " ..." : "");
    } else {
      elWarnList.style.display = "none";
      elWarnList.textContent = "";
    }
  }

  warn1Modal.style.display = "block";
}
function hideWarn1(){ warn1Modal.style.display = "none"; }

function showWarn2(){ warn2Modal.style.display = "block"; }
function hideWarn2(){ warn2Modal.style.display = "none"; }

btnWarn1Back.addEventListener("click", hideWarn1);
btnWarn1Go.addEventListener("click", ()=>{
  hideWarn1();
  showWarn2();
});

btnWarn2Back.addEventListener("click", ()=>{
  hideWarn2();
  showWarn1(lastMissingSeq);
});
btnWarn2Yes.addEventListener("click", async ()=>{
  hideWarn2();
  if (btnSubmit && !btnSubmit.disabled) {
    // submit pakai flag confirm_incomplete
    await doSubmit(false, true);
  }
});


  const elOverlayMsg  = overlay.querySelector("#qzProctorMsg");
  const elOverlayMini = overlay.querySelector("#qzProctorMini");
  const btnFS         = overlay.querySelector("#qzProctorBtn");
  const elWarnText    = examBadge.querySelector("#qzWarnText");

  const btnDoneClose = doneModal.querySelector("#qzDoneClose");
  const btnDoneGo = doneModal.querySelector("#qzDoneGo");

  function setWarnUI(){
    if (elWarnText) elWarnText.textContent = "Warn: " + warnCount;
  }
  setWarnUI();

  function showOverlay(msg, mini){
    document.body.classList.add("qz-exam-lock");
    if (PROCTOR.blockShortcuts) document.body.classList.add("qz-exam-nosel");
    overlay.style.display = "block";
    if (elOverlayMsg) elOverlayMsg.innerHTML = msg || "Exam paused.";
    if (elOverlayMini) elOverlayMini.innerHTML = mini || "";
  }

  function hideOverlay(){
    overlay.style.display = "none";
    document.body.classList.remove("qz-exam-lock");
  }

  function showDoneModal(){ doneModal.style.display = "block"; }
  function hideDoneModal(){ doneModal.style.display = "none"; }

  btnDoneClose.addEventListener("click", hideDoneModal);
  btnDoneGo.addEventListener("click", ()=>{
    hideDoneModal();
    if (btnSubmit && !btnSubmit.disabled) btnSubmit.click();
  });

  function showAlert(type, msg){
    if (!alertBox) return;
    alertBox.style.display = "block";
    alertBox.style.background = type==="error" ? "rgba(220,53,69,.10)" : "rgba(34,197,94,.12)";
    alertBox.style.border = type==="error" ? "1px solid rgba(220,53,69,.25)" : "1px solid rgba(34,197,94,.25)";
    alertBox.style.color = type==="error" ? "#7f1d1d" : "#14532d";
    alertBox.innerText = msg;
    setTimeout(()=>{ alertBox.style.display="none"; }, 2600);
  }

  function addWarn(reason, proctorEventType, meta){
    warnCount++;
    try { localStorage.setItem(WARN_KEY, String(warnCount)); } catch(e){}
    setWarnUI();
    showAlert("error", "Warning ("+warnCount+"): " + reason);
    if (proctorEventType) logProctor(proctorEventType, meta || {reason: reason});
  }

  function fullscreenSupported(){
    return !!(document.documentElement && document.documentElement.requestFullscreen) && !!document.fullscreenEnabled;
  }

  function inFullscreen(){ return !!document.fullscreenElement; }

  function hasFocusAndVisible(){
    if (!PROCTOR.pauseWhenBlur) return true;
    if (document.visibilityState !== "visible") return false;
    return document.hasFocus();
  }

  let devtoolsWasOpen = false;
  function devtoolsLikelyOpen(){
    if (!PROCTOR.devtoolsDetect) return false;
    const gapW = Math.abs((window.outerWidth || 0) - (window.innerWidth || 0));
    const gapH = Math.abs((window.outerHeight || 0) - (window.innerHeight || 0));
    return (gapW > PROCTOR.devtoolsThreshold || gapH > PROCTOR.devtoolsThreshold);
  }

  function proctorOk(){
    if (PROCTOR.requireFullscreen && fullscreenSupported() && !inFullscreen()) return false;
    if (!hasFocusAndVisible()) return false;
    if (devtoolsLikelyOpen()) return false;
    return true;
  }

  function explainProctorBlock(){
    if (PROCTOR.requireFullscreen && fullscreenSupported() && !inFullscreen()) {
      return {
        msg: "Mandatory exam <b>Fullscreen</b>. Enable full screen to continue.",
        mini: "Click the <b>Enable Fullscreen</b> button. If it is rejected, click the button again."
      };
    }
    if (!hasFocusAndVisible()) {
      return { msg: "The exam was paused because you <b>switched tabs/windows</b>.", mini: "Return to the exam tab to continue.." };
    }
    if (devtoolsLikelyOpen()) {
      return { msg: "Detected <b>Developer Tools</b> is open. Close DevTools to continue.", mini: "If detected incorrectly, resize the browser / close the tools panel." };
    }
    return { msg: "Exam paused.", mini: "" };
  }

  async function requestFullscreen(){
    if (!fullscreenSupported()) return true;
    if (inFullscreen()) return true;
    try { await document.documentElement.requestFullscreen(); return true; } catch(e){ return false; }
  }

  async function proctorGate(){
    if (PROCTOR.showBadge) examBadge.style.display = "block";
    btnFS.textContent = fullscreenSupported() ? "Enable Fullscreen" : "Start the Exam";

    if (!proctorOk()) {
      const info = explainProctorBlock();
      showOverlay(info.msg, info.mini);
    }

    return new Promise((resolve) => {
      async function tryResolve(){
        const open = devtoolsLikelyOpen();
        if (open && !devtoolsWasOpen) {
          devtoolsWasOpen = true;
          addWarn("DevTools detected", "devtools_flag", {gap: true});
        }
        if (!open) devtoolsWasOpen = false;

        if (proctorOk()) {
          hideOverlay();
          resolve(true);
          return;
        }
        const info = explainProctorBlock();
        showOverlay(info.msg, info.mini);
      }

      btnFS.addEventListener("click", async ()=>{
        if (fullscreenSupported()) {
          const ok = await requestFullscreen();
          if (!ok) addWarn("Failed to enter fullscreen (browser rejects)", "exit_fullscreen", {reason:"request_denied"});
        }
        tryResolve();
      });

      document.addEventListener("fullscreenchange", ()=>{
        if (PROCTOR.requireFullscreen && fullscreenSupported()) {
          if (!inFullscreen()) addWarn("Exit fullscreen", "exit_fullscreen");
        }
        tryResolve();
      });

      document.addEventListener("visibilitychange", ()=>{
        if (PROCTOR.pauseWhenBlur && document.visibilityState !== "visible") addWarn("Move tab / minimize", "tab_hidden");
        tryResolve();
      });

      window.addEventListener("blur", ()=>{
        if (PROCTOR.pauseWhenBlur) addWarn("Window blur (Out of the exam)", "window_blur");
        tryResolve();
      });

      window.addEventListener("focus", ()=>{ tryResolve(); });

      if (PROCTOR.devtoolsDetect) setInterval(tryResolve, 900);
      tryResolve();
    });
  }

  if (PROCTOR.blockContextMenu) {
    document.addEventListener("contextmenu", (e)=>{
      e.preventDefault();
      addWarn("Right click restricted", "right_click");
      return false;
    }, {capture:true});
  }

  if (PROCTOR.blockShortcuts) {
    document.addEventListener("keydown", (e)=>{
      const k = (e.key || "").toLowerCase();
      const ctrl = e.ctrlKey || e.metaKey;
      const shift = e.shiftKey;
      const alt = e.altKey;

      if ((e.key || "").toLowerCase() === "printscreen") addWarn("PrintScreen detected", "printscreen_flag");

      const block =
        (k === "f12") ||
        (ctrl && shift && ["i","j","c"].includes(k)) ||
        (ctrl && ["u","s","p"].includes(k)) ||
        (ctrl && shift && k === "k") ||
        (alt && k === "d");

      if (block) {
        e.preventDefault();
        e.stopPropagation();
        addWarn("Shortcut dibatasi", "shortcut_blocked", {key: e.key, ctrl: !!ctrl, shift: !!shift, alt: !!alt});
        return false;
      }

      if (ctrl && ["c","x","v"].includes(k) && !isTypingTarget(e.target)) {
        e.preventDefault();
        e.stopPropagation();
        const ev = (k === "v") ? "paste_blocked" : "copy_blocked";
        addWarn("Copy/Paste restricted", ev, {key: e.key});
        return false;
      }
    }, {capture:true});
  }

  document.addEventListener("copy", (e)=>{
    if (PROCTOR.blockShortcuts && !isTypingTarget(e.target)) {
      e.preventDefault();
      addWarn("Copy restricted", "copy_blocked");
    }
  }, {capture:true});

  document.addEventListener("paste", (e)=>{
    if (PROCTOR.blockShortcuts && !isTypingTarget(e.target)) {
      e.preventDefault();
      addWarn("Paste restricted", "paste_blocked");
    }
  }, {capture:true});

  window.addEventListener("beforeunload", (e)=>{
    if (submitting) return;
    e.preventDefault();
    e.returnValue = "";
  });

  /* ==========================================================
     NAV SECTIONS (Kategori + Range)
     - jika #navSections ada tapi kosong, kita render dari NAV_SECTIONS
     ========================================================== */
  function buildSectionsUI(){
    if (!navSections) return;
    if (navSections.children && navSections.children.length > 0) return;
    if (!Array.isArray(NAV_SECTIONS) || NAV_SECTIONS.length === 0) return;

    const frag = document.createDocumentFragment();
    NAV_SECTIONS.forEach(s=>{
      const st = Number(s.start || 0);
      const en = Number(s.end || 0);
      if (!(st > 0 && en > 0)) return;
      const nm = String(s.name || "Lainnya");

      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "qt-sec";
      btn.dataset.start = String(st);
      btn.dataset.end = String(en);
      btn.dataset.seq = String(st);

      const left = document.createElement("span");
      left.textContent = nm;

      const right = document.createElement("span");
      right.className = "qt-sec-range";
      
const mn = Number(s.min || 0);
const mx = (s.max === null || typeof s.max === "undefined") ? null : Number(s.max);

right.textContent =
  st + "–" + en +
  (mn > 0 ? " • min " + mn : "") +
  (mx !== null && mx > 0 ? " • max " + mx : "");


      btn.appendChild(left);
      btn.appendChild(right);

      frag.appendChild(btn);
    });
    navSections.appendChild(frag);
  }

  function setActiveSection(seq){
    if (!navSections) return;
    navSections.querySelectorAll(".qt-sec").forEach(b=>b.classList.remove("active"));
    navSections.querySelectorAll(".qt-sec").forEach(b=>{
      const s = Number(b.dataset.start || 0);
      const e = Number(b.dataset.end || 0);
      if (seq >= s && seq <= e) b.classList.add("active");
    });
  }

  if (navSections) {
    navSections.addEventListener("click", (e)=>{
      const btn = e.target.closest(".qt-sec");
      if (!btn) return;
      const seq = Number(btn.dataset.seq || btn.dataset.start || 0);
      if (seq > 0) loadQuestion(seq);
    });
  }

  /* ==========================================================
     QUIZ LOGIC
     ========================================================== */
  function countAnswered(){
    let c = 0;
    for (let i=1;i<=total;i++) if (answeredMap[i]) c++;
    return c;
  }

  let submitNudged = false;

  function refreshSectionStates(){
    if (!navSections) return;
    navSections.querySelectorAll(".qt-sec").forEach(btn=>{
      const start = Number(btn.dataset.start || 0);
      const end = Number(btn.dataset.end || 0);
      let done = start > 0 && end >= start;
      for (let seq=start; done && seq<=end; seq++) {
        if (!answeredMap[seq]) done = false;
      }
      btn.classList.toggle("done", done);
    });
  }

function refreshProgressUI(){
  const c = countAnswered();
  if (elProgress) elProgress.textContent = c + "/" + total;
  refreshSectionStates();

  if (btnSubmit) {
    const ready = (c === total);
    // jangan disable; tetap bisa submit walau belum lengkap
    btnSubmit.classList.toggle("qz-submit-ready", ready);

    if (ready && !submitNudged) {
      submitNudged = true;
      doneModal.style.display = "block";
    }
  }
}

  function setDotState(seq, done){
    if (!navGrid) return;
    const btn = navGrid.querySelector('.qt-dot[data-seq="'+seq+'"]');
    if (!btn) return;
    btn.classList.toggle("done", !!done);
    btn.classList.toggle("todo", !done);
  }

  function setActiveDot(seq){
    if (!navGrid) return;
    navGrid.querySelectorAll(".qt-dot").forEach(b=> b.classList.remove("active"));
    const btn = navGrid.querySelector('.qt-dot[data-seq="'+seq+'"]');
    if (btn) btn.classList.add("active");
  }

  function setReviewState(seq, marked){
    reviewMap[seq] = marked ? 1 : 0;
    if (navGrid) {
      const dot = navGrid.querySelector('.qt-dot[data-seq="'+seq+'"]');
      if (dot) dot.classList.toggle("reviewed", !!marked);
    }
    if (btnReview && Number(currentSeq) === Number(seq)) {
      btnReview.setAttribute("aria-pressed", marked ? "true" : "false");
      btnReview.textContent = marked ? "Remove review mark" : "Mark for review";
    }
  }

  function ensureProctorOrThrow(){
    if (!proctorOk()) {
      const info = explainProctorBlock();
      showOverlay(info.msg, info.mini);
      throw new Error("The exam is paused. Please fulfill the exam rules to continue.");
    }
  }

  async function api(url, data){
    ensureProctorOrThrow();
    return await apiRaw(url, data);
  }

  // dukung 2 bentuk payload passage:
  // A) payload.passage = { title, passage_text, image_url, file_id, file_url, youtube_url }
  // B) payload.passage_text / payload.passage_title / payload.passage_image_url / payload.passage_file_id / payload.passage_youtube_url (flat)
  function normalizePassage(payload){
    if (!payload) return null;

    if (payload.passage && (payload.passage.passage_text || payload.passage.title)) {
      return {
        title: String(payload.passage.title || "Passage"),
        passage_text: String(payload.passage.passage_text || ""),
        image_url: String(payload.passage.image_url || ""),
        file_id: payload.passage.file_id ? Number(payload.passage.file_id) : 0,
        file_label: String(payload.passage.file_label || ""),
        file_url: String(payload.passage.file_url || ""),
        youtube_url: String(payload.passage.youtube_url || "")
      };
    }

    const pText = String(payload.passage_text || payload.passageText || "");
    const pTitle = String(payload.passage_title || payload.passageTitle || "Passage");
    const pImg = String(payload.passage_image_url || payload.passageImageUrl || "");
    const pFid = payload.passage_file_id ? Number(payload.passage_file_id) : 0;
    const pFlab = String(payload.passage_file_label || "");
    const pFurl = String(payload.passage_file_url || "");
    const pYt = String(payload.passage_youtube_url || payload.passageYoutubeUrl || "");

    if (pText.trim() === "" && pImg.trim() === "" && !(pFid > 0) && pYt.trim() === "") return null;

    return {
      title: pTitle || "Passage",
      passage_text: pText,
      image_url: pImg,
      file_id: pFid,
      file_label: pFlab,
      file_url: pFurl,
      youtube_url: pYt
    };
  }

  function renderQuestion(payload){
    currentSeq = payload.seq;
    currentQuestionId = Number(payload.question_id || 0);
    setActiveDot(currentSeq);
    setActiveSection(currentSeq);
    refreshProgressUI();
    if (btnReview) setReviewState(payload.seq, !!payload.marked_for_review);

    const catName = labelOther(payload.category_name);
    if (elCatPill){
      elCatPill.style.display = "inline-flex";
      elCatPill.textContent = catName;
    }

    // PASSAGE (optional)
    const pass = normalizePassage(payload);
    if (elPassBox && pass && String(pass.passage_text || "").trim() !== "") {
      elPassBox.style.display = "block";
      if (elPassTitle) elPassTitle.textContent = String(pass.title || "Passage");
      if (elPassText) renderMDWithMath(pass.passage_text || "", elPassText);
      renderMediaInto(elPassMedia, {
        image_url: pass.image_url,
        file_id: pass.file_id,
        file_label: pass.file_label,
        file_url: pass.file_url,
        youtube_url: pass.youtube_url
      }, "Gambar passage");
    } else {
      if (elPassBox) elPassBox.style.display = "none";
      if (elPassTitle) elPassTitle.textContent = "";
      if (elPassText) elPassText.textContent = "";
      if (elPassMedia){ elPassMedia.innerHTML = ""; elPassMedia.style.display = "none"; }
    }

    // meta
    if (elMeta) {
      const cmin = Number(payload.category_min_questions || 0);
const cmax = (payload.category_max_questions === null || typeof payload.category_max_questions === "undefined")
  ? null
  : Number(payload.category_max_questions);

let meta = "Question " + payload.seq + " from " + payload.total +
           " • " + catName +
           (cmin > 0 ? " (min " + cmin + ")" : "") +
           (cmax !== null && cmax > 0 ? " (max " + cmax + ")" : "") +
           " • " + payload.question_type;

      if (pass && pass.title) meta += " • Passage: " + String(pass.title);
      elMeta.textContent = meta;
    }

    // question text
    if (elText) renderMDWithMath(payload.question_text || "", elText);

    // question media
    renderMediaInto(elMedia, payload, "Gambar soal");

    if (elAnswers) elAnswers.innerHTML = "";

    const qType = payload.question_type;
    const answerSeq = Number(payload.seq || 0);
    if (!elAnswers) return;

    if (qType === "text" || qType === "number") {
      const field = document.createElement(qType === "number" ? "input" : "textarea");
      if (qType === "number") {
        field.type = "number";
        field.className = "qt-input";
      } else {
        field.className = "qt-input";
        field.rows = 4;
      }
      field.value = payload.existing_answer_text || "";
      field.placeholder = "Write the answer...";

      let t = null;
      field.addEventListener("input", ()=>{
        clearTimeout(t);
        t = setTimeout(async ()=>{
          try{
            await api(API_SAVE, {
              attempt: attempt,
              quiz_id: quizId,
              question_id: payload.question_id,
              answer_text: field.value
            });

            const ok = (String(field.value || "").trim() !== "");
            answeredMap[answerSeq] = ok ? 1 : 0;
            setDotState(answerSeq, ok);
            refreshProgressUI();
          }catch(e){
            showAlert("error", e.message);
            if (String(e.message || "").toLowerCase().includes("habis")) onTimeUp();
          }
        }, 450);
      });

      elAnswers.appendChild(field);
    }
    else if (qType === "multiple_choice") {
      const selected = Array.isArray(payload.existing_selected_answer_ids)
        ? payload.existing_selected_answer_ids.map(String)
        : [];

      (payload.answers || []).forEach((ans, idx)=>{
        const label = document.createElement("label");
        label.style.display = "flex";
        label.style.gap = "10px";
        label.style.alignItems = "flex-start";
        label.style.padding = "10px";
        label.style.border = "1px solid rgba(0,0,0,.10)";
        label.style.borderRadius = "12px";
        label.style.cursor = "pointer";
        label.style.background = "rgba(255,255,255,.75)";

        const input = document.createElement("input");
        const badge = makeOptBadge(idx);

        input.type = "checkbox";
        input.className = "qt-choice-input";
        input.value = ans.id;
        input.checked = selected.includes(String(ans.id));

        input.addEventListener("change", async ()=>{
          try{
            const nowSelected = Array.from(elAnswers.querySelectorAll('input[type="checkbox"]'))
              .filter(x=>x.checked)
              .map(x=>Number(x.value));

            await api(API_SAVE, {
              attempt: attempt,
              quiz_id: quizId,
              question_id: payload.question_id,
              selected_answer_ids: nowSelected
            });

            const ok = nowSelected.length > 0;
            answeredMap[answerSeq] = ok ? 1 : 0;
            setDotState(answerSeq, ok);
            refreshProgressUI();
          }catch(e){
            showAlert("error", e.message);
            if (String(e.message || "").toLowerCase().includes("habis")) onTimeUp();
          }
        });

        const text = document.createElement("div");
        renderMDWithMath(ans.answer_text || "", text);

        label.appendChild(input);
        label.appendChild(badge);

        label.appendChild(text);
        elAnswers.appendChild(label);
      });
    }
    else {
      (payload.answers || []).forEach((ans, idx)=>{
        const label = document.createElement("label");
        label.style.display = "flex";
        label.style.gap = "10px";
        label.style.alignItems = "flex-start";
        label.style.padding = "10px";
        label.style.border = "1px solid rgba(0,0,0,.10)";
        label.style.borderRadius = "12px";
        label.style.cursor = "pointer";
        label.style.background = "rgba(255,255,255,.75)";

        const input = document.createElement("input");
        const badge = makeOptBadge(idx);

        input.type = "radio";
        input.className = "qt-choice-input";
        input.name = "answer";
        input.value = ans.id;
        input.checked = (payload.existing_selected_answer_id && (String(payload.existing_selected_answer_id) === String(ans.id)));

        input.addEventListener("change", async ()=>{
          try{
            await api(API_SAVE, {
              attempt: attempt,
              quiz_id: quizId,
              question_id: payload.question_id,
              selected_answer_id: ans.id
            });

            answeredMap[answerSeq] = 1;
            setDotState(answerSeq, true);
            refreshProgressUI();
          }catch(e){
            showAlert("error", e.message);
            if (String(e.message || "").toLowerCase().includes("habis")) onTimeUp();
          }
        });

        const text = document.createElement("div");
        renderMDWithMath(ans.answer_text || "", text);

        label.appendChild(input);
        label.appendChild(badge);

        label.appendChild(text);
        elAnswers.appendChild(label);
      });
    }

    if (btnPrev) btnPrev.disabled = (payload.seq <= 1);
    if (btnNext) btnNext.disabled = (payload.seq >= payload.total);
  }

  async function loadQuestion(seq){
    try { ensureProctorOrThrow(); } catch(e){ showAlert("error", e.message); return; }
    if (qLoadBusy) return;

    qLoadBusy = true;
    setNavBusy(true);

    if (qLoadAbort) { try { qLoadAbort.abort(); } catch(e){} }
    const ac = new AbortController();
    qLoadAbort = ac;

    if (elMeta) elMeta.textContent = "Memuat soal…";
    if (elText) elText.textContent = "";
    if (elAnswers) elAnswers.innerHTML = '<div style="padding:12px;opacity:.75">Memuat…</div>';

// clear optional blocks (loading state)
if (elCatPill){
  elCatPill.style.display = "none";
  elCatPill.textContent = "";
}

    if (elMedia) { elMedia.innerHTML = ""; elMedia.style.display = "none"; }
    if (elPassBox) elPassBox.style.display = "none";
    if (elPassTitle) elPassTitle.textContent = "";
    if (elPassText) elPassText.textContent = "";
    if (elPassMedia){ elPassMedia.innerHTML = ""; elPassMedia.style.display = "none"; }

    try{
      const payload = await apiRaw(API_GET, {
        attempt: attempt,
        quiz_id: quizId,
        seq: seq || null
      }, ac.signal);

      if (ac.signal.aborted) return;

      renderQuestion(payload.data);
      try{ localStorage.setItem("take_last_"+attempt, String(payload.data.seq)); }catch(e){}
    }catch(e){
      if (e && e.name === "AbortError") return;
      showAlert("error", e.message || "Gagal memuat soal.");
    }finally{
      if (qLoadAbort === ac) qLoadAbort = null;
      qLoadBusy = false;
      setNavBusy(false);
    }
  }

  if (navGrid) {
    navGrid.addEventListener("click", (e)=>{
      const btn = e.target.closest(".qt-dot");
      if (!btn) return;
      const seq = Number(btn.dataset.seq || 0);
      if (seq>0) loadQuestion(seq);
    });
  }

  if (btnPrev) btnPrev.addEventListener("click", ()=>{
    if (currentSeq>1) loadQuestion(currentSeq-1);
  });

  if (btnNext) btnNext.addEventListener("click", ()=>{
    if (currentSeq<total) loadQuestion(currentSeq+1);
  });

  if (btnReview) btnReview.addEventListener("click", async ()=>{
    if (!currentSeq || !currentQuestionId) return;
    const requestedSeq = Number(currentSeq);
    const requestedQuestionId = Number(currentQuestionId);
    const marked = !reviewMap[requestedSeq];
    const response = await api(API_REVIEW, {
      attempt: attempt, quiz_id: quizId, question_id: requestedQuestionId, marked: marked ? 1 : 0
    }).catch(e=>{ showAlert("error", e.message); return null; });
    if (response) setReviewState(requestedSeq, !!(response.data && response.data.marked_for_review));
  });

async function doSubmit(autoFlag, confirmIncomplete){
  try{
    submitting = true;
    const resp = await apiRaw(API_SUBMIT, {
      attempt: attempt,
      quiz_id: quizId,
      auto: autoFlag ? 1 : 0,
      confirm_incomplete: confirmIncomplete ? 1 : 0
    });
    showAlert("ok", "Submitted successfully.");
    const resultUrl = resp && resp.data ? String(resp.data.result_url || "") : "";
    setTimeout(()=>{ if (resultUrl) location.assign(resultUrl); else location.reload(); }, 650);
    return resp;
  }catch(e){
    showAlert("error", e.message);
    setTimeout(()=>location.reload(), 900);
  }
}

if (btnSubmit) btnSubmit.addEventListener("click", async ()=>{
  try { ensureProctorOrThrow(); } catch(e){ showAlert("error", e.message); return; }
  if (submitting) return;

  const missing = [];
  for (let i=1;i<=total;i++) if (!answeredMap[i]) missing.push(i);

  if (missing.length) {
    // tampilkan 2x modal warning (English)
    showWarn1(missing);
    return;
  }

  // lengkap → submit normal
  await doSubmit(false, false);
});


  function getStartSeq(){
    let s = 1;
    try{
      const saved = Number(localStorage.getItem("take_last_"+attempt) || "0");
      if (saved >= 1 && saved <= total) s = saved;
    }catch(e){}
    return s;
  }

  async function onTimeUp(){
    if (timeUpTriggered !== true) timeUpTriggered = true;
    showOverlay("The exam time is up.", "Answers will be sent automatically...");
    await doSubmit(true);
  }

  buildSectionsUI();
  refreshProgressUI();
  syncTimerFrom({timer: {$timerJson}});

  (async function startExam(){
    try{
      await proctorGate();
      const startSeq = getStartSeq();
      await loadQuestion(startSeq);
    }catch(e){
      showAlert("error", e.message || "Gagal memulai ujian.");
    }
  })();

})();
</script>
HTML;
