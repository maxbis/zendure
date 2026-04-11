<?php
/**
 * Schedule Panels Partial - Mobile Version
 * One card with two tabs: Schedule (today/tomorrow) and Schedule entries.
 */

// Helper functions for rendering
function getTimeClass($h)
{
    return ($h < 6) ? 'time-night' : (($h < 12) ? 'time-morning' : (($h < 18) ? 'time-afternoon' : 'time-evening'));
}

function getValueLabel($val)
{
    if ($val === null)
        return '-';
    if ($val === 'netzero')
        return 'Net Zero';
    if ($val === 'netzero-')
        return 'Netzero-';
    if ($val === 'netzero+')
        return 'Solar Charge';
    if ($val === 'auto')
        return 'Auto';
    if (is_numeric($val))
        return ($val > 0 ? '+' : '') . intval($val) . ' W';
    return $val . ' W';
}

function getRuleDisplayLabel($slot)
{
    $ruleName = isset($slot['rule_name']) ? trim((string) $slot['rule_name']) : '';
    if ($ruleName === '') {
        return '';
    }

    $ruleIndex = (isset($slot['rule_index']) && is_numeric($slot['rule_index']))
        ? intval($slot['rule_index'])
        : null;

    return $ruleIndex !== null && $ruleIndex > 0
        ? ('#' . $ruleIndex . ' ' . $ruleName)
        : $ruleName;
}

function normalizeScheduleRuleColor($value)
{
    if (!is_string($value)) {
        return '';
    }

    $trimmed = trim($value);
    return preg_match('/^#([0-9a-fA-F]{6})$/', $trimmed) ? strtoupper($trimmed) : '';
}

function loadScheduleRuleColorMap()
{
    $rulesFile = __DIR__ . '/../data/charge_schedule_conditions.json';
    if (!is_file($rulesFile) || !is_readable($rulesFile)) {
        return [];
    }

    $raw = file_get_contents($rulesFile);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $colorMap = [];
    foreach (array_values($decoded) as $idx => $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $normalizedColor = normalizeScheduleRuleColor($rule['color'] ?? null);
        if ($normalizedColor === '') {
            continue;
        }
        $colorMap[(string) ($idx + 1)] = $normalizedColor;
    }

    return $colorMap;
}

function getRuleColorForSlot($slot, $colorMap)
{
    $ruleIndex = (isset($slot['rule_index']) && is_numeric($slot['rule_index']))
        ? (string) intval($slot['rule_index'])
        : '';

    if ($ruleIndex === '' || !is_array($colorMap) || !isset($colorMap[$ruleIndex])) {
        return '';
    }

    return normalizeScheduleRuleColor($colorMap[$ruleIndex]);
}

function buildHourlyDisplaySlots($slots)
{
    if (!is_array($slots)) {
        $slots = [];
    }

    $sortedSlots = array_values($slots);
    usort($sortedSlots, function ($a, $b) {
        $timeA = isset($a['time']) ? (string) $a['time'] : '';
        $timeB = isset($b['time']) ? (string) $b['time'] : '';
        return strcmp($timeA, $timeB);
    });

    $displayedSlots = [];
    $lastKnownSlot = null;
    $slotIndex = 0;
    $slotCount = count($sortedSlots);

    for ($hour = 0; $hour < 24; $hour++) {
        $hourTime = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . '00';

        while ($slotIndex < $slotCount) {
            $candidate = $sortedSlots[$slotIndex];
            $candidateTime = isset($candidate['time']) ? (string) $candidate['time'] : '';
            if ($candidateTime !== '' && strcmp($candidateTime, $hourTime) <= 0) {
                $lastKnownSlot = $candidate;
                $slotIndex++;
                continue;
            }
            break;
        }

        $slot = is_array($lastKnownSlot) ? $lastKnownSlot : [];
        $slot['time'] = $hourTime;
        if (!array_key_exists('value', $slot)) {
            $slot['value'] = null;
        }
        $displayedSlots[] = $slot;
    }

    return $displayedSlots;
}

