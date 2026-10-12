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

function quiz_normalize_public_prefix(mixed $value, bool $allowQuiz = false): ?string {
  $prefix = strtolower(trim((string)$value));
  if ($prefix === '') return null;
  if (strlen($prefix) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $prefix) !== 1) {
    throw new InvalidArgumentException('Public URL prefix must use 1-63 lowercase letters, numbers, or hyphens.');
  }
  if ((!$allowQuiz && $prefix === 'quiz') || preg_match('/\A[0-9]{4}\z/', $prefix) === 1) {
    throw new InvalidArgumentException('This public URL prefix is reserved.');
  }
  return $prefix;
}

function quiz_database_setting(PDO $pdo, string $key, ?string $default = null): ?string {
  try {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=:key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
  } catch (Throwable) {
    return function_exists('settings_get') ? settings_get($pdo, $key, $default) : $default;
  }
}

function quiz_normalize_public_title(mixed $value): string {
  $title = trim((string)$value);
  if ($title === '') {
    throw new InvalidArgumentException('Global Title is required.');
  }
  if (preg_match('//u', $title) !== 1) {
    throw new InvalidArgumentException('Global Title must contain valid text.');
  }
  if (preg_match('/[\x00-\x1F\x7F<>]/u', $title) === 1) {
    throw new InvalidArgumentException('Global Title must be plain text without markup or control characters.');
  }
  if (mb_strlen($title, 'UTF-8') > 80) {
    throw new InvalidArgumentException('Global Title must not exceed 80 characters.');
  }
  return $title;
}

function quiz_public_title(PDO $pdo): string {
  try {
    return quiz_normalize_public_title(quiz_database_setting($pdo, 'quiz_global_title', 'Quiz'));
  } catch (Throwable) {
    return 'Quiz';
  }
}

function quiz_public_prefix_conflict(PDO $pdo, string $prefix, ?int $excludeQuizId = null,
    bool $allowGlobalPrefix = false, bool $deepContentCheck = true): ?string {
  $reserved = ['admin', 'dashboard', 'login', 'register', 'author', 'private', 'plugin', 'plugins',
    'theme', 'themes', 'download', 'tags', 'posts', 'pages', 'category', 'static', 'pondasi', 'sitemaps', 'views'];
  $settingDefaults = [
    'admin_path' => 'dashboard',
    'login_path' => 'login',
    'register_path' => 'register',
    'posts_list_path' => 'artikel',
    'pages_list_path' => 'halaman',
    'category_path' => 'category',
  ];
  foreach ($settingDefaults as $key => $default) {
    if (!function_exists('settings_get')) continue;
    $configured = trim((string)(settings_get($pdo, $key, $default) ?? $default), '/ ');
    if ($configured !== '') $reserved[] = strtolower((string)explode('/', $configured, 2)[0]);
  }
  if (in_array($prefix, array_unique($reserved), true)) return 'This public URL prefix is reserved by Jyavani.';
  if (!$allowGlobalPrefix) {
    try {
      $globalPrefix = quiz_normalize_public_prefix(
        quiz_database_setting($pdo, 'quiz_global_public_prefix', 'quiz'),
        true
      ) ?? 'quiz';
      if ($prefix === $globalPrefix) return 'This public URL prefix is already used by the global Quiz route.';
    } catch (InvalidArgumentException) {
      // An invalid stored global prefix safely falls back to /quiz/.
    }
  }

  $paths = [$prefix => $prefix];
  if (function_exists('quiz_frontend_routes')) {
    foreach (array_keys(quiz_frontend_routes()) as $route) {
      $path = $prefix . ($route === '' ? '' : '/' . $route);
      $paths[$path] = $path;
    }
  }

  try {
    if (function_exists('router_core_path_is_owned')) {
      foreach ($paths as $path) {
        if (router_core_path_is_owned($pdo, $path)) {
          return 'This public URL prefix conflicts with an existing Jyavani route.';
        }
      }
    }

    $contentPaths = array_values(array_filter($paths, static fn(string $path): bool =>
      !function_exists('content_route_is_valid_path') || content_route_is_valid_path($path)));
    if ($contentPaths !== []) {
      $placeholders = [];
      $params = [];
      foreach ($contentPaths as $index => $path) {
        $placeholder = ':content_path_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $path;
      }
      $content = $pdo->prepare('SELECT 1 FROM content_routes WHERE path IN (' . implode(', ', $placeholders) . ') LIMIT 1');
      $content->execute($params);
      if ($content->fetchColumn() !== false) {
        return 'This public URL prefix conflicts with an existing Jyavani route.';
      }
    }
  } catch (Throwable) {
    return 'This public URL prefix could not be validated against Jyavani routes.';
  }

  if ($deepContentCheck && function_exists('content_route_path_conflict')) {
    try {
      foreach ($paths as $path) {
        if (function_exists('content_route_is_valid_path') && !content_route_is_valid_path($path)) continue;
        $conflict = content_route_path_conflict($pdo, $path);
        if (($conflict['type'] ?? '') === 'plugin_route'
            && isset($GLOBALS['quiz_public_route_paths'][trim((string)($conflict['value'] ?? ''), '/')])) {
          continue;
        }
        if ($conflict !== null) {
          return 'This public URL prefix conflicts with an existing Jyavani route.';
        }
      }
    } catch (Throwable) {
      return 'This public URL prefix could not be validated against Jyavani routes.';
    }
  }

  $sql = 'SELECT id FROM quizzes WHERE public_prefix=:prefix';
  $params = [':prefix' => $prefix];
  if (($excludeQuizId ?? 0) > 0) {
    $sql .= ' AND id<>:quiz_id';
    $params[':quiz_id'] = $excludeQuizId;
  }
  $sql .= ' LIMIT 1';
  $duplicate = $pdo->prepare($sql);
  $duplicate->execute($params);
  if ($duplicate->fetchColumn() !== false) return 'This public URL prefix is already assigned to another Quiz.';

  if (function_exists('get_frontend_route_definitions')) {
    foreach (get_frontend_route_definitions() as $definition) {
      $path = trim((string)($definition['path'] ?? ''), '/');
      if ($path === '') continue;
      if (isset($GLOBALS['quiz_public_route_paths'][$path])) continue;
      if (strtolower((string)explode('/', $path, 2)[0]) === $prefix) {
        return 'This public URL prefix conflicts with another frontend route.';
      }
    }
  }
  return null;
}

function quiz_validate_public_prefix(PDO $pdo, mixed $value, ?int $excludeQuizId = null,
    ?string $currentPrefix = null): ?string {
  $prefix = quiz_normalize_public_prefix($value);
  if ($prefix === null) return null;
  $conflict = quiz_public_prefix_conflict($pdo, $prefix, $excludeQuizId);
  if ($conflict !== null) throw new InvalidArgumentException($conflict);
  return $prefix;
}

function quiz_validate_global_public_prefix(PDO $pdo, mixed $value, string $currentPrefix = 'quiz'): string {
  $prefix = quiz_normalize_public_prefix($value, true) ?? 'quiz';
  $conflict = quiz_public_prefix_conflict($pdo, $prefix, null, true);
  if ($conflict !== null) throw new InvalidArgumentException($conflict);
  return $prefix;
}

function quiz_stored_global_public_prefix(PDO $pdo): string {
  try {
    $stored = quiz_database_setting($pdo, 'quiz_global_public_prefix', 'quiz');
    return quiz_normalize_public_prefix($stored, true) ?? 'quiz';
  } catch (Throwable) {
    return 'quiz';
  }
}

function quiz_global_public_prefix(PDO $pdo): string {
  $prefix = quiz_stored_global_public_prefix($pdo);
  if (($GLOBALS['quiz_public_routes_initialized'] ?? false) === true) {
    $registered = $GLOBALS['quiz_public_registered_global_prefix'] ?? null;
    if (!is_string($registered) || $registered === '') {
      throw new RuntimeException('No global Quiz public route is available.');
    }
    return $registered;
  }
  if ($prefix !== 'quiz' && quiz_public_prefix_conflict($pdo, $prefix, null, true) !== null) return 'quiz';
  return $prefix;
}

