/* global FullCalendar */
(function () {
  "use strict";

  var el = document.getElementById("admin-schedule-calendar");
  if (!el || typeof FullCalendar === "undefined") return;

  var events = window.__ADMIN_SCHEDULE_EVENTS__ || [];

  var dowEl = document.getElementById("admin-slot-dow");
  var startEl = document.getElementById("admin-slot-start");
  var endEl = document.getElementById("admin-slot-end");
  var roomEl = document.getElementById("admin-slot-room");

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function fmtTime(d) {
    if (!(d instanceof Date)) return "";
    return pad2(d.getHours()) + ":" + pad2(d.getMinutes());
  }

  function setFormFromSelection(sel) {
    if (!sel || !sel.start) return;
    // FullCalendar: 0=Sun..6=Sat
    if (dowEl) dowEl.value = String(sel.start.getDay());
    if (startEl) startEl.value = fmtTime(sel.start);
    if (endEl) endEl.value = sel.end ? fmtTime(sel.end) : "";
    if (roomEl && roomEl.value === "") {
      // Encourage keeping lab/room consistent with the schedule list
      roomEl.value = "";
    }
  }

  function setNavButtons() {
    var prevBtn = el.querySelector(".fc-prev-button");
    var nextBtn = el.querySelector(".fc-next-button");

    // Use thicker "fill" icons so they're obvious against the primary button bg.
    if (prevBtn) {
      prevBtn.innerHTML = '<i class="ri-arrow-left-s-fill" aria-hidden="true"></i>';
      prevBtn.setAttribute("title", "Previous");
      prevBtn.setAttribute("aria-label", "Previous");
    }
    if (nextBtn) {
      nextBtn.innerHTML = '<i class="ri-arrow-right-s-fill" aria-hidden="true"></i>';
      nextBtn.setAttribute("title", "Next");
      nextBtn.setAttribute("aria-label", "Next");
    }
  }

  function clamp255(v) {
    var n = Number(v);
    if (!Number.isFinite(n)) return 0;
    if (n < 0) return 0;
    if (n > 255) return 255;
    return n;
  }

  function parseColorToRgb(value) {
    if (!value) return null;
    var input = String(value).trim();

    var hex = input.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (hex) {
      var raw = hex[1];
      if (raw.length === 3) {
        return {
          r: clamp255(parseInt(raw.charAt(0) + raw.charAt(0), 16)),
          g: clamp255(parseInt(raw.charAt(1) + raw.charAt(1), 16)),
          b: clamp255(parseInt(raw.charAt(2) + raw.charAt(2), 16)),
        };
      }
      return {
        r: clamp255(parseInt(raw.slice(0, 2), 16)),
        g: clamp255(parseInt(raw.slice(2, 4), 16)),
        b: clamp255(parseInt(raw.slice(4, 6), 16)),
      };
    }

    var rgb = input.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
    if (!rgb) return null;
    return {
      r: clamp255(rgb[1]),
      g: clamp255(rgb[2]),
      b: clamp255(rgb[3]),
    };
  }

  function channelLuminance(c) {
    var v = clamp255(c) / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  }

  function relativeLuminance(rgb) {
    return (
      0.2126 * channelLuminance(rgb.r) +
      0.7152 * channelLuminance(rgb.g) +
      0.0722 * channelLuminance(rgb.b)
    );
  }

  function contrastRatio(a, b) {
    var hi = Math.max(a, b);
    var lo = Math.min(a, b);
    return (hi + 0.05) / (lo + 0.05);
  }

  function pickReadableTextColor(backgroundColor, preferredTextColor) {
    var bgRgb = parseColorToRgb(backgroundColor);
    var preferredRgb = parseColorToRgb(preferredTextColor);

    // If we can parse both and the preferred text already meets AA for normal text,
    // keep it as-is for consistency with subject color chips.
    if (bgRgb && preferredRgb) {
      var bgL = relativeLuminance(bgRgb);
      var preferredL = relativeLuminance(preferredRgb);
      if (contrastRatio(bgL, preferredL) >= 4.5) {
        return preferredTextColor;
      }
    }

    if (!bgRgb) return preferredTextColor || "#0f172a";

    // Compare dark and white alternatives, then pick the stronger one.
    var dark = { r: 15, g: 23, b: 42 };
    var light = { r: 255, g: 255, b: 255 };
    var bgLum = relativeLuminance(bgRgb);
    var darkContrast = contrastRatio(bgLum, relativeLuminance(dark));
    var lightContrast = contrastRatio(bgLum, relativeLuminance(light));
    return darkContrast >= lightContrast ? "#0f172a" : "#ffffff";
  }

  function applyReadableEventText(info) {
    if (!info || !info.el || !info.event) return;

    var eventEl = info.el;
    var bgColor =
      info.event.backgroundColor ||
      eventEl.style.backgroundColor ||
      window.getComputedStyle(eventEl).backgroundColor;
    var borderColor =
      info.event.borderColor ||
      eventEl.style.borderColor ||
      window.getComputedStyle(eventEl).borderColor;
    var textColor = pickReadableTextColor(bgColor, info.event.textColor || "");

    if (bgColor) eventEl.style.setProperty("--fc-event-bg-color", bgColor);
    if (borderColor) eventEl.style.setProperty("--fc-event-border-color", borderColor);
    if (textColor) eventEl.style.setProperty("--fc-event-text-color", textColor);

    // Force text visibility against any legacy theme overrides.
    eventEl.style.setProperty("color", textColor, "important");
    eventEl.style.setProperty("opacity", "1", "important");

    var nodes = eventEl.querySelectorAll(".fc-event-main, .fc-event-time, .fc-event-title");
    nodes.forEach(function (node) {
      node.style.setProperty("color", textColor, "important");
      node.style.setProperty("opacity", "1", "important");
    });
  }

  var calendar = new FullCalendar.Calendar(el, {
    themeSystem: "bootstrap",
    initialView: "timeGridWeek",
    headerToolbar: {
      left: "prev,next today",
      center: "title",
      right: "timeGridWeek,timeGridDay,dayGridMonth,listWeek",
    },
    buttonText: {
      today: "Today",
      month: "Month",
      week: "Week",
      day: "Day",
      list: "List",
    },
    firstDay: 1,
    nowIndicator: true,
    allDaySlot: false,
    slotMinTime: "06:00:00",
    slotMaxTime: "21:00:00",
    expandRows: true,
    height: "auto",
    selectable: true,
    selectMirror: true,
    events: events,
    eventDidMount: function (info) {
      // Enforce readable text on plotted schedule blocks.
      applyReadableEventText(info);
    },
    datesSet: function () {
      // FullCalendar can rerender the toolbar; re-apply our icons when dates/view change.
      setNavButtons();
    },
    select: function (info) {
      setFormFromSelection(info);
      calendar.unselect();
      var formAnchor = document.getElementById("admin-add-slot-form");
      if (formAnchor) formAnchor.scrollIntoView({ behavior: "smooth", block: "start" });
    },
    eventClick: function (info) {
      info.jsEvent.preventDefault();
      var p = info.event.extendedProps || {};
      var slotId = p.slot_id ? Number(p.slot_id) : 0;
      if (slotId <= 0) return;

      var ok = window.confirm("Deactivate this slot?");
      if (!ok) return;

      var hid = document.getElementById("admin-deactivate-slot-id");
      var form = document.getElementById("admin-deactivate-slot");
      if (!hid || !form) return;
      hid.value = String(slotId);
      form.submit();
    },
  });

  calendar.render();

  // Initial toolbar icon fix (in case datesSet hasn't fired yet).
  setNavButtons();
})();
