<?php

/*
 * Pest bootstrap. Unit tests run without a LibreNMS installation: the
 * transport and definition engine only depend on phpseclib, ext-dom and
 * symfony/yaml. Feature tests that need the LibreNMS application are
 * added in Phase 3 and skip themselves when LIBRENMS_PATH is not set.
 */

function fixture(string $relativePath): string
{
    $path = __DIR__ . '/fixtures/' . ltrim($relativePath, '/');
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("Fixture not found: $path");
    }

    return $content;
}
