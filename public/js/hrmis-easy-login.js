(function (window, document) {
  "use strict";

  var runButton = document.getElementById("runHrmisEasyLogin");
  var statusBox = document.getElementById("hrmisEasyLoginStatus");
  var resultBox = document.getElementById("hrmisEasyLoginResult");
  var dtrUrlInput = document.getElementById("hrmisDtrUrl");
  var emailInput = document.getElementById("hrmisEmail");
  var visualModeInput = document.getElementById("hrmisVisualMode");
  var slowMoInput = document.getElementById("hrmisSlowMoMs");
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');

  if (!runButton || !statusBox || !resultBox) {
    return;
  }

  function appendStatus(message, tone) {
    var row = document.createElement("div");
    var palette = {
      info: "text-body",
      success: "text-success",
      warn: "text-warning",
      danger: "text-danger",
    };

    row.className = palette[tone || "info"] || palette.info;
    row.textContent = String(message || "");
    statusBox.appendChild(row);
    statusBox.scrollTop = statusBox.scrollHeight;
  }

  function resetStatus() {
    statusBox.innerHTML = "";
  }

  function showResult(ok, message) {
    resultBox.classList.remove("d-none", "alert-success", "alert-danger", "alert-info");
    resultBox.classList.add(ok ? "alert-success" : "alert-danger");
    resultBox.textContent = String(message || "");
  }

  function setRunning(running) {
    runButton.disabled = !!running;
    if (running) {
      runButton.innerHTML =
        '<i class="spinner-grow spinner-grow-sm me-1"></i> Running HRMIS Time In...';
      return;
    }

    runButton.innerHTML = '<i class="bx bx-log-in-circle me-1"></i> Run Easy Login + Time In';
  }

  function extractErrorMessage(rawText) {
    var compact = String(rawText || "").replace(/\s+/g, " ").trim();

    try {
      var parsed = JSON.parse(rawText);
      if (parsed && typeof parsed.message === "string" && parsed.message.trim()) {
        return parsed.message.trim();
      }

      if (parsed && parsed.errors && typeof parsed.errors === "object") {
        var keys = Object.keys(parsed.errors);
        for (var i = 0; i < keys.length; i += 1) {
          var value = parsed.errors[keys[i]];
          if (Array.isArray(value) && value.length > 0) {
            return String(value[0] || "").trim();
          }
          if (typeof value === "string" && value.trim()) {
            return value.trim();
          }
        }
      }
    } catch (error) {
      // Fall through to compact text.
    }

    if (compact.length > 260) {
      return compact.slice(0, 260) + "...";
    }

    return compact || "Unknown server error.";
  }

  function buildPayload() {
    var dtrUrl = (dtrUrlInput && dtrUrlInput.value) || "";
    var email = (emailInput && emailInput.value) || "";
    var visualMode = !!(visualModeInput && visualModeInput.checked);
    var slowMoMs = Number((slowMoInput && slowMoInput.value) || 0);

    var baseUrl = "";
    try {
      var parsed = new URL(dtrUrl);
      baseUrl = parsed.origin;
    } catch (error) {
      baseUrl = "";
    }

    var payload = new URLSearchParams();
    if (baseUrl) {
      payload.set("hrmis_base_url", baseUrl);
    }
    if (dtrUrl) {
      payload.set("hrmis_dtr_url", dtrUrl);
    }
    if (email) {
      payload.set("hrmis_email", email);
    }

    payload.set("headless", visualMode ? "0" : "1");

    if (!Number.isNaN(slowMoMs) && slowMoMs >= 0) {
      payload.set("slow_mo_ms", String(Math.min(2000, Math.floor(slowMoMs))));
    }

    return payload;
  }

  async function runEasyLogin() {
    setRunning(true);
    resetStatus();
    appendStatus("Starting HRMIS Easy Login + Time In...", "info");

    var endpoint = runButton.getAttribute("data-endpoint") || "/forms/easy-login/hrmis/time-in";
    var csrfToken = csrfMeta ? csrfMeta.getAttribute("content") : "";

    try {
      var payload = buildPayload();

      var response = await window.fetch(endpoint, {
        method: "POST",
        headers: {
          "X-CSRF-TOKEN": csrfToken || "",
          Accept: "application/json",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: payload.toString(),
      });

      if (!response.ok) {
        var rawError = await response.text();
        throw new Error(
          "Easy Login failed (" +
            response.status +
            "). " +
            extractErrorMessage(rawError)
        );
      }

      var data = await response.json();
      var ok = !!(data && data.ok);
      var message =
        (data && typeof data.message === "string" && data.message.trim()) ||
        "HRMIS Time In request completed.";

      appendStatus(message, ok ? "success" : "warn");
      showResult(ok, message);
    } catch (error) {
      var messageText = error && error.message ? error.message : "Easy Login request failed.";
      appendStatus(messageText, "danger");
      showResult(false, messageText);
    } finally {
      setRunning(false);
    }
  }

  runButton.addEventListener("click", function () {
    runEasyLogin();
  });
})(window, document);
