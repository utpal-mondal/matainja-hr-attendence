<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · HRM Matainja</title>
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v=6">
    @stack('styles')
</head>
<body>
@php
    $icons = [
        'dashboard' => 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z',
        'profile' => 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
        'attendance' => 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
        'regularization' => 'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z',
        'leave' => 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z',
        'wfh' => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
        'payslips' => 'M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-9-1c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm13-6v11c0 1.1-.9 2-2 2H4v-2h17V7h2z',
        'holidays' => 'M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13zM12 10l1.55 3.13 3.45.5-2.5 2.44.59 3.43L12 17.88l-3.09 1.62.59-3.43L7 13.63l3.45-.5z',
    ];
    $nav = [
        'Overview' => [
            ['portal.dashboard', 'Dashboard', 'dashboard', 'portal.dashboard'],
            ['portal.profile', 'My profile', 'profile', 'portal.profile*'],
        ],
        'Attendance' => [
            ['portal.attendance', 'My attendance', 'attendance', 'portal.attendance'],
            ['portal.regularization.index', 'Regularization', 'regularization', 'portal.regularization.*'],
        ],
        'Requests' => [
            ['portal.leave.index', 'Leave', 'leave', 'portal.leave.*'],
            ['portal.wfh.index', 'Work from home', 'wfh', 'portal.wfh.*'],
        ],
        'More' => [
            ['portal.payslips.index', 'Payslips', 'payslips', 'portal.payslips.*'],
            ['portal.holidays', 'Holidays', 'holidays', 'portal.holidays'],
        ],
    ];
    $me = auth()->user()->staff;
@endphp
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            @include('admin.partials.brand-mark')
            <span>HRM Matainja<small>Employee portal</small></span>
        </div>
        <nav class="nav">
            @foreach ($nav as $group => $items)
                <div class="nav-label">{{ $group }}</div>
                @foreach ($items as $item)
                    <a href="{{ route($item[0]) }}" class="{{ request()->routeIs($item[3]) ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="{{ $icons[$item[2]] }}"/></svg>
                        {{ $item[1] }}
                    </a>
                @endforeach
            @endforeach
        </nav>
        <div class="sidebar-foot">
            <div class="who">{{ $me?->full_name ?: auth()->user()->name }}</div>
            <div>{{ $me?->employee_code ? $me->employee_code.' · ' : '' }}{{ auth()->user()->email }}</div>
            <form method="POST" action="{{ route('portal.logout') }}">
                @csrf
                <button class="btn sm" type="submit">Sign out</button>
            </form>
        </div>
    </aside>
    <div class="scrim" onclick="document.body.classList.remove('nav-open')"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" aria-label="Open menu" onclick="document.body.classList.add('nav-open')">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            <strong>HRM Matainja</strong>
        </header>

        <main class="content">
            @include('admin.partials.flash')
            @yield('content')
        </main>
    </div>
</div>

<script>
    // Confirm before actions that can't be undone: <form data-confirm="...">
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    });
</script>
@stack('scripts')
</body>
</html>
