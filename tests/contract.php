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
$check(($manifest['version'] ?? null) === '1.4.12', 'plugin version must be 1.4.12');
$check(($manifest['plugin_uri'] ?? null) === $repository, 'plugin_uri must use the canonical repository');
$check(($manifest['homepage'] ?? null) === $repository, 'homepage must use the canonical repository');
$check(($manifest['github_url'] ?? null) === $repository, 'github_url must use the canonical repository');
$check(($manifest['store']['url'] ?? null) === 'https://jyavani.com/plugin-store', 'store URL must use the canonical Plugin Store');
$check(($manifest['store']['slug'] ?? null) === 'quiz', 'store slug must match the plugin identity');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.74', 'Jyavani requirement must be >=2.3.74');
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
$check(version_compare($coreVersion, '2.3.74', '>='), 'current Core must be 2.3.74 or newer');

if (!defined('BACKEND_PATH')) define('BACKEND_PATH', $coreRoot . '/cfg');
if (!defined('PLUGIN_PATH')) define('PLUGIN_PATH', dirname($pluginRoot));
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', $coreRoot . '/public');
require_once $coreRoot . '/cfg/helpers/hooks.php';
require_once $coreRoot . '/cfg/helpers/authorization.php';
require_once $coreRoot . '/plugins/index.php';

