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
