<?php
declare(strict_types=1);

defined('DASHBOARD_CONTEXT') || exit;

// Load dashboard authorization helpers if they are not already available.
if (!function_exists('adiwira_require_permission')) {
    $guardFile = defined('DASH_PATH') ? DASH_PATH . '/admin/_guard.php' : __DIR__ . '/../../../dashboard/admin/_guard.php';
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}
if (!function_exists('adiwira_require_permission')) {
    http_response_code(403);
    echo 'Guard function not available';
    exit;
}

if (!function_exists('qz_route_permission')) {
    function qz_route_permission(string $route): ?string {
        $routes = [
            'admin/quiz' => 'plugin.quiz.dashboard.access',
            'admin/quiz/settings' => 'plugin.quiz.settings.manage',
            'admin/quiz/students' => 'plugin.quiz.students.manage',
            'admin/quiz/quizzes/set_primary' => 'plugin.quiz.primary.manage',
            'admin/quiz/quizzes/collaborators' => 'plugin.quiz.content.manage-any',
            'admin/quiz/quizzes/add' => 'plugin.quiz.quizzes.manage',
            'admin/quiz/quizzes/edit' => 'plugin.quiz.quizzes.manage',
            'admin/quiz/quizzes/delete' => 'plugin.quiz.quizzes.manage',
            'admin/quiz/quizzes/duplicate' => 'plugin.quiz.quizzes.manage',
            'admin/quiz/quizzes/bulk_status' => 'plugin.quiz.quizzes.manage',
            'admin/quiz/attempts' => 'plugin.quiz.attempts.view',
            'admin/quiz/attempts/view' => 'plugin.quiz.attempts.view',
            'admin/quiz/attempts/export' => 'plugin.quiz.attempts.export',
            'admin/quiz/attempts/action_generate_otp' => 'plugin.quiz.attempts.operate',
            'admin/quiz/attempts/action_update_delivery_mode' => 'plugin.quiz.attempts.operate',
            'admin/quiz/attempts/action_verify_manual' => 'plugin.quiz.attempts.operate',
            'admin/quiz/attempts/action_reset' => 'plugin.quiz.attempts.operate',
            'admin/quiz/attempts/action_delete' => 'plugin.quiz.attempts.delete',
            'admin/quiz/attempts/api/response_correct' => 'plugin.quiz.attempt-responses.correct',
            'admin/quiz/quizzes' => 'plugin.quiz.workspace.access',
            'admin/quiz/questions' => 'plugin.quiz.workspace.access',
            'admin/quiz/questions/bulk' => 'plugin.quiz.workspace.access',
            'admin/quiz/master' => 'plugin.quiz.workspace.access',
            'admin/quiz/categories/index' => 'plugin.quiz.workspace.access',
            'admin/quiz/categories/add' => 'plugin.quiz.workspace.access',
            'admin/quiz/categories/edit' => 'plugin.quiz.workspace.access',
            'admin/quiz/categories/delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/passages/index' => 'plugin.quiz.workspace.access',
            'admin/quiz/passages/add' => 'plugin.quiz.workspace.access',
            'admin/quiz/passages/edit' => 'plugin.quiz.workspace.access',
            'admin/quiz/passages/delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/dump' => 'plugin.quiz.workspace.access',
            'admin/quiz/dump/export' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/questions_list' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/question_get' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/question_save' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/question_delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/questions_reorder' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/answer_save' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/answer_delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/answers_reorder' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/bulk_import' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/passages_list' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/filter_passages' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/passage_get' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/passage_save' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/passage_delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/passages_reorder' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/categories_list' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/category_get' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/category_save' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/category_delete' => 'plugin.quiz.workspace.access',
            'admin/quiz/master/api/category_order' => 'plugin.quiz.workspace.access',
        ];
        return $routes[trim($route, '/')] ?? null;
    }
}

$quizRouteMetadata = $GLOBALS['_plugin_current_route'] ?? null;
$quizQueryRoute = trim((string)($_GET['page'] ?? ''), '/');
$quizRoute = '';
$quizPermission = null;

