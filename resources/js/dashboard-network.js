import './pwa-register';

const POLL_INTERVAL_MS = 10000;

const appRoot = document.getElementById('dashboardApp');
const initialPayloadEl = document.getElementById('dashboardInitialPayload');

if (!appRoot || !initialPayloadEl) {
  // Dashboard assets are loaded only on the dashboard shell.
} else {
  const dataUrl = appRoot.dataset.dashboardDataUrl || '';
  const appTimezone = appRoot.dataset.timezone || 'Asia/Manila';
  const farmSwitcher = document.getElementById('dashboardFarmSwitcher');
  const deviceSwitcher = document.getElementById('dashboardDeviceSwitcher');
  const rangeButtons = Array.from(document.querySelectorAll('[data-range-btn]'));
  const liveBadge = document.getElementById('dashboardLiveBadge');
  const asOfLabel = document.getElementById('dashboardAsOfLabel');
  const themeToggle = document.getElementById('dashboardThemeToggle');

  const charts = {
    size: null,
    activity: null,
    timeline: null,
  };

  let abortController = null;
  let pollTimer = null;
  let staleTimer = null;
  let payload = safeJsonParse(initialPayloadEl.textContent, {});
  let lastSuccessAt = Date.now();
  const chartFontFamily = resolveChartFontFamily();

  const state = {
    range: payload.range || '1d',
    farmId: normalizeId(payload?.context?.selected?.farm_id),
    deviceId: normalizeId(payload?.context?.selected?.device_id),
  };

  applySavedTheme();
  bindControls();
  syncControls();
  render(payload);
  startPolling();
  startStaleTicker();

  function bindControls() {
    if (farmSwitcher) {
      farmSwitcher.addEventListener('change', () => {
        state.farmId = normalizeId(farmSwitcher.value);

        if (deviceSwitcher && state.deviceId) {
          const selectedOption = deviceSwitcher.querySelector(`option[value="${state.deviceId}"]`);
          const optionFarmId = normalizeId(selectedOption?.dataset?.farmId ?? null);
          if (optionFarmId && state.farmId && optionFarmId !== state.farmId) {
            state.deviceId = null;
            deviceSwitcher.value = '';
          }
        }

        refreshData({ silent: false });
      });
    }

    if (deviceSwitcher) {
      deviceSwitcher.addEventListener('change', () => {
        state.deviceId = normalizeId(deviceSwitcher.value);

        if (state.deviceId) {
          const selectedOption = deviceSwitcher.querySelector(`option[value="${state.deviceId}"]`);
          const optionFarmId = normalizeId(selectedOption?.dataset?.farmId ?? null);
          if (optionFarmId && farmSwitcher) {
            state.farmId = optionFarmId;
            farmSwitcher.value = String(optionFarmId);
          }
        }

        refreshData({ silent: false });
      });
    }

    rangeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const rangeValue = button.dataset.range || '1d';
        if (state.range === rangeValue) {
          return;
        }

        state.range = rangeValue;
        syncRangeButtons();
        refreshData({ silent: false });
      });
    });

    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        applyTheme(nextTheme);
      });
    }
  }

  function startPolling() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
    }

    pollTimer = window.setInterval(() => {
      refreshData({ silent: true });
    }, POLL_INTERVAL_MS);
  }

  function startStaleTicker() {
    if (staleTimer) {
      window.clearInterval(staleTimer);
    }

    staleTimer = window.setInterval(() => {
      if (!liveBadge) {
        return;
      }

      const ageMs = Date.now() - lastSuccessAt;
      if (ageMs <= POLL_INTERVAL_MS + 1500) {
        liveBadge.textContent = 'Live';
        liveBadge.classList.remove('is-stale');
      } else {
        const seconds = Math.max(1, Math.floor(ageMs / 1000));
        liveBadge.textContent = `Stale ${seconds}s`;
        liveBadge.classList.add('is-stale');
      }
    }, 1000);
  }

  async function refreshData({ silent }) {
    if (!dataUrl) {
      return;
    }

    if (abortController) {
      abortController.abort();
    }

    abortController = new AbortController();

    const params = new URLSearchParams();
    params.set('range', state.range);

    if (state.farmId) {
      params.set('context_farm_id', String(state.farmId));
    }

    if (state.deviceId) {
      params.set('context_device_id', String(state.deviceId));
    }

    const queryString = params.toString();
    const url = queryString === '' ? dataUrl : `${dataUrl}?${queryString}`;

    try {
      const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        signal: abortController.signal,
      });

      const nextPayload = await response.json();
      if (!response.ok || nextPayload?.ok === false) {
        const message = nextPayload?.message || `Failed to refresh dashboard (${response.status}).`;
        throw new Error(message);
      }

      payload = nextPayload;
      state.range = nextPayload.range || state.range;
      state.farmId = normalizeId(nextPayload?.context?.selected?.farm_id);
      state.deviceId = normalizeId(nextPayload?.context?.selected?.device_id);
      lastSuccessAt = Date.now();

      syncControls();
      pushUrlState();
      render(nextPayload);

      if (!silent) {
        showToast('Dashboard data updated.', false, 1400);
      }
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }

      showToast(error instanceof Error ? error.message : 'Dashboard refresh failed.', true);
    }
  }

  function render(nextPayload) {
    renderMeta(nextPayload);
    renderProfile(nextPayload.summary || {});
    renderSizeBreakdown(nextPayload.size_breakdown || []);
    renderActivityBreakdown(nextPayload.activity_breakdown || []);
    renderBandBreakdown(nextPayload.band_breakdown || {});
    renderTopActive(nextPayload.top_active || []);
    renderTimeline(nextPayload.timeline || []);
  }

  function renderMeta(nextPayload) {
    if (asOfLabel) {
      const parsed = parseIso(nextPayload.as_of);
      asOfLabel.textContent = parsed ? formatDateTime(parsed) : '-';
    }

    setText('dashboardSummaryTotalEggs', formatNumber(nextPayload?.summary?.total_eggs));
    setText('dashboardSummaryQualityScore', `${toPercent(nextPayload?.summary?.quality_score)}%`);
    setText('dashboardSummaryFarms', formatNumber(nextPayload?.summary?.active_farms));
  }

  function renderProfile(summary) {
    const profile = summary.profile || {};

    setText('dashboardProfileDeviceName', profile.device_name || 'No active device selected');
    setText('dashboardProfileDeviceSerial', profile.serial ? `Serial ${profile.serial}` : 'Serial not available');
    setText('dashboardProfileOwnerName', profile.owner_name || '-');
    setText('dashboardProfileFarmName', profile.farm_name || '-');
    setText('dashboardProfileLastSeen', profile.last_seen_label || 'No signal');
    setText('dashboardProfileHealth', profile.ingest_health || 'No active device selected');
    setText('dashboardEventsPerMinute', `${formatDecimal(profile.events_per_minute)} eggs/min`);

    const health = document.getElementById('dashboardProfileHealth');
    if (health) {
      health.classList.remove('ppn-health--good', 'ppn-health--warn', 'ppn-health--bad', 'ppn-health--neutral');
      const tone = profile.ingest_health_tone || 'neutral';
      health.classList.add(`ppn-health--${tone}`);
    }

    const down = toPercent(profile.down_utilization_pct);
    const up = toPercent(profile.up_utilization_pct);

    setText('dashboardDownUtilizationValue', `${down}%`);
    setText('dashboardUpUtilizationValue', `${up}%`);

    updateProgress('dashboardDownUtilizationBar', down);
    updateProgress('dashboardUpUtilizationBar', up);
  }

  function renderSizeBreakdown(rows) {
    const total = rows.reduce((sum, row) => sum + Number(row.count || 0), 0);
    setText('dashboardSizeTotal', `${formatNumber(total)} eggs`);

    const tableBody = document.getElementById('dashboardSizeTableBody');
    if (tableBody) {
      if (!rows.length) {
        tableBody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary">No telemetry available.</td></tr>';
      } else {
        tableBody.innerHTML = rows
          .map((row) => {
            const color = row.color || '#7b8597';
            return `<tr>
              <td><span style="display:inline-flex;align-items:center;gap:.4rem;"><i class="bx bx-circle" style="color:${color};font-size:.62rem"></i>${escapeHtml(row.size_class)}</span></td>
              <td>${formatNumber(row.count)}</td>
              <td>${toPercent(row.percent)}%</td>
              <td>${formatDecimal(row.avg_weight)}</td>
            </tr>`;
          })
          .join('');
      }
    }

    const labels = rows.map((row) => row.size_class);
    const series = rows.map((row) => Number(row.count || 0));
    const colors = rows.map((row) => row.color || '#7b8597');

    renderDonutChart('size', '#dashboardSizeChart', labels, series, colors, `${formatNumber(total)} eggs`);
  }

  function renderActivityBreakdown(rows) {
    const total = rows.reduce((sum, row) => sum + Number(row.total || 0), 0);
    setText('dashboardActivityTotal', `${formatNumber(total)} streams`);

    const wrapper = document.getElementById('dashboardActivityList');
    if (wrapper) {
      if (!rows.length) {
        wrapper.innerHTML = '<p class="text-body-secondary mb-0">No activity records.</p>';
      } else {
        wrapper.innerHTML = rows
          .map((row) => {
            const color = row.color || '#7b8597';
            const activity = toPercent(row.activity_percent);
            const score = toPercent(row.score_percent);
            return `<section class="ppn-activity-row">
              <div class="ppn-activity-row__head">
                <strong>${escapeHtml(row.label)}</strong>
                <span>${formatNumber(row.total)}</span>
              </div>
              <div class="progress mb-2" style="height:8px;">
                <div class="progress-bar" role="progressbar" style="width:${activity}%;background:${color};" aria-valuenow="${activity}" aria-valuemin="0" aria-valuemax="100"></div>
              </div>
              <div class="ppn-activity-row__meta">Activity ${activity}% | EXP ${score}%</div>
            </section>`;
          })
          .join('');
      }
    }

    const labels = rows.map((row) => row.label);
    const series = rows.map((row) => Number(row.total || 0));
    const colors = rows.map((row) => row.color || '#7b8597');
    renderDonutChart('activity', '#dashboardActivityChart', labels, series, colors, `${formatNumber(total)}`);
  }

  function renderBandBreakdown(bands) {
    const light = bands.light || { total: 0, classes: [] };
    const standard = bands.standard || { total: 0, classes: [] };
    const heavy = bands.heavy || { total: 0, classes: [] };

    const total = Number(light.total || 0) + Number(standard.total || 0) + Number(heavy.total || 0);
    setText('dashboardBandTotal', `${formatNumber(total)} eggs`);

    setText('dashboardBandLightTotal', `${formatNumber(light.total || 0)} eggs`);
    setText('dashboardBandStandardTotal', `${formatNumber(standard.total || 0)} eggs`);
    setText('dashboardBandHeavyTotal', `${formatNumber(heavy.total || 0)} eggs`);

    setMiniList('dashboardBandLightClasses', light.classes || []);
    setMiniList('dashboardBandStandardClasses', standard.classes || []);
    setMiniList('dashboardBandHeavyClasses', heavy.classes || []);
  }

  function renderTopActive(rows) {
    setText('dashboardTopActiveCount', `${formatNumber(rows.length)} entities`);

    const wrapper = document.getElementById('dashboardTopActiveList');
    if (!wrapper) {
      return;
    }

    if (!rows.length) {
      wrapper.innerHTML = '<p class="text-body-secondary mb-0">No active entities detected.</p>';
      return;
    }

    wrapper.innerHTML = rows
      .map((row) => `<article class="ppn-top-active-item">
        <i class="bx ${escapeHtml(row.icon || 'bx-chip')}"></i>
        <strong>${escapeHtml(row.label || 'Unknown')}</strong>
        <span>${escapeHtml(row.sub_label || '-')}</span>
        <em>${formatNumber(row.value || 0)}</em>
      </article>`)
      .join('');
  }

  function renderTimeline(rows) {
    const total = rows.reduce((sum, row) => sum + Number(row.eggs || 0), 0);
    const nowValue = rows.length ? Number(rows[rows.length - 1].eggs || 0) : 0;

    setText('dashboardTimelineNow', formatNumber(nowValue));
    setText('dashboardTimelineTotal', formatNumber(total));

    const avgQuality = rows.length
      ? rows.reduce((sum, row) => sum + Number(row.quality_score || 0), 0) / rows.length
      : 0;
    setText('dashboardTimelineQuality', `${toPercent(avgQuality)}%`);

    if (typeof window.ApexCharts !== 'function') {
      return;
    }

    const element = document.querySelector('#dashboardTimelineChart');
    if (!element) {
      return;
    }

    const categories = rows.map((row) => row.label || '');
    const eggsSeries = rows.map((row) => Number(row.eggs || 0));
    const qualitySeries = rows.map((row) => Number(row.quality_score || 0));

    const options = {
      chart: {
        height: 320,
        type: 'line',
        stacked: false,
        toolbar: { show: false },
        fontFamily: chartFontFamily,
      },
      stroke: {
        width: [0, 3],
        curve: 'smooth',
      },
      series: [
        {
          name: 'Eggs',
          type: 'column',
          data: eggsSeries,
        },
        {
          name: 'Quality %',
          type: 'area',
          data: qualitySeries,
        },
      ],
      fill: {
        opacity: [0.7, 0.22],
        type: ['solid', 'gradient'],
        gradient: {
          shadeIntensity: 0.8,
          opacityFrom: 0.32,
          opacityTo: 0.02,
          stops: [0, 95, 100],
        },
      },
      markers: {
        size: 0,
      },
      xaxis: {
        categories,
        labels: {
          style: { colors: '#7d8ba1', fontSize: '11px', fontFamily: chartFontFamily },
        },
      },
      yaxis: [
        {
          title: { text: 'Eggs' },
          labels: { style: { colors: '#7d8ba1', fontSize: '11px', fontFamily: chartFontFamily } },
        },
        {
          opposite: true,
          max: 100,
          min: 0,
          title: { text: 'Quality %' },
          labels: { style: { colors: '#7d8ba1', fontSize: '11px', fontFamily: chartFontFamily } },
        },
      ],
      legend: {
        position: 'top',
        horizontalAlign: 'left',
      },
      colors: ['#8ab6ff', '#45c4dd'],
      grid: {
        borderColor: 'rgba(142, 160, 187, 0.2)',
        strokeDashArray: 4,
      },
      tooltip: {
        shared: true,
      },
      dataLabels: {
        enabled: false,
      },
    };

    if (!charts.timeline) {
      charts.timeline = new window.ApexCharts(element, options);
      charts.timeline.render();
    } else {
      charts.timeline.updateOptions(options, true, true);
    }
  }

  function renderDonutChart(chartKey, selector, labels, series, colors, totalLabel) {
    if (typeof window.ApexCharts !== 'function') {
      return;
    }

    const element = document.querySelector(selector);
    if (!element) {
      return;
    }

    const hasSeries = series.some((value) => Number(value) > 0);

    const options = {
      chart: {
        type: 'donut',
        height: 220,
        fontFamily: chartFontFamily,
      },
      labels,
      series: hasSeries ? series : [1],
      colors: hasSeries ? colors : ['#d5deeb'],
      dataLabels: {
        enabled: false,
      },
      legend: {
        show: false,
      },
      stroke: {
        width: 0,
      },
      plotOptions: {
        pie: {
          donut: {
            size: '68%',
            labels: {
              show: true,
              name: {
                show: true,
                offsetY: -5,
                formatter: () => 'Identified',
              },
              value: {
                show: true,
                fontSize: '1.4rem',
                fontWeight: 700,
                formatter: () => totalLabel,
              },
              total: {
                show: false,
              },
            },
          },
        },
      },
      tooltip: {
        y: {
          formatter: (value) => `${formatNumber(value)} eggs`,
        },
      },
    };

    if (!charts[chartKey]) {
      charts[chartKey] = new window.ApexCharts(element, options);
      charts[chartKey].render();
    } else {
      charts[chartKey].updateOptions(options, true, true);
    }
  }

  function setMiniList(id, rows) {
    const list = document.getElementById(id);
    if (!list) {
      return;
    }

    if (!rows.length) {
      list.innerHTML = '<li><span>No data</span><span>0</span></li>';
      return;
    }

    list.innerHTML = rows
      .slice(0, 4)
      .map((row) => `<li><span>${escapeHtml(row.size_class || 'Unknown')}</span><span>${formatNumber(row.count || 0)}</span></li>`)
      .join('');
  }

  function updateProgress(id, value) {
    const progress = document.getElementById(id);
    if (!progress) {
      return;
    }

    progress.style.width = `${value}%`;
    progress.setAttribute('aria-valuenow', String(value));
  }

  function syncControls() {
    if (farmSwitcher) {
      farmSwitcher.value = state.farmId ? String(state.farmId) : '';
    }

    if (deviceSwitcher) {
      deviceSwitcher.value = state.deviceId ? String(state.deviceId) : '';
    }

    syncRangeButtons();
  }

  function syncRangeButtons() {
    rangeButtons.forEach((button) => {
      const isActive = (button.dataset.range || '1d') === state.range;
      button.classList.toggle('is-active', isActive);
    });
  }

  function pushUrlState() {
    const params = new URLSearchParams();
    params.set('range', state.range);

    if (state.farmId) {
      params.set('context_farm_id', String(state.farmId));
    }

    if (state.deviceId) {
      params.set('context_device_id', String(state.deviceId));
    }

    const queryString = params.toString();
    const newUrl = queryString ? `${window.location.pathname}?${queryString}` : window.location.pathname;
    window.history.replaceState({}, '', newUrl);
  }

  function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
      element.textContent = String(value);
    }
  }

  function normalizeId(value) {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  }

  function formatDecimal(value) {
    return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function toPercent(value) {
    return Math.max(0, Math.min(100, Math.round(Number(value || 0) * 10) / 10));
  }

  function parseIso(value) {
    if (!value) {
      return null;
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
      return null;
    }

    return parsed;
  }

  function formatDateTime(date) {
    return date.toLocaleString('en-US', {
      timeZone: appTimezone,
      month: 'short',
      day: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  function safeJsonParse(raw, fallback) {
    try {
      return JSON.parse(raw || '');
    } catch {
      return fallback;
    }
  }

  function showToast(message, isError, timeoutMs = 3200) {
    const toast = document.createElement('div');
    toast.className = `ppn-toast ${isError ? 'ppn-toast--error' : ''}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    window.setTimeout(() => {
      toast.remove();
    }, timeoutMs);
  }

  function applySavedTheme() {
    const saved = window.localStorage.getItem('ppn-theme');
    if (saved === 'dark' || saved === 'light') {
      applyTheme(saved);
      return;
    }

    applyTheme(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    window.localStorage.setItem('ppn-theme', theme);

    if (themeToggle) {
      themeToggle.innerHTML = theme === 'dark' ? '<i class="bx bx-sun"></i>' : '<i class="bx bx-moon"></i>';
      themeToggle.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      themeToggle.setAttribute('aria-label', themeToggle.title);
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function resolveChartFontFamily() {
    const fromCssVar = window
      .getComputedStyle(document.documentElement)
      .getPropertyValue('--bs-body-font-family')
      .trim();

    return fromCssVar || "'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
  }
}
