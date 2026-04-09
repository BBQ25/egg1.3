@extends('layouts.admin')

@section('title', 'APEWSD - Admin User Registration')

@section('content')
  <style>
    .user-register-shell .card {
      border: 1px solid rgba(67, 89, 113, 0.13);
      box-shadow: 0 0.9rem 1.9rem rgba(67, 89, 113, 0.08);
    }

    .user-register-headline {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .user-table-wrap {
      border-top: 1px solid rgba(67, 89, 113, 0.12);
    }

    .user-table {
      margin-bottom: 0;
    }

    .user-table thead th {
      text-transform: uppercase;
      font-size: 0.74rem;
      letter-spacing: 0.045em;
      color: #6b7b93;
      border-bottom: 1px solid rgba(67, 89, 113, 0.13);
      white-space: nowrap;
    }

    .user-table tbody tr {
      transition: background-color 150ms ease;
    }

    .user-table tbody tr:hover {
      background-color: rgba(105, 108, 255, 0.04);
    }

    .user-table tbody td {
      border-bottom: 1px solid rgba(67, 89, 113, 0.1);
      vertical-align: middle;
    }

    .user-id-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2rem;
      height: 2rem;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 600;
      color: #566a7f;
      background: rgba(67, 89, 113, 0.1);
    }

    .user-role-badge {
      border: 1px solid transparent;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 600;
      padding: 0.33rem 0.6rem;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .user-role-badge.is-admin {
      background: rgba(105, 108, 255, 0.14);
      color: #4c50d8;
      border-color: rgba(105, 108, 255, 0.25);
    }

    .user-role-badge.is-owner {
      background: rgba(40, 199, 111, 0.14);
      color: #1f8f53;
      border-color: rgba(40, 199, 111, 0.24);
    }

    .user-role-badge.is-staff {
      background: rgba(3, 195, 236, 0.14);
      color: #08758f;
      border-color: rgba(3, 195, 236, 0.25);
    }

    .user-role-badge.is-customer {
      background: rgba(255, 171, 0, 0.16);
      color: #956200;
      border-color: rgba(255, 171, 0, 0.25);
    }

    .user-modal .modal-content {
      border: 1px solid rgba(67, 89, 113, 0.14);
      box-shadow: 0 1rem 2rem rgba(67, 89, 113, 0.12);
    }
  </style>

  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">Admin User Registration</h4>
      <p class="mb-6">Create user accounts for Admin, Poultry Owner, Poultry Staff, and Customer.</p>
    </div>
  </div>

  @if (session('status'))
    <div class="alert alert-success" role="alert">
      {{ session('status') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger" role="alert">
      {{ $errors->first() }}
    </div>
  @endif

  <div class="row user-register-shell">
    <div class="col-12">
      <div class="card">
        <div class="card-header py-4">
          <div class="user-register-headline">
            <div>
              <h5 class="card-title mb-1">Recent Users</h5>
              <p class="mb-0 text-body-secondary">Latest registered accounts and roles.</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
              <i class="bx bx-plus me-1"></i> Add User
            </button>
          </div>
        </div>
        <div class="table-responsive user-table-wrap">
          <table class="table user-table">
            <thead>
              <tr>
                <th class="ps-4">#</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Status</th>
                <th class="text-end pe-4">Created</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($recentUsers as $user)
                @php
                  $roleValue = (string) ($user->role?->value ?? '');
                  $roleBadgeClass = match ($roleValue) {
                      'ADMIN' => 'is-admin',
                      'OWNER' => 'is-owner',
                      'WORKER' => 'is-staff',
                      default => 'is-customer',
                  };
                @endphp
                <tr>
                  <td class="ps-4"><span class="user-id-badge">{{ $user->id }}</span></td>
                  <td>
                    <div class="fw-semibold">{{ $user->username }}</div>
                  </td>
                  <td>{{ $user->full_name }}</td>
                  <td>
                    <span class="user-role-badge {{ $roleBadgeClass }}">{{ $user->role?->label() }}</span>
                  </td>
                  <td>
                    @if ($user->is_active)
                      <span class="badge bg-label-success">Active</span>
                    @else
                      <span class="badge bg-label-danger">Inactive</span>
                    @endif
                  </td>
                  <td class="text-end pe-4 text-body-secondary">
                    {{ \App\Support\AppTimezone::formatDateTime($user->created_at, 'M d, Y h:i A') }}
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="6" class="text-center text-body-secondary py-5">No users found.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade user-modal" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
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
                <label class="form-label" for="full_name">Full Name</label>
                <input
                  type="text"
                  id="full_name"
                  name="full_name"
                  class="form-control"
                  value="{{ old('full_name') }}"
                  maxlength="120"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="username">Username</label>
                <input
                  type="text"
                  id="username"
                  name="username"
                  class="form-control"
                  value="{{ old('username') }}"
                  maxlength="60"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="role">Role</label>
                <select id="role" name="role" class="form-select" required>
                  @foreach ($roleOptions as $roleValue => $roleLabel)
                    <option value="{{ $roleValue }}" @selected(old('role', 'CUSTOMER') === $roleValue)>{{ $roleLabel }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" minlength="8" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" required />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create User</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  @if ($errors->any())
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const modalElement = document.getElementById('addUserModal');
        if (!modalElement || typeof window.bootstrap === 'undefined') {
          return;
        }

        const addUserModal = new window.bootstrap.Modal(modalElement);
        addUserModal.show();
      });
    </script>
  @endif
@endsection
