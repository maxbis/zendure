<?php

declare(strict_types=1);

require_once __DIR__ . '/../main/includes/config_loader.php';

/**
 * Returns configured API base URL, normalized for safe concatenation.
 */
function restartApiBaseUrl(): string {
    $baseUrl = ConfigLoader::get('apiBaseUrlPiControl', '');
    return is_string($baseUrl) ? trim($baseUrl) : '';
}
