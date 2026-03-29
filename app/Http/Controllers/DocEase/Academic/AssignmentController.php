<?php

namespace App\Http\Controllers\DocEase\Academic;

use App\Domain\DocEase\DocEaseAcademicService;
use App\Http\Controllers\Controller;
use App\Models\DocEaseUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class AssignmentController extends Controller
{
    public function __construct(private readonly DocEaseAcademicService $academicService)
    {
    }

    public function index(): View
    {
        return view('doc-ease.academic.assignments.index', [
            'teachers' => $this->academicService->activeTeachers(),
            'subjects' => $this->academicService->activeSubjects(),
            'academicYears' => $this->academicService->academicYearOptions(),
            'semesters' => $this->academicService->semesterOptions(),
            'sections' => $this->academicService->sectionOptions(),
            'assignments' => $this->academicService->assignments(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:20'],
            'section' => ['required', 'string', 'max:50'],
            'teacher_role' => ['required', Rule::in(['primary', 'co_teacher'])],
            'assignment_notes' => ['nullable', 'string'],
        ]);

        /** @var DocEaseUser|null $actor */
        $actor = Auth::guard('doc_ease')->user();
        if (!$actor) {
            abort(401);
        }

        try {
            $this->academicService->assignTeacher($actor, [
                'teacher_id' => (int) $data['teacher_id'],
                'subject_id' => (int) $data['subject_id'],
                'academic_year' => trim((string) $data['academic_year']),
                'semester' => trim((string) $data['semester']),
                'section' => trim((string) $data['section']),
                'teacher_role' => trim((string) $data['teacher_role']),
                'assignment_notes' => trim((string) ($data['assignment_notes'] ?? '')),
            ]);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('doc-ease.academic.assignments.index')
                ->with('status_message', $e->getMessage())
                ->with('status_type', 'warning')
                ->withInput();
        }

        return redirect()
            ->route('doc-ease.academic.assignments.index')
            ->with('status_message', 'Teacher assigned successfully.')
            ->with('status_type', 'success');
    }

    public function revoke(Request $request, int $assignmentId): RedirectResponse
    {
        /** @var DocEaseUser|null $actor */
        $actor = Auth::guard('doc_ease')->user();
        if (!$actor) {
            abort(401);
        }

        try {
            $this->academicService->revokeAssignment($actor, $assignmentId);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('doc-ease.academic.assignments.index')
                ->with('status_message', $e->getMessage())
                ->with('status_type', 'warning');
        }

        return redirect()
            ->route('doc-ease.academic.assignments.index')
            ->with('status_message', 'Assignment revoked.')
            ->with('status_type', 'success');
    }
}

