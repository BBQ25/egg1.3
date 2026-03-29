<article class="nms-card nms-card--timeline">
  <div class="nms-timeline-head">
    <div>
      <h3>Active Clients</h3>
      <p><span id="dashboardTimelineNow">0</span> Now / <span id="dashboardTimelineTotal">0</span> Total</p>
    </div>

    <div class="nms-timeline-filters">
      <label class="nms-filter-select">
        <i class="bx bx-router"></i>
        <span>Access Points</span>
        <select id="dashboardAccessPointFilter" aria-label="Access points filter">
          <option value="all">All</option>
          <option value="farm">Current Farm</option>
          <option value="device">Current Device</option>
        </select>
      </label>

      <label class="nms-filter-select">
        <i class="bx bx-broadcast"></i>
        <span>Radios</span>
        <select id="dashboardRadioFilter" aria-label="Radios filter">
          <option value="all">All</option>
          <option value="automated">Automated</option>
          <option value="manual">Manual</option>
        </select>
      </label>

      <label class="nms-filter-select">
        <i class="bx bx-wifi"></i>
        <span>WiFi Standards</span>
        <select id="dashboardWifiStdFilter" aria-label="WiFi standards filter">
          <option value="all">All</option>
          <option value="wifi4">WiFi 4</option>
          <option value="wifi5">WiFi 5</option>
          <option value="wifi6">WiFi 6</option>
        </select>
      </label>
    </div>

    <div class="nms-experience-box">
      <h4>WiFi Experience</h4>
      <p><span id="dashboardTimelineQuality">0%</span> Now</p>
    </div>
  </div>

  <div id="dashboardTimelineChart" class="nms-timeline-chart"></div>
</article>