$check(
    plugin_version_requirement_met('2.3.74', (string)($manifest['requires']['jyavani'] ?? '')),
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
$check(array_keys($permissionDefinitions) === array_keys($permissionPolicies), 'manifest must declare the exact ordered 1.4.12 Quiz permission set');
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
$check($manifestRoutePermissions == $routePermissions, 'manifest must declare the exact 1.4.12 admin route map');
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

$pluginSource = (string)file_get_contents($pluginRoot . '/plugin.php');
$check(str_contains($pluginSource, "\$schemaRevision = '20260821-resource-owners'"), '1.4.12 must use the resource-owner schema revision');
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
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function register_frontend_route(string $prefix, callable|string $handler): void {}
function plugin_manifest(string $name): ?array { return null; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
require $argv[1];
$valid = ['', 'index.php', 'register', 'register/index.php', 'verify', 'verify/index.php', 'start', 'start/index.php',
    'start/confirm', 'start/confirm/index.php', 'take', 'take/index.php', 'reset', 'reset/index.php', 'send_otp.php',
    'api/get_question.php', 'api/proctor_event.php', 'api/save_answer.php', 'api/submit.php'];
foreach ($valid as $route) {
    $file = quiz_frontend_entrypoint($route);
    if (!is_string($file) || !is_file($file)) exit(10);
}
foreach (['../plugin.php', '%2e%2e/plugin.php', 'api/_timer_helper.php', 'layout.php', '/etc/passwd', 'unknown'] as $route) {
    if (quiz_frontend_entrypoint($route) !== null) exit(11);
}
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

$publicIndex = (string)file_get_contents($pluginRoot . '/public/index.php');
$check(str_contains($publicIndex, "header('Location: /quiz/start/', true, 302)"), 'Quiz root must redirect to the public start page');
$check(!str_contains($publicIndex, 'Quiz page not found'), 'Quiz root must not emit a plain-text plugin 404');

$assetScript = <<<'PHP'
$GLOBALS['contract_hooks'] = [];
function add_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['contract_hooks'][$hook][] = $callback; }
function register_frontend_route(string $prefix, callable|string $handler): void {}
function plugin_manifest(string $name): ?array { return ['version' => '1.4.12']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
require $argv[1];
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
if (!str_contains($head, '/static/plugins/quiz/admin.css?v=1.4.12') || str_contains($head, 'quiz.css')) exit(31);
if (!str_contains($head, '/static/plugins/quiz/admin.js?v=1.4.12') || !str_contains($head, ' defer')) exit(32);
if ($footer !== '') exit(34);
$_SERVER['REQUEST_URI'] = '/quiz/register/';
if (!str_contains($render('jy_head'), 'Quiz - Public Frontend Styles')) exit(33);
$_GET = ['page' => 'admin/quizzer'];
$_SERVER['REQUEST_URI'] = '/quizzer/';
if ($render('admin_head') !== '' || $render('jy_head') !== '') exit(35);
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
function register_frontend_route(string $prefix, callable|string $handler): void {}
function plugin_manifest(string $name): ?array { return ['version' => '1.4.12']; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $GLOBALS['contract_settings'][$key] ?? $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { $GLOBALS['contract_settings'][$key] = (string)$value; return true; }
final class ContractPDO extends PDO {
    public bool $fail = true;
    public int $executions = 0;
    public function __construct() {}
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
if (($GLOBALS['contract_settings']['quiz_test_version'] ?? '') !== '1.4.12'
    || ($GLOBALS['contract_settings']['quiz_schema_revision'] ?? '') !== '20260821-resource-owners'
    || $pdo->executions !== 2) exit(22);
quiz_install_schema($pdo);
if ($pdo->executions !== 2) exit(23);
unset($GLOBALS['contract_settings']['quiz_schema_revision']);
quiz_install_schema($pdo);
if (($GLOBALS['contract_settings']['quiz_schema_revision'] ?? '') !== '20260821-resource-owners'
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

$check(!str_contains($pluginSource, "add_action('init'"), 'schema migration must not run on every frontend request');
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
    && str_contains($settingsSource, 'Nomor pengerjaan, wajib')
    && str_contains($settingsSource, 'quiz_normalize_test_number_format($test_number_format)'), 'Quiz settings must explain, validate, and persist the test-number format');
$check(str_contains($settingsSource, "\$otp_email_enabled && \$from_name === ''") && str_contains($settingsSource, "\$otp_wa_enabled && \$admin_wa === ''"), 'OTP configuration fields must be required only for enabled methods');
$check(str_contains($settingsSource, 'qz-method-grid') && !str_contains($settingsSource, '<span class="qz-check-meta">Peserta dapat meminta OTP'), 'OTP method descriptions must use their wrapping card layout');
$check(str_contains($settingsSource, 'class="qz-settings-form"'), 'Quiz settings must expose a component-specific scope for delegated controls');

$verifySource = (string)file_get_contents($pluginRoot . '/public/verify/index.php');
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
$timerHelper = (string)file_get_contents($pluginRoot . '/public/api/_timer_helper.php');
$check(str_contains($takeContext, '$needsPreparation && !$isBeginRequest')
    && str_contains($takeContext, 'qz_public_csrf_check(qz_public_csrf_from_request())')
    && str_contains($takeContext, '$isBeginRequest, $isBeginRequest')
    && str_contains($timerHelper, 'if (!$startIfMissing && !$finalizeIfExpired)'), 'take-page GET is read-only and preparation requires CSRF-protected POST');
$check(substr_count($takeJs, 'input.className = "qt-choice-input";') === 2, 'single and multiple choice controls must share aligned input styling');
$check(str_contains($takeView, '.qt-answers .qt-choice-input{') && str_contains($takeView, 'margin:5px 0 0;'), 'choice controls must align vertically with the 28px answer badge');

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
$check(str_contains($attemptIndex, 'class="qz-resultbar"')
    && str_contains($attemptIndex, 'class="table qz-attempt-table"')
    && str_contains($attemptIndex, 'class="qz-pagination"')
    && str_contains($adminCss, '.qz-attempt-table [data-column][hidden]'), 'Attempt column controls must use the shared result, table, and pagination presentation');
$check(str_contains($attemptIndex, 'title="Mode pelaksanaan dipilih sendiri oleh peserta."')
    && str_contains($attemptIndex, 'title="Metode yang tercatat setelah attempt diverifikasi. Bukan bukti bahwa OTP telah dikirim atau diterima."'), 'Attempt Mode and Verification headers must explain the limits of participant-provided metadata');
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
    && str_contains($attemptExportHelper, "'test_number', 'label'=>'Nomor Test'")
    && !str_contains($attemptExportHelper, "'label'=>'Alias'"), 'attempt export must allowlist visible report groups while retaining required identity and status columns');
$check(str_contains($attemptIndex, 'data-attempt-export-form')
    && str_contains($attemptIndex, 'data-attempt-export-column')
    && str_contains($adminJs, 'syncAttemptExportColumns')
    && str_contains($attemptExport, "qz_attempt_export_columns(\$_POST['columns'] ?? null)"), 'Attempt column preferences must drive server-validated CSV and XLSX export columns');
$attemptExportScript = <<<'PHP'
define('DASHBOARD_CONTEXT', true);
require $argv[1];
$defaults = ['Nama Siswa','No HP','Email','Nomor Test','Quiz','Status','Mode','Verifikasi','Dijawab','Benar','Nilai 100','Aktivitas'];
if (qz_attempt_export_headers() !== $defaults) exit(31);
$custom = qz_attempt_export_headers(['bogus', 'reset', 'rank', 'legacy_score']);
if ($custom !== ['No.','Nama Siswa','No HP','Email','Nomor Test','Status','Nilai Legacy','Reset']) exit(32);
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
    && str_contains($attemptIndex, "Excel tidak tersedia di hosting ini; gunakan CSV."), 'Excel availability must fail gracefully with a visible CSV fallback');
$check(str_contains($attemptExport, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    && str_contains($attemptExport, 'setCellValueExplicit')
    && str_contains($attemptExport, "setTitle('Hasil Quiz')")
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
$check(str_contains($dashboardIndex, 'if ($canManageStudents):') && str_contains($dashboardIndex, 'Buka Siswa'), 'Student Manager card must remain behind students.manage');

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
    && str_contains($studentIndex, 'class="qz-stats"')
    && str_contains($studentIndex, 'class="qz-filterbar qz-student-filterbar"'), 'Student Manager must expose useful summary statistics and a focused filter bar');
$check(str_contains($studentIndex, 'class="qz-table qz-student-table"')
    && str_contains($studentIndex, 'data-label="Student"')
    && str_contains($studentIndex, 'data-label="Kontak"')
    && !str_contains($studentIndex, '<th>Email</th>')
    && !str_contains($studentIndex, '<th>HP</th>'), 'Student Manager must use compact semantic columns with combined contact details');
$check(str_contains($studentIndex, 'data-student-column-picker')
    && str_contains($studentIndex, 'quiz-student-columns-v1')
    && str_contains($adminJs, "document.querySelectorAll('[data-student-column-picker]')")
    && str_contains($adminJs, "wireColumnPicker(picker, '[data-student-column-table]'")
    && str_contains($adminCss, '.qz-student-table tbody tr'), 'Student columns must persist and collapse into responsive cards');
$check(str_contains($studentIndex, 'class="adam-card qz-card qz-editor-page qz-student-editor"')
    && str_contains($studentIndex, 'class="qz-student-form-grid"'), 'Student add and edit forms must use the shared Quiz editor presentation');
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
$check(!str_contains($bulkSource, '$changed < 1') && str_contains($bulkSource, "' quiz diproses; '") && str_contains($bulkSource, "' baris berubah.'"), 'bulk status must accept authorized no-ops with a truthful changed-row message');
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
function quiz_install_schema(PDO $pdo): void {}
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
function quiz_install_schema(PDO $pdo): void {}
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
$menuLabels = ["'label' => 'Dashboard'", "'label' => 'Quiz'", "'label' => 'Master Builder'", "'label' => 'Student'", "'label' => 'Attempt'", "'label' => 'Config'"];
$menuPositions = array_map(static fn(string $label): int|false => strpos($menuSource, $label), $menuLabels);
$check(!in_array(false, $menuPositions, true) && $menuPositions === array_values($menuPositions)
    && $menuPositions === array_values(array_unique($menuPositions))
    && $menuPositions === (function (array $positions): array { $sorted = $positions; sort($sorted); return $sorted; })($menuPositions)
    && !str_contains($menuSource, 'Tambah Quiz (Old)'), 'Quiz sidebar must follow Dashboard, Quiz, Master Builder, Student, Attempt, and Config without legacy labels');
$check(str_contains($menuSource, "'prefixes' => ['admin/quiz/master', 'admin/quiz/categories', 'admin/quiz/passages', 'admin/quiz/questions', 'admin/quiz/dump']")
    && str_contains($menuSource, "str_starts_with(\$requested, \$pagePrefix . '/')"), 'Quiz sidebar must keep the correct workflow section active on child routes');
$check(str_contains($initSource, 'function qz_admin_breadcrumb(array $items)')
    && str_contains($adminCss, '.qz-breadcrumb')
    && str_contains((string)file_get_contents($pluginRoot . '/admin/index.php'), "['label'=>'Dashboard']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/quizzes/index.php'), "['label'=>'Quiz']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/master/index.php'), "['label'=>'Master Builder']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/student/index.php'), "['label'=>'Student']")
    && str_contains((string)file_get_contents($pluginRoot . '/admin/student/attempts/index.php'), "['label'=>'Attempt']")
    && str_contains($settingsSource, "['label'=>'Config']"), 'primary Quiz workflow pages must expose consistent shared breadcrumbs');
$check(!str_contains($settingsSource, 'qz-menu-card')
    && !str_contains($settingsSource, '$stats[')
    && str_contains($settingsSource, 'Atur nomor test, metode OTP, kontak admin'), 'Config must contain settings only instead of duplicating the Quiz dashboard');

$dumpSource = (string)file_get_contents($pluginRoot . '/admin/dump/index.php');
$check(str_contains($dumpSource, 'cms_sanitize_restricted_html') && !str_contains($dumpSource, 'javascript:'), 'Dump QC uses the Core HTML sanitizer');
$duplicateSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/duplicate.php');
$check(str_contains($duplicateSource, '$catMap[$oldCategory]'), 'quiz duplication remaps question category IDs');
$check(str_contains($duplicateSource, "':created_by' => \$uid") && !str_contains($duplicateSource, "':created_by' => (int)\$srcQuiz['created_by']"), 'duplicated quizzes must belong to the actor');
$check(str_contains($duplicateSource, 'qz_lock_quiz_for_workspace_mutation'), 'duplication must lock and re-authorize the source quiz');
$quizEditSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/edit.php');
$quizDeleteSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/delete.php');
$quizAddSource = (string)file_get_contents($pluginRoot . '/admin/quizzes/add.php');
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
$check(str_contains($collaboratorSource, 'owner must not be assigned as a collaborator'), 'collaborator save must reject redundant owner assignment');
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
$check(!str_contains($otpGenerate, "'OTP baru: ' . \$otp")
    && !str_contains($otpReset, "'Attempt di-reset. OTP baru: ' . \$otp"), 'plaintext OTP must not be copied into generic session flash messages');

$confirmSource = (string)file_get_contents($pluginRoot . '/public/start/confirm/index.php');
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
$check(str_contains($answerSource, 'strlen($txt) > 20000') && str_contains($answerSource, 'count($ids) > 100'), 'participant answers and option arrays have hard size limits');
$check(str_contains($proctorSource, 'INTERVAL 1 MINUTE') && str_contains($proctorSource, '>= 120') && str_contains($proctorSource, 'FOR UPDATE'), 'proctor events are transactionally rate-limited per attempt');

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
