<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage');
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$routeUser = qz_admin_user($pdo);
if (!$routeUser['can_manage_quizzes']) { http_response_code(403); exit('Quiz management permission required.'); }

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing or invalid id');
}
qz_require_quiz_access($pdo, $id);

function is_valid_dt(string $s): bool {
  if ($s === '') return true;
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s);
}

$stmt = $pdo->prepare("
  SELECT q.*, u.name AS owner_name, u.email AS owner_email
  FROM quizzes q
  LEFT JOIN users u ON u.id = q.created_by
  WHERE q.id = :id
  LIMIT 1
");
$stmt->execute([':id' => $id]);
$quiz = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$quiz) {
  http_response_code(404);
  exit('Quiz not found');
}

$errors = [];

$title = (string)($quiz['title'] ?? '');
$description = (string)($quiz['description'] ?? '');
$questions_per_attempt = (int)($quiz['questions_per_attempt'] ?? 25);
$duration_minutes = ($quiz['duration_minutes'] !== null) ? (string)$quiz['duration_minutes'] : '';
$shuffle_answers = quiz_safe_bool($quiz['shuffle_answers'] ?? 1, 1);
$timer_warning_minutes = quiz_timer_warning_seconds_to_minutes(quiz_safe_timer_warning_seconds($quiz['timer_warning_seconds'] ?? null));
$allow_mark_for_review = quiz_safe_bool($quiz['allow_mark_for_review'] ?? 0, 0);
$result_visibility = quiz_safe_result_visibility($quiz['result_visibility'] ?? 'hidden');
$is_active = (int)($quiz['is_active'] ?? 1);
$start_at = (string)($quiz['start_at'] ?? '');
$end_at   = (string)($quiz['end_at'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    $errors[] = 'CSRF token tidak valid.';
  }

  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $questions_per_attempt = (int)($_POST['questions_per_attempt'] ?? 25);
  $duration_minutes = trim((string)($_POST['duration_minutes'] ?? ''));
  $timer_warning_minutes = trim((string)($_POST['timer_warning_minutes'] ?? ''));
  $is_active = (int)($_POST['is_active'] ?? 1);
  $start_at = trim((string)($_POST['start_at'] ?? ''));
  $end_at = trim((string)($_POST['end_at'] ?? ''));

  try {
    $is_active = quiz_normalize_bool($_POST['is_active'] ?? null, 1);
    $shuffle_answers = quiz_normalize_bool($_POST['shuffle_answers'] ?? null, 1);
    $allow_mark_for_review = quiz_normalize_bool($_POST['allow_mark_for_review'] ?? null, 0);
    $result_visibility = quiz_normalize_result_visibility($_POST['result_visibility'] ?? '');
    $timerWarningSeconds = quiz_timer_warning_minutes_to_seconds($timer_warning_minutes);
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
    $timerWarningSeconds = [];
  }

  if ($title === '') $errors[] = 'Judul wajib diisi.';
  if ($questions_per_attempt <= 0 || $questions_per_attempt > 200) $errors[] = 'Jumlah soal per attempt tidak valid (1..200).';
  if ($duration_minutes !== '' && (!ctype_digit($duration_minutes) || (int)$duration_minutes <= 0)) $errors[] = 'Durasi harus angka menit.';
  if ($timerWarningSeconds !== [] && ($duration_minutes === '' || max($timerWarningSeconds) > (int)$duration_minutes * 60)) $errors[] = 'Peringatan timer harus berada dalam durasi quiz.';
  if (!is_valid_dt($start_at)) $errors[] = 'Format start_at: YYYY-MM-DD HH:MM:SS';
  if (!is_valid_dt($end_at)) $errors[] = 'Format end_at: YYYY-MM-DD HH:MM:SS';

  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_quiz_for_workspace_mutation($pdo, $id, (int)$routeUser['id']);

      $stmt = $pdo->prepare("
        UPDATE quizzes
        SET title = :title,
            description = :desc,
            questions_per_attempt = :qpa,
            duration_minutes = :dur,
            shuffle_answers = :shuffle_answers,
            timer_warning_seconds = :timer_warnings,
            allow_mark_for_review = :allow_review,
            result_visibility = :result_visibility,
            is_active = :act,
            start_at = :start_at,
            end_at = :end_at
        WHERE id = :id
        LIMIT 1
      ");
      $params = [
        ':title' => $title,
        ':desc' => ($description === '' ? null : $description),
        ':qpa' => $questions_per_attempt,
        ':dur' => ($duration_minutes === '' ? null : (int)$duration_minutes),
        ':shuffle_answers' => $shuffle_answers,
        ':timer_warnings' => quiz_serialize_timer_warning_seconds($timerWarningSeconds),
        ':allow_review' => $allow_mark_for_review,
        ':result_visibility' => $result_visibility,
        ':act' => ($is_active ? 1 : 0),
        ':start_at' => ($start_at === '' ? null : $start_at),
        ':end_at' => ($end_at === '' ? null : $end_at),
        ':id' => $id,
      ];
      $stmt->execute($params);
      if ($stmt->rowCount() > 1) throw new RuntimeException('Quiz update affected an unexpected number of rows.');
      $pdo->commit();

      qz_redirect($base . "/?page=admin/quiz/quizzes&msg=" . urlencode("Quiz berhasil diperbarui."));
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[admin/quiz/quizzes/edit] ' . $e->getMessage());
      $errors[] = 'Quiz tidak berubah atau akses sudah tidak berlaku.';
    }
  }
}

