<?php
// /quiz/api/_timer_helper.php
declare(strict_types=1);

/**
 * Timer helper berbasis MySQL agar konsisten (hindari mismatch PHP time/strtotime).
 * - Bisa dipakai di dalam transaction atau tidak.
 * - Jika $startIfMissing=true dan started_at kosong, helper akan set started_at=NOW() (sekali saja).
 * - Jika $finalizeIfExpired=true dan time up, helper akan auto-finalize attempt (set submitted + total_score).
 *
 * Return:
 * [
 *   'server_now_ts' => int,
 *   'duration_sec'  => int,
 *   'ends_at_ts'    => int,
 *   'time_up'       => bool,
 *   'started_at'    => string|null,
 * ]
 */
function qz_timer_enforce(PDO $pdo, int $attempt_id, int $durMin, bool $startIfMissing = true, bool $finalizeIfExpired = true, mixed $warningSeconds = null): array
{
    $durMin = max(0, (int)$durMin);
    $warningSeconds = quiz_safe_timer_warning_seconds($warningSeconds);

    // default (no timer)
    $timer = [
        'server_now_ts' => (int)time(),
        'duration_sec'  => $durMin > 0 ? $durMin * 60 : 0,
        'ends_at_ts'    => 0,
        'time_up'       => false,
        'started_at'    => null,
        'warning_seconds' => $warningSeconds,
        'finalized'     => false,
    ];

    if ($durMin <= 0) {
        if ($startIfMissing) {
            $startedLocalTx = false;
            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedLocalTx = true;
                }
                $lock = $pdo->prepare('SELECT started_at, status, email_verified_at FROM quiz_attempts WHERE id=:id LIMIT 1 FOR UPDATE');
                $lock->execute([':id' => $attempt_id]);
                $attempt = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$attempt) throw new RuntimeException('Attempt not found for timer.');
                $status = (string)($attempt['status'] ?? '');
                if (empty($attempt['email_verified_at']) || $status === 'registered') {
                    throw new RuntimeException('Attempt is not verified for timer mutation.');
                }
                if ($status === 'submitted') throw new RuntimeException('Attempt is already submitted.');
                if (!in_array($status, ['verified', 'in_progress'], true)) {
                    throw new RuntimeException('Attempt status is not eligible for timer mutation.');
                }
                $startedAt = $attempt['started_at'] ?? null;
                if ($startedAt === null || $startedAt === '') {
                    $pdo->prepare("UPDATE quiz_attempts SET started_at=NOW(), status=IF(status='verified','in_progress',status), last_activity_at=NOW() WHERE id=:id LIMIT 1")
                        ->execute([':id' => $attempt_id]);
                } else {
                    $pdo->prepare('UPDATE quiz_attempts SET last_activity_at=NOW() WHERE id=:id LIMIT 1')->execute([':id' => $attempt_id]);
                }
                $now = $pdo->prepare('SELECT UNIX_TIMESTAMP(NOW()), started_at FROM quiz_attempts WHERE id=:id LIMIT 1');
                $now->execute([':id' => $attempt_id]);
                $row = $now->fetch(PDO::FETCH_NUM) ?: [];
                $timer['server_now_ts'] = (int)($row[0] ?? time());
                $timer['started_at'] = isset($row[1]) ? (string)$row[1] : null;
                if ($startedLocalTx) $pdo->commit();
            } catch (Throwable $error) {
                if ($startedLocalTx && $pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        }
        return $timer;
    }

    if (!$startIfMissing && !$finalizeIfExpired) {
        $calc = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(NOW()) AS now_ts,
                   CASE WHEN started_at IS NULL THEN 0 ELSE UNIX_TIMESTAMP(DATE_ADD(started_at, INTERVAL :dur MINUTE)) END AS end_ts,
                   started_at
            FROM quiz_attempts WHERE id = :id LIMIT 1
        ");
        $calc->execute([':id' => $attempt_id, ':dur' => $durMin]);
        $row = $calc->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Attempt not found for timer.');
        $timer['server_now_ts'] = (int)($row['now_ts'] ?? time());
        $timer['ends_at_ts'] = (int)($row['end_ts'] ?? 0);
        $timer['started_at'] = !empty($row['started_at']) ? (string)$row['started_at'] : null;
        $timer['time_up'] = $timer['ends_at_ts'] > 0 && $timer['server_now_ts'] > $timer['ends_at_ts'];
        return $timer;
    }

    $startedLocalTx = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedLocalTx = true;
        }

        // lock attempt
        $st = $pdo->prepare("
            SELECT started_at, status, email_verified_at
            FROM quiz_attempts
            WHERE id = :id
            FOR UPDATE
        ");
        $st->execute([':id' => $attempt_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Attempt not found for timer.');
        }

        $startedAt = (string)($row['started_at'] ?? '');
        $status    = (string)($row['status'] ?? '');
        if (empty($row['email_verified_at']) || $status === 'registered') {
            throw new RuntimeException('Attempt is not verified for timer mutation.');
        }
        if ($status === 'submitted') throw new RuntimeException('Attempt is already submitted.');
        if (!in_array($status, ['verified', 'in_progress'], true)) {
            throw new RuntimeException('Attempt status is not eligible for timer mutation.');
        }

        // start only once (idempotent)
        if ($startIfMissing && $startedAt === '') {
            $up = $pdo->prepare("
                UPDATE quiz_attempts
                SET started_at = NOW(),
                    status = IF(status='verified','in_progress',status),
                    last_activity_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $up->execute([':id' => $attempt_id]);
        } else {
            // touch activity only
            $pdo->prepare("UPDATE quiz_attempts SET last_activity_at = NOW() WHERE id = :id LIMIT 1")
                ->execute([':id' => $attempt_id]);
        }

        // compute now/end using MySQL to avoid timezone drift
        $calc = $pdo->prepare("
            SELECT
              UNIX_TIMESTAMP(NOW()) AS now_ts,
              CASE
                WHEN started_at IS NULL THEN 0
                ELSE UNIX_TIMESTAMP(DATE_ADD(started_at, INTERVAL :dur MINUTE))
              END AS end_ts,
              started_at
            FROM quiz_attempts
            WHERE id = :id
            LIMIT 1
        ");
        $calc->execute([':id' => $attempt_id, ':dur' => $durMin]);
        $trow = $calc->fetch(PDO::FETCH_ASSOC) ?: [];

        $nowTs = (int)($trow['now_ts'] ?? time());
        $endTs = (int)($trow['end_ts'] ?? 0);
        $startedAt2 = $trow['started_at'] ?? null;

        $timer['server_now_ts'] = $nowTs;
        $timer['ends_at_ts']    = $endTs;
        $timer['started_at']    = is_string($startedAt2) && $startedAt2 !== '' ? $startedAt2 : null;

        $timeUp = ($endTs > 0 && $nowTs > $endTs);
        $timer['time_up'] = $timeUp;

        $finalizedAttempt = null;
        if ($timeUp && $finalizeIfExpired) {
            $finalizedAttempt = quiz_finalize_attempt($pdo, $attempt_id);
            $timer['finalized'] = !empty($finalizedAttempt['finalized']);
        }
        if ($startedLocalTx) {
            $pdo->commit();
            if (!empty($finalizedAttempt['finalized']) && function_exists('quiz_extension_emit')) {
                quiz_extension_emit('attempt.submitted', [
                    'attempt_id' => (int)$finalizedAttempt['attempt_id'],
                    'quiz_id' => (int)$finalizedAttempt['quiz_id'],
                    'reason' => 'timer_expired',
                    'status' => 'submitted',
                ]);
            }
        }

        return $timer;

    } catch (Throwable $e) {
        if ($startedLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
