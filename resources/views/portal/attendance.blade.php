@extends('portal.layout')
@section('title', 'My attendance')

@section('content')
    @php
        $statusBadge = ['present' => 'ok', 'late' => 'warn', 'wfh' => 'wfh', 'half_day' => 'info', 'absent' => 'bad'];
        $statusLabel = ['present' => 'Present', 'late' => 'Late', 'wfh' => 'Work from home', 'half_day' => 'Half day', 'absent' => 'Absent'];
        $time = fn ($t) => $t ? $t->copy()->setTimezone($tz)->format('h:i A') : '—';
        $hours = fn (?int $m) => $m ? intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m' : '—';
    @endphp

    <div class="page-head">
        <div>
            <h1>My attendance</h1>
            <p>Your check-ins from the mobile app. If a day is wrong, send a regularization request.</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('portal.attendance', ['month' => $previousMonth]) }}">← Previous</a>
            <strong style="min-width:130px;text-align:center">{{ $month->format('F Y') }}</strong>
            @if ($nextMonth)
                <a class="btn" href="{{ route('portal.attendance', ['month' => $nextMonth]) }}">Next →</a>
            @else
                <span class="btn" aria-disabled="true" style="opacity:.5;pointer-events:none">Next →</span>
            @endif
        </div>
    </div>

    <div class="stats">
        @foreach ($summary as $label => $value)
            <div class="card stat">
                <div class="label">{{ $label }}</div>
                <div class="value">{{ rtrim(rtrim(number_format((float) $value, 1), '0'), '.') }}</div>
            </div>
        @endforeach
        <div class="card stat">
            <div class="label">Hours worked</div>
            <div class="value" style="font-size:22px">{{ $hours($workedMinutes) === '—' ? '0h' : $hours($workedMinutes) }}</div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Date</th><th>Status</th><th>Check-in</th><th>Check-out</th><th>Worked</th><th>Late</th><th>Breaks</th><th>Note</th><th class="right"></th></tr>
                </thead>
                <tbody>
                @forelse ($days as $day)
                    @php $r = $day['record']; @endphp
                    <tr>
                        <td class="nowrap"><strong>{{ $day['date']->format('d M') }}</strong> <span class="muted small">{{ $day['date']->format('D') }}</span></td>
                        <td>
                            @if ($r)
                                <span class="badge {{ $statusBadge[$r->status] ?? '' }}">{{ $statusLabel[$r->status] ?? ucfirst((string) $r->status) }}</span>
                            @elseif ($day['holiday'])
                                <span class="badge brand">Holiday</span>
                            @elseif ($day['leave'])
                                <span class="badge info">On leave</span>
                            @else
                                <span class="muted small">No record</span>
                            @endif
                        </td>
                        <td class="nowrap">{{ $time($r?->check_in_at) }}</td>
                        <td class="nowrap">{{ $time($r?->check_out_at) }}</td>
                        <td class="nowrap">{{ $hours($r?->worked_minutes) }}</td>
                        <td class="nowrap">{{ $r?->late_minutes ? $r->late_minutes.' min' : '—' }}</td>
                        <td>{{ $r?->breaks_count ?: '—' }}</td>
                        <td class="small">
                            @if ($day['holiday']){{ $day['holiday']->name }}@endif
                            @if ($day['leave'])<div>{{ $day['leave']->leaveType?->name ?? 'Leave' }}{{ $day['leave']->partial_day ? ' (half day)' : '' }}</div>@endif
                            @if ($day['wfh'] && $r?->status !== 'wfh')<div>WFH approved</div>@endif
                            @if ($r?->source === 'correction' || $r?->source === 'manual')<div class="muted">{{ $r->source === 'correction' ? 'Regularized' : 'Entered by HR' }}</div>@endif
                        </td>
                        <td class="right nowrap">
                            @if ($day['correctionPending'])
                                <span class="badge warn">Request pending</span>
                            @else
                                <a class="btn sm" href="{{ route('portal.regularization.index', ['date' => $day['date']->toDateString()]) }}#request">Regularize</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty">No days to show for this month.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
