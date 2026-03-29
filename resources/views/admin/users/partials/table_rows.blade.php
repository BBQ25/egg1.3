@forelse ($users as $managedUser)
  <tr>
    <td class="text-center">
      <input
        type="checkbox"
        class="form-check-input bulk-user-checkbox"
        value="{{ $managedUser->id }}"
        aria-label="Select user {{ $managedUser->username }}" />
    </td>
    <td>{{ $managedUser->id }}</td>
    <td>
      {{ $managedUser->username }}
      @if ($managedUser->id === auth()->id())
        <span class="badge bg-label-secondary ms-1">You</span>
      @endif
    </td>
    <td>{{ $managedUser->full_name }}</td>
    <td>{{ $managedUser->role?->label() }}</td>
    <td>
      @if ($managedUser->isPendingApproval())
        <span class="badge bg-label-warning">Pending</span>
      @elseif ($managedUser->isApproved())
        <span class="admin-row-dusk-badge">
          @include('partials.curated-shell-icon', [
            'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
            'alt' => 'Approved',
            'classes' => 'admin-row-dusk-badge-icon',
          ])
          Approved
        </span>
      @else
        <span class="badge bg-label-danger">Denied</span>
        @if ($managedUser->denial_reason)
          <div class="small text-body-secondary mt-1">{{ $managedUser->denial_reason }}</div>
        @endif
      @endif
    </td>
    <td>
      @if ($managedUser->is_active)
        <span class="admin-row-dusk-badge">
          @include('partials.curated-shell-icon', [
            'src' => 'resources/icons/dusk/hand/animated/icons8-ok-hand--v2.gif',
            'alt' => 'Active',
            'classes' => 'admin-row-dusk-badge-icon',
          ])
          Active
        </span>
      @else
        <span class="badge bg-label-danger">Deactivated</span>
      @endif
    </td>
    <td>
      <div class="admin-row-actions">
        @if ($managedUser->isPendingApproval())
          <form method="POST" action="{{ route('admin.users.approve', $managedUser) }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-sm btn-outline-success admin-row-text-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/check/icons/icons8-ok--v2.png',
                'alt' => 'Approve user',
                'classes' => 'admin-row-text-icon me-1',
              ])
              Approve
            </button>
          </form>

          <form method="POST" action="{{ route('admin.users.deny', $managedUser) }}" class="d-flex gap-1 align-items-center">
            @csrf
            @method('PATCH')
            <input
              type="text"
              name="denial_reason"
              class="form-control form-control-sm"
              placeholder="Deny reason"
              maxlength="500"
              required />
            <button type="submit" class="btn btn-sm btn-outline-danger admin-row-text-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                'alt' => 'Deny user',
                'classes' => 'admin-row-text-icon me-1',
              ])
              Deny
            </button>
          </form>
        @endif

        <a
          href="{{ route('admin.users.edit', $managedUser) }}"
          class="btn btn-sm btn-icon btn-outline-primary admin-row-action-btn"
          aria-label="Edit user {{ $managedUser->username }}"
          data-action-label="Edit">
          @include('partials.curated-shell-icon', [
            'src' => 'resources/icons/dusk/edit/icons/icons8-edit-user-male.png',
            'alt' => 'Edit user',
            'classes' => 'admin-row-action-icon',
          ])
        </a>

        @if ($managedUser->is_active)
          <form method="POST" action="{{ route('admin.users.deactivate', $managedUser) }}">
            @csrf
            @method('PATCH')
            <button
              type="submit"
              class="btn btn-sm btn-icon btn-outline-danger admin-row-action-btn"
              aria-label="Deactivate user {{ $managedUser->username }}"
              data-action-label="Deactivate"
              @disabled($managedUser->id === auth()->id())>
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-remove-user-male.png',
                'alt' => 'Deactivate user',
                'classes' => 'admin-row-action-icon',
              ])
            </button>
          </form>
        @else
          <form method="POST" action="{{ route('admin.users.reactivate', $managedUser) }}">
            @csrf
            @method('PATCH')
            <button
              type="submit"
              class="btn btn-sm btn-icon btn-outline-success admin-row-action-btn"
              aria-label="Reactivate user {{ $managedUser->username }}"
              data-action-label="Reactivate">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/login/icons/icons8-checked-user-male.png',
                'alt' => 'Reactivate user',
                'classes' => 'admin-row-action-icon',
              ])
            </button>
          </form>
        @endif
      </div>
    </td>
  </tr>
@empty
  <tr>
    <td colspan="8" class="text-center text-body-secondary py-5">No users found.</td>
  </tr>
@endforelse

<style>
  .admin-row-action-icon {
    width: 1rem;
    height: 1rem;
    display: inline-block;
  }

  .admin-row-text-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .admin-row-text-icon {
    width: 0.95rem;
    height: 0.95rem;
    display: inline-block;
    flex-shrink: 0;
  }

  .admin-row-dusk-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
    color: #566a7f;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
  }

  .admin-row-dusk-badge-icon {
    width: 0.95rem;
    height: 0.95rem;
    display: inline-block;
    flex-shrink: 0;
  }
</style>
