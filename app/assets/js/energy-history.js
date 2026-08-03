(function () {
    "use strict";

    const config = Object.freeze({
        energyHistoryUrl: "../main/api/app_energy_history.php?days=3",
        ...(window.GRAPHITE_APP_CONFIG || {})
    });

    const component = document.querySelector('[data-component="energy-history"]');
    if (!component) return;

    const elements = {
        loading: component.querySelector('[data-role="energy-history-loading"]'),
        error: component.querySelector('[data-role="energy-history-error"]'),
        errorMessage: component.querySelector('[data-role="energy-history-error-message"]'),
        content: component.querySelector('[data-role="energy-history-content"]'),
        date: component.querySelector('[data-role="energy-history-date"]'),
        refresh: component.querySelector('[data-role="energy-history-refresh"]'),
        retry: component.querySelector('[data-role="energy-history-retry"]'),
        chartScroll: component.querySelector('[data-role="energy-chart-scroll"]'),
        chart: component.querySelector('[data-role="energy-chart"]'),
        detail: component.querySelector('[data-role="energy-hour-detail"]'),
        detailTime: component.querySelector('[data-role="energy-detail-time"]'),
        detailFlow: component.querySelector('[data-role="energy-detail-flow"]'),
        detailBattery: component.querySelector('[data-role="energy-detail-battery"]'),
        summary: component.querySelector('[data-role="energy-summary"]'),
        summaryPeriod: component.querySelector('[data-role="energy-summary-period"]'),
        charged: component.querySelector('[data-role="energy-total-charged"]'),
        discharged: component.querySelector('[data-role="energy-total-discharged"]'),
        net: component.querySelector('[data-role="energy-total-net"]'),
        chargedConsumer: component.querySelector('[data-role="energy-charged-consumer"]'),
        chargedSpot: component.querySelector('[data-role="energy-charged-spot"]'),
        dischargedConsumer: component.querySelector('[data-role="energy-discharged-consumer"]'),
        dischargedSpot: component.querySelector('[data-role="energy-discharged-spot"]'),
        netConsumer: component.querySelector('[data-role="energy-net-consumer"]'),
        netSpot: component.querySelector('[data-role="energy-net-spot"]'),
        status: component.querySelector('[data-role="energy-history-status"]')
    };

    const SVG_NS = "http://www.w3.org/2000/svg";
    const compactChartMedia = window.matchMedia("(max-width: 600px)");
    const CHART_LAYOUTS = Object.freeze({
        compact: Object.freeze({ height: 170, margin: Object.freeze({ top: 12, right: 36, bottom: 28, left: 44 }) }),
        standard: Object.freeze({ height: 250, margin: Object.freeze({ top: 18, right: 48, bottom: 40, left: 54 }) })
    });
    let payload = null;
    let availableDays = [];
    let selectedDay = null;
    let activeController = null;

    function localDateKey(date = new Date()) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
    }

    function parseDateKey(value) {
        return new Date(`${value}T12:00:00`);
    }

    function splitHourLabel(label) {
        const match = String(label || "").match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}):/);
        return match ? { day: match[1], hour: Number(match[2]) } : null;
    }

    function finiteNumber(value, fallback = null) {
        if (value === null || value === undefined || value === "") return fallback;
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function formatDay(day, includeYear = false) {
        if (!day) return "Recent activity";
        if (day === localDateKey()) return "Today";

        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        if (day === localDateKey(yesterday)) return "Yesterday";

        return parseDateKey(day).toLocaleDateString([], {
            weekday: "short",
            day: "numeric",
            month: "short",
            ...(includeYear ? { year: "numeric" } : {})
        });
    }

    function formatDateRange(days) {
        if (!days.length) return "Recent activity";
        if (days.length === 1) return formatDay(days[0], true);

        const first = parseDateKey(days[0]);
        const last = parseDateKey(days[days.length - 1]);
        const formatter = new Intl.DateTimeFormat([], {
            day: "numeric",
            month: "short",
            year: "numeric"
        });
        if (typeof formatter.formatRange === "function") {
            return formatter.formatRange(first, last);
        }

        const sameYear = first.getFullYear() === last.getFullYear();
        const sameMonth = sameYear && first.getMonth() === last.getMonth();
        const firstLabel = first.toLocaleDateString([], sameMonth
            ? { day: "numeric" }
            : { day: "numeric", month: "short", ...(sameYear ? {} : { year: "numeric" }) });
        const lastLabel = last.toLocaleDateString([], {
            day: "numeric",
            month: "short",
            year: "numeric"
        });
        return `${firstLabel}–${lastLabel}`;
    }

    function formatEnergy(value, signed = false) {
        const number = finiteNumber(value, 0);
        const absolute = Math.abs(number);
        const decimals = absolute >= 10000 ? 0 : absolute >= 1000 ? 2 : 0;
        const formatted = absolute >= 1000
            ? `${(absolute / 1000).toFixed(decimals).replace(/\.00$/, "")} kWh`
            : `${Math.round(absolute).toLocaleString()} Wh`;

        if (!signed || number === 0) return formatted;
        return `${number > 0 ? "+" : "−"}${formatted}`;
    }

    function formatMoney(value, signed = false) {
        const number = finiteNumber(value);
        if (number === null) return "—";
        const formatted = new Intl.NumberFormat([], {
            style: "currency",
            currency: "EUR",
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(number));
        if (!signed || number === 0) return formatted;
        return `${number > 0 ? "+" : "−"}${formatted}`;
    }

    function setDimmedToken(element, formattedValue, token) {
        const tokenIndex = formattedValue.indexOf(token);
        if (tokenIndex < 0) {
            element.textContent = formattedValue;
            return;
        }

        const affix = document.createElement("span");
        affix.className = "app-value-affix";
        affix.textContent = token;
        element.replaceChildren(
            document.createTextNode(formattedValue.slice(0, tokenIndex)),
            affix,
            document.createTextNode(formattedValue.slice(tokenIndex + token.length))
        );
    }

    function setEnergySummaryValue(element, value, signed = false) {
        const formatted = formatEnergy(value, signed);
        setDimmedToken(element, formatted, formatted.includes("kWh") ? "kWh" : "Wh");
    }

    function setMoneySummaryValue(element, value, signed = false) {
        setDimmedToken(element, formatMoney(value, signed), "€");
    }

    function formatAxisEnergy(value) {
        const absolute = Math.abs(value);
        const sign = value < 0 ? "−" : "";
        if (absolute >= 1000) {
            return `${sign}${(absolute / 1000).toFixed(absolute % 1000 === 0 ? 0 : 1)}k`;
        }
        return `${sign}${Math.round(absolute)}`;
    }

    function setState(state, message = "") {
        component.dataset.state = state;
        component.setAttribute("aria-busy", state === "loading" ? "true" : "false");
        elements.loading.hidden = state !== "loading";
        elements.error.hidden = state !== "error";
        elements.content.hidden = state !== "ready";
        if (message) elements.errorMessage.textContent = message;
    }

    function normalizedRows() {
        if (!Array.isArray(payload?.whPerHour)) return [];
        return payload.whPerHour
            .map((row) => {
                const time = splitHourLabel(row?.hourLabel);
                if (!time) return null;
                return {
                    ...time,
                    hourLabel: row.hourLabel,
                    wh: finiteNumber(row.wh, 0),
                    battery: finiteNumber(row.electricLevel)
                };
            })
            .filter(Boolean)
            .sort((a, b) => a.hourLabel.localeCompare(b.hourLabel));
    }

    function createSvgElement(name, attributes = {}, text = "") {
        const element = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        if (text !== "") element.textContent = text;
        return element;
    }

    function appendText(svg, x, y, text, className, anchor = "start") {
        const element = createSvgElement("text", {
            x,
            y,
            class: className,
            "text-anchor": anchor
        }, text);
        svg.append(element);
        return element;
    }

    function axisMaximum(rows) {
        const peak = rows.reduce((maximum, row) => Math.max(maximum, Math.abs(row.wh)), 0);
        const candidates = [200, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 15000, 20000];
        return candidates.find((candidate) => candidate >= peak) || Math.ceil(peak / 10000) * 10000;
    }

    function batteryPath(rows, xForIndex, yForBattery) {
        let path = "";
        let segmentOpen = false;
        rows.forEach((row, index) => {
            if (row.battery === null) {
                segmentOpen = false;
                return;
            }
            const command = segmentOpen ? "L" : "M";
            path += `${command}${xForIndex(index).toFixed(1)},${yForBattery(row.battery).toFixed(1)} `;
            segmentOpen = true;
        });
        return path.trim();
    }

    function hourAriaLabel(row, previousBattery) {
        const direction = row.wh > 0 ? "charged" : row.wh < 0 ? "discharged" : "no battery flow";
        const energy = row.wh === 0 ? "" : ` ${formatEnergy(row.wh)}`;
        const battery = row.battery === null
            ? "Battery level unavailable."
            : previousBattery === null
                ? `Battery ${Math.round(row.battery)} percent.`
                : `Battery ${Math.round(previousBattery)} to ${Math.round(row.battery)} percent.`;
        return `${formatDay(row.day)}, ${String(row.hour).padStart(2, "0")}:00 to ${String((row.hour + 1) % 24).padStart(2, "0")}:00, ${direction}${energy}. ${battery} Activate to show totals for this day.`;
    }

    function setActiveChartBar(index = null) {
        elements.chart.querySelectorAll(".app-energy-chart__bar.is-active").forEach((element) => {
            element.classList.remove("is-active");
        });
        if (index === null || index === undefined || index === "") return;
        elements.chart.querySelector(`.app-energy-chart__bar[data-index="${index}"]`)?.classList.add("is-active");
    }

    function showHourDetail(row, previousBattery, target) {
        elements.chart.querySelectorAll(".app-energy-chart__hit.is-active").forEach((element) => {
            element.classList.remove("is-active");
        });
        target?.classList.add("is-active");
        setActiveChartBar(target?.dataset.index);
        elements.detail.hidden = false;

        elements.detailTime.textContent = `${formatDay(row.day)} · ${String(row.hour).padStart(2, "0")}:00–${String((row.hour + 1) % 24).padStart(2, "0")}:00`;
        elements.detailFlow.textContent = row.wh > 0
            ? `Charged ${formatEnergy(row.wh)}`
            : row.wh < 0
                ? `Discharged ${formatEnergy(row.wh)}`
                : "No battery flow";
        elements.detailFlow.dataset.direction = row.wh > 0 ? "charged" : row.wh < 0 ? "discharged" : "idle";

        if (row.battery === null) {
            elements.detailBattery.textContent = "Battery level unavailable";
        } else if (previousBattery === null) {
            elements.detailBattery.textContent = `Battery ${Math.round(row.battery)}%`;
        } else {
            const delta = row.battery - previousBattery;
            const deltaText = delta === 0 ? "no change" : `${delta > 0 ? "+" : "−"}${Math.abs(Math.round(delta))} pts`;
            elements.detailBattery.textContent = `Battery ${Math.round(previousBattery)}% → ${Math.round(row.battery)}% · ${deltaText}`;
        }
    }

    function renderChart(rows) {
        elements.chart.replaceChildren();
        if (!rows.length) {
            elements.chart.innerHTML = '<p class="app-energy-history__empty">No hourly battery data is available for the last four days.</p>';
            return;
        }

        const layout = compactChartMedia.matches ? CHART_LAYOUTS.compact : CHART_LAYOUTS.standard;
        const chartHeight = layout.height;
        const margin = layout.margin;
        const slotWidth = 14;
        const chartWidth = Math.max(1320, margin.left + margin.right + (rows.length * slotWidth));
        const plotWidth = chartWidth - margin.left - margin.right;
        const plotHeight = chartHeight - margin.top - margin.bottom;
        const baseline = margin.top + (plotHeight / 2);
        const energyMax = axisMaximum(rows);
        const actualSlotWidth = plotWidth / rows.length;
        const barWidth = Math.max(4, actualSlotWidth * 0.68);
        const xForIndex = (index) => margin.left + ((index + 0.5) * actualSlotWidth);
        const yForBattery = (value) => margin.top + ((100 - clamp(value, 0, 100)) / 100 * plotHeight);
        const svg = createSvgElement("svg", {
            viewBox: `0 0 ${chartWidth} ${chartHeight}`,
            width: chartWidth,
            height: chartHeight,
            role: "group",
            "aria-label": "Interactive hourly energy values",
            focusable: "false"
        });

        [-1, -0.5, 0, 0.5, 1].forEach((factor) => {
            const y = baseline - (factor * plotHeight / 2);
            svg.append(createSvgElement("line", {
                x1: margin.left,
                y1: y,
                x2: chartWidth - margin.right,
                y2: y,
                class: factor === 0 ? "app-energy-chart__zero" : "app-energy-chart__grid"
            }));
            appendText(svg, margin.left - 9, y + 4, formatAxisEnergy(energyMax * factor), "app-energy-chart__axis-label", "end");
            appendText(svg, chartWidth - margin.right + 9, y + 4, `${Math.round(50 + factor * 50)}%`, "app-energy-chart__axis-label app-energy-chart__axis-label--battery");
        });

        appendText(svg, 6, 11, "Wh", "app-energy-chart__unit");
        appendText(svg, chartWidth - 5, 11, "Battery", "app-energy-chart__unit app-energy-chart__unit--battery", "end");

        rows.forEach((row, index) => {
            if (index > 0 && row.day !== rows[index - 1].day) {
                const x = margin.left + (index * actualSlotWidth);
                svg.append(createSvgElement("line", {
                    x1: x,
                    y1: 0,
                    x2: x,
                    y2: chartHeight,
                    class: "app-energy-chart__day-separator"
                }));
            }
        });

        const bars = [];
        rows.forEach((row, index) => {
            const x = xForIndex(index);
            const height = Math.abs(row.wh) / energyMax * (plotHeight / 2);
            const y = row.wh >= 0 ? baseline - height : baseline;
            const bar = createSvgElement("rect", {
                x: x - (barWidth / 2),
                y,
                width: barWidth,
                height: Math.max(row.wh === 0 ? 1 : height, 1),
                rx: Math.min(3, barWidth / 3),
                class: row.wh >= 0 ? "app-energy-chart__bar app-energy-chart__bar--charged" : "app-energy-chart__bar app-energy-chart__bar--discharged",
                "data-index": String(index)
            });
            bars.push(bar);
            svg.append(bar);
        });

        const path = batteryPath(rows, xForIndex, yForBattery);
        if (path) {
            svg.append(createSvgElement("path", { d: path, class: "app-energy-chart__battery-line" }));
        }

        const now = new Date();
        const today = localDateKey(now);
        const todayIndex = rows.findIndex((row) => row.day === today && row.hour === now.getHours());
        if (todayIndex !== -1) {
            const nowX = margin.left + ((todayIndex + (now.getMinutes() / 60)) * actualSlotWidth);
            svg.append(createSvgElement("line", {
                x1: nowX,
                y1: margin.top - 3,
                x2: nowX,
                y2: chartHeight - margin.bottom + 6,
                class: "app-energy-chart__now"
            }));
            appendText(svg, nowX, margin.top - 6, "Now", "app-energy-chart__now-label", "middle");
        }

        for (let startIndex = 0; startIndex < rows.length;) {
            const day = rows[startIndex].day;
            let endIndex = startIndex + 1;
            while (endIndex < rows.length && rows[endIndex].day === day) endIndex += 1;

            const daySelect = createSvgElement("rect", {
                x: margin.left + (startIndex * actualSlotWidth),
                y: chartHeight - margin.bottom + 7,
                width: (endIndex - startIndex) * actualSlotWidth,
                height: margin.bottom - 7,
                rx: 3,
                class: "app-energy-chart__day-select",
                "data-day": day,
                tabindex: "0",
                role: "button",
                "aria-label": `Show totals for ${formatDay(day, true)}`,
                "aria-pressed": "false"
            });
            daySelect.addEventListener("click", () => selectSummaryDay(day));
            daySelect.addEventListener("keydown", (event) => {
                if (event.key !== "Enter" && event.key !== " ") return;
                event.preventDefault();
                selectSummaryDay(day);
            });
            svg.append(daySelect);
            startIndex = endIndex;
        }

        rows.forEach((row, index) => {
            const x = xForIndex(index);
            const isDayStart = index === 0 || row.day !== rows[index - 1].day;
            const showTime = row.hour % 6 === 0;
            if (showTime) {
                appendText(svg, x, chartHeight - 5, `${String(row.hour).padStart(2, "0")}:00`, "app-energy-chart__x-label", "middle");
            }
            if (isDayStart) {
                const dayLabel = appendText(svg, x + 3, chartHeight - 18, formatDay(row.day), "app-energy-chart__day-label");
                dayLabel.setAttribute("data-day", row.day);
            }

            const previousBattery = index > 0 && rows[index - 1].day === row.day ? rows[index - 1].battery : null;
            const hit = createSvgElement("rect", {
                x: margin.left + (index * actualSlotWidth),
                y: margin.top,
                width: actualSlotWidth,
                height: plotHeight,
                class: "app-energy-chart__hit",
                "data-day": row.day,
                "data-index": String(index),
                tabindex: "0",
                role: "button",
                "aria-label": hourAriaLabel(row, previousBattery)
            });
            const bar = bars[index];
            hit.append(createSvgElement("title", {}, hourAriaLabel(row, previousBattery)));
            hit.addEventListener("pointerenter", () => bar.classList.add("is-hover"));
            hit.addEventListener("pointerleave", () => bar.classList.remove("is-hover"));
            hit.addEventListener("focus", () => showHourDetail(row, previousBattery, hit));
            hit.addEventListener("click", () => {
                showHourDetail(row, previousBattery, hit);
                selectSummaryDay(row.day);
            });
            hit.addEventListener("keydown", (event) => {
                if (event.key !== "Enter" && event.key !== " ") return;
                event.preventDefault();
                showHourDetail(row, previousBattery, hit);
                selectSummaryDay(row.day);
            });
            svg.append(hit);
        });

        elements.chart.append(svg);
        elements.chart.style.width = `${chartWidth}px`;
        elements.chart.setAttribute("aria-label", `Four-day battery energy chart. Scale from minus ${formatEnergy(energyMax)} to plus ${formatEnergy(energyMax)}.`);

        requestAnimationFrame(() => {
            elements.chartScroll.scrollLeft = elements.chartScroll.scrollWidth - elements.chartScroll.clientWidth;
        });
    }

    function totalsForDays(days) {
        return days.reduce((totals, day) => {
            const dayTotals = payload?.whPerDay?.[day];
            if (dayTotals) {
                totals.charged += Math.max(0, finiteNumber(dayTotals.pos, 0));
                totals.discharged += Math.abs(Math.min(0, finiteNumber(dayTotals.neg, 0)));
                return totals;
            }

            normalizedRows().filter((row) => row.day === day).forEach((row) => {
                if (row.wh >= 0) totals.charged += row.wh;
                else totals.discharged += Math.abs(row.wh);
            });
            return totals;
        }, { charged: 0, discharged: 0 });
    }

    function moneyTotalsForDays(days) {
        const totals = {};
        ["consumer", "spot"].forEach((priceType) => {
            totals[priceType] = {};
            ["charged", "discharged"].forEach((direction) => {
                totals[priceType][direction] = { eur: 0, complete: true, missingHours: [] };
            });
        });

        days.forEach((day) => {
            const priceTotals = payload?.whPerDay?.[day]?.priceTotals;
            ["consumer", "spot"].forEach((priceType) => {
                ["charged", "discharged"].forEach((direction) => {
                    const target = totals[priceType][direction];
                    const source = priceTotals?.[priceType]?.[direction];
                    if (!source || source.complete !== true || finiteNumber(source.eur) === null) {
                        target.complete = false;
                    } else {
                        target.eur += finiteNumber(source.eur, 0);
                    }
                    if (Array.isArray(source?.missingHours)) {
                        target.missingHours.push(...source.missingHours);
                    }
                });
            });
        });

        ["consumer", "spot"].forEach((priceType) => {
            const charged = totals[priceType].charged;
            const discharged = totals[priceType].discharged;
            charged.eur = charged.complete ? charged.eur : null;
            discharged.eur = discharged.complete ? discharged.eur : null;
            totals[priceType].net = {
                eur: charged.complete && discharged.complete ? charged.eur - discharged.eur : null,
                complete: charged.complete && discharged.complete,
                missingHours: [...new Set([...charged.missingHours, ...discharged.missingHours])]
            };
        });

        return totals;
    }

    function priceWarning(totals) {
        const missingHours = [...new Set([
            ...totals.consumer.net.missingHours,
            ...totals.spot.net.missingHours
        ])];
        if (!missingHours.length) return "";
        const shown = missingHours.slice(0, 3).map((hour) => hour.replace(" ", " · ")).join(", ");
        const remainder = missingHours.length > 3 ? ` and ${missingHours.length - 3} more` : "";
        return `Some price totals are unavailable because price data is missing for ${shown}${remainder}.`;
    }

    function renderSummary(days) {
        const totals = totalsForDays(days);
        const money = moneyTotalsForDays(days);
        const net = totals.charged - totals.discharged;
        setEnergySummaryValue(elements.charged, totals.charged);
        setEnergySummaryValue(elements.discharged, totals.discharged);
        setEnergySummaryValue(elements.net, net, true);
        elements.net.dataset.direction = net > 0 ? "charged" : net < 0 ? "discharged" : "idle";
        setMoneySummaryValue(elements.chargedConsumer, money.consumer.charged.eur);
        setMoneySummaryValue(elements.chargedSpot, money.spot.charged.eur);
        setMoneySummaryValue(elements.dischargedConsumer, money.consumer.discharged.eur);
        setMoneySummaryValue(elements.dischargedSpot, money.spot.discharged.eur);
        setMoneySummaryValue(elements.netConsumer, money.consumer.net.eur, true);
        setMoneySummaryValue(elements.netSpot, money.spot.net.eur, true);
        [elements.netConsumer, elements.netSpot].forEach((element, index) => {
            const value = index === 0 ? money.consumer.net.eur : money.spot.net.eur;
            element.dataset.direction = value > 0 ? "charged" : value < 0 ? "discharged" : "idle";
        });
        return priceWarning(money);
    }

    function summaryPeriodLabel(day) {
        return day === localDateKey()
            ? "Today totals · through now"
            : `${formatDay(day, true)} totals`;
    }

    function selectSummaryDay(day) {
        if (!availableDays.includes(day)) return;
        selectedDay = day;
        elements.summaryPeriod.textContent = summaryPeriodLabel(day);
        elements.summary.setAttribute("aria-label", `Energy and price totals for ${formatDay(day, true)}`);

        elements.chart.querySelectorAll(".app-energy-chart__hit[data-day]").forEach((hit) => {
            hit.classList.toggle("is-selected-day", hit.dataset.day === day);
        });
        elements.chart.querySelectorAll(".app-energy-chart__day-label[data-day]").forEach((label) => {
            label.classList.toggle("is-selected-day", label.dataset.day === day);
        });
        elements.chart.querySelectorAll(".app-energy-chart__day-select[data-day]").forEach((control) => {
            const isSelected = control.dataset.day === day;
            control.classList.toggle("is-selected-day", isSelected);
            control.setAttribute("aria-pressed", isSelected ? "true" : "false");
        });

        renderStatus(renderSummary([day]));
    }

    function resetDetail() {
        elements.chart.querySelectorAll(".app-energy-chart__hit.is-active").forEach((element) => {
            element.classList.remove("is-active");
        });
        setActiveChartBar(null);
        elements.detailTime.textContent = "Select an hour";
        elements.detailFlow.textContent = "Explore the chart for exact values";
        elements.detailFlow.dataset.direction = "idle";
        elements.detailBattery.textContent = "Battery level appears when available";
        elements.detail.hidden = true;
    }

    function renderStatus(priceMessage = "") {
        const cache = payload?.cacheInfo || {};
        const messages = [];
        if (priceMessage) messages.push(priceMessage);
        if (cache.isStale) {
            const timestamp = finiteNumber(cache.cachedAt);
            const updated = timestamp
                ? new Date(timestamp * 1000).toLocaleString([], { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })
                : null;
            messages.push(updated ? `Showing cached data from ${updated}.` : "Showing cached battery data.");
        }
        if (!messages.length) {
            elements.status.hidden = true;
            elements.status.textContent = "";
            return;
        }
        elements.status.textContent = messages.join(" ");
        elements.status.hidden = false;
    }

    function render() {
        const rows = normalizedRows();
        const dateRange = formatDateRange(availableDays);
        elements.date.textContent = `${dateRange} · energy flow and battery level`;
        elements.date.dataset.mobileLabel = `${dateRange} · flow & battery`;
        renderChart(rows);
        selectSummaryDay(selectedDay);
        resetDetail();
    }

    function normalizePayload(data) {
        if (!data || !Array.isArray(data.whPerHour) || typeof data.whPerDay !== "object") {
            throw new Error("The energy history response has an unexpected format.");
        }
        return data;
    }

    async function load() {
        if (activeController) activeController.abort();
        const controller = new AbortController();
        activeController = controller;
        elements.refresh.setAttribute("aria-busy", "true");
        if (!payload) setState("loading");

        try {
            const response = await fetch(config.energyHistoryUrl, {
                signal: controller.signal,
                headers: { Accept: "application/json" },
                cache: "no-store"
            });
            const data = await response.json().catch(() => null);
            if (!response.ok) throw new Error(data?.error || `Energy history request failed (${response.status}).`);

            payload = normalizePayload(data);
            availableDays = [...new Set(normalizedRows().map((row) => row.day))].sort();
            if (!availableDays.length) throw new Error("No recent hourly battery data is available.");
            if (!selectedDay || !availableDays.includes(selectedDay)) {
                const today = localDateKey();
                selectedDay = availableDays.includes(today) ? today : availableDays[availableDays.length - 1];
            }
            setState("ready");
            render();
        } catch (error) {
            if (error?.name === "AbortError") return;
            setState("error", error?.message || "Battery energy history could not be loaded.");
        } finally {
            elements.refresh.removeAttribute("aria-busy");
            if (activeController === controller) activeController = null;
        }
    }

    elements.refresh.addEventListener("click", load);
    elements.retry.addEventListener("click", load);
    compactChartMedia.addEventListener?.("change", () => {
        if (payload) render();
    });

    load();
}());
