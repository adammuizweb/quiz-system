<?php
declare(strict_types=1);

$bodyContent = $content_html ?? '';
$pageTitle = $page_title ?? 'Quiz';

?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8fafc; color: #0f172a; }
  </style>
  <?php if (function_exists('do_action')) do_action('jy_head'); ?>
</head>
<body>
<?= $bodyContent ?>
</body>
</html>