if ($quizRouteMetadata !== null) {
    if (is_array($quizRouteMetadata)) {
        $quizRoute = trim((string)($quizRouteMetadata['route'] ?? ''), '/');
        $quizPermission = qz_route_permission($quizRoute);
        $metadataPermission = trim((string)($quizRouteMetadata['permission'] ?? ''));
        if ($quizPermission === null || $metadataPermission !== $quizPermission) {
            $quizPermission = null;
        }
    }
    if ($quizQueryRoute !== '' && $quizQueryRoute !== $quizRoute) {
        $quizPermission = null;
    }
} elseif ($quizQueryRoute !== '') {
    $quizRoute = $quizQueryRoute;
    $quizPermission = qz_route_permission($quizRoute);
}

$requiredPermission = defined('QUIZ_REQUIRED_PERMISSION')
    ? trim((string)constant('QUIZ_REQUIRED_PERMISSION'))
    : null;
if ($quizPermission === null || ($requiredPermission !== null && $requiredPermission !== $quizPermission)) {
    http_response_code(403);
    exit('Quiz route permission mismatch');
}
[$quizUserId] = adiwira_require_permission($pdo, $quizPermission, false);
$GLOBALS['_quiz_actor_id'] = (int)$quizUserId;
if (!quiz_install_schema($pdo)) {
    http_response_code(503);
    exit('Quiz upgrade could not be completed. Please retry.');
}

define('QUIZ_ADMIN', __DIR__);
define('QUIZ_ROOT', dirname(__DIR__));
define('QUIZ_INCLUDES', QUIZ_ROOT . '/includes');

if (!function_exists('qz_redirect')) {
    function qz_redirect(string $url): void {
        if (!headers_sent()) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Location: ' . $url, true, 303);
            exit;
        }
        echo '<script>location.replace(' . json_encode($url) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('qz_admin_breadcrumb')) {
    function qz_admin_breadcrumb(array $items): void {
        $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '';
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        array_unshift($items, ['label' => 'Quiz System', 'url' => $base . '/?page=admin/quiz']);
        echo '<nav class="qz-breadcrumb" aria-label="Breadcrumb"><ol>';
        $last = count($items) - 1;
        foreach ($items as $index => $item) {
            $label = is_array($item) ? trim((string)($item['label'] ?? '')) : trim((string)$item);
            $url = is_array($item) ? trim((string)($item['url'] ?? '')) : '';
            if ($label === '') continue;
            echo '<li>';
            if ($url !== '' && $index !== $last) echo '<a href="' . $escape($url) . '">' . $escape($label) . '</a>';
            else echo '<strong' . ($index === $last ? ' aria-current="page"' : '') . '>' . $escape($label) . '</strong>';
            echo '</li>';
        }
        echo '</ol></nav>';
    }
}

if (!function_exists('quiz_csrf_check')) {
    function quiz_csrf_check(): void {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!csrf_check($token)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token mismatch']);
            exit;
        }
    }
}

if (!function_exists('qz_user_can')) {
    function qz_user_can(PDO $pdo, int $userId, string $permission): bool {
        return $userId > 0 && function_exists('user_can') && user_can($pdo, $userId, $permission);
    }
}

if (!function_exists('qz_can_manage_any')) {
    function qz_can_manage_any(PDO $pdo, int $userId): bool {
        return qz_user_can($pdo, $userId, 'plugin.quiz.content.manage-any');
    }
}

if (!function_exists('qz_require_workspace_mutation_access')) {
    function qz_require_workspace_mutation_access(PDO $pdo, int $userId): void {
        if (!qz_user_can($pdo, $userId, 'plugin.quiz.workspace.access')) {
            throw new RuntimeException('Quiz workspace authorization is no longer valid.');
        }
    }
}

if (!function_exists('qz_quiz_access_sql')) {
    function qz_quiz_access_sql(PDO $pdo, int $userId, array &$params, string $alias = 'q', string $parameter = 'qz_access_uid'): string {
        if (qz_can_manage_any($pdo, $userId)) return '1=1';
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', $parameter);
        $ownerPlaceholder = ':' . $base . '_owner';
        $collaboratorPlaceholder = ':' . $base . '_collaborator';
        $params[$ownerPlaceholder] = $userId;
        $params[$collaboratorPlaceholder] = $userId;
        return "({$alias}.created_by = {$ownerPlaceholder} OR EXISTS (SELECT 1 FROM quiz_collaborators qzc WHERE qzc.quiz_id = {$alias}.id AND qzc.user_id = {$collaboratorPlaceholder}))";
    }
}

