<?php
declare(strict_types=1);

function quiz_h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('quiz_redirect')) {
  function quiz_redirect(string $url): never {
    if (!headers_sent()) {
      while (ob_get_level() > 0) { ob_end_clean(); }
      header('Location: ' . $url, true, 303);
      exit;
    }
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    exit;
  }
}

function quiz_json(array $data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function quiz_json_error(string $msg, int $code = 400): never {
  quiz_json(['ok' => false, 'error' => $msg], $code);
}

function quiz_json_ok(array $data = []): never {
  quiz_json(['ok' => true] + $data);
}

function quiz_available_quizzes(PDO $pdo): array {
  $now = date('Y-m-d H:i:s');
  $st = $pdo->prepare("
    SELECT id, title, description, questions_per_attempt, duration_minutes,
           is_active, start_at, end_at, is_primary,
           (SELECT COUNT(*) FROM questions WHERE quiz_id=quizzes.id AND is_active=1) AS qcount,
           (SELECT COUNT(*) FROM question_passages WHERE quiz_id=quizzes.id AND is_active=1) AS pcount
    FROM quizzes
    WHERE is_active=1
      AND (start_at IS NULL OR start_at <= :now)
      AND (end_at IS NULL OR end_at >= :now)
    ORDER BY is_primary DESC, id ASC
  ");
  $st->execute([':now' => $now]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function quiz_generate_uuid(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function quiz_generate_alias(): string {
  return 'QZ' . strtoupper(substr(bin2hex(random_bytes(10)), 0, 14));
}

function quiz_get_or_create_cookie(): string {
  $name = 'quiz_cid';
  if (!empty($_COOKIE[$name])) {
    $id = trim((string)$_COOKIE[$name]);
    if (preg_match('/^[a-f0-9\-]{36}$/i', $id)) return $id;
  }
  $id = quiz_generate_uuid();
  setcookie($name, $id, time() + 86400 * 365, '/', '', false, true);
  $_COOKIE[$name] = $id;
  return $id;
}

function quiz_find_student(PDO $pdo, string $email, string $phone, string $cookie_id = ''): ?array {
  $conds = [];
  $params = [];
  if ($email !== '') { $conds[] = 'email=:email'; $params[':email'] = $email; }
  if ($phone !== '') { $conds[] = 'phone=:phone'; $params[':phone'] = $phone; }
  if ($cookie_id !== '') { $conds[] = 'cookie_id=:cid'; $params[':cid'] = $cookie_id; }
  if (!$conds) return null;
  $st = $pdo->prepare("SELECT * FROM students WHERE " . implode(' OR ', $conds) . " LIMIT 1");
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
