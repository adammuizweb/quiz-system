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
    return '1.0.0';
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
