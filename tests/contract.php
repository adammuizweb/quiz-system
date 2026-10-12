<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreOverride = trim((string)getenv('QUIZ_CORE_ROOT'));
$coreCandidates = $coreOverride !== '' ? [$coreOverride] : [];
for ($candidate = dirname($pluginRoot); $candidate !== dirname($candidate); $candidate = dirname($candidate)) {
    $coreCandidates[] = $candidate;
}
$coreRoot = '';
foreach (array_unique($coreCandidates) as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false && is_file($resolved . '/VERSION')
        && is_file($resolved . '/cfg/helpers/authorization.php') && is_file($resolved . '/plugins/index.php')) {
        $coreRoot = $resolved;
        break;
    }
}
if ($coreRoot === '') {
    fwrite(STDERR, "Unable to locate Jyavani Core. Set QUIZ_CORE_ROOT.\n");
    exit(2);
}
$failures = [];
$checks = 0;

$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$manifestJson = file_get_contents($pluginRoot . '/plugin.json');
$manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
$check(is_array($manifest), 'plugin.json must contain valid JSON');
if (!is_array($manifest)) $manifest = [];

$repository = 'https://github.com/adammuizweb/quiz-system';
$check(($manifest['version'] ?? null) === '1.8.3', 'plugin version must be 1.8.3');
$check(($manifest['plugin_uri'] ?? null) === $repository, 'plugin_uri must use the canonical repository');
$check(($manifest['homepage'] ?? null) === $repository, 'homepage must use the canonical repository');
$check(($manifest['github_url'] ?? null) === $repository, 'github_url must use the canonical repository');
$check(($manifest['store']['url'] ?? null) === 'https://jyavani.com/plugin-store', 'store URL must use the canonical Plugin Store');
$check(($manifest['store']['slug'] ?? null) === 'quiz', 'store slug must match the plugin identity');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.91', 'Jyavani requirement must cover exact frontend route registration');
$check(($manifest['requires']['php'] ?? null) === '>=8.3', 'Quiz and its bundled spreadsheet dependency must require PHP 8.3 or newer');

$composerJson = json_decode((string)file_get_contents($pluginRoot . '/composer.json'), true);
$composerLock = json_decode((string)file_get_contents($pluginRoot . '/composer.lock'), true);
$check(is_array($composerJson)
    && ($composerJson['require']['php'] ?? null) === '>=8.3'
    && ($composerJson['require']['phpoffice/phpspreadsheet'] ?? null) === '~5.8.1', 'Composer must match the PHP 8.3 plugin baseline and pin PhpSpreadsheet 5.8');
$lockedPackages = [];
foreach (is_array($composerLock['packages'] ?? null) ? $composerLock['packages'] : [] as $package) {
    if (is_array($package)) $lockedPackages[(string)($package['name'] ?? '')] = (string)($package['version'] ?? '');
}
$check(($lockedPackages['phpoffice/phpspreadsheet'] ?? null) === '5.8.1', 'composer.lock must freeze PhpSpreadsheet 5.8.1');
$check(($lockedPackages['maennchen/zipstream-php'] ?? null) === '3.2.2', 'composer.lock must freeze the PHP 8.3-compatible ZipStream release');
$vendorAutoload = $pluginRoot . '/vendor/autoload.php';
$check(is_file($vendorAutoload), 'release source must bundle the Composer vendor autoloader for shared hosting');
if (is_file($vendorAutoload)) require_once $vendorAutoload;
$check(class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class), 'bundled vendor tree must load PhpSpreadsheet');

$coreVersion = trim((string)file_get_contents($coreRoot . '/VERSION'));
$check(version_compare($coreVersion, '2.3.91', '>='), 'current Core must support exact frontend routes');

if (!defined('BACKEND_PATH')) define('BACKEND_PATH', $coreRoot . '/cfg');
if (!defined('PLUGIN_PATH')) define('PLUGIN_PATH', dirname($pluginRoot));
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', $coreRoot . '/public');
require_once $coreRoot . '/cfg/helpers/hooks.php';
require_once $coreRoot . '/cfg/helpers/authorization.php';
require_once $coreRoot . '/cfg/helpers/content_route_helpers.php';
require_once $coreRoot . '/plugins/index.php';

$check(
    plugin_version_requirement_met('2.3.91', (string)($manifest['requires']['jyavani'] ?? '')),
    'target Core must satisfy the manifest requirement'
);
$check(plugin_normalize_requirements($manifest)['errors'] === [], 'Core must accept manifest requirement metadata');
$check(plugin_manifest_contract_errors($manifest) === [], 'Core must accept the dynamic permission manifest');
$check(function_exists('plugin_route_is_allowed'), 'target Core must provide dynamic plugin route guards');

$pages = $manifest['admin']['pages'] ?? [];
$check(is_array($pages) && $pages !== [], 'manifest must declare admin routes');
$permissionPolicies = [
    'plugin.quiz.dashboard.access' => [['admin', 'editor'], true],
    'plugin.quiz.workspace.access' => [['admin', 'editor'], true],
    'plugin.quiz.quizzes.manage' => [['admin'], false],
    'plugin.quiz.attempts.view' => [['admin'], true],
    'plugin.quiz.attempts.export' => [['admin'], true],
    'plugin.quiz.attempt-otp.view' => [['admin'], true],
    'plugin.quiz.attempt-responses.view' => [['admin'], false],
    'plugin.quiz.attempts.operate' => [['admin'], true],
    'plugin.quiz.attempts.delete' => [['admin'], false],
    'plugin.quiz.settings.manage' => [['admin'], false],
    'plugin.quiz.students.manage' => [['admin'], false],
    'plugin.quiz.primary.manage' => [['admin'], false],
    'plugin.quiz.content.manage-any' => [['admin'], false],
];
$permissionDefinitions = [];
foreach ($manifest['permissions'] ?? [] as $permission) {
    if (is_array($permission)) $permissionDefinitions[(string)($permission['key'] ?? '')] = $permission;
}
$check(array_keys($permissionDefinitions) === array_keys($permissionPolicies), 'manifest must preserve the exact ordered Quiz permission set');
$check(!isset($permissionDefinitions['plugin.quiz.control.manage']), 'broad control.manage permission must be removed');
$check(!isset($permissionDefinitions['plugin.quiz.attempts.manage']), 'legacy attempts.manage permission must be removed');
foreach ($permissionPolicies as $key => [$defaultRoles, $delegable]) {
    $definition = $permissionDefinitions[$key] ?? [];
    $check(($definition['default_roles'] ?? null) === $defaultRoles, "{$key} must declare stable default roles");
    $check(($definition['delegable'] ?? null) === $delegable, "{$key} must declare its delegation policy");
    $check(($definition['supports_scope'] ?? null) === false, "{$key} must remain an unscoped permission");
}
$routePermissions = [];
foreach ([
    'admin/quiz/quizzes', 'admin/quiz/questions', 'admin/quiz/questions/bulk', 'admin/quiz/master',
    'admin/quiz/categories/index', 'admin/quiz/categories/add', 'admin/quiz/categories/edit', 'admin/quiz/categories/delete',
    'admin/quiz/passages/index', 'admin/quiz/passages/add', 'admin/quiz/passages/edit', 'admin/quiz/passages/delete',
    'admin/quiz/dump',
    'admin/quiz/master/api/questions_list', 'admin/quiz/master/api/question_get', 'admin/quiz/master/api/question_save',
    'admin/quiz/master/api/question_delete', 'admin/quiz/master/api/questions_reorder',
    'admin/quiz/master/api/answer_save', 'admin/quiz/master/api/answer_delete', 'admin/quiz/master/api/answers_reorder',
    'admin/quiz/master/api/bulk_import', 'admin/quiz/master/api/passages_list', 'admin/quiz/master/api/filter_passages', 'admin/quiz/master/api/passage_get',
    'admin/quiz/master/api/passage_save', 'admin/quiz/master/api/passage_delete', 'admin/quiz/master/api/passages_reorder',
    'admin/quiz/master/api/categories_list', 'admin/quiz/master/api/category_get', 'admin/quiz/master/api/category_save',
    'admin/quiz/master/api/category_delete', 'admin/quiz/master/api/category_order',
] as $route) $routePermissions[$route] = 'plugin.quiz.workspace.access';
foreach ([
    'admin/quiz/quizzes/add', 'admin/quiz/quizzes/edit', 'admin/quiz/quizzes/delete',
    'admin/quiz/quizzes/duplicate', 'admin/quiz/quizzes/bulk_status',
] as $route) $routePermissions[$route] = 'plugin.quiz.quizzes.manage';
$routePermissions += [
    'admin/quiz' => 'plugin.quiz.dashboard.access',
    'admin/quiz/settings' => 'plugin.quiz.settings.manage',
    'admin/quiz/students' => 'plugin.quiz.students.manage',
    'admin/quiz/quizzes/set_primary' => 'plugin.quiz.primary.manage',
    'admin/quiz/quizzes/collaborators' => 'plugin.quiz.content.manage-any',
    'admin/quiz/attempts' => 'plugin.quiz.attempts.view',
    'admin/quiz/attempts/view' => 'plugin.quiz.attempts.view',
    'admin/quiz/attempts/export' => 'plugin.quiz.attempts.export',
    'admin/quiz/attempts/action_generate_otp' => 'plugin.quiz.attempts.operate',
    'admin/quiz/attempts/action_update_delivery_mode' => 'plugin.quiz.attempts.operate',
    'admin/quiz/attempts/action_verify_manual' => 'plugin.quiz.attempts.operate',
    'admin/quiz/attempts/action_reset' => 'plugin.quiz.attempts.operate',
    'admin/quiz/attempts/action_delete' => 'plugin.quiz.attempts.delete',
];
$manifestRoutePermissions = [];
foreach (is_array($pages) ? $pages : [] as $page) {
    if (is_array($page)) $manifestRoutePermissions[(string)($page['route'] ?? '')] = (string)($page['permission'] ?? '');
}
$check($manifestRoutePermissions == $routePermissions, 'manifest must preserve the exact admin route map');
foreach (is_array($pages) ? $pages : [] as $page) {
    $route = is_array($page) ? (string)($page['route'] ?? '') : '';
    $relative = is_array($page) ? (string)($page['file'] ?? '') : '';
    $resolved = $relative !== '' ? realpath($pluginRoot . '/' . $relative) : false;
    $check($route !== '', 'every admin route must have a route name');
    $expectedPermission = $routePermissions[$route] ?? null;
    $check(($page['permission'] ?? null) === $expectedPermission, "admin route {$route} must use its dynamic permission family");
    $check(($page['roles'] ?? null) === ($permissionPolicies[$expectedPermission][0] ?? null), "admin route {$route} roles must match permission defaults");
    $check(
        $resolved !== false && is_file($resolved) && str_starts_with($resolved, $pluginRoot . DIRECTORY_SEPARATOR),
        "admin route {$route} must resolve to a file inside the plugin"
    );
    if (str_starts_with($route, 'admin/quiz/master/api/')) {
        $check(($page['roles'] ?? null) === ['admin', 'editor'], "Master API route {$route} must allow admin/editor");
        $check(($page['hidden'] ?? null) === true, "Master API route {$route} must be hidden");
    }
}
$pagesByRoute = [];
foreach (is_array($pages) ? $pages : [] as $page) {
    if (is_array($page)) $pagesByRoute[(string)($page['route'] ?? '')] = $page;
}
foreach (['admin/quiz/settings', 'admin/quiz/students', 'admin/quiz/quizzes/set_primary'] as $route) {
    $check(($pagesByRoute[$route]['roles'] ?? null) === ['admin'], "{$route} must be admin-only");
}
$collaboratorRoute = $pagesByRoute['admin/quiz/quizzes/collaborators'] ?? [];
$check(($collaboratorRoute['roles'] ?? null) === ['admin'], 'collaborator assignment must be admin-only');
$check(($collaboratorRoute['hidden'] ?? null) === true, 'collaborator assignment route must be hidden');
$check(($collaboratorRoute['permission'] ?? null) === 'plugin.quiz.content.manage-any', 'collaborator assignment must require nondelegable manage-any');
$check(($pagesByRoute['admin/quiz']['roles'] ?? null) === ['admin', 'editor'], 'Quiz dashboard must allow its admin/editor defaults');
foreach (['admin/quiz/attempts', 'admin/quiz/attempts/view', 'admin/quiz/attempts/export'] as $route) {
    $check(($pagesByRoute[$route]['roles'] ?? null) === ['admin'], "{$route} must use its admin-only default role gate");
}
$bulkRoute = $pagesByRoute['admin/quiz/quizzes/bulk_status'] ?? [];
$check(($bulkRoute['roles'] ?? null) === ['admin'], 'bulk quiz status must use the quizzes.manage admin default');
$check(($bulkRoute['hidden'] ?? null) === true, 'bulk quiz status must be hidden from navigation');
$modeRoute = $pagesByRoute['admin/quiz/attempts/action_update_delivery_mode'] ?? [];
$check(($modeRoute['roles'] ?? null) === ['admin'], 'delivery mode mutation must use the attempts.operate admin default');
$check(($modeRoute['hidden'] ?? null) === true, 'delivery mode mutation must be hidden from navigation');

$copies = $manifest['static']['copy'] ?? [];
$check(is_array($copies) && $copies !== [], 'manifest must declare static copies');
foreach (is_array($copies) ? $copies : [] as $copy) {
    $from = is_array($copy) ? (string)($copy['from'] ?? '') : '';
    $to = is_array($copy) ? (string)($copy['to'] ?? '') : '';
    $check(is_file($pluginRoot . '/' . $from), "static source {$from} must exist");
    $check(str_starts_with($to, 'static/plugins/quiz/'), "static destination {$to} must use the plugin namespace");
    $check(plugin_static_path('quiz', $to) !== null, "Core must accept static destination {$to}");
}
foreach (['css', 'js'] as $assetType) {
    foreach ($manifest['assets'][$assetType] ?? [] as $url) {
        $check(str_starts_with((string)$url, '/static/plugins/quiz/'), "{$assetType} URL {$url} must use the plugin namespace");
    }
}
$check(($manifest['assets'] ?? []) === [], 'admin assets must not be declared globally in the manifest');
$copyDestinations = array_column(is_array($copies) ? $copies : [], 'to');
$check(!in_array('static/plugins/quiz/quiz.css', $copyDestinations, true), 'unused shared CSS must not be published as a global asset');
$copyMap = [];
foreach (is_array($copies) ? $copies : [] as $copy) {
    if (is_array($copy)) $copyMap[(string)($copy['from'] ?? '')] = (string)($copy['to'] ?? '');
}
$check(($copyMap['assets/admin.css'] ?? '') === 'static/plugins/quiz/admin.css', 'admin CSS must be declared for static publication');
$check(($copyMap['assets/admin.js'] ?? '') === 'static/plugins/quiz/admin.js', 'admin JavaScript must be declared for static publication');