if (!function_exists('qz_lock_quiz_for_workspace_mutation')) {
    function qz_lock_quiz_for_workspace_mutation(PDO $pdo, int $quizId, int $userId): array {
        if (!$pdo->inTransaction()) throw new RuntimeException('Quiz mutation authorization requires a transaction.');
        qz_require_workspace_mutation_access($pdo, $userId);

        $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id=:id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $quizId]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quiz) throw new RuntimeException('Quiz not found or unauthorized.');
        if (qz_can_manage_any($pdo, $userId) || (int)$quiz['created_by'] === $userId) return $quiz;

        $collaborator = $pdo->prepare('SELECT user_id FROM quiz_collaborators WHERE quiz_id=:quiz_id AND user_id=:user_id LIMIT 1 FOR UPDATE');
        $collaborator->execute([':quiz_id' => $quizId, ':user_id' => $userId]);
        if (!$collaborator->fetchColumn()) throw new RuntimeException('Quiz not found or unauthorized.');
        return $quiz;
    }
}

if (!function_exists('qz_lock_quiz_for_owner_mutation')) {
    function qz_lock_quiz_for_owner_mutation(PDO $pdo, int $quizId, int $userId): array {
        $quiz = qz_lock_quiz_for_workspace_mutation($pdo, $quizId, $userId);
        if ((int)$quiz['created_by'] !== $userId && !qz_can_manage_any($pdo, $userId)) {
            throw new RuntimeException('Only the quiz owner may perform this action.');
        }
        return $quiz;
    }
}

if (!function_exists('qz_can_mutate_resource')) {
    function qz_can_mutate_resource(PDO $pdo, int $userId, array $quiz, array $resource): bool {
        return qz_can_manage_any($pdo, $userId)
            || (int)($quiz['created_by'] ?? 0) === $userId
            || (int)($resource['created_by'] ?? 0) === $userId;
    }
}

