@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/vendor/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
@endphp

<!doctype html>
<html lang="en" class="layout-wide" dir="ltr" data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/" data-template="vertical-menu-template" data-bs-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Edit Subject (Laravel)</title>

  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
</head>
<body>
  <div class="container-xxl py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">Edit Subject (Laravel)</h4>
      <a href="{{ route('doc-ease.academic.subjects.index') }}" class="btn btn-outline-secondary btn-sm">Back to Subjects</a>
    </div>

    @if ($errors->any())
      <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="card">
      <div class="card-body">
        <form method="POST" action="{{ route('doc-ease.academic.subjects.update', $subject) }}">
          @csrf
          @method('PUT')

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Subject Code</label>
              <input type="text" class="form-control" name="subject_code" value="{{ old('subject_code', $subject->subject_code) }}" required maxlength="20">
            </div>
            <div class="col-md-8">
              <label class="form-label">Subject Name</label>
              <input type="text" class="form-control" name="subject_name" value="{{ old('subject_name', $subject->subject_name) }}" required maxlength="200">
            </div>
            <div class="col-md-3">
              <label class="form-label">Type</label>
              <select class="form-select" name="type" required>
                @foreach (['Lecture', 'Laboratory', 'Lec&Lab'] as $type)
                  <option value="{{ $type }}" @selected(old('type', $subject->type) === $type)>{{ $type }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Units</label>
              <input type="number" step="0.1" min="0" max="99.9" class="form-control" name="units" value="{{ old('units', $subject->units) }}">
            </div>
            <div class="col-md-3">
              <label class="form-label">Academic Year</label>
              <input type="text" class="form-control" name="academic_year" value="{{ old('academic_year', $subject->academic_year) }}" maxlength="50">
            </div>
            <div class="col-md-2">
              <label class="form-label">Semester</label>
              <input type="text" class="form-control" name="semester" value="{{ old('semester', $subject->semester) }}" maxlength="50">
            </div>
            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select class="form-select" name="status" required>
                <option value="active" @selected(old('status', $subject->status) === 'active')>active</option>
                <option value="inactive" @selected(old('status', $subject->status) === 'inactive')>inactive</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Course</label>
              <input type="text" class="form-control" name="course" value="{{ old('course', $subject->course) }}" maxlength="100">
            </div>
            <div class="col-md-4">
              <label class="form-label">Major</label>
              <input type="text" class="form-control" name="major" value="{{ old('major', $subject->major) }}" maxlength="100">
            </div>
            <div class="col-md-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" rows="3">{{ old('description', $subject->description) }}</textarea>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ route('doc-ease.academic.subjects.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Subject</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="{{ $sneatAssetsBase }}/vendor/libs/jquery/jquery.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/popper/popper.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/bootstrap.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/hammer/hammer.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/i18n/i18n.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/menu.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/main.js"></script>
</body>
</html>
