const DATA_URL = "../data/p1_hourly_energy.json";
const DISPLAY_CAP_WH = 500;
const LOG_BASE = 10;
const LOG_ORDER = 3;
const GREEN_ZONE_WH = 25;
const WINDOW_DAYS = 2;

const HOUR_COUNT = 24;
const hourKey = (hour) => String(hour).padStart(2, "0");

const formatDateKey = (date) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

const clampAbs = (value, cap) => {
  const sign = Math.sign(value);
  const absValue = Math.min(Math.abs(value), cap);
  return sign * absValue;
};

const toLogValue = (value) => {
  if (value === 0) return 0;
  const sign = Math.sign(value);
  const absValue = Math.abs(value);
  const logValue = Math.log1p(absValue) / Math.log(LOG_BASE);
  return sign * Math.pow(logValue, LOG_ORDER);
};

const fromLogValue = (value) => {
  if (value === 0) return 0;
  const sign = Math.sign(value);
  const absValue = Math.abs(value);
  const logValue = Math.pow(absValue, 1 / LOG_ORDER);
  return sign * (Math.pow(LOG_BASE, logValue) - 1);
};

const readDeltaWh = (entry, keyPrefix) => {
  if (!entry) return 0;
  const whKey = `${keyPrefix}_delta_wh`;
  if (typeof entry[whKey] === "number") {
    return entry[whKey];
  }
  const kwhKey = `${keyPrefix}_delta_kwh`;
  if (typeof entry[kwhKey] === "number") {
    return entry[kwhKey] * 1000;
  }
  return 0;
};

const formatWh = (value) => `${Math.round(value)} Wh`;

const isDateKey = (key) => /^\d{4}-\d{2}-\d{2}$/.test(key);

const getDateKeys = (data) =>
  Object.keys(data || {})
    .filter((key) => isDateKey(key))
    .sort();

const getMaxHourForDate = (dateKey) => {
  const todayKey = formatDateKey(new Date());
  return dateKey === todayKey ? new Date().getHours() : HOUR_COUNT - 1;
};

const buildSeries = (data, dateKey, maxHour) => {
  const labels = [];
  const importRaw = [];
  const exportRaw = [];
  const importDisplay = [];
  const exportDisplay = [];

  for (let hour = 0; hour <= maxHour; hour += 1) {
    const key = hourKey(hour);
    const entry = data?.[dateKey]?.[key];
    const importWh = readDeltaWh(entry, "import");
    const exportWh = readDeltaWh(entry, "export");
    const exportSigned = -exportWh;

    labels.push(`${dateKey} ${key}:00`);
    importRaw.push(importWh);
    exportRaw.push(exportWh);

    const cappedImport = clampAbs(importWh, DISPLAY_CAP_WH);
    const cappedExport = clampAbs(exportSigned, DISPLAY_CAP_WH);

    importDisplay.push(toLogValue(cappedImport));
    exportDisplay.push(toLogValue(cappedExport));
  }

  return { labels, importRaw, exportRaw, importDisplay, exportDisplay };
};

const mergeSeries = (seriesList) => {
  return seriesList.reduce(
    (acc, series) => {
      acc.labels.push(...series.labels);
      acc.importRaw.push(...series.importRaw);
      acc.exportRaw.push(...series.exportRaw);
      acc.importDisplay.push(...series.importDisplay);
      acc.exportDisplay.push(...series.exportDisplay);
      return acc;
    },
    { labels: [], importRaw: [], exportRaw: [], importDisplay: [], exportDisplay: [] }
  );
};

const greenZonePlugin = {
  id: "greenZone",
  beforeDraw(chart, _args, options) {
    const { ctx, chartArea, scales } = chart;
    if (!chartArea) return;
    const yScale = scales.y;
    if (!yScale) return;

    const zoneValue = Number(options?.value ?? 0);
    if (!zoneValue) return;

    const upper = toLogValue(zoneValue);
    const lower = toLogValue(-zoneValue);
    const yTop = yScale.getPixelForValue(upper);
    const yBottom = yScale.getPixelForValue(lower);

    ctx.save();
    ctx.fillStyle = "rgba(46, 204, 113, 0.12)";
    ctx.fillRect(
      chartArea.left,
      Math.min(yTop, yBottom),
      chartArea.right - chartArea.left,
      Math.abs(yBottom - yTop)
    );
    ctx.restore();
  },
};

const dividerPlugin = {
  id: "dividerLines",
  beforeDatasetsDraw(chart, _args, options) {
    const { ctx, chartArea, scales } = chart;
    if (!chartArea) return;
    const xScale = scales.x;
    if (!xScale) return;

    const step = Number(options?.step ?? 6);
    const dayColor = options?.dayColor ?? "rgba(255, 255, 255, 0.18)";
    const stepColor = options?.stepColor ?? "rgba(255,255,255,0.28)";

    const labels = chart.data.labels ?? [];

    ctx.save();
    ctx.lineWidth = 1;

    labels.forEach((label, index) => {
      const parts = String(label).split(" ");
      const timePart = parts[1] ?? "";
      const hourPart = timePart.slice(0, 2);
      const hour = Number(hourPart);
      if (Number.isNaN(hour)) return;

      if (index === 0) return;
      const prevX = xScale.getPixelForValue(index - 1);
      const currX = xScale.getPixelForValue(index);
      const x = (prevX + currX) / 2;

      if (hour === 0) {
        ctx.strokeStyle = dayColor;
        ctx.setLineDash([2, 4]);
        ctx.lineWidth = 1.5;
      } else if (step && hour % step === 0) {
        ctx.strokeStyle = stepColor;
        ctx.setLineDash([6, 4]);
        ctx.lineWidth = 1;
      } else {
        return;
      }

      ctx.beginPath();
      ctx.moveTo(x, chartArea.top);
      ctx.lineTo(x, chartArea.bottom);
      ctx.stroke();
    });

    ctx.restore();
  },
};

