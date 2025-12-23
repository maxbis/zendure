<?php
/**
 * Charge Schedule Manager
 * View, edit, and visualize charge/discharge schedule.
 */

require_once __DIR__ . '/api/charge_schedule_functions.php';

$dataFile = __DIR__ . '/../data/charge_schedule.json';

// Initial Server-Side Render Data
$schedule = loadSchedule($dataFile);
$today = isset($_GET['initial_date']) ? $_GET['initial_date'] : date('Ymd');
$resolvedToday = resolveScheduleForDate($schedule, $today);
$currentHour = date('H') . '00';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Charge Schedule Manager</title>
    <style>
        /* CSS Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.4rem;
            font-weight: 700;
            color: #64b5f6;
            margin-bottom: 8px;
        }

        .header p {
            color: #666;
            font-size: 1rem;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
            gap: 20px;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: #fafafa;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 24px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .card h2 {
            font-size: 1.3rem;
            margin-bottom: 16px;
            color: #333;
        }

        .schedule-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-height: 600px;
            overflow-y: auto;
            flex: 1 1 auto;
        }

        .schedule-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
        }

        .schedule-item:hover {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .schedule-item-time {
            min-width: 80px;
            font-weight: 600;
            color: #333;
        }

        .schedule-item-value {
            flex: 1;
            font-weight: 600;
            font-size: 1.1rem;
            margin-left: 16px;
        }

        .schedule-item-key {
            font-size: 0.75rem;
            color: #888;
            font-family: monospace;
            margin-left: 16px;
        }

        .time-night {
            background-color: #e3f2fd;
        }

        .time-morning {
            background-color: #fff9e6;
        }

        .time-afternoon {
            background-color: #e8f5e9;
        }

        .time-evening {
            background-color: #fce4ec;
        }

        .slot-current {
            border-color: #ffb300;
            box-shadow: 0 0 0 2px rgba(255, 179, 0, 0.3);
            font-weight: 600;
        }

        .charge {
            color: #1e88e5;
        }

        .discharge {
            color: #e53935;
        }

        .neutral {
            color: #757575;
        }

        .netzero {
            color: #7b1fa2;
            font-weight: 700;
        }

        /* Right Panel */
        .table-wrapper {
            max-height: 600px;
            overflow-y: auto;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }

        th {
            background: #f0f0f0;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .badge {
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 0.7rem;
            display: inline-block;
        }

        .badge-exact {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .badge-wildcard {
            background: #ffecb3;
            color: #f57c00;
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.85rem;
            cursor: pointer;
            font-weight: 500;
            transition: transform 0.1s;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #64b5f6;
            color: #fff;
        }

        .btn-danger {
            background: #ef5350;
            color: #fff;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #bbb;
            color: #555;
        }

        .btn-icon {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px;
            font-size: 1.1rem;
        }

        .btn-icon:hover {
            background: #e0e0e0;
            border-radius: 4px;
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-backdrop.active {
            display: flex;
            animation: fadeIn 0.15s ease-out;
        }

        .modal-dialog {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 420px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #888;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 4px;
        }

        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .helper-text {
            font-size: 0.75rem;
            color: #888;
            margin-top: 4px;
        }

        .modal-footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>⚡ Charge Schedule Manager</h1>
            <p>Manage charge and discharge targets with wildcard pattern matching.</p>
        </div>

        <div class="layout">
            <!-- Left Panel: Today's Schedule -->
            <div class="card">
                <h2>Today's Schedule (<?php echo htmlspecialchars($today); ?>)</h2>
                <div class="helper-text" style="margin-bottom: 10px;">
                    Showing value changes only. Highlight = Current Hour.
                </div>
                <div class="schedule-list" id="today-schedule-grid">
                    <?php
                    function getTimeClass($h)
                    {
                        return ($h >= 22 || $h < 6) ? 'time-night' : (($h < 12) ? 'time-morning' : (($h < 18) ? 'time-afternoon' : 'time-evening'));
                    }
                    $prevVal = null;
                    foreach ($resolvedToday as $slot):
                        $val = $slot['value'];
                        // Filter logic: Only show changes or first item
                        if (
                            $prevVal !== null &&
                            (($val === $prevVal) || ($val === 'netzero' && $prevVal === 'netzero'))
                        ) {
                            continue;
                        }
                        $prevVal = $val;

                        $time = $slot['time'];
                        $h = intval(substr($time, 0, 2));
                        $isCurrent = ($time === $currentHour);
                        $bgClass = getTimeClass($h);

                        $valDisplay = '-';
                        $catClass = 'neutral';
                        if ($val === 'netzero') {
                            $valDisplay = 'Net Zero';
                            $catClass = 'netzero';
                        } elseif (is_numeric($val)) {
                            $valDisplay = ($val > 0 ? '+' : '') . intval($val) . ' W';
                            $catClass = ($val > 0) ? 'charge' : (($val < 0) ? 'discharge' : 'neutral');
                        }
                        ?>
                        <div class="schedule-item <?php echo $bgClass; ?> <?php echo $isCurrent ? 'slot-current' : ''; ?>">
                            <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?>
                            </div>
                            <div class="schedule-item-value <?php echo $catClass; ?>">
                                <?php echo htmlspecialchars($valDisplay); ?>
                            </div>
                            <?php if ($slot['key']): ?>
                                <div class="schedule-item-key"><?php echo htmlspecialchars($slot['key']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right Panel: Schedule Entries -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h2>Schedule Entries</h2>
                    <button class="btn btn-primary" id="add-entry-btn">Add Entry</button>
                </div>
                <div class="status-bar" id="status-bar" style="margin-top:5px; font-size:0.8rem; color:#666;">
                    <span><?php echo count($schedule); ?> entries loaded.</span>
                </div>
                <div class="table-wrapper">
                    <table id="schedule-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Key</th>
                                <th>Value</th>
                                <th>Type</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sort for display
                            uksort($schedule, 'strcmp');
                            $idx = 0;
                            foreach ($schedule as $k => $v):
                                $idx++;
                                $isWild = strpos($k, '*') !== false;
                                $displayVal = ($v === 'netzero') ? 'Net Zero' : $v . ' W';
                                $valClass = ($v === 'netzero') ? 'netzero' : ($v > 0 ? 'charge' : ($v < 0 ? 'discharge' : 'neutral'));
                                ?>
                                <tr data-key="<?php echo htmlspecialchars($k); ?>"
                                    data-value="<?php echo htmlspecialchars($v); ?>">
                                    <td style="color:#888;"><?php echo $idx; ?></td>
                                    <td style="font-family:monospace;"><?php echo htmlspecialchars($k); ?></td>
                                    <td class="<?php echo $valClass; ?>" style="font-weight:500;">
                                        <?php echo htmlspecialchars($displayVal); ?>
                                    </td>
                                    <td><span
                                            class="badge <?php echo $isWild ? 'badge-wildcard' : 'badge-exact'; ?>"><?php echo $isWild ? 'Wildcard' : 'Exact'; ?></span>
                                    </td>
                                    <td><button class="btn-icon btn-edit">✏️</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal-backdrop" id="edit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title" id="modal-title">Edit Entry</div>
                <button class="modal-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>Date Pattern (YYYYMMDD)</label>
                        <input type="text" id="inp-date" maxlength="8" placeholder="20251222">
                    </div>
                    <div class="form-group">
                        <label>Time Pattern (HHmm)</label>
                        <input type="text" id="inp-time" maxlength="4" placeholder="0800">
                    </div>
                </div>
                <div class="helper-text" style="margin-bottom:15px;">Use <code>*</code> for wildcards.</div>

                <div class="form-group">
                    <label>Value Mode</label>
                    <div style="display:flex; gap:15px; margin-top:5px;">
                        <label><input type="radio" name="val-mode" value="fixed" checked> Fixed Watts (W)</label>
                        <label><input type="radio" name="val-mode" value="netzero"> Net Zero</label>
                    </div>
                </div>
                <div class="form-group" id="group-watts">
                    <label>Watts (Positive = Charge, Negative = Discharge)</label>
                    <input type="number" id="inp-watts" placeholder="0">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" id="btn-delete" style="display:none;">Delete</button>
                <div style="margin-left:auto; display:flex; gap:10px;">
                    <button class="btn btn-outline" id="btn-cancel">Cancel</button>
                    <button class="btn btn-primary" id="btn-save">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_URL = 'http://localhost/Energy/schedule/api/charge_schedule_api.php';
        let currentOriginalKey = null;

        // --- UI Rendering ---

        function renderToday(resolved, currentHour) {
            const container = document.getElementById('today-schedule-grid');
            container.innerHTML = '';

            let prevVal = null;

            resolved.forEach(slot => {
                const val = slot.value;
                // Filter logic
                if (prevVal !== null &&
                    ((val === prevVal) || (val === 'netzero' && prevVal === 'netzero'))) {
                    return;
                }
                prevVal = val;

                const time = String(slot.time);
                const h = parseInt(time.substring(0, 2));
                const isCurrent = (time === currentHour);

                let bgClass = 'time-evening';
                if (h >= 22 || h < 6) bgClass = 'time-night';
                else if (h < 12) bgClass = 'time-morning';
                else if (h < 18) bgClass = 'time-afternoon';

                let valText = '-';
                let valClass = 'neutral';
                if (val === 'netzero') {
                    valText = 'Net Zero';
                    valClass = 'netzero';
                } else if (val !== null) {
                    valText = (val > 0 ? '+' : '') + val + ' W';
                    valClass = (val > 0) ? 'charge' : ((val < 0) ? 'discharge' : 'neutral');
                }

                const div = document.createElement('div');
                div.className = `schedule-item ${bgClass} ${isCurrent ? 'slot-current' : ''}`;
                div.innerHTML = `
                    <div class="schedule-item-time">${time.substring(0, 2)}:${time.substring(2, 4)}</div>
                    <div class="schedule-item-value ${valClass}">${valText}</div>
                    ${slot.key ? `<div class="schedule-item-key">${slot.key}</div>` : ''}
                `;
                container.appendChild(div);
            });
        }

        function renderEntries(entries) {
            const tbody = document.querySelector('#schedule-table tbody');
            tbody.innerHTML = '';

            // Sort entries same as PHP: key asc
            entries.sort((a, b) => String(a.key).localeCompare(String(b.key)));

            entries.forEach((entry, idx) => {
                const tr = document.createElement('tr');
                tr.dataset.key = entry.key;
                tr.dataset.value = entry.value;

                // Ensure key is string (PHP might send int for numeric keys)
                const keyStr = String(entry.key);
                const isWild = keyStr.includes('*');
                let displayVal = entry.value === 'netzero' ? 'Net Zero' : entry.value + ' W';
                let valClass = entry.value === 'netzero' ? 'netzero' : (entry.value > 0 ? 'charge' : (entry.value < 0 ? 'discharge' : 'neutral'));

                tr.innerHTML = `
                    <td style="color:#888;">${idx + 1}</td>
                    <td style="font-family:monospace;">${keyStr}</td>
                    <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
                    <td><span class="badge ${isWild ? 'badge-wildcard' : 'badge-exact'}">${isWild ? 'Wildcard' : 'Exact'}</span></td>
                    <td><button class="btn-icon btn-edit">✏️</button></td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function refreshData() {
            try {
                const res = await fetch(API_URL);
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Server returned non-JSON response. Check console for details.');
                }
                
                const data = await res.json();
                if (data.success) {
                    renderEntries(data.entries);
                    renderToday(data.resolved, data.currentHour);
                    document.getElementById('status-bar').innerHTML = `<span>${data.entries.length} entries loaded.</span>`;
                }
            } catch (e) {
                console.error(e);
                alert('Connection failed: ' + e.message);
            }
        }

        // --- Modal & Actions ---

        const modal = document.getElementById('edit-modal');
        function openModal(key = null, value = null) {
            currentOriginalKey = key;
            const isAdd = (key === null);
            document.getElementById('modal-title').innerText = isAdd ? 'Add Schedule Entry' : 'Edit Schedule Entry';
            document.getElementById('btn-delete').style.display = isAdd ? 'none' : 'block';

            if (isAdd) {
                // Calculate next full hour
                const now = new Date();
                const nextHour = new Date(now);
                nextHour.setHours(now.getHours() + 1, 0, 0, 0);
                
                // Format date as YYYYMMDD
                const year = nextHour.getFullYear();
                const month = String(nextHour.getMonth() + 1).padStart(2, '0');
                const day = String(nextHour.getDate()).padStart(2, '0');
                const dateStr = `${year}${month}${day}`;
                
                // Format time as HHmm
                const hours = String(nextHour.getHours()).padStart(2, '0');
                const timeStr = `${hours}00`;
                
                document.getElementById('inp-date').value = dateStr;
                document.getElementById('inp-time').value = timeStr;
                document.getElementById('inp-watts').value = '0';
                document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
                document.getElementById('group-watts').style.display = 'block';
                document.getElementById('inp-watts').disabled = false;
            } else {
                document.getElementById('inp-date').value = key.substring(0, 8);
                document.getElementById('inp-time').value = key.substring(8, 12);

                if (value === 'netzero') {
                    document.querySelector('input[name="val-mode"][value="netzero"]').checked = true;
                    document.getElementById('group-watts').style.display = 'block';
                    document.getElementById('inp-watts').disabled = true;
                    document.getElementById('inp-watts').value = '';
                } else {
                    document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
                    document.getElementById('group-watts').style.display = 'block';
                    document.getElementById('inp-watts').disabled = false;
                    document.getElementById('inp-watts').value = value;
                }
            }
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        // Event Listeners
        document.getElementById('add-entry-btn').onclick = () => openModal();
        document.getElementById('modal-close').onclick = closeModal;
        document.getElementById('btn-cancel').onclick = closeModal;
        document.getElementById('edit-modal').onclick = (e) => { if (e.target === modal) closeModal(); };

        // Mode toggle
        document.querySelectorAll('input[name="val-mode"]').forEach(r => {
            r.onchange = () => {
                const wattsInput = document.getElementById('inp-watts');
                if (r.value === 'netzero') {
                    wattsInput.disabled = true;
                    wattsInput.value = '';
                    wattsInput.setAttribute('value', '');
                } else {
                    wattsInput.disabled = false;
                }
            };
            // Also handle click to ensure it works
            r.onclick = () => {
                if (r.value === 'netzero') {
                    const wattsInput = document.getElementById('inp-watts');
                    wattsInput.disabled = true;
                    wattsInput.value = '';
                    wattsInput.setAttribute('value', '');
                }
            };
        });

        // Edit button delegation
        document.querySelector('#schedule-table tbody').addEventListener('click', (e) => {
            if (e.target.closest('.btn-edit')) {
                const tr = e.target.closest('tr');
                openModal(tr.dataset.key, tr.dataset.value);
            }
        });

        // Delete
        document.getElementById('btn-delete').onclick = async () => {
            if (!currentOriginalKey || !confirm('Delete this entry?')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ key: currentOriginalKey })
                });
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const json = await res.json();
                if (json.success) {
                    closeModal();
                    refreshData();
                } else {
                    alert(json.error || 'Delete failed');
                }
            } catch (e) {
                console.error(e);
                alert('Delete failed');
            }
        };

        // Save
        document.getElementById('btn-save').onclick = async () => {
            const d = document.getElementById('inp-date').value.trim();
            const t = document.getElementById('inp-time').value.trim();
            if (d.length !== 8 || t.length !== 4) return alert('Invalid Date/Time pattern length');

            const mode = document.querySelector('input[name="val-mode"]:checked').value;
            let val;
            if (mode === 'netzero') val = 'netzero';
            else {
                val = document.getElementById('inp-watts').value;
                if (val === '') return alert('Enter watts value');
                val = parseInt(val);
            }

            const key = d + t;
            const payload = { key, value: val };

            let method = 'POST';
            if (currentOriginalKey) {
                method = 'PUT';
                payload.originalKey = currentOriginalKey;
            }

            try {
                const res = await fetch(API_URL, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new Error('Server returned non-JSON response. Check console for details.');
                }
                
                const json = await res.json();
                if (json.success) {
                    closeModal();
                    refreshData();
                } else {
                    alert(json.error || 'Save failed');
                }
            } catch (e) {
                console.error(e);
                alert('Save failed: ' + e.message);
            }
        };

    </script>
</body>

</html>