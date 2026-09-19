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
function qz_timer_enforce(PDO $pdo, int $attempt_id, int $durMin, bool $startIfMissing = true, bool $finalizeIfExpired = true): array
{
    $durMin = max(0, (int)$durMin);

    // default (no timer)
    $timer = [
        'server_now_ts' => (int)time(),
        'duration_sec'  => $durMin > 0 ? $durMin * 60 : 0,
        'ends_at_ts'    => 0,
        'time_up'       => false,
        'started_at'    => null,
    ];

    if ($durMin <= 0) {
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
            SELECT started_at, status
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

        if ($timeUp && $finalizeIfExpired) {
            // idempotent finalize
            $sc = $pdo->prepare("SELECT COALESCE(SUM(computed_score),0) FROM attempt_answers WHERE attempt_id = :aid");
            $sc->execute([':aid' => $attempt_id]);
            $total_score = (int)$sc->fetchColumn();

            $fin = $pdo->prepare("
                UPDATE quiz_attempts
                SET total_score = :score,
                    status = 'submitted',
                    submitted_at = COALESCE(submitted_at, NOW()),
                    last_activity_at = NOW()
                WHERE id = :id AND status <> 'submitted'
                LIMIT 1
            ");
            $fin->execute([':score' => $total_score, ':id' => $attempt_id]);
        }

        if ($startedLocalTx) {
            $pdo->commit();
        }

        return $timer;

    } catch (Throwable $e) {
        if ($startedLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