$scheduleRuleColorMap = loadScheduleRuleColorMap();
?>
<div class="layout">
<div class="card schedule-mobile-card">
    <div class="schedule-mobile-card__title-row">
        <h3 class="card-header schedule-mobile-card__heading">Schedule</h3>
        <label class="schedule-mobile-card__show-toggle" title="Show or hide this panel. Saved in this browser only.">
            <span class="schedule-mobile-card__toggle-text" style="font-size: 0.6rem;">Show</span>
            <input type="checkbox" class="schedule-mobile-card__show-toggle-input" data-role="schedule-show-toggle" checked
                aria-label="Show schedule panel" />
            <span class="schedule-mobile-card__show-track" aria-hidden="true"><span class="schedule-mobile-card__show-thumb"></span></span>
        </label>
    </div>
    <div class="schedule-mobile-card__body" data-role="schedule-panel-body">
    <div class="schedule-mobile-tabs" role="tablist">
        <button type="button" class="schedule-mobile-tab active" data-tab="schedule" role="tab" aria-selected="true">Schedule</button>
        <button type="button" class="schedule-mobile-tab" data-tab="entries" role="tab" aria-selected="false">Entries</button>
        <button type="button" class="schedule-mobile-tab" data-tab="rules" role="tab" aria-selected="false">Rules</button>
    </div>
    <div class="schedule-mobile-tab-panels">
        <!-- Tab 1: Today's and Tomorrow's Schedule -->
        <div class="schedule-mobile-tab-panel active" data-tab="schedule" role="tabpanel" aria-hidden="false">
            <div class="schedule-day-switcher" role="tablist" aria-label="Schedule day">
                <button type="button" class="schedule-day-dot active" data-day-chip="today" role="tab" aria-selected="true" aria-label="Show Today"></button>
                <button type="button" class="schedule-day-dot" data-day-chip="tomorrow" role="tab" aria-selected="false" aria-label="Show Tomorrow"></button>
            </div>
            <div class="schedule-days-container" id="schedule-days-swipe">
                <!-- Today's Schedule (Left) -->
                <div class="schedule-day" data-day-panel="today">
                    <div class="schedule-day-header">
                        <h3 class="card-header">Today <?php echo substr($today, -2); ?></h3>
                    </div>
                    <div class="schedule-list" id="today-schedule-grid">
                        <?php
                        $displayedSlots = buildHourlyDisplaySlots($resolvedToday);

                        // Find the current active entry from displayed slots (closest to current time but not larger)
                        $currentActiveTime = null;
                        foreach ($displayedSlots as $slot) {
                            $time = $slot['time'];
                            if ($time <= $currentTime) {
                                if ($currentActiveTime === null || $time > $currentActiveTime) {
                                    $currentActiveTime = $time;
                                }
                            }
                        }

                        // Second pass: render the displayed slots
                        foreach ($displayedSlots as $slot):
                            $val = $slot['value'];
                            $time = $slot['time'];
                            $h = intval(substr($time, 0, 2));
                            $isCurrent = ($time === $currentActiveTime);
                            $bgClass = getTimeClass($h);
                            $ruleName = isset($slot['rule_name']) ? trim((string) $slot['rule_name']) : '';
                            $ruleLabel = getRuleDisplayLabel($slot);
                            $ruleColor = getRuleColorForSlot($slot, $scheduleRuleColorMap);
                            $isConditionSlot = (isset($slot['source']) && $slot['source'] === 'condition');

                            $valDisplay = getValueLabel($val);
                            $catClass = 'neutral';
                            if ($val === 'netzero') {
                                $catClass = 'netzero';
                            } elseif ($val === 'netzero-') {
                                $catClass = 'netzero-minus';
                            } elseif ($val === 'netzero+') {
                                $catClass = 'netzero-plus';
                            } elseif (is_numeric($val)) {
                                $catClass = ($val > 0) ? 'charge' : (($val < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <div class="schedule-item <?php echo $bgClass; ?> <?php echo $isCurrent ? 'slot-current' : ''; ?>">
                                <div class="schedule-item-main">
                                    <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?>
                                    </div>
                                    <div class="schedule-item-value <?php echo $catClass; ?>">
                                        <?php echo htmlspecialchars($valDisplay); ?>
                                    </div>
                                </div>
                                <?php if ($isConditionSlot && $ruleName !== ''): ?>
                                    <div class="schedule-item-meta" title="<?php echo htmlspecialchars($ruleLabel); ?>">
                                        <?php if ($ruleColor !== ''): ?>
                                            <span class="schedule-rule-color-dot" style="background: <?php echo htmlspecialchars($ruleColor); ?>;" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span class="schedule-item-rule-name"><?php echo htmlspecialchars($ruleLabel); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tomorrow's Schedule (Right) -->
                <div class="schedule-day" data-day-panel="tomorrow">
                    <div class="schedule-day-header">
                        <h3 class="card-header">Tomorrow <?php echo substr($tomorrow, -2); ?></h3>
                    </div>
                    <div class="schedule-list" id="tomorrow-schedule-grid">
                        <?php
                        $displayedSlots = buildHourlyDisplaySlots($resolvedTomorrow);

                        // Second pass: render the displayed slots (no current time for tomorrow)
                        foreach ($displayedSlots as $slot):
                            $val = $slot['value'];
                            $time = $slot['time'];
                            $h = intval(substr($time, 0, 2));
                            $bgClass = getTimeClass($h);
                            $ruleName = isset($slot['rule_name']) ? trim((string) $slot['rule_name']) : '';
                            $ruleLabel = getRuleDisplayLabel($slot);
                            $ruleColor = getRuleColorForSlot($slot, $scheduleRuleColorMap);
                            $isConditionSlot = (isset($slot['source']) && $slot['source'] === 'condition');

                            $valDisplay = getValueLabel($val);
                            $catClass = 'neutral';
                            if ($val === 'netzero') {
                                $catClass = 'netzero';
                            } elseif ($val === 'netzero-') {
                                $catClass = 'netzero-minus';
                            } elseif ($val === 'netzero+') {
                                $catClass = 'netzero-plus';
                            } elseif (is_numeric($val)) {
                                $catClass = ($val > 0) ? 'charge' : (($val < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <div class="schedule-item <?php echo $bgClass; ?>">
                                <div class="schedule-item-main">
                                    <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?>
                                    </div>
                                    <div class="schedule-item-value <?php echo $catClass; ?>">
                                        <?php echo htmlspecialchars($valDisplay); ?>
                                    </div>
                                </div>
                                <?php if ($isConditionSlot && $ruleName !== ''): ?>
                                    <div class="schedule-item-meta" title="<?php echo htmlspecialchars($ruleLabel); ?>">
                                        <?php if ($ruleColor !== ''): ?>
                                            <span class="schedule-rule-color-dot" style="background: <?php echo htmlspecialchars($ruleColor); ?>;" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span class="schedule-item-rule-name"><?php echo htmlspecialchars($ruleLabel); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Schedule Entries -->
        <div class="schedule-mobile-tab-panel" data-tab="entries" role="tabpanel" aria-hidden="true">
            <div class="schedule-entries-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="card-header">🧾 Schedule Entries</h3>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button class="btn btn-danger" id="clear-entry-btn">Clr</button>
                    <button class="btn btn-add" id="add-entry-btn">Add</button>
                </div>
            </div>
            <div class="status-bar" id="status-bar" style="margin-top:6px; font-size:0.75rem; color:var(--text-tertiary);">
                <span><?php echo count($schedule); ?> entries loaded.</span>
            </div>
            <div class="table-wrapper">
                <table id="schedule-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>Key</th>
                            <th>Value</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sort for display
                        uksort($schedule, 'strcmp');
                        $idx = 0;
                        foreach ($schedule as $k => $entry):
                            $idx++;
                            $v = (is_array($entry) && array_key_exists('value', $entry)) ? $entry['value'] : null;
                            $displayVal = getValueLabel($v);
                            $valClass = 'neutral';
                            if ($v === 'netzero') {
                                $valClass = 'netzero';
                            } elseif ($v === 'netzero-') {
                                $valClass = 'netzero-minus';
                            } elseif ($v === 'netzero+') {
                                $valClass = 'netzero-plus';
                            } elseif (is_numeric($v)) {
                                $valClass = ($v > 0) ? 'charge' : (($v < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <tr data-key="<?php echo htmlspecialchars($k); ?>"
                                data-value="<?php echo htmlspecialchars((string) $v); ?>"
                                data-entry="<?php echo htmlspecialchars(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>">
                                <td style="color:var(--text-tertiary);"><?php echo $idx; ?></td>
                                <td style="font-family:monospace; font-size:0.8rem;"><?php echo htmlspecialchars($k); ?></td>
                                <td class="<?php echo $valClass; ?>" style="font-weight:500;">
                                    <?php echo htmlspecialchars($displayVal); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 3: Rules list (toggle enabled/disabled only) -->
        <div class="schedule-mobile-tab-panel" data-tab="rules" role="tabpanel" aria-hidden="true">
            <div class="schedule-rules-header">
                <h3 class="card-header">⚙️ Rules</h3>
                <div class="schedule-rules-actions">
                    <a class="btn btn-outline" href="edit_rules.php" title="Open full rules editor" target="_blank" rel="noopener">Edit</a>
                    <button type="button" class="btn btn-outline" id="rules-refresh-btn">Reload</button>
                </div>
            </div>
            <div class="schedule-rules-status" id="rules-status"></div>
            <div class="schedule-rules-list-wrap">
                <ul class="schedule-rules-list" id="rules-list" aria-live="polite"></ul>
            </div>
        </div>
    </div>
    </div>
</div>
<script>
window.SCHEDULE_RULE_COLOR_MAP = <?php echo json_encode($scheduleRuleColorMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

(function() {
    var SCHEDULE_PANEL_LS = 'zendure_charge_schedule_panel_show';

    function readSchedulePanelShow() {
        try {
            var v = localStorage.getItem(SCHEDULE_PANEL_LS);
            if (v === null) {
                return true;
            }
            return v !== '0' && v !== 'false';
        } catch (e) {
            return true;
        }
    }

    function writeSchedulePanelShow(on) {
        try {
            localStorage.setItem(SCHEDULE_PANEL_LS, on ? '1' : '0');
        } catch (e) {}
    }

    (function initSchedulePanelShowToggle() {
        var card = document.querySelector('.schedule-mobile-card');
        if (!card) {
            return;
        }
        var body = card.querySelector('[data-role="schedule-panel-body"]');
        var input = card.querySelector('[data-role="schedule-show-toggle"]');
        if (!body || !input) {
            return;
        }
        var show = readSchedulePanelShow();
        input.checked = show;
        body.hidden = !show;

        input.addEventListener('change', function() {
            var on = !!input.checked;
            body.hidden = !on;
            writeSchedulePanelShow(on);
        });
    })();

    var tabs = document.querySelectorAll('.schedule-mobile-card .schedule-mobile-tab');
    var panels = document.querySelectorAll('.schedule-mobile-card .schedule-mobile-tab-panel');
    var rulesApiUrl = 'edit_rules.php?api=1';
    var rulesRefreshBtn = document.getElementById('rules-refresh-btn');
    var rulesStatus = document.getElementById('rules-status');
    var rulesList = document.getElementById('rules-list');
    var rulesLoaded = false;
    var rulesLoading = false;
    var rulesState = [];
    var ruleProfilesState = { active_profile_id: 'show_all', profiles: [] };

    function normalizeRuleProfiles(config, rules) {
        var validRuleIds = {};
        (rules || []).forEach(function(rule) {
            var ruleId = rule && rule.rule_id ? String(rule.rule_id) : '';
            if (ruleId) {
                validRuleIds[ruleId] = true;
            }
        });
        var profiles = Array.isArray(config && config.profiles) ? config.profiles : [];
        var normalizedProfiles = profiles.map(function(profile) {
            var seen = {};
            var ruleIds = Array.isArray(profile && profile.rule_ids) ? profile.rule_ids.filter(function(ruleId) {
                var normalizedId = String(ruleId || '').trim();
                if (!normalizedId || !validRuleIds[normalizedId] || seen[normalizedId]) {
                    return false;
                }
                seen[normalizedId] = true;
                return true;
            }).map(function(ruleId) {
                return String(ruleId).trim();
            }) : [];
            return {
                id: String((profile && profile.id) || '').trim(),
                short_name: String((profile && profile.short_name) || '').trim(),
                description: String((profile && profile.description) || '').trim(),
                rule_ids: ruleIds
            };
        }).filter(function(profile) {
            return !!profile.id && profile.id !== 'show_all';
        });
        var activeProfileId = config && typeof config.active_profile_id === 'string'
            ? String(config.active_profile_id).trim()
            : 'show_all';
        if (activeProfileId !== 'show_all' && !normalizedProfiles.some(function(profile) { return profile.id === activeProfileId; })) {
            activeProfileId = 'show_all';
        }
        return {
            active_profile_id: activeProfileId,
            profiles: normalizedProfiles
        };
    }

    function ruleVisibleInActiveProfile(rule) {
        if (!ruleProfilesState || ruleProfilesState.active_profile_id === 'show_all') {
            return true;
        }
        var profile = (ruleProfilesState.profiles || []).find(function(item) {
            return item.id === ruleProfilesState.active_profile_id;
        });
        return !!(profile && Array.isArray(profile.rule_ids) && profile.rule_ids.indexOf(rule.rule_id) !== -1);
    }

    function setRulesStatus(text, type) {
        if (!rulesStatus) return;
        rulesStatus.className = 'schedule-rules-status' + (type ? (' ' + type) : '');
        rulesStatus.textContent = text || '';
    }

    function renderRulesList() {
        if (!rulesList) return;
        rulesList.innerHTML = '';
        if (!Array.isArray(rulesState) || rulesState.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'schedule-rules-empty';
            empty.textContent = 'No rules found.';
            rulesList.appendChild(empty);
            return;
        }

        var visibleRules = rulesState
            .map(function(rule, idx) { return { rule: rule, idx: idx }; })
            .filter(function(entry) { return ruleVisibleInActiveProfile(entry.rule); });

        if (!visibleRules.length) {
            var emptyFiltered = document.createElement('li');
            emptyFiltered.className = 'schedule-rules-empty';
            emptyFiltered.textContent = 'No rules in the active profile.';
            rulesList.appendChild(emptyFiltered);
            return;
        }

        visibleRules.forEach(function(entry) {
            var rule = entry.rule;
            var idx = entry.idx;
            var li = document.createElement('li');
            li.className = 'schedule-rules-item';

            var label = document.createElement('label');
            label.className = 'schedule-rules-toggle';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = rule && rule.enabled !== false;
            checkbox.setAttribute('data-rule-idx', String(idx));
            checkbox.setAttribute('aria-label', 'Enable rule ' + String((rule && rule.name) || ('#' + (idx + 1))));

            var name = document.createElement('span');
            name.className = 'schedule-rules-name';
            name.textContent = (rule && rule.name) ? String(rule.name) : ('Rule #' + (idx + 1));

            label.appendChild(checkbox);
            label.appendChild(name);
            li.appendChild(label);
            rulesList.appendChild(li);
        });
    }

    async function fetchRules() {
        var res = await fetch(rulesApiUrl, { method: 'GET' });
        var data = await res.json();
        if (!res.ok || !data.success || !Array.isArray(data.rules)) {
            throw new Error((data && data.error) ? data.error : 'Failed to load rules.');
        }
        return data;
    }

    async function saveRules() {
        var res = await fetch(rulesApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rules: rulesState, rule_profiles: ruleProfilesState })
        });
        var data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Failed to save rules.');
        }
        return data;
    }

    async function loadRules(forceReload) {
        if (!rulesList || rulesLoading) return;
        if (!forceReload && rulesLoaded) return;
        rulesLoading = true;
        setRulesStatus('Loading rules...', '');
        if (rulesRefreshBtn) rulesRefreshBtn.disabled = true;
        try {
            var data = await fetchRules();
            var rules = data.rules || [];
            rulesState = rules.map(function(rule) {
                var out = Object.assign({}, rule || {});
                out.enabled = out.enabled !== false;
                return out;
            });
            ruleProfilesState = normalizeRuleProfiles(data.rule_profiles || {}, rulesState);
            renderRulesList();
            setRulesStatus('Loaded ' + rulesState.length + ' rules.', 'ok');
            rulesLoaded = true;
        } catch (e) {
            setRulesStatus(e.message || 'Failed to load rules.', 'error');
        } finally {
            rulesLoading = false;
            if (rulesRefreshBtn) rulesRefreshBtn.disabled = false;
        }
    }

    async function toggleRule(index, enabled, checkbox) {
        if (!Array.isArray(rulesState) || !rulesState[index]) return;
        var prev = rulesState[index].enabled !== false;
        rulesState[index].enabled = !!enabled;
        setRulesStatus('Saving...', '');
        if (checkbox) checkbox.disabled = true;
        try {
            var result = await saveRules();
            if (result && Array.isArray(result.rules)) {
                rulesState = result.rules.map(function(rule) {
                    var out = Object.assign({}, rule || {});
                    out.enabled = out.enabled !== false;
                    return out;
                });
            }
            if (result) {
                ruleProfilesState = normalizeRuleProfiles(result.rule_profiles || ruleProfilesState, rulesState);
            }
            renderRulesList();
            setRulesStatus((enabled ? 'Enabled' : 'Disabled') + ' "' + String(rulesState[index].name || ('Rule #' + (index + 1))) + '".', 'ok');
        } catch (e) {
            rulesState[index].enabled = prev;
            if (checkbox) checkbox.checked = prev;
            setRulesStatus(e.message || 'Failed to save rule toggle.', 'error');
        } finally {
            if (checkbox) checkbox.disabled = false;
        }
    }
    var daySwipe = document.getElementById('schedule-days-swipe');
    var dayPanels = daySwipe ? daySwipe.querySelectorAll('.schedule-day[data-day-panel]') : [];
    var dayChips = document.querySelectorAll('.schedule-mobile-card [data-day-chip]');

    function setActiveDay(day) {
        if (!dayChips.length) return;
        dayChips.forEach(function(chip) {
            var isActive = chip.getAttribute('data-day-chip') === day;
            chip.classList.toggle('active', isActive);
            chip.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    function initDaySwipe() {
        if (!daySwipe || !dayPanels.length || !dayChips.length) return;

        dayChips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                var day = this.getAttribute('data-day-chip');
                var target = daySwipe.querySelector('.schedule-day[data-day-panel="' + day + '"]');
                if (!target) return;
                daySwipe.scrollTo({
                    left: target.offsetLeft,
                    behavior: 'smooth'
                });
                setActiveDay(day);
            });
        });

        var ticking = false;
        daySwipe.addEventListener('scroll', function() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function() {
                var closestDay = 'today';
                var closestDistance = Number.POSITIVE_INFINITY;
                dayPanels.forEach(function(panel) {
                    var day = panel.getAttribute('data-day-panel');
                    var dist = Math.abs(panel.offsetLeft - daySwipe.scrollLeft);
                    if (dist < closestDistance) {
                        closestDistance = dist;
                        closestDay = day;
                    }
                });
                setActiveDay(closestDay);
                ticking = false;
            });
        }, { passive: true });

        setActiveDay('today');
    }

    if (daySwipe) {
        initDaySwipe();
    }

    if (rulesRefreshBtn) {
        rulesRefreshBtn.addEventListener('click', function() {
            loadRules(true);
        });
    }

    if (rulesList) {
        rulesList.addEventListener('change', function(e) {
            var checkbox = e.target.closest('input[type="checkbox"][data-rule-idx]');
            if (!checkbox) return;
            var index = Number(checkbox.getAttribute('data-rule-idx'));
            if (!Number.isInteger(index)) return;
            toggleRule(index, checkbox.checked, checkbox);
        });
    }

    if (!tabs.length || !panels.length) return;
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var targetTab = this.getAttribute('data-tab');
            tabs.forEach(function(t) {
                t.classList.toggle('active', t.getAttribute('data-tab') === targetTab);
                t.setAttribute('aria-selected', t.getAttribute('data-tab') === targetTab ? 'true' : 'false');
            });
            panels.forEach(function(panel) {
                var isActive = panel.getAttribute('data-tab') === targetTab;
                panel.classList.toggle('active', isActive);
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
            if (targetTab === 'rules') {
                loadRules(false);
            }
        });
    });
})();
</script>
</div><!-- /.layout -->
