@extends('portal.layout')
@section('title', 'Dashboard')

@section('content')
    @php
        $statusBadge = ['present' => 'ok', 'late' => 'warn', 'wfh' => 'wfh', 'half_day' => 'info', 'absent' => 'bad'];
        $statusLabel = fn (?string $s) => $s ? ucwords(str_replace('_', ' ', $s === 'wfh' ? 'Work from home' : $s)) : '—';
        $time = fn ($t) => $t ? $t->copy()->setTimezone($tz)->format('h:i A') : '—';
        $hours = fn (int $m) => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
        $days = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
        $a = $todayAttendance;
        $workedSoFar = $a?->check_in_at ? ($a->check_out_at ? (int) $a->worked_minutes : (int) $a->check_in_at->diffInMinutes(now())) : 0;
        $present = ($monthCounts['present'] ?? 0) + ($monthCounts['late'] ?? 0) + ($monthCounts['wfh'] ?? 0);
    @endphp

    <div class="page-head">
        <div>
            <h1>Hello, {{ $staff->first_name ?: $staff->full_name }}</h1>
            <p>{{ $today->format('l, d F Y') }}</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('portal.attendance') }}">My attendance</a>
            <a class="btn primary" href="{{ route('portal.leave.index') }}#apply">Apply for leave</a>
        </div>
    </div>

    <div class="alert info">Check-in and check-out are done from the mobile app. Here you can see your records and send requests.</div>

    <div class="stats">
        <div class="card stat">
            <div class="label">Today</div>
            <div class="value" style="font-size:20px">
                @if ($a)
                    <span class="badge {{ $statusBadge[$a->status] ?? '' }}">{{ $statusLabel($a->status) }}</span>
                @else
                    <span class="badge">Not checked in</span>
                @endif
            </div>
            <div class="sub">In {{ $time($a?->check_in_at) }} · Out {{ $time($a?->check_out_at) }}@if ($workedSoFar) · {{ $hours($workedSoFar) }}@endif</div>
        </div>
        <a class="card stat" href="{{ route('portal.attendance') }}">
            <div class="label">Present this month</div>
            <div class="value">{{ $present }}</div>
            <div class="sub">{{ $monthCounts['wfh'] ?? 0 }} from home · {{ $monthCounts['half_day'] ?? 0 }} half day</div>
        </a>
        <a class="card stat" href="{{ route('portal.attendance') }}">
            <div class="label">Late this month</div>
            <div class="value">{{ $monthCounts['late'] ?? 0 }}</div>
            <div class="sub">after shift grace time</div>
        </a>
        <a class="card stat" href="{{ route('portal.leave.index') }}">
            <div class="label">Pending requests</div>
            <div class="value">{{ collect($pending)->sum(fn ($p) => $p[0]) }}</div>
            <div class="sub">{{ collect($pending)->map(fn ($p, $k) => $p[0].' '.strtolower($k))->implode(' · ') }}</div>
        </a>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Leave balance {{ $today->year }}</h2><a class="small" href="{{ route('portal.leave.index') }}">Leave →</a></div>
            @if ($balances->isEmpty())
                <div class="empty">No leave balances set up yet.</div>
            @else
                <ul class="list">
                    @foreach ($balances as $b)
                        @php $total = (float) $b->opening + (float) $b->accrued; @endphp
                        <li>
                            <div style="flex:1">
                                <strong>{{ $b->leaveType?->name }}</strong>
                                <div class="meter"><span style="width:{{ $total > 0 ? min(100, round(max(0, $b->available) / $total * 100)) : 0 }}%"></span></div>
                            </div>
                            <span class="nowrap"><strong>{{ $days($b->available) }}</strong> <span class="muted small">of {{ $days($total) }} left</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>Upcoming holidays</h2><a class="small" href="{{ route('portal.holidays') }}">All holidays →</a></div>
            @if ($upcomingHolidays->isEmpty())
                <div class="empty">No upcoming holidays.</div>
            @else
                <ul class="list">
                    @foreach ($upcomingHolidays as $h)
                        <li><span>{{ $h->name }}</span><span class="muted nowrap">{{ $h->holiday_date->format('D, d M Y') }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>My shift</h2><a class="small" href="{{ route('portal.profile') }}">Profile →</a></div>
            <div class="card-body">
                @if ($shift)
                    <dl class="kv">
                        <dt>Shift</dt><dd>{{ $shift->name }}</dd>
                        <dt>Timing</dt><dd>{{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('h:i A') }} – {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('h:i A') }}</dd>
                        @if ($shift->grace_minutes)<dt>Grace time</dt><dd>{{ $shift->grace_minutes }} minutes</dd>@endif
                    </dl>
                @else
                    <p class="muted" style="margin:0">No shift assigned. Please contact HR.</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Latest payslip</h2><a class="small" href="{{ route('portal.payslips.index') }}">All payslips →</a></div>
            <div class="card-body">
                @if ($latestPayslip)
                    <dl class="kv">
                        <dt>Month</dt><dd>{{ $latestPayslip->run->label }}</dd>
                        <dt>Net pay</dt><dd><strong>₹{{ number_format((float) $latestPayslip->net_pay, 2) }}</strong></dd>
                        <dt>Paid days</dt><dd>{{ $days($latestPayslip->paid_days) }} of {{ $latestPayslip->period_days }}</dd>
                    </dl>
                    <div class="actions" style="margin-top:12px">
                        <a class="btn sm" href="{{ route('portal.payslips.pdf', [$latestPayslip, 'inline' => 1]) }}" target="_blank" rel="noopener">View</a>
                        <a class="btn sm" href="{{ route('portal.payslips.pdf', $latestPayslip) }}">Download PDF</a>
                    </div>
                @else
                    <p class="muted" style="margin:0">No payslips yet.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