function quiz_public_prefix_lock(PDO $pdo, int $timeoutSeconds = 5): bool {
  try {
    if (strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') return true;
    $stmt = $pdo->prepare("SELECT GET_LOCK('quiz-public-prefixes', :timeout)");
    $stmt->execute([':timeout' => max(0, min(30, $timeoutSeconds))]);
    return (int)$stmt->fetchColumn() === 1;
  } catch (Throwable) {
    return false;
  }
}

function quiz_public_prefix_unlock(PDO $pdo): void {
  try {
    if (strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') return;
    $pdo->query("SELECT RELEASE_LOCK('quiz-public-prefixes')");
  } catch (Throwable) {
  }
}

function quiz_set_public_route_context(string $prefix, ?int $quizId): void {
  $GLOBALS['quiz_public_route_context'] = [
    'prefix' => $prefix,
    'quiz_id' => ($quizId ?? 0) > 0 ? $quizId : null,
  ];
}

function quiz_public_route_context(): array {
  $context = $GLOBALS['quiz_public_route_context'] ?? null;
  return is_array($context) ? $context : ['prefix' => 'quiz', 'quiz_id' => null];
}

function quiz_public_base(): string {
  $prefix = (string)(quiz_public_route_context()['prefix'] ?? 'quiz');
  return '/' . ($prefix !== '' ? $prefix : 'quiz');
}

function quiz_public_url(string $route = '', array $query = []): string {
  $route = trim($route, '/');
  $url = quiz_public_base() . '/';
  if ($route !== '') $url .= $route . (str_ends_with($route, '.php') ? '' : '/');
  if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  return $url;
}

function quiz_global_public_url(PDO $pdo, string $route = '', array $query = []): string {
  $previous = $GLOBALS['quiz_public_route_context'] ?? null;
  quiz_set_public_route_context(quiz_global_public_prefix($pdo), null);
  try {
    return quiz_public_url($route, $query);
  } finally {
    if (is_array($previous)) $GLOBALS['quiz_public_route_context'] = $previous;
    else unset($GLOBALS['quiz_public_route_context']);
  }
}

function quiz_public_prefix_for_quiz(PDO $pdo, int $quizId): ?string {
  if ($quizId <= 0) return null;
  static $cache = [];
  if (!array_key_exists($quizId, $cache)) {
    try {
      $stmt = $pdo->prepare('SELECT public_prefix FROM quizzes WHERE id=:id LIMIT 1');
      $stmt->execute([':id' => $quizId]);
      $value = $stmt->fetchColumn();
      $cache[$quizId] = $value === false ? null : quiz_normalize_public_prefix($value);
    } catch (Throwable) {
      $cache[$quizId] = null;
    }
  }
  $prefix = $cache[$quizId];
  if ($prefix !== null) {
    if (($GLOBALS['quiz_public_routes_initialized'] ?? false) === true) {
      if (($GLOBALS['quiz_public_registered_quiz_prefixes'][$quizId] ?? null) !== $prefix) return null;
    } elseif (quiz_public_prefix_conflict($pdo, $prefix, $quizId) !== null) {
      return null;
    }
  }
  return $prefix;
}

function quiz_public_url_for_quiz(PDO $pdo, int $quizId, string $route = '', array $query = []): string {
  $context = quiz_public_route_context();
  if ((int)($context['quiz_id'] ?? 0) === $quizId && $quizId > 0) return quiz_public_url($route, $query);
  $dedicatedPrefix = quiz_public_prefix_for_quiz($pdo, $quizId);
  $prefix = $dedicatedPrefix ?? quiz_global_public_prefix($pdo);
  $previous = $GLOBALS['quiz_public_route_context'] ?? null;
  quiz_set_public_route_context($prefix, $dedicatedPrefix !== null ? $quizId : null);
  try {
    return quiz_public_url($route, $query);
  } finally {
    if (is_array($previous)) $GLOBALS['quiz_public_route_context'] = $previous;
    else unset($GLOBALS['quiz_public_route_context']);
  }
}

function quiz_public_route_accepts_quiz(int $quizId): bool {
  $bound = (int)(quiz_public_route_context()['quiz_id'] ?? 0);
  return $quizId > 0 && ($bound <= 0 || $bound === $quizId);
}

function quiz_render_core_404(): never {
  http_response_code(404);
  $core404 = defined('PLUGIN_PATH') ? dirname(PLUGIN_PATH) . '/app/frontend_404.php' : '';
  if ($core404 !== '' && is_file($core404)) require $core404;
  exit('Page not found');
}

function quiz_public_require_quiz(int $quizId): int {
  if (!quiz_public_route_accepts_quiz($quizId)) quiz_render_core_404();
  return $quizId;
}

function quiz_public_safe_next(string $next, string $fallbackRoute = 'start'): string {
  $fallback = quiz_public_url($fallbackRoute);
  $next = trim($next);
  if ($next === '') return $fallback;
  $parts = parse_url($next);
  if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
      || isset($parts['fragment']) || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
    return $fallback;
  }
  $path = rawurldecode((string)($parts['path'] ?? ''));
  $base = quiz_public_base();
  if (!str_starts_with($path, $base . '/') || str_contains($path, '//')
      || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1F\x7F]~', $path) === 1) return $fallback;
  return $next;
}

function quiz_normalize_bool(mixed $value, int $default = 0): int {
  if ($value === null || $value === '') return $default === 1 ? 1 : 0;
  if ($value === true || $value === 1 || $value === '1') return 1;
  if ($value === false || $value === 0 || $value === '0') return 0;
  throw new InvalidArgumentException('Invalid boolean value.');
}

function quiz_safe_bool(mixed $value, int $default = 0): int {
  try { return quiz_normalize_bool($value, $default); }
  catch (InvalidArgumentException) { return $default === 1 ? 1 : 0; }
}

function quiz_normalize_result_visibility(mixed $value): string {
  $value = strtolower(trim((string)$value));
  if (!in_array($value, ['hidden', 'summary', 'categories'], true)) {
    throw new InvalidArgumentException('Invalid result visibility.');
  }
  return $value;
}

function quiz_safe_result_visibility(mixed $value): string {
  try { return quiz_normalize_result_visibility($value); }
  catch (InvalidArgumentException) { return 'hidden'; }
}

function quiz_normalize_visual_theme(mixed $value): string {
  $value = strtolower(trim((string)$value));
  if (!in_array($value, ['default', 'studio'], true)) {
    throw new InvalidArgumentException('Invalid visual theme.');
  }
  return $value;
}

function quiz_safe_visual_theme(mixed $value): string {
  try { return quiz_normalize_visual_theme($value); }
  catch (InvalidArgumentException) { return 'default'; }
}

function quiz_safe_visual_theme_override(mixed $value): ?string {
  if ($value === null || trim((string)$value) === '') return null;
  try { return quiz_normalize_visual_theme($value); }
  catch (InvalidArgumentException) { return null; }
}

function quiz_public_visual_theme(PDO $pdo, ?int $quizId = null): string {
  $globalTheme = quiz_safe_visual_theme(quiz_database_setting($pdo, 'quiz_visual_theme', 'default'));
  $quizId ??= (int)(quiz_public_route_context()['quiz_id'] ?? 0);
  if ($quizId <= 0) return $globalTheme;

  try {
    $stmt = $pdo->prepare('SELECT visual_theme_override FROM quizzes WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $quizId]);
    $override = $stmt->fetchColumn();
    return $override === false ? $globalTheme : (quiz_safe_visual_theme_override($override) ?? $globalTheme);
  } catch (Throwable) {
    return $globalTheme;
  }
}

function quiz_site_favicon_url(PDO $pdo): string {
  $fallback = '/static/img/favicon/jyavani.svg';
  if (!function_exists('settings_get')) return $fallback;
  $url = trim((string)(settings_get($pdo, 'favicon_url', '') ?? ''));
  if ($url === '') return $fallback;
  if (function_exists('settings_favicon_url_validation_error')) {
    return settings_favicon_url_validation_error($url) === null ? $url : $fallback;
  }

  $parts = parse_url($url);
  if (!is_array($parts)) return $fallback;
  $path = rawurldecode((string)($parts['path'] ?? ''));
  $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//')
    && !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass']);
  $isHttps = ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
    && !isset($parts['user'], $parts['pass']);
  if ((!$isLocal && !$isHttps) || isset($parts['fragment']) || !in_array($extension, ['png', 'ico', 'svg'], true)
      || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || str_contains($path, '\\')) return $fallback;
  return $url;
}

/** Normalize stored seconds into unique, descending warning thresholds. */
function quiz_normalize_timer_warning_seconds(mixed $value): array {
  if ($value === null || $value === '') return [];
  if (is_string($value)) {
    $decoded = json_decode($value, true);
    $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
  }
  if (!is_array($value)) throw new InvalidArgumentException('Invalid timer warning thresholds.');
  $seconds = [];
  foreach ($value as $item) {
    if (is_int($item)) $number = $item;
    elseif (is_string($item) && ctype_digit($item)) $number = (int)$item;
    else throw new InvalidArgumentException('Timer warning thresholds must be whole seconds.');
    if ($number <= 0 || $number > 604800) throw new InvalidArgumentException('Timer warning threshold is out of range.');
    $seconds[$number] = $number;
  }
  rsort($seconds, SORT_NUMERIC);
  return array_values($seconds);
}

function quiz_safe_timer_warning_seconds(mixed $value): array {
  try { return quiz_normalize_timer_warning_seconds($value); }
  catch (InvalidArgumentException) { return []; }
}

/** Parse the admin's comma-separated minute values and store canonical seconds. */
function quiz_timer_warning_minutes_to_seconds(string $value): array {
  $value = trim($value);
  if ($value === '') return [];
  $minutes = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
  if (!is_array($minutes) || count($minutes) > 20) throw new InvalidArgumentException('Invalid timer warnings.');
  $seconds = [];
  foreach ($minutes as $minute) {
    if (!ctype_digit($minute) || (int)$minute <= 0 || (int)$minute > 10080) {
      throw new InvalidArgumentException('Timer warnings must be whole minutes between 1 and 10080.');
    }
    $seconds[] = (int)$minute * 60;
  }
  return quiz_normalize_timer_warning_seconds($seconds);
}

function quiz_timer_warning_seconds_to_minutes(mixed $value): string {
  return implode(', ', array_map(static fn(int $seconds): string => (string)intdiv($seconds, 60), quiz_normalize_timer_warning_seconds($value)));
}

function quiz_serialize_timer_warning_seconds(array $seconds): ?string {
  $seconds = quiz_normalize_timer_warning_seconds($seconds);
  return $seconds === [] ? null : implode(',', $seconds);
}

function quiz_mark_attempt_manually_verified(PDO $pdo, int $attemptId, string $via, ?int $verifiedBy, ?string $note): void {
  $stmt = $pdo->prepare("UPDATE quiz_attempts
    SET email_verified_at=NOW(), verified_via=:via, verified_by=:by, verified_note=:note,
        status='verified', last_activity_at=NOW()
    WHERE id=:id AND status IN ('registered','verified')
      AND started_at IS NULL AND submitted_at IS NULL LIMIT 1");
  $stmt->execute([
    ':via' => $via,
    ':by' => $verifiedBy,
    ':note' => $note,
    ':id' => $attemptId,
  ]);
  if ($stmt->rowCount() !== 1) {
    throw new RuntimeException('Attempt is not eligible for manual verification.');
  }
}

function quiz_attempt_question_snapshot_incomplete_condition(string $alias = 'aq'): string {
  if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
    throw new InvalidArgumentException('Invalid snapshot table alias.');
  }
  return "({$alias}.snapshot_at IS NULL
    OR {$alias}.available_score_snapshot IS NULL
    OR {$alias}.category_key_snapshot IS NULL
    OR {$alias}.category_name_snapshot IS NULL
    OR {$alias}.options_snapshotted_at IS NULL
    OR {$alias}.delivery_snapshotted_at IS NULL
    OR {$alias}.delivery_snapshot_json IS NULL
    OR JSON_VALID({$alias}.delivery_snapshot_json)=0
    OR COALESCE(JSON_EXTRACT({$alias}.delivery_snapshot_json,'$.version'),0)<>1
    OR COALESCE(JSON_TYPE(JSON_EXTRACT({$alias}.delivery_snapshot_json,'$.question')),'')<>'OBJECT')";
}

function quiz_questions_per_attempt_limit(mixed $value): int {
  $limit = (int)($value ?? 25);
  if ($limit <= 0) $limit = 25;
  return min(200, $limit);
}

/** Validate the live assignment count, category blocks/quotas, and passage grouping. */
function quiz_attempt_assignment_is_valid(PDO $pdo, int $attemptId): bool {
  if ($attemptId <= 0) return false;

  $attemptStmt = $pdo->prepare('SELECT a.quiz_id, q.questions_per_attempt
    FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE a.id=:attempt_id LIMIT 1');
  $attemptStmt->execute([':attempt_id' => $attemptId]);
  $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) return false;

  $quizId = (int)($attempt['quiz_id'] ?? 0);
  $limit = quiz_questions_per_attempt_limit($attempt['questions_per_attempt'] ?? null);
  if ($quizId <= 0) return false;

  $categoryStmt = $pdo->prepare('SELECT id, min_questions, max_questions
    FROM question_categories WHERE quiz_id=:quiz_id AND is_active=1
    ORDER BY CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END, sort_order ASC, id ASC');
  $categoryStmt->execute([':quiz_id' => $quizId]);
  $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $categoryOrder = [];
  $categoryBounds = [];
  $minimumTotal = 0;
  $maximumTotal = 0;
  $hasUnlimitedCategory = false;
  foreach ($categories as $index => $category) {
    $categoryId = (int)($category['id'] ?? 0);
    if ($categoryId <= 0 || isset($categoryOrder[$categoryId])) return false;

    $minRaw = $category['min_questions'] ?? null;
    $maxRaw = $category['max_questions'] ?? null;
    if ($minRaw !== null && $minRaw !== '' && !(is_int($minRaw) || is_string($minRaw) && ctype_digit($minRaw))) return false;
    if ($maxRaw !== null && $maxRaw !== '' && !(is_int($maxRaw) || is_string($maxRaw) && ctype_digit($maxRaw))) return false;
    $minimum = ($minRaw === null || $minRaw === '') ? 0 : (int)$minRaw;
    $maximum = ($maxRaw === null || $maxRaw === '') ? null : (int)$maxRaw;
    if ($minimum < 0 || ($maximum !== null && ($maximum < 0 || $minimum > $maximum))) return false;

    $categoryOrder[$categoryId] = $index;
    $categoryBounds[$categoryId] = [$minimum, $maximum];
    $minimumTotal += $minimum;
    if ($maximum === null) $hasUnlimitedCategory = true;
    else $maximumTotal += $maximum;
  }
  if ($minimumTotal > $limit) return false;

  $assignmentStmt = $pdo->prepare("SELECT aq.id, aq.seq, aq.question_id,
      q.quiz_id AS question_quiz_id, q.category_id AS question_category_id, q.passage_id AS question_passage_id,
      p.id AS passage_id, p.quiz_id AS passage_quiz_id, p.category_id AS passage_category_id, p.passage_text,
      p.min_questions AS passage_min_questions, p.max_questions AS passage_max_questions
    FROM attempt_questions aq
    LEFT JOIN questions q ON q.id=aq.question_id
    LEFT JOIN question_passages p ON p.id=q.passage_id
    WHERE aq.attempt_id=:attempt_id
    ORDER BY aq.seq ASC, aq.id ASC");
  $assignmentStmt->execute([':attempt_id' => $attemptId]);
  $rows = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (count($rows) !== $limit) return false;

  $categoryCounts = array_fill_keys(array_keys($categoryBounds), 0);
  $seenQuestions = [];
  $seenPassages = [];
  $passageDefinitions = [];
  $passageCounts = [];
  $currentPassageId = 0;
  $lastCategoryIndex = -1;
  $uncategorizedStarted = false;
  $hasUncategorized = false;

  foreach ($rows as $offset => $row) {
    $seqRaw = $row['seq'] ?? null;
    if (!(is_int($seqRaw) || is_string($seqRaw) && ctype_digit($seqRaw)) || (int)$seqRaw !== $offset + 1) return false;

    $questionId = (int)($row['question_id'] ?? 0);
    if ($questionId <= 0 || isset($seenQuestions[$questionId])
        || (int)($row['question_quiz_id'] ?? 0) !== $quizId) return false;
    $seenQuestions[$questionId] = true;

    $configuredPassageId = (int)($row['question_passage_id'] ?? 0);
    $passageId = (int)($row['passage_id'] ?? 0);
    if ($configuredPassageId > 0
        && ($passageId !== $configuredPassageId || (int)($row['passage_quiz_id'] ?? 0) !== $quizId)) return false;
    $realPassage = $passageId > 0 && (string)($row['passage_text'] ?? '') !== '__CATEGORY_CONTAINER__';

    if ($realPassage) {
      $minRaw = $row['passage_min_questions'] ?? null;
      $maxRaw = $row['passage_max_questions'] ?? null;
      if ($minRaw !== null && $minRaw !== '' && !(is_int($minRaw) || is_string($minRaw) && ctype_digit($minRaw))) return false;
      if ($maxRaw !== null && $maxRaw !== '' && !(is_int($maxRaw) || is_string($maxRaw) && ctype_digit($maxRaw))) return false;
      $minimum = ($minRaw === null || $minRaw === '') ? 0 : (int)$minRaw;
      $maximum = ($maxRaw === null || $maxRaw === '') ? null : (int)$maxRaw;
      if ($minimum < 0 || ($maximum !== null && ($maximum < 0 || $minimum > $maximum))) return false;
      $definition = [(int)$row['passage_quiz_id'], (int)($row['passage_category_id'] ?? 0),
        (string)$row['passage_text'], $minimum, $maximum];
      if (isset($passageDefinitions[$passageId]) && $passageDefinitions[$passageId] !== $definition) return false;
      $passageDefinitions[$passageId] = $definition;
      $passageCounts[$passageId] = ($passageCounts[$passageId] ?? 0) + 1;
      if ($currentPassageId !== $passageId) {
        if (isset($seenPassages[$passageId])) return false;
        $seenPassages[$passageId] = true;
        $currentPassageId = $passageId;
      }
    } else {
      $currentPassageId = 0;
    }

    if ($categories === []) continue;

    $passageCategoryId = (int)($row['passage_category_id'] ?? 0);
    $questionCategoryId = (int)($row['question_category_id'] ?? 0);
    $effectiveCategoryId = $realPassage
      ? $passageCategoryId
      : ($passageCategoryId > 0 ? $passageCategoryId : $questionCategoryId);

    if ($effectiveCategoryId <= 0) {
      $hasUncategorized = true;
      $uncategorizedStarted = true;
      continue;
    }
    if ($uncategorizedStarted || !isset($categoryOrder[$effectiveCategoryId])) return false;

    $categoryIndex = $categoryOrder[$effectiveCategoryId];
    if ($categoryIndex < $lastCategoryIndex) return false;
    $lastCategoryIndex = $categoryIndex;
    $categoryCounts[$effectiveCategoryId]++;
  }

  foreach ($passageDefinitions as $passageId => [, , , $minimum, $maximum]) {
    $count = (int)$passageCounts[$passageId];
    if ($count < $minimum || ($maximum !== null && $count > $maximum)) return false;
  }

  if ($categories === []) return true;

  foreach ($categoryBounds as $categoryId => [$minimum, $maximum]) {
    $count = (int)$categoryCounts[$categoryId];
    if ($count < $minimum || ($maximum !== null && $count > $maximum)) return false;
  }

  if ($hasUncategorized && ($hasUnlimitedCategory || $maximumTotal >= $limit)) return false;
  return true;
}

/** An attempt package is ready only when its structure and immutable metadata are complete. */
function quiz_attempt_package_is_complete(PDO $pdo, int $attemptId): bool {
  if ($attemptId <= 0) return false;

  $stmt = $pdo->prepare('SELECT id, attempt_id, question_id, seq, available_score_snapshot,
      category_key_snapshot, category_id_snapshot, category_name_snapshot, options_snapshotted_at,
      delivery_snapshot_json, delivery_snapshotted_at, snapshot_at
    FROM attempt_questions WHERE attempt_id=:attempt_id ORDER BY seq ASC, id ASC');
  $stmt->execute([':attempt_id' => $attemptId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) return false;

  $optionStmt = $pdo->prepare('SELECT attempt_question_id, attempt_id, question_id, source_answer_id,
      answer_text, score, delivery_seq
    FROM attempt_question_options WHERE attempt_id=:attempt_id
    ORDER BY attempt_question_id ASC, delivery_seq ASC, id ASC');
  $optionStmt->execute([':attempt_id' => $attemptId]);
  $optionRows = $optionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $isInteger = static fn(mixed $value): bool => is_int($value)
    || is_string($value) && preg_match('/^-?\d+$/D', $value) === 1;
  $hasKeys = static function (array $value, array $keys): bool {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $value)) return false;
    }
    return true;
  };
  $validAssetMetadata = static function (array $value, bool $passage) use ($isInteger, $hasKeys): bool {
    $keys = ['image_media_id', 'image_url', 'file_id', 'file_label', 'file_url', 'youtube_url'];
    if ($passage) array_push($keys, 'id', 'title', 'text', 'min_questions', 'max_questions');
    else array_push($keys, 'text', 'type');
    if (!$hasKeys($value, $keys)) return false;
    foreach (['image_media_id', 'file_id'] as $idKey) {
      if ($value[$idKey] !== null && (!$isInteger($value[$idKey]) || (int)$value[$idKey] <= 0)) return false;
    }
    foreach (['image_url', 'file_label', 'file_url', 'youtube_url'] as $textKey) {
      if (!is_string($value[$textKey])) return false;
    }
    if (!is_string($value['text'])) return false;
    if (!$passage) {
      return is_string($value['type'])
        && in_array($value['type'], ['single_choice', 'multiple_choice', 'yesno', 'text', 'number'], true);
    }
    if (!$isInteger($value['id']) || (int)$value['id'] <= 0 || !is_string($value['title'])
        || !$isInteger($value['min_questions']) || (int)$value['min_questions'] < 0) return false;
    return $value['max_questions'] === null
      || $isInteger($value['max_questions']) && (int)$value['max_questions'] >= (int)$value['min_questions'];
  };

  $assignments = [];
  $seenQuestions = [];
  $categoryDefinitions = [];
  $categoryCounts = [];
  $seenCategoryBlocks = [];
  $currentCategoryKey = null;
  $uncategorizedStarted = false;
  $passageDefinitions = [];
  $passageCounts = [];
  $seenPassageBlocks = [];
  $currentPassageId = 0;

  foreach ($rows as $offset => $row) {
    if (!$isInteger($row['id'] ?? null) || (int)$row['id'] <= 0
        || !$isInteger($row['attempt_id'] ?? null) || (int)$row['attempt_id'] !== $attemptId
        || !$isInteger($row['seq'] ?? null) || (int)$row['seq'] !== $offset + 1
        || !$isInteger($row['question_id'] ?? null) || (int)$row['question_id'] <= 0
        || isset($assignments[(int)$row['id']]) || isset($seenQuestions[(int)$row['question_id']])
        || !$isInteger($row['available_score_snapshot'] ?? null) || (int)$row['available_score_snapshot'] < 0
        || empty($row['options_snapshotted_at']) || empty($row['delivery_snapshotted_at']) || empty($row['snapshot_at'])) {
      return false;
    }

    $delivery = json_decode((string)($row['delivery_snapshot_json'] ?? ''), true);
    if (!is_array($delivery) || !$isInteger($delivery['version'] ?? null) || (int)$delivery['version'] !== 1
        || !is_array($delivery['question'] ?? null) || !$validAssetMetadata($delivery['question'], false)
        || !array_key_exists('passage', $delivery) || !is_array($delivery['category'] ?? null)) {
      return false;
    }

    $category = $delivery['category'];
    if (!$hasKeys($category, ['id', 'key', 'name', 'min_questions', 'max_questions'])
        || !is_string($category['key']) || !is_string($category['name'])
        || trim($category['key']) === '' || trim($category['name']) === ''
        || !$isInteger($category['min_questions']) || (int)$category['min_questions'] < 0
        || $category['max_questions'] !== null && (!$isInteger($category['max_questions'])
          || (int)$category['max_questions'] < (int)$category['min_questions'])
        || $category['id'] !== null && (!$isInteger($category['id']) || (int)$category['id'] <= 0)) {
      return false;
    }
    $categoryKey = trim($category['key']);
    $categoryName = trim($category['name']);
    $columnCategoryKey = trim((string)($row['category_key_snapshot'] ?? ''));
    $columnCategoryName = trim((string)($row['category_name_snapshot'] ?? ''));
    if (($row['category_id_snapshot'] ?? null) !== null
        && (!$isInteger($row['category_id_snapshot']) || (int)$row['category_id_snapshot'] <= 0)) return false;
    $columnCategoryId = $row['category_id_snapshot'] === null ? null : (int)$row['category_id_snapshot'];
    $categoryId = $category['id'] === null ? null : (int)$category['id'];
    if ($columnCategoryKey === '' || $columnCategoryName === '' || $columnCategoryKey !== $categoryKey
        || $columnCategoryName !== $categoryName || $columnCategoryId !== $categoryId) return false;

    $definition = [$categoryName, $categoryId, (int)$category['min_questions'],
      $category['max_questions'] === null ? null : (int)$category['max_questions']];
    if (isset($categoryDefinitions[$categoryKey]) && $categoryDefinitions[$categoryKey] !== $definition) return false;
    $categoryDefinitions[$categoryKey] = $definition;
    $categoryCounts[$categoryKey] = ($categoryCounts[$categoryKey] ?? 0) + 1;
    if ($currentCategoryKey !== $categoryKey) {
      if (isset($seenCategoryBlocks[$categoryKey]) || $uncategorizedStarted) return false;
      $seenCategoryBlocks[$categoryKey] = true;
      $currentCategoryKey = $categoryKey;
      if ($categoryKey === 'uncategorized') $uncategorizedStarted = true;
    }

    $passage = $delivery['passage'];
    $realPassageId = 0;
    if ($passage !== null) {
      if (!is_array($passage) || !$validAssetMetadata($passage, true)) return false;
      $passageId = (int)$passage['id'];
      $realPassage = trim($passage['text']) !== '' && trim($passage['text']) !== '__CATEGORY_CONTAINER__';
      if ($realPassage) {
        $realPassageId = $passageId;
        if (isset($passageDefinitions[$passageId]) && $passageDefinitions[$passageId] != $passage) return false;
        $passageDefinitions[$passageId] = $passage;
        $passageCounts[$passageId] = ($passageCounts[$passageId] ?? 0) + 1;
        if ($currentPassageId !== $passageId) {
          if (isset($seenPassageBlocks[$passageId])) return false;
          $seenPassageBlocks[$passageId] = true;
          $currentPassageId = $passageId;
        }
      } else {
        $currentPassageId = 0;
      }
    } else {
      $currentPassageId = 0;
    }
    if ($categoryKey === 'uncategorized') {
      if ($categoryId !== null) return false;
    } elseif (preg_match('/^category:([1-9]\d*)$/D', $categoryKey, $categoryMatch) === 1) {
      if ($categoryId !== (int)$categoryMatch[1]) return false;
    } elseif (preg_match('/^passage:([1-9]\d*)$/D', $categoryKey, $passageMatch) === 1) {
      if ($categoryId !== null || $realPassageId !== (int)$passageMatch[1]) return false;
    } else {
      return false;
    }

    $assignmentId = (int)$row['id'];
    $questionId = (int)$row['question_id'];
    $assignments[$assignmentId] = ['question_id' => $questionId,
      'type' => $delivery['question']['type'], 'available_score' => (int)$row['available_score_snapshot']];
    $seenQuestions[$questionId] = true;
  }

  foreach ($categoryDefinitions as $key => [, , $minimum, $maximum]) {
    $count = (int)$categoryCounts[$key];
    if ($count < $minimum || ($maximum !== null && $count > $maximum)) return false;
  }
  foreach ($passageDefinitions as $passageId => $passage) {
    $minimum = (int)$passage['min_questions'];
    $maximum = $passage['max_questions'] === null ? null : (int)$passage['max_questions'];
    $count = (int)$passageCounts[$passageId];
    if ($count < $minimum || ($maximum !== null && $count > $maximum)) return false;
  }

  $optionsByAssignment = [];
  foreach ($optionRows as $option) {
    if (!$isInteger($option['attempt_question_id'] ?? null) || !isset($assignments[(int)$option['attempt_question_id']])
        || !$isInteger($option['attempt_id'] ?? null) || (int)$option['attempt_id'] !== $attemptId
        || !$isInteger($option['question_id'] ?? null)
        || (int)$option['question_id'] !== $assignments[(int)$option['attempt_question_id']]['question_id']
        || !$isInteger($option['source_answer_id'] ?? null) || (int)$option['source_answer_id'] <= 0
        || !is_string($option['answer_text'] ?? null) || !$isInteger($option['score'] ?? null)
        || !$isInteger($option['delivery_seq'] ?? null) || (int)$option['delivery_seq'] <= 0) {
      return false;
    }
    $assignmentId = (int)$option['attempt_question_id'];
    $sourceId = (int)$option['source_answer_id'];
    $deliverySeq = (int)$option['delivery_seq'];
    if (isset($optionsByAssignment[$assignmentId]['sources'][$sourceId])
        || isset($optionsByAssignment[$assignmentId]['rows'][$deliverySeq])) return false;
    $optionsByAssignment[$assignmentId]['sources'][$sourceId] = true;
    $optionsByAssignment[$assignmentId]['rows'][$deliverySeq] = (int)$option['score'];
  }

  foreach ($assignments as $assignmentId => $assignment) {
    $options = $optionsByAssignment[$assignmentId]['rows'] ?? [];
    if ($options !== []) ksort($options, SORT_NUMERIC);
    if (array_keys($options) !== ($options === [] ? [] : range(1, count($options)))) return false;
    $choice = in_array($assignment['type'], ['single_choice', 'multiple_choice', 'yesno'], true);
    if ($choice !== ($options !== [])) return false;
    $availableScore = 0;
    if ($assignment['type'] === 'multiple_choice') {
      foreach ($options as $score) $availableScore += max(0, $score);
    } elseif (in_array($assignment['type'], ['single_choice', 'yesno'], true)) {
      foreach ($options as $score) $availableScore = max($availableScore, $score);
      $availableScore = max(0, $availableScore);
    }
    if ($assignment['available_score'] !== $availableScore) return false;
  }
  return true;
}

/** Capture immutable denominator, category, option, and delivery metadata once. */
function quiz_snapshot_attempt_questions(PDO $pdo, int $attemptId): void {
  if (!$pdo->inTransaction()) throw new RuntimeException('Attempt question snapshots require a transaction.');

  $attemptStmt = $pdo->prepare('SELECT token_hash, quiz_id FROM quiz_attempts WHERE id=:attempt_id LIMIT 1 FOR UPDATE');
  $attemptStmt->execute([':attempt_id' => $attemptId]);
  $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) throw new RuntimeException('Attempt not found for question snapshot.');
  $quizStmt = $pdo->prepare('SELECT shuffle_answers FROM quizzes WHERE id=:quiz_id LIMIT 1');
  $quizStmt->execute([':quiz_id' => (int)$attempt['quiz_id']]);
  $shuffleAnswers = quiz_safe_bool($quizStmt->fetchColumn(), 1);

  $assignmentLock = $pdo->prepare('SELECT id FROM attempt_questions WHERE attempt_id=:attempt_id ORDER BY id ASC FOR UPDATE');
  $assignmentLock->execute([':attempt_id' => $attemptId]);
  $assignmentLock->fetchAll(PDO::FETCH_COLUMN);

  $incomplete = quiz_attempt_question_snapshot_incomplete_condition('aq');
  $stmt = $pdo->prepare("SELECT aq.id, aq.available_score_snapshot, aq.category_key_snapshot,
      aq.category_name_snapshot, aq.options_snapshotted_at, aq.delivery_snapshot_json,
      aq.delivery_snapshotted_at, aq.snapshot_at,
      q.id AS question_id, q.question_text, q.question_type, q.passage_id,
      q.image_media_id, qm.url AS question_image_url, q.file_id,
      COALESCE(qf.filename,qf.title,'') AS question_file_label, qf.url AS question_file_url, q.youtube_url,
      p.category_id AS passage_category_id, p.title AS passage_title, p.passage_text,
      p.image_media_id AS passage_image_media_id, pm.url AS passage_image_url, p.file_id AS passage_file_id,
      COALESCE(pf.filename,pf.title,'') AS passage_file_label, pf.url AS passage_file_url, p.youtube_url AS passage_youtube_url,
      p.min_questions AS passage_min_questions, p.max_questions AS passage_max_questions,
      q.category_id AS question_category_id, pc.name AS passage_category_name, qc.name AS question_category_name,
      pc.min_questions AS passage_category_min_questions, pc.max_questions AS passage_category_max_questions,
      qc.min_questions AS question_category_min_questions, qc.max_questions AS question_category_max_questions
    FROM attempt_questions aq
    JOIN questions q ON q.id=aq.question_id
    LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id
    LEFT JOIN question_categories pc ON pc.id=p.category_id AND pc.quiz_id=q.quiz_id
    LEFT JOIN question_categories qc ON qc.id=q.category_id AND qc.quiz_id=q.quiz_id
    LEFT JOIN media qm ON qm.id=q.image_media_id AND qm.visibility='public' AND qm.storage_disk='public' AND qm.access_scope='public'
    LEFT JOIN media pm ON pm.id=p.image_media_id AND pm.visibility='public' AND pm.storage_disk='public' AND pm.access_scope='public'
    LEFT JOIN `file` qf ON qf.id=q.file_id AND qf.visibility='public' AND qf.storage_disk='public' AND qf.access_scope='public' AND qf.is_downloadable=1
    LEFT JOIN `file` pf ON pf.id=p.file_id AND pf.visibility='public' AND pf.storage_disk='public' AND pf.access_scope='public' AND pf.is_downloadable=1
    WHERE aq.attempt_id=:attempt_id AND {$incomplete}
    ORDER BY aq.id ASC");
  $stmt->execute([':attempt_id' => $attemptId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) return;

  if (!defined('QZ_ANSWER_SHUFFLE_SALT')) define('QZ_ANSWER_SHUFFLE_SALT', 'CHANGE_ME_to_random_long_string');
  $loadOptions = $pdo->prepare('SELECT id, answer_text, score, sort_order FROM question_answers
    WHERE question_id=:question_id AND is_active=1 ORDER BY sort_order ASC, id ASC');
  $deleteOptions = $pdo->prepare('DELETE FROM attempt_question_options WHERE attempt_question_id=:attempt_question_id');
  $insertOption = $pdo->prepare('INSERT INTO attempt_question_options
    (attempt_question_id, attempt_id, question_id, source_answer_id, answer_text, score, delivery_seq)
    VALUES (:attempt_question_id, :attempt_id, :question_id, :source_answer_id, :answer_text, :score, :delivery_seq)');
  $snapshottedOptions = $pdo->prepare('SELECT score FROM attempt_question_options
    WHERE attempt_question_id=:attempt_question_id ORDER BY delivery_seq ASC');
  $update = $pdo->prepare('UPDATE attempt_questions SET available_score_snapshot=COALESCE(available_score_snapshot,:available_score),
    category_id_snapshot=COALESCE(category_id_snapshot,:category_id),
    category_key_snapshot=COALESCE(category_key_snapshot,:category_key),
    category_name_snapshot=COALESCE(category_name_snapshot,:category_name),
    options_snapshotted_at=COALESCE(options_snapshotted_at,NOW()),
    delivery_snapshot_json=CASE WHEN :replace_delivery_json=1 THEN :delivery_snapshot ELSE delivery_snapshot_json END,
    delivery_snapshotted_at=CASE WHEN :replace_delivery_time=1 THEN NOW() ELSE COALESCE(delivery_snapshotted_at,NOW()) END,
    snapshot_at=COALESCE(snapshot_at,NOW())
    WHERE id=:id AND attempt_id=:attempt_id LIMIT 1');
  foreach ($rows as $row) {
    $questionType = (string)$row['question_type'];
    $existingDelivery = json_decode((string)($row['delivery_snapshot_json'] ?? ''), true);
    $deliveryPayloadComplete = is_array($existingDelivery) && (int)($existingDelivery['version'] ?? 0) === 1
      && is_array($existingDelivery['question'] ?? null);
    if ($deliveryPayloadComplete) {
      $questionType = (string)($existingDelivery['question']['type'] ?? $questionType);
    }
    $options = [];
    if (empty($row['options_snapshotted_at'])) {
      $deleteOptions->execute([':attempt_question_id' => (int)$row['id']]);
      $loadOptions->execute([':question_id' => (int)$row['question_id']]);
      $options = $loadOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if ($shuffleAnswers === 1 && $options !== []) {
        $seed = hash('sha256', QZ_ANSWER_SHUFFLE_SALT . '|' . $attemptId . '|'
          . (int)$row['question_id'] . '|' . (string)$attempt['token_hash']);
        usort($options, static function (array $left, array $right) use ($seed): int {
          $order = strcmp(md5($seed . ':' . (int)$left['id']), md5($seed . ':' . (int)$right['id']));
          return $order !== 0 ? $order : ((int)$left['id'] <=> (int)$right['id']);
        });
      }
      $deliverySeq = 1;
      foreach ($options as $option) {
        $insertOption->execute([
          ':attempt_question_id' => (int)$row['id'], ':attempt_id' => $attemptId,
          ':question_id' => (int)$row['question_id'], ':source_answer_id' => (int)$option['id'],
          ':answer_text' => (string)$option['answer_text'], ':score' => (int)$option['score'],
          ':delivery_seq' => $deliverySeq++,
        ]);
      }
    } else {
      $snapshottedOptions->execute([':attempt_question_id' => (int)$row['id']]);
      $options = array_map(static fn(mixed $score): array => ['score' => (int)$score], $snapshottedOptions->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $availableScore = 0;
    if ($questionType === 'multiple_choice') {
      foreach ($options as $option) $availableScore += max(0, (int)$option['score']);
    } elseif (in_array($questionType, ['single_choice', 'yesno'], true)) {
      foreach ($options as $option) $availableScore = max($availableScore, (int)$option['score']);
      $availableScore = max(0, $availableScore);
    }

    $passageId = (int)($row['passage_id'] ?? 0);
    $passageText = trim((string)($row['passage_text'] ?? ''));
    $realPassage = $passageId > 0 && $passageText !== '' && $passageText !== '__CATEGORY_CONTAINER__';
    $categoryId = (int)($row['passage_category_id'] ?? 0);
    $categoryName = trim((string)($row['passage_category_name'] ?? ''));
    if (!$realPassage && $categoryId <= 0) {
      $categoryId = (int)($row['question_category_id'] ?? 0);
      $categoryName = trim((string)($row['question_category_name'] ?? ''));
    }
    if ($categoryId > 0) {
      $categoryKey = 'category:' . $categoryId;
      if ($categoryName === '') $categoryName = 'Kategori';
    } elseif ($realPassage) {
      $categoryKey = 'passage:' . $passageId;
      $categoryName = trim((string)($row['passage_title'] ?? '')) ?: 'Passage';
      $categoryId = 0;
    } else {
      $categoryKey = 'uncategorized';
      $categoryName = 'Lainnya';
      $categoryId = 0;
    }
    if ($categoryId > 0 && (int)($row['passage_category_id'] ?? 0) > 0) {
      $categoryMin = (int)($row['passage_category_min_questions'] ?? 0);
      $categoryMax = $row['passage_category_max_questions'] === null ? null : (int)$row['passage_category_max_questions'];
    } elseif ($categoryId > 0) {
      $categoryMin = (int)($row['question_category_min_questions'] ?? 0);
      $categoryMax = $row['question_category_max_questions'] === null ? null : (int)$row['question_category_max_questions'];
    } elseif ($realPassage) {
      $categoryMin = (int)($row['passage_min_questions'] ?? 0);
      $categoryMax = $row['passage_max_questions'] === null ? null : (int)$row['passage_max_questions'];
    } else {
      $categoryMin = 0;
      $categoryMax = null;
    }
    $deliverySnapshot = null;
    if (!$deliveryPayloadComplete) {
      $deliverySnapshot = json_encode([
        'version' => 1,
        'question' => [
          'text' => (string)$row['question_text'],
          'type' => $questionType,
          'image_media_id' => !empty($row['question_image_url']) ? (int)$row['image_media_id'] : null,
          'image_url' => (string)($row['question_image_url'] ?? ''),
          'file_id' => !empty($row['question_file_url']) ? (int)$row['file_id'] : null,
          'file_label' => (string)($row['question_file_label'] ?? ''),
          'file_url' => (string)($row['question_file_url'] ?? ''),
          'youtube_url' => (string)($row['youtube_url'] ?? ''),
        ],
        'passage' => (int)($row['passage_id'] ?? 0) > 0 ? [
          'id' => (int)$row['passage_id'],
          'title' => (string)($row['passage_title'] ?? ''),
          'text' => (string)($row['passage_text'] ?? ''),
          'min_questions' => (int)($row['passage_min_questions'] ?? 0),
          'max_questions' => $row['passage_max_questions'] === null ? null : (int)$row['passage_max_questions'],
          'image_media_id' => !empty($row['passage_image_url']) ? (int)$row['passage_image_media_id'] : null,
          'image_url' => (string)($row['passage_image_url'] ?? ''),
          'file_id' => !empty($row['passage_file_url']) ? (int)$row['passage_file_id'] : null,
          'file_label' => (string)($row['passage_file_label'] ?? ''),
          'file_url' => (string)($row['passage_file_url'] ?? ''),
          'youtube_url' => (string)($row['passage_youtube_url'] ?? ''),
        ] : null,
        'category' => [
          'id' => $categoryId ?: null,
          'key' => $categoryKey,
          'name' => $categoryName,
          'min_questions' => $categoryMin,
          'max_questions' => $categoryMax,
        ],
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    $update->execute([
      ':available_score' => $availableScore,
      ':category_key' => $categoryKey,
      ':category_id' => $categoryId ?: null,
      ':category_name' => $categoryName,
      ':delivery_snapshot' => $deliverySnapshot,
      ':replace_delivery_json' => $deliveryPayloadComplete ? 0 : 1,
      ':replace_delivery_time' => $deliveryPayloadComplete ? 0 : 1,
      ':id' => (int)$row['id'],
      ':attempt_id' => $attemptId,
    ]);
  }
}

/** Keep immutable snapshot metadata only while its referenced assets remain public. */
function quiz_revalidate_delivery_snapshot_assets(PDO $pdo, array $snapshot): array {
  $sections = [];
  foreach (['question', 'passage'] as $section) {
    if (is_array($snapshot[$section] ?? null)) $sections[] = $section;
  }

  $mediaIds = [];
  $fileIds = [];
  foreach ($sections as $section) {
    $mediaId = (int)($snapshot[$section]['image_media_id'] ?? 0);
    $fileId = (int)($snapshot[$section]['file_id'] ?? 0);
    if ($mediaId > 0) $mediaIds[$mediaId] = $mediaId;
    if ($fileId > 0) $fileIds[$fileId] = $fileId;
  }

  $authorizedMediaIds = [];
  if ($mediaIds !== []) {
    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM media WHERE id IN ($placeholders)
      AND visibility='public' AND storage_disk='public' AND access_scope='public'");
    $stmt->execute(array_values($mediaIds));
    $authorizedMediaIds = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
  }

  $authorizedFileIds = [];
  if ($fileIds !== []) {
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM `file` WHERE id IN ($placeholders)
      AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1");
    $stmt->execute(array_values($fileIds));
    $authorizedFileIds = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
  }

  foreach ($sections as $section) {
    $mediaId = (int)($snapshot[$section]['image_media_id'] ?? 0);
    if ($mediaId <= 0 || !isset($authorizedMediaIds[$mediaId])) {
      $snapshot[$section]['image_media_id'] = null;
      $snapshot[$section]['image_url'] = '';
    }
    $fileId = (int)($snapshot[$section]['file_id'] ?? 0);
    if ($fileId <= 0 || !isset($authorizedFileIds[$fileId])) {
      $snapshot[$section]['file_id'] = null;
      $snapshot[$section]['file_label'] = '';
      $snapshot[$section]['file_url'] = '';
    }
  }
  return $snapshot;
}

/** Results are public only when the immutable denominator and aggregates agree. */
function quiz_attempt_result_is_ready(PDO $pdo, int $attemptId): bool {
  $stmt = $pdo->prepare("SELECT a.status, a.submitted_at, a.total_score, COUNT(aq.id) AS question_count,
      COALESCE(SUM(CASE WHEN aq.snapshot_at IS NOT NULL AND aq.options_snapshotted_at IS NOT NULL
        AND aq.delivery_snapshotted_at IS NOT NULL AND JSON_VALID(aq.delivery_snapshot_json)
        AND JSON_EXTRACT(aq.delivery_snapshot_json,'$.version')=1
        AND aq.available_score_snapshot IS NOT NULL
        AND aq.category_key_snapshot IS NOT NULL
        AND aq.category_name_snapshot IS NOT NULL THEN 1 ELSE 0 END),0) AS snapshot_count,
      COALESCE(SUM(aq.available_score_snapshot),0) AS available_score
    FROM quiz_attempts a LEFT JOIN attempt_questions aq ON aq.attempt_id=a.id
    WHERE a.id=:attempt_id GROUP BY a.id, a.status, a.submitted_at, a.total_score");
  $stmt->execute([':attempt_id' => $attemptId]);
  $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt || (string)$attempt['status'] !== 'submitted' || empty($attempt['submitted_at'])) return false;
  $questionCount = (int)$attempt['question_count'];
  $available = (int)$attempt['available_score'];
  $totalScore = (int)$attempt['total_score'];
  if ($questionCount <= 0 || (int)$attempt['snapshot_count'] !== $questionCount
      || $available <= 0 || $totalScore < 0 || $totalScore > $available) return false;

  $scores = $pdo->prepare("SELECT COUNT(*) AS category_count, COALESCE(SUM(question_count),0) AS question_count,
      COALESCE(SUM(earned_score),0) AS earned_score, COALESCE(SUM(available_score),0) AS available_score,
      COALESCE(SUM(CASE WHEN earned_score<0 OR earned_score>available_score
        OR (available_score>0 AND (percent IS NULL OR percent<0 OR percent>100))
        OR (available_score=0 AND percent IS NOT NULL) THEN 1 ELSE 0 END),0) AS invalid_count
    FROM attempt_category_scores WHERE attempt_id=:attempt_id");
  $scores->execute([':attempt_id' => $attemptId]);
  $aggregate = $scores->fetch(PDO::FETCH_ASSOC) ?: [];
  return (int)($aggregate['category_count'] ?? 0) > 0
    && (int)($aggregate['question_count'] ?? 0) === $questionCount
    && (int)($aggregate['earned_score'] ?? 0) === $totalScore
    && (int)($aggregate['available_score'] ?? 0) === $available
    && (int)($aggregate['invalid_count'] ?? 0) === 0;
}

function quiz_participant_submit_payload(int $attemptId, int $total, int $answered, bool $timeUp,
    string $visibility, bool $resultReady, string $attemptAlias): array {
  $data = [
    'attempt_id' => $attemptId,
    'total_questions' => $total,
    'answered_count' => $answered,
    'time_up' => $timeUp,
  ];
  if (in_array($visibility, ['summary', 'categories'], true) && $resultReady) {
    $data['result_url'] = quiz_public_url('result', ['a' => $attemptAlias]);
  }
  return $data;
}

/** Finalize an attempt under a row lock; safe to call repeatedly in a transaction. */
function quiz_finalize_attempt(PDO $pdo, int $attemptId): array {
  if (!$pdo->inTransaction()) throw new RuntimeException('Attempt finalization requires a transaction.');

  $lock = $pdo->prepare('SELECT id, quiz_id, status, started_at, submitted_at, total_score, elapsed_seconds FROM quiz_attempts WHERE id=:id LIMIT 1 FOR UPDATE');
  $lock->execute([':id' => $attemptId]);
  $attempt = $lock->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) throw new RuntimeException('Attempt not found for finalization.');

  if (!quiz_attempt_package_is_complete($pdo, $attemptId)) {
    throw new RuntimeException('Attempt package preparation is required.');
  }

  if ((string)$attempt['status'] === 'submitted') {
    return [
      'attempt_id' => $attemptId,
      'quiz_id' => (int)$attempt['quiz_id'],
      'finalized' => false,
    ];
  }

  $rowsStmt = $pdo->prepare("SELECT aq.question_id, aq.available_score_snapshot,
      aq.category_key_snapshot, aq.category_id_snapshot, aq.category_name_snapshot, aq.snapshot_at,
      aa.selected_answer_id, aa.answer_text, COALESCE(aa.computed_score, 0) AS computed_score
    FROM attempt_questions aq
    LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
    WHERE aq.attempt_id=:aid ORDER BY aq.seq ASC");
  $rowsStmt->execute([':aid' => $attemptId]);
  $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) throw new RuntimeException('No questions assigned for finalization.');

  $snapshotCategoryIds = [];
  foreach ($rows as $row) {
    $categoryId = (int)($row['category_id_snapshot'] ?? 0);
    if ($categoryId > 0) $snapshotCategoryIds[$categoryId] = $categoryId;
  }
  $validCategoryIds = [];
  if ($snapshotCategoryIds !== []) {
    $placeholders = implode(',', array_fill(0, count($snapshotCategoryIds), '?'));
    $categoryStmt = $pdo->prepare("SELECT id FROM question_categories
      WHERE quiz_id=? AND id IN ($placeholders) FOR UPDATE");
    $categoryStmt->execute([(int)$attempt['quiz_id'], ...array_values($snapshotCategoryIds)]);
    $validCategoryIds = array_fill_keys(array_map('intval', $categoryStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
  }

  $totalScore = 0;
  $categories = [];
  foreach ($rows as $row) {
    $key = trim((string)($row['category_key_snapshot'] ?? ''));
    $categoryName = trim((string)($row['category_name_snapshot'] ?? ''));
    if (empty($row['snapshot_at']) || $row['available_score_snapshot'] === null || $key === '' || $categoryName === '') {
      throw new RuntimeException('Attempt question snapshot is incomplete.');
    }
    $categoryId = (int)($row['category_id_snapshot'] ?? 0);
    $categoryId = isset($validCategoryIds[$categoryId]) ? $categoryId : 0;
    $answered = $row['selected_answer_id'] !== null
      || (trim((string)($row['answer_text'] ?? '')) !== '' && trim((string)$row['answer_text']) !== '[]');
    $score = (int)($row['computed_score'] ?? 0);
    $totalScore += $score;
    if (!isset($categories[$key])) {
      $categories[$key] = ['category_id' => $categoryId ?: null, 'category_name' => $categoryName,
        'question_count' => 0, 'answered_count' => 0, 'correct_count' => 0, 'earned_score' => 0, 'available_score' => 0];
    }
    $categories[$key]['question_count']++;
    if ($answered) $categories[$key]['answered_count']++;
    if ($score > 0) $categories[$key]['correct_count']++;
    $categories[$key]['earned_score'] += $score;
    $categories[$key]['available_score'] += max(0, (int)$row['available_score_snapshot']);
  }

  $pdo->prepare('DELETE FROM attempt_category_scores WHERE attempt_id=:aid')->execute([':aid' => $attemptId]);
  $insertCategory = $pdo->prepare('INSERT INTO attempt_category_scores
    (attempt_id, category_key, category_id, category_name, question_count, answered_count, correct_count, earned_score, available_score, percent)
    VALUES (:aid, :category_key, :category_id, :category_name, :question_count, :answered_count, :correct_count, :earned_score, :available_score, :percent)');
  foreach ($categories as $key => $category) {
    $available = (int)$category['available_score'];
    $insertCategory->execute([
      ':aid' => $attemptId, ':category_key' => $key, ':category_id' => $category['category_id'],
      ':category_name' => $category['category_name'], ':question_count' => $category['question_count'],
      ':answered_count' => $category['answered_count'], ':correct_count' => $category['correct_count'],
      ':earned_score' => $category['earned_score'], ':available_score' => $available,
      ':percent' => $available > 0 && (int)$category['earned_score'] >= 0 && (int)$category['earned_score'] <= $available
        ? round(((int)$category['earned_score'] / $available) * 100, 2) : null,
    ]);
  }

  $update = $pdo->prepare("UPDATE quiz_attempts SET total_score=:score, status='submitted',
      submitted_at=COALESCE(submitted_at,NOW()),
      elapsed_seconds=CASE WHEN started_at IS NULL THEN NULL ELSE GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,COALESCE(submitted_at,NOW()))) END,
      last_activity_at=NOW() WHERE id=:id AND status<>'submitted' LIMIT 1");
  $update->execute([':score' => $totalScore, ':id' => $attemptId]);

  return ['attempt_id' => $attemptId, 'quiz_id' => (int)$attempt['quiz_id'], 'finalized' => $update->rowCount() === 1];
}

function quiz_available_quizzes(PDO $pdo): array {
  $now = date('Y-m-d H:i:s');
  $st = $pdo->prepare("
    SELECT id, title, public_prefix, description, questions_per_attempt, duration_minutes,
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
