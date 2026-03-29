@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/vendor/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
  $flashMessage = session('status_message');
  $flashType = session('status_type', 'success');
  $alertClass = $flashType === 'danger' ? 'danger' : ($flashType === 'warning' ? 'warning' : 'success');
@endphp

<!doctype html>
<html lang="en" class="layout-wide" dir="ltr" data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/" data-template="vertical-menu-template" data-bs-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Doc-Ease Subjects (Laravel)</title>

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
      <h4 class="mb-0">Doc-Ease Subjects (Laravel)</h4>
      <div class="d-flex gap-2">
        <a href="{{ route('doc-ease.portal') }}" class="btn btn-outline-secondary btn-sm">Portal</a>
        <a href="{{ route('doc-ease.academic.assignments.index') }}" class="btn btn-outline-primary btn-sm">Teacher Assignments</a>
      </div>
    </div>

    @if ($flashMessage)
      <div class="alert alert-{{ $alertClass }}" role="alert">{{ $flashMessage }}</div>
    @endif

    @if ($errors->any())
      <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card">
          <div class="card-header">
            <h6 class="mb-0">Add Subject</h6>
          </div>
          <div class="card-body">
            <form method="POST" action="{{ route('doc-ease.academic.subjects.store') }}">
              @csrf
              <div class="mb-2">
                <label class="form-label">Subject Code</label>
                <input type="text" class="form-control" name="subject_code" value="{{ old('subject_code') }}" required maxlength="20">
              </div>
              <div class="mb-2">
                <label class="form-label">Subject Name</label>
                <input type="text" class="form-control" name="subject_name" value="{{ old('subject_name') }}" required maxlength="200">
              </div>
              <div class="mb-2">
                <label class="form-label">Type</label>
                <select class="form-select" name="type" required>
                  @foreach (['Lecture', 'Laboratory', 'Lec&Lab'] as $type)
                    <option value="{{ $type }}" @selected(old('type', 'Lecture') === $type)>{{ $type }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Units</label>
                <input type="number" step="0.1" min="0" max="99.9" class="form-control" name="units" value="{{ old('units', '3.0') }}">
              </div>
              <div class="mb-2">
                <label class="form-label">Academic Year</label>
                <input type="text" class="form-control" name="academic_year" value="{{ old('academic_year') }}" maxlength="50">
              </div>
              <div class="mb-2">
                <label class="form-label">Semester</label>
                <input type="text" class="form-control" name="semester" value="{{ old('semester') }}" maxlength="50">
              </div>
              <div class="mb-2">
                <label class="form-label">Course</label>
                <input type="text" class="form-control" name="course" value="{{ old('course') }}" maxlength="100">
              </div>
              <div class="mb-2">
                <label class="form-label">Major</label>
                <input type="text" class="form-control" name="major" value="{{ old('major') }}" maxlength="100">
              </div>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="2">{{ old('description') }}</textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" required>
                  <option value="active" @selected(old('status', 'active') === 'active')>active</option>
                  <option value="inactive" @selected(old('status') === 'inactive')>inactive</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary w-100">Add Subject</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Subjects</h6>
            <form method="GET" action="{{ route('doc-ease.academic.subjects.index') }}" class="d-flex gap-2">
              <select class="form-select form-select-sm" name="status">
                <option value="" @selected($statusFilter === '')>All</option>
                <option value="active" @selected($statusFilter === 'active')>active</option>
                <option value="inactive" @selected($statusFilter === 'inactive')>inactive</option>
              </select>
              <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
            </form>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>AY / Sem</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($subjects as $subject)
                    <tr>
                      <td>{{ $subject->subject_code }}</td>
                      <td>{{ $subject->subject_name }}</td>
                      <td>{{ $subject->academic_year ?: 'N/A' }} / {{ $subject->semester ?: 'N/A' }}</td>
                      <td>
                        <span class="badge bg-label-{{ $subject->status === 'active' ? 'success' : 'secondary' }}">
                          {{ $subject->status }}
                        </span>
                      </td>
                      <td class="text-end">
                        <div class="d-inline-flex gap-1">
                          <a href="{{ route('doc-ease.academic.subjects.edit', $subject) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                          <form method="POST" action="{{ route('doc-ease.academic.subjects.status', $subject) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $subject->status === 'active' ? 'inactive' : 'active' }}">
                            <button class="btn btn-sm btn-outline-warning" type="submit">
                              {{ $subject->status === 'active' ? 'Disable' : 'Enable' }}
                            </button>
                          </form>
                          <form method="POST" action="{{ route('doc-ease.academic.subjects.destroy', $subject) }}" onsubmit="return confirm('Delete subject {{ $subject->subject_code }}?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="5" class="text-center text-muted py-3">No subjects found.</td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
          <div class="card-footer">
            {{ $subjects->links() }}
          </div>
        </div>
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