$schemaSource = (string)file_get_contents($pluginRoot . '/schema.sql');
preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?/i', $schemaSource, $schemaCreates);
preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', $schemaSource, $idempotentCreates);
$check(
    count($schemaCreates[0]) > 0 && count($schemaCreates[0]) === count($idempotentCreates[0]),
    'all schema table creation must be idempotent for upgrades from 1.1.0'
);
$check(!preg_match('/\bALTER\s+TABLE\b/i', $schemaSource), 'schema must not contain speculative ALTER statements');
$check(preg_match('/`title`\s+VARCHAR\(255\)\s+NOT\s+NULL/i', $schemaSource) === 1, 'passage title schema contract must remain non-null');
$check(preg_match('/`delivery_mode`\s+VARCHAR\(20\)\s+NULL/i', $schemaSource) === 1, 'new installs must include nullable delivery mode');
$check(str_contains($schemaSource, 'UNIQUE KEY `uq_quiz_student` (`quiz_id`, `student_id`)'), 'new installs must enforce one attempt per quiz and student');
$check(str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS `quiz_collaborators`'), 'new installs must create the collaborator table idempotently');
$check(str_contains($schemaSource, 'PRIMARY KEY (`quiz_id`, `user_id`)'), 'collaborators must be unique per quiz and user');
$check(str_contains($schemaSource, 'CONSTRAINT `fk_quiz_collaborators_quiz`') && str_contains($schemaSource, 'REFERENCES `quizzes` (`id`) ON DELETE CASCADE'), 'collaborators must cascade when a quiz is deleted');
$check(str_contains($schemaSource, '`assigned_by` INT UNSIGNED NULL') && str_contains($schemaSource, 'ON DELETE SET NULL'), 'collaborator assignment audit must safely tolerate deleted assigners');
foreach ([
    'question_categories' => 'idx_question_categories_owner',
    'question_passages' => 'idx_question_passages_owner',
    'questions' => 'idx_questions_owner',
] as $table => $ownerIndex) {
    preg_match('/CREATE TABLE IF NOT EXISTS `' . preg_quote($table, '/') . '` \((.*?)\n\) ENGINE=/s', $schemaSource, $tableMatch);
    $tableSchema = (string)($tableMatch[1] ?? '');
    $check(str_contains($tableSchema, '`created_by` INT UNSIGNED NOT NULL'), "{$table} must require a resource owner on new installs");
    $check(str_contains($tableSchema, "KEY `{$ownerIndex}` (`quiz_id`, `created_by`)"), "{$table} must index quiz and resource owner");
}
$check(!preg_match('/CREATE TABLE IF NOT EXISTS `question_answers` \(.*?`created_by`/s', $schemaSource), 'answer ownership must be inherited from its question');
$check(str_contains($schemaSource, '`shuffle_answers` TINYINT(1) NOT NULL DEFAULT 1')
    && str_contains($schemaSource, '`timer_warning_seconds` VARCHAR(255) NULL')
    && str_contains($schemaSource, '`allow_mark_for_review` TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($schemaSource, "`result_visibility` ENUM('hidden','summary','categories') NOT NULL DEFAULT 'hidden'"), 'new quiz settings must preserve legacy defaults with a strict result allowlist');
$check(str_contains($schemaSource, '`public_prefix` VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NULL')
    && str_contains($schemaSource, 'UNIQUE KEY `uq_quizzes_public_prefix` (`public_prefix`)'), 'new installs must support one unique public URL prefix per Quiz');
$overrideColumns = ['verification_required_override', 'require_fullscreen_override', 'pause_when_blur_override', 'block_context_menu_override', 'block_shortcuts_override', 'devtools_detect_override', 'show_exam_badge_override'];
$check(array_reduce($overrideColumns, static fn(bool $valid, string $column): bool => $valid && str_contains($schemaSource, "`{$column}` TINYINT(1) NULL"), true),
    'new installs must support nullable per-Quiz verification and proctor overrides');
$check(str_contains($schemaSource, '`visual_theme_override` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL'),
    'new installs must support a nullable allowlisted per-Quiz visual theme override');
$check(str_contains($schemaSource, '`elapsed_seconds` INT UNSIGNED NULL')
    && str_contains($schemaSource, '`marked_for_review` TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($schemaSource, '`revision_count` INT UNSIGNED NOT NULL DEFAULT 0'), 'attempt readiness columns must use backward-compatible defaults');
$check(str_contains($schemaSource, '`available_score_snapshot` INT UNSIGNED NULL')
    && str_contains($schemaSource, '`category_key_snapshot` VARCHAR(100) NULL')
    && str_contains($schemaSource, '`category_id_snapshot` INT UNSIGNED NULL')
    && str_contains($schemaSource, '`category_name_snapshot` VARCHAR(255) NULL')
    && str_contains($schemaSource, '`options_snapshotted_at` DATETIME NULL')
    && str_contains($schemaSource, '`delivery_snapshot_json` LONGTEXT NULL')
    && str_contains($schemaSource, '`delivery_snapshotted_at` DATETIME NULL')
    && str_contains($schemaSource, '`snapshot_at` DATETIME NULL'), 'assigned questions must support additive immutable denominator, option, category, and delivery snapshots');
$check(str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS `attempt_answer_revisions`')
    && str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS `attempt_category_scores`')
    && str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS `attempt_question_options`')
    && str_contains($schemaSource, '`source_answer_id` INT UNSIGNED NOT NULL')
    && str_contains($schemaSource, '`delivery_seq` INT UNSIGNED NOT NULL'), 'attempt audit, option snapshot, and aggregate tables must be idempotent on new installs');

$pluginSource = (string)file_get_contents($pluginRoot . '/plugin.php');
$extensionSource = (string)file_get_contents($pluginRoot . '/includes/extensions.php');
$helperSource = (string)file_get_contents($pluginRoot . '/includes/helpers.php');
$takeScriptSource = (string)file_get_contents($pluginRoot . '/public/take/_take_js.php');
$check(!str_contains($takeScriptSource, '<?=')
    && str_contains($takeScriptSource, '$apiGetJson      = json_encode(quiz_public_url(')
    && str_contains($takeScriptSource, 'const API_GET    = {$apiGetJson};')
    && str_contains($takeScriptSource, 'const PROCTOR_API = {$proctorApiJson};'),
    'participant script must interpolate pre-encoded API URLs without literal PHP tags');
require_once $pluginRoot . '/includes/helpers.php';
require_once $pluginRoot . '/includes/extensions.php';
require_once $pluginRoot . '/public/api/_timer_helper.php';
$check(quiz_extension_api_version() === '1.1.0'
    && array_keys(quiz_extension_context()) === ['plugin', 'version', 'api_version'], 'extension 1.0 callable metadata must remain backward compatible in API 1.1');
$emittedLifecycle = null;
quiz_extension_subscribe('answer.saved', static function (array $payload) use (&$emittedLifecycle): void { $emittedLifecycle = $payload; });
quiz_extension_emit('answer.saved', ['attempt_id' => 7, 'question_id' => 9, 'token_hash' => 'secret',
    'answer_text' => 'secret', 'selected_answer_id' => 4, 'computed_score' => 100]);
$check(($emittedLifecycle['attempt_id'] ?? null) === 7 && ($emittedLifecycle['event'] ?? null) === 'answer.saved'
    && !isset($emittedLifecycle['token_hash'], $emittedLifecycle['answer_text'], $emittedLifecycle['selected_answer_id'], $emittedLifecycle['computed_score']), 'answer.saved lifecycle payloads must exclude answers, selections, scores, and correctness');
$submittedLifecycle = null;
quiz_extension_subscribe('attempt.submitted', static function (array $payload) use (&$submittedLifecycle): void { $submittedLifecycle = $payload; });
quiz_extension_emit('attempt.submitted', ['attempt_id' => 7, 'quiz_id' => 2, 'reason' => 'timer_expired',
    'status' => 'submitted', 'total_score' => 100, 'elapsed_seconds' => 60]);
$check(array_keys($submittedLifecycle ?? []) === ['event', 'api_version', 'attempt_id', 'quiz_id', 'reason', 'status'], 'all attempt.submitted listeners must receive the same score-free lifecycle shape');
$hiddenSubmitPayload = quiz_participant_submit_payload(4, 10, 8, false, 'hidden', true, 'a_private');
$check($hiddenSubmitPayload === ['attempt_id' => 4, 'total_questions' => 10, 'answered_count' => 8, 'time_up' => false]
    && !isset($hiddenSubmitPayload['total_score'], $hiddenSubmitPayload['elapsed_seconds'], $hiddenSubmitPayload['result_url'], $hiddenSubmitPayload['category_scores']), 'hidden submit payload must expose no score, elapsed time, categories, or result URL');
$check(isset(quiz_participant_submit_payload(4, 10, 8, false, 'summary', true, 'a_public')['result_url'])
    && !isset(quiz_participant_submit_payload(4, 10, 8, false, 'summary', false, 'a_incomplete')['result_url']), 'submit result URLs require both visible configuration and a valid finalized snapshot');
$titleRejections = 0;
foreach (['', '<b>Exam</b>', "Exam\nTitle", str_repeat('T', 81)] as $invalidTitle) {
    try { quiz_normalize_public_title($invalidTitle); } catch (InvalidArgumentException) { $titleRejections++; }
}
$check(quiz_normalize_public_title(' Exam ') === 'Exam' && $titleRejections === 4,
    'Global Title normalization must trim valid text and reject blank, markup, control characters, and overlong values');
$prefixRejected = false;
try { quiz_normalize_public_prefix('quiz'); } catch (InvalidArgumentException) { $prefixRejected = true; }
quiz_set_public_route_context('assessment', 9);
$check(quiz_normalize_public_prefix(' TRAINING ') === 'training' && quiz_normalize_public_prefix('quiz', true) === 'quiz' && $prefixRejected
    && quiz_public_url('register') === '/assessment/register/'
    && quiz_public_url('verify', ['quiz' => 9]) === '/assessment/verify/?quiz=9'
    && quiz_public_safe_next('/assessment/take/?a=abc') === '/assessment/take/?a=abc'
    && quiz_public_safe_next('/quiz/take/?a=abc') === '/assessment/start/'
    && quiz_public_route_accepts_quiz(9) && !quiz_public_route_accepts_quiz(8),
    'public route helpers must normalize prefixes, bind one Quiz, and reject cross-prefix redirects');
unset($GLOBALS['quiz_public_route_context']);
$prefixPdo = new PDO('sqlite::memory:');
$prefixPdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
$prefixPdo->exec('CREATE TABLE quizzes (id INTEGER PRIMARY KEY, public_prefix TEXT)');
$prefixPdo->exec('CREATE TABLE content_routes (id INTEGER PRIMARY KEY, post_id INTEGER, locale TEXT, path TEXT, is_canonical INTEGER)');
$prefixPdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT, type TEXT, is_deleted INTEGER)');
$prefixPdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('quiz_global_title', 'Exam')");
$prefixPdo->exec("INSERT INTO quizzes (id, public_prefix) VALUES (901, 'unavailable')");
$prefixPdo->exec("INSERT INTO content_routes (id, post_id, locale, path, is_canonical) VALUES (1, 1, '', 'training', 1)");
$GLOBALS['quiz_public_routes_initialized'] = true;
$GLOBALS['quiz_public_registered_global_prefix'] = 'quiz';
$check(quiz_public_prefix_conflict($prefixPdo, 'training') !== null
    && quiz_public_prefix_conflict($prefixPdo, 'artikel') !== null
    && quiz_public_url_for_quiz($prefixPdo, 901, 'register') === '/quiz/register/'
    && quiz_public_url_for_quiz($prefixPdo, 901, 'register') === '/quiz/register/',
    'public prefixes must reject Core/content collisions and unavailable stored routes must fall back to /quiz');
$check(quiz_public_title($prefixPdo) === 'Exam', 'participant terminology must resolve the configured Global Title');
$prefixPdo->exec("UPDATE settings SET `value`='<script>' WHERE `key`='quiz_global_title'");
$check(quiz_public_title($prefixPdo) === 'Quiz', 'invalid stored Global Title values must fail closed to Quiz');
unset($GLOBALS['quiz_public_routes_initialized'], $GLOBALS['quiz_public_registered_global_prefix'], $GLOBALS['quiz_public_registered_quiz_prefixes']);
$check(quiz_normalize_timer_warning_seconds('60,300,60') === [300, 60]
    && quiz_timer_warning_minutes_to_seconds('5, 1') === [300, 60]
    && quiz_serialize_timer_warning_seconds([]) === null, 'timer warning helpers must normalize, deduplicate, sort, and preserve empty defaults');
$normalizerRejected = false;
try { quiz_normalize_result_visibility('answers'); } catch (InvalidArgumentException) { $normalizerRejected = true; }
$themeNormalizerRejected = false;
try { quiz_normalize_visual_theme('custom'); } catch (InvalidArgumentException) { $themeNormalizerRejected = true; }
$check($normalizerRejected && quiz_safe_result_visibility('answers') === 'hidden'
    && $themeNormalizerRejected && quiz_normalize_visual_theme(' STUDIO ') === 'studio'
    && quiz_normalize_visual_theme(' NAVY ') === 'navy'
    && quiz_safe_visual_theme('custom') === 'default' && quiz_safe_visual_theme_override('custom') === null
    && quiz_safe_bool(null, 1) === 1, 'quiz setting helpers must validate input strictly and fail closed for stored values');
$visualThemePdo = new PDO('sqlite::memory:');
$visualThemePdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
$visualThemePdo->exec('CREATE TABLE quizzes (id INTEGER PRIMARY KEY, visual_theme_override TEXT)');
$visualThemePdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('quiz_visual_theme','default')");
$visualThemePdo->exec("INSERT INTO quizzes (id,visual_theme_override) VALUES (9,'studio'),(10,NULL),(11,'invalid'),(12,'navy')");
quiz_set_public_route_context('tpa', 9);
$dedicatedTheme = quiz_public_visual_theme($visualThemePdo);
quiz_set_public_route_context('assessment', 10);
$inheritedTheme = quiz_public_visual_theme($visualThemePdo);
$visualThemePdo->exec("UPDATE settings SET `value`='studio' WHERE `key`='quiz_visual_theme'");
$inheritedStudioTheme = quiz_public_visual_theme($visualThemePdo);
quiz_set_public_route_context('assessment', 11);
$invalidOverrideTheme = quiz_public_visual_theme($visualThemePdo);
quiz_set_public_route_context('assessment', null);
$globalTheme = quiz_public_visual_theme($visualThemePdo);
$visualThemePdo->exec("UPDATE settings SET `value`='navy' WHERE `key`='quiz_visual_theme'");
quiz_set_public_route_context('navy-assessment', 12);
$dedicatedNavyTheme = quiz_public_visual_theme($visualThemePdo);
quiz_set_public_route_context('assessment', 10);
$inheritedNavyTheme = quiz_public_visual_theme($visualThemePdo);
quiz_set_public_route_context('assessment', null);
$globalNavyTheme = quiz_public_visual_theme($visualThemePdo);
$check($dedicatedTheme === 'studio' && $inheritedTheme === 'default' && $inheritedStudioTheme === 'studio'
    && $invalidOverrideTheme === 'studio' && $globalTheme === 'studio' && $dedicatedNavyTheme === 'navy'
    && $inheritedNavyTheme === 'navy' && $globalNavyTheme === 'navy',
    'participant visual themes must use a bound Quiz override and otherwise inherit the allowlisted global theme');
unset($GLOBALS['quiz_public_route_context']);
final class AssignmentContractStatement extends PDOStatement {
    private array $rows = [];
    private mixed $row = false;
    public function __construct(private AssignmentContractPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->sql, 'FROM quiz_attempts a JOIN quizzes q')) {
            $this->row = ['quiz_id' => $this->pdo->quizId, 'questions_per_attempt' => $this->pdo->limit];
        } elseif (str_contains($this->sql, 'FROM question_categories')) {
            $this->rows = $this->pdo->categories;
        } elseif (str_contains($this->sql, 'LEFT JOIN questions q ON q.id=aq.question_id')) {
            $this->rows = $this->pdo->assignments;
        } elseif (str_contains($this->sql, 'COUNT(*) AS assignment_count')) {
            $this->row = ['assignment_count' => count($this->pdo->assignments),
                'incomplete_count' => $this->pdo->incompleteSnapshots];
        } else {
            throw new RuntimeException('Unexpected assignment contract query.');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0): mixed { return $this->row; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class AssignmentContractPDO extends PDO {
    public int $quizId = 9;
    public int $limit = 4;
    public int $incompleteSnapshots = 0;
    public array $categories = [];
    public array $assignments = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new AssignmentContractStatement($this, $query);
    }
}
$assignmentRow = static function (int $seq, int $questionId, int $questionCategoryId = 0,
    int $passageId = 0, int $passageCategoryId = 0, ?string $passageText = null,
    mixed $passageMinimum = null, mixed $passageMaximum = null): array {
    return [
        'id' => $questionId + 1000,
        'seq' => $seq,
        'question_id' => $questionId,
        'question_quiz_id' => 9,
        'question_category_id' => $questionCategoryId ?: null,
        'question_passage_id' => $passageId ?: null,
        'passage_id' => $passageId ?: null,
        'passage_quiz_id' => $passageId > 0 ? 9 : null,
        'passage_category_id' => $passageCategoryId ?: null,
        'passage_text' => $passageText,
        'passage_min_questions' => $passageMinimum,
        'passage_max_questions' => $passageMaximum,
    ];
};
$assignmentPdo = new AssignmentContractPDO();
$assignmentPdo->categories = [
    ['id' => 10, 'min_questions' => 2, 'max_questions' => 2],
    ['id' => 20, 'min_questions' => 2, 'max_questions' => 2],
];
$validAssignments = [
    $assignmentRow(1, 101, 20, 51, 10, 'Passage one', 2, 2),
    $assignmentRow(2, 102, 20, 51, 10, 'Passage one', 2, 2),
    $assignmentRow(3, 103, 20),
    $assignmentRow(4, 104, 20),
];
$assignmentPdo->assignments = $validAssignments;
$validQuotaPackage = quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = array_slice($validAssignments, 0, 3);
$badCountRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [...$validAssignments, $assignmentRow(5, 105, 20)];
$excessCountRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = $validAssignments;
$assignmentPdo->assignments[1]['seq'] = 1;
$badSequenceRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 10), $assignmentRow(2, 102, 10),
    $assignmentRow(3, 103, 10), $assignmentRow(4, 104, 20),
];
$badQuotaRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 10), $assignmentRow(2, 102, 20),
    $assignmentRow(3, 103, 10), $assignmentRow(4, 104, 20),
];
$badBlockOrderRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 10), $assignmentRow(2, 102, 10),
    $assignmentRow(3, 103, 30), $assignmentRow(4, 104, 20),
];
$unknownCategoryRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 20, 61, 10, '__CATEGORY_CONTAINER__'),
    $assignmentRow(2, 102, 10, 61, 0, '__CATEGORY_CONTAINER__'),
    $assignmentRow(3, 103, 20), $assignmentRow(4, 104, 20),
];
$containerCategoryFallbackAccepted = quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->categories = [
    ['id' => 10, 'min_questions' => 2, 'max_questions' => 2],
    ['id' => 20, 'min_questions' => 1, 'max_questions' => 1],
];
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 10), $assignmentRow(2, 102, 10),
    $assignmentRow(3, 103, 20), $assignmentRow(4, 104),
];
$uncategorizedFinalAllowed = quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 10), $assignmentRow(2, 102),
    $assignmentRow(3, 103, 10), $assignmentRow(4, 104, 20),
];
$uncategorizedMiddleRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->categories = [];
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(2, 102, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(3, 103), $assignmentRow(4, 104),
];
$exactPassageBoundsAccepted = quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(2, 102), $assignmentRow(3, 103), $assignmentRow(4, 104),
];
$underfilledPassageRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(2, 102, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(3, 103, 0, 51, 0, 'Passage one', 2, 2), $assignmentRow(4, 104),
];
$overfilledPassageRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(2, 102, 0, 51, 0, 'Passage one', 2, 3),
    $assignmentRow(3, 103), $assignmentRow(4, 104),
];
$inconsistentPassageBoundsRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 3, 2),
    $assignmentRow(2, 102, 0, 51, 0, 'Passage one', 3, 2),
    $assignmentRow(3, 103), $assignmentRow(4, 104),
];
$malformedPassageBoundsRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 61, 0, '__CATEGORY_CONTAINER__', 0, 0),
    $assignmentRow(2, 102, 0, 61, 0, '__CATEGORY_CONTAINER__', 0, 0),
    $assignmentRow(3, 103), $assignmentRow(4, 104),
];
$containerAndNonPassageAccepted = quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->assignments = [
    $assignmentRow(1, 101, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(2, 102),
    $assignmentRow(3, 103, 0, 51, 0, 'Passage one', 2, 2),
    $assignmentRow(4, 104),
];
$splitPassageRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$assignmentPdo->categories = [['id' => 10, 'min_questions' => 3, 'max_questions' => 2]];
$malformedBoundsRejected = !quiz_attempt_assignment_is_valid($assignmentPdo, 7);
$check($validQuotaPackage && $badCountRejected && $excessCountRejected && $badSequenceRejected && $badQuotaRejected
    && $badBlockOrderRejected && $unknownCategoryRejected && $containerCategoryFallbackAccepted
    && $uncategorizedFinalAllowed && $uncategorizedMiddleRejected && $exactPassageBoundsAccepted
    && $underfilledPassageRejected && $overfilledPassageRejected && $inconsistentPassageBoundsRejected
    && $malformedPassageBoundsRejected && $containerAndNonPassageAccepted
    && $splitPassageRejected && $malformedBoundsRejected,
    'assignment validation must enforce exact count/seq, category and real-passage quotas, block order, metadata consistency, and container exclusions');

final class PackageContractStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private PackageContractPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->pdo->queries[] = $this->sql;
        if (preg_match('/\b(?:quizzes|questions|question_categories|question_passages|question_answers)\b/', $this->sql)) {
            throw new RuntimeException('Package validation queried mutable configuration.');
        }
        if (str_contains($this->sql, 'FROM attempt_questions')) $this->rows = $this->pdo->assignments;
        elseif (str_contains($this->sql, 'FROM attempt_question_options')) $this->rows = $this->pdo->options;
        else throw new RuntimeException('Unexpected package contract query.');
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class PackageContractPDO extends PDO {
    public array $assignments = [];
    public array $options = [];
    public array $queries = [];
    public int $liveConfigurationVersion = 1;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new PackageContractStatement($this, $query);
    }
}
$packageDelivery = static function (string $key, string $name, int $minimum, ?int $maximum,
    ?array $passage = null, string $type = 'single_choice'): array {
    return [
        'version' => 1,
        'question' => ['text' => 'Immutable question', 'type' => $type, 'image_media_id' => null,
            'image_url' => '', 'file_id' => null, 'file_label' => '', 'file_url' => '', 'youtube_url' => ''],
        'passage' => $passage,
        'category' => ['id' => str_starts_with($key, 'category:') ? (int)substr($key, 9) : null,
            'key' => $key, 'name' => $name, 'min_questions' => $minimum, 'max_questions' => $maximum],
    ];
};
$packagePassage = ['id' => 51, 'title' => 'Immutable passage', 'text' => 'Passage text',
    'min_questions' => 2, 'max_questions' => 2, 'image_media_id' => null, 'image_url' => '',
    'file_id' => null, 'file_label' => '', 'file_url' => '', 'youtube_url' => ''];
