<?php
declare(strict_types=1);

// Quiz Plugin - Jyavani CMS

function quiz_default_sender_email(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    if ($host !== '' && $host !== 'localhost'
        && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
        return 'no-reply@' . $host;
    }
    return 'no-reply@example.com';
}

function quiz_normalize_test_number_format(string $format): ?string {
    $format = trim($format);
    if ($format === '' || strlen($format) > 60
        || preg_match('/\{(?:attempt_id|id)(?::(?:[1-9]|1[0-2]))?\}/', $format) !== 1) {
        return null;
    }
    $literal = preg_replace('/\{(?:year|(?:(?:attempt_id|quiz_id|id)(?::(?:[1-9]|1[0-2]))?))\}/', '', $format);
    if (!is_string($literal) || preg_match('/^[A-Za-z0-9._\/-]*$/', $literal) !== 1) return null;
    return $format;
}

function quiz_format_test_number(string $format, int $attemptId, ?int $year = null, ?int $quizId = null): string {
    $format = quiz_normalize_test_number_format($format) ?? 'QUIZ/{year}/{attempt_id:6}';
    if (str_contains($format, '{quiz_id') && ($quizId ?? 0) <= 0) {
        throw new InvalidArgumentException('Quiz ID is required by the test-number format.');
    }
    $formatted = str_replace('{year}', (string)($year ?? (int)date('Y')), $format);
    return (string)preg_replace_callback(
        '/\{(attempt_id|quiz_id|id)(?::([1-9]|1[0-2]))?\}/',
        static function (array $match) use ($attemptId, $quizId): string {
            $value = $match[1] === 'quiz_id' ? (int)$quizId : $attemptId;
            $width = isset($match[2]) ? (int)$match[2] : 0;
            return $width > 0 ? str_pad((string)$value, $width, '0', STR_PAD_LEFT) : (string)$value;
        },
        $formatted
    );
}

function quiz_upgrade_schema(\PDO $pdo): void {
    try {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    } catch (\Throwable $e) {
        return;
    }
    if ($driver !== 'mysql') return;

    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name'
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $indexExists = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name'
        );
        $stmt->execute([':table_name' => $table, ':index_name' => $index]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_collaborators (
          quiz_id INT UNSIGNED NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          assigned_by INT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (quiz_id, user_id),
          KEY idx_quiz_collaborators_user (user_id, quiz_id),
          KEY idx_quiz_collaborators_assigned_by (assigned_by),
          CONSTRAINT fk_quiz_collaborators_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE,
          CONSTRAINT fk_quiz_collaborators_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
          CONSTRAINT fk_quiz_collaborators_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $column = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_attempts' AND COLUMN_NAME = 'delivery_mode'"
    );
    $column->execute();
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN delivery_mode VARCHAR(20) NULL AFTER verified_note");
    }

    $index = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_attempts' AND INDEX_NAME = 'uq_quiz_student'"
    );
    $index->execute();
    if ((int)$index->fetchColumn() === 0) {
        $duplicate = $pdo->query(
            'SELECT 1 FROM quiz_attempts GROUP BY quiz_id, student_id HAVING COUNT(*) > 1 LIMIT 1'
        );
        if ($duplicate !== false && $duplicate->fetchColumn() !== false) {
            throw new \RuntimeException('Duplicate quiz attempts must be resolved before enforcing one attempt per student.');
        }
        $pdo->exec('ALTER TABLE quiz_attempts ADD UNIQUE KEY uq_quiz_student (quiz_id, student_id)');
    }

    $ownerTables = [
        'question_categories' => 'idx_question_categories_owner',
        'question_passages' => 'idx_question_passages_owner',
        'questions' => 'idx_questions_owner',
    ];
    foreach ($ownerTables as $table => $ownerIndex) {
        if (!$columnExists($table, 'created_by')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN created_by INT UNSIGNED NULL AFTER quiz_id");
        }
        $pdo->exec(
            "UPDATE {$table} resource JOIN quizzes q ON q.id=resource.quiz_id "
            . 'SET resource.created_by=q.created_by WHERE resource.created_by IS NULL OR resource.created_by=0'
        );
        $missingOwner = $pdo->query("SELECT 1 FROM {$table} WHERE created_by IS NULL OR created_by=0 LIMIT 1");
        if ($missingOwner !== false && $missingOwner->fetchColumn() !== false) {
            throw new \RuntimeException("Unable to backfill {$table} ownership.");
        }
        $pdo->exec("ALTER TABLE {$table} MODIFY created_by INT UNSIGNED NOT NULL");
        if (!$indexExists($table, $ownerIndex)) {
            $pdo->exec("ALTER TABLE {$table} ADD KEY {$ownerIndex} (quiz_id, created_by)");
        }
    }
}