if (!function_exists('qz_lock_resource_for_mutation')) {
    function qz_lock_resource_for_mutation(PDO $pdo, string $resource, int $quizId, int $resourceId, int $userId): array {
        $tables = [
            'category' => 'question_categories',
            'passage' => 'question_passages',
            'question' => 'questions',
        ];
        $table = $tables[$resource] ?? null;
        if ($table === null || $resourceId <= 0) throw new RuntimeException('Invalid Quiz resource.');
        $quiz = qz_lock_quiz_for_workspace_mutation($pdo, $quizId, $userId);
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=:id AND quiz_id=:quiz_id LIMIT 1 FOR UPDATE");
        $stmt->execute([':id' => $resourceId, ':quiz_id' => $quizId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !qz_can_mutate_resource($pdo, $userId, $quiz, $row)) {
            throw new RuntimeException('Quiz resource not found or unauthorized.');
        }
        return $row;
    }
}

if (!function_exists('qz_lock_category_for_mutation')) {
    function qz_lock_category_for_mutation(PDO $pdo, int $quizId, int $categoryId, int $userId): array {
        return qz_lock_resource_for_mutation($pdo, 'category', $quizId, $categoryId, $userId);
    }
}

if (!function_exists('qz_lock_passage_for_mutation')) {
    function qz_lock_passage_for_mutation(PDO $pdo, int $quizId, int $passageId, int $userId): array {
        return qz_lock_resource_for_mutation($pdo, 'passage', $quizId, $passageId, $userId);
    }
}

if (!function_exists('qz_lock_question_for_mutation')) {
    function qz_lock_question_for_mutation(PDO $pdo, int $quizId, int $questionId, int $userId): array {
        return qz_lock_resource_for_mutation($pdo, 'question', $quizId, $questionId, $userId);
    }
}

if (!function_exists('qz_lock_answer_for_mutation')) {
    function qz_lock_answer_for_mutation(PDO $pdo, int $quizId, int $questionId, int $answerId, int $userId): array {
        qz_lock_question_for_mutation($pdo, $quizId, $questionId, $userId);
        $stmt = $pdo->prepare('SELECT * FROM question_answers WHERE id=:id AND question_id=:question_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $answerId, ':question_id' => $questionId]);
        $answer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$answer) throw new RuntimeException('Answer not found or unauthorized.');
        return $answer;
    }
}

if (!function_exists('qz_assert_question_not_used_by_attempt')) {
    function qz_assert_question_not_used_by_attempt(PDO $pdo, int $questionId): void {
        if (!$pdo->inTransaction()) throw new RuntimeException('Question deletion guard requires a transaction.');
        $stmt = $pdo->prepare('SELECT 1 FROM attempt_questions WHERE question_id=:question_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':question_id' => $questionId]);
        if ($stmt->fetchColumn()) throw new QuizAssignedContentException('Question is used by an attempt and cannot be deleted.');
    }
}

if (!class_exists('QuizAssignedContentException')) {
    class QuizAssignedContentException extends RuntimeException {}
}

if (!function_exists('qz_assert_question_snapshots_complete')) {
    function qz_assert_question_snapshots_complete(PDO $pdo, int $questionId, bool $delivery = true, bool $options = true): void {
        if (!$pdo->inTransaction()) throw new RuntimeException('Snapshot completeness guard requires a transaction.');
        $requirements = [];
        if ($delivery) $requirements[] = "delivery_snapshotted_at IS NOT NULL AND JSON_VALID(delivery_snapshot_json) AND JSON_EXTRACT(delivery_snapshot_json,'$.version')=1";
        if ($options) $requirements[] = 'options_snapshotted_at IS NOT NULL';
        if ($requirements === []) return;
        $complete = implode(' AND ', $requirements);
        $stmt = $pdo->prepare("SELECT COUNT(*) AS assigned_count,
            COALESCE(SUM(CASE WHEN {$complete} THEN 1 ELSE 0 END),0) AS complete_count
            FROM attempt_questions WHERE question_id=:question_id");
        $stmt->execute([':question_id' => $questionId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($counts['assigned_count'] ?? 0) !== (int)($counts['complete_count'] ?? 0)) {
            throw new QuizAssignedContentException('Existing assignments must be snapshotted before this bank content can be changed.');
        }
    }
}

if (!function_exists('qz_assert_related_delivery_snapshots_complete')) {
    function qz_assert_related_delivery_snapshots_complete(PDO $pdo, string $scope, int $resourceId): void {
        if (!$pdo->inTransaction()) throw new RuntimeException('Delivery snapshot guard requires a transaction.');
        $where = match ($scope) {
            'quiz' => 'q.quiz_id=:resource_id',
            'passage' => 'q.passage_id=:resource_id',
            'category' => '(q.category_id=:resource_id OR p.category_id=:related_id)',
            default => throw new InvalidArgumentException('Invalid delivery snapshot scope.'),
        };
        $stmt = $pdo->prepare("SELECT COUNT(*) AS assigned_count,
            COALESCE(SUM(CASE WHEN aq.delivery_snapshotted_at IS NOT NULL
              AND JSON_VALID(aq.delivery_snapshot_json)
              AND JSON_EXTRACT(aq.delivery_snapshot_json,'$.version')=1 THEN 1 ELSE 0 END),0) AS complete_count
            FROM attempt_questions aq
            JOIN questions q ON q.id=aq.question_id
            LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id
            WHERE {$where}");
        $params = [':resource_id' => $resourceId];
        if ($scope === 'category') $params[':related_id'] = $resourceId;
        $stmt->execute($params);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($counts['assigned_count'] ?? 0) !== (int)($counts['complete_count'] ?? 0)) {
            throw new QuizAssignedContentException('Existing assignments must be snapshotted before this bank content can be changed.');
        }
    }
}

if (!function_exists('qz_assert_answer_not_used_by_attempt')) {
    function qz_assert_answer_not_used_by_attempt(PDO $pdo, int $questionId, int $answerId): void {
        if (!$pdo->inTransaction()) throw new RuntimeException('Answer deletion guard requires a transaction.');
        qz_assert_question_snapshots_complete($pdo, $questionId, false, true);
        $stmt = $pdo->prepare('SELECT id FROM attempt_answers WHERE question_id=:question_id AND selected_answer_id=:answer_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':question_id' => $questionId, ':answer_id' => $answerId]);
        if ($stmt->fetchColumn()) throw new QuizAssignedContentException('This answer is referenced by an attempt response and cannot be deleted.');
        $stmt = $pdo->prepare('SELECT id FROM attempt_answer_revisions WHERE question_id=:question_id AND selected_answer_id=:answer_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':question_id' => $questionId, ':answer_id' => $answerId]);
        if ($stmt->fetchColumn()) throw new QuizAssignedContentException('This answer is referenced by response history and cannot be deleted.');
        $answerJson = (string)$answerId;
        $stmt = $pdo->prepare("SELECT id FROM attempt_answers WHERE question_id=:question_id
            AND JSON_TYPE(IF(JSON_VALID(answer_text),answer_text,'null'))='ARRAY'
            AND JSON_CONTAINS(IF(JSON_VALID(answer_text),answer_text,'null'),:answer_json,'$') LIMIT 1 FOR UPDATE");
        $stmt->execute([':question_id' => $questionId, ':answer_json' => $answerJson]);
        if ($stmt->fetchColumn()) throw new QuizAssignedContentException('This answer is referenced by an attempt response and cannot be deleted.');
        $stmt = $pdo->prepare("SELECT id FROM attempt_answer_revisions WHERE question_id=:question_id
            AND JSON_TYPE(IF(JSON_VALID(answer_text),answer_text,'null'))='ARRAY'
            AND JSON_CONTAINS(IF(JSON_VALID(answer_text),answer_text,'null'),:answer_json,'$') LIMIT 1 FOR UPDATE");
        $stmt->execute([':question_id' => $questionId, ':answer_json' => $answerJson]);
        if ($stmt->fetchColumn()) throw new QuizAssignedContentException('This answer is referenced by response history and cannot be deleted.');
    }
}

if (!function_exists('qz_assert_question_type_change_safe')) {
    function qz_assert_question_type_change_safe(PDO $pdo, int $questionId, string $currentType, string $newType): void {
        if ($currentType === $newType) return;
        qz_assert_question_snapshots_complete($pdo, $questionId, true, true);
    }
}

if (!function_exists('qz_append_sort_order')) {
    function qz_append_sort_order(PDO $pdo, string $resource, int $parentId): ?int {
        if (!$pdo->inTransaction()
            || !in_array($resource, ['categories', 'passages', 'questions', 'answers'], true)
            || $parentId <= 0) {
            throw new RuntimeException('Invalid Quiz order allocation.');
        }
        // Nullable collections sort NULL last; answers use 0 as their non-null tail sentinel.
        return $resource === 'answers' ? 0 : null;
    }
}

if (!function_exists('qz_lock_attempt_for_mutation')) {
    function qz_lock_attempt_for_mutation(PDO $pdo, int $attemptId, int $userId, string $permission): array {
        if (!$pdo->inTransaction()) throw new RuntimeException('Attempt mutation authorization requires a transaction.');
        if (!in_array($permission, ['plugin.quiz.attempts.operate', 'plugin.quiz.attempts.delete', 'plugin.quiz.attempt-responses.correct'], true)
            || !qz_user_can($pdo, $userId, $permission)) {
            throw new RuntimeException('Quiz attempt authorization is no longer valid.');
        }
        $stmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id=:id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) throw new RuntimeException('Attempt not found or unauthorized.');
        return $attempt;
    }
}

if (!function_exists('qz_admin_user')) {
    function qz_admin_user(PDO $pdo): array {
        static $user = null;
        if ($user !== null) {
            return $user;
        }

        $id = (int)($GLOBALS['_quiz_actor_id'] ?? 0);
        if ($id <= 0) $id = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
        return $user = [
            'id' => $id,
            'can_manage_any' => qz_can_manage_any($pdo, $id),
            'can_manage_quizzes' => qz_user_can($pdo, $id, 'plugin.quiz.quizzes.manage'),
            'can_view_attempts' => qz_user_can($pdo, $id, 'plugin.quiz.attempts.view'),
            'can_export_attempts' => qz_user_can($pdo, $id, 'plugin.quiz.attempts.export'),
            'can_view_attempt_otp' => qz_user_can($pdo, $id, 'plugin.quiz.attempt-otp.view'),
            'can_view_attempt_responses' => qz_user_can($pdo, $id, 'plugin.quiz.attempt-responses.view'),
            'can_correct_attempt_responses' => qz_user_can($pdo, $id, 'plugin.quiz.attempt-responses.correct'),
            'can_operate_attempts' => qz_user_can($pdo, $id, 'plugin.quiz.attempts.operate'),
            'can_delete_attempts' => qz_user_can($pdo, $id, 'plugin.quiz.attempts.delete'),
        ];
    }
}

if (!function_exists('qz_flash_set')) {
    function qz_flash_set(string $type, string $message): void {
        if (!isset($_SESSION['quiz_flash']) || !is_array($_SESSION['quiz_flash'])) $_SESSION['quiz_flash'] = [];
        $_SESSION['quiz_flash'][$type] = $message;
    }
}

if (!function_exists('qz_flash_take')) {
    function qz_flash_take(string $type): string {
        $message = is_array($_SESSION['quiz_flash'] ?? null) ? (string)($_SESSION['quiz_flash'][$type] ?? '') : '';
        unset($_SESSION['quiz_flash'][$type]);
        return $message;
    }
}

if (!function_exists('qz_require_quiz_access')) {
    function qz_require_quiz_access(PDO $pdo, int $quizId): array {
        if ($quizId <= 0) {
            http_response_code(400);
            exit('Missing or invalid quiz_id');
        }

        $user = qz_admin_user($pdo);
        $sql = 'SELECT q.* FROM quizzes q WHERE q.id=:id';
        $params = [':id' => $quizId];
        if (!$user['can_manage_any']) {
            $sql .= ' AND ' . qz_quiz_access_sql($pdo, $user['id'], $params, 'q', 'qz_require_uid');
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quiz) {
            http_response_code(404);
            exit('Quiz not found');
        }
        return $quiz;
    }
}

if (!function_exists('qz_require_question_access')) {
    function qz_require_question_access(PDO $pdo, int $quizId, int $questionId): array {
        qz_require_quiz_access($pdo, $quizId);
        $stmt = $pdo->prepare('SELECT * FROM questions WHERE id=:id AND quiz_id=:quiz_id LIMIT 1');
        $stmt->execute([':id' => $questionId, ':quiz_id' => $quizId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            http_response_code(404);
            exit('Question not found');
        }
        return $question;
    }
}

if (!function_exists('qz_require_answer_access')) {
    function qz_require_answer_access(PDO $pdo, int $quizId, int $questionId, int $answerId): array {
        qz_require_question_access($pdo, $quizId, $questionId);
        $stmt = $pdo->prepare('SELECT * FROM question_answers WHERE id=:id AND question_id=:question_id LIMIT 1');
        $stmt->execute([':id' => $answerId, ':question_id' => $questionId]);
        $answer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$answer) {
            http_response_code(404);
            exit('Answer not found');
        }
        return $answer;
    }
}

if (!function_exists('qz_require_attempt_access')) {
    function qz_require_attempt_access(PDO $pdo, int $attemptId): array {
        if ($attemptId <= 0) {
            http_response_code(400);
            exit('Missing or invalid attempt_id');
        }

        $user = qz_admin_user($pdo);
        if (!$user['can_view_attempts'] && !$user['can_operate_attempts'] && !$user['can_delete_attempts']) {
            http_response_code(404);
            exit('Attempt not found');
        }
        $stmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            http_response_code(404);
            exit('Attempt not found');
        }
        return $attempt;
    }
}

if (!function_exists('qz_require_passage_access')) {
    function qz_require_passage_access(PDO $pdo, int $quizId, int $passageId): array {
        qz_require_quiz_access($pdo, $quizId);
        $stmt = $pdo->prepare('SELECT * FROM question_passages WHERE id=:id AND quiz_id=:quiz_id LIMIT 1');
        $stmt->execute([':id' => $passageId, ':quiz_id' => $quizId]);
        $passage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$passage) {
            http_response_code(404);
            exit('Passage not found');
        }
        return $passage;
    }
}

if (!function_exists('qz_require_category_access')) {
    function qz_require_category_access(PDO $pdo, int $quizId, int $categoryId): array {
        qz_require_quiz_access($pdo, $quizId);
        $stmt = $pdo->prepare('SELECT * FROM question_categories WHERE id=:id AND quiz_id=:quiz_id LIMIT 1');
        $stmt->execute([':id' => $categoryId, ':quiz_id' => $quizId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) {
            http_response_code(404);
            exit('Category not found');
        }
        return $category;
    }
}

if (!function_exists('qz_unique_student_cookie_id')) {
    function qz_unique_student_cookie_id(PDO $pdo): string {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);

            $stmt = $pdo->prepare('SELECT 1 FROM students WHERE cookie_id=:cookie_id LIMIT 1');
            $stmt->execute([':cookie_id' => $uuid]);
            if (!$stmt->fetchColumn()) {
                return $uuid;
            }
        }
        throw new RuntimeException('Unable to generate a unique student cookie ID');
    }
}
