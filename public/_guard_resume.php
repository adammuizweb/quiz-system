<?php
// /quiz/_guard_resume.php
declare(strict_types=1);

require_once __DIR__ . '/_security.php';

/**
 * Resume guard:
 * - Jika user punya attempt yang masih aktif (status: verified / in_progress),
 *   maka halaman sebelum take (register/start/confirm/verify/send_otp) akan redirect ke take.
 *
 * Catatan:
 * - status "submitted" TIDAK dianggap perlu resume (supaya user bisa balik ke /quiz/start).
 *
 * Validasi redirect:
 * - student cookie_id valid
 * - attempt milik student_id tersebut
 * - UA hash match
 * - token cookie quiz_token_{quiz_id} ada dan hash match token_hash di attempt
 */

if (!function_exists('qz_is_https')) {
  function qz_is_https(): bool {
    return qz_cookie_secure();
  }
}

if (!function_exists('qz_cookie_get')) {
  function qz_cookie_get(string $name): string {
    return isset($_COOKIE[$name]) ? trim((string)$_COOKIE[$name]) : '';
  }
}

if (!function_exists('qz_cookie_set')) {
  function qz_cookie_set(string $name, string $value, int $ttlSeconds = 31536000): void {
    $secure = qz_is_https();
    setcookie($name, $value, [
      'expires'  => time() + $ttlSeconds,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

if (!function_exists('qz_cookie_clear')) {
  function qz_cookie_clear(string $name): void {
    $secure = qz_is_https();
    setcookie($name, '', [
      'expires'  => time() - 3600,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

if (!function_exists('qz_ua_hash')) {
  function qz_ua_hash(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  }
}

if (!function_exists('qz_redirect')) {
  function qz_redirect(string $path): void {
    if (!headers_sent()) {
      header('Location: ' . $path);
      exit;
    }
    echo '<script>location.href='.json_encode($path).';</script>';
    exit;
  }
}

if (!function_exists('qz_token_cookie_hash')) {
  function qz_token_cookie_hash(int $quizId): string {
    $plain = qz_cookie_get('quiz_token_' . $quizId);
    if ($plain === '') return '';
    return hash('sha256', $plain);
  }
}

if (!function_exists('qz_guard_resume')) {
  function qz_guard_resume(PDO $pdo): void {
    // jangan redirect kalau sudah di take/api (hindari loop)
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $publicBase = quiz_public_base();
    if (strpos($path, $publicBase . '/take') === 0) return;
    if (strpos($path, $publicBase . '/api/') === 0) return;

    // anti BFCache / back-button nge-bypass
    if (!headers_sent()) {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
      header('Pragma: no-cache');
      header('Expires: 0');
    }

    $cookieId = qz_cookie_get('quiz_cid');
    if ($cookieId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $cookieId)) return;

    // cari student_id
    $stmt = $pdo->prepare("SELECT id FROM students WHERE cookie_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $cookieId]);
    $sid = (int)($stmt->fetchColumn() ?: 0);
    if ($sid <= 0) return;

    $uaHash = qz_ua_hash();

    $isActive = function(array $att) use ($uaHash): bool {
      if (!isset($att['status'], $att['user_agent_hash'], $att['quiz_id'], $att['token_hash'])) return false;

      $st = (string)$att['status'];
      if (!in_array($st, ['verified','in_progress'], true)) return false;

      if (!hash_equals((string)$att['user_agent_hash'], $uaHash)) return false;
      return true;
    };

    // 1) cek cookie attempt alias (PENTING: jangan hapus cookie hanya karena belum active;
    // cookie ini dipakai juga untuk flow verify/otp)
    $alias = qz_cookie_get('quiz_attempt');
    if ($alias !== '') {
      $st = $pdo->prepare("
        SELECT attempt_alias, quiz_id, status, user_agent_hash, token_hash
        FROM quiz_attempts
        WHERE student_id = :sid AND attempt_alias = :a
        LIMIT 1
      ");
      $st->execute([':sid' => $sid, ':a' => $alias]);
      $att = $st->fetch(PDO::FETCH_ASSOC);

      // kalau alias tidak ketemu -> bersihkan (basi / manipulasi)
      if (!$att) {
        qz_cookie_clear('quiz_attempt');
        return;
      }

      // kalau submitted -> bersihkan supaya user tidak “nempel” ke attempt lama
      if ((string)$att['status'] === 'submitted') {
        qz_cookie_clear('quiz_attempt');
        return;
      }

      // kalau memang ACTIVE, baru lakukan redirect resume (butuh token match)
      if ($isActive($att)) {
        $quizId  = (int)$att['quiz_id'];
        $tokHash = qz_token_cookie_hash($quizId);

        if ($tokHash !== '' && hash_equals((string)$att['token_hash'], $tokHash)) {
          qz_redirect(quiz_public_url_for_quiz($pdo, $quizId, 'take', ['a' => (string)$att['attempt_alias']]));
        }

        // ACTIVE tapi token mismatch -> jangan resume paksa; cookie alias boleh dibersihkan agar tidak loop
        qz_cookie_clear('quiz_attempt');
        return;
      }

      // status masih registered/dll -> JANGAN clear, JANGAN redirect
      // biarkan flow verify/otp menggunakan cookie ini.
    }

    // 2) fallback: attempt terakhir yang masih aktif (verified / in_progress)
    $st = $pdo->prepare("
      SELECT attempt_alias, quiz_id, status, user_agent_hash, token_hash
      FROM quiz_attempts
      WHERE student_id = :sid
        AND status IN ('verified','in_progress')
      ORDER BY updated_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([':sid' => $sid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);

    if ($att && $isActive($att)) {
      $quizId  = (int)$att['quiz_id'];
      $tokHash = qz_token_cookie_hash($quizId);

      if ($tokHash !== '' && hash_equals((string)$att['token_hash'], $tokHash)) {
        qz_cookie_set('quiz_attempt', (string)$att['attempt_alias']);
        qz_redirect(quiz_public_url_for_quiz($pdo, $quizId, 'take', ['a' => (string)$att['attempt_alias']]));
      }
    }
  }
}
