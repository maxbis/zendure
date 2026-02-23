<?php

declare(strict_types=1);

/**
 * Load ENTSO-E security token from main/prices/config.json (git-ignored).
 */
function getEntsoeSecurityToken(): string {
    $path = __DIR__ . '/config.json';
    if (!is_readable($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return '';
    }
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['ENTSOE_SECURITY_TOKEN']) && is_string($data['ENTSOE_SECURITY_TOKEN'])
        ? trim($data['ENTSOE_SECURITY_TOKEN'])
        : '';
}