const buildChart = (series) => {
  const ctx = document.getElementById("energyChart");
  const maxLog = toLogValue(DISPLAY_CAP_WH);

  Chart.register(greenZonePlugin, dividerPlugin);

  return new Chart(ctx, {
    type: "bar",
    data: {
      labels: series.labels,
      datasets: [
        {
          label: "Import (Wh)",
          data: series.importDisplay,
          rawValues: series.importRaw,
          stack: "energy",
          grouped: false,
          backgroundColor: "rgba(52, 152, 219, 0.65)",
          borderColor: "rgba(52, 152, 219, 1)",
          borderWidth: 1,
        },
        {
          label: "Export (Wh)",
          data: series.exportDisplay,
          rawValues: series.exportRaw,
          stack: "energy",
          grouped: false,
          backgroundColor: "rgba(46, 204, 113, 0.65)",
          borderColor: "rgba(46, 204, 113, 1)",
          borderWidth: 1,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: {
        greenZone: { value: GREEN_ZONE_WH },
        dividerLines: {
          step: 6,
          dayColor: "rgba(255, 255, 255, 0.43)",
          stepColor: "rgba(255,255,255,0.28)",
        },
        legend: {
          labels: { color: "#e6e9ef" },
        },
        tooltip: {
          callbacks: {
            label(context) {
              const rawValue = context.dataset.rawValues?.[context.dataIndex] ?? 0;
              const capped = Math.abs(rawValue) > DISPLAY_CAP_WH;
              const suffix = capped ? ` (capped at ${DISPLAY_CAP_WH} Wh)` : "";
              const labelPrefix = context.dataset.label?.includes("Export")
                ? "Export"
                : "Import";
              return `${labelPrefix}: ${formatWh(rawValue)}${suffix}`;
            },
          },
        },
      },
      scales: {
        x: {
          ticks: {
            color: "#9aa3b2",
            maxRotation: 0,
            minRotation: 0,
            callback(value) {
              const label = this.getLabelForValue(value);
              if (!label) return "";
              const [datePart, timePart] = label.split(" ");
              const hourPart = timePart?.slice(0, 2) ?? "";
              if (hourPart === "00") {
                return datePart;
              }
              return hourPart;
            },
          },
          grid: { color: "rgba(255,255,255,0.06)" },
        },
        y: {
          min: -maxLog,
          max: maxLog,
          ticks: {
            color: "#9aa3b2",
            callback(value) {
              const actual = fromLogValue(Number(value));
              return formatWh(Math.abs(actual));
            },
          },
          grid: { color: "rgba(255,255,255,0.06)" },
        },
      },
    },
  });
};

const loadData = async () => {
  const response = await fetch(DATA_URL, { cache: "no-store" });
  if (!response.ok) {
    throw new Error(`Failed to load data (${response.status})`);
  }
  return response.json();
};

const applySeriesToChart = (chart, series) => {
  chart.data.labels = series.labels;
  chart.data.datasets[0].data = series.importDisplay;
  chart.data.datasets[0].rawValues = series.importRaw;
  chart.data.datasets[1].data = series.exportDisplay;
  chart.data.datasets[1].rawValues = series.exportRaw;
  chart.update();
};

const init = async () => {
  const prevButton = document.getElementById("prevDays");
  const nextButton = document.getElementById("nextDays");
  const rangeLabel = document.getElementById("rangeLabel");

  try {
    const data = await loadData();
    const dateKeys = getDateKeys(data);

    if (dateKeys.length === 0) {
      const container = document.querySelector(".card");
      container.textContent = "No date data available.";
      return;
    }

    let startIndex = Math.max(0, dateKeys.length - WINDOW_DAYS);
    let chartInstance = null;

    const updateView = () => {
      const rangeKeys = dateKeys.slice(startIndex, startIndex + WINDOW_DAYS);
      const seriesList = rangeKeys.map((key) =>
        buildSeries(data, key, getMaxHourForDate(key))
      );
      const merged = mergeSeries(seriesList);

      if (!chartInstance) {
        chartInstance = buildChart(merged);
      } else {
        applySeriesToChart(chartInstance, merged);
      }

      const rangeText =
        rangeKeys.length === 1 ? rangeKeys[0] : `${rangeKeys[0]} to ${rangeKeys.at(-1)}`;
      rangeLabel.textContent = rangeText;
      prevButton.disabled = startIndex <= 0;
      nextButton.disabled = startIndex + WINDOW_DAYS >= dateKeys.length;
    };

    prevButton.addEventListener("click", () => {
      if (startIndex > 0) {
        startIndex -= 1;
        updateView();
      }
    });

    nextButton.addEventListener("click", () => {
      if (startIndex + WINDOW_DAYS < dateKeys.length) {
        startIndex += 1;
        updateView();
      }
    });

    updateView();
  } catch (error) {
    const container = document.querySelector(".card");
    container.textContent = `Error loading data: ${error.message}`;
  }
};

init();
