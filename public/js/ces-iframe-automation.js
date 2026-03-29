(function (window, document) {
  "use strict";

  function normalize(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/\s+/g, " ")
      .trim();
  }

  function bySelectors(root, selectors) {
    for (var i = 0; i < selectors.length; i += 1) {
      var node = root.querySelector(selectors[i]);
      if (node) {
        return node;
      }
    }
    return null;
  }

  function dispatchChange(node) {
    node.dispatchEvent(new Event("input", { bubbles: true }));
    node.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function wait(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function waitFor(fn, timeoutMs, intervalMs) {
    var timeout = timeoutMs || 12000;
    var interval = intervalMs || 250;
    var startedAt = Date.now();

    return new Promise(function (resolve, reject) {
      function poll() {
        var result = fn();
        if (result) {
          resolve(result);
          return;
        }

        if (Date.now() - startedAt >= timeout) {
          reject(new Error("Timed out waiting for expected CES element."));
          return;
        }

        window.setTimeout(poll, interval);
      }

      poll();
    });
  }

  var iframe = document.getElementById("cesAutomationFrame");
  var loadButton = document.getElementById("loadCesFrame");
  var runButton = document.getElementById("runCesAutomation");
  var runServerButton = document.getElementById("runCesServerAutomation");
  var runServerTestButton = document.getElementById("testCesServerConnection");
  var statusBox = document.getElementById("cesAutomationStatus");
  var urlInput = document.getElementById("cesFrameUrl");
  var campusInput = document.getElementById("cesCampus");
  var schoolYearInput = document.getElementById("cesSchoolYear");
  var semesterInput = document.getElementById("cesSemester");
  var sectionInput = document.getElementById("cesSectionCode");
  var usernameInput = document.getElementById("cesUsername");
  var passwordInput = document.getElementById("cesPassword");
  var filenameInput = document.getElementById("cesFilename");
  var visualModeInput = document.getElementById("cesVisualMode");
  var slowMoInput = document.getElementById("cesSlowMoMs");
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');

  if (!iframe || !loadButton || !runButton || !statusBox) {
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
    row.textContent = message;
    statusBox.appendChild(row);
    statusBox.scrollTop = statusBox.scrollHeight;
  }

  function resetStatus() {
    statusBox.innerHTML = "";
  }

  function getFrameDocument() {
    if (!iframe.contentWindow) {
      throw new Error("Iframe is not ready yet.");
    }

    try {
      return iframe.contentWindow.document;
    } catch (error) {
      throw new Error(
        "Cannot control CES from iframe: blocked by cross-origin policy (X-Frame-Options/SAMEORIGIN)."
      );
    }
  }

  function selectOptionByText(select, candidates) {
    var expected = candidates.map(normalize);
    var options = Array.prototype.slice.call(select.options || []);

    for (var j = 0; j < expected.length; j += 1) {
      for (var i = 0; i < options.length; i += 1) {
        var option = options[i];
        var optionText = normalize(option.textContent || option.label || option.value);
        if (optionText === expected[j] || optionText.indexOf(expected[j]) !== -1) {
          select.value = option.value;
          dispatchChange(select);
          return true;
        }
      }
    }

    return false;
  }

  function findSchoolYearSelect(root) {
    return bySelectors(root, [
      "select#SchoolYear",
      "select[name='SchoolYear']",
      "select#school_year",
      "select[name='school_year']",
      "select.form-select",
    ]);
  }

  function findSemesterSelect(root) {
    return bySelectors(root, [
      "select#Semester",
      "select[name='Semester']",
      "select#semester",
      "select[name='semester']",
      "select.form-select",
    ]);
  }

  function findViewTrigger(root) {
    var buttons = root.querySelectorAll("a, button, input[type='button'], input[type='submit']");
    for (var i = 0; i < buttons.length; i += 1) {
      var element = buttons[i];
      var label = normalize(element.textContent || element.value || "");
      if (label === "view" || label.indexOf(" view") !== -1) {
        return element;
      }
    }
    return null;
  }

  function clickSection(root, sectionCode) {
    var rows = root.querySelectorAll("tr");
    var matchText = normalize(sectionCode);

    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      if (normalize(row.textContent).indexOf(matchText) === -1) {
        continue;
      }

      var target = row.querySelector("a, button, td a, td button");
      if (target) {
        target.click();
        return true;
      }
    }

    var links = root.querySelectorAll("a, button");
    for (var j = 0; j < links.length; j += 1) {
      var link = links[j];
      if (normalize(link.textContent).indexOf(matchText) !== -1) {
        link.click();
        return true;
      }
    }

    return false;
  }

  function clickGenerate(root) {
    var nodes = root.querySelectorAll("a, button, input[type='button'], input[type='submit']");
    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];
      var label = normalize(node.textContent || node.value || "");
      if (
        label.indexOf("generate grade sheet") !== -1 ||
        label.indexOf("geneerate grade sheet") !== -1
      ) {
        node.click();
        return true;
      }
    }
    return false;
  }

  function semesterCandidates(rawSemester) {
    var raw = normalize(rawSemester);
    if (raw.indexOf("1") !== -1 || raw.indexOf("first") !== -1) {
      return ["1st semester", "first semester", "1st", "1"];
    }
    if (raw.indexOf("2") !== -1 || raw.indexOf("second") !== -1) {
      return ["2nd semester", "second semester", "2nd", "2"];
    }
    return [rawSemester, "summer", "sum"];
  }

  function setRunning(running) {
    runButton.disabled = running;
    if (running) {
      runButton.innerHTML = '<i class="spinner-grow spinner-grow-sm me-1"></i> Running...';
      return;
    }
    runButton.innerHTML = '<i class="bx bx-play-circle me-1"></i> Run Auto Click Flow';
  }

  function setServerRunning(running) {
    if (!runServerButton) {
      return;
    }

    runServerButton.disabled = running;
    if (running) {
      runServerButton.innerHTML =
        '<i class="spinner-grow spinner-grow-sm me-1"></i> Running Server Playwright...';
      return;
    }

    runServerButton.innerHTML =
      '<i class="bx bx-rocket me-1"></i> Run Server Playwright + Download PDF';
  }

  function setServerTestRunning(running) {
    if (!runServerTestButton) {
      return;
    }

    runServerTestButton.disabled = running;
    if (running) {
      runServerTestButton.innerHTML =
        '<i class="spinner-grow spinner-grow-sm me-1"></i> Testing CES Session...';
      return;
    }

    runServerTestButton.innerHTML =
      '<i class="bx bx-check-shield me-1"></i> Test CES Connection';
  }

  function parseFilenameFromDisposition(value) {
    var raw = String(value || "");
    var utf8Match = raw.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf8Match && utf8Match[1]) {
      try {
        return decodeURIComponent(utf8Match[1].replace(/["']/g, "").trim());
      } catch (error) {
        return utf8Match[1].replace(/["']/g, "").trim();
      }
    }

    var basicMatch = raw.match(/filename=([^;]+)/i);
    if (basicMatch && basicMatch[1]) {
      return basicMatch[1].replace(/["']/g, "").trim();
    }

    return "";
  }

  function buildServerPayload(includeFilename) {
    var campus = (campusInput && campusInput.value) || "Bontoc";
    var schoolYear = (schoolYearInput && schoolYearInput.value) || "2025-2026";
    var semester = (semesterInput && semesterInput.value) || "1st Semester";
    var sectionCode = (sectionInput && sectionInput.value) || "IF-2-A-6";
    var cesUrl = (urlInput && urlInput.value) || "https://ces.southernleytestateu.edu.ph/teacher/encode-grades";
    var cesBaseUrl = cesUrl.split("/teacher/")[0];
    var cesUsername = (usernameInput && usernameInput.value) || "";
    var cesPassword = (passwordInput && passwordInput.value) || "";
    var requestedFilename = (filenameInput && filenameInput.value) || "";
    var visualMode = !!(visualModeInput && visualModeInput.checked);
    var slowMoMs = Number((slowMoInput && slowMoInput.value) || 0);

    var payload = new URLSearchParams();
    payload.set("ces_base_url", cesBaseUrl);
    payload.set("campus", campus);
    payload.set("school_year", schoolYear);
    payload.set("semester", semester);
    payload.set("section_code", sectionCode);
    payload.set("headless", visualMode ? "0" : "1");

    if (!Number.isNaN(slowMoMs) && slowMoMs >= 0) {
      payload.set("slow_mo_ms", String(Math.min(2000, Math.floor(slowMoMs))));
    }

    if (cesUsername) {
      payload.set("ces_username", cesUsername);
    }
    if (cesPassword) {
      payload.set("ces_password", cesPassword);
    }
    if (includeFilename && requestedFilename) {
      payload.set("filename", requestedFilename);
    }

    return {
      payload: payload,
      schoolYear: schoolYear,
      sectionCode: sectionCode,
    };
  }

  async function runServerAutomation() {
    if (!runServerButton) {
      return;
    }

    setServerRunning(true);
    appendStatus("Starting server-side Playwright automation...", "info");

    var endpoint = runServerButton.getAttribute("data-endpoint") || "/forms/gradesheet/ces";
    var csrfToken = csrfMeta ? csrfMeta.getAttribute("content") : "";

    try {
      var built = buildServerPayload(true);
      var payload = built.payload;
      var schoolYear = built.schoolYear;
      var sectionCode = built.sectionCode;

      var response = await window.fetch(endpoint, {
        method: "POST",
        headers: {
          "X-CSRF-TOKEN": csrfToken || "",
          Accept: "application/pdf, application/json, text/plain",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: payload.toString(),
      });

      if (!response.ok) {
        var errorText = await response.text();
        var shortMessage = (errorText || "").replace(/\s+/g, " ").trim();
        if (shortMessage.length > 240) {
          shortMessage = shortMessage.slice(0, 240) + "...";
        }
        throw new Error(
          "Server Playwright failed (" +
            response.status +
            "). " +
            (shortMessage || "See server logs.")
        );
      }

      var pdfBlob = await response.blob();
      if (!pdfBlob || !pdfBlob.size) {
        throw new Error("Server returned an empty PDF response.");
      }

      var filename =
        parseFilenameFromDisposition(response.headers.get("content-disposition")) ||
        sectionCode + "-" + schoolYear + ".pdf";

      var link = document.createElement("a");
      link.href = window.URL.createObjectURL(pdfBlob);
      link.download = filename;
      link.click();
      window.URL.revokeObjectURL(link.href);

      appendStatus("Server Playwright completed. Downloaded: " + filename, "success");
    } catch (error) {
      appendStatus(error.message || "Server Playwright request failed.", "danger");
    } finally {
      setServerRunning(false);
    }
  }

  async function runServerConnectionTest() {
    if (!runServerTestButton) {
      return;
    }

    setServerTestRunning(true);
    appendStatus("Testing CES login/session via server Playwright...", "info");

    var endpoint =
      runServerTestButton.getAttribute("data-endpoint") || "/forms/gradesheet/ces/test-connection";
    var csrfToken = csrfMeta ? csrfMeta.getAttribute("content") : "";

    try {
      var built = buildServerPayload(false);
      var payload = built.payload;

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
        var errorBody = await response.text();
        var shortText = (errorBody || "").replace(/\s+/g, " ").trim();
        if (shortText.length > 240) {
          shortText = shortText.slice(0, 240) + "...";
        }
        throw new Error(
          "CES connection test failed (" + response.status + "). " + (shortText || "Check server logs.")
        );
      }

      var data = await response.json();
      appendStatus(
        (data && data.message) || "CES login/session verified.",
        "success"
      );
    } catch (error) {
      appendStatus(error.message || "CES connection test failed.", "danger");
    } finally {
      setServerTestRunning(false);
    }
  }

  async function runAutomation() {
    resetStatus();
    setRunning(true);

    try {
      appendStatus("Starting CES auto-click flow...", "info");

      var doc = getFrameDocument();
      var yearValue = (schoolYearInput && schoolYearInput.value) || "2025-2026";
      var semesterValue = (semesterInput && semesterInput.value) || "1st Semester";
      var sectionValue = (sectionInput && sectionInput.value) || "IF-2-A-6";

      var schoolYearSelect = await waitFor(function () {
        return findSchoolYearSelect(doc);
      });

      if (!selectOptionByText(schoolYearSelect, [yearValue])) {
        throw new Error('School Year "' + yearValue + '" option was not found in CES dropdown.');
      }
      appendStatus("School Year selected: " + yearValue, "success");

      await wait(350);

      var semesterSelect = await waitFor(function () {
        return findSemesterSelect(doc);
      });

      if (!selectOptionByText(semesterSelect, semesterCandidates(semesterValue))) {
        throw new Error('Semester "' + semesterValue + '" option was not found in CES dropdown.');
      }
      appendStatus("Semester selected: " + semesterValue, "success");

      await wait(350);

      var viewButton = findViewTrigger(doc);
      if (viewButton) {
        viewButton.click();
        appendStatus("Clicked View.", "success");
      } else {
        appendStatus("View button not found. Continuing with current content.", "warn");
      }

      await wait(1400);
      doc = getFrameDocument();

      if (clickSection(doc, sectionValue)) {
        appendStatus("Clicked section: " + sectionValue, "success");
        await wait(1400);
      } else {
        appendStatus(
          'Section "' + sectionValue + '" not found in current CES table. Continuing...',
          "warn"
        );
      }

      doc = getFrameDocument();
      if (!clickGenerate(doc)) {
        throw new Error('Generate Grade Sheet button was not found in the CES page.');
      }

      appendStatus("Clicked Generate Grade Sheet.", "success");
      appendStatus("Automation done.", "success");
    } catch (error) {
      appendStatus(error.message || "Automation failed.", "danger");
    } finally {
      setRunning(false);
    }
  }

  loadButton.addEventListener("click", function () {
    resetStatus();
    var url = (urlInput && urlInput.value) || "";
    if (!url) {
      appendStatus("Please enter CES URL first.", "warn");
      return;
    }
    iframe.src = url;
    appendStatus("Loading iframe: " + url, "info");
  });

  runButton.addEventListener("click", function () {
    runAutomation();
  });

  if (runServerButton) {
    runServerButton.addEventListener("click", function () {
      runServerAutomation();
    });
  }

  if (runServerTestButton) {
    runServerTestButton.addEventListener("click", function () {
      runServerConnectionTest();
    });
  }

  iframe.addEventListener("load", function () {
    appendStatus("Iframe loaded. Checking iframe access...", "info");
    try {
      getFrameDocument();
      appendStatus("Iframe is script-accessible. You can run auto-click flow.", "success");
    } catch (error) {
      appendStatus(error.message, "danger");
    }
  });
})(window, document);
