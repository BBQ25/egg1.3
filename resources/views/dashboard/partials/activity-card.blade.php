<article class="nms-card nms-card--activity">
  <div class="nms-card-head">
    <h3>Client Device Types</h3>
    <div class="nms-chip-group" role="tablist" aria-label="Activity filters">
      <button type="button" class="nms-chip is-active" data-activity-filter="all">All</button>
      <button type="button" class="nms-chip" data-activity-filter="wired">Wired</button>
      <button type="button" class="nms-chip" data-activity-filter="wireless">Wireless</button>
      <button type="button" class="nms-chip" data-activity-filter="guest">Guest</button>
      <i class="bx bx-chevron-right"></i>
    </div>
  </div>

  <div class="nms-card-body-split">
    <div class="nms-donut-wrap">
      <div id="dashboardActivityChart" class="nms-donut-chart"></div>
      <div class="nms-total-center">
        <small>Total Clients</small>
        <strong id="dashboardActivityTotal">0</strong>
      </div>
    </div>

    <div class="nms-table-wrap">
      <table class="nms-data-table">
        <thead>
          <tr>
            <th>TITLE</th>
            <th class="text-center">ACTIVITY</th>
            <th class="text-end">EXP</th>
            <th class="text-end">TOTAL</th>
          </tr>
        </thead>
        <tbody id="dashboardActivityTableBody">
          <tr>
            <td colspan="4" class="text-center text-body-secondary">No activity records.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</article>
