<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · HRM Matainja Admin</title>
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v=6">
    @stack('styles')
</head>
<body>
@php
    $icons = [
        'dashboard' => 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z',
        'staff' => 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
        'users' => 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z',
        'roles' => 'M12.65 10A5.99 5.99 0 0 0 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6a5.99 5.99 0 0 0 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z',
        'org' => 'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z',
        'attendance' => 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
        'approvals' => 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z',
        'settings' => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z',
        'audit' => 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z',
        'clients' => 'M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z',
        'invoices' => 'M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5zM19 19.09H5V4.91h14v14.18zM6 15h12v2H6zm0-4h12v2H6zm0-4h12v2H6z',
        'payroll' => 'M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-9-1c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm13-6v11c0 1.1-.9 2-2 2H4v-2h17V7h2z',
        'console' => 'M20 4H4c-1.11 0-2 .9-2 2v12c0 1.1.89 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.89-2-2-2zm0 14H4V8h16v10zm-2-1h-6v-2h6v2zM7.5 17l-1.41-1.41L8.67 13l-2.59-2.59L7.5 9l4 4-4 4z',
        'telescope' =>'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
    ];
    // Clients share the organisation screens, so tell the two sidebar links apart by {type}.
    $onClients = request()->routeIs('admin.org.*') && request()->route('type') === 'clients';
    $isActive = fn (array $item) => match ($item[2]) {
        'clients' => $onClients,
        'org' => request()->routeIs('admin.org.*') && ! $onClients,
        default => request()->routeIs($item[3]),
    };
    $nav = [
        'Overview' => [
            ['admin.dashboard', 'Dashboard', 'dashboard', 'admin.dashboard'],
        ],
        'People' => [
            ['admin.staff.index', 'Employees', 'staff', 'admin.staff.*'],
            ['admin.users.index', 'Users & logins', 'users', 'admin.users.*'],
            ['admin.roles.index', 'Roles & permissions', 'roles', 'admin.roles.*'],
        ],
        'Operations' => [
            ['admin.attendance.index', 'Attendance', 'attendance', 'admin.attendance.*'],
            ['admin.approvals.index', 'Approvals', 'approvals', 'admin.approvals.*'],
            ['admin.payroll.index', 'Payroll', 'payroll', 'admin.payroll.*'],
            ['admin.org.index', 'Clients', 'clients', 'admin.org.*', ['type' => 'clients']],
            ['admin.invoices.index', 'Invoices', 'invoices', 'admin.invoices.*'],
        ],
        'Setup' => [
            ['admin.org.index', 'Organisation', 'org', 'admin.org.*', ['type' => 'branches']],
            ['admin.settings.index', 'Settings', 'settings', 'admin.settings.*'],
            ['admin.audit.index', 'Audit log', 'audit', 'admin.audit.*'],
            ['admin.console.index', 'Command runner', 'console', 'admin.console.*'],
        ],
    ];
@endphp
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            @include('admin.partials.brand-mark')
            <span>HRM Matainja<small>Super Admin console</small></span>
        </div>
        <nav class="nav">
            @foreach ($nav as $group => $items)
                <div class="nav-label">{{ $group }}</div>
                @foreach ($items as $item)
                    <a href="{{ route($item[0], $item[4] ?? []) }}" class="{{ $isActive($item) ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="{{ $icons[$item[2]] }}"/></svg>
                        {{ $item[1] }}
                        @if ($item[2] === 'approvals' && ($pendingApprovals ?? 0) > 0)
                            <span class="badge">{{ $pendingApprovals }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
            {{-- Telescope is a dev-only package: the link shows only where it is installed and registered. --}}
            @if (Route::has('telescope'))
                <a href="{{ route('telescope') }}" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="{{ $icons['telescope'] }}"/></svg>
                    Site Health
                </a>
            @endif
        </nav>
        <div class="sidebar-foot">
            <div class="who">{{ auth()->user()->name }} <span style="font-size: 11px; font-weight: normal; opacity: .6;">#{{ config('github.pull_number') }}</span></div>
            <div>{{ auth()->user()->email }}</div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="btn sm" type="submit">Sign out</button>
            </form>        </div>
    </aside>
    <div class="scrim" onclick="document.body.classList.remove('nav-open')"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" aria-label="Open menu" onclick="document.body.classList.add('nav-open')">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            <strong>HRM Matainja Admin</strong>
        </header>

        <main class="content">
            @include('admin.partials.flash')
            @yield('content')
        </main>
    </div>
</div>

<dialog id="decision-dialog">
    <form method="POST" class="card-body" id="decision-form">
        @csrf
        <h2 id="decision-title">Reject request</h2>
        <p class="muted" id="decision-text" style="margin:6px 0 14px"></p>
        <div class="field">
            <label for="decision-comment" id="decision-label">Reason</label>
            <textarea id="decision-comment" name="comment" maxlength="1000"></textarea>
        </div>
        <div class="actions" style="justify-content:flex-end">
            <button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
            <button type="submit" class="btn primary" id="decision-submit">Confirm</button>
        </div>
    </form>
</dialog>

<script>
    // Confirm before destructive actions: <form data-confirm="...">
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    });

    // Generic pop-ups: <button data-open-dialog="id">, <button data-close-dialog>, <dialog data-open-on-load>.
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-open-dialog]');
        if (opener) {
            var dlg = document.getElementById(opener.getAttribute('data-open-dialog'));
            dlg.showModal();
            var first = dlg.querySelector('input:not([type=hidden]), select, textarea');
            if (first) first.focus();
            return;
        }
        var closer = e.target.closest('[data-close-dialog]');
        if (closer) { closer.closest('dialog').close(); return; }
        // Click on the dark backdrop closes the pop-up.
        if (e.target.tagName === 'DIALOG') { e.target.close(); }
    });
    document.querySelectorAll('dialog[data-open-on-load]').forEach(function (dlg) { dlg.showModal(); });

    // Approve / reject with an optional (approve) or required (reject) comment.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-decision]');
        if (!btn) return;
        e.preventDefault();
        var reject = btn.getAttribute('data-decision') === 'reject';
        var dlg = document.getElementById('decision-dialog');
        var form = document.getElementById('decision-form');
        var comment = document.getElementById('decision-comment');
        form.action = btn.getAttribute('data-action');
        document.getElementById('decision-title').textContent = reject ? 'Reject request' : 'Approve request';
        document.getElementById('decision-text').textContent = btn.getAttribute('data-summary') || '';
        document.getElementById('decision-label').textContent = reject ? 'Reason (required, shown to the employee)' : 'Comment (optional)';
        var submit = document.getElementById('decision-submit');
        submit.textContent = reject ? 'Reject' : 'Approve';
        submit.className = 'btn ' + (reject ? 'danger' : 'primary');
        comment.required = reject;
        comment.value = '';
        dlg.showModal();
        comment.focus();
    });
</script>
@stack('scripts')
</body>
</html>
