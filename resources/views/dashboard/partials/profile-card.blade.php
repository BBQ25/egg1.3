<article class="nms-card nms-card--profile">
  <div class="nms-device-image-wrap">
    <img src="{{ $heroImage }}" alt="Device render" class="nms-device-image" />
  </div>

  <h3 class="nms-device-title" id="dashboardProfileDeviceName">No active device selected</h3>
  <p class="nms-device-subtitle" id="dashboardProfileDeviceSerial">Serial not available</p>

  <div class="nms-profile-links">
    <a href="#" tabindex="-1" aria-disabled="true">Release Notes</a>
    <span>|</span>
    <a href="#" tabindex="-1" aria-disabled="true">Submit Ticket</a>
  </div>

  <div class="nms-kv-list">
    <div class="nms-kv-item">
      <span>WAN IP (Port 9)</span>
      <strong id="dashboardProfileWanIp">--</strong>
    </div>
    <div class="nms-kv-item">
      <span>Gateway IP</span>
      <strong id="dashboardProfileGatewayIp">--</strong>
    </div>
    <div class="nms-kv-item">
      <span>System Uptime</span>
      <strong id="dashboardProfileUptime">N/A</strong>
    </div>
  </div>

  <div class="nms-profile-internet">
    <div class="nms-profile-internet-row">
      <span>Internet</span>
      <strong id="dashboardProfileInternetName">No active uplink</strong>
    </div>
    <div class="nms-profile-internet-row">
      <span>Uptime</span>
      <strong id="dashboardProfileInternetUptime">0%</strong>
    </div>
  </div>

  <div class="nms-latency-grid">
    <div class="nms-latency-item">
      <div class="nms-latency-icon is-blue"><i class="bx bxl-facebook-circle"></i></div>
      <span id="dashboardLatencyA">2 ms</span>
    </div>
    <div class="nms-latency-item">
      <div class="nms-latency-icon is-red"><i class="bx bx-globe"></i></div>
      <span id="dashboardLatencyB">21 ms</span>
    </div>
    <div class="nms-latency-item">
      <div class="nms-latency-icon is-cyan"><i class="bx bx-chip"></i></div>
      <span id="dashboardLatencyC">43 ms</span>
    </div>
  </div>

  <div class="nms-speed-row">
    <button type="button" class="nms-link-btn" id="dashboardSpeedTestBtn">Run Speed Test</button>
    <span id="dashboardProfileLastTested">--</span>
  </div>

  <div class="nms-hidden-metrics" aria-hidden="true">
    <span id="dashboardSummaryTotalEggs">0</span>
    <span id="dashboardSummaryQualityScore">0%</span>
    <span id="dashboardSummaryFarms">0</span>
    <span id="dashboardEventsPerMinute">0.00 / min</span>
  </div>
</article>
