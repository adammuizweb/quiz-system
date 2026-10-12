<?php
declare(strict_types=1);

if (!function_exists('qz_env_enabled')) {
  function qz_env_enabled(string $name): bool {
    $value = getenv($name);
    return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('qz_request_is_https')) {
  function qz_request_is_https(): bool {
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
  }
}

if (!function_exists('qz_cookie_secure')) {
  function qz_cookie_secure(): bool {
    if (qz_request_is_https()) return true;

    $forceHttps = qz_env_enabled('FORCE_HTTPS');
    $allowInsecure = qz_env_enabled('SESSION_ALLOW_INSECURE_COOKIES');
    return $forceHttps && !$allowInsecure;
  }
}

if (!function_exists('qz_public_csrf_token')) {
  function qz_public_csrf_token(): string {
    if (!function_exists('stateless_csrf_token')) {
      throw new RuntimeException('Stateless CSRF support is unavailable.');
    }
    return stateless_csrf_token();
  }
}

if (!function_exists('qz_public_csrf_check')) {
  function qz_public_csrf_check(?string $token, int $ttl = 604800): bool {
    return function_exists('stateless_csrf_check') && stateless_csrf_check($token, $ttl);
  }
}

if (!function_exists('qz_public_csrf_from_request')) {
  function qz_public_csrf_from_request(): string {
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') return $header;
    return (string)($_POST['csrf_token'] ?? '');
  }
}

if (!function_exists('qz_public_post_redirect')) {
  function qz_public_post_redirect(string $action, array $fields): never {
    global $pdo;
    $publicTitle = $pdo instanceof PDO ? quiz_public_title($pdo) : 'Quiz';
    $safeTitle = htmlspecialchars($publicTitle, ENT_QUOTES, 'UTF-8');
    $parts = parse_url($action);
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    if (!is_array($parts) || !str_starts_with($path, '/') || str_starts_with($path, '//')
        || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['fragment'])
        || preg_match('/[\x00-\x1F\x7F]/', $action) === 1) {
      throw new InvalidArgumentException('Invalid internal POST destination.');
    }

    $inputs = '';
    foreach ($fields as $name => $value) {
      if (!is_string($name) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) !== 1 || !is_scalar($value)) {
        throw new InvalidArgumentException('Invalid internal POST field.');
      }
      $inputs .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
    }

    if (!headers_sent()) {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
      header('Referrer-Policy: same-origin');
      header('X-Frame-Options: DENY');
    }
    $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>Starting ' . $safeTitle . '</title><style>body{font-family:system-ui,sans-serif;margin:0;background:#f8fafc;color:#0f172a}'
      . 'main{max-width:560px;margin:12vh auto;padding:24px}button{border:0;border-radius:12px;padding:12px 16px;background:#111827;color:#fff;font-weight:800}</style>'
      . '</head><body><form id="qz-auto-start" method="post" action="' . $safeAction . '">' . $inputs
      . '<noscript><main><h1>Ready to begin?</h1><p>JavaScript is disabled. Select Start ' . $safeTitle . ' to continue.</p>'
      . '<button type="submit">Start ' . $safeTitle . '</button></main></noscript></form>'
      . '<script>document.getElementById("qz-auto-start").submit();</script></body></html>';
    exit;
  }
}

if (!function_exists('qz_public_enter_quiz')) {
  function qz_public_enter_quiz(string $attemptAlias, mixed $startedAt = null): never {
    $url = quiz_public_url('take', ['a' => $attemptAlias]);
    if (trim((string)$startedAt) === '') {
      qz_public_post_redirect($url, ['csrf_token' => qz_public_csrf_token()]);
    }
    if (!headers_sent()) {
      header('Location: ' . $url, true, 303);
      exit;
    }
    echo '<script>location.href=' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    exit;
  }
}

if (!function_exists('qz_current_origin')) {
  function qz_current_origin(PDO $pdo): string {
    $siteUrl = function_exists('settings_get') ? trim((string)(settings_get($pdo, 'site_url', '') ?? '')) : '';
    if ($siteUrl !== '' && filter_var($siteUrl, FILTER_VALIDATE_URL)) {
      $parts = parse_url($siteUrl);
      $scheme = strtolower((string)($parts['scheme'] ?? ''));
      $host = (string)($parts['host'] ?? '');
      if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
        if (qz_cookie_secure()) $scheme = 'https';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $host = '[' . $host . ']';
        $portNumber = (int)($parts['port'] ?? 0);
        $port = ($portNumber > 0 && !in_array($portNumber, [80, 443], true)) ? ':' . $portNumber : '';
        return $scheme . '://' . $host . $port;
      }
    }

    $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/i', $host))) {
      throw new RuntimeException('Unable to determine a trusted site origin.');
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $host = '[' . $host . ']';

    $scheme = qz_cookie_secure() ? 'https' : 'http';
    $portNumber = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $port = ($portNumber > 0 && !in_array($portNumber, [80, 443], true)) ? ':' . $portNumber : '';
    return $scheme . '://' . $host . $port;
  }
}

if (!function_exists('qz_admin_base_path')) {
  function qz_admin_base_path(PDO $pdo): string {
    if (defined('ADMIN_BASE_PATH')) {
      return '/' . trim((string)constant('ADMIN_BASE_PATH'), '/');
    }
    $path = function_exists('get_admin_path') ? get_admin_path($pdo) : 'adiwira';
    return '/' . trim((string)$path, '/');
  }
}
