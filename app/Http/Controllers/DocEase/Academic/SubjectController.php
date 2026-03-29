<?php

namespace App\Http\Controllers\DocEase\Academic;

use App\Http\Controllers\Controller;
use App\Models\DocEaseSubject;
use App\Models\DocEaseUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SubjectController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));

        $subjectsQuery = DocEaseSubject::query()->orderByDesc('updated_at')->orderByDesc('id');
        if (in_array($status, ['active', 'inactive'], true)) {
            $subjectsQuery->where('status', $status);
        }

        return view('doc-ease.academic.subjects.index', [
            'subjects' => $subjectsQuery->paginate(30)->withQueryString(),
            'statusFilter' => $status,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:20', 'unique:doc_ease.subjects,subject_code'],
            'subject_name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'course' => ['nullable', 'string', 'max:100'],
            'major' => ['nullable', 'string', 'max:100'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'units' => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'type' => ['required', Rule::in(['Lecture', 'Laboratory', 'Lec&Lab'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        /** @var DocEaseUser|null $actor */
        $actor = Auth::guard('doc_ease')->user();

        $subject = new DocEaseSubject($data);
        $subject->created_by = (int) ($actor?->id ?? 1);
        $subject->save();

        return redirect()
            ->route('doc-ease.academic.subjects.index')
            ->with('status_message', 'Subject added successfully.')
            ->with('status_type', 'success');
    }

    public function edit(DocEaseSubject $subject): View
    {
        return view('doc-ease.academic.subjects.edit', [
            'subject' => $subject,
        ]);
    }

    public function update(Request $request, DocEaseSubject $subject): RedirectResponse
    {
        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:20', 'unique:doc_ease.subjects,subject_code,' . (int) $subject->id],
            'subject_name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'course' => ['nullable', 'string', 'max:100'],
            'major' => ['nullable', 'string', 'max:100'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'units' => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'type' => ['required', Rule::in(['Lecture', 'Laboratory', 'Lec&Lab'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $subject->fill($data);
        $subject->save();

        return redirect()
            ->route('doc-ease.academic.subjects.index')
            ->with('status_message', 'Subject updated successfully.')
            ->with('status_type', 'success');
    }

    public function toggleStatus(Request $request, DocEaseSubject $subject): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $subject->status = (string) $data['status'];
        $subject->save();

        return redirect()
            ->route('doc-ease.academic.subjects.index')
            ->with('status_message', 'Subject status updated.')
            ->with('status_type', 'success');
    }

    public function destroy(DocEaseSubject $subject): RedirectResponse
    {
        try {
            $subject->delete();
        } catch (Throwable) {
            return redirect()
                ->route('doc-ease.academic.subjects.index')
                ->with('status_message', 'Unable to delete subject. It may still be referenced by class records.')
                ->with('status_type', 'danger');
        }

        return redirect()
            ->route('doc-ease.academic.subjects.index')
            ->with('status_message', 'Subject deleted successfully.')
            ->with('status_type', 'success');
    }
}

