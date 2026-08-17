<?php
declare(strict_types=1);

const RULE_PROFILE_AUTO_STATE_FILE = __DIR__ . '/../data/rule_profile_auto_state.json';

function readRuleProfileAutoState(?string $path = null): array
{
    $path = $path ?? RULE_PROFILE_AUTO_STATE_FILE;
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function effectiveRuleProfileForDate(array $profileConfig, string $dateYmd, ?array $autoState = null): array
{
    $manualId = (string) ($profileConfig['active_profile_id'] ?? 'show_all');
    $mode = ($profileConfig['selection_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $dateIso = preg_match('/^\d{8}$/', $dateYmd) === 1
        ? substr($dateYmd, 0, 4) . '-' . substr($dateYmd, 4, 2) . '-' . substr($dateYmd, 6, 2)
        : $dateYmd;
    $result = [
        'mode' => $mode,
        'date' => $dateIso,
        'effective_profile_id' => $manualId,
        'manual_profile_id' => $manualId,
        'reason' => $mode === 'manual' ? 'manual' : 'manual_fallback',
        'swr_wh_m2' => null,
        'forecast_status' => null,
        'forecast_cached_at' => null,
        'evaluated_at' => null,
    ];
    if ($mode !== 'auto') {
        return $result;
    }

    $state = $autoState ?? readRuleProfileAutoState();
    $day = isset($state['days'][$dateIso]) && is_array($state['days'][$dateIso]) ? $state['days'][$dateIso] : null;
    $profiles = isset($profileConfig['profiles']) && is_array($profileConfig['profiles']) ? $profileConfig['profiles'] : [];
    if ($day !== null && isset($day['profile_id']) && isset($profiles[$day['profile_id']])) {
        $result['effective_profile_id'] = (string) $day['profile_id'];
        $result['reason'] = (string) ($day['reason'] ?? 'automatic');
        $result['swr_wh_m2'] = isset($day['swr_wh_m2']) && is_numeric($day['swr_wh_m2']) ? (int) round((float) $day['swr_wh_m2']) : null;
        $result['forecast_cached_at'] = $day['forecast_cached_at'] ?? null;
        $result['evaluated_at'] = $day['evaluated_at'] ?? ($state['last_evaluation_at'] ?? null);
    }
    $result['forecast_status'] = $state['forecast_status'] ?? null;
    return $result;
}
