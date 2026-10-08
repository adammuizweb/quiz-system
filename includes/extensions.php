<?php
declare(strict_types=1);

/**
 * Stable integration surface for sibling plugins.
 *
 * Consumers must not depend on Quiz's internal tables, public entrypoints, or
 * private helpers. This API only exposes lifecycle and capability metadata.
 */
function quiz_extension_api_version(): string
{
    return '1.1.0';
}

function quiz_extension_context(): array
{
    $manifest = function_exists('plugin_manifest') ? plugin_manifest('quiz') : null;
    return [
        'plugin' => 'quiz',
        'version' => is_array($manifest) ? (string)($manifest['version'] ?? '') : '',
        'api_version' => quiz_extension_api_version(),
    ];
}

/** Subscribe to a sanitized Quiz lifecycle event for the current request. */
function quiz_extension_subscribe(string $event, callable $listener): void
{
    $allowed = ['attempt.submitted', 'answer.saved', 'question.review_changed'];
    if (!in_array($event, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported Quiz lifecycle event.');
    }
    $GLOBALS['_quiz_extension_listeners'][$event][] = $listener;
}

/** Emit only after the caller has committed its database transaction. */
function quiz_extension_emit(string $event, array $payload): void
{
    $fields = [
        'attempt.submitted' => ['attempt_id', 'quiz_id', 'reason', 'status'],
        'answer.saved' => ['attempt_id', 'quiz_id', 'question_id', 'question_type', 'revision_count'],
        'question.review_changed' => ['attempt_id', 'quiz_id', 'question_id', 'marked'],
    ];
    if (!isset($fields[$event])) throw new InvalidArgumentException('Unsupported Quiz lifecycle event.');
    $payload = array_intersect_key($payload, array_fill_keys($fields[$event], true));
    $listeners = $GLOBALS['_quiz_extension_listeners'][$event] ?? [];
    foreach (is_array($listeners) ? $listeners : [] as $listener) {
        try {
            $listener(['event' => $event, 'api_version' => quiz_extension_api_version()] + $payload);
        } catch (Throwable $error) {
            error_log('[quiz/extensions] ' . $event . ' listener failed: ' . $error->getMessage());
        }
    }
}
