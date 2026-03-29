/* global FullCalendar, bootstrap */
(function () {
  "use strict";

  var el = document.getElementById("teacher-schedule-calendar");
  if (!el || typeof FullCalendar === "undefined") return;

  var events = window.__TEACHER_SCHEDULE_EVENTS__ || [];

  var modalEl = document.getElementById("schedule-slot-modal");
  var modal = modalEl ? new bootstrap.Modal(modalEl, { backdrop: true }) : null;

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function fmtTime(d) {
    if (!(d instanceof Date)) return "";
    return pad2(d.getHours()) + ":" + pad2(d.getMinutes());
  }

  function safeText(el2, txt) {
    if (!el2) return;
    el2.textContent = txt == null ? "" : String(txt);
  }

  function setHref(el2, href) {
    if (!el2) return;
    el2.setAttribute("href", href || "#");
  }

  function formatModality(value) {
    var raw = value == null ? "" : String(value).trim();
    if (!raw) return "-";
    return raw
      .replace(/_/g, " ")
      .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  var calendar = new FullCalendar.Calendar(el, {
    themeSystem: "bootstrap",
    initialView: "timeGridWeek",
    headerToolbar: {
      left: "prev,next today",
      center: "title",
      right: "timeGridWeek,dayGridMonth,listWeek",
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
    events: events,
    eventClick: function (info) {
      info.jsEvent.preventDefault();
      if (!modal) return;

      var ev = info.event;
      var p = ev.extendedProps || {};

      var title = ev.title || "Schedule Slot";
      var subj = (p.subject_code ? p.subject_code : "") + (p.section ? " (" + p.section + ")" : "");
      var subtitle = p.subject_name ? p.subject_name : "";
      var time =
        (ev.start ? fmtTime(ev.start) : "") +
        (ev.end ? " - " + fmtTime(ev.end) : "");

      var chip = document.getElementById("schedule-slot-chip");
      if (chip) {
        chip.textContent = subj || title;
        chip.style.setProperty("--subj-bg", ev.backgroundColor || "#f2f2f7");
        chip.style.setProperty("--subj-border", ev.borderColor || "#dee2e6");
        chip.style.setProperty("--subj-text", ev.textColor || "#343a40");
      }

      safeText(document.getElementById("schedule-slot-modal-title"), title);
      safeText(document.getElementById("schedule-slot-subtitle"), subtitle);
      safeText(document.getElementById("schedule-slot-time"), time || "-");
      safeText(document.getElementById("schedule-slot-room"), p.room || "-");
      safeText(document.getElementById("schedule-slot-modality"), formatModality(p.modality));

      var notesWrap = document.getElementById("schedule-slot-notes-wrap");
      if (notesWrap) notesWrap.classList.toggle("d-none", !p.notes);
      safeText(document.getElementById("schedule-slot-notes"), p.notes || "");

      var classId = p.class_record_id ? Number(p.class_record_id) : 0;
      var slotId = p.slot_id ? Number(p.slot_id) : 0;
      setHref(
        document.getElementById("schedule-slot-components"),
        classId > 0 ? "teacher-grading-config.php?class_record_id=" + classId : "#"
      );
      setHref(
        document.getElementById("schedule-slot-print"),
        classId > 0
          ? "class-record-print.php?class_record_id=" +
              classId +
              "&term=midterm&view=assessments"
          : "#"
      );
      setHref(
        document.getElementById("schedule-slot-wheel"),
        classId > 0 ? "teacher-wheel.php?class_record_id=" + classId : "#"
      );
      setHref(
        document.getElementById("schedule-slot-manage"),
        slotId > 0 ? "teacher-schedule.php?tab=manage&edit_slot_id=" + slotId : "teacher-schedule.php?tab=manage"
      );

      modal.show();
    },
  });

  calendar.render();
})();
