<?php
declare(strict_types=1);

$bodyContent = $content_html ?? '';
$publicTitle = isset($pdo) && $pdo instanceof PDO ? quiz_public_title($pdo) : 'Quiz';
$pageTitle = $page_title ?? $publicTitle;
$faviconUrl = isset($pdo) && $pdo instanceof PDO && function_exists('quiz_site_favicon_url')
  ? quiz_site_favicon_url($pdo)
  : '/static/img/favicon/jyavani.svg';
$visualThemeQuizId = isset($quiz_visual_theme_quiz_id) ? (int)$quiz_visual_theme_quiz_id : null;
$visualTheme = isset($pdo) && $pdo instanceof PDO && function_exists('quiz_public_visual_theme')
  ? quiz_public_visual_theme($pdo, $visualThemeQuizId)
  : 'default';
$visualThemeClass = 'quiz-theme-' . quiz_safe_visual_theme($visualTheme);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8fafc; color: #0f172a; }
  </style>
  <?php if (function_exists('do_action')) do_action('jy_head'); ?>
</head>
<body class="<?= htmlspecialchars($visualThemeClass, ENT_QUOTES, 'UTF-8') ?>" data-quiz-visual-theme="<?= htmlspecialchars($visualTheme, ENT_QUOTES, 'UTF-8') ?>">
<?= $bodyContent ?>
</body>
</html>
