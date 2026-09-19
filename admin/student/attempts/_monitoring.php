<?php
declare(strict_types=1);

defined('DASHBOARD_CONTEXT') || exit;

function qz_attempt_no_store(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function qz_attempt_valid_date(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

function qz_attempt_filters(array $input): array {
    $statuses = ['registered', 'verified', 'in_progress', 'submitted', 'expired'];
    $dateFields = [
        'created_at' => 'a.created_at',
        'started_at' => 'a.started_at',
        'submitted_at' => 'a.submitted_at',
        'last_activity_at' => 'a.last_activity_at',
    ];
    $sorts = [
        'newest' => 'attempt_id DESC',
        'oldest' => 'attempt_id ASC',
        'score100_desc' => 'score100 DESC, submitted_at DESC, attempt_id DESC',
        'score100_asc' => 'score100 ASC, submitted_at DESC, attempt_id DESC',
        'score_desc' => 'total_score DESC, submitted_at DESC, attempt_id DESC',
        'score_asc' => 'total_score ASC, submitted_at DESC, attempt_id DESC',
        'activity_desc' => 'last_activity_at DESC, attempt_id DESC',
    ];

    $value = static function (string $key, string $default = '') use ($input): string {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : $default;
    };
    $positiveId = static function (string $key) use ($value): int {
        $raw = $value($key);
        return ctype_digit($raw) ? max(0, (int)$raw) : 0;
    };
    $filters = [
        'student_id' => $positiveId('student_id'),
        'quiz_id' => $positiveId('quiz_id'),
        'status' => $value('status'),
        'delivery_mode' => $value('delivery_mode'),
        'q' => mb_substr($value('q'), 0, 255, 'UTF-8'),
        'date_field' => $value('date_field', 'submitted_at'),
        'date_from' => $value('date_from'),
        'date_to' => $value('date_to'),
        'sort' => $value('sort', 'newest'),
    ];

    if (!in_array($filters['status'], $statuses, true)) $filters['status'] = '';
    if (!in_array($filters['delivery_mode'], ['online', 'onsite', 'unset'], true)) $filters['delivery_mode'] = '';
    if (!isset($dateFields[$filters['date_field']])) $filters['date_field'] = 'submitted_at';
    if (!qz_attempt_valid_date($filters['date_from'])) $filters['date_from'] = '';
    if (!qz_attempt_valid_date($filters['date_to'])) $filters['date_to'] = '';
    if (!isset($sorts[$filters['sort']])) $filters['sort'] = 'newest';

    $params = [];
    $where = [];
    if ($filters['student_id'] > 0) {
        $where[] = 'a.student_id = :student_id';
        $params[':student_id'] = $filters['student_id'];
    }
    if ($filters['quiz_id'] > 0) {
        $where[] = 'a.quiz_id = :quiz_id';
        $params[':quiz_id'] = $filters['quiz_id'];
    }
    if ($filters['status'] !== '') {
        $where[] = 'a.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (in_array($filters['delivery_mode'], ['online', 'onsite'], true)) {
        $where[] = 'a.delivery_mode = :delivery_mode';
        $params[':delivery_mode'] = $filters['delivery_mode'];
    } elseif ($filters['delivery_mode'] === 'unset') {
        $where[] = 'a.delivery_mode IS NULL';
    }
    if ($filters['q'] !== '') {
        $where[] = "(
            a.attempt_alias LIKE :search_alias OR
            a.nomor_test LIKE :search_number OR
            COALESCE(NULLIF(TRIM(a.contact_name), ''), s.name) LIKE :search_name OR
            COALESCE(NULLIF(TRIM(a.contact_email), ''), s.email) LIKE :search_email OR
            COALESCE(NULLIF(TRIM(a.contact_phone), ''), s.phone) LIKE :search_phone
        )";
        $search = '%' . $filters['q'] . '%';
        foreach (['alias', 'number', 'name', 'email', 'phone'] as $field) {
            $params[':search_' . $field] = $search;
        }
    }

    $dateSql = $dateFields[$filters['date_field']];
    if ($filters['date_from'] !== '') {
        $where[] = "{$dateSql} >= :date_from";
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $nextDate = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d');
        $where[] = "{$dateSql} < :date_to";
        $params[':date_to'] = $nextDate . ' 00:00:00';
    }

    return [
        'values' => $filters,
        'params' => $params,
        'where_sql' => $where === [] ? '' : 'WHERE ' . implode(' AND ', $where),
        'order_sql' => $sorts[$filters['sort']],
    ];
}

function qz_attempt_answered_condition(string $alias = 'aa'): string {
    return "({$alias}.selected_answer_id IS NOT NULL OR (TRIM(COALESCE({$alias}.answer_text, '')) <> '' AND TRIM({$alias}.answer_text) <> '[]'))";
}

function qz_attempt_monitoring_sql(string $whereSql, string $orderSql, ?int $limit = null, int $offset = 0): string {
    $answered = qz_attempt_answered_condition('aa');
    $pagination = $limit === null ? '' : ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    return "
        SELECT attempt_rows.*,
            CASE WHEN total_questions > 0 THEN (correct_count / total_questions) * 100 ELSE 0 END AS score100
        FROM (
            SELECT
                a.id AS attempt_id,
                a.student_id,
                a.quiz_id,
                a.attempt_alias,
                a.nomor_test,
                a.status,
                a.email_verified_at,
                a.verified_via,
                a.delivery_mode,
                a.last_activity_at,
                a.created_at,
                a.started_at,
                a.submitted_at,
                a.reset_count,
                a.identity_locked,
                a.contact_name,
                a.contact_email,
                a.contact_phone,
                COALESCE(NULLIF(TRIM(a.contact_name), ''), s.name) AS display_name,
                COALESCE(NULLIF(TRIM(a.contact_email), ''), s.email) AS display_email,
                COALESCE(NULLIF(TRIM(a.contact_phone), ''), s.phone) AS display_phone,
                q.title AS quiz_title,
                (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = a.id) AS total_questions,
                (SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id = a.id AND {$answered}) AS answered_count,
                COALESCE((SELECT SUM(aa.computed_score) FROM attempt_answers aa WHERE aa.attempt_id = a.id), 0) AS total_score,
                (SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id = a.id AND aa.computed_score > 0) AS correct_count
            FROM quiz_attempts a
            JOIN students s ON s.id = a.student_id
            JOIN quizzes q ON q.id = a.quiz_id
            {$whereSql}
        ) attempt_rows
        ORDER BY {$orderSql}{$pagination}
    ";
}