// --- Idempotent schema installation on Quiz requests ---
function quiz_install_schema(\PDO $pdo): void {
    try {
        $schemaRevision = '20260821-resource-owners';
        $manifest = plugin_manifest('quiz');
        $version = is_array($manifest) && is_string($manifest['version'] ?? null)
            ? $manifest['version']
            : '';
        if ($version === '') {
            throw new \RuntimeException('Plugin version is unavailable.');
        }
        $installedVersion = settings_get($pdo, 'quiz_test_version', '');
        if ($installedVersion === $version) {
            if (settings_get($pdo, 'quiz_schema_revision', '') !== $schemaRevision) {
                quiz_upgrade_schema($pdo);
                if (!settings_set($pdo, 'quiz_schema_revision', $schemaRevision)) {
                    throw new \RuntimeException('Schema revision could not be recorded.');
                }
            }
            return;
        }

        $schemaFile = __DIR__ . '/schema.sql';
        $sql = is_file($schemaFile) ? file_get_contents($schemaFile) : false;
        if (!is_string($sql) || trim($sql) === '') {
            throw new \RuntimeException('Schema file is missing or unreadable.');
        }
        if ($pdo->exec($sql) === false) {
            $error = $pdo->errorInfo();
            throw new \RuntimeException((string)($error[2] ?? 'Schema execution failed.'));
        }
        quiz_upgrade_schema($pdo);
        if (!settings_set($pdo, 'quiz_schema_revision', $schemaRevision)) {
            throw new \RuntimeException('Schema revision could not be recorded.');
        }
        if (!settings_set($pdo, 'quiz_test_version', $version)) {
            throw new \RuntimeException('Schema version could not be recorded.');
        }
    } catch (\Throwable $e) {
        // Leave the stored version unchanged so the next request retries.
        error_log('[quiz] Schema migration failed: ' . $e->getMessage());
    }
}

function quiz_schema_is_ready(\PDO $pdo): bool {
    $manifest = plugin_manifest('quiz');
    $version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
    return $version !== ''
        && settings_get($pdo, 'quiz_test_version', '') === $version
        && settings_get($pdo, 'quiz_schema_revision', '') === '20260821-resource-owners';
}

