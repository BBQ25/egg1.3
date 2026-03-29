@extends('layouts.admin')

@section('title', 'APEWSD - User Management')

@section('content')
  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">User Management</h4>
      <p class="mb-6">List, edit, deactivate, reactivate, and apply bulk user actions.</p>
    </div>
  </div>

  @if (session('status'))
    <div class="alert alert-success" role="alert">
      {{ session('status') }}
    </div>
  @endif

  @if (session('error'))
    <div class="alert alert-danger" role="alert">
      {{ session('error') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger" role="alert">
      {{ $errors->first() }}
    </div>
  @endif

  <div class="card">
    <div class="card-header py-3">
      <div class="row g-3 align-items-center">
        <div class="col-12 col-xl-7">
          <form id="bulk-users-form" method="POST" action="{{ route('admin.users.bulk') }}" class="row g-2 align-items-center mb-0 users-bulk-form">
            @csrf
            <input type="hidden" name="return_q" value="{{ $search }}" />
            <input type="hidden" name="current_password" id="bulk-current-password" value="" />
            <div id="bulk-user-ids"></div>

            <div class="col-12 col-md-5 col-lg-4">
              <label class="visually-hidden" for="bulk_action">Bulk Action</label>
              <select id="bulk_action" name="bulk_action" class="form-select users-toolbar-control" required>
                <option value="">Select action</option>
                <option value="deactivate" @selected(old('bulk_action') === 'deactivate')>Deactivate selected</option>
                <option value="reactivate" @selected(old('bulk_action') === 'reactivate')>Reactivate selected</option>
                <option value="change_role" @selected(old('bulk_action') === 'change_role')>Change role</option>
              </select>
            </div>

            <div class="col-12 col-md-4 col-lg-4" id="bulk-role-wrap">
              <label class="visually-hidden" for="bulk-role">New Role</label>
              <select id="bulk-role" name="role" class="form-select users-toolbar-control">
                <option value="">Select role</option>
                @foreach ($roleOptions as $roleValue => $roleLabel)
                  <option value="{{ $roleValue }}" @selected(old('role') === $roleValue)>{{ $roleLabel }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-12 col-md-3 col-lg-4">
              <button type="submit" class="btn btn-primary w-100 users-toolbar-control users-toolbar-button text-nowrap">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/check/icons/icons8-ok--v2.png',
                  'alt' => 'Apply to selected',
                  'classes' => 'users-button-icon me-1',
                ])
                Apply to Selected
              </button>
            </div>
          </form>
        </div>

        <div class="col-12 col-xl-5">
          <div class="d-flex gap-2 justify-content-xl-end align-items-center flex-wrap flex-md-nowrap users-toolbar-actions">
            <form id="users-search-form" method="GET" action="{{ route('admin.users.index') }}" class="d-flex gap-2 flex-grow-1 mb-0 users-search-form">
              <input type="text" name="q" class="form-control users-toolbar-control" placeholder="Search username or full name" value="{{ $search }}" />
              <button type="submit" class="btn btn-primary users-toolbar-control users-toolbar-button text-nowrap">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/find/icons/icons8-search--v2.png',
                  'alt' => 'Search',
                  'classes' => 'users-button-icon me-1',
                ])
                Search
              </button>
            </form>
            <button type="button" class="btn btn-primary users-toolbar-control users-toolbar-button text-nowrap" data-bs-toggle="modal" data-bs-target="#addUserModal">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/add/icons/icons8-add-user-male.png',
                'alt' => 'Add user',
                'classes' => 'users-button-icon me-1',
              ])
              Add User
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th class="text-center">
                <input type="checkbox" id="select-all-users" class="form-check-input" />
              </th>
              <th>ID</th>
              <th>Username</th>
              <th>Full Name</th>
              <th>Role</th>
              <th>Registration</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="users-table-body">
            @include('admin.users.partials.table_rows', ['users' => $users])
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2 users-table-footer" id="users-table-footer">
      @include('admin.users.partials.table_footer', ['users' => $users])
    </div>
  </div>

  <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addUserModalLabel">Register New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="{{ route('admin.users.store') }}" method="POST">
          @csrf
          <div class="modal-body">
            <div class="row g-4">
              <div class="col-12">
                <label class="form-label" for="create_full_name">Full Name</label>
                <input
                  type="text"
                  id="create_full_name"
                  name="full_name"
                  class="form-control"
                  value="{{ old('full_name') }}"
                  maxlength="120"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_username">Username</label>
                <input
                  type="text"
                  id="create_username"
                  name="username"
                  class="form-control"
                  value="{{ old('username') }}"
                  maxlength="60"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_role">Role</label>
                <select id="create_role" name="role" class="form-select" required>
                  @foreach ($roleOptions as $roleValue => $roleLabel)
                    <option value="{{ $roleValue }}" @selected(old('role', 'CUSTOMER') === $roleValue)>{{ $roleLabel }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_password">Password</label>
                <input
                  type="password"
                  id="create_password"
                  name="password"
                  class="form-control"
                  minlength="8"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_password_confirmation">Confirm Password</label>
                <input
                  type="password"
                  id="create_password_confirmation"
                  name="password_confirmation"
                  class="form-control"
                  minlength="8"
                  required />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary users-toolbar-button" data-bs-dismiss="modal">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                'alt' => 'Cancel',
                'classes' => 'users-button-icon me-1',
              ])
              Cancel
            </button>
            <button type="submit" class="btn btn-primary users-toolbar-button">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/add/icons/icons8-add-user-male.png',
                'alt' => 'Create user',
                'classes' => 'users-button-icon me-1',
              ])
              Create User
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="bulkAuthModal" tabindex="-1" aria-labelledby="bulkAuthModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="bulkAuthModalLabel">Confirm Bulk Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0 text-body-secondary" id="bulk-auth-message">Select at least one user to continue.</p>
          <div class="mt-3 d-none" id="bulk-auth-password-wrap">
            <label class="form-label" for="bulk-auth-password">Admin Password</label>
            <input type="password" id="bulk-auth-password" class="form-control" autocomplete="current-password" />
            <div class="invalid-feedback" id="bulk-auth-password-error">Admin password is required.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary users-toolbar-button" data-bs-dismiss="modal" id="bulk-auth-close-btn">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/check/icons/icons8-ok--v2.png',
              'alt' => 'OK',
              'classes' => 'users-button-icon me-1',
            ])
            OK
          </button>
          <button type="button" class="btn btn-primary d-none users-toolbar-button" id="bulk-auth-confirm-btn">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/admin/icons/icons8-user-shield.png',
              'alt' => 'Authenticate and apply',
              'classes' => 'users-button-icon me-1',
            ])
            Authenticate &amp; Apply
          </button>
        </div>
      </div>
    </div>
  </div>

  <style>
    .users-toolbar-control {
      height: 42px;
    }

    .users-toolbar-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .users-button-icon {
      width: 1rem;
      height: 1rem;
      flex-shrink: 0;
    }

    @media (max-width: 767.98px) {
      .users-toolbar-actions,
      .users-search-form {
        width: 100%;
      }

      .users-toolbar-actions {
        flex-direction: column;
        align-items: stretch !important;
      }

      .users-search-form {
        flex-direction: column;
      }

      .users-search-form > *,
      .users-toolbar-actions > *,
      .users-table-footer > * {
        width: 100%;
      }
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const shouldOpenCreateModal = @json(
        ($openModal ?? false)
          || session()->hasOldInput('full_name')
          || session()->hasOldInput('username')
      );
      const addUserModalElement = document.getElementById('addUserModal');
      if (shouldOpenCreateModal && addUserModalElement && typeof window.bootstrap !== 'undefined') {
        const addUserModal = new window.bootstrap.Modal(addUserModalElement);
        addUserModal.show();
      }

      const bulkForm = document.getElementById('bulk-users-form');
      const selectAll = document.getElementById('select-all-users');
      const actionSelect = document.getElementById('bulk_action');
      const roleWrap = document.getElementById('bulk-role-wrap');
      const roleSelect = document.getElementById('bulk-role');
      const hiddenIdsContainer = document.getElementById('bulk-user-ids');
      const returnQueryInput = bulkForm ? bulkForm.querySelector('input[name="return_q"]') : null;
      const bulkCurrentPasswordInput = document.getElementById('bulk-current-password');
      const bulkAuthModalElement = document.getElementById('bulkAuthModal');
      const bulkAuthModalLabel = document.getElementById('bulkAuthModalLabel');
      const bulkAuthMessage = document.getElementById('bulk-auth-message');
      const bulkAuthPasswordWrap = document.getElementById('bulk-auth-password-wrap');
      const bulkAuthPasswordInput = document.getElementById('bulk-auth-password');
      const bulkAuthPasswordError = document.getElementById('bulk-auth-password-error');
      const bulkAuthCloseBtn = document.getElementById('bulk-auth-close-btn');
      const bulkAuthConfirmBtn = document.getElementById('bulk-auth-confirm-btn');
      const bulkAuthModal = bulkAuthModalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(bulkAuthModalElement)
        : null;
      const searchForm = document.getElementById('users-search-form');
      const searchInput = searchForm ? searchForm.querySelector('input[name="q"]') : null;
      const tableBody = document.getElementById('users-table-body');
      const tableFooter = document.getElementById('users-table-footer');
      let searchDebounceTimer = null;
      let activeSearchController = null;
      let latestSearchRequestId = 0;
      let allowBulkSubmit = false;

      const getCheckboxes = () => Array.from(document.querySelectorAll('.bulk-user-checkbox'));

      const syncSelectAll = () => {
        const checkboxes = getCheckboxes();
        if (!selectAll) {
          return;
        }

        if (checkboxes.length === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
          return;
        }

        const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        selectAll.checked = selectedCount === checkboxes.length;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
      };

      const syncRoleVisibility = () => {
        if (!actionSelect || !roleWrap || !roleSelect) {
          return;
        }

        const roleRequired = actionSelect.value === 'change_role';
        roleWrap.style.display = roleRequired ? '' : 'none';
        roleSelect.required = roleRequired;
      };

      const renderSelectedUserInputs = (selectedCheckboxes) => {
        if (!hiddenIdsContainer) {
          return;
        }

        hiddenIdsContainer.innerHTML = '';

        selectedCheckboxes.forEach(function (checkbox) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'user_ids[]';
          input.value = checkbox.value;
          hiddenIdsContainer.appendChild(input);
        });
      };

      const currentActionLabel = () => {
        if (!actionSelect || actionSelect.selectedIndex < 0) {
          return 'apply this bulk action';
        }

        const selectedOption = actionSelect.options[actionSelect.selectedIndex];
        return selectedOption?.textContent?.trim().toLowerCase() || 'apply this bulk action';
      };

      const setBulkPasswordFieldState = (errorMessage = '') => {
        if (!bulkAuthPasswordInput || !bulkAuthPasswordError) {
          return;
        }

        const hasError = errorMessage.trim() !== '';
        bulkAuthPasswordInput.classList.toggle('is-invalid', hasError);
        bulkAuthPasswordError.textContent = hasError ? errorMessage : 'Admin password is required.';
      };

      const showBulkModal = ({ title, message, requirePassword }) => {
        if (!bulkAuthModal) {
          return;
        }

        if (bulkAuthModalLabel) {
          bulkAuthModalLabel.textContent = title;
        }
        if (bulkAuthMessage) {
          bulkAuthMessage.textContent = message;
        }

        if (bulkAuthPasswordWrap) {
          bulkAuthPasswordWrap.classList.toggle('d-none', !requirePassword);
        }
        if (bulkAuthConfirmBtn) {
          bulkAuthConfirmBtn.classList.toggle('d-none', !requirePassword);
        }
        if (bulkAuthCloseBtn) {
          bulkAuthCloseBtn.textContent = requirePassword ? 'Cancel' : 'OK';
        }

        if (bulkAuthPasswordInput) {
          bulkAuthPasswordInput.value = '';
        }
        setBulkPasswordFieldState('');
        bulkAuthModal.show();
      };

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          getCheckboxes().forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
          });
          syncSelectAll();
        });
      }

      document.addEventListener('change', function (event) {
        if (event.target instanceof HTMLInputElement && event.target.classList.contains('bulk-user-checkbox')) {
          syncSelectAll();
        }
      });

      if (actionSelect) {
        actionSelect.addEventListener('change', syncRoleVisibility);
      }

      const clearBulkSelection = () => {
        if (hiddenIdsContainer) {
          hiddenIdsContainer.innerHTML = '';
        }
        if (bulkCurrentPasswordInput) {
          bulkCurrentPasswordInput.value = '';
        }
        if (selectAll) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
        }
        allowBulkSubmit = false;
      };

      const buildSearchUrl = (queryValue = '', page = '') => {
        if (!searchForm) {
          return null;
        }

        const url = new URL(searchForm.action, window.location.origin);
        const normalizedQuery = queryValue.trim();
        const normalizedPage = String(page).trim();

        if (normalizedQuery === '') {
          url.searchParams.delete('q');
        } else {
          url.searchParams.set('q', normalizedQuery);
        }

        if (normalizedPage === '') {
          url.searchParams.delete('page');
        } else {
          url.searchParams.set('page', normalizedPage);
        }

        return url;
      };

      const updateAddressBar = (url) => {
        const browserUrl = new URL(url.toString());
        browserUrl.searchParams.delete('ajax');
        if (browserUrl.searchParams.get('q') === '') {
          browserUrl.searchParams.delete('q');
        }
        window.history.replaceState({}, '', `${browserUrl.pathname}${browserUrl.search}${browserUrl.hash}`);
      };

      const runLiveSearch = async (url, fallbackOnFailure = false) => {
        if (!tableBody || !tableFooter || !url) {
          return;
        }

        latestSearchRequestId += 1;
        const currentRequestId = latestSearchRequestId;

        if (activeSearchController) {
          activeSearchController.abort();
        }

        activeSearchController = new AbortController();
        const ajaxUrl = new URL(url.toString());
        ajaxUrl.searchParams.set('ajax', '1');
        tableBody.classList.add('opacity-50');

        try {
          const response = await fetch(ajaxUrl.toString(), {
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            signal: activeSearchController.signal,
          });

          if (!response.ok) {
            throw new Error(`Live search request failed with status ${response.status}`);
          }

          const payload = await response.json();

          if (currentRequestId !== latestSearchRequestId) {
            return;
          }

          if (typeof payload.table_rows_html !== 'string' || typeof payload.table_footer_html !== 'string') {
            throw new Error('Live search response is missing expected HTML payload.');
          }

          tableBody.innerHTML = payload.table_rows_html;
          tableFooter.innerHTML = payload.table_footer_html;
          clearBulkSelection();
          if (returnQueryInput) {
            returnQueryInput.value = ajaxUrl.searchParams.get('q') ?? '';
          }
          syncSelectAll();
          syncRoleVisibility();
          updateAddressBar(ajaxUrl);
        } catch (error) {
          if (!(error instanceof DOMException && error.name === 'AbortError')) {
            window.console.error('Live user search failed.', error);
            if (fallbackOnFailure) {
              window.location.assign(url.toString());
            }
          }
        } finally {
          if (currentRequestId === latestSearchRequestId) {
            tableBody.classList.remove('opacity-50');
          }
        }
      };

      if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
          if (!hiddenIdsContainer || !actionSelect) {
            return;
          }

          if (allowBulkSubmit) {
            allowBulkSubmit = false;
            return;
          }

          event.preventDefault();

          if (bulkCurrentPasswordInput) {
            bulkCurrentPasswordInput.value = '';
          }
          setBulkPasswordFieldState('');

          if (!bulkForm.checkValidity()) {
            bulkForm.reportValidity();
            return;
          }

          const selected = getCheckboxes().filter((checkbox) => checkbox.checked);

          if (selected.length === 0) {
            showBulkModal({
              title: 'No Users Selected',
              message: 'Select at least one user.',
              requirePassword: false,
            });
            return;
          }

          renderSelectedUserInputs(selected);
          showBulkModal({
            title: 'Authenticate Bulk Action',
            message: `You are about to ${currentActionLabel()} for ${selected.length} user(s). Enter your admin password to continue.`,
            requirePassword: true,
          });
        });
      }

      if (bulkAuthConfirmBtn && bulkForm) {
        bulkAuthConfirmBtn.addEventListener('click', function () {
          if (!bulkAuthPasswordInput || !bulkCurrentPasswordInput) {
            return;
          }

          const enteredPassword = bulkAuthPasswordInput.value.trim();
          if (enteredPassword === '') {
            setBulkPasswordFieldState('Admin password is required.');
            bulkAuthPasswordInput.focus();
            return;
          }

          setBulkPasswordFieldState('');
          bulkCurrentPasswordInput.value = enteredPassword;
          allowBulkSubmit = true;
          if (bulkAuthModal) {
            bulkAuthModal.hide();
          }
          bulkForm.requestSubmit();
        });
      }

      if (bulkAuthModalElement && bulkAuthPasswordInput) {
        bulkAuthModalElement.addEventListener('shown.bs.modal', function () {
          const shouldFocusPassword = bulkAuthPasswordWrap && !bulkAuthPasswordWrap.classList.contains('d-none');
          if (shouldFocusPassword) {
            bulkAuthPasswordInput.focus();
          }
        });

        bulkAuthModalElement.addEventListener('hidden.bs.modal', function () {
          if (bulkAuthPasswordInput) {
            bulkAuthPasswordInput.value = '';
          }
          setBulkPasswordFieldState('');
          if (bulkCurrentPasswordInput) {
            bulkCurrentPasswordInput.value = '';
          }
        });

        bulkAuthPasswordInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') {
            event.preventDefault();
            if (bulkAuthConfirmBtn && !bulkAuthConfirmBtn.classList.contains('d-none')) {
              bulkAuthConfirmBtn.click();
            }
          }
        });
      }

      if (searchForm && searchInput) {
        searchForm.addEventListener('submit', function (event) {
          event.preventDefault();
          if (searchDebounceTimer) {
            window.clearTimeout(searchDebounceTimer);
          }
          runLiveSearch(buildSearchUrl(searchInput.value), true);
        });

        searchInput.addEventListener('input', function () {
          if (searchDebounceTimer) {
            window.clearTimeout(searchDebounceTimer);
          }

          searchDebounceTimer = window.setTimeout(function () {
            runLiveSearch(buildSearchUrl(searchInput.value));
          }, 280);
        });
      }

      if (tableFooter && searchInput) {
        tableFooter.addEventListener('click', function (event) {
          const anchor = event.target instanceof HTMLElement ? event.target.closest('a[href]') : null;
          if (!(anchor instanceof HTMLAnchorElement)) {
            return;
          }

          event.preventDefault();

          const targetUrl = new URL(anchor.href, window.location.origin);
          searchInput.value = targetUrl.searchParams.get('q') ?? '';
          runLiveSearch(targetUrl, true);
        });
      }

      syncSelectAll();
      syncRoleVisibility();
    });
  </script>
@endsection
