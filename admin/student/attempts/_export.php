<?php
declare(strict_types=1);

defined('DASHBOARD_CONTEXT') || exit;

function qz_attempt_export_groups(mixed $value): array {
    $order = ['rank', 'student', 'quiz', 'status', 'mode', 'verification', 'progress', 'legacy_score', 'score100', 'reset', 'activity'];
    $defaults = ['student', 'quiz', 'status', 'mode', 'verification', 'progress', 'score100', 'activity'];
    $requested = is_array($value)
        ? array_map(static fn(mixed $group): string => is_scalar($group) ? (string)$group : '', $value)
        : $defaults;
    $selected = array_fill_keys(array_intersect($order, $requested), true);
    $selected['student'] = true;
    $selected['status'] = true;
    return array_values(array_filter($order, static fn(string $group): bool => isset($selected[$group])));
}

function qz_attempt_export_columns(mixed $groups = null): array {
    $definitions = [
        'rank' => [['key'=>'rank', 'label'=>'No.', 'type'=>'number', 'width'=>8]],
        'student' => [
            ['key'=>'name', 'label'=>'Nama Siswa', 'type'=>'text', 'width'=>28],
            ['key'=>'phone', 'label'=>'No HP', 'type'=>'text', 'width'=>18],
            ['key'=>'email', 'label'=>'Email', 'type'=>'text', 'width'=>32],
            ['key'=>'test_number', 'label'=>'Nomor Test', 'type'=>'text', 'width'=>24],
        ],
        'quiz' => [['key'=>'quiz', 'label'=>'Quiz', 'type'=>'text', 'width'=>36]],
        'status' => [['key'=>'status', 'label'=>'Status', 'type'=>'text', 'width'=>15]],
        'mode' => [['key'=>'mode', 'label'=>'Mode', 'type'=>'text', 'width'=>12]],
        'verification' => [['key'=>'verification', 'label'=>'Verifikasi', 'type'=>'text', 'width'=>14]],
        'progress' => [
            ['key'=>'answered', 'label'=>'Dijawab', 'type'=>'text', 'width'=>14],
            ['key'=>'correct', 'label'=>'Benar', 'type'=>'text', 'width'=>14],
        ],
        'legacy_score' => [['key'=>'legacy_score', 'label'=>'Nilai Legacy', 'type'=>'number', 'width'=>14]],
        'score100' => [['key'=>'score100', 'label'=>'Nilai 100', 'type'=>'number', 'width'=>12]],
        'reset' => [['key'=>'reset', 'label'=>'Reset', 'type'=>'number', 'width'=>10]],
        'activity' => [['key'=>'activity', 'label'=>'Aktivitas', 'type'=>'datetime', 'width'=>19]],
    ];
    $columns = [];
    foreach (qz_attempt_export_groups($groups) as $group) {
        foreach ($definitions[$group] as $column) $columns[] = $column;
    }
    return $columns;
}

function qz_attempt_export_headers(mixed $groups = null): array {
    return array_column(qz_attempt_export_columns($groups), 'label');
}

function qz_attempt_export_record(array $row): array {
    $mode = match (strtolower(trim((string)($row['delivery_mode'] ?? '')))) {
        'online' => 'Online',
        'onsite' => 'Onsite',
        default => '-',
    };
    $verification = match (strtolower(trim((string)($row['verified_via'] ?? '')))) {
        'email' => 'Email',
        'manual' => 'Manual',
        default => '-',
    };
    $lastActivity = trim((string)($row['last_activity_at'] ?? ''));
    $activityTimestamp = $lastActivity !== '' ? strtotime($lastActivity) : false;
    $status = match (strtolower(trim((string)($row['status'] ?? '')))) {
        'registered' => 'Registered',
        'verified' => 'Verified',
        'in_progress' => 'In Progress',
        'submitted' => 'Submitted',
        'expired' => 'Expired',
        default => (string)($row['status'] ?? ''),
    };
    $totalQuestions = (int)($row['total_questions'] ?? 0);

    return [
        'name' => (string)($row['display_name'] ?? ''),
        'phone' => (string)($row['display_phone'] ?? ''),
        'email' => (string)($row['display_email'] ?? ''),
        'test_number' => (string)($row['nomor_test'] ?? ''),
        'quiz' => (string)($row['quiz_title'] ?? ''),
        'status' => $status,
        'mode' => $mode,
        'verification' => $verification,
        'answered' => (int)($row['answered_count'] ?? 0) . '/' . $totalQuestions,
        'correct' => (int)($row['correct_count'] ?? 0) . '/' . $totalQuestions,
        'legacy_score' => round((float)($row['total_score'] ?? 0), 2),
        'score100' => round((float)($row['score100'] ?? 0), 2),
        'reset' => (int)($row['reset_count'] ?? 0),
        'activity' => $activityTimestamp !== false ? date('d/m/Y H:i', $activityTimestamp) : '-',
        'activity_timestamp' => $activityTimestamp,
    ];
}

function qz_attempt_xlsx_support(): array {
    $requiredExtensions = [
        'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'libxml', 'mbstring',
        'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];
    $missingExtensions = array_values(array_filter(
        $requiredExtensions,
        static fn(string $extension): bool => !extension_loaded($extension)
    ));
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        return [
            'available' => false,
            'autoload' => $autoload,
            'message' => 'Dependency PhpSpreadsheet belum tersedia dalam paket plugin.',
        ];
    }
    if (PHP_INT_SIZE !== 8) {
        return [
            'available' => false,
            'autoload' => $autoload,
            'message' => 'Export Excel membutuhkan PHP 64-bit.',
        ];
    }
    if ($missingExtensions !== []) {
        return [
            'available' => false,
            'autoload' => $autoload,
            'message' => 'Ekstensi PHP belum tersedia: ' . implode(', ', $missingExtensions) . '.',
        ];
    }

    return ['available' => true, 'autoload' => $autoload, 'message' => ''];
}
