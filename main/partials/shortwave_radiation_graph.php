<?php
$shortwaveGraphId = 'shortwave-radiation-' . uniqid();
$shortwaveApiUrl = 'api/shortwave_radiation_api.php';
?>
<div class="shortwave-radiation-block">
    <div class="card shortwave-radiation-card" id="<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?>">
        <h3 class="card-header">Shortwave Radiation <span class="shortwave-radiation-card__unit">(W/m²)</span></h3>
        <div class="shortwave-radiation-card__status" data-role="status" hidden></div>
        <div class="shortwave-radiation-card__viewport" data-role="viewport" hidden>
            <div class="shortwave-radiation-card__content" data-role="content">
                <div class="shortwave-radiation-card__chart" data-role="chart"></div>
                <div class="shortwave-radiation-card__days" data-role="days"></div>
            </div>
        </div>
    </div>
</div>
<style>
    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> {
        background: var(--bg-secondary);
        border-radius: 20px;
        padding: 10px 12px 8px;
        color: #dfe9f5;
        overflow: hidden;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__unit {
        font-size: 0.85rem;
        color: var(--text-tertiary);
        font-weight: 500;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__status {
        min-height: 0;
        font-size: 0.82rem;
        color: #c7d2dc;
        text-align: center;
        padding: 0 0 8px;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__status.is-error {
        color: #ff9b9b;
        display: block;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__viewport {
        background: var(--bg-tertiary);
        border-radius: 10px;
        overflow-x: auto;
        overflow-y: hidden;
        padding-bottom: 2px;
        scrollbar-width: thin;
        scrollbar-color: rgba(159, 211, 255, 0.55) rgba(255, 255, 255, 0.08);
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__viewport::-webkit-scrollbar {
        height: 8px;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__viewport::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.08);
        border-radius: 999px;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__viewport::-webkit-scrollbar-thumb {
        background: rgba(159, 211, 255, 0.55);
        border-radius: 999px;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__content {
        min-width: 100%;
        padding: 8px 20px 0px 8px;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__chart svg {
        display: block;
        width: 100%;
        height: auto;
    }

    #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> .shortwave-radiation-card__days {
        display: none;
    }

    @media (max-width: 640px) {
        #<?php echo htmlspecialchars($shortwaveGraphId, ENT_QUOTES, 'UTF-8'); ?> {
            padding: 9px 10px 7px;
            border-radius: 16px;
        }
    }
</style>
<script>
    (function() {
        var MAX_DISPLAY_AGE_MS = 4 * 60 * 60 * 1000;
        var root = document.getElementById(<?php echo json_encode($shortwaveGraphId, JSON_UNESCAPED_SLASHES); ?>);
        if (!root) return;

        var statusEl = root.querySelector('[data-role="status"]');
        var viewportEl = root.querySelector('[data-role="viewport"]');
        var contentEl = root.querySelector('[data-role="content"]');
        var chartEl = root.querySelector('[data-role="chart"]');
        var daysEl = root.querySelector('[data-role="days"]');
        var apiUrl = <?php echo json_encode($shortwaveApiUrl, JSON_UNESCAPED_SLASHES); ?>;
        var latestPayload = null;
        var activeRequest = null;
        var hasRenderError = false;
        var resizeRaf = 0;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function setStatus(message, isError) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.classList.toggle('is-error', !!isError);
            statusEl.hidden = !isError;
            if (viewportEl) viewportEl.hidden = true;
            if (chartEl) chartEl.hidden = true;
            if (daysEl) daysEl.hidden = true;
        }

        function getCachedAtMs(payload) {
            if (!payload || payload.cachedAt == null) return null;
            var cachedAtSeconds = Number(payload.cachedAt);
            if (!Number.isFinite(cachedAtSeconds) || cachedAtSeconds <= 0) return null;
            return cachedAtSeconds * 1000;
        }

        function shouldRefresh() {
            if (!latestPayload || hasRenderError) {
                return true;
            }

            var cachedAtMs = getCachedAtMs(latestPayload);
            if (cachedAtMs === null) {
                return true;
            }

            return (Date.now() - cachedAtMs) >= MAX_DISPLAY_AGE_MS;
        }

        function fetchPayload() {
            return fetch(apiUrl, { method: 'GET' })
                .then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('Shortwave radiation response is not valid JSON.');
                    }).then(function(payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload && payload.error ? payload.error : 'Failed to load shortwave radiation.');
                        }
                        return payload;
                    });
                });
        }

        function formatDate(timestamp) {
            var date = new Date(timestamp);
            if (Number.isNaN(date.getTime())) return null;
            return date;
        }

        function buildDaySummaries(times, values, dailyUnit) {
            var groups = [];

            times.forEach(function(timestamp, index) {
                var date = formatDate(timestamp);
                if (!date) return;
                var key = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                if (!groups[key]) {
                    groups[key] = {
                        weekday: date.toLocaleDateString('en-GB', { weekday: 'short' }),
                        dateLabel: String(date.getDate()).padStart(2, '0') + '-' + String(date.getMonth() + 1).padStart(2, '0'),
                        total: 0
                    };
                }
                groups[key].total += Number(values[index] || 0);
            });

            return Object.keys(groups).map(function(key) {
                var group = groups[key];
                return {
                    date: key,
                    weekday: group.weekday,
                    dateLabel: group.dateLabel,
                    total: Math.round(group.total) + ' ' + dailyUnit
                };
            });
        }

        function buildChartData(payload, viewportWidth) {
            var hourly = payload && payload.hourly ? payload.hourly : null;
            var times = hourly && Array.isArray(hourly.time) ? hourly.time : [];
            var values = hourly && Array.isArray(hourly.shortwave_radiation) ? hourly.shortwave_radiation.map(function(value) {
                return Number(value || 0);
            }) : [];

            if (!times.length || times.length !== values.length) {
                throw new Error('Shortwave radiation data is unavailable.');
            }

            var days = buildDaySummaries(times, values, payload.unit ? String(payload.unit) : 'Wh/m²');
            var visibleWidth = Math.max(320, Math.floor(viewportWidth || root.clientWidth || 320));
            var perDayWidth = Math.max(100, visibleWidth / 4.2);
            var width = Math.max(visibleWidth, Math.round(days.length * perDayWidth));
            var height = 176;
            var paddingLeft = 38;
            var paddingRight = 28;
            var paddingTop = 8;
            var paddingBottom = 56;
            var plotWidth = width - paddingLeft - paddingRight;
            var plotHeight = height - paddingTop - paddingBottom;
            var maxValue = values.reduce(function(max, value) {
                return value > max ? value : max;
            }, 0);
            var scaleMax = Math.max(50, Math.ceil(maxValue / 50) * 50);
            var dayIndexByKey = {};
            days.forEach(function(day, index) {
                dayIndexByKey[day.date] = index;
            });

            var points = values.map(function(value, index) {
                var date = formatDate(times[index]);
                var key = date ? date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0') : null;
                var currentDayIndex = key !== null && Object.prototype.hasOwnProperty.call(dayIndexByKey, key) ? dayIndexByKey[key] : 0;
                var hourFraction = date ? (date.getHours() + (date.getMinutes() / 60)) / 24 : 0;
                var x = paddingLeft + ((currentDayIndex + hourFraction) / Math.max(1, days.length)) * plotWidth;
                var y = paddingTop + plotHeight - ((value / scaleMax) * plotHeight);
                return {
                    x: x,
                    y: y
                };
            });
            var yAxis = [];
            for (var step = 0; step <= 5; step += 1) {
                yAxis.push({
                    y: paddingTop + plotHeight - (step / 5) * plotHeight,
                    label: String(Math.round((scaleMax / 5) * step))
                });
            }

            var xAxis = [];
            days.forEach(function(day, dayIdx) {
                [0, 6, 12, 18].forEach(function(hour) {
                    var x = paddingLeft + ((dayIdx + (hour / 24)) / Math.max(1, days.length)) * plotWidth;
                    xAxis.push({
                        x: x,
                        label: hour === 0 ? day.dateLabel : (String(hour).padStart(2, '0') + ':00'),
                        isDateChange: hour === 0
                    });
                });
            });

            var labelY = paddingTop + 10;
            var dayLabels = days.map(function(day, index) {
                var centerX = paddingLeft + index * (perDayWidth-8) + 55;
                return {
                    x: centerX,
                    y: labelY,
                    text: day.weekday + ' ' + day.total
                };
            });

            return {
                visibleWidth: visibleWidth,
                width: width,
                height: height,
                paddingLeft: paddingLeft,
                paddingRight: paddingRight,
                paddingTop: paddingTop,
                paddingBottom: paddingBottom,
                yAxis: yAxis,
                xAxis: xAxis,
                polyline: points.map(function(point) {
                    return point.x.toFixed(2) + ',' + point.y.toFixed(2);
                }).join(' '),
                polygon: [paddingLeft.toFixed(2) + ',' + (height - paddingBottom).toFixed(2)]
                    .concat(points.map(function(point) {
                        return point.x.toFixed(2) + ',' + point.y.toFixed(2);
                    }))
                    .concat([(width - paddingRight).toFixed(2) + ',' + (height - paddingBottom).toFixed(2)])
                    .join(' '),
                hourlyUnit: payload.hourly_units && payload.hourly_units.shortwave_radiation ? String(payload.hourly_units.shortwave_radiation) : 'W/m²',
                dailyUnit: payload.unit ? String(payload.unit) : 'Wh/m²',
                perDayWidth: perDayWidth,
                days: days,
                dayLabels: dayLabels
            };
        }

        function render(payload) {
            latestPayload = payload;
            hasRenderError = false;
            var viewportWidth = viewportEl ? viewportEl.clientWidth : root.clientWidth;
            var chart = buildChartData(payload, viewportWidth);
            var bottomY = chart.height - chart.paddingBottom;
            var svg = [
                '<svg viewBox="0 0 ' + chart.width + ' ' + chart.height + '" role="img" aria-label="Shortwave radiation chart">',
                chart.yAxis.map(function(tick) {
                    return '<line x1="' + chart.paddingLeft + '" y1="' + tick.y + '" x2="' + (chart.width - chart.paddingRight) + '" y2="' + tick.y + '" stroke="rgba(255,255,255,0.12)" stroke-width="1"></line>' +
                        '<text x="' + (chart.paddingLeft - 7) + '" y="' + (tick.y + 4) + '" text-anchor="end" font-size="11" fill="#b0b0b0">' + escapeHtml(tick.label) + '</text>';
                }).join(''),
                '<line x1="' + chart.paddingLeft + '" y1="' + bottomY + '" x2="' + (chart.width - chart.paddingRight) + '" y2="' + bottomY + '" stroke="rgba(255,255,255,0.2)" stroke-width="1"></line>',
                '<line x1="' + chart.paddingLeft + '" y1="' + chart.paddingTop + '" x2="' + chart.paddingLeft + '" y2="' + bottomY + '" stroke="rgba(255,255,255,0.2)" stroke-width="1"></line>',
                '<polygon points="' + chart.polygon + '" fill="rgba(126,200,255,0.22)"></polygon>',
                chart.days.map(function(day, index) {
                    return [0.25, 0.5, 0.75].map(function(offset) {
                        var markerX = chart.paddingLeft + (((index + offset) / Math.max(1, chart.days.length)) * (chart.width - chart.paddingLeft - chart.paddingRight));
                        return '<line x1="' + markerX + '" y1="' + chart.paddingTop + '" x2="' + markerX + '" y2="' + bottomY + '" stroke="rgba(126,200,255,0.35)" stroke-width="1" stroke-dasharray="4 4"></line>';
                    }).join('');
                }).join(''),
                chart.days.map(function(day, index) {
                    if (index === 0) return '';
                    var separatorX = chart.paddingLeft + ((index / Math.max(1, chart.days.length)) * (chart.width - chart.paddingLeft - chart.paddingRight));
                    return '<line x1="' + separatorX + '" y1="' + chart.paddingTop + '" x2="' + separatorX + '" y2="' + bottomY + '" stroke="#888" stroke-width="1"></line>';
                }).join(''),
                '<polyline points="' + chart.polyline + '" fill="none" stroke="#7ec8ff" stroke-width="1" stroke-linejoin="round" stroke-linecap="round"></polyline>',
                chart.xAxis.map(function(tick) {
                    return '<line x1="' + tick.x + '" y1="' + bottomY + '" x2="' + tick.x + '" y2="' + (bottomY + 8) + '" stroke="rgba(255,255,255,0.18)" stroke-width="1"></line>' +
                        '<text x="' + tick.x + '" y="' + (bottomY + 31) + '" text-anchor="start" font-size="11" font-weight="400" fill="' + (tick.isDateChange ? '#5fb0f3' : '#c2c2c2') + '" transform="rotate(-38 ' + tick.x + ' ' + (bottomY + 31) + ')">' + escapeHtml(tick.label) + '</text>';
                }).join(''),
                chart.dayLabels.map(function(label) {
                    return '<text x="' + label.x + '" y="' + label.y + '" text-anchor="middle" font-size="12" font-weight="500" fill="rgba(220,233,245,0.92)" stroke="rgba(47,47,47,0.65)" stroke-width="2" paint-order="stroke">' + escapeHtml(label.text) + '</text>';
                }).join(''),
                '</svg>'
            ].join('');

            if (contentEl) {
                contentEl.style.width = chart.width + 'px';
                contentEl.style.minWidth = chart.width + 'px';
            }
            chartEl.innerHTML = svg;
            chartEl.hidden = false;
            chartEl.style.width = chart.width + 'px';
            daysEl.hidden = false;
            statusEl.hidden = true;
            if (viewportEl) viewportEl.hidden = false;
        }

        function refreshShortwaveRadiationGraph(options) {
            options = options || {};

            if (activeRequest) {
                return activeRequest;
            }

            if (!options.force && !shouldRefresh()) {
                return Promise.resolve();
            }

            activeRequest = fetchPayload()
                .then(function(payload) {
                    render(payload);
                })
                .catch(function(error) {
                    hasRenderError = true;
                    setStatus(error && error.message ? error.message : 'Failed to load shortwave radiation.', true);
                    console.warn('Shortwave radiation refresh failed:', error);
                })
                .finally(function() {
                    activeRequest = null;
                });

            return activeRequest;
        }

        function queueResizeRender() {
            if (!latestPayload) return;
            if (resizeRaf) cancelAnimationFrame(resizeRaf);
            resizeRaf = requestAnimationFrame(function() {
                resizeRaf = 0;
                render(latestPayload);
            });
        }

        window.refreshShortwaveRadiationGraph = refreshShortwaveRadiationGraph;

        refreshShortwaveRadiationGraph({ force: true }).catch(function() {
            return null;
        });

        window.addEventListener('resize', queueResizeRender);
    })();
</script>
