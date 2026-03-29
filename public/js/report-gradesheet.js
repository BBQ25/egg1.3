(function (window, $) {
  "use strict";

  if (!$) {
    return;
  }

  $.ajaxSetup({
    headers: {
      "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
    },
  });

  $(document).on("click", "#gradesheet", function (event) {
    event.preventDefault();

    var $button = $(this);
    var endpoint = $button.data("endpoint") || "/forms/gradesheet";
    var filename = String($("#filenameLabel").val() || $button.attr("filename") || "grade-sheet.pdf");

    var payload = {
      id: $button.attr("sid"),
      sy: $button.attr("sy"),
      sem: $button.attr("sem"),
      filename: filename,
      course_code: String($("#courseCode").val() || ""),
      course_title: String($("#courseTitle").val() || ""),
      schedule: String($("#scheduleLabel").val() || ""),
      section: String($("#sectionLabel").val() || ""),
    };

    if (!payload.id || !payload.sy || !payload.sem) {
      return;
    }

    $.ajax({
      url: endpoint,
      method: "POST",
      data: payload,
      cache: false,
      xhrFields: {
        responseType: "blob",
      },
      beforeSend: function () {
        $button.prop("disabled", true);
        $button.html('<i class="spinner-grow spinner-grow-sm me-1"></i> Generating...');
      },
      success: function (data) {
        if (data && data.size > 0) {
          var blob = new Blob([data], { type: "application/pdf" });
          var link = document.createElement("a");
          link.href = window.URL.createObjectURL(blob);
          link.download = filename;
          link.click();
          window.URL.revokeObjectURL(link.href);
        } else {
          window.location.reload();
        }
      },
      error: function () {
        if (window.Swal && typeof window.Swal.fire === "function") {
          window.Swal.fire("Unable to generate", "The grade sheet could not be generated.", "error");
        } else {
          window.alert("Unable to generate grade sheet.");
        }
      },
      complete: function () {
        $button.prop("disabled", false);
        $button.html('<i class="bx bx-download me-1"></i> Generate Grade Sheet');
      },
    });
  });
})(window, window.jQuery);
