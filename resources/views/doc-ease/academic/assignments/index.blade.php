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
  <title>Doc-Ease Teacher Assignments (Laravel)</title>

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
      <h4 class="mb-0">Doc-Ease Teacher Assignments (Laravel)</h4>
      <div class="d-flex gap-2">
        <a href="{{ route('doc-ease.portal') }}" class="btn btn-outline-secondary btn-sm">Portal</a>
        <a href="{{ route('doc-ease.academic.subjects.index') }}" class="btn btn-outline-primary btn-sm">Subjects</a>
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
            <h6 class="mb-0">Assign Teacher</h6>
          </div>
          <div class="card-body">
            <form method="POST" action="{{ route('doc-ease.academic.assignments.store') }}">
              @csrf

              <div class="mb-2">
                <label class="form-label">Teacher</label>
                <select class="form-select" name="teacher_id" required>
                  <option value="">Select teacher</option>
                  @foreach ($teachers as $teacher)
                    <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === (int) $teacher->id)>
                      {{ $teacher->username }}{{ $teacher->useremail ? ' (' . $teacher->useremail . ')' : '' }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="mb-2">
                <label class="form-label">Subject</label>
                <select class="form-select" name="subject_id" required>
                  <option value="">Select subject</option>
                  @foreach ($subjects as $subject)
                    <option value="{{ $subject->id }}" @selected((int) old('subject_id') === (int) $subject->id)>
                      {{ $subject->subject_code }} - {{ $subject->subject_name }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="mb-2">
                <label class="form-label">Academic Year</label>
                <input type="text" class="form-control" name="academic_year" list="academic-years" value="{{ old('academic_year') }}" required maxlength="20">
                <datalist id="academic-years">
                  @foreach ($academicYears as $year)
                    <option value="{{ $year }}"></option>
                  @endforeach
                </datalist>
              </div>

              <div class="mb-2">
                <label class="form-label">Semester</label>
                <input type="text" class="form-control" name="semester" list="semesters" value="{{ old('semester') }}" required maxlength="20">
                <datalist id="semesters">
                  @foreach ($semesters as $semester)
                    <option value="{{ $semester }}"></option>
                  @endforeach
                </datalist>
              </div>

              <div class="mb-2">
                <label class="form-label">Section</label>
                <input type="text" class="form-control text-uppercase" name="section" list="sections" value="{{ old('section') }}" required maxlength="50">
                <datalist id="sections">
                  @foreach ($sections as $section)
                    <option value="{{ $section }}"></option>
                  @endforeach
                </datalist>
              </div>

              <div class="mb-2">
                <label class="form-label">Teacher Role</label>
                <select class="form-select" name="teacher_role" required>
                  <option value="primary" @selected(old('teacher_role', 'primary') === 'primary')>primary</option>
                  <option value="co_teacher" @selected(old('teacher_role') === 'co_teacher')>co_teacher</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="assignment_notes" rows="2">{{ old('assignment_notes') }}</textarea>
              </div>

              <button type="submit" class="btn btn-primary w-100">Assign Teacher</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card">
          <div class="card-header">
            <h6 class="mb-0">Current Assignments</h6>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Teacher</th>
                    <th>Subject</th>
                    <th>Class</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($assignments as $assignment)
                    @php
                      $locked = (int) ($assignment['has_students'] ?? 0) === 1;
                      $isActive = ($assignment['status'] ?? 'inactive') === 'active';
                      $assignmentId = (int) ($assignment['assignment_id'] ?? 0);
                    @endphp
                    <tr>
                      <td>
                        <div>{{ $assignment['teacher_name'] ?? 'N/A' }}</div>
                        <div class="small text-muted">{{ $assignment['teacher_email'] ?? '' }}</div>
                      </td>
                      <td>
                        <div>{{ $assignment['subject_code'] ?? 'N/A' }}</div>
                        <div class="small text-muted">{{ $assignment['subject_name'] ?? '' }}</div>
                      </td>
                      <td>
                        <div>{{ $assignment['section'] ?? 'N/A' }}</div>
                        <div class="small text-muted">{{ $assignment['academic_year'] ?? 'N/A' }} / {{ $assignment['semester'] ?? 'N/A' }}</div>
                      </td>
                      <td>{{ $assignment['teacher_role'] ?? 'primary' }}</td>
                      <td>
                        <span class="badge bg-label-{{ $isActive ? 'success' : 'secondary' }}">{{ $assignment['status'] ?? 'inactive' }}</span>
                        @if ($locked)
                          <div class="small text-warning">Roster locked</div>
                        @endif
                      </td>
                      <td class="text-end">
                        @if ($assignmentId > 0)
                          <form method="POST" action="{{ route('doc-ease.academic.assignments.revoke', ['assignmentId' => $assignmentId]) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger" @disabled(!$isActive || $locked)>
                              Revoke
                            </button>
                          </form>
                        @else
                          <span class="text-muted small">N/A</span>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="6" class="text-center text-muted py-3">No assignments found.</td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
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
