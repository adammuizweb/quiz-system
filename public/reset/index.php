<?php
// /quiz/reset/index.php
declare(strict_types=1);

require_once __DIR__ . '/../_security.php';

if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$publicTitle = quiz_public_title($pdo);
$publicTitleHtml = h($publicTitle);
$page_title = 'Reset ' . $publicTitle . ' Session';
$next = quiz_public_safe_next((string)($_GET['next'] ?? ''), 'register');
$isReset = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isReset && !qz_public_csrf_check(qz_public_csrf_from_request())) {
  http_response_code(419);
  exit('CSRF token mismatch');
}

function clear_cookie(string $name): void {
  setcookie($name, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => qz_cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

$cleared = [];
if ($isReset) {
  foreach (array_keys($_COOKIE ?? []) as $k) {
    if (strpos($k, 'quiz_') === 0) {
      clear_cookie($k);
      $cleared[] = $k;
    }
  }
}

$clearedTxt = $cleared ? implode(', ', $cleared) : '(no session cookies detected)';

$content_html = !$isReset ? '
<section class="st">
  <div class="st-wrap">
    <div class="st-hero">
      <div class="st-badge">RESET</div>
      <h1 class="st-h1">Reset '.$publicTitleHtml.' Session</h1>
      <div class="st-desc">This action removes '.$publicTitleHtml.' cookies and session data from this browser.</div>
    </div>

    <div class="st-box">
      <div class="st-note">Only reset if you want to restart from the registration page.</div>
      <form method="post" class="st-actions" style="margin-top:14px">
        <input type="hidden" name="csrf_token" value="'.h(qz_public_csrf_token()).'">
        <button class="st-btn primary" type="submit">Reset '.$publicTitleHtml.' Session</button>
        <a class="st-btn" href="'.h($next).'">Cancel</a>
      </form>
    </div>
  </div>
</section>
' : '
<section class="st">
  <div class="st-wrap">
    <div class="st-hero">
      <div class="st-badge">RESET</div>
      <h1 class="st-h1">Reset '.$publicTitleHtml.' Session</h1>
      <div class="st-desc">The '.$publicTitleHtml.' session data was removed from this browser.</div>
    </div>

    <div class="st-box ok">
      <div style="font-weight:800;margin-bottom:6px">Cookies removed:</div>
      <div style="font-family:ui-monospace,monospace;white-space:pre-wrap">'.h($clearedTxt).'</div>

      <div class="st-note" style="margin-top:10px">
        This page will return automatically to <b>'.h($next).'</b>.
      </div>

      <div class="st-actions" style="margin-top:14px">
        <a class="st-btn primary" href="'.h($next).'">Back to Registration</a>
      </div>
    </div>
  </div>
</section>

<script>
(async function(){
  try {
    const ownsQuizKey = (key) =>
      key === "otp_pick" ||
      key.startsWith("take_warn_") ||
      key.startsWith("take_last_") ||
      key.startsWith("quiz_") ||
      key.startsWith("quiz-") ||
      key.startsWith("qz_") ||
      key.startsWith("qz-");

    const clearQuizStorage = (storage) => {
      for (let i = storage.length - 1; i >= 0; i--) {
        const key = storage.key(i);
        if (key && ownsQuizKey(key)) storage.removeItem(key);
      }
    };

    try { clearQuizStorage(localStorage); } catch(e){}
    try { clearQuizStorage(sessionStorage); } catch(e){}

    if (window.caches && caches.keys) {
      try {
        const keys = await caches.keys();
        const quizKeys = keys.filter(ownsQuizKey);
        await Promise.all(quizKeys.map(k => caches.delete(k)));
      } catch(e){}
    }
  } finally {
    setTimeout(() => { location.replace('.json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'); }, 350);
  }
})();
</script>
';

require __DIR__ . '/../layout.php';
exit;