$owner = trim((string)($quiz['owner_name'] ?? ''));
$oemail = trim((string)($quiz['owner_email'] ?? ''));
$ownerLine = ($owner !== '' || $oemail !== '')
  ? (($owner !== '' ? $owner : 'User#'.(int)$quiz['created_by']) . ($oemail !== '' ? " · {$oemail}" : ''))
  : ('User#'.(int)$quiz['created_by']);
?>
<section class="adam-card">
  <?php qz_admin_breadcrumb([['label'=>'Quiz','url'=>$base.'/?page=admin/quiz/quizzes'],['label'=>(string)$quiz['title']]]); ?>
  <h2 style="margin:0 0 12px">Edit Quiz</h2>
  <div class="muted" style="font-size:12px;margin-bottom:12px">
    ID: <b><?= (int)$id ?></b> · Owner: <b><?= h($ownerLine) ?></b>
    <?php if ((int)($quiz['is_primary'] ?? 0) === 1): ?>
      · <span class="badge ok" style="font-weight:900">PRIMARY</span>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
    <div class="adam-alert error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <div style="display:grid;gap:.5rem;margin-bottom:1rem">
      <label><strong>Judul</strong></label>
      <input class="adam-input" name="title" value="<?= h($title) ?>" required>
    </div>

    <div style="display:grid;gap:.5rem;margin-bottom:1rem">
      <label><strong>Deskripsi</strong> (opsional)</label>
      <textarea class="adam-input" name="description" rows="4"><?= h($description) ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="display:grid;gap:.5rem">
        <label><strong>Acak Jawaban</strong></label>
        <select class="adam-input" name="shuffle_answers">
          <option value="1" <?= $shuffle_answers===1?'selected':'' ?>>Aktif (default lama)</option>
          <option value="0" <?= $shuffle_answers===0?'selected':'' ?>>Nonaktif</option>
        </select>
      </div>
      <div style="display:grid;gap:.5rem">
        <label><strong>Peringatan Timer (menit)</strong> (opsional)</label>
        <input class="adam-input" name="timer_warning_minutes" value="<?= h($timer_warning_minutes) ?>" placeholder="mis. 10, 5, 1">
      </div>
      <div style="display:grid;gap:.5rem">
        <label><strong>Tandai untuk Ditinjau</strong></label>
        <select class="adam-input" name="allow_mark_for_review">
          <option value="0" <?= $allow_mark_for_review===0?'selected':'' ?>>Nonaktif (default)</option>
          <option value="1" <?= $allow_mark_for_review===1?'selected':'' ?>>Aktif</option>
        </select>
      </div>
      <div style="display:grid;gap:.5rem">
        <label><strong>Hasil Peserta</strong></label>
        <select class="adam-input" name="result_visibility">
          <option value="hidden" <?= $result_visibility==='hidden'?'selected':'' ?>>Disembunyikan (default)</option>
          <option value="summary" <?= $result_visibility==='summary'?'selected':'' ?>>Ringkasan</option>
          <option value="categories" <?= $result_visibility==='categories'?'selected':'' ?>>Ringkasan + kategori</option>
        </select>
        <div class="muted" style="font-size:12px">Disembunyikan menutup halaman hasil peserta. Ringkasan menampilkan total saja; kategori menambahkan rinciannya.</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="display:grid;gap:.5rem">
        <label><strong>Soal per Attempt</strong></label>
        <input class="adam-input" type="number" min="1" max="200" name="questions_per_attempt" value="<?= (int)$questions_per_attempt ?>">
      </div>
      <div style="display:grid;gap:.5rem">
        <label><strong>Durasi (menit)</strong> (opsional)</label>
        <input class="adam-input" name="duration_minutes" value="<?= h($duration_minutes) ?>" placeholder="mis. 30">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="display:grid;gap:.5rem">
        <label><strong>Start At</strong> (opsional)</label>
        <input class="adam-input" name="start_at" value="<?= h($start_at) ?>" placeholder="YYYY-MM-DD HH:MM:SS">
      </div>
      <div style="display:grid;gap:.5rem">
        <label><strong>End At</strong> (opsional)</label>
        <input class="adam-input" name="end_at" value="<?= h($end_at) ?>" placeholder="YYYY-MM-DD HH:MM:SS">
      </div>
    </div>

    <div style="margin-top:.2rem">
      <label><strong>Status</strong></label><br>
      <select class="adam-input" name="is_active" style="max-width:260px">
        <option value="1" <?= ((int)$is_active===1?'selected':'') ?>>Aktif</option>
        <option value="0" <?= ((int)$is_active===0?'selected':'') ?>>Nonaktif</option>
      </select>
    </div>

    <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="adam-button" type="submit">Simpan</button>
      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/quizzes">Kembali</a>
      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/questions&quiz_id=<?= (int)$id ?>">Kelola Soal</a>
      <a class="adam-button ghost"
         href="<?= h($base) ?>/?page=admin/quiz/master&quiz_id=<?= (int)$id ?>">Master Builder</a>
    </div>
  </form>
</section>

<?php
