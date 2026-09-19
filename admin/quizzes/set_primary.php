<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.primary.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$uid  = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  exit('Forbidden (no session)');
}

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go($loc, $title='Mengalihkan...'){
  if (!headers_sent()) { header('Location: '.$loc, true, 303); exit; }
  ?>
  <div style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:4000;">
    <div style="background:var(--adam-card,#fff);padding:1.2rem 1.4rem;border-radius:12px;max-width:460px;width:92%;box-shadow:0 12px 40px rgba(0,0,0,.18);text-align:center;color:var(--adam-text,#0f172a)">
      <h3 style="margin-top:0"><?= h($title) ?></h3>
      <p>Memproses…</p>
      <p><a href="<?= h($loc) ?>">Klik jika tidak otomatis</a></p>
    </div>
  </div>
  <script>setTimeout(function(){ location.href = <?= json_encode($loc) ?>; }, 450);</script>
  <?php
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$csrf = (string)($_POST['csrf_token'] ?? '');
if (!csrf_check($csrf)) {
  go($base . '/?page=admin/quiz/quizzes&err=' . rawurlencode('CSRF token tidak valid.'), 'Gagal');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  go($base . '/?page=admin/quiz/quizzes&err=' . rawurlencode('ID quiz tidak valid.'), 'Gagal');
}
try {
  $pdo->beginTransaction();

  $select = $pdo->prepare("SELECT id, title, is_primary FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE");
  $select->execute([':id' => $id]);
  $q = $select->fetch(PDO::FETCH_ASSOC);
  if (!$q) {
    $pdo->rollBack();
    go($base . '/?page=admin/quiz/quizzes&err=' . rawurlencode('Quiz tidak ditemukan.'), 'Gagal');
  }
  if ((int)$q['is_primary'] === 1) {
    $pdo->rollBack();
    go($base . '/?page=admin/quiz/quizzes&msg=' . rawurlencode('Quiz sudah PRIMARY: ' . (string)$q['title']), 'OK');
  }

  $pdo->exec("UPDATE quizzes SET is_primary=0 WHERE is_primary=1");

  $stmt = $pdo->prepare("UPDATE quizzes SET is_primary=1 WHERE id=:id LIMIT 1");
  $stmt->execute([':id' => $id]);

  if ($stmt->rowCount() < 1) {
    $pdo->rollBack();
    go($base . '/?page=admin/quiz/quizzes&err=' . rawurlencode('Gagal set PRIMARY.'), 'Gagal');
  }

  $pdo->commit();
  go($base . '/?page=admin/quiz/quizzes&msg=' . rawurlencode('Berhasil set PRIMARY: ' . (string)$q['title']), 'Berhasil');

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[admin/quiz/quizzes/set_primary] " . $e->getMessage());
  go($base . '/?page=admin/quiz/quizzes&err=' . rawurlencode('Terjadi kendala saat set primary.'), 'Gagal');
}
