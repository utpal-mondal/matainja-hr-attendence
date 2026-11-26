@extends('portal.layout')
@section('title', 'My profile')

@section('content')
    @php
        $d = fn ($date) => $date ? $date->format('d M Y') : '—';
        $statusBadge = ['active' => 'ok', 'probation' => 'info', 'notice' => 'warn'];
    @endphp

    <div class="page-head">
        <div>
            <h1>My profile</h1>
            <p>Your details as recorded by HR. Ask HR if anything here is wrong.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Personal & job details</h2>
                <span class="badge {{ $statusBadge[$staff->employment_status] ?? '' }}">{{ ucfirst($staff->employment_status ?? 'active') }}</span>
            </div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Name</dt><dd>{{ $staff->full_name }}</dd>
                    <dt>Employee code</dt><dd>{{ $staff->employee_code ?: '—' }}</dd>
                    <dt>Email</dt><dd>{{ $staff->email ?: $user->email }}</dd>
                    <dt>Phone</dt><dd>{{ $staff->phone ?: '—' }}</dd>
                    <dt>Department</dt><dd>{{ $staff->department?->name ?? '—' }}</dd>
                    <dt>Designation</dt><dd>{{ $staff->designation?->title ?? ($staff->position ?: '—') }}</dd>
                    <dt>Team</dt><dd>{{ $staff->team?->name ?? '—' }}</dd>
                    <dt>Reports to</dt><dd>{{ $staff->manager?->full_name ?? '—' }}</dd>
                    <dt>Branch</dt><dd>{{ $staff->branch?->name ?? '—' }}</dd>
                    <dt>Joining date</dt><dd>{{ $d($staff->joining_date) }}</dd>
                    <dt>Shift</dt><dd>
                        @if ($shift)
                            {{ $shift->name }} · {{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('h:i A') }} – {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('h:i A') }}
                        @else
                            —
                        @endif
                    </dd>
                    <dt>Roles</dt><dd>{{ $roles->implode(', ') ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>Contact number</h2></div>
                <form method="POST" action="{{ route('portal.profile.update') }}" class="card-body">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $staff->phone) }}"
                               class="{{ $errors->profile->has('phone') ? 'is-invalid' : '' }}">
                        @error('phone', 'profile')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn primary" type="submit">Save phone</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h2>Change password</h2></div>
                <form method="POST" action="{{ route('portal.profile.password') }}" class="card-body">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="current_password">Current password</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                               class="{{ $errors->password->has('current_password') ? 'is-invalid' : '' }}">
                        @error('current_password', 'password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="password">New password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="{{ $errors->password->has('password') ? 'is-invalid' : '' }}">
                        @error('password', 'password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                        <div class="hint">The mobile app will ask you to sign in again with the new password.</div>
                    </div>
                    <button class="btn primary" type="submit">Change password</button>
                </form>
            </div>
        </div>
    </div>
@endsection