/** Resolve only explicitly supported public PHP entrypoints. */
function quiz_frontend_entrypoint(string $route): ?string {
    $entrypoints = [
        '' => 'index.php',
        'index.php' => 'index.php',
        'register' => 'register/index.php',
        'register/index.php' => 'register/index.php',
        'verify' => 'verify/index.php',
        'verify/index.php' => 'verify/index.php',
        'start' => 'start/index.php',
        'start/index.php' => 'start/index.php',
        'start/confirm' => 'start/confirm/index.php',
        'start/confirm/index.php' => 'start/confirm/index.php',
        'take' => 'take/index.php',
        'take/index.php' => 'take/index.php',
        'reset' => 'reset/index.php',
        'reset/index.php' => 'reset/index.php',
        'send_otp.php' => 'send_otp.php',
        'api/get_question.php' => 'api/get_question.php',
        'api/proctor_event.php' => 'api/proctor_event.php',
        'api/save_answer.php' => 'api/save_answer.php',
        'api/submit.php' => 'api/submit.php',
    ];

    $relative = $entrypoints[trim($route, '/')] ?? null;
    if ($relative === null) return null;

    $public = realpath(__DIR__ . '/public');
    $file = realpath(__DIR__ . '/public/' . $relative);
    if ($public === false || $file === false || !is_file($file)
        || !str_starts_with($file, $public . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $file;
}

// --- Frontend route: /quiz/* ---
register_frontend_route('quiz', function (\PDO $pdo): void {
    if (!quiz_schema_is_ready($pdo)) {
        http_response_code(503);
        echo 'Quiz is not ready. An authorized administrator must open the Quiz dashboard first.';
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $path = rawurldecode($path);

    $route = $path === '/quiz'
        ? ''
        : (str_starts_with($path, '/quiz/') ? substr($path, strlen('/quiz/')) : null);
    $entrypoint = is_string($route) ? quiz_frontend_entrypoint($route) : null;
    if ($entrypoint !== null) {
        require $entrypoint;
        exit;
    }

    http_response_code(404);
    echo 'Quiz page not found';
    exit;
});

// --- Include shared helpers ---
$helperFile = __DIR__ . '/includes/helpers.php';
if (is_file($helperFile)) {
    require_once $helperFile;
}

function quiz_is_public_request(): bool {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $path = rawurldecode($path);
    return $path === '/quiz' || str_starts_with($path, '/quiz/');
}

function quiz_is_admin_request(): bool {
    $page = trim((string)($_GET['page'] ?? ''), "/ \t\n\r\0\x0B");
    return $page === 'admin/quiz' || str_starts_with($page, 'admin/quiz/');
}

// --- Assets are request-scoped because Core otherwise loads manifest assets globally. ---
add_action('jy_head', function (): void {
    if (!quiz_is_public_request()) return;
    $cssFile = __DIR__ . '/public/style.css';
    if (is_file($cssFile)) {
        echo '<style>' . file_get_contents($cssFile) . '</style>';
    }
});

add_action('admin_head', function (): void {
    if (!quiz_is_admin_request()) return;
    echo '<link rel="stylesheet" href="/static/plugins/quiz/admin.css?v=1.4.12">' . PHP_EOL;
    echo '<script src="/static/plugins/quiz/admin.js?v=1.4.12" defer></script>' . PHP_EOL;
});

// --- Admin sidebar menu ---
add_action('admin_menu', function (): void {
    if (!defined('ADMIN_BASE_PATH') || !function_exists('svg_ico')
        || !function_exists('plugin_resolve_route') || !function_exists('plugin_route_is_allowed')) return;

    $base = ADMIN_BASE_PATH;
    $pdo = $GLOBALS['pdo'] ?? null;
    $uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
    if (!($pdo instanceof \PDO) || $uid <= 0) return;
    $requested = trim((string)($_GET['page'] ?? 'home'), "/ \t\n\r\0\x0B");
    $prefix = 'admin/quiz';
    $isActive = function_exists('adam_nav_active') ? adam_nav_active($requested, $prefix) : str_starts_with($requested, $prefix);
    $openClass = $isActive ? ' is-open' : '';
    $activeClass = $isActive ? ' adam-nav-link--active' : '';

    $pages = [
        ['page' => 'admin/quiz',          'label' => 'Dashboard',      'icon' => 'house',     'prefixes' => ['admin/quiz']],
        ['page' => 'admin/quiz/quizzes',  'label' => 'Quiz',           'icon' => 'pen',       'prefixes' => ['admin/quiz/quizzes']],
        ['page' => 'admin/quiz/master',   'label' => 'Master Builder', 'icon' => 'cog',       'prefixes' => ['admin/quiz/master', 'admin/quiz/categories', 'admin/quiz/passages', 'admin/quiz/questions', 'admin/quiz/dump']],
        ['page' => 'admin/quiz/students', 'label' => 'Student',        'icon' => 'users',     'prefixes' => ['admin/quiz/students']],
        ['page' => 'admin/quiz/attempts', 'label' => 'Attempt',        'icon' => 'file-text', 'prefixes' => ['admin/quiz/attempts']],
        ['page' => 'admin/quiz/settings', 'label' => 'Config',         'icon' => 'settings',  'prefixes' => ['admin/quiz/settings']],
    ];
    $pages = array_values(array_filter($pages, static function (array $item) use ($pdo, $uid): bool {
        $route = plugin_resolve_route((string)$item['page']);
        return is_array($route) && plugin_route_is_allowed($pdo, $route, $uid);
    }));
    if ($pages === []) return;
    $parentPage = (string)$pages[0]['page'];

    echo '<li class="adam-nav-item' . $openClass . '" data-prefix="' . h($prefix) . '">';
    echo '<a class="adam-nav-link' . $activeClass . '" href="' . h($base . '/?page=' . $parentPage) . '">';
    echo '<span class="adam-nav-icon" aria-hidden="true">' . svg_ico('book-open') . '</span>';
    echo '<span class="adam-nav-text">Sistem Quiz</span>';
    echo '</a>';

    echo '<div class="adam-nav-sub" aria-hidden="' . ($isActive ? 'false' : 'true') . '">';

    foreach ($pages as $p) {
        $href = $base . '/?page=' . $p['page'];
        $isSubActive = false;
        foreach ($p['prefixes'] ?? [$p['page']] as $pagePrefix) {
            if ($requested === $pagePrefix || ($pagePrefix !== 'admin/quiz' && str_starts_with($requested, $pagePrefix . '/'))) {
                $isSubActive = true;
                break;
            }
        }
        $sActiveClass = $isSubActive ? ' adam-nav-sublink--active' : '';
        echo '<a class="adam-nav-sublink' . $sActiveClass . '" href="' . h($href) . '">';
        echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . svg_ico($p['icon'], 'adam-svg-icon--sm') . '</span>';
        echo '<span class="adam-nav-sublink-text">' . __($p['label']) . '</span>';
        echo '</a>';
    }

    echo '</div>';
    echo '</li>';
});

// --- Uninstall: hapus data hanya jika user tidak ceklist "keep data" ---
add_action('plugin_uninstall', function(string $name): void {
    if ($name !== 'quiz') return;
    global $pdo;
    $tables = [
        'attempt_answers',
        'attempt_questions',
        'question_answers',
        'quiz_attempt_proctor_logs',
        'questions',
        'question_passages',
        'question_categories',
        'quiz_attempts',
        'quiz_collaborators',
        'quizzes',
        'students'
    ];
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->prepare("DELETE FROM settings WHERE `key` LIKE 'quiz_%'")->execute();
});
