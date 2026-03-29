<div class="text-body-secondary">
  Showing {{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users
</div>
<div class="d-flex gap-2">
  @if ($users->previousPageUrl())
    <a href="{{ $users->previousPageUrl() }}" class="btn btn-sm btn-outline-secondary">Previous</a>
  @endif
  @if ($users->nextPageUrl())
    <a href="{{ $users->nextPageUrl() }}" class="btn btn-sm btn-outline-secondary">Next</a>
  @endif
</div>