$packageRow = static function (int $seq, int $questionId, array $delivery): array {
    return ['id' => 1000 + $questionId, 'attempt_id' => 7, 'question_id' => $questionId, 'seq' => $seq,
        'available_score_snapshot' => 1, 'category_key_snapshot' => $delivery['category']['key'],
        'category_id_snapshot' => $delivery['category']['id'], 'category_name_snapshot' => $delivery['category']['name'],
        'options_snapshotted_at' => '2026-10-08 10:00:00',
        'delivery_snapshot_json' => json_encode($delivery, JSON_THROW_ON_ERROR),
        'delivery_snapshotted_at' => '2026-10-08 10:00:00', 'snapshot_at' => '2026-10-08 10:00:00'];
};
$basePackageRows = [
    $packageRow(1, 101, $packageDelivery('category:10', 'Category A', 2, 2, $packagePassage)),
    $packageRow(2, 102, $packageDelivery('category:10', 'Category A', 2, 2, $packagePassage)),
    $packageRow(3, 103, $packageDelivery('category:20', 'Category B', 1, 1)),
    $packageRow(4, 104, $packageDelivery('uncategorized', 'Other', 0, null)),
];
$basePackageOptions = [];
foreach ($basePackageRows as $row) {
    $basePackageOptions[] = ['attempt_question_id' => $row['id'], 'attempt_id' => 7,
        'question_id' => $row['question_id'], 'source_answer_id' => 5000 + $row['question_id'],
        'answer_text' => 'Immutable option', 'score' => 1, 'delivery_seq' => 1];
}
$setPackageCategory = static function (array &$row, array $category): void {
    $delivery = json_decode($row['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    $delivery['category'] = $category;
    $row['category_key_snapshot'] = $category['key'];
    $row['category_id_snapshot'] = $category['id'];
    $row['category_name_snapshot'] = $category['name'];
    $row['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
};
$packagePdo = new PackageContractPDO();
$packagePdo->assignments = $basePackageRows;
$packagePdo->options = $basePackageOptions;
$packageInitiallyComplete = quiz_attempt_package_is_complete($packagePdo, 7);
$packagePdo->liveConfigurationVersion = 99;
$packageAfterLiveMutation = quiz_attempt_package_is_complete($packagePdo, 7);
$check($packageInitiallyComplete && $packageAfterLiveMutation && count($packagePdo->queries) === 4,
    'package completeness must remain valid after live configuration and bank mutations by querying snapshots only');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[1]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['passage'] = null;
$packagePdo->assignments[1]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject underfilled immutable real passages');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[2]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['passage'] = $packagePassage;
$packagePdo->assignments[2]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject overfilled immutable real passages');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[1]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['passage']['max_questions'] = 3;
$packagePdo->assignments[1]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject inconsistent immutable passage bounds');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[0]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['passage']['max_questions'] = 1;
$packagePdo->assignments[0]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject malformed immutable passage bounds');
$containerPassage = $packagePassage;
$containerPassage['text'] = '__CATEGORY_CONTAINER__';
$containerPassage['min_questions'] = 0;
$containerPassage['max_questions'] = 0;
$packagePdo->assignments = $basePackageRows;
foreach ([0, 1] as $index) {
    $delivery = json_decode($packagePdo->assignments[$index]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    $delivery['passage'] = $containerPassage;
    $packagePdo->assignments[$index]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
}
$check(quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must exclude container passages from immutable passage quotas');
$packagePdo->assignments = $basePackageRows;
foreach ([0, 1] as $index) {
    $delivery = json_decode($packagePdo->assignments[$index]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    $delivery['passage'] = null;
    $packagePdo->assignments[$index]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
}
$check(quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must keep non-passage immutable assignments valid');
$packageValidatorStart = (int)strpos($helperSource, 'function quiz_attempt_package_is_complete');
$packageValidatorEnd = (int)strpos($helperSource, 'function quiz_snapshot_attempt_questions', $packageValidatorStart);
$packageValidatorSource = substr($helperSource, $packageValidatorStart, $packageValidatorEnd - $packageValidatorStart);
$check(!str_contains($packageValidatorSource, 'quiz_attempt_assignment_is_valid')
    && !preg_match('/\b(?:quizzes|questions|question_categories|question_passages|question_answers)\b/', $packageValidatorSource),
    'package completeness source must not call live assignment validation or query mutable quiz-bank tables');

$packagePdo->assignments = [];
$packagePdo->options = [];
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject empty assignments');
$packagePdo->options = $basePackageOptions;
$packagePdo->assignments = $basePackageRows;
$packagePdo->assignments[1]['seq'] = 1;
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject duplicate or noncontiguous snapshot sequence numbers');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[0]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['version'] = 2;
$packagePdo->assignments[0]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject incomplete or unsupported delivery snapshots');
$packagePdo->assignments = $basePackageRows;
$delivery = json_decode($packagePdo->assignments[0]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['category']['min_questions'] = 3;
$packagePdo->assignments[0]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject malformed or inconsistent snapshotted category bounds');
$packagePdo->assignments = $basePackageRows;
$categoryA = ['id' => 10, 'key' => 'category:10', 'name' => 'Category A', 'min_questions' => 2, 'max_questions' => 2];
$categoryB = ['id' => 20, 'key' => 'category:20', 'name' => 'Category B', 'min_questions' => 1, 'max_questions' => 1];
$setPackageCategory($packagePdo->assignments[1], $categoryB);
$setPackageCategory($packagePdo->assignments[2], $categoryA);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject repeated category blocks in locally randomized packages');
$packagePdo->assignments = $basePackageRows;
$setPackageCategory($packagePdo->assignments[2],
    ['id' => null, 'key' => 'uncategorized', 'name' => 'Other', 'min_questions' => 0, 'max_questions' => null]);
$setPackageCategory($packagePdo->assignments[3], $categoryB);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must keep the uncategorized block final when represented');
$packagePdo->assignments = $basePackageRows;
$packagePdo->assignments[1]['delivery_snapshot_json'] = json_encode(
    $packageDelivery('category:10', 'Category A', 2, 2), JSON_THROW_ON_ERROR);
$delivery = json_decode($packagePdo->assignments[2]['delivery_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$delivery['passage'] = $packagePassage;
$packagePdo->assignments[2]['delivery_snapshot_json'] = json_encode($delivery, JSON_THROW_ON_ERROR);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject split immutable real-passage blocks');
$packagePdo->assignments = $basePackageRows;
$setPackageCategory($packagePdo->assignments[1],
    ['id' => 10, 'key' => 'category:10', 'name' => 'Changed name', 'min_questions' => 2, 'max_questions' => 2]);
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject inconsistent immutable metadata within a category block');
$packagePdo->assignments = $basePackageRows;
$packagePdo->options = $basePackageOptions;
$packagePdo->options[0]['delivery_seq'] = 2;
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject malformed option snapshot ordering');
$packagePdo->options = $basePackageOptions;
$packagePdo->options[0]['question_id'] = 999;
$check(!quiz_attempt_package_is_complete($packagePdo, 7), 'package completeness must reject option metadata inconsistent with its immutable assignment');

final class ManualVerifyContractStatement extends PDOStatement {
    private int $affected = 0;
    public function __construct(private ManualVerifyContractPDO $pdo, public string $sql) {}
    public function execute(?array $params = null): bool {
        $this->pdo->executedSql = $this->sql;
        if (in_array($this->pdo->status, ['registered', 'verified'], true)
            && $this->pdo->startedAt === null && $this->pdo->submittedAt === null) {
            $this->pdo->status = 'verified';
            $this->pdo->verified = true;
            $this->affected = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->affected; }
}
final class ManualVerifyContractPDO extends PDO {
    public ?string $startedAt = null;
    public ?string $submittedAt = null;
    public string $status = 'registered';
    public bool $verified = false;
    public string $executedSql = '';
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ManualVerifyContractStatement($this, $query);
    }
}
$manualVerifyPdo = new ManualVerifyContractPDO();
quiz_mark_attempt_manually_verified($manualVerifyPdo, 7, 'manual', 3, null);
$eligibleManualVerified = $manualVerifyPdo->verified && $manualVerifyPdo->startedAt === null;
$inProgressVerifyPdo = new ManualVerifyContractPDO();
$inProgressVerifyPdo->status = 'in_progress';
$inProgressVerifyPdo->startedAt = '2026-10-08 12:00:00';
$inProgressRejected = false;
try { quiz_mark_attempt_manually_verified($inProgressVerifyPdo, 7, 'manual', 3, null); }
catch (RuntimeException) { $inProgressRejected = !$inProgressVerifyPdo->verified && $inProgressVerifyPdo->status === 'in_progress'; }
$submittedVerifyPdo = new ManualVerifyContractPDO();
$submittedVerifyPdo->status = 'submitted';
$submittedVerifyPdo->submittedAt = '2026-10-08 13:00:00';
$submittedRejected = false;
try { quiz_mark_attempt_manually_verified($submittedVerifyPdo, 7, 'manual', 3, null); }
catch (RuntimeException) { $submittedRejected = !$submittedVerifyPdo->verified && $submittedVerifyPdo->status === 'submitted'; }
$check($eligibleManualVerified && $inProgressRejected && $submittedRejected
    && str_contains($manualVerifyPdo->executedSql, "status IN ('registered','verified')")
    && str_contains($manualVerifyPdo->executedSql, 'started_at IS NULL')
    && str_contains($manualVerifyPdo->executedSql, 'submitted_at IS NULL'),
    'manual verification must atomically reject in-progress or submitted attempts and never reopen them');
$manualVerifyActionSource = (string)file_get_contents($pluginRoot . '/admin/student/attempts/action_verify_manual.php');
$manualVerifyViewSource = (string)file_get_contents($pluginRoot . '/admin/student/attempts/view.php');
$check(str_contains($manualVerifyActionSource, 'quiz_mark_attempt_manually_verified(')
    && str_contains($manualVerifyViewSource, '$manualVerificationEligible')
    && str_contains($manualVerifyViewSource, 'if ($manualVerificationEligible)'),
    'manual verification action must retain the server guard and only render its form for eligible attempts');

final class TimerContractStatement extends PDOStatement {
    private mixed $row = false;
    public function __construct(private TimerContractPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->sql, 'FOR UPDATE')) {
            $this->row = ['started_at' => $this->pdo->startedAt, 'status' => $this->pdo->status,
                'email_verified_at' => $this->pdo->verifiedAt];
        } elseif (str_contains($this->sql, 'SET started_at')) {
            $this->pdo->starts++;
            $this->pdo->mutations++;
            $this->pdo->startedAt = '2026-10-08 12:00:00';
            if ($this->pdo->status === 'verified') $this->pdo->status = 'in_progress';
        } elseif (str_contains($this->sql, 'SET last_activity_at')) {
            $this->pdo->touches++;
            $this->pdo->mutations++;
        } elseif (str_contains($this->sql, 'UNIX_TIMESTAMP(NOW())')) {
            $this->row = ['now_ts' => 1000, 'end_ts' => $this->pdo->startedAt === null ? 0 : 2000,
                'started_at' => $this->pdo->startedAt];
        } else {
            throw new RuntimeException('Unexpected timer contract query.');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0): mixed {
        if ($mode === PDO::FETCH_NUM && is_array($this->row)) {
            return [1000, $this->row['started_at'] ?? null];
        }
        return $this->row;
    }
}
final class TimerContractPDO extends PDO {
    public ?string $startedAt = null;
    public string $status = 'verified';
    public ?string $verifiedAt = '2026-10-08 11:00:00';
    public int $starts = 0;
    public int $touches = 0;
    public int $mutations = 0;
    public int $rollbacks = 0;
    private bool $transaction = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new TimerContractStatement($this, $query);
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
}
$timerPdo = new TimerContractPDO();
$startedTimer = qz_timer_enforce($timerPdo, 7, 10, true, true);
$timerStartedOnce = $timerPdo->starts === 1 && $timerPdo->status === 'in_progress'
    && ($startedTimer['started_at'] ?? null) === '2026-10-08 12:00:00';
qz_timer_enforce($timerPdo, 7, 10, true, true);
$timerRetryWasIdempotent = $timerPdo->starts === 1 && $timerPdo->touches === 1;
$resetRacePdo = new TimerContractPDO();
$resetRacePdo->status = 'registered';
$resetRacePdo->verifiedAt = null;
$resetRaceRejected = false;
try { qz_timer_enforce($resetRacePdo, 7, 10, true, true); }
catch (RuntimeException) { $resetRaceRejected = $resetRacePdo->mutations === 0 && $resetRacePdo->rollbacks === 1; }
$timerlessResetPdo = new TimerContractPDO();
$timerlessResetPdo->verifiedAt = null;
$timerlessResetRejected = false;
try { qz_timer_enforce($timerlessResetPdo, 7, 0, true, true); }
catch (RuntimeException) { $timerlessResetRejected = $timerlessResetPdo->mutations === 0; }
$submittedTimerPdo = new TimerContractPDO();
$submittedTimerPdo->status = 'submitted';
$submittedTimerRejected = false;
try { qz_timer_enforce($submittedTimerPdo, 7, 10, true, true); }
catch (RuntimeException) { $submittedTimerRejected = $submittedTimerPdo->mutations === 0; }
$check($timerStartedOnce && $timerRetryWasIdempotent && $resetRaceRejected
    && $timerlessResetRejected && $submittedTimerRejected,
    'timer mutation must remain idempotent for verified attempts and reject reset, unverified, or submitted attempts under lock');
$check(str_contains($extensionSource, "return '1.1.0';"), 'Quiz extension API exposes the 1.1.0 version');
$check(str_contains($extensionSource, 'function quiz_extension_subscribe')
    && str_contains($extensionSource, 'function quiz_extension_emit')
    && str_contains($extensionSource, "['attempt.submitted', 'answer.saved', 'question.review_changed']"), 'extension API must preserve callables and add isolated lifecycle subscriptions');
$check(str_contains($pluginSource, "require_once \$extensionFile")
    && !str_contains($pluginSource, "do_action('quiz_loaded'")
    && !str_contains($pluginSource, "do_action_isolated('quiz_loaded'"), 'Quiz publishes a load-order-safe callable extension API');
$check(str_contains($pluginSource, "\$schemaRevision = '20261012-visual-theme'"), 'the visual-theme schema revision must retain the migration gate');
$check(str_contains($pluginSource, "'public_prefix', 'VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER title'")
    && str_contains($pluginSource, 'ADD UNIQUE KEY uq_quizzes_public_prefix (public_prefix)'), 'existing installs must add and uniquely index the optional public prefix');
$check(array_reduce($overrideColumns, static fn(bool $valid, string $column): bool => $valid && str_contains($pluginSource, "'{$column}', 'TINYINT(1) NULL"), true),
    'existing installs must add every nullable per-Quiz override');
$check(str_contains($pluginSource, "'visual_theme_override', 'VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL"),
    'existing installs must add the nullable per-Quiz visual theme override');
$check(str_contains($pluginSource, "'shuffle_answers', 'TINYINT(1) NOT NULL DEFAULT 1")
    && str_contains($pluginSource, "'result_visibility', \"ENUM('hidden','summary','categories') NOT NULL DEFAULT 'hidden'")
    && substr_count($pluginSource, 'CREATE TABLE IF NOT EXISTS attempt_answer_revisions') === 1
    && substr_count($pluginSource, 'CREATE TABLE IF NOT EXISTS attempt_category_scores') === 1
    && substr_count($pluginSource, 'CREATE TABLE IF NOT EXISTS attempt_question_options') === 1, 'MySQL upgrade source must be additive and idempotent');
$check(str_contains($pluginSource, "'question_categories' => 'idx_question_categories_owner'")
    && str_contains($pluginSource, "'question_passages' => 'idx_question_passages_owner'")
    && str_contains($pluginSource, "'questions' => 'idx_questions_owner'"), 'migration must cover every owned question-bank table');
$check(str_contains($pluginSource, 'ADD COLUMN created_by INT UNSIGNED NULL AFTER quiz_id')
    && str_contains($pluginSource, 'SET resource.created_by=q.created_by WHERE resource.created_by IS NULL OR resource.created_by=0')
    && str_contains($pluginSource, 'MODIFY created_by INT UNSIGNED NOT NULL')
    && str_contains($pluginSource, 'ADD KEY {$ownerIndex} (quiz_id, created_by)'), 'migration must add, backfill, require, and index resource owners');
$check(str_contains($pluginSource, 'function quiz_frontend_entrypoint'), 'frontend routing must use an explicit resolver');
$check(!str_contains($pluginSource, '$base . $path'), 'frontend routing must not append request paths to the public directory');
$check(!str_contains($pluginSource, 'require $apiFile'), 'frontend routing must not require request-derived API paths');

// Load only the resolver declarations in an isolated subprocess because Core's
// registry functions are already loaded in this process.
$resolverScript = <<<'PHP'
if (($argv[2] ?? '') === 'admin') define('ADMIN_BASE_PATH', '/dashboard');
$GLOBALS['contract_routes'] = [];
$GLOBALS['contract_actions'] = [];
function add_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['contract_actions'][$hook][$priority][] = $callback; }
function register_frontend_route(string $prefix, callable|string $handler, array $options = []): bool { $GLOBALS['contract_routes'][$prefix] = $options; return true; }
function plugin_manifest(string $name): ?array { return null; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
require $argv[1];
$registrationHook = defined('ADMIN_BASE_PATH') ? 'plugins_loaded' : 'init';
foreach ($GLOBALS['contract_actions'][$registrationHook] ?? [] as $callbacks) foreach ($callbacks as $callback) $callback();
$valid = ['', 'index.php', 'register', 'register/index.php', 'verify', 'verify/index.php', 'start', 'start/index.php',
    'start/confirm', 'start/confirm/index.php', 'take', 'take/index.php', 'result', 'result/index.php',
    'reset', 'reset/index.php', 'send_otp.php', 'api/get_question.php', 'api/proctor_event.php',
    'api/save_answer.php', 'api/set_review.php', 'api/submit.php'];
foreach ($valid as $route) {
    $file = quiz_frontend_entrypoint($route);
    if (!is_string($file) || !is_file($file)) exit(10);
}
foreach (['../plugin.php', '%2e%2e/plugin.php', 'api/_timer_helper.php', 'layout.php', '/etc/passwd', 'unknown'] as $route) {
    if (quiz_frontend_entrypoint($route) !== null) exit(11);
}
foreach ($GLOBALS['contract_routes'] as $path => $options) {
    if (($options['match'] ?? null) !== 'exact' || !str_starts_with($path, 'quiz')) exit(12);
}
if (isset($GLOBALS['contract_routes']['quiz/register-notfound'])
    || count($GLOBALS['contract_routes']) !== count(quiz_frontend_routes())) exit(13);
if (!quiz_register_public_prefix('assessment', 9)) exit(14);
if (!isset($GLOBALS['contract_routes']['assessment/register'], $GLOBALS['contract_routes']['assessment/api/submit.php'])) exit(15);
PHP;
$command = [PHP_BINARY, '-r', $resolverScript, $pluginRoot . '/plugin.php'];
$pipes = [];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$resolverStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $resolverStatus = proc_close($process);
}
$check($resolverStatus === 0, 'frontend resolver must accept only the explicit safe entrypoints');
$process = proc_open([PHP_BINARY, '-r', $resolverScript, $pluginRoot . '/plugin.php', 'admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$dashboardResolverStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $dashboardResolverStatus = proc_close($process);
}
$check($dashboardResolverStatus === 0, 'dashboard plugin loading must register the same exact Quiz routes for collision checks');
$check(str_contains($pluginSource, "defined('ADMIN_BASE_PATH') ? 'plugins_loaded' : 'init'")
    && str_contains($pluginSource, "add_action(\$quizRouteRegistrationHook, 'quiz_register_public_routes', PHP_INT_MAX)"),
    'Quiz routes must register after public init routes and remain visible to dashboard collision checks');

$coreRouteScript = <<<'PHP'
define('BACKEND_PATH', $argv[1] . '/cfg');
define('PLUGIN_PATH', dirname($argv[2]));
define('PUBLIC_PATH', $argv[1] . '/public');
require $argv[1] . '/cfg/helpers/hooks.php';
require $argv[1] . '/plugins/index.php';
require $argv[2] . '/plugin.php';
add_action('init', static function (): void {
    if (!quiz_register_public_prefix('training', 8)) exit(23);
}, 10);
plugin_run_frontend_init();
$known = resolve_frontend_route('quiz/register', 'GET');
if (!is_array($known) || ($known['route']['match'] ?? null) !== 'exact') exit(21);
if (resolve_frontend_route('quiz/register-notfound', 'GET') !== null) exit(22);
$custom = resolve_frontend_route('training/register', 'GET');
if (!is_array($custom) || ($custom['route']['match'] ?? null) !== 'exact') exit(24);
if (resolve_frontend_route('training/register-notfound', 'GET') !== null) exit(25);
PHP;
$process = proc_open([PHP_BINARY, '-r', $coreRouteScript, $coreRoot, $pluginRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$coreRouteStatus = -1;
$coreRouteOutput = '';
if (is_resource($process)) {
    $coreRouteOutput = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $coreRouteStatus = proc_close($process);
}
$check($coreRouteStatus === 0, 'known Quiz endpoints must resolve exactly while unknown default and custom-prefix paths fall through to Core'
    . ($coreRouteOutput !== '' ? ': ' . $coreRouteOutput : ' (status ' . $coreRouteStatus . ')'));

$prefixRouteScript = <<<'PHP'
define('BACKEND_PATH', $argv[1] . '/cfg');
define('PLUGIN_PATH', dirname($argv[2]));
define('PUBLIC_PATH', $argv[1] . '/public');
require $argv[1] . '/cfg/helpers/hooks.php';
require $argv[1] . '/cfg/helpers/content_route_helpers.php';
require $argv[1] . '/plugins/index.php';
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=:key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
$pdo->exec('CREATE TABLE quizzes (id INTEGER PRIMARY KEY, public_prefix TEXT)');
$pdo->exec('CREATE TABLE content_routes (id INTEGER PRIMARY KEY, post_id INTEGER, locale TEXT, path TEXT, is_canonical INTEGER)');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT, type TEXT, is_deleted INTEGER)');
$pdo->exec("INSERT INTO quizzes VALUES (8, 'training'), (9, 'certification'), (10, 'portal')");
$pdo->exec("INSERT INTO settings VALUES ('quiz_global_public_prefix', 'assessment')");
$pdo->exec("INSERT INTO content_routes VALUES (1, 1, '', 'training/register', 1)");
$GLOBALS['pdo'] = $pdo;
add_action('init', static function (): void {
    register_frontend_route('certification/foreign', static function (): void {}, ['match' => 'exact']);
    register_frontend_route('quiz/register', static function (): void {}, ['match' => 'exact']);
}, 10);
require $argv[2] . '/plugin.php';
plugin_run_frontend_init();
if (resolve_frontend_route('training/register', 'GET') !== null) exit(31);
if (resolve_frontend_route('certification/register', 'GET') !== null) exit(32);
if (resolve_frontend_route('assessment/register', 'GET') === null
    || resolve_frontend_route('assessment/register-notfound', 'GET') !== null
    || resolve_frontend_route('quiz/register', 'GET') === null) exit(36);
if (quiz_validate_global_public_prefix($pdo, 'assessment', 'assessment') !== 'assessment'
    || quiz_validate_public_prefix($pdo, 'portal', 10, 'portal') !== 'portal') exit(37);
$quizConflictRejected = false;
try { quiz_validate_global_public_prefix($pdo, 'quiz', 'assessment'); }
catch (InvalidArgumentException) { $quizConflictRejected = true; }
if (!$quizConflictRejected) exit(38);
if (resolve_frontend_route('portal/register', 'GET') === null) {
    fwrite(STDERR, (string)quiz_public_prefix_conflict($pdo, 'portal', 10));
    exit(33);
}
if (quiz_public_url_for_quiz($pdo, 8, 'register') !== '/assessment/register/'
    || quiz_global_public_url($pdo, 'start') !== '/assessment/start/') exit(34);
if (quiz_public_url_for_quiz($pdo, 10, 'register') !== '/portal/register/') exit(35);
PHP;
$process = proc_open([PHP_BINARY, '-r', $prefixRouteScript, $coreRoot, $pluginRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$prefixRouteStatus = -1;
$prefixRouteOutput = '';
if (is_resource($process)) {
    $prefixRouteOutput = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $prefixRouteStatus = proc_close($process);
}
$check($prefixRouteStatus === 0, 'dedicated Quiz routes must yield to existing content/plugin routes and unavailable prefixes must generate compatibility URLs'
    . ($prefixRouteOutput !== '' ? ': ' . $prefixRouteOutput : ' (status ' . $prefixRouteStatus . ')'));

$publicIndex = (string)file_get_contents($pluginRoot . '/public/index.php');
$check(str_contains($publicIndex, "quiz_public_url('start')"), 'Quiz root must redirect within the active public prefix');
$check(!str_contains($pluginSource, 'Quiz page not found')
    && !str_contains($pluginSource, "register_frontend_route('quiz', function"), 'unknown Quiz paths must fall through to the Core content and 404 resolver');
$check(str_contains($pluginSource, "quiz_register_public_prefix('quiz')")
    && str_contains($pluginSource, 'quiz_stored_global_public_prefix($routePdo)')
    && str_contains($pluginSource, 'quiz_public_registered_global_prefix'),
    'route bootstrap must preserve /quiz compatibility and register the configured global Quiz prefix');

$assetScript = <<<'PHP'
$GLOBALS['contract_hooks'] = [];
function add_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['contract_hooks'][$hook][] = $callback; }
function register_frontend_route(string $prefix, callable|string $handler, array $options = []): bool { return true; }
function plugin_manifest(string $name): ?array { return ['version' => '1.8.3']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
require $argv[1];
foreach ($GLOBALS['contract_hooks']['init'] ?? [] as $callback) $callback();
if (quiz_normalize_test_number_format('ORG-{year}-{attempt_id:8}') !== 'ORG-{year}-{attempt_id:8}') exit(36);
if (quiz_normalize_test_number_format('ORG-{year}') !== null) exit(37);
if (quiz_format_test_number('ORG-{year}-{attempt_id:8}', 123, 2026) !== 'ORG-2026-00000123') exit(38);
if (quiz_format_test_number('LEGACY-{id:6}', 123, 2026) !== 'LEGACY-000123') exit(39);
if (quiz_format_test_number('ORG-{quiz_id:3}-{attempt_id:6}', 123, 2026, 8) !== 'ORG-008-000123') exit(40);
$render = static function (string $hook): string {
    ob_start();
    foreach ($GLOBALS['contract_hooks'][$hook] ?? [] as $callback) $callback();
    return (string)ob_get_clean();
};
$_GET = ['page' => 'home'];
$_SERVER['REQUEST_URI'] = '/';
if ($render('admin_head') !== '' || $render('admin_footer') !== '' || $render('jy_head') !== '') exit(30);
$_GET = ['page' => 'admin/quiz/attempts'];
$head = $render('admin_head');
$footer = $render('admin_footer');
$root = dirname($argv[1]);
$cssVersion = substr((string)hash_file('sha256', $root . '/assets/admin.css'), 0, 12);
$jsVersion = substr((string)hash_file('sha256', $root . '/assets/admin.js'), 0, 12);
if (!str_contains($head, '/static/plugins/quiz/admin.css?v=' . $cssVersion) || str_contains($head, 'quiz.css')) exit(31);
if (!str_contains($head, '/static/plugins/quiz/admin.js?v=' . $jsVersion) || !str_contains($head, ' defer')) exit(32);
if ($footer !== '') exit(34);
$_SERVER['REQUEST_URI'] = '/quiz/register/';
if (!str_contains($render('jy_head'), 'Quiz - Public Frontend Styles')) exit(33);
$_GET = ['page' => 'admin/quizzer'];
$_SERVER['REQUEST_URI'] = '/quizzer/';
if ($render('admin_head') !== '' || $render('jy_head') !== '') exit(35);
$_SERVER['REQUEST_URI'] = '/quiz/register-notfound/';
if ($render('jy_head') !== '') exit(41);
PHP;
$process = proc_open([PHP_BINARY, '-r', $assetScript, $pluginRoot . '/plugin.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$assetStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $assetStatus = proc_close($process);
}
$check($assetStatus === 0, 'plugin assets must load only on their matching public or admin requests');

$migrationScript = <<<'PHP'
$GLOBALS['contract_settings'] = [];
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function register_frontend_route(string $prefix, callable|string $handler, array $options = []): bool { return true; }
function plugin_manifest(string $name): ?array { return ['version' => '1.8.3']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $GLOBALS['contract_settings'][$key] ?? $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { $GLOBALS['contract_settings'][$key] = (string)$value; return true; }
final class ContractPDO extends PDO {
    public bool $fail = true;
    public int $executions = 0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'contract'; }
    public function exec(string $statement): int|false {
        $this->executions++;
        if ($this->fail) throw new PDOException('expected contract failure');
        return 0;
    }
}
$pdo = new ContractPDO();
require $argv[1];
quiz_install_schema($pdo);
if ($GLOBALS['contract_settings'] !== [] || $pdo->executions !== 1) exit(21);
$pdo->fail = false;
quiz_install_schema($pdo);
if (($GLOBALS['contract_settings']['quiz_test_version'] ?? '') !== '1.8.3'
    || ($GLOBALS['contract_settings']['quiz_schema_revision'] ?? '') !== '20261012-visual-theme'
    || $pdo->executions !== 2) exit(22);
quiz_install_schema($pdo);
if ($pdo->executions !== 2) exit(23);
unset($GLOBALS['contract_settings']['quiz_schema_revision']);
quiz_install_schema($pdo);
if (($GLOBALS['contract_settings']['quiz_schema_revision'] ?? '') !== '20261012-visual-theme'
    || $pdo->executions !== 2) exit(24);
PHP;
$process = proc_open([PHP_BINARY, '-r', $migrationScript, $pluginRoot . '/plugin.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$migrationStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $migrationStatus = proc_close($process);
}
$check($migrationStatus === 0, 'schema migration must record only success and retry failures on Quiz requests');

$backfillScript = <<<'PHP'
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function register_frontend_route(string $prefix, callable|string $handler, array $options = []): bool { return true; }
function plugin_manifest(string $name): ?array { return ['version' => '1.8.3']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
final class BackfillStatement extends PDOStatement {
    private array $rows = [];
    private mixed $column = false;
    public function __construct(private BackfillPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        if (str_contains($this->sql, 'SELECT DISTINCT aq.attempt_id, a.status')) {
            $after = (int)($params[':after_attempt_id'] ?? 0);
            $ids = array_keys($this->pdo->incomplete);
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                if ($id > $after && $this->pdo->incomplete[$id] === 'submitted') {
                    $this->rows[] = ['attempt_id' => $id, 'status' => $this->pdo->incomplete[$id]];
                }
            }
            return true;
        }
        if (str_contains($this->sql, 'WHERE aq.attempt_id=:attempt_id')) {
            $id = (int)($params[':attempt_id'] ?? 0);
            $this->pdo->currentAttempt = $id;
            $this->column = (int)($this->pdo->verificationFailures[$id] ?? 0);
            return true;
        }
        throw new RuntimeException('Unexpected fake statement.');
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
}
final class BackfillPDO extends PDO {
    public array $incomplete = [4 => 'submitted', 8 => 'in_progress', 12 => 'submitted'];
    public array $verificationFailures = [12 => 1];
    public array $snapshotted = [];
    public ?int $currentAttempt = null;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $transaction = false;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new BackfillStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new class($this) extends PDOStatement {
            public function __construct(private BackfillPDO $pdo) {}
            public function fetchColumn(int $column = 0): mixed {
                return count(array_filter($this->pdo->incomplete, static fn(string $status): bool => $status === 'submitted'));
            }
        };
    }
    public function beginTransaction(): bool { $this->begins++; $this->transaction = true; $this->currentAttempt = null; return true; }
    public function commit(): bool {
        $this->commits++;
        if ($this->currentAttempt !== null) unset($this->incomplete[$this->currentAttempt]);
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool { $this->rollbacks++; $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
}
$pdo = new BackfillPDO();
require $argv[1];
$snapshot = static function (PDO $connection, int $attemptId) use ($pdo): void { $pdo->snapshotted[] = $attemptId; };
$failed = false;
try {
    quiz_backfill_attempt_question_snapshots($pdo, $snapshot, 10);
} catch (RuntimeException $error) {
    $failed = $error->getMessage() === 'Legacy attempt snapshots could not be completed.';
}
if (!$failed || $pdo->snapshotted !== [4, 12] || $pdo->begins !== 2 || $pdo->commits !== 1
    || $pdo->rollbacks !== 1 || $pdo->incomplete !== [8 => 'in_progress', 12 => 'submitted']) exit(61);
$pdo->verificationFailures[12] = 0;
quiz_backfill_attempt_question_snapshots($pdo, $snapshot, 10);
if ($pdo->snapshotted !== [4, 12, 12] || $pdo->begins !== 3 || $pdo->commits !== 2
    || $pdo->rollbacks !== 1 || $pdo->incomplete !== [8 => 'in_progress']) exit(62);
PHP;
$process = proc_open([PHP_BINARY, '-r', $backfillScript, $pluginRoot . '/plugin.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$backfillStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $backfillStatus = proc_close($process);
}
$check($backfillStatus === 0, 'snapshot migration must process submitted attempts only and retry only uncommitted submitted work after failure');

$migrationGateScript = <<<'PHP'
$GLOBALS['contract_settings'] = [];
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function register_frontend_route(string $prefix, callable|string $handler, array $options = []): bool { return true; }
function plugin_manifest(string $name): ?array { return ['version' => '1.8.3']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $GLOBALS['contract_settings'][$key] ?? $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { $GLOBALS['contract_settings'][$key] = (string)$value; return true; }
final class MigrationGateStatement extends PDOStatement {
    public function __construct(private MigrationGatePDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function fetchColumn(int $column = 0): mixed {
        if (str_contains($this->sql, 'information_schema.')) return 1;
        if (str_contains($this->sql, 'COUNT(*) FROM attempt_questions aq')) return $this->pdo->globalIncomplete;
        return false;
    }
}
final class MigrationGatePDO extends PDO {
    public int $globalIncomplete = 1;
    public int $executions = 0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function exec(string $statement): int|false { $this->executions++; return 0; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MigrationGateStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new MigrationGateStatement($this, $query); }
}
$pdo = new MigrationGatePDO();
require $argv[1];
if (quiz_install_schema($pdo) !== false || $GLOBALS['contract_settings'] !== [] || $pdo->executions === 0) exit(71);
$ddlExecutions = $pdo->executions;
$pdo->globalIncomplete = 0;
if (quiz_install_schema($pdo) !== true
    || ($GLOBALS['contract_settings']['quiz_test_version'] ?? '') !== '1.8.3'
    || ($GLOBALS['contract_settings']['quiz_schema_revision'] ?? '') !== '20261012-visual-theme'
    || $pdo->executions <= $ddlExecutions) exit(72);
PHP;
$process = proc_open([PHP_BINARY, '-r', $migrationGateScript, $pluginRoot . '/plugin.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$migrationGateStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $migrationGateStatus = proc_close($process);
}
$check($migrationGateStatus === 0, 'incomplete global verification must leave version and revision unadvanced across implicit DDL commits, then succeed on retry');

$backfillStart = (int)strpos($pluginSource, 'function quiz_backfill_attempt_question_snapshots');
$installStart = (int)strpos($pluginSource, 'function quiz_install_schema');
$backfillSource = substr($pluginSource, $backfillStart, $installStart - $backfillStart);
$installSource = substr($pluginSource, $installStart, (int)strpos($pluginSource, 'function quiz_schema_is_ready') - $installStart);
$backfillCall = strpos($installSource, 'quiz_backfill_attempt_question_snapshots($pdo)');
$revisionWrite = strpos($installSource, "settings_set(\$pdo, 'quiz_schema_revision'");
$check(str_contains($backfillSource, 'SELECT DISTINCT aq.attempt_id, a.status')
    && substr_count($backfillSource, "a.status='submitted'") === 2
    && str_contains($backfillSource, 'ORDER BY aq.attempt_id ASC')
    && str_contains($backfillSource, '$pdo->beginTransaction()')
    && str_contains($backfillSource, '$pdo->commit()')
    && str_contains($backfillSource, '$pdo->rollBack()'), 'snapshot backfill must process only submitted attempts deterministically in one transaction per attempt');
$check($backfillCall !== false && $revisionWrite !== false && $backfillCall < $revisionWrite
    && str_contains($backfillSource, 'SELECT COUNT(*) FROM attempt_questions aq')
    && str_contains($backfillSource, 'JOIN quiz_attempts a ON a.id=aq.attempt_id')
    && str_contains($backfillSource, 'Legacy attempt snapshot verification failed.'), 'schema revision persistence must follow a global incomplete-assignment verification');
$check(str_contains($backfillSource, "__DIR__ . '/includes/helpers.php'")
    && str_contains($backfillSource, "function_exists('quiz_snapshot_attempt_questions')"), 'schema lifecycle must make the snapshot helper available before backfill');
$check(!preg_match('/\b(?:UPDATE|DELETE FROM|INSERT INTO)\s+(?:quiz_attempts|attempt_answers|questions|quizzes|students)\b/i', $backfillSource), 'snapshot migration orchestration must not mutate attempts, answers, bank content, Quiz settings, or participant identity');
$check(str_contains((string)file_get_contents($pluginRoot . '/admin/_init.php'), "exit('Quiz upgrade could not be completed. Please retry.')"), 'admin migration failures must return a bounded generic error');

$check(!str_contains($pluginSource, "add_action('init', 'quiz_install_schema'"), 'schema migration must not run on every frontend request');
$check(!str_contains($pluginSource, "add_action('admin_init'"), 'schema migration must not run before dashboard authentication');
$check(str_contains($pluginSource, 'quiz_schema_is_ready($pdo)'), 'Quiz frontend dispatch must fail closed until an authorized dashboard request installs schema');
$check(!str_contains($pluginSource, "register_frontend_route('quiz', function (\\PDO \$pdo): void {\n    quiz_install_schema"), 'public requests must not execute schema DDL');
$check(str_contains($pluginSource, "add_action('admin_head'"), 'admin assets must load through the request-scoped Core hook');
$check(!str_contains($pluginSource, "add_action('admin_footer'"), 'admin assets must avoid the Core footer hook that runs twice');
$check(str_contains($pluginSource, 'quiz_is_admin_request()'), 'admin assets must be restricted to Quiz routes');
$check(str_contains($pluginSource, 'quiz_is_public_request()'), 'public CSS must be restricted to Quiz routes');
$check(str_contains($pluginSource, 'function quiz_default_sender_email()')
    && str_contains($pluginSource, "return 'no-reply@' . \$host"), 'default Quiz sender address must derive from the active host');

$settingsSource = (string)file_get_contents($pluginRoot . '/admin/settings/index.php');
$check(str_contains($settingsSource, "settings_set(\$pdo, 'quiz_otp_email_enabled'") && str_contains($settingsSource, "settings_set(\$pdo, 'quiz_otp_whatsapp_enabled'"), 'Quiz settings must persist both OTP method toggles');
$check(str_contains($settingsSource, "settings_set(\$pdo, 'quiz_test_number_format'")
    && str_contains($settingsSource, 'name="quiz_test_number_format"')
    && str_contains($settingsSource, '{quiz_id:3}')
    && str_contains($settingsSource, 'Attempt ID, required')
    && str_contains($settingsSource, 'quiz_normalize_test_number_format($test_number_format)'), 'Quiz settings must explain, validate, and persist the test-number format');
$check(str_contains($settingsSource, "settings_set(\$pdo, 'quiz_global_public_prefix'")
    && str_contains($settingsSource, 'quiz_validate_global_public_prefix($pdo, $global_public_prefix, $stored_global_public_prefix)')
    && str_contains($settingsSource, 'name="quiz_global_public_prefix"')
    && str_contains($settingsSource, 'The legacy <code>/quiz/</code> route remains available for compatibility.'),
    'Quiz settings must validate, persist, and explain the configurable global public URL');
$check(str_contains($settingsSource, "settings_set(\$pdo, 'quiz_visual_theme'")
    && str_contains($settingsSource, 'quiz_normalize_visual_theme(')
    && str_contains($settingsSource, 'name="quiz_visual_theme"')
    && str_contains($settingsSource, '>Studio</option>')
    && str_contains($settingsSource, '>Navy</option>'),
    'Quiz settings must validate and persist the global participant visual theme');
$check(str_contains($settingsSource, "settings_set(\$pdo, 'quiz_global_title'")
    && str_contains($settingsSource, 'quiz_normalize_public_title($global_title)')
    && str_contains($settingsSource, 'name="quiz_global_title"')
    && str_contains($settingsSource, 'data-qz-settings-summary="global-title"'),
    'Quiz settings must validate, persist, explain, and summarize the participant Global Title');
$check(str_contains($settingsSource, 'quiz_public_prefix_lock($pdo)')
    && str_contains($settingsSource, 'quiz_public_prefix_unlock($pdo)')
    && str_contains($settingsSource, 'if (!$pdo->beginTransaction())')
    && str_contains($settingsSource, 'if (!$pdo->commit())')
    && str_contains($settingsSource, "unset(\$GLOBALS['__jy_settings_autoload_cache'])")
    && str_contains($settingsSource, 'configured slug is not currently active'),
    'global prefix settings must save atomically, serialize writes, and expose unavailable stored routes');
$check(str_contains($helperSource, 'function quiz_database_setting(')
    && str_contains($helperSource, "quiz_database_setting(\$pdo, 'quiz_global_public_prefix', 'quiz')")
    && !str_contains($helperSource, "if (\$prefix === 'quiz') return \$prefix;"),
    'prefix revalidation must read the global namespace uncached and validate the compatibility slug');
$check(str_contains($helperSource, 'function quiz_normalize_public_title(')
    && str_contains($helperSource, 'function quiz_public_title(PDO $pdo)')
    && str_contains($helperSource, "quiz_database_setting(\$pdo, 'quiz_global_title', 'Quiz')"),
    'public terminology must use one validated database-backed Global Title resolver');
$quizAddSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/add.php');
$quizEditSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/edit.php');
$quizDuplicateSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/duplicate.php');
$check(str_contains($quizEditSource, 'name="<?= h($field) ?>"')
    && array_reduce($overrideColumns, static fn(bool $valid, string $column): bool => $valid
        && str_contains($quizEditSource, $column . ' = :' . $column)
        && str_contains($quizDuplicateSource, ':' . $column), true),
    'Quiz edit and duplication must preserve every verification and proctor override');
$check(str_contains($quizEditSource, 'name="visual_theme_override"')
    && str_contains($quizEditSource, 'visual_theme_override = :visual_theme_override')
    && str_contains($quizEditSource, 'value="navy"')
    && str_contains($quizDuplicateSource, "':visual_theme_override' => quiz_safe_visual_theme_override"),
    'Quiz edit and duplication must preserve a validated nullable visual theme override');
$check(str_contains($quizAddSource, 'quiz_public_prefix_lock($pdo)')
    && str_contains($quizEditSource, 'quiz_public_prefix_lock($pdo)')
    && substr_count($quizAddSource, 'quiz_validate_public_prefix($pdo, $public_prefix)') >= 2
    && substr_count($quizEditSource, 'quiz_validate_public_prefix(') >= 2,
    'per-Quiz prefix writes must revalidate while holding the shared namespace lock');
$adminDashboardSource = (string)file_get_contents($pluginRoot . '/admin/index.php');
$check(str_contains($adminDashboardSource, "quiz_global_public_url(\$pdo, 'start')")
    && str_contains($adminDashboardSource, "quiz_global_public_url(\$pdo, 'register')")
    && !str_contains($adminDashboardSource, 'href="/quiz/start/"'),
    'Quiz dashboard public actions must use the configured global prefix');
$check(str_contains($settingsSource, "\$otp_email_enabled && \$from_name === ''") && str_contains($settingsSource, "\$otp_wa_enabled && \$admin_wa === ''"), 'OTP configuration fields must be required only for enabled methods');
$check(str_contains($settingsSource, 'qz-method-grid') && !str_contains($settingsSource, '<span class="qz-check-meta">Peserta dapat meminta OTP'), 'OTP method descriptions must use their wrapping card layout');
$check(str_contains($settingsSource, 'class="qz-settings-form"'), 'Quiz settings must expose a component-specific scope for delegated controls');

$verifySource = (string)file_get_contents($pluginRoot . '/public/verify/index.php');
$publicSecuritySource = (string)file_get_contents($pluginRoot . '/public/_security.php');
$check(str_contains($verifySource, "settings_get(\$pdo, 'quiz_otp_email_enabled', '1')") && str_contains($verifySource, "settings_get(\$pdo, 'quiz_otp_whatsapp_enabled', '0')"), 'OTP verification UI must default email on and unconfigured WhatsApp off');
$check(str_contains($verifySource, 'Request OTP from Admin') && str_contains($verifySource, 'available.includes(saved)'), 'OTP verification UI must support manual-only mode and select only available methods');

$sendOtpSource = (string)file_get_contents($pluginRoot . '/public/send_otp.php');
$methodGuardPosition = strpos($sendOtpSource, 'if (!$methodEnabled)');
$otpMutationPosition = strpos($sendOtpSource, '// pastikan OTP ada (sekali)');
$check($methodGuardPosition !== false && $otpMutationPosition !== false && $methodGuardPosition < $otpMutationPosition, 'disabled OTP methods must be rejected before OTP mutation');
$takeViewSource = (string)file_get_contents($pluginRoot . '/public/take/_take_view.php');
$contactSources = $settingsSource . $verifySource . $sendOtpSource . $takeViewSource;
$check(str_contains($takeViewSource, "settings_get(\$pdo, 'quiz_admin_wa', '')")
    && preg_match('/(?:08|62)\d{9,13}/', $contactSources) !== 1
    && preg_match('/@[a-z0-9.-]+\.(?:lan|local|test)\b/i', $contactSources) !== 1,
    'Quiz contact defaults must be deployment-neutral and contain no hardcoded operator contact');

$takeJs = (string)file_get_contents($pluginRoot . '/public/take/_take_js.php');
$check(str_contains($takeJs, 'JSON_HEX_TAG'), 'inline take-page JSON must hex-escape HTML tags');
$check(!preg_match('/nav(?:Sections|CatMap)Json\s*=\s*json_encode\([^\n]+JSON_UNESCAPED_SLASHES/', $takeJs), 'navigation JSON must not allow script-closing tags');
$takeView = (string)file_get_contents($pluginRoot . '/public/take/_take_view.php');
$takeContext = (string)file_get_contents($pluginRoot . '/public/take/_take_ctx.php');
$submittedViewSource = (string)file_get_contents($pluginRoot . '/public/take/_submitted_view.php');
$timerHelper = (string)file_get_contents($pluginRoot . '/public/api/_timer_helper.php');
$saveAnswerSource = (string)file_get_contents($pluginRoot . '/public/api/save_answer.php');
$submitSource = (string)file_get_contents($pluginRoot . '/public/api/submit.php');
$reviewSource = (string)file_get_contents($pluginRoot . '/public/api/set_review.php');
$resultSource = (string)file_get_contents($pluginRoot . '/public/result/index.php');
$publicLayoutSource = (string)file_get_contents($pluginRoot . '/public/layout.php');
$publicStyleSource = (string)file_get_contents($pluginRoot . '/public/style.css');
$registerSource = (string)file_get_contents($pluginRoot . '/public/register/index.php');
$resetSource = (string)file_get_contents($pluginRoot . '/public/reset/index.php');
$attemptViewSource = (string)file_get_contents($pluginRoot . '/admin/student/attempts/view.php');
$readmeSource = (string)file_get_contents($pluginRoot . '/README.md');
$participantTerminologyPaths = [
    '/public/layout.php', '/public/_security.php', '/public/register/index.php', '/public/reset/index.php',
    '/public/start/index.php', '/public/start/confirm/index.php', '/public/verify/index.php', '/public/send_otp.php',
    '/public/take/index.php', '/public/take/_take_ctx.php', '/public/take/_take_view.php',
    '/public/take/_take_js.php', '/public/take/_submitted_view.php', '/public/result/index.php',
    '/public/api/get_question.php', '/public/api/save_answer.php', '/public/api/set_review.php', '/public/api/submit.php',
];
$participantTerminologySource = '';
foreach ($participantTerminologyPaths as $path) $participantTerminologySource .= (string)file_get_contents($pluginRoot . $path);
$check(str_contains($publicLayoutSource, '<html lang="en">')
    && str_contains($registerSource, '$publicTitle = quiz_public_title($pdo);')
    && str_contains($takeJs, '$publicTitleJson = json_encode(quiz_public_title($pdo)')
    && str_contains($sendOtpSource, '$subject = $publicTitle . \' OTP Verification\';')
    && str_contains($submittedViewSource, '$publicTitle = quiz_public_title($pdo);')
    && str_contains($resultSource, '$publicTitle = quiz_public_title($pdo);'),
    'the complete participant journey must declare English content and resolve generic terminology from Global Title');
$check(str_contains($resetSource, "if (!function_exists('h'))")
    && str_contains($resetSource, '$page_title = \'Reset \' . $publicTitle . \' Session\';'),
    'participant session reset must coexist with the Core escape helper and use the configured Global Title');
$forbiddenParticipantCopy = [
    'Quiz tidak valid.', 'Kode OTP harus 6 digit.', 'Kolom quiz_attempts belum lengkap', 'Quiz sudah disubmit',
    'Kamu tidak bisa', 'Tidak bisa mulai', 'Email ini sudah terdaftar', 'Nomor WhatsApp ini sudah terdaftar',
    'Waktu ujian sudah habis.', 'Belum semua soal dijawab', 'Konteks quiz tidak valid.', 'Terjadi kendala',
    'Paket soal tidak valid', 'Memuat soal', 'Gagal memuat soal.', 'Gagal memulai ujian.',
    'Tindakan ini menghapus', 'Gunakan reset', 'Membersihkan data Quiz', 'Cookies dihapus:',
    'Setelah ini halaman', 'Kembali ke Register', '>Select Quiz<', '>Quiz List<', '>Take Quiz<',
    'Quiz OTP Verification', 'Reset Quiz Session', 'Quiz Result', 'Quiz Submitted', 'Quiz complete',
];
$check(array_reduce($forbiddenParticipantCopy, static fn(bool $valid, string $copy): bool =>
    $valid && !str_contains($participantTerminologySource, $copy), true),
    'participant-rendered copy must not regress to hardcoded Quiz terminology or Indonesian UI text');
$check(str_contains($takeContext, '$needsPreparation = !$packageComplete || empty($attempt[\'started_at\'])')
    && str_contains($takeContext, '$needsPreparation && !$isBeginRequest')
    && str_contains($takeContext, 'qz_public_csrf_check(qz_public_csrf_from_request())')
    && str_contains($takeContext, '$isBeginRequest, $isBeginRequest')
    && str_contains($timerHelper, 'if (!$startIfMissing && !$finalizeIfExpired)'), 'take-page GET is read-only and preparation requires CSRF-protected POST');
$check(substr_count($takeJs, 'input.className = "qt-choice-input";') === 2, 'single and multiple choice controls must share aligned input styling');
$check(str_contains($takeView, '.qt-answers .qt-choice-input{') && str_contains($takeView, 'margin:5px 0 0;'), 'choice controls must align vertically with the 28px answer badge');
$check(str_contains($takeContext, 'visual_theme_override')
    && str_contains($takeContext, "settings_get(\$pdo, 'quiz_visual_theme', 'default')")
    && str_contains($takeView, 'qt-theme-studio')
    && str_contains($takeView, '.qt-theme-studio .qt-answers label:has(.qt-choice-input:checked)')
    && str_contains($takeView, '.qt-theme-navy .qt-answers label:has(.qt-choice-input:checked)')
    && str_contains($takeJs, 'document.body.classList.add("qz-theme-" + VISUAL_THEME)')
    && str_contains($takeJs, 'body.qz-theme-studio #qzProctorCard')
    && str_contains($takeJs, 'body.qz-theme-navy #qzProctorCard'),
    'the resolved global or per-Quiz theme must color the take page, answer states, and proctor surfaces');
$themeResolutionPosition = strpos($takeContext, "\$quiz['visual_theme'] =");
$submittedGuardPosition = strpos($takeContext, "if (\$status === 'submitted')");
$check($themeResolutionPosition !== false && $submittedGuardPosition !== false && $themeResolutionPosition < $submittedGuardPosition
    && str_contains($takeContext, "require __DIR__ . '/_submitted_view.php'")
    && str_contains($submittedViewSource, 'qs-theme-studio')
    && str_contains($submittedViewSource, 'qs-theme-navy')
    && str_contains($submittedViewSource, "quiz_public_url('start')")
    && str_contains($submittedViewSource, "\$visibility === 'hidden'")
    && str_contains($submittedViewSource, 'Results are private')
    && !str_contains($submittedViewSource, 'total_score')
    && !str_contains($submittedViewSource, 'attempt_category_scores')
    && !str_contains($submittedViewSource, 'elapsed_seconds'),
    'submitted hidden-result attempts must render a themed private completion state without scores, categories, or elapsed result data');
$check(str_contains($takeView, '.qt-theme-studio .qt-main .qt-card{ box-shadow:10px 10px 0 #10162f; }')
    && str_contains($takeView, 'box-shadow:6px 6px 0 #10162f;')
    && str_contains($takeJs, 'box-shadow:10px 10px 0 #10162f;')
    && str_contains($resultSource, '.qr.qr-theme-studio .qr-hero{ box-shadow:0 24px 70px rgba(16,22,47,.18); }')
    && str_contains($submittedViewSource, '.qs.qs-theme-studio .qs-result-note{ box-shadow:7px 7px 0 #10162f; }'),
    'Studio theme must carry Study Interest soft and offset shadow depth across take, result, and submitted surfaces');
$check(str_contains($takeView, '.qt-theme-navy .qt-main .qt-card{')
    && str_contains($takeView, 'box-shadow:0 18px 50px rgba(11,31,53,.08);')
    && str_contains($takeJs, 'body.qz-theme-navy #qzProctorCard')
    && str_contains($takeJs, 'background:#fcbc55; color:#132842;')
    && str_contains($resultSource, '.qr.qr-theme-navy .qr-hero{ box-shadow:0 24px 70px rgba(11,31,53,.18); }')
    && str_contains($submittedViewSource, '.qs.qs-theme-navy .qs-result-note{ box-shadow:0 18px 44px rgba(11,31,53,.09); }'),
    'Navy theme must carry generic navy, warm-gold, ivory, and soft elevation across participant surfaces');
$check(str_contains($takeView, "class=\"qt-sec'.(\$sectionDone?' done':'').'\"")
    && str_contains($takeView, '.qt-sec.done{')
    && str_contains($takeJs, 'function refreshSectionStates()')
    && str_contains($takeJs, 'if (!answeredMap[seq]) done = false;')
    && substr_count($takeJs, 'answeredMap[answerSeq]') === 3
    && strpos($takeJs, 'buildSectionsUI();') < strpos($takeJs, 'refreshProgressUI();', strpos($takeJs, 'buildSectionsUI();')),
    'category navigation must render and live-update its completed state after every question in the range is answered');
$check(str_contains($pluginSource, "'api/set_review.php' => 'api/set_review.php'")
    && str_contains($pluginSource, "'result' => 'result/index.php'"), 'review and result routes must be explicitly allowlisted');
$check(str_contains($reviewSource, 'qz_public_csrf_check') && str_contains($reviewSource, 'FOR UPDATE')
    && str_contains($reviewSource, 'allow_mark_for_review') && str_contains($reviewSource, 'attempt_questions'), 'review mutation must enforce CSRF, locked ownership, opt-in, and assigned-question boundaries');
$check(str_contains($resultSource, "header('Cache-Control: no-store")
    && str_contains($resultSource, "status'] !== 'submitted'") && str_contains($resultSource, "visibility === 'hidden'")
    && !str_contains($resultSource, 'attempt_answers aa.answer_text'), 'participant results must be no-store, submitted-only, opt-in, and response-free');
$check(str_contains($resultSource, 'q.visual_theme_override')
    && str_contains($resultSource, "settings_get(\$pdo, 'quiz_visual_theme', 'default')")
    && str_contains($resultSource, 'class="qr-score-ring"')
    && str_contains($resultSource, 'class="qr-category"')
    && str_contains($resultSource, '$available > 0 ?')
    && str_contains($resultSource, '$totalQuestions > 0 ?'),
    'participant results must use the resolved visual theme, responsive report UI, and zero-safe score and completion calculations');
$check(str_contains($helperSource, 'function quiz_site_favicon_url(PDO $pdo)')
    && str_contains($helperSource, "settings_get(\$pdo, 'favicon_url', '')")
    && str_contains($helperSource, "settings_favicon_url_validation_error(\$url)")
    && str_contains($resultSource, 'src="<?= h($faviconUrl) ?>"')
    && str_contains($resultSource, '$publicTitle = quiz_public_title($pdo);')
    && str_contains($publicLayoutSource, '<link rel="icon" href="<?= htmlspecialchars($faviconUrl'),
    'public participant branding must combine the validated Core favicon with the configured Global Title');
$check(str_contains($saveAnswerSource, '$pdo->beginTransaction()') && str_contains($saveAnswerSource, 'LIMIT 1 FOR UPDATE')
    && str_contains($saveAnswerSource, 'attempt_answer_revisions') && str_contains($saveAnswerSource, 'if (!$same)'), 'answer saves must lock, revise material changes, and ignore identical retries');
$check(str_contains($submitSource, 'quiz_finalize_attempt($pdo, $attempt_id)')
    && str_contains($timerHelper, 'quiz_finalize_attempt($pdo, $attempt_id)'), 'explicit and timer submission must share one finalizer');
$finalizerSource = substr($helperSource, (int)strpos($helperSource, 'function quiz_finalize_attempt'));
$check(str_contains($helperSource, 'function quiz_snapshot_attempt_questions')
    && str_contains($finalizerSource, 'available_score_snapshot')
    && !str_contains($finalizerSource, 'FROM question_answers'), 'finalization denominator and category grouping must use immutable assignment snapshots, not the mutable answer bank');
$categoryValidationPosition = strpos($finalizerSource, 'SELECT id FROM question_categories');
$categoryInsertPosition = strpos($finalizerSource, 'INSERT INTO attempt_category_scores');
$check($categoryValidationPosition !== false && $categoryInsertPosition !== false
    && $categoryValidationPosition < $categoryInsertPosition
    && str_contains($finalizerSource, 'WHERE quiz_id=? AND id IN ($placeholders) FOR UPDATE')
    && str_contains($finalizerSource, '$categoryId = isset($validCategoryIds[$categoryId]) ? $categoryId : 0;')
    && str_contains($finalizerSource, "\$key = trim((string)(\$row['category_key_snapshot']")
    && str_contains($finalizerSource, "\$categoryName = trim((string)(\$row['category_name_snapshot']"), 'finalization must bulk-check snapshot category IDs in the attempt quiz, null stale IDs, and retain immutable key/name aggregation');
$getQuestionSource = (string)file_get_contents($pluginRoot . '/public/api/get_question.php');
$check(str_contains($helperSource, 'INSERT INTO attempt_question_options')
    && str_contains($helperSource, 'source_answer_id')
    && str_contains($helperSource, 'delivery_seq')
    && str_contains($helperSource, 'options_snapshotted_at IS NULL')
    && str_contains($helperSource, 'COALESCE(options_snapshotted_at,NOW())'), 'option snapshots must capture immutable IDs, labels, scores, and stable delivery order exactly once');
$check(str_contains($helperSource, "'version' => 1")
    && str_contains($helperSource, "'question' => [")
    && str_contains($helperSource, "'passage' =>")
    && str_contains($helperSource, "'category' => [")
    && str_contains($helperSource, 'delivery_snapshot_json=CASE WHEN :replace_delivery_json=1 THEN :delivery_snapshot ELSE delivery_snapshot_json END')
    && str_contains($helperSource, 'available_score_snapshot=COALESCE(available_score_snapshot,:available_score)'), 'delivery and denominator snapshots must be versioned and write-once');
$snapshotHelperSource = substr($helperSource, (int)strpos($helperSource, 'function quiz_snapshot_attempt_questions'),
    (int)strpos($helperSource, '/** Results are public') - (int)strpos($helperSource, 'function quiz_snapshot_attempt_questions'));
$check(str_contains($snapshotHelperSource, 'SELECT token_hash, quiz_id FROM quiz_attempts WHERE id=:attempt_id LIMIT 1 FOR UPDATE')
    && str_contains($snapshotHelperSource, 'SELECT id FROM attempt_questions WHERE attempt_id=:attempt_id ORDER BY id ASC FOR UPDATE')
    && str_contains($snapshotHelperSource, 'SELECT shuffle_answers FROM quizzes WHERE id=:quiz_id LIMIT 1')
    && !str_contains($snapshotHelperSource, 'SELECT shuffle_answers FROM quizzes WHERE id=:quiz_id LIMIT 1 FOR UPDATE'), 'snapshot capture must lock attempt then assignments without taking a quiz-row lock');
$check(!preg_match('/UPDATE\s+(?:quiz_attempts|attempt_answers|questions|quizzes|students)\b/i', $snapshotHelperSource)
    && !str_contains($snapshotHelperSource, 'SET seq='), 'snapshot capture must not change attempt state, scores, answers, assignment order, bank content, settings, or identity');
$check(str_contains($getQuestionSource, 'FROM attempt_question_options')
    && !str_contains($getQuestionSource, 'FROM question_answers')
    && substr_count($saveAnswerSource, 'attempt_question_options') >= 2
    && str_contains($saveAnswerSource, 'SELECT score FROM attempt_question_options')
    && !str_contains($saveAnswerSource, 'SELECT score FROM question_answers'), 'participant rendering, validation, and scoring must use snapshotted options exclusively');
$check(str_contains($saveAnswerSource, '$answerText = (string)$selectedSourceId')
    && str_contains($getQuestionSource, 'FILTER_VALIDATE_INT')
    && str_contains($attemptViewSource, 'FILTER_VALIDATE_INT'), 'deleted but snapshotted single-choice options must remain selectable and readable without violating legacy foreign keys');
$check(str_contains($getQuestionSource, "\$deliverySnapshot = json_decode")
    && str_contains($getQuestionSource, "\$q['question_text'] = (string)(\$questionDelivery['text']")
    && str_contains($saveAnswerSource, "\$delivery['question']['type']")
    && str_contains($attemptViewSource, "\$item['question_text'] = (string)(\$delivery['question']['text']"), 'participant delivery, scoring type, and admin history must prefer immutable delivery snapshots');
$check(str_contains($getQuestionSource, 'quiz_revalidate_delivery_snapshot_assets($pdo, $deliverySnapshot)')
    && str_contains($helperSource, "visibility='public' AND storage_disk='public' AND access_scope='public'")
    && str_contains($helperSource, "access_scope='public' AND is_downloadable=1"), 'snapshotted question and passage assets must recheck current public and downloadable authorization');

final class SnapshotAssetContractStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool {
        $allowed = str_contains($this->sql, 'FROM media') ? [11] : [22];
        $this->rows = array_values(array_intersect($params ?? [], $allowed));
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class SnapshotAssetContractPDO extends PDO {
    public array $queries = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return new SnapshotAssetContractStatement($query);
    }
}
$assetPdo = new SnapshotAssetContractPDO();
$filteredSnapshot = quiz_revalidate_delivery_snapshot_assets($assetPdo, [
    'question' => ['image_media_id' => 11, 'image_url' => '/immutable-question.jpg',
        'file_id' => 21, 'file_label' => 'Old question label', 'file_url' => '/immutable-question.pdf'],
    'passage' => ['image_media_id' => 12, 'image_url' => '/immutable-passage.jpg',
        'file_id' => 22, 'file_label' => 'Old passage label', 'file_url' => '/immutable-passage.pdf'],
]);
$assetQueries = implode("\n", $assetPdo->queries);
$check(($filteredSnapshot['question']['image_media_id'] ?? null) === 11
    && ($filteredSnapshot['question']['image_url'] ?? null) === '/immutable-question.jpg'
    && ($filteredSnapshot['question']['file_id'] ?? null) === null
    && ($filteredSnapshot['question']['file_label'] ?? null) === ''
    && ($filteredSnapshot['question']['file_url'] ?? null) === ''
    && ($filteredSnapshot['passage']['image_media_id'] ?? null) === null
    && ($filteredSnapshot['passage']['image_url'] ?? null) === ''
    && ($filteredSnapshot['passage']['file_id'] ?? null) === 22
    && ($filteredSnapshot['passage']['file_label'] ?? null) === 'Old passage label'
    && ($filteredSnapshot['passage']['file_url'] ?? null) === '/immutable-passage.pdf'
    && substr_count($assetQueries, "visibility='public'") === 2
    && substr_count($assetQueries, "storage_disk='public'") === 2
    && substr_count($assetQueries, "access_scope='public'") === 2
    && substr_count($assetQueries, 'is_downloadable=1') === 1, 'snapshot asset delivery must preserve authorized immutable metadata and fail closed for revoked or missing question and passage assets');
$check(str_contains($verifySource, "status = 'verified'")
    && !str_contains($verifySource, 'attempt_questions')
    && !str_contains($verifySource, 'ORDER BY RAND()')
    && !str_contains($verifySource, 'quiz_snapshot_attempt_questions')
    && !str_contains($verifySource, 'SET started_at'),
    'successful OTP verification must only mark verification and must not assign, randomize, snapshot, or start an attempt');
$takePreparationSource = substr($takeContext, (int)strpos($takeContext, '$requestMethod ='));
$takePickPosition = strpos($takePreparationSource, '$ids = qz_pick_attempt_question_ids($pdo, $quiz_id, $limit)');
$takeResequencePosition = strpos($takePreparationSource, 'qz_resequence_attempt_questions($pdo, $attempt_id);');
$takeAssignmentValidationPosition = strpos($takePreparationSource, 'quiz_attempt_assignment_is_valid($pdo, $attempt_id)');
$takeSnapshotPosition = strpos($takePreparationSource, 'quiz_snapshot_attempt_questions($pdo, $attempt_id)');
$takeCommitPosition = strpos($takePreparationSource, '$pdo->commit();', $takeSnapshotPosition === false ? 0 : $takeSnapshotPosition);
$takeTimerPosition = strpos($takePreparationSource, 'qz_timer_enforce(', $takeCommitPosition === false ? 0 : $takeCommitPosition);
$check($takePickPosition !== false && $takeResequencePosition !== false && $takeAssignmentValidationPosition !== false
    && $takeSnapshotPosition !== false
    && $takeCommitPosition !== false && $takeTimerPosition !== false
    && $takePickPosition < $takeResequencePosition && $takeResequencePosition < $takeAssignmentValidationPosition
    && $takeAssignmentValidationPosition < $takeSnapshotPosition
    && str_contains($takePreparationSource, 'if (!$hasAnySnapshot && !quiz_attempt_assignment_is_valid($pdo, $attempt_id))')
    && $takeSnapshotPosition < $takeCommitPosition && $takeCommitPosition < $takeTimerPosition,
    'take POST must quota-pick, resequence, and live-validate only before the first snapshot, then start the timer after commit');
$check(str_contains($takePreparationSource, '$hasAnyAnswer')
    && str_contains($takePreparationSource, '$hasAnySnapshot')
    && str_contains($takePreparationSource, '$buildPackage = $assignmentRows === [] || (!$hasAnyAnswer && !$hasAnySnapshot)')
    && str_contains($takePreparationSource, 'if (!quiz_attempt_package_is_complete($pdo, $attempt_id))'),
    'take preparation must preserve answered or snapshotted assignments while completing missing immutable package rows');
$pickerSource = substr($takeContext, (int)strpos($takeContext, 'function qz_pick_attempt_question_ids'),
    (int)strpos($takeContext, 'function qz_resequence_attempt_questions') - (int)strpos($takeContext, 'function qz_pick_attempt_question_ids'));
$check(str_contains($pickerSource, 'sort_order ASC') && str_contains($pickerSource, '$catMaxById')
    && str_contains($pickerSource, 'if ($cur >= $cap) return false;')
    && str_contains($takePreparationSource, "DELETE FROM attempt_questions WHERE attempt_id = :aid"),
    'new and unsnapshotted packages must rebuild through category order and strict category caps');
$finalizerReadinessPosition = strpos($finalizerSource, 'quiz_attempt_package_is_complete($pdo, $attemptId)');
$finalizerRowsPosition = strpos($finalizerSource, 'FROM attempt_questions aq');
$check($finalizerReadinessPosition !== false && $finalizerRowsPosition !== false
    && $finalizerReadinessPosition < $finalizerRowsPosition
    && !str_contains($finalizerSource, 'quiz_snapshot_attempt_questions('),
    'attempt finalization must require a complete package and never create its first snapshot implicitly');
foreach ([
    'get question' => [$getQuestionSource, 'qz_timer_enforce('],
    'save answer' => [$saveAnswerSource, 'qz_timer_enforce('],
    'set review' => [$reviewSource, 'qz_timer_enforce('],
    'submit' => [$submitSource, 'qz_timer_enforce('],
] as $apiName => [$apiSource, $mutationNeedle]) {
    $readinessPosition = strpos($apiSource, 'quiz_attempt_package_is_complete(');
    $mutationPosition = strpos($apiSource, $mutationNeedle);
    $check($readinessPosition !== false && $mutationPosition !== false && $readinessPosition < $mutationPosition
        && str_contains($apiSource, "quiz_public_title(\$pdo) . ' preparation required.'"),
        "{$apiName} API must return bounded 409 preparation-required before timer or participant mutation");
}
$check(!str_contains($getQuestionSource, 'quiz_snapshot_attempt_questions(')
    && !str_contains($saveAnswerSource, 'quiz_snapshot_attempt_questions(')
    && !str_contains($reviewSource, 'quiz_snapshot_attempt_questions(')
    && !str_contains($submitSource, 'quiz_snapshot_attempt_questions('),
    'participant APIs must never lazily create the first package snapshot');
$uninstallSource = substr($pluginSource, (int)strpos($pluginSource, "add_action('plugin_uninstall'"));
$check(strpos($uninstallSource, "'attempt_question_options'") !== false
    && strpos($uninstallSource, "'attempt_question_options'") < strpos($uninstallSource, "'attempt_questions'"), 'uninstall must drop option snapshots before assigned questions');
$check(str_contains($helperSource, 'function quiz_attempt_result_is_ready')
    && str_contains($resultSource, 'Result Unavailable')
    && str_contains($resultSource, 'quiz_attempt_result_is_ready'), 'historical or inconsistent results must fail closed without a raw score');
$startSource = (string)file_get_contents($pluginRoot . '/public/start/index.php');
$check(str_contains($startSource, 'quiz_attempt_result_is_ready')
    && str_contains($submittedViewSource, 'quiz_attempt_result_is_ready'), 'start and take pages must withhold result links until finalized snapshots are valid');
$check(str_contains($attemptViewSource, "if (\$user['can_view_attempt_responses'])")
    && str_contains($attemptViewSource, 'FROM attempt_answer_revisions r'), 'revision history must remain behind the response-detail permission boundary');
$adminInitSource = (string)file_get_contents($pluginRoot . '/admin/_init.php');
$check(str_contains($adminInitSource, 'qz_assert_question_not_used_by_attempt')
    && str_contains($adminInitSource, 'qz_assert_answer_not_used_by_attempt')
    && str_contains($adminInitSource, 'qz_assert_question_snapshots_complete')
    && str_contains($adminInitSource, 'qz_assert_related_delivery_snapshots_complete')
    && str_contains($adminInitSource, 'qz_assert_question_type_change_safe')
    && str_contains($adminInitSource, 'JSON_CONTAINS'), 'bank guards must reject incomplete legacy assignments and protect scalar or multiple-choice response references');
$questionSaveSource = (string)file_get_contents($pluginRoot . '/admin/master/api/question_save.php');
$check(str_contains($questionSaveSource, 'qz_assert_question_snapshots_complete')
    && str_contains($questionSaveSource, 'qz_assert_question_type_change_safe')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/questions/index.php'), 'qz_assert_question_snapshots_complete'), 'every question editor must permit changes only after existing assignments have complete immutable snapshots');
$snapshotGuardStart = (int)strpos($adminInitSource, 'function qz_assert_question_snapshots_complete');
$snapshotGuardEnd = (int)strpos($adminInitSource, "if (!function_exists('qz_assert_related_delivery_snapshots_complete')", $snapshotGuardStart);
$snapshotGuardSource = substr($adminInitSource, $snapshotGuardStart, $snapshotGuardEnd - $snapshotGuardStart);
$check(str_contains($snapshotGuardSource, "COUNT(*) AS assigned_count")
    && str_contains($snapshotGuardSource, "AS complete_count")
    && str_contains($snapshotGuardSource, "['assigned_count'] ?? 0) !== (int)(\$counts['complete_count'] ?? 0)")
    && !str_contains($snapshotGuardSource, "['assigned_count'] ?? 0) > 0"), 'fully snapshotted assignments must not lock non-destructive question authoring');
$attemptLockPosition = strpos($saveAnswerSource, '$attemptStmt');
$packageCheckPosition = strpos($saveAnswerSource, 'quiz_attempt_package_is_complete(');
$assignedLockPosition = strpos($saveAnswerSource, '$questionStmt');
$deliveryReadPosition = strpos($saveAnswerSource, '$delivery = json_decode');
$optionLockPosition = strpos($saveAnswerSource, 'FROM attempt_question_options');
$check($attemptLockPosition !== false && $packageCheckPosition !== false && $assignedLockPosition !== false
    && $deliveryReadPosition !== false && $optionLockPosition !== false
    && $attemptLockPosition < $packageCheckPosition && $packageCheckPosition < $assignedLockPosition
    && $assignedLockPosition < $deliveryReadPosition && $deliveryReadPosition < $optionLockPosition
    && !str_contains($saveAnswerSource, '$questionDefinitionStmt')
    && substr_count($saveAnswerSource, 'FOR UPDATE') >= 4, 'answer save must lock auth, require package readiness, then use locked delivery and option snapshots in order');
$check(str_contains($submitSource, 'quiz_participant_submit_payload')
    && !str_contains($submitSource, "'total_score' =>")
    && !str_contains($submitSource, "'elapsed_seconds' =>"), 'submit endpoint must not serialize score or elapsed fields directly');
$check(str_contains($takeJs, 'const requestedSeq = Number(currentSeq)')
    && str_contains($takeJs, 'setReviewState(requestedSeq,'), 'review UI must update the requested row rather than mutable navigation state');
$lifecycleSources = $timerHelper . $saveAnswerSource . $reviewSource . $submitSource;
$check(substr_count($lifecycleSources, "'status' => 'submitted'") === 4
    && !str_contains($extensionSource, "'total_score', 'elapsed_seconds'")
    && !str_contains($extensionSource, "'computed_score'"), 'explicit and timer lifecycle emitters must use consistent score-free payload allowlists');
$answerSaveAdminSource = (string)file_get_contents($pluginRoot . '/admin/master/api/answer_save.php');
$answerDeleteAdminSource = (string)file_get_contents($pluginRoot . '/admin/master/api/answer_delete.php');
$answerReorderAdminSource = (string)file_get_contents($pluginRoot . '/admin/master/api/answers_reorder.php');
$check(str_contains($answerSaveAdminSource, 'qz_assert_question_snapshots_complete')
    && str_contains($answerDeleteAdminSource, 'qz_assert_answer_not_used_by_attempt')
    && str_contains($answerReorderAdminSource, 'qz_assert_question_snapshots_complete')
    && substr_count($answerSaveAdminSource . $answerDeleteAdminSource . $answerReorderAdminSource, "'assigned_content_locked'") === 3, 'answer mutation must require complete option snapshots, while deletion additionally protects response references with bounded domain errors');
$check(str_contains($attemptViewSource, 'FROM attempt_question_options')
    && str_contains($attemptViewSource, 'aq.delivery_snapshot_json')
    && str_contains($attemptViewSource, "if (\$type === 'multiple_choice')")
    && str_contains($attemptViewSource, "implode('; ', \$resolved)"), 'admin multiple-choice current and revision responses must render snapshotted labels instead of raw JSON IDs');
$check(str_contains($readmeSource, 'Delivery Snapshots')
    && str_contains($readmeSource, 'including assignments on submitted attempts')
    && str_contains($readmeSource, 'one transaction per attempt')
    && str_contains($readmeSource, 'retains the legacy foreign keys'), 'README must document the retry-safe legacy backfill and retained foreign-key limitation');

$adminCss = (string)file_get_contents($pluginRoot . '/assets/admin.css');
$adminJs = (string)file_get_contents($pluginRoot . '/assets/admin.js');
$check(!preg_match('/(^|})\s*\.adam-button\s*\{/m', $adminCss), 'Quiz CSS must not override the Core adam-button component');
$check(!preg_match('/(^|})\s*\.adam-card(?:\s|\{|,)/m', $adminCss), 'Quiz CSS must not globally override the Core adam-card component');
$check(!preg_match('/(^|})\s*\.adam-input(?:\s|\{|:)/m', $adminCss), 'Quiz CSS must not globally override the Core adam-input component');
$check(!str_contains($adminCss, 'html.theme-dark,html.theme-dark body'), 'admin CSS must not force a global color scheme');
$check(str_contains($adminCss, '#adam-main .qz-card'), 'Quiz admin selectors must be scoped to the Core main region');
$check(str_contains($adminCss, '#adam-main .adam-input{'), 'Quiz form controls must retain request-scoped styling');
$check(str_contains($adminCss, '#adam-main .tag{'), 'Quiz master tags must retain request-scoped styling');
$check(str_contains($adminCss, '#adam-main .qz-bulkbar{'), 'Quiz bulk controls must remain scoped to the plugin region');
$check(str_contains($adminCss, '#adam-main .qz-action-menu{'), 'Student action dropdown must remain scoped to the plugin region');
$check(str_contains($adminCss, '.qz-action-menu__panel{position:fixed;'), 'Student action dropdown must overlay instead of resizing its table cell');
$check(str_contains($adminCss, '.qz-action-layer{position:fixed;') && str_contains($adminCss, 'pointer-events:none'), 'Student action dropdown layer must stay outside table overflow and ignore empty-space pointer events');
$cssWithoutComments = preg_replace('~/\*.*?\*/~s', '', $adminCss) ?? $adminCss;
preg_match_all('/(?<=[{}])\s*([^{}]+?)\s*\{/m', '{' . $cssWithoutComments, $selectorBlocks);
$unscopedSelectors = [];
foreach ($selectorBlocks[1] ?? [] as $selectorBlock) {
    $selectorBlock = trim((string)$selectorBlock);
    if ($selectorBlock === '' || str_starts_with($selectorBlock, '@')) continue;
    foreach (explode(',', $selectorBlock) as $selector) {
        $selector = trim($selector);
        if ($selector !== '' && !str_contains($selector, '#adam-main')) $unscopedSelectors[] = $selector;
    }
}
$check($unscopedSelectors === [], 'every Quiz admin selector must be scoped to #adam-main');

$attemptIndex = (string)file_get_contents($pluginRoot . '/admin/student/attempts/index.php');
$attemptView = (string)file_get_contents($pluginRoot . '/admin/student/attempts/view.php');
$attemptExport = (string)file_get_contents($pluginRoot . '/admin/student/attempts/export.php');
$attemptExportHelper = (string)file_get_contents($pluginRoot . '/admin/student/attempts/_export.php');
$attemptMonitoring = (string)file_get_contents($pluginRoot . '/admin/student/attempts/_monitoring.php');
$check(str_contains($attemptIndex, 'SELECT COUNT(*)')
    && str_contains($attemptIndex, 'qz_attempt_monitoring_sql($whereSql, $orderBy, $perPage, $offset)')
    && str_contains($attemptMonitoring, "' LIMIT ' . max(1, \$limit) . ' OFFSET ' . max(0, \$offset)"), 'attempt list must use filtered server pagination');
$check(!str_contains($attemptIndex, 'LIMIT 500'), 'attempt list must not silently truncate at 500 rows');
$check(str_contains($attemptIndex, "name=\"student_id\"") && str_contains($attemptIndex, "\$_GET['p']"), 'attempt pagination must preserve student scope and use the p query key');
$check(!str_contains($attemptIndex, 'GROUP BY attempt_id'), 'attempt pages must not aggregate every attempt before pagination');
$check(str_contains($attemptMonitoring, "function qz_attempt_filters")
    && str_contains($attemptIndex, 'qz_attempt_filters($_GET)')
    && str_contains($attemptExport, 'qz_attempt_filters($_POST)'), 'attempt list and export must share one allowlisted filter implementation');
$check(str_contains($attemptMonitoring, "['online', 'onsite', 'unset']") && str_contains($attemptMonitoring, 'a.delivery_mode IS NULL'), 'shared attempt filters must include legacy unset delivery mode');
$check(str_contains($attemptIndex, 'data-attempt-column-picker')
    && str_contains($attemptIndex, 'quiz-attempt-columns-v1')
    && str_contains($attemptIndex, 'data-attempt-column="legacy_score"')
    && str_contains($attemptIndex, 'data-column="legacy_score" hidden')
    && str_contains($adminJs, "document.querySelectorAll('[data-attempt-column-picker]')")
    && str_contains($adminJs, "wireColumnPicker(picker, '[data-attempt-column-table]'"), 'Attempt Manager must expose persistent optional columns with legacy score hidden by default');
$check(str_contains($attemptIndex, 'qz-resultbar')
    && str_contains($attemptIndex, 'qz-attempt-table')
    && str_contains($attemptIndex, 'class="qz-pagination"')
    && str_contains($adminCss, '.qz-attempt-table [data-column][hidden]'), 'Attempt column controls must use the shared result, table, and pagination presentation');
$check(str_contains($attemptIndex, 'title="The participant selects the delivery mode."')
    && str_contains($attemptIndex, 'title="The method recorded after the Attempt is Verified. This does not prove that an OTP was sent or received."'), 'Attempt Mode and Verification headers must explain the limits of participant-provided metadata');
$check(str_contains($attemptIndex, 'class="qz-attempt-student-cell"')
    && str_contains($attemptIndex, 'class="qz-attempt-student__contact"')
    && str_contains($attemptIndex, 'class="qz-attempt-student__ids"')
    && str_contains($adminCss, '.qz-attempt-student__ids span:first-child code'), 'Attempt identity must separate contact details and emphasize the test number');
$check(str_contains($attemptIndex, 'class="adam-button ghost qz-attempt-detail"')
    && str_contains($attemptIndex, 'class="qz-action-menu qz-action-menu--compact"')
    && str_contains($attemptIndex, 'qz-action-menu__item--danger')
    && str_contains($attemptIndex, 'data-confirm-variant="danger"'), 'Attempt detail must stay visible while destructive deletion uses the accessible overflow menu');
$check(str_contains($attemptMonitoring, "'started_at' => 'a.started_at'")
    && str_contains($attemptMonitoring, "'submitted_at' => 'a.submitted_at'")
    && str_contains($attemptIndex, "\$todayUrl('started_at')")
    && str_contains($attemptIndex, "\$todayUrl('submitted_at', 'submitted')"), 'attempt monitoring must provide started-today and submitted-today filters');
foreach (['index.php' => $attemptIndex, 'view.php' => $attemptView, 'export.php' => $attemptExport] as $file => $source) {
    $check(str_contains($source, 'qz_attempt_no_store();'), "attempt {$file} must disable browser and intermediary caching");
}
$check(str_contains($attemptMonitoring, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private')")
    && str_contains($attemptMonitoring, "header('Pragma: no-cache')"), 'attempt monitoring no-store headers must fail closed across legacy caches');
$check(str_contains($attemptExport, "REQUEST_METHOD'] ?? '') !== 'POST'")
    && str_contains($attemptExport, 'quiz_csrf_check();'), 'attempt CSV export must require POST and CSRF');
$check(str_contains($attemptIndex, 'if ($user[\'can_export_attempts\'])')
    && str_contains($attemptIndex, 'method="post"')
    && str_contains($attemptIndex, 'name="action" value="export"')
    && str_contains($attemptIndex, 'name="format" value="xlsx"')
    && str_contains($attemptIndex, 'name="format" value="csv"')
    && str_contains($attemptIndex, 'page=admin/quiz/attempts/export'), 'attempt export UI must be capability-gated and submit by POST');
$check(str_contains($attemptExport, "['xlsx', 'csv']")
    && str_contains($attemptExport, "\$_POST['format'] ?? 'xlsx'"), 'attempt export must allowlist XLSX and CSV with Excel as the default');
$check(str_contains($attemptExport, "header('Content-Type: text/csv; charset=UTF-8')")
    && str_contains($attemptExport, "fputcsv(\$output, array_map(\$safe, \$cells), ';'")
    && str_contains($attemptExport, "header('X-Content-Type-Options: nosniff')"), 'CSV fallback must emit a hardened semicolon-delimited response');
$check(str_contains($attemptExportHelper, 'function qz_attempt_export_groups')
    && str_contains($attemptExportHelper, "\$selected['student'] = true")
    && str_contains($attemptExportHelper, "\$selected['status'] = true")
    && str_contains($attemptExportHelper, "'test_number', 'label'=>'Test number'")
    && !str_contains($attemptExportHelper, "'label'=>'Alias'"), 'attempt export must allowlist visible report groups while retaining required identity and status columns');
$check(str_contains($attemptIndex, 'data-attempt-export-form')
    && str_contains($attemptIndex, 'data-attempt-export-column')
    && str_contains($adminJs, 'syncAttemptExportColumns')
    && str_contains($attemptExport, "qz_attempt_export_columns(\$_POST['columns'] ?? null)"), 'Attempt column preferences must drive server-validated CSV and XLSX export columns');
$attemptExportScript = <<<'PHP'
define('DASHBOARD_CONTEXT', true);
require $argv[1];
$defaults = ['Student Name','Phone Number','Email','Test number','Quiz','Status','Mode','Verification','Answered','Correct','Score out of 100','Activity'];
if (qz_attempt_export_headers() !== $defaults) exit(31);
$custom = qz_attempt_export_headers(['bogus', 'reset', 'rank', 'legacy_score']);
if ($custom !== ['No.','Student Name','Phone Number','Email','Test number','Status','Legacy Score','Reset Count']) exit(32);
PHP;
$process = proc_open([PHP_BINARY, '-r', $attemptExportScript, $pluginRoot . '/admin/student/attempts/_export.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$attemptExportStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $attemptExportStatus = proc_close($process);
}
$check($attemptExportStatus === 0, 'Attempt export schema must ignore unknown columns and retain required student and status groups');
$check(str_contains($attemptExportHelper, "date('d/m/Y H:i', \$activityTimestamp)")
    && str_contains($attemptExportHelper, "'online' => 'Online'")
    && str_contains($attemptExportHelper, "'email' => 'Email'"), 'attempt export must format activity, mode, and verification for staff');
$check(str_contains($attemptExportHelper, "'/vendor/autoload.php'")
    && str_contains($attemptExportHelper, "extension_loaded(\$extension)")
    && str_contains($attemptExportHelper, 'PHP_INT_SIZE !== 8')
    && str_contains($attemptIndex, 'Excel is not available on this server. Use CSV instead.'), 'Excel availability must fail gracefully with a visible CSV fallback');
$check(str_contains($attemptExport, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    && str_contains($attemptExport, 'setCellValueExplicit')
    && str_contains($attemptExport, "setTitle('Quiz Results')")
    && str_contains($attemptExport, "freezePane('A2')")
    && str_contains($attemptExport, "setAutoFilter('A1:' . \$lastColumn")
    && str_contains($attemptExport, "setFormatCode('dd/mm/yyyy hh:mm')"), 'XLSX export must use explicit text cells and staff-friendly worksheet formatting');
$check(str_contains($attemptExport, '/^[\\p{Z}\\x00-\\x20]*[=+\\-@]/u')
    && str_contains($attemptExport, '$cell = "\'" . $cell'), 'attempt export must neutralize spreadsheet formulas including whitespace-prefixed payloads');
foreach (['email_verification_code', 'otp_email_sent_at', 'otp_wa_sent_at', 'ip_address', 'token_hash', 'user_agent_hash'] as $sensitiveColumn) {
    $check(!str_contains($attemptMonitoring, $sensitiveColumn) && !str_contains($attemptExport, $sensitiveColumn) && !str_contains($attemptExportHelper, $sensitiveColumn), "attempt exports must exclude {$sensitiveColumn}");
}
foreach (['selected_answer_id', 'answer_text', 'question_text', 'computed_score'] as $responseColumn) {
    $check(!str_contains($attemptExport, $responseColumn) && !str_contains($attemptExportHelper, $responseColumn), "attempt exports must exclude response field {$responseColumn}");
}
$check(str_contains($attemptView, "if (\$user['can_view_attempt_responses'])")
    && str_contains($attemptView, "\$user['can_view_attempt_otp']")
    && str_contains($attemptView, "\$user['can_operate_attempts']")
    && str_contains($attemptView, "\$user['can_delete_attempts']"), 'attempt detail must independently gate responses, OTP, operations, and deletion');

$dashboardIndex = (string)file_get_contents($pluginRoot . '/admin/index.php');
$check(str_contains($dashboardIndex, "\$canViewAttempts = \$user['can_view_attempts']"), 'dashboard must evaluate attempts.view independently');
$check(preg_match('/if \(\$canViewAttempts\) \{.*COUNT\(DISTINCT student_id\).*COUNT\(\*\).*SUM\(status=\'submitted\'\)/s', $dashboardIndex) === 1, 'participant and attempt count queries must be inside the attempts.view gate');
$check(substr_count($dashboardIndex, 'if ($canViewAttempts):') === 2, 'participant metadata and Attempt Manager card must both be hidden without attempts.view');
$check(str_contains($dashboardIndex, 'if ($canManageStudents):') && str_contains($dashboardIndex, 'Open Students'), 'Student Manager card must remain behind students.manage');

$masterIndex = (string)file_get_contents($pluginRoot . '/admin/master/index.php');
$quizIndex = (string)file_get_contents($pluginRoot . '/admin/quizzes/index.php');
$check(str_contains($quizIndex, ' · {$oemail}') && !str_contains($quizIndex, '&middot;'), 'quiz owner separator must render as text instead of an escaped HTML entity');
foreach (['master selector' => $masterIndex, 'quiz list' => $quizIndex] as $label => $source) {
    $check(str_contains($source, 'SELECT COUNT(*)') && str_contains($source, 'OFFSET {$offset}'), "{$label} must use filtered server pagination");
    $check(str_contains($source, 'data-bulk-form') && str_contains($source, 'data-bulk-item'), "{$label} must expose current-page bulk selection");
    $check(str_contains($source, "\$_GET['status']") && str_contains($source, "\$_GET['primary']"), "{$label} must expose allowlisted status and primary filters");
}

$studentIndex = (string)file_get_contents($pluginRoot . '/admin/student/index.php');
$check(str_contains($studentIndex, 'SELECT COUNT(*)') && str_contains($studentIndex, 'LIMIT {$perPage} OFFSET {$offset}'), 'student list must use filtered server pagination');
$check(!str_contains($studentIndex, 'LIMIT 500') && str_contains($studentIndex, "\$_GET['p']"), 'student list must not silently truncate and must use the p query key');
$check(str_contains($studentIndex, 'COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN s.id END)')
    && str_contains($studentIndex, 'qz-metric-grid')
    && str_contains($studentIndex, 'qz-student-filterbar qz-command-bar'), 'Student Manager must expose useful summary statistics and a focused filter bar');
$check(str_contains($studentIndex, 'qz-data-table qz-student-table')
    && str_contains($studentIndex, 'data-label="Student"')
    && str_contains($studentIndex, 'data-label="Contact"')
    && !str_contains($studentIndex, '<th>Email</th>')
    && !str_contains($studentIndex, '<th>HP</th>'), 'Student Manager must use compact semantic columns with combined contact details');
$check(str_contains($studentIndex, 'data-student-column-picker')
    && str_contains($studentIndex, 'quiz-student-columns-v1')
    && str_contains($adminJs, "document.querySelectorAll('[data-student-column-picker]')")
    && str_contains($adminJs, "wireColumnPicker(picker, '[data-student-column-table]'")
    && str_contains($adminCss, '.qz-student-table tbody tr'), 'Student columns must persist and collapse into responsive cards');
$check(str_contains($studentIndex, 'qz-editor-page qz-student-editor')
    && str_contains($studentIndex, 'qz-student-form-grid qz-form-grid'), 'Student add and edit forms must use the shared Quiz editor presentation');
$check(str_contains($studentIndex, 'qz-action-menu__toggle') && str_contains($studentIndex, 'aria-expanded="false"'), 'student actions must use an accessible disclosure control');
$check(!str_contains($studentIndex, 'class="actions qz-row-actions"'), 'student action cells must not inherit the Core flex actions utility');
$check(str_contains($studentIndex, 'qz-action-menu__item--danger') && str_contains($studentIndex, 'data-confirm-variant="danger"'), 'student delete must retain the destructive confirmation inside the dropdown');
$check(str_contains($studentIndex, 'admin/quiz/attempts/view&attempt_id='), 'student last-attempt fallback must target the detail route');
$masterBuilder = (string)file_get_contents($pluginRoot . '/admin/master/main.php');
$check(str_contains($masterBuilder, "\$_GET['return']") && str_contains($masterBuilder, "admin/quiz/master'"), 'Master Builder return navigation must preserve validated list state');
$check(str_contains($masterBuilder, 'id="btnToggleList"')
    && str_contains($masterBuilder, 'class="qz-question-pane" id="questionPane"')
    && str_contains($masterBuilder, 'class="qz-question-pane__toggle"')
    && strpos($masterBuilder, 'id="btnToggleList"') > strpos($masterBuilder, 'id="leftPanel"')
    && str_contains($masterBuilder, "const masterListStorageKey = 'quiz-master-list-collapsed-v2'")
    && str_contains($masterBuilder, 'let initialListCollapsed = false')
    && str_contains($masterBuilder, 'localStorage.setItem(masterListStorageKey'), 'Master Builder question list must start visible and use a persistent edge-mounted toggle');
$check(str_contains($masterBuilder, 'id="questionActions"')
    && str_contains($masterBuilder, 'id="questionActions" style="display:none;gap:8px;align-items:center"')
    && str_contains($masterBuilder, "questionActions.style.display = 'flex'")
    && str_contains($masterBuilder, "questionActions.style.display = 'none'"), 'Master Builder question actions must stay hidden until a question is selected');
$check(str_contains($masterBuilder, '<details class="attach-wrap attach-disclosure"')
    && str_contains($masterBuilder, 'id="questionAttachmentsState"')
    && str_contains($masterBuilder, 'syncQuestionAttachments(true)'), 'Master Builder attachments must use a native disclosure with populated state feedback');
$check(str_contains($masterBuilder, 'class="row qz-master-question-column"')
    && strpos($masterBuilder, 'class="qz-master-answer-section"') < strpos($masterBuilder, 'Posisi di daftar')
    && str_contains($adminCss, '.master-wrap .list{height:clamp(420px,72vh,900px);overflow:auto}')
    && str_contains($adminCss, '.master-wrap .qz-master-answer-section{display:grid;gap:6px;margin-top:4px}'), 'Master Builder must keep answers directly below question content and bound the expanded question list to the viewport');
$check(str_contains($masterBuilder, 'class="qz-live-preview qz-live-preview--question"')
    && str_contains($masterBuilder, 'Preview soal')
    && str_contains($masterBuilder, 'class="qz-live-preview qz-live-preview--answer"')
    && str_contains($masterBuilder, 'Preview jawaban ${index + 1}')
    && str_contains($adminCss, '.qz-live-preview__head{')
    && str_contains($adminCss, ".qz-live-preview .md-preview:empty::before{content:'Preview akan muncul saat teks diisi.'"), 'Master Builder question and answer previews must be visually identified as participant-facing output');
$check(str_contains($adminCss, 'grid-template-columns:300px minmax(0,1fr)')
    && str_contains($adminCss, '.master-wrap .right-body{min-width:0;padding:14px;overflow-x:auto}')
    && str_contains($adminCss, '.master-wrap #answersBox{max-width:100%;overflow-x:auto}')
    && str_contains($adminCss, 'grid-template-columns:minmax(0,1fr) minmax(180px,220px);gap:12px;min-width:0;align-items:start')
    && str_contains($adminCss, '.master-wrap.is-list-collapsed{grid-template-columns:1px minmax(0,1fr);gap:24px;max-width:1180px;margin-inline:auto}')
    && str_contains($adminCss, '.qz-question-pane__toggle{position:absolute')
    && str_contains($adminCss, '@media (max-width:1100px)'), 'Master Builder editor must bound grid tracks, stack on narrow screens, and contain unavoidable horizontal overflow locally');

$check(str_contains($adminJs, "document.querySelectorAll('[data-bulk-form]')"), 'admin JavaScript must initialize bulk selection');
$check(str_contains($adminJs, "e.key === 'ArrowDown'") && str_contains($adminJs, "e.key !== 'Escape'"), 'student action dropdown must support keyboard open and close behavior');
$check(str_contains($adminJs, 'getBoundingClientRect()') && str_contains($adminJs, 'rect.top - height - gap'), 'student action dropdown must position against its trigger and flip above when needed');
$check(str_contains($adminJs, "layer.appendChild(panel)") && str_contains($adminJs, "panel.style.visibility = 'hidden'"), 'student action dropdown must portal outside the table before visible positioning');
$check(str_contains($adminJs, ".qz-settings-form .settings-section-toggle") && str_contains($adminJs, "body.hidden = open"), 'Quiz settings sections must collapse through an AJAX-safe delegated handler');

$bulkSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/bulk_status.php');
$check(str_contains($bulkSource, 'count($ids) > 100'), 'bulk status mutation must cap selected IDs');
$check(str_contains($bulkSource, 'FOR UPDATE') && str_contains($bulkSource, 'qz_lock_quiz_for_workspace_mutation'), 'bulk status mutation must lock and authorize every selected quiz');
$check(strpos($bulkSource, 'beginTransaction()') < strpos($bulkSource, 'qz_lock_quiz_for_workspace_mutation('), 'bulk status must reauthorize workspace access after beginning its transaction');
$check(!str_contains($bulkSource, '$changed < 1') && str_contains($bulkSource, "' Quiz records processed; '") && str_contains($bulkSource, "' rows changed.'"), 'bulk status must accept authorized no-ops with a truthful changed-row message');
$check(str_contains($bulkSource, "is_primary'] === 1"), 'bulk status mutation must protect the primary quiz from deactivation');
$check(!str_contains($bulkSource, 'user_role'), 'bulk status mutation must rely on dynamic permission and owner guards');

$initSource = (string)file_get_contents($pluginRoot . '/admin/_init.php');
$check(str_contains($initSource, 'adiwira_require_permission') && str_contains($initSource, 'qz_route_permission'), 'admin entrypoints repeat the route permission guard');
$check(str_contains($initSource, "\$GLOBALS['_plugin_current_route']") && str_contains($initSource, "\$quizQueryRoute !== ''"), 'route guard must prefer Core route metadata and only validate a nonempty query route');
$check(str_contains($initSource, "plugin.quiz.content.manage-any") && !str_contains($initSource, "role === 'admin'"), 'cross-owner elevation uses only the explicit manage-any permission');
$check(str_contains($initSource, 'qz_require_workspace_mutation_access') && str_contains($initSource, "user_can(\$pdo, \$userId, 'plugin.quiz.workspace.access')"), 'mutation workspace reauthorization must fail closed through Core user_can');
$check(str_contains($initSource, 'qz_lock_quiz_for_owner_mutation') && str_contains($initSource, 'Only the quiz owner may perform this action.'), 'owner-level destructive actions remain unavailable to collaborators');
$check(str_contains($initSource, "['plugin.quiz.attempts.operate', 'plugin.quiz.attempts.delete']")
    && str_contains($initSource, 'qz_user_can($pdo, $userId, $permission)'), 'attempt mutations must reauthorize only operate or delete inside the transaction');
$capabilityMap = [
    'can_manage_any' => 'plugin.quiz.content.manage-any',
    'can_manage_quizzes' => 'plugin.quiz.quizzes.manage',
    'can_view_attempts' => 'plugin.quiz.attempts.view',
    'can_export_attempts' => 'plugin.quiz.attempts.export',
    'can_view_attempt_otp' => 'plugin.quiz.attempt-otp.view',
    'can_view_attempt_responses' => 'plugin.quiz.attempt-responses.view',
    'can_operate_attempts' => 'plugin.quiz.attempts.operate',
    'can_delete_attempts' => 'plugin.quiz.attempts.delete',
];
foreach ($capabilityMap as $metadata => $permission) {
    $expectedCall = $metadata === 'can_manage_any'
        ? "'can_manage_any' => qz_can_manage_any(\$pdo, \$id)"
        : "'{$metadata}' => qz_user_can(\$pdo, \$id, '{$permission}')";
    $check(str_contains($initSource, $expectedCall), "Quiz metadata {$metadata} must use only {$permission}");
}
$check(str_contains($initSource, 'function qz_quiz_access_sql') && str_contains($initSource, '.created_by =')
    && str_contains($initSource, 'EXISTS (SELECT 1 FROM quiz_collaborators'), 'shared quiz SQL must allow manage-any, owner, or collaborator access');
$check(str_contains($initSource, 'function qz_lock_quiz_for_workspace_mutation')
    && str_contains($initSource, 'SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE')
    && str_contains($initSource, 'SELECT user_id FROM quiz_collaborators')
    && str_contains($initSource, 'FOR UPDATE'), 'mutation authorization must lock the quiz and relevant collaborator assignment');
$check(str_contains($initSource, "'can_manage_any'") && !str_contains($initSource, "['is_admin']"), 'ownership semantics use the clear can_manage_any name');
$check(!str_contains($initSource, 'authorization_actor') && str_contains($initSource, 'user_can'), 'Site Owner cross-owner bypass flows through user_can');
$guardScript = <<<'PHP'
define('DASHBOARD_CONTEXT', true);
final class GuardPDO extends PDO { public function __construct() {} }
$pdo = new GuardPDO();
$GLOBALS['guard_permission'] = '';
function adiwira_require_permission(PDO $pdo, string $permission, bool $allowScope): array {
    $GLOBALS['guard_permission'] = $permission;
    return [7, []];
}
function quiz_install_schema(PDO $pdo): bool { return true; }
function current_user_id(): int { return 7; }
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool {
    $GLOBALS['user_can_permissions'][] = $permission;
    return $GLOBALS['user_can_result'] ?? true;
}
$GLOBALS['_plugin_current_route'] = [
    'route' => 'admin/quiz',
    'permission' => 'plugin.quiz.dashboard.access',
];
$_GET = [];
require $argv[1];
$manifest = json_decode((string)file_get_contents($argv[2]), true);
if (($GLOBALS['guard_permission'] ?? '') !== 'plugin.quiz.dashboard.access') exit(41);
foreach ($manifest['admin']['pages'] ?? [] as $page) {
    if (qz_route_permission((string)$page['route']) !== (string)$page['permission']) exit(42);
}
if (qz_route_permission('admin/quiz/unknown') !== null || qz_route_permission('') !== null) exit(43);
$user = qz_admin_user($pdo);
if (($user['can_manage_any'] ?? false) !== true
    || !in_array('plugin.quiz.content.manage-any', $GLOBALS['user_can_permissions'] ?? [], true)
    || !in_array('plugin.quiz.attempts.delete', $GLOBALS['user_can_permissions'] ?? [], true)) exit(44);
$GLOBALS['user_can_result'] = false;
$workspaceDenied = false;
try {
    qz_require_workspace_mutation_access($pdo, 7);
} catch (RuntimeException $error) {
    $workspaceDenied = true;
}
$permissionCalls = $GLOBALS['user_can_permissions'] ?? [];
if (!$workspaceDenied || end($permissionCalls) !== 'plugin.quiz.workspace.access') exit(45);
PHP;
$process = proc_open([PHP_BINARY, '-r', $guardScript, $pluginRoot . '/admin/_init.php', $pluginRoot . '/plugin.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$guardStatus = -1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $guardStatus = proc_close($process);
}
$check($guardStatus === 0, 'Core route metadata must authorize direct routes without page, map every route, and isolate manage-any authority');
$unknownRouteScript = <<<'PHP'
define('DASHBOARD_CONTEXT', true);
final class UnknownRoutePDO extends PDO { public function __construct() {} }
$pdo = new UnknownRoutePDO();
function adiwira_require_permission(PDO $pdo, string $permission, bool $allowScope): array { exit(51); }
function quiz_install_schema(PDO $pdo): bool { return true; }
$GLOBALS['_plugin_current_route'] = ['route' => 'admin/quiz/unknown', 'permission' => 'plugin.quiz.workspace.access'];
$_GET = [];
require $argv[1];
PHP;
$process = proc_open([PHP_BINARY, '-r', $unknownRouteScript, $pluginRoot . '/admin/_init.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$unknownRouteOutput = '';
if (is_resource($process)) {
    $unknownRouteOutput = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
$check(str_contains($unknownRouteOutput, 'Quiz route permission mismatch'), 'unknown authoritative route policy must fail closed before the permission guard');
$sensitiveGuards = [
    'admin/index.php' => 'plugin.quiz.dashboard.access',
    'admin/settings/index.php' => 'plugin.quiz.settings.manage',
    'admin/student/index.php' => 'plugin.quiz.students.manage',
    'admin/quizzes/set_primary.php' => 'plugin.quiz.primary.manage',
    'admin/quizzes/collaborators.php' => 'plugin.quiz.content.manage-any',
    'admin/quizzes/add.php' => 'plugin.quiz.quizzes.manage',
    'admin/quizzes/edit.php' => 'plugin.quiz.quizzes.manage',
    'admin/quizzes/delete.php' => 'plugin.quiz.quizzes.manage',
    'admin/quizzes/duplicate.php' => 'plugin.quiz.quizzes.manage',
    'admin/quizzes/bulk_status.php' => 'plugin.quiz.quizzes.manage',
    'admin/student/attempts/index.php' => 'plugin.quiz.attempts.view',
    'admin/student/attempts/view.php' => 'plugin.quiz.attempts.view',
    'admin/student/attempts/export.php' => 'plugin.quiz.attempts.export',
    'admin/student/attempts/action_generate_otp.php' => 'plugin.quiz.attempts.operate',
    'admin/student/attempts/action_update_delivery_mode.php' => 'plugin.quiz.attempts.operate',
    'admin/student/attempts/action_verify_manual.php' => 'plugin.quiz.attempts.operate',
    'admin/student/attempts/action_reset.php' => 'plugin.quiz.attempts.operate',
    'admin/student/attempts/action_delete.php' => 'plugin.quiz.attempts.delete',
    'admin/master/api/_init.php' => 'plugin.quiz.workspace.access',
];
foreach ($sensitiveGuards as $relative => $permission) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, "define('QUIZ_REQUIRED_PERMISSION', '{$permission}')"), "{$relative} must bind its exact repeated permission guard");
}
$attemptActionGuards = [
    'action_generate_otp.php' => 'plugin.quiz.attempts.operate',
    'action_update_delivery_mode.php' => 'plugin.quiz.attempts.operate',
    'action_verify_manual.php' => 'plugin.quiz.attempts.operate',
    'action_reset.php' => 'plugin.quiz.attempts.operate',
    'action_delete.php' => 'plugin.quiz.attempts.delete',
];
foreach ($attemptActionGuards as $file => $permission) {
    $source = (string)file_get_contents($pluginRoot . '/admin/student/attempts/' . $file);
    $check(str_contains($source, "REQUEST_METHOD") && str_contains($source, "'POST'"), "{$file} must reject non-POST attempt mutations");
    $check(str_contains($source, 'quiz_csrf_check()') || (str_contains($source, 'csrf_token') && str_contains($source, 'hash_equals(')), "{$file} must enforce CSRF");
    $transactionPosition = strpos($source, 'beginTransaction()');
    $lockPosition = strpos($source, "qz_lock_attempt_for_mutation(\$pdo");
    $permissionPosition = strpos($source, "'{$permission}'", $lockPosition === false ? 0 : $lockPosition);
    $check($transactionPosition !== false && $lockPosition !== false && $permissionPosition !== false
        && $transactionPosition < $lockPosition, "{$file} must lock and reauthorize with exactly {$permission} inside its transaction");
}
$menuSource = $pluginSource;
$check(str_contains($menuSource, 'plugin_route_is_allowed') && !str_contains($menuSource, "SESSION['user_role']"), 'custom Quiz navigation uses target route authorization');
$menuLabels = ["'label' => 'Dashboard'", "'label' => 'Quiz'", "'label' => 'Master Builder'", "'label' => 'Students'", "'label' => 'Attempts'", "'label' => 'Settings'"];
$menuPositions = array_map(static fn(string $label): int|false => strpos($menuSource, $label), $menuLabels);
$check(!in_array(false, $menuPositions, true) && $menuPositions === array_values($menuPositions)
    && $menuPositions === array_values(array_unique($menuPositions))
    && $menuPositions === (function (array $positions): array { $sorted = $positions; sort($sorted); return $sorted; })($menuPositions)
    && !str_contains($menuSource, 'Tambah Quiz (Old)'), 'Quiz sidebar must follow Dashboard, Quiz, Master Builder, Students, Attempts, and Settings without legacy labels');
$check(str_contains($menuSource, "'prefixes' => ['admin/quiz/master', 'admin/quiz/categories', 'admin/quiz/passages', 'admin/quiz/questions', 'admin/quiz/dump']")
    && str_contains($menuSource, "str_starts_with(\$requested, \$pagePrefix . '/')"), 'Quiz sidebar must keep the correct workflow section active on child routes');
$check(str_contains($initSource, 'function qz_admin_breadcrumb(array $items)')
    && str_contains($adminCss, '.qz-breadcrumb')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/index.php'), "['label'=>'Dashboard']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/quizzes/index.php'), "['label'=>'Quiz']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/master/index.php'), "['label'=>'Master Builder']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/student/index.php'), "['label'=>'Students']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/student/attempts/index.php'), "['label'=>'Attempts']")
    && str_contains($settingsSource, "['label'=>'Quiz Settings']"), 'primary Quiz workflow pages must expose consistent shared breadcrumbs');
$check(!str_contains($settingsSource, 'qz-menu-card')
    && !str_contains($settingsSource, '$stats[')
    && str_contains($settingsSource, 'Manage participant appearance, the Test Number format, verification channels'), 'Config must contain settings only instead of duplicating the Quiz dashboard');

$dumpSource = (string)file_get_contents($pluginRoot . '/admin/dump/index.php');
$check(str_contains($dumpSource, 'cms_sanitize_restricted_html') && !str_contains($dumpSource, 'javascript:'), 'Dump QC uses the Core HTML sanitizer');
$duplicateSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/duplicate.php');
$check(str_contains($duplicateSource, '$catMap[$oldCategory]'), 'quiz duplication remaps question category IDs');
$check(str_contains($duplicateSource, "':created_by' => \$uid") && !str_contains($duplicateSource, "':created_by' => (int)\$srcQuiz['created_by']"), 'duplicated quizzes must belong to the actor');
$check(str_contains($duplicateSource, 'qz_lock_quiz_for_workspace_mutation'), 'duplication must lock and re-authorize the source quiz');
$quizEditSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/edit.php');
$quizDeleteSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/delete.php');
$quizAddSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/add.php');
$check(str_contains($quizAddSource, 'name="result_visibility"')
    && str_contains($quizEditSource, 'name="result_visibility"')
    && str_contains($quizAddSource, 'Hidden disables the Student results page.')
    && str_contains($quizEditSource, 'Hidden disables the Student results page.'),
    'quiz add/edit must expose and explain participant result visibility controls');
$check(str_contains($quizAddSource, 'name="public_prefix"')
    && str_contains($quizEditSource, 'name="public_prefix"')
    && str_contains($quizAddSource, 'quiz_validate_public_prefix($pdo, $public_prefix)')
    && str_contains($quizEditSource, 'quiz_validate_public_prefix(')
    && str_contains($quizAddSource, 'title, public_prefix, description')
    && str_contains($quizEditSource, 'public_prefix = :public_prefix')
    && str_contains($duplicateSource, 'title, public_prefix, description')
    && str_contains($duplicateSource, '(:created_by, :title, NULL, :description'),
    'Quiz management must validate and persist unique prefixes while duplicated Quizzes start without a public route');
$globalProductCopy = $quizAddSource . $quizEditSource . $readmeSource;
$check(substr_count($globalProductCopy, 'example-quiz') >= 4
    && str_contains($readmeSource, 'Advanced Assessment Readiness')
    && str_contains($readmeSource, '`assessment` or `training`'),
    'global Quiz documentation and public-prefix guidance must remain organization-neutral');
$check(str_contains($quizAddSource, "define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.quizzes.manage')")
    && str_contains($quizAddSource, "\$user['can_manage_quizzes']"), 'quiz creation must repeat and evaluate exactly quizzes.manage');
$check(strpos($quizAddSource, 'beginTransaction()') < strpos($quizAddSource, 'qz_require_workspace_mutation_access(')
    && str_contains($quizAddSource, "INSERT INTO quizzes (created_by")
    && str_contains($quizAddSource, "':uid' => \$uid"), 'quiz creation must reauthorize workspace access and stamp the actor inside its transaction');
$check(str_contains($quizEditSource, 'qz_lock_quiz_for_workspace_mutation'), 'quiz edit mutation must authorize owner or collaborator against a locked quiz');
$check(str_contains($quizDeleteSource, 'qz_lock_quiz_for_owner_mutation'), 'quiz deletion remains owner-or-manage-any only');
foreach ([
    'quiz edit' => [$quizEditSource, 'qz_lock_quiz_for_workspace_mutation('],
    'quiz delete' => [$quizDeleteSource, 'qz_lock_quiz_for_owner_mutation('],
    'quiz duplicate' => [$duplicateSource, 'qz_lock_quiz_for_workspace_mutation('],
] as $label => [$source, $authorizationHelper]) {
    $transactionPosition = strpos($source, 'beginTransaction()');
    $authorizationPosition = strpos($source, $authorizationHelper);
    $check($transactionPosition !== false && $authorizationPosition !== false
        && $transactionPosition < $authorizationPosition, "{$label} must reauthorize owner/collaborator access against a locked quiz in the transaction");
    $check(!str_contains($source, "\$user['can_manage_any']"), "{$label} mutation must not rely on cached cross-owner authority");
}
$check(!str_contains($quizEditSource, 'rowCount() !== 1') && str_contains($quizEditSource, 'rowCount() > 1'), 'quiz edit must accept an authorized no-op while rejecting impossible multi-row updates');
$check(str_contains($quizDeleteSource, 'rowCount() !== 1') && str_contains($quizDeleteSource, 'rollBack()'), 'quiz delete must reject zero-row deletes instead of reporting success');
$check(str_contains($duplicateSource, '$insQuiz->rowCount() !== 1'), 'quiz duplicate must verify that the actor-owned copy was inserted');
$collaboratorSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/collaborators.php');
$check(str_contains($collaboratorSource, "define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.content.manage-any')"), 'collaborator page must repeat its exact manage-any guard');
$check(str_contains($collaboratorSource, "REQUEST_METHOD'] ?? 'GET') === 'POST'")
    && str_contains($collaboratorSource, 'csrf_check('), 'collaborator display must use GET and saves must use POST with CSRF');
$check(strpos($collaboratorSource, 'beginTransaction()') < strpos($collaboratorSource, "user_can(\$pdo, \$actorId, 'plugin.quiz.content.manage-any')"), 'collaborator save must reauthorize manage-any inside its transaction');
$check(str_contains($collaboratorSource, 'FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE')
    && str_contains($collaboratorSource, 'FROM quiz_collaborators WHERE quiz_id=:quiz_id FOR UPDATE')
    && str_contains($collaboratorSource, 'FROM users WHERE id IN')
    && str_contains($collaboratorSource, 'FOR UPDATE'), 'collaborator save must lock the quiz, assignments, and target users');
$check(str_contains($collaboratorSource, "is_deleted'] !== 0") && str_contains($collaboratorSource, "is_locked'] !== 0")
    && str_contains($collaboratorSource, "user_can(\$pdo, \$id, 'plugin.quiz.workspace.access')"), 'collaborator save must reject unavailable users and require effective workspace access');
$check(str_contains($collaboratorSource, "in_array((int)\$lockedQuiz['created_by'], \$selectedIds, true)")
    && str_contains($collaboratorSource, 'The Quiz owner cannot be assigned as a collaborator.'), 'collaborator save must reject redundant owner assignment');
$check(str_contains($collaboratorSource, "\$_POST['collaborator_ids']")
    && str_contains($collaboratorSource, 'name="collaborator_ids[]"')
    && str_contains($collaboratorSource, 'foreach ($selectedIds as $id) $insert->execute'), 'collaborator management must persist multiple users per quiz');
$check(str_contains($quizIndex, "'can_manage_collaborators' => \$user['can_manage_any']")
    && str_contains($adminJs, 'd.can_manage_collaborators'), 'quiz list must expose Collaborators only to manage-any actors');

$ownerStamping = [
    'admin/categories/add.php' => ['INSERT INTO question_categories (quiz_id,created_by', "':created_by'=>(int)\$user['id']"],
    'admin/passages/add.php' => ['INSERT INTO question_passages (quiz_id,created_by', "':created_by'=>(int)\$user['id']"],
    'admin/questions/index.php' => ['INSERT INTO questions (quiz_id,created_by', "':created_by'=>(int)\$user['id']"],
    'admin/master/api/category_save.php' => ['INSERT INTO question_categories (quiz_id, created_by', "':created_by'=>(int)\$user['id']"],
    'admin/master/api/passage_save.php' => ['INSERT INTO question_passages (quiz_id, created_by', "':created_by'=>(int)\$user['id']"],
    'admin/master/api/question_save.php' => ['INSERT INTO questions (quiz_id, created_by', "':created_by'=>(int)\$user['id']"],
    'admin/master/api/bulk_import.php' => ["['quiz_id','created_by'", "':created_by'=>(int)\$user['id']"],
];
foreach ($ownerStamping as $relative => [$insertNeedle, $actorNeedle]) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, $insertNeedle) && str_contains($source, $actorNeedle), "{$relative} must stamp new resources with the current actor");
}
$check(substr_count($duplicateSource, "':created_by' => \$uid") >= 4, 'quiz duplication must restamp the quiz, categories, passages, and questions to the actor');

$transactionLocks = [
    'admin/categories/edit.php' => 'qz_lock_category_for_mutation(',
    'admin/categories/delete.php' => 'qz_lock_category_for_mutation(',
    'admin/master/api/category_save.php' => 'qz_lock_category_for_mutation(',
    'admin/master/api/category_delete.php' => 'qz_lock_category_for_mutation(',
    'admin/master/api/category_order.php' => 'lock_order_collection(',
    'admin/passages/edit.php' => 'qz_lock_passage_for_mutation(',
    'admin/passages/delete.php' => 'qz_lock_passage_for_mutation(',
    'admin/master/api/passage_save.php' => 'qz_lock_passage_for_mutation(',
    'admin/master/api/passage_delete.php' => 'qz_lock_passage_for_mutation(',
    'admin/master/api/passages_reorder.php' => 'lock_order_collection(',
    'admin/questions/index.php' => 'qz_lock_question_for_mutation(',
    'admin/questions/bulk.php' => 'qz_lock_question_for_mutation(',
    'admin/master/api/question_save.php' => 'qz_lock_question_for_mutation(',
    'admin/master/api/question_delete.php' => 'qz_lock_question_for_mutation(',
    'admin/master/api/questions_reorder.php' => 'lock_order_collection(',
    'admin/master/api/answer_save.php' => 'qz_lock_answer_for_mutation(',
    'admin/master/api/answer_delete.php' => 'qz_lock_answer_for_mutation(',
    'admin/master/api/answers_reorder.php' => 'qz_lock_answer_for_mutation(',
];
foreach ($transactionLocks as $relative => $helper) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $transactionPosition = strpos($source, 'beginTransaction()');
    $lockPosition = strpos($source, $helper);
    $check($transactionPosition !== false && $lockPosition !== false && $transactionPosition < $lockPosition,
        "{$relative} must acquire its resource lock after starting a transaction");
}
$check(str_contains($initSource, 'function qz_lock_answer_for_mutation')
    && strpos($initSource, 'qz_lock_question_for_mutation($pdo, $quizId, $questionId, $userId)')
        < strpos($initSource, "SELECT * FROM question_answers WHERE id=:id AND question_id=:question_id LIMIT 1 FOR UPDATE"),
    'answer mutation ownership must be inherited from a locked, authorized question');

foreach ([
    'admin/categories/delete.php' => ['question_passages', 'questions', 'milik kolaborator lain'],
    'admin/master/api/category_delete.php' => ['question_passages', 'questions', 'owned by another collaborator'],
    'admin/passages/delete.php' => ['questions', 'FOR UPDATE', 'milik kolaborator lain'],
    'admin/master/api/passage_delete.php' => ['questions', 'FOR UPDATE', 'owned by another collaborator'],
] as $relative => $needles) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(array_reduce($needles, static fn(bool $ok, string $needle): bool => $ok && str_contains($source, $needle), true)
        && str_contains($source, 'created_by'), "{$relative} must reject deletion when foreign-owned dependents are locked");
}

$apiInitSource = (string)file_get_contents($pluginRoot . '/admin/master/api/_init.php');
$check(str_contains($apiInitSource, 'count($ids) !== count(array_unique($ids))')
    && str_contains($apiInitSource, "jfail('Duplicate ids are not allowed', 422)"), 'all reorder APIs must reject duplicate IDs');
$check(str_contains($apiInitSource, 'function order_revision')
    && str_contains($apiInitSource, "hash('sha256'")
    && str_contains($apiInitSource, 'function required_order_revision'), 'reorder APIs must use validated deterministic collection revisions');
$check(str_contains($apiInitSource, 'function lock_order_collection')
    && str_contains($apiInitSource, 'ORDER BY id ASC FOR UPDATE')
    && str_contains($apiInitSource, 'function order_members_match'), 'reorder APIs must lock and compare the complete ordered collection');
$check(str_contains($apiInitSource, 'function order_conflict')
    && str_contains($apiInitSource, "409, ['code' => 'order_conflict']"), 'stale reorder requests must roll back with a stable 409 conflict code');
foreach ([
    'admin/master/api/category_order.php' => ['categories', true],
    'admin/master/api/passages_reorder.php' => ['passages', true],
    'admin/master/api/questions_reorder.php' => ['questions', true],
    'admin/master/api/answers_reorder.php' => ['answers', false],
] as $relative => [$resource, $checksEveryOwner]) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, 'ordered_positive_ids(')
        && str_contains($source, "lock_order_collection(\$pdo, '{$resource}'")
        && str_contains($source, 'order_members_match('), "{$relative} must reject duplicate, omitted, and foreign collection members");
    $check(str_contains($source, 'required_order_revision(')
        && str_contains($source, 'hash_equals(order_revision($rows), $expectedRevision)')
        && str_contains($source, 'order_conflict($pdo)'), "{$relative} must reject stale order revisions before updating rows");
    if ($checksEveryOwner) {
        $check(str_contains($source, 'foreach ($rows as $row)')
            && str_contains($source, 'qz_can_mutate_resource('), "{$relative} must authorize every member of the globally ordered collection");
    } else {
        $check(str_contains($source, 'qz_lock_answer_for_mutation('), "{$relative} must inherit answer ownership from its locked question");
    }
}
$check(substr_count($masterBuilder, 'order_revision:') >= 4
    && str_contains($masterBuilder, 'async function persistOrder(')
    && str_contains($masterBuilder, "error.status === 409")
    && str_contains($masterBuilder, "error.code === 'order_conflict'"), 'Master Builder must submit revisions and reload canonical state after stale reorder conflicts');
foreach ([
    'admin/master/api/categories_list.php',
    'admin/master/api/passages_list.php',
    'admin/master/api/questions_list.php',
    'admin/master/api/question_get.php',
] as $relative) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, 'order_revision('), "{$relative} must expose a canonical order revision");
}
$check(str_contains($initSource, 'function qz_append_sort_order')
    && str_contains($initSource, "return \$resource === 'answers' ? 0 : null"), 'creates must use a transaction-bound tail sentinel without rewriting foreign-owned order values');
foreach ([
    'admin/categories/add.php' => 'categories',
    'admin/passages/add.php' => 'passages',
    'admin/questions/index.php' => 'questions',
] as $relative => $resource) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, "qz_append_sort_order(\$pdo, '{$resource}'")
        && !str_contains($source, 'name="sort_order"'), "{$relative} must append new records without accepting manual sort input");
}
foreach (['admin/categories/edit.php', 'admin/passages/edit.php'] as $relative) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(!str_contains($source, "\$_POST['sort_order']")
        && !str_contains($source, 'name="sort_order"'), "{$relative} must preserve order and direct users to Master Builder");
}
$check(!str_contains((string)file_get_contents($pluginRoot . '/admin/categories/delete.php'), "\$user['can_manage_any']")
    && !str_contains((string)file_get_contents($pluginRoot . '/admin/passages/delete.php'), "\$user['can_manage_any']"), 'dependent deletes must not trust cached manage-any authority');
$dumpSource = (string)file_get_contents($pluginRoot . '/admin/dump/index.php');
$legacyAnswerFiles = glob($pluginRoot . '/admin/answers/*.php') ?: [];
$check($legacyAnswerFiles === []
    && !str_contains($manifestJson, 'admin/quiz/answers')
    && !str_contains($initSource, 'admin/quiz/answers')
    && !str_contains($menuSource, 'admin/quiz/answers'), 'legacy answer CRUD routes and files must remain removed');
$check(str_contains($dumpSource, 'Edit Soal &amp; Jawaban')
    && !str_contains($dumpSource, 'admin/quiz/answers')
    && !str_contains($dumpSource, '(Old)'), 'Dump QC must expose only the aggregate question-and-answer editor');
$legacyQuestions = (string)file_get_contents($pluginRoot . '/admin/questions/index.php');
$check(str_contains($legacyQuestions, 'SELECT COUNT(*) FROM questions q WHERE {$whereSql}')
    && str_contains($legacyQuestions, 'LIMIT {$perPage} OFFSET {$offset}')
    && str_contains($legacyQuestions, "\$_GET['p']"), 'legacy question list must use filtered server-side pagination');
$check(str_contains($legacyQuestions, 'class="qz-filterbar"')
    && str_contains($legacyQuestions, 'name="type"')
    && str_contains($legacyQuestions, 'name="status"')
    && str_contains($legacyQuestions, 'name="category_id"')
    && str_contains($legacyQuestions, 'name="passage_id"')
    && str_contains($legacyQuestions, 'name="author_id"'), 'legacy question list must expose search, taxonomy, author, type, and status filters');
$check(str_contains($legacyQuestions, '$canViewAllContentMetadata')
    && str_contains($legacyQuestions, 'created_by=:filter_created_by')
    && str_contains($legacyQuestions, '$canShowContext && !empty($qq[\'category_name\'])'), 'contributor filters and foreign question metadata must not expose categories owned by other contributors');
$check(str_contains($legacyQuestions, "!qz_can_mutate_resource(\$pdo, (int)\$user['id'], \$currentQuiz, \$resource)")
    && str_contains($legacyQuestions, 'Kategori tidak tersedia untuk contributor ini.'), 'question saves must reject forged foreign category or passage assignments');
$filterPassagesSource = (string)file_get_contents($pluginRoot . '/admin/master/api/filter_passages.php');
$check(str_contains($legacyQuestions, 'data-passages-url=')
    && !str_contains($legacyQuestions, 'data-category-id=')
    && str_contains($adminJs, "document.querySelectorAll('[data-question-category-filter]')")
    && str_contains($adminJs, "fetch(url.toString()"), 'question passage filter must fetch only the selected category instead of preloading hidden options');
$check(str_contains($filterPassagesSource, "require_quiz_exists(\$quizId)")
    && str_contains($filterPassagesSource, "category_id IS NULL")
    && str_contains($filterPassagesSource, "category_id=:category_id")
    && str_contains($filterPassagesSource, "created_by=:created_by"), 'passage filter endpoint must scope results by quiz, category branch, and contributor ownership');
$check(str_contains($legacyQuestions, 'CASE WHEN p.id IS NOT NULL THEN p.category_id ELSE q.category_id END')
    && !str_contains($legacyQuestions, "title=\"Contributor\">Oleh ")
    && !str_contains($legacyQuestions, "title=\"Urutan global\">Urutan #"), 'question metadata must show direct or passage categories with compact contributor and position chips');
$check(str_contains($legacyQuestions, 'class="qz-table qz-question-table"')
    && str_contains($adminCss, '.qz-question-table tbody tr')
    && str_contains($adminCss, 'grid-template-columns:1fr 1fr'), 'legacy question list must become responsive cards instead of a wide mobile table');
$check(str_contains($legacyQuestions, 'qz-action-menu--compact')
    && str_contains($legacyQuestions, 'question-actions-toggle-')
    && str_contains($legacyQuestions, 'qz-action-menu__item--danger'), 'legacy question actions must use the accessible overflow menu');
$check(str_contains($legacyQuestions, 'data-question-aggregate-editor')
    && str_contains($legacyQuestions, 'data-answer-list')
    && !str_contains($legacyQuestions, 'page=admin/quiz/answers/index'), 'legacy question edit must aggregate answers instead of linking to a separate answer workflow');
$check(str_contains($legacyQuestions, "in_array(\$do, ['add', 'edit'], true)")
    && str_contains($legacyQuestions, 'data-question-is-new=')
    && str_contains($legacyQuestions, 'data-question-edit-url=')
    && str_contains($legacyQuestions, '>+ Tambah Soal</a>')
    && !str_contains($legacyQuestions, '+ Tambah Cepat'), 'new questions must open the complete aggregate editor instead of the quick-add form');
$check(str_contains($adminJs, 'questionId = createdId;')
    && str_contains($adminJs, "window.history.replaceState({}, '', questionEditUrl.replace('__QUESTION_ID__'")
    && str_contains($adminJs, 'if (answerAdd) answerAdd.disabled = false;')
    && str_contains($adminJs, "if (questionId <= 0) return;"), 'the first AJAX autosave must promote a new question into edit mode before answers can be added');
$check(str_contains($legacyQuestions, 'class="qz-live-preview qz-live-preview--question"')
    && substr_count($legacyQuestions, 'class="qz-live-preview qz-live-preview--answer"') === 2
    && str_contains($legacyQuestions, '<span>Preview soal</span><small>Tampilan peserta</small>')
    && str_contains($legacyQuestions, '<span>Preview jawaban</span><small>Tampilan peserta</small>'), 'legacy aggregate editor must share the participant-preview presentation for questions and answers');
$check(str_contains($legacyQuestions, '<details class="qz-editor-section qz-editor-disclosure" data-question-attachments')
    && str_contains($legacyQuestions, 'data-question-attachment-state')
    && str_contains($adminCss, '.qz-editor-disclosure__summary{')
    && str_contains($adminJs, 'function syncQuestionAttachmentState()')
    && str_contains($adminJs, "attachmentState.textContent = count > 0 ? count + ' terisi' : 'Belum ada'"), 'legacy question attachments must use a native disclosure with live populated-state feedback');
$check(str_contains($legacyQuestions, 'class="qz-save-guide" role="note"')
    && str_contains($legacyQuestions, 'Autosave aktif')
    && str_contains($legacyQuestions, 'data-question-save-url=')
    && !str_contains($legacyQuestions, 'Simpan Perubahan Soal')
    && substr_count($legacyQuestions, 'data-answer-save hidden') === 2
    && substr_count($legacyQuestions, 'class="adam-button ghost qz-save-retry"') === 3
    && substr_count($legacyQuestions, "svg_ico('save')") === 3
    && substr_count($legacyQuestions, 'aria-label="Coba simpan lagi"') === 3
    && !str_contains($legacyQuestions, '>Coba lagi</button>')
    && str_contains($adminCss, '.qz-save-retry svg{width:16px;height:16px}')
    && str_contains($legacyQuestions, 'disimpan otomatis per baris melalui AJAX')
    && str_contains($adminJs, 'function scheduleQuestionAutosave()')
    && str_contains($adminJs, 'function scheduleAnswerAutosave()')
    && substr_count($adminJs, 'setTimeout(saveQuestionNow') >= 2
    && substr_count($adminJs, 'setTimeout(saveAnswerNow') >= 2
    && str_contains($adminJs, "window.addEventListener('beforeunload'")
    && str_contains($adminCss, '.qz-inline-answer__status[data-state="dirty"]'), 'legacy aggregate editor must autosave questions and answers through AJAX while exposing pending, saved, and error states');
$check(str_contains($legacyQuestions, 'name="image_media_id"')
    && str_contains($legacyQuestions, 'name="file_id"')
    && str_contains($legacyQuestions, 'name="youtube_url"')
    && str_contains($legacyQuestions, 'name="passage_id"')
    && str_contains($legacyQuestions, 'name="category_id"'), 'legacy question edit must expose the complete Master attachment and context fields');
$check(str_contains($legacyQuestions, 'UPDATE questions SET question_text=:qt,question_type=:qtype,is_active=:act,image_media_id=:mid,file_id=:fid,youtube_url=:yt,passage_id=:pid,passage_seq=:pseq,category_id=:cid')
    && str_contains($legacyQuestions, "REQUEST_METHOD'] === 'POST' && !\$canEditQuestion"), 'legacy aggregate save must persist complete question state while keeping foreign resources read-only');
$check(str_contains($adminJs, "document.querySelectorAll('[data-question-aggregate-editor]')")
    && str_contains($adminJs, 'renderQuestionMarkdown(')
    && str_contains($adminJs, 'data-answer-save-url')
    && str_contains($adminJs, 'openQuestionSelector('), 'aggregate question editor must initialize Math preview, inline answers, and attachment selectors');
$questionBulk = (string)file_get_contents($pluginRoot . '/admin/questions/bulk.php');
$check(str_contains($legacyQuestions, 'data-question-bulk-form')
    && str_contains($legacyQuestions, 'data-question-bulk-item')
    && !str_contains($legacyQuestions, '<th width="70">ID</th>'), 'question list must replace confusing record IDs with current-page bulk selection');
$check(str_contains($adminJs, "document.querySelectorAll('[data-question-bulk-form]')")
    && str_contains($adminJs, "activate:'mengaktifkan'")
    && str_contains($adminJs, 'NewNotifConfirm.danger'), 'question bulk controls must synchronize selection and confirm destructive actions');
$check(str_contains($questionBulk, "count(\$ids) > 100")
    && str_contains($questionBulk, "['activate', 'deactivate', 'delete']")
    && str_contains($questionBulk, 'qz_lock_question_for_mutation('), 'question bulk mutation must cap, allowlist, and authorize every selected question');
$check(str_contains($legacyQuestions, 'data-question-column-picker')
    && str_contains($legacyQuestions, 'data-question-column="type"')
    && str_contains($legacyQuestions, 'data-column="type" hidden'), 'question list must hide Type by default and expose optional column controls');
$check(str_contains($adminJs, 'quiz-question-columns-v1')
    && str_contains($adminJs, 'localStorage.setItem(storageKey')
    && str_contains($adminCss, '[data-column][hidden]'), 'question column preferences must persist while required columns remain server-rendered');
$check(strpos($questionBulk, 'beginTransaction()') < strpos($questionBulk, 'qz_lock_question_for_mutation(')
    && str_contains($questionBulk, 'sort($ids, SORT_NUMERIC)'), 'question bulk mutation must lock selected questions in deterministic order inside one transaction');
$check(str_contains($masterBuilder, 'async function flushPendingAnswerSaves()')
    && str_contains($masterBuilder, 'questionId + \':\' + Number(answerId)')
    && str_contains($masterBuilder, 'await flushPendingAnswerSaves();'), 'answer autosaves must be serialized by question and flushed before canonical reloads');
$check(str_contains($masterBuilder, 'moveCategoryByStep(')
    && str_contains($masterBuilder, 'movePassageByStep(')
    && str_contains($masterBuilder, 'moveAnswerByStep('), 'non-question ordering must provide touch and keyboard-accessible step controls');
$check(str_contains((string)file_get_contents($pluginRoot . '/admin/master/api/category_delete.php'), '$currentQuizOwner')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/master/api/passage_delete.php'), '$currentQuizOwner'), 'Master dependent deletes must evaluate current locked quiz ownership');
$questionSaveSource = (string)file_get_contents($pluginRoot . '/admin/master/api/question_save.php');
$check(str_contains($questionSaveSource, 'clone_from_question_id')
    && strpos($questionSaveSource, 'qz_lock_question_for_mutation($pdo, $quiz_id, $clone_from')
        < strpos($questionSaveSource, 'SELECT answer_text, score, sort_order, is_active FROM question_answers'),
    'question duplication must reject a foreign-owned source before copying inherited answers');

$metadataSources = [
    'questions list' => 'admin/master/api/questions_list.php',
    'question detail and inherited answers' => 'admin/master/api/question_get.php',
    'categories list' => 'admin/master/api/categories_list.php',
    'category detail' => 'admin/master/api/category_get.php',
    'passages list' => 'admin/master/api/passages_list.php',
    'passage detail' => 'admin/master/api/passage_get.php',
];
foreach ($metadataSources as $label => $relative) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, "['can_mutate'] = qz_api_can_mutate"), "{$label} must expose can_mutate metadata");
}
$check(str_contains($masterBuilder, 'activeQ.can_mutate === true')
    && str_contains($masterBuilder, 'activeP.can_mutate === true')
    && str_contains($masterBuilder, 'activeC.can_mutate === true')
    && str_contains($masterBuilder, 'questions.every(q=>q.can_mutate === true)')
    && str_contains($masterBuilder, 'categories.every(c=>c.can_mutate === true)'), 'Master Builder must enforce can_mutate for controls and drag metadata');
$check(str_contains((string)file_get_contents($pluginRoot . '/admin/categories/index.php'), 'qz_can_mutate_resource(')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/passages/index.php'), 'qz_can_mutate_resource(')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/questions/index.php'), 'qz_can_mutate_resource('), 'legacy question-bank UI must hide mutations for foreign-owned resources');

$otpGenerate = (string)file_get_contents($pluginRoot . '/admin/student/attempts/action_generate_otp.php');
$otpReset = (string)file_get_contents($pluginRoot . '/admin/student/attempts/action_reset.php');
$check(str_contains($otpGenerate, "qz_flash_set('msg'") && str_contains($otpReset, "qz_flash_set('msg'"), 'new OTP values use session flash messages');
$check(!str_contains($otpGenerate, "'&msg='") && !str_contains($otpReset, "'&msg='"), 'new OTP values never enter redirect URLs');
$check(str_contains($otpGenerate, "status'] ?? '') !== 'registered'")
    && !str_contains($otpGenerate, "status = 'registered'")
    && !str_contains($otpGenerate, 'started_at = NULL')
    && !str_contains($otpGenerate, 'submitted_at = NULL'), 'OTP rotation must not reopen or rewind a non-registered attempt');
$check(str_contains($otpGenerate, 'otp_try_count = 0') && str_contains($otpGenerate, 'otp_locked_at = NULL')
    && str_contains($otpReset, 'otp_try_count = 0') && str_contains($otpReset, 'otp_locked_at = NULL'), 'OTP rotation and reset must clear verifier lockout state');
$check(str_contains($otpReset, "token_hash = ''")
    && str_contains($otpReset, "user_agent_hash = ''")
    && str_contains($otpReset, 'ip_address = NULL'),
    'attempt reset must release the previous participant browser and device binding');
$check(!str_contains($otpGenerate, "'OTP baru: ' . \$otp")
    && !str_contains($otpReset, "'Attempt di-reset. OTP baru: ' . \$otp"), 'plaintext OTP must not be copied into generic session flash messages');

$confirmSource = (string)file_get_contents($pluginRoot . '/public/start/confirm/index.php');
$check(str_contains($confirmSource, 'verification_required_override')
    && str_contains($confirmSource, "verified_via='quiz_override'")
    && str_contains($confirmSource, 'qz_public_enter_quiz($attempt_alias);'),
    'OTP override must atomically verify new attempts and continue with their exact alias');
$check(str_contains($publicSecuritySource, 'function qz_public_post_redirect(string $action, array $fields): never')
    && str_contains($publicSecuritySource, '<form id="qz-auto-start" method="post"')
    && str_contains($publicSecuritySource, "['csrf_token' => qz_public_csrf_token()]")
    && str_contains($confirmSource, 'qz_public_enter_quiz(')
    && str_contains($verifySource, 'qz_public_enter_quiz(')
    && str_contains($takeContext, 'qz_public_post_redirect(')
    && str_contains($publicSecuritySource, "<button type=\"submit\">Start ' . \$safeTitle . '</button>")
    && !str_contains($takeContext, 'Start / Resume Quiz'),
    'normal, direct, and fallback first-start flows must auto-POST while retaining a configurable no-JavaScript start fallback');
$check(str_contains($confirmSource, "\$btnLabel = \$verificationRequired ? 'Start &amp; Get OTP' : 'Continue';")
    && str_contains($confirmSource, "\$btnLabel = \$verificationRequired ? 'Continue to OTP Verification' : 'Continue';")
    && str_contains($confirmSource, "\$verificationSteps = \$verificationRequired")
    && str_contains($confirmSource, "\$confirmationSteps = \$boundQuizId > 0")
    && str_contains($confirmSource, "OTP verification is disabled for this '.\$publicTitleHtml.'."),
    'confirmation copy and progress steps must omit OTP when the per-Quiz override disables verification');
$check(str_contains($helperSource, 'function quiz_public_visual_theme(PDO $pdo, ?int $quizId = null): string')
    && str_contains($helperSource, "quiz_database_setting(\$pdo, 'quiz_visual_theme', 'default')")
    && str_contains($helperSource, 'SELECT visual_theme_override FROM quizzes WHERE id=:id LIMIT 1')
    && str_contains($publicLayoutSource, 'data-quiz-visual-theme=')
    && str_contains($publicLayoutSource, "'quiz-theme-' . quiz_safe_visual_theme(\$visualTheme)")
    && str_contains($registerSource, "'DEDICATED ' . \$publicTitleUpper . ' ACCESS'")
    && str_contains($registerSource, 'Register for ')
    && str_contains($confirmSource, '$quiz_visual_theme_quiz_id = $quiz_id;')
    && str_contains($verifySource, '$quiz_visual_theme_quiz_id = $quiz_id;')
    && str_contains($publicStyleSource, 'body.quiz-theme-studio')
    && str_contains($publicStyleSource, 'body.quiz-theme-navy')
    && str_contains($publicStyleSource, '--qz-highlight:#fcbc55;')
    && str_contains($publicStyleSource, '--qz-shadow: 10px 10px 0 #10162f;'),
    'dedicated registration, confirmation, and verification must carry the resolved Quiz theme and contextual journey UI');
$check(str_contains($takeContext, "'requireFullscreen' => \$quiz['require_fullscreen_override'] === null ? true")
    && str_contains($takeContext, "'devtoolsDetect' => \$quiz['devtools_detect_override'] === null ? true")
    && str_contains($takeContext, "'showBadge' => \$quiz['show_exam_badge_override'] === null ? true")
    && str_contains($takeJs, 'requireFullscreen: {$requireFullscreenJson}')
    && str_contains($takeJs, 'devtoolsDetect: {$devtoolsDetectJson}')
    && str_contains($takeJs, 'showBadge: {$showExamBadgeJson}')
    && str_contains($takeJs, 'if (PROCTOR.showBadge) examBadge.style.display = "block";'),
    'proctor overrides must retain secure defaults and reach the browser configuration');
$check(str_contains($confirmSource, "settings_get(\$pdo, 'quiz_test_number_format'")
    && str_contains($confirmSource, 'SELECT quiz_id FROM quiz_attempts WHERE id=:id LIMIT 1')
    && str_contains($confirmSource, 'quiz_format_test_number($format, $attemptId, $year, $quizId)')
    && str_contains($confirmSource, 'generate_nomor_test($pdo, $attemptId)'), 'new test numbers must use the administrator-defined format');
$check(str_contains($confirmSource, 'name="delivery_mode"') && str_contains($confirmSource, "['online', 'onsite']"), 'participant confirmation must require an allowlisted delivery mode');
$check(str_contains($confirmSource, ':delivery_mode') && str_contains($confirmSource, 'SET delivery_mode=:mode'), 'participant confirmation must persist delivery mode for new and registered attempts');
$modeAction = (string)file_get_contents($pluginRoot . '/admin/student/attempts/action_update_delivery_mode.php');
$check(str_contains($modeAction, "REQUEST_METHOD") && str_contains($modeAction, 'quiz_csrf_check()'), 'delivery mode mutation must require POST and CSRF');
$check(str_contains($modeAction, 'qz_require_attempt_access') && str_contains($modeAction, "['', 'online', 'onsite']"), 'delivery mode mutation must enforce scoped access and an allowlist');
$collaboratorSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/collaborators.php');
$selectedUserLock = strpos($collaboratorSource, 'SELECT id, is_deleted, is_locked FROM users');
$quizAssignmentLock = strpos($collaboratorSource, 'SELECT id, created_by FROM quizzes');
$check($selectedUserLock !== false && $quizAssignmentLock !== false && $selectedUserLock < $quizAssignmentLock, 'collaborator assignment locks selected users before the quiz to match mutation lock order');
$answerSource = (string)file_get_contents($pluginRoot . '/public/api/save_answer.php');
$proctorSource = (string)file_get_contents($pluginRoot . '/public/api/proctor_event.php');
$check(str_contains($answerSource, 'strlen($answerText) > 20000') && str_contains($answerSource, 'count($ids) > 100'), 'participant answers and option arrays have hard size limits');
$check(str_contains($proctorSource, 'INTERVAL 1 MINUTE') && str_contains($proctorSource, '>= 120') && str_contains($proctorSource, 'FOR UPDATE'), 'proctor events are transactionally rate-limited per attempt');
$check(str_contains($confirmSource, 'quiz_public_require_quiz($quiz_id)')
    && str_contains($verifySource, 'quiz_public_require_quiz($quiz_id)')
    && str_contains($takeContext, 'quiz_public_require_quiz($quiz_id)')
    && str_contains($resultSource, 'quiz_public_require_quiz($quizId)')
    && str_contains($sendOtpSource, 'quiz_public_route_accepts_quiz($quiz_id)')
    && str_contains($getQuestionSource, 'quiz_public_route_accepts_quiz($quiz_id)')
    && str_contains($saveAnswerSource, 'quiz_public_route_accepts_quiz($quizId)')
    && str_contains($reviewSource, 'quiz_public_route_accepts_quiz($quizId)')
    && str_contains($submitSource, 'quiz_public_route_accepts_quiz($quiz_id)')
    && str_contains($proctorSource, 'quiz_public_route_accepts_quiz($quiz_id)'),
    'every Quiz-sensitive public page and API must enforce its dedicated route binding');
$publicPhp = '';
$publicIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot . '/public', FilesystemIterator::SKIP_DOTS));
foreach ($publicIterator as $publicFile) {
    if ($publicFile->isFile() && $publicFile->getExtension() === 'php') $publicPhp .= (string)file_get_contents($publicFile->getPathname());
}
$check(preg_match('/[\'\"]\/quiz\//', $publicPhp) !== 1, 'public runtime URLs must use the active Quiz prefix instead of hardcoded /quiz paths');

$destructiveFiles = [
    'admin/quizzes/delete.php', 'admin/quizzes/duplicate.php', 'admin/quizzes/set_primary.php',
    'admin/quizzes/bulk_status.php',
    'admin/categories/delete.php', 'admin/passages/delete.php',
    'admin/questions/bulk.php',
];
foreach ($destructiveFiles as $relative) {
    $source = (string)file_get_contents($pluginRoot . '/' . $relative);
    $check(str_contains($source, "REQUEST_METHOD"), "{$relative} must enforce a request method");
    $check(!str_contains($source, "\$_GET['csrf']"), "{$relative} must not accept CSRF secrets in URLs");
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $status = -1;
    if (is_resource($process)) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    }
    $check($status === 0, 'PHP lint failed: ' . substr($file->getPathname(), strlen($pluginRoot) + 1));
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, count($failures) . " of {$checks} checks failed.\n");
    exit(1);
}

echo "Quiz compatibility contract passed ({$checks} checks, Core {$coreVersion}).\n";
