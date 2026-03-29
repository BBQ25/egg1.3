const root = document.getElementById('eggRecordLivePanel');

if (root) {
    const refreshLabel = document.getElementById('eggRecordLiveRefreshedAt');
    const gapLabel = document.getElementById('eggRecordLiveGap');
    const totalLabel = document.getElementById('eggRecordLiveTotalRecords');
    const latestLabel = document.getElementById('eggRecordLiveLatestRecord');
    const rowsHost = document.getElementById('eggRecordLiveRows');
    const tallyHost = document.getElementById('eggRecordLiveTally');
    const statusHost = document.getElementById('eggRecordLiveStatus');
    const pageSummaryHost = document.getElementById('eggRecordLivePageSummary');
    const pageLabelHost = document.getElementById('eggRecordLivePageLabel');
    const prevButton = document.getElementById('eggRecordLivePrev');
    const nextButton = document.getElementById('eggRecordLiveNext');
    const intervalMs = Number(root.dataset.refreshIntervalMs || 2000);
    let currentPage = Math.max(1, Number(root.dataset.livePage || 1));
    let inFlight = false;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const formatDateTime = (value) => {
        if (!value) {
            return 'N/A';
        }

        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    };

    const formatWeight = (value) => `${Number(value || 0).toFixed(2)} g`;

    const buildUrl = () => {
        const liveUrl = new URL(root.dataset.liveUrl, window.location.origin);
        const currentUrl = new URL(window.location.href);
        liveUrl.search = currentUrl.search;
        liveUrl.searchParams.set('live_page', String(currentPage));

        return liveUrl.toString();
    };

    const renderPagination = (pagination) => {
        if (!pagination) {
            return;
        }

        const current = Number(pagination.current_page || 1);
        const last = Math.max(1, Number(pagination.last_page || 1));
        const total = Number(pagination.total || 0);
        const from = pagination.from;
        const to = pagination.to;

        currentPage = current;
        root.dataset.livePage = String(current);

        if (pageSummaryHost) {
            pageSummaryHost.textContent = total > 0
                ? `Showing ${new Intl.NumberFormat().format(from || 0)} to ${new Intl.NumberFormat().format(to || 0)} of ${new Intl.NumberFormat().format(total)} live records`
                : 'No live records available';
        }

        if (pageLabelHost) {
            pageLabelHost.innerHTML = `Page <strong>${escapeHtml(current)}</strong> of <strong>${escapeHtml(last)}</strong>`;
        }

        if (prevButton) {
            prevButton.disabled = current <= 1;
        }

        if (nextButton) {
            nextButton.disabled = current >= last;
        }
    };

    const renderTally = (sizeTally) => {
        if (!tallyHost) {
            return;
        }

        tallyHost.innerHTML = sizeTally.map((row) => `
            <div class="egg-record-live-chip">
              <span class="egg-record-live-chip__label">${escapeHtml(row.size_class)}</span>
              <strong>${escapeHtml(row.total)}</strong>
            </div>
        `).join('');
    };

    const renderRows = (rows) => {
        if (!rowsHost) {
            return;
        }

        if (!rows.length) {
            rowsHost.innerHTML = `
                <tr>
                  <td colspan="6" class="text-center text-body-secondary py-4">No live ingest records matched the current scope.</td>
                </tr>
            `;

            return;
        }

        rowsHost.innerHTML = rows.map((row) => `
            <tr>
              <td>${escapeHtml(formatDateTime(row.recorded_at))}</td>
              <td>${escapeHtml(row.egg_uid || 'Not set')}</td>
              <td><span class="badge bg-label-primary">${escapeHtml(row.size_class)}</span></td>
              <td>${escapeHtml(formatWeight(row.weight_grams))}</td>
              <td>${escapeHtml(row.batch_code || 'Not batched')}</td>
              <td>
                <div>${escapeHtml(row.device_name || 'Unknown device')}</div>
                <div class="small text-body-secondary">${escapeHtml(row.device_serial || '')}</div>
              </td>
            </tr>
        `).join('');
    };

    const renderSnapshot = (payload) => {
        if (!payload || payload.ok === false) {
            if (statusHost) {
                statusHost.textContent = payload?.message || 'Live feed unavailable';
            }

            return;
        }

        if (statusHost) {
            statusHost.textContent = 'Live feed connected';
        }

        if (refreshLabel) {
            refreshLabel.textContent = formatDateTime(payload.as_of);
        }

        if (gapLabel) {
            const gap = payload.stats?.observed_gap_seconds;
            gapLabel.textContent = gap === null || gap === undefined ? 'Awaiting device timestamps' : `${Number(gap).toFixed(1)} s`;
        }

        if (totalLabel) {
            totalLabel.textContent = new Intl.NumberFormat().format(payload.stats?.total_records || 0);
        }

        if (latestLabel) {
            latestLabel.textContent = formatDateTime(payload.stats?.latest_recorded_at || null);
        }

        renderTally(payload.size_tally || []);
        renderRows(payload.recent_records || []);
        renderPagination(payload.pagination || null);
    };

    const refresh = async () => {
        if (inFlight) {
            return;
        }

        inFlight = true;

        try {
            const response = await fetch(buildUrl(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            const payload = await response.json();
            renderSnapshot(payload);
        } catch (error) {
            if (statusHost) {
                statusHost.textContent = 'Live feed retrying';
            }
        } finally {
            inFlight = false;
        }
    };

    if (prevButton) {
        prevButton.addEventListener('click', () => {
            if (inFlight || currentPage <= 1) {
                return;
            }

            currentPage -= 1;
            refresh();
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => {
            if (inFlight) {
                return;
            }

            currentPage += 1;
            refresh();
        });
    }

    refresh();
    window.setInterval(refresh, intervalMs);
}
