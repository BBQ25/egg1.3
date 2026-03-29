/* global fetch */
(function () {
  "use strict";

  var cfg = window.DOC_EASE_SESSION || null;
  if (!cfg) return;

  var idleTimeoutMs = Number(cfg.idleTimeoutMs || 0);
  if (!Number.isFinite(idleTimeoutMs) || idleTimeoutMs <= 0) return;

  var keepaliveUrl = String(cfg.keepaliveUrl || "");
  var signalPollUrl = String(cfg.signalPollUrl || "");
  var signalPollEveryMs = Number(cfg.signalPollEveryMs || 15000);
  if (!Number.isFinite(signalPollEveryMs) || signalPollEveryMs < 5000) {
    signalPollEveryMs = 15000;
  }
  var logoutUrl = String(cfg.logoutUrl || "");
  var logoutReason = String(cfg.logoutReason || "timeout");
  var logoutCsrfToken = String(cfg.logoutCsrfToken || "");
  var logoutLegacyUrl = String(cfg.logoutLegacyUrl || "");
  if (!logoutUrl && !logoutLegacyUrl) return;

  var lastActivityAt = Date.now();
  var lastPingAt = Date.now();
  var pingInFlight = false;
  var activitySincePing = false;
  var logoutInProgress = false;
  var signalPollInFlight = false;
  var lastSignalPollAt = 0;
  var refreshVersionStorageKey = "docEaseSessionRefreshVersion";

  function doLogout() {
    if (logoutInProgress) return;
    logoutInProgress = true;

    if (logoutUrl && logoutCsrfToken) {
      try {
        var form = document.createElement("form");
        form.method = "POST";
        form.action = logoutUrl;
        form.style.display = "none";

        var csrfField = document.createElement("input");
        csrfField.type = "hidden";
        csrfField.name = "csrf_token";
        csrfField.value = logoutCsrfToken;
        form.appendChild(csrfField);

        var reasonField = document.createElement("input");
        reasonField.type = "hidden";
        reasonField.name = "reason";
        reasonField.value = logoutReason || "timeout";
        form.appendChild(reasonField);

        (document.body || document.documentElement).appendChild(form);
        form.submit();
        return;
      } catch (e) {
        // Fall through to legacy GET redirect for compatibility.
      }
    }

    if (logoutLegacyUrl) {
      window.location.href = logoutLegacyUrl;
      return;
    }

    if (logoutUrl) {
      var sep = logoutUrl.indexOf("?") >= 0 ? "&" : "?";
      window.location.href =
        logoutUrl + sep + "reason=" + encodeURIComponent(logoutReason || "timeout");
    }
  }

  function readSeenRefreshVersion() {
    try {
      if (!window.sessionStorage) return null;
      var raw = window.sessionStorage.getItem(refreshVersionStorageKey);
      var value = Number(raw || 0);
      if (!Number.isFinite(value) || value <= 0) return null;
      return value;
    } catch (e) {
      return null;
    }
  }

  function writeSeenRefreshVersion(version) {
    var value = Number(version || 0);
    if (!Number.isFinite(value) || value <= 0) return;
    try {
      if (!window.sessionStorage) return;
      window.sessionStorage.setItem(refreshVersionStorageKey, String(value));
    } catch (e) {
      // Ignore storage failures (privacy mode, quota, etc.).
    }
  }

  function pollSignals() {
    if (!signalPollUrl) return;
    if (typeof fetch !== "function") return;
    if (signalPollInFlight || logoutInProgress) return;

    var now = Date.now();
    if (now - lastSignalPollAt < signalPollEveryMs) return;
    lastSignalPollAt = now;
    signalPollInFlight = true;

    fetch(signalPollUrl, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, data: data };
          });
      })
      .then(function (result) {
        signalPollInFlight = false;
        var data = result && typeof result === "object" ? result.data : null;

        if (data && typeof data === "object" && data.code === "SESSION_EXPIRED") {
          doLogout();
          return;
        }

        if (!result || !result.ok) {
          return;
        }

        if (!data || typeof data !== "object") return;

        var refreshVersion = Number(data.refresh_version || 0);
        if (!Number.isFinite(refreshVersion) || refreshVersion <= 0) return;

        var seenRefreshVersion = readSeenRefreshVersion();
        if (seenRefreshVersion === null) {
          writeSeenRefreshVersion(refreshVersion);
          return;
        }

        if (refreshVersion > seenRefreshVersion) {
          writeSeenRefreshVersion(refreshVersion);
          window.location.reload();
        }
      })
      .catch(function () {
        signalPollInFlight = false;
        // Ignore transient failures (offline, restart, etc.).
      });
  }

  function ping() {
    if (!keepaliveUrl) return;
    if (typeof fetch !== "function") return;
    if (pingInFlight) return;

    var now = Date.now();
    if (!activitySincePing) return;
    if (now - lastPingAt < 60 * 1000) return; // at most once per minute

    pingInFlight = true;
    lastPingAt = now;

    fetch(keepaliveUrl, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, data: data };
          });
      })
      .then(function (result) {
        pingInFlight = false;
        activitySincePing = false;

        var data = result && typeof result === "object" ? result.data : null;
        if (data && typeof data === "object" && data.code === "SESSION_EXPIRED") {
          doLogout();
          return;
        }

        if (!result || !result.ok) {
          return;
        }
      })
      .catch(function () {
        pingInFlight = false;
        // Ignore transient failures (offline, server restart, etc.).
      });
  }

  function markActivity() {
    lastActivityAt = Date.now();
    activitySincePing = true;
    ping();
  }

  // Throttle high-frequency events.
  var lastMarkAt = 0;
  function throttledMarkActivity() {
    var now = Date.now();
    if (now - lastMarkAt < 1000) return;
    lastMarkAt = now;
    markActivity();
  }

  ["click", "keydown", "mousemove", "mousedown", "touchstart"].forEach(
    function (evt) {
      window.addEventListener(evt, throttledMarkActivity, false);
    }
  );
  // Scroll does not bubble; capture it at the document level so element scrolls count as activity.
  document.addEventListener("scroll", throttledMarkActivity, true);

  // Check idle timeout (client-side auto logout).
  setInterval(function () {
    pollSignals();

    var now = Date.now();
    if (now - lastActivityAt >= idleTimeoutMs) {
      doLogout();
      return;
    }

    // Opportunistic keepalive in case events are sparse.
    ping();
  }, 5000);

  // Prime refresh version for this tab quickly after load.
  setTimeout(pollSignals, 750);
})();
