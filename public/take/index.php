<?php
declare(strict_types=1);

// anti clickjacking / embedded iframe
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none';");
header('Referrer-Policy: same-origin');


$publicTitle = quiz_public_title($pdo);
$page_title = 'Take ' . $publicTitle;

// Anti-cache (ujian)
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: 0');
}

require_once __DIR__ . '/_take_ctx.php';
require_once __DIR__ . '/_take_view.php';

require_once __DIR__ . '/../layout.php';
exit;
