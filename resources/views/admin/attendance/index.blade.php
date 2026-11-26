@extends('admin.layout')
@section('title', 'Attendance')

@section('content')
    @php
        $badge = ['in' => 'ok', 'late' => 'warn', 'wfh' => 'wfh', 'leave' => 'info', 'absent' => '', 'holiday' => 'brand', 'off' => ''];
        // Over a range, $date is the last day of the period and $from the first.
        $isToday = ! $ranged && $date->isSameDay($today);
        $keepPeriod = request()->only(['period', 'date', 'from', 'to']);
        // Work from home can be added from today onwards, and isn't needed on a WFH weekday.
        $canAddWfh = $isToday && ! $isWfhDay;
        $q = request()->except('page');
        $fmtTime = fn ($t) => $t ? $t->copy()->setTimezone($tz)->format('h:i A') : null;
        $place = function (?array $loc, bool $wfh) {
            if (! $loc) {
                return null;
            }
            $dist = $loc['distance_from_office_meters'];
            $text = $wfh ? 'Remote' : ($dist === null ? 'Location' : ($dist < 1000 ? $dist.' m from office' : round($dist / 1000, 1).' km from office'));

            return ['text' => $text, 'url' => 'https://www.google.com/maps?q='.$loc['latitude'].','.$loc['longitude'], 'far' => ! $wfh && $dist !== null && $dist > 300];
        };
    @endphp

    <div class="page-head">
        <div>
            <h1>Attendance</h1>
            @if ($ranged)
                <p>{{ $periods[$period] }} · {{ $from->format('d M Y') }} – {{ $date->format('d M Y') }} · {{ $days }} day{{ $days === 1 ? '' : 's' }}
                    · {{ $employeeCount }} employee{{ $employeeCount === 1 ? '' : 's' }} · {{ $total }} working day{{ $total === 1 ? '' : 's' }} expected</p>
            @else
                <p>{{ $date->format('l, d F Y') }}{{ $isToday ? ' · today' : '' }} · {{ $total }} employee{{ $total === 1 ? '' : 's' }} expected</p>
            @endif
        </div>
        <div class="actions">
            @unless ($ranged)
                <a class="btn" href="{{ route('admin.attendance.index', array_merge($q, ['date' => $date->subDay()->toDateString()])) }}">‹ Prev day</a>
                @unless ($isToday)<a class="btn" href="{{ route('admin.attendance.index', array_merge($q, ['date' => null])) }}">Today</a>@endunless
                @if ($date->lt($today))<a class="btn" href="{{ route('admin.attendance.index', array_merge($q, ['date' => $date->addDay()->toDateString()])) }}">Next day ›</a>@endif
            @endunless
            <a class="btn primary" href="{{ route('admin.attendance.export', $ranged ? $q : array_merge($q, ['date' => $date->toDateString()])) }}">Export CSV</a>
        </div>
    </div>

    @if (! $ranged && $holiday)
        <div class="alert info"><strong>Holiday:</strong>&nbsp;{{ $holiday->name }}</div>
    @elseif (! $ranged && $date->isWeekend())
        <div class="alert info">Weekend: attendance is optional.</div>
    @endif
    @if (! $ranged && $isWfhDay)
        <div class="alert wfh"><strong>Work-from-home day.</strong>&nbsp;Everyone can check in from anywhere; the office geofence is skipped and the punch location is still recorded.</div>
    @endif

    <div class="stats">
        @foreach ($filters as $key => $label)
            <a class="card stat {{ request('status') === $key ? 'active' : '' }}"
               href="{{ route('admin.attendance.index', array_merge($q, ['status' => request('status') === $key ? null : $key])) }}">
                <div class="label"><span class="dot" style="background:var(--{{ ['in' => 'ok', 'late' => 'warn', 'wfh' => 'wfh', 'leave' => 'info', 'absent' => 'muted'][$key] }})"></span>{{ $key === 'absent' && ! $isToday ? 'Absent' : $label }}</div>
                <div class="value">{{ $counts[$key] }}</div>
                <div class="sub">{{ $total ? round($counts[$key] / $total * 100) : 0 }}%</div>
            </a>
        @endforeach
    </div>

    <div class="card">
        <div class="card-head">
            <form method="GET" class="toolbar" style="flex:1" id="attendance-filters">
                <select name="period" id="period" aria-label="Period">
                    @foreach ($periods as $value => $label)<option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>@endforeach
                </select>
                <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()"
                       data-period-input="day" aria-label="Day" @if ($period !== 'day') hidden disabled @endif>
                <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}"
                       data-period-input="custom" aria-label="From" title="From" @if ($period !== 'custom') hidden disabled @endif>
                <input type="date" name="to" value="{{ $date->toDateString() }}" max="{{ $today->toDateString() }}"
                       data-period-input="custom" aria-label="To" title="To" @if ($period !== 'custom') hidden disabled @endif>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search employee or code">
                <select name="branch" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected(request('branch') == $b->id)>{{ $b->name }}</option>@endforeach
                </select>
                <select name="department" onchange="this.form.submit()">
                    <option value="">All departments</option>
                    @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(request('department') == $d->id)>{{ $d->name }}</option>@endforeach
                </select>
                @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                <button class="btn" type="submit">Apply</button>
                @if (request()->hasAny(['q', 'branch', 'department', 'status']))<a class="btn ghost" href="{{ route('admin.attendance.index', $ranged ? $keepPeriod : ['date' => $date->toDateString()]) }}">Clear</a>@endif
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>@if ($ranged)<th>Date</th>@endif<th>Employee</th><th>Shift</th><th>Check-in</th><th>Check-out</th><th>Worked</th><th>Check-in place</th><th>Check-out place</th><th>Status</th><th class="right">Actions</th></tr>
                </thead>
                <tbody>
                @forelse ($rows as $r)
                    @php
                        $s = $r['staff'];
                        $a = $r['attendance'];
                        $wfh = $r['bucket'] === 'wfh';
                        $in = $place($r['in_location'], $wfh);
                        $out = $place($r['out_location'], $wfh);
                        $rowIsToday = $r['date']->isSameDay($today);
                        $worked = $a?->worked_minutes;
                        if ($a && $a->check_in_at && ! $a->check_out_at && $rowIsToday) {
                            $worked = (int) $a->check_in_at->diffInMinutes(now());
                        }
                    @endphp
                    <tr>
                        @if ($ranged)
                            <td class="nowrap"><strong>{{ $r['date']->format('d M Y') }}</strong><span class="sub">{{ $r['date']->format('l') }}</span></td>
                        @endif
                        <td>
                            <div class="person">
                                <span class="avatar">{{ strtoupper(mb_substr($s->first_name ?: $s->full_name, 0, 1).mb_substr($s->last_name ?? '', 0, 1)) }}</span>
                                <div><a href="{{ route('admin.staff.edit', $s) }}"><strong>{{ $s->full_name }}</strong></a>
                                    <span class="sub">{{ $s->employee_code }} · {{ $s->department?->name ?? 'No department' }}</span></div>
                            </div>
                        </td>
                        <td class="nowrap">{{ $a?->shift ? substr($a->shift->start_time, 0, 5).'–'.substr($a->shift->end_time, 0, 5) : '—' }}</td>
                        <td class="nowrap">
                            @if ($a?->check_in_at)
                                <strong>{{ $fmtTime($a->check_in_at) }}</strong>
                                <span class="sub">{{ $a->late_minutes > 0 ? $a->late_minutes.' min late' : ($wfh ? 'remote' : 'on time') }}</span>
                            @else — @endif
                        </td>
                        <td class="nowrap">
                            @if ($a?->check_out_at)
                                <strong>{{ $fmtTime($a->check_out_at) }}</strong>
                                @if ($a->overtime_minutes > 0)<span class="sub">+{{ $a->overtime_minutes }} min overtime</span>@endif
                            @elseif ($a?->check_in_at)
                                <span class="badge {{ $rowIsToday ? 'ok' : 'warn' }}">{{ $rowIsToday ? 'Working' : 'Missed check-out' }}</span>
                            @else — @endif
                        </td>
                        <td class="nowrap" style="min-width:110px">
                            @if ($worked)
                                {{ intdiv($worked, 60) }}h {{ str_pad($worked % 60, 2, '0', STR_PAD_LEFT) }}m
                                <div class="meter"><span style="width:{{ min(100, $worked / 540 * 100) }}%"></span></div>
                            @else — @endif
                        </td>
                        @foreach ([$in, $out] as $p)
                            <td class="nowrap">
                                @if ($p)
                                    <a href="{{ $p['url'] }}" target="_blank" rel="noopener" title="Open in Google Maps">📍 {{ $p['text'] }}</a>
                                    @if ($p['far'])<span class="sub" style="color:var(--warn)">outside usual range</span>@endif
                                @elseif ($a?->check_in_at && $loop->first)
                                    <span class="muted small">Not recorded</span>
                                @else — @endif
                            </td>
                        @endforeach
                        <td><span class="badge {{ $badge[$r['bucket']] }}">{{ $r['label'] }}</span></td>
                        <td class="right nowrap">
                            @php
                                $currentMark = match (true) {
                                    ! $a => '',
                                    $a->status === 'half_day' => 'half_day',
                                    $a->status === 'absent' => 'absent',
                                    default => $a->check_in_at ? 'present' : '',
                                };
                            @endphp
                            <button type="button" class="btn sm" data-open-dialog="mark-dialog"
                                    data-mark-action="{{ route('admin.attendance.mark', $s) }}" data-mark-staff-id="{{ $s->id }}" data-mark-name="{{ $s->full_name }}"
                                    data-mark-date="{{ $r['date']->toDateString() }}" data-mark-date-label="{{ $r['date']->format('l, d M Y') }}"
                                    data-mark-current="{{ $currentMark }}" data-mark-in="{{ $a?->check_in_at?->copy()->setTimezone($tz)->format('H:i') }}"
                                    data-mark-out="{{ $a?->check_out_at?->copy()->setTimezone($tz)->format('H:i') }}">{{ $a ? 'Edit' : 'Mark' }}</button>
                            @unless ($r['on_leave'] || $ranged)
                                <button type="button" class="btn sm" data-open-dialog="leave-dialog"
                                        data-leave-action="{{ route('admin.staff.leave.store', $s) }}" data-leave-staff-id="{{ $s->id }}" data-leave-name="{{ $s->full_name }}">+ Leave</button>
                            @endunless
                            @if ($canAddWfh)
                                @if ($r['wfh_approved'])
                                    <span class="muted small">WFH approved</span>
                                @elseif (! $r['on_leave'])
                                    <button type="button" class="btn sm" data-open-dialog="wfh-dialog"
                                            data-wfh-action="{{ route('admin.staff.wfh.store', $s) }}" data-wfh-staff-id="{{ $s->id }}" data-wfh-name="{{ $s->full_name }}">+ WFH</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $ranged ? 10 : 9 }}" class="empty">No {{ $ranged ? 'days' : 'employees' }} match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($ranged)
            {{ $rows->links('admin.partials.pagination') }}
        @endif
    </div>

    @if ($canAddWfh)
        @php
            // Re-open the pop-up for the same employee when the last submit failed.
            $wfhRetryId = $errors->wfh->any() ? old('wfh_staff_id') : null;
        @endphp
        <dialog id="wfh-dialog" aria-labelledby="wfh-title" @if ($wfhRetryId) data-open-on-load @endif>
            <form method="POST" action="{{ $wfhRetryId ? route('admin.staff.wfh.store', $wfhRetryId) : '' }}" class="card-body" id="wfh-form">
                @csrf
                <input type="hidden" name="return_to" value="attendance">
                <input type="hidden" name="wfh_staff_id" id="wfh-staff-id" value="{{ $wfhRetryId }}">
                <input type="hidden" name="wfh_staff_name" id="wfh-staff-name" value="{{ $wfhRetryId ? old('wfh_staff_name') : '' }}">
                <h2 id="wfh-title">Add work from home</h2>
                <p class="muted" style="margin:4px 0 14px"><strong id="wfh-name">{{ $wfhRetryId ? old('wfh_staff_name') : '' }}</strong> can check in from anywhere on these days. It's approved straight away.</p>
                <div class="form-grid">
                    <div class="field">
                        <label for="wfh_from">From *</label>
                        <input id="wfh_from" type="date" name="from_date" min="{{ $today->toDateString() }}" value="{{ $wfhRetryId ? old('from_date') : $today->toDateString() }}" required
                               class="{{ $errors->wfh->has('from_date') ? 'is-invalid' : '' }}">
                        @if ($errors->wfh->has('from_date'))<div class="error">{{ $errors->wfh->first('from_date') }}</div>@endif
                    </div>
                    <div class="field">
                        <label for="wfh_to">To *</label>
                        <input id="wfh_to" type="date" name="to_date" min="{{ $today->toDateString() }}" value="{{ $wfhRetryId ? old('to_date') : $today->toDateString() }}" required
                               class="{{ $errors->wfh->has('to_date') ? 'is-invalid' : '' }}">
                        @if ($errors->wfh->has('to_date'))<div class="error">{{ $errors->wfh->first('to_date') }}</div>@endif
                    </div>
                    <div class="field wide">
                        <label for="wfh_reason">Reason</label>
                        <input id="wfh_reason" type="text" name="reason" maxlength="2000" value="{{ $wfhRetryId ? old('reason') : '' }}" placeholder="e.g. Unwell, internet installation">
                    </div>
                </div>
                <div class="actions" style="justify-content:flex-end">
                    <button type="button" class="btn" data-close-dialog>Cancel</button>
                    <button type="submit" class="btn primary">Add work from home</button>
                </div>
            </form>
        </dialog>
    @endif

    @include('admin.staff._leave-dialog', ['leaveDate' => $date->toDateString(), 'leaveReturnTo' => 'attendance'])

    @php
        // Re-open the pop-up for the same employee when the last submit failed.
        $markRetryId = $errors->mark->any() ? old('mark_staff_id') : null;
    @endphp
    <dialog id="mark-dialog" aria-labelledby="mark-title" @if ($markRetryId) data-open-on-load @endif>
        <form method="POST" action="{{ $markRetryId ? route('admin.attendance.mark', $markRetryId) : '' }}" class="card-body" id="mark-form">
            @csrf
            <input type="hidden" name="date" id="mark-date" value="{{ $markRetryId ? old('date') : $date->toDateString() }}">
            <input type="hidden" name="mark_staff_id" id="mark-staff-id" value="{{ $markRetryId }}">
            <input type="hidden" name="mark_staff_name" id="mark-staff-name" value="{{ $markRetryId ? old('mark_staff_name') : '' }}">
            <h2 id="mark-title">Mark attendance</h2>
            <p class="muted" style="margin:4px 0 14px"><strong id="mark-name">{{ $markRetryId ? old('mark_staff_name') : '' }}</strong> · <span id="mark-date-label">{{ \Carbon\CarbonImmutable::parse($markRetryId && old('date') ? old('date') : $date)->format('l, d M Y') }}</span></p>
            @if ($errors->mark->has('date'))<div class="error">{{ $errors->mark->first('date') }}</div>@endif
            <div class="field">
                <label>Mark as *</label>
                <div class="toolbar" style="gap:14px">
                    @foreach (\App\Http\Controllers\Admin\AttendanceController::MARKS as $value => $label)
                        <label class="check" style="font-weight:400"><input type="radio" name="mark" value="{{ $value }}" @checked(($markRetryId ? old('mark') : 'present') === $value)> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <div class="form-grid" id="mark-times">
                <div class="field">
                    <label for="mark_in">Check-in</label>
                    <input id="mark_in" type="time" name="check_in" value="{{ $markRetryId ? old('check_in') : '' }}" class="{{ $errors->mark->has('check_in') ? 'is-invalid' : '' }}">
                    @if ($errors->mark->has('check_in'))<div class="error">{{ $errors->mark->first('check_in') }}</div>@endif
                </div>
                <div class="field">
                    <label for="mark_out">Check-out</label>
                    <input id="mark_out" type="time" name="check_out" value="{{ $markRetryId ? old('check_out') : '' }}" class="{{ $errors->mark->has('check_out') ? 'is-invalid' : '' }}">
                    @if ($errors->mark->has('check_out'))<div class="error">{{ $errors->mark->first('check_out') }}</div>@endif
                </div>
            </div>
            <div class="field">
                <label for="mark_note">Note</label>
                <input id="mark_note" type="text" name="note" maxlength="255" value="{{ $markRetryId ? old('note') : '' }}" placeholder="e.g. No smartphone, signed the register">
            </div>
            <p class="hint" style="margin:0 0 12px">Times are office time ({{ $tz }}). Late, worked and overtime minutes are worked out from the employee's shift. Saved as a manual entry in the audit log.</p>
            <div class="actions" style="justify-content:flex-end">
                <button type="button" class="btn" data-close-dialog>Cancel</button>
                <button type="submit" class="btn primary">Save attendance</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
<script>
    // Period filter: show the day picker or the from/to pickers; presets apply straight away.
    (function () {
        var form = document.getElementById('attendance-filters');
        var period = document.getElementById('period');
        period.addEventListener('change', function () {
            form.querySelectorAll('[data-period-input]').forEach(function (input) {
                var show = input.getAttribute('data-period-input') === period.value;
                input.hidden = !show;
                input.disabled = !show;
            });
            if (period.value !== 'custom') { form.submit(); }
        });
    })();

    // Point the shared "Mark attendance" pop-up at the clicked employee and fill in their current day.
    (function () {
        var form = document.getElementById('mark-form');
        var times = document.getElementById('mark-times');
        function toggleTimes() {
            var checked = form.querySelector('input[name=mark]:checked');
            times.style.display = checked && checked.value === 'absent' ? 'none' : '';
        }
        form.addEventListener('change', toggleTimes);
        toggleTimes();

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-mark-action]');
            if (!btn) return;
            form.action = btn.getAttribute('data-mark-action');
            document.getElementById('mark-staff-id').value = btn.getAttribute('data-mark-staff-id');
            document.getElementById('mark-staff-name').value = btn.getAttribute('data-mark-name');
            document.getElementById('mark-name').textContent = btn.getAttribute('data-mark-name');
            document.getElementById('mark-date').value = btn.getAttribute('data-mark-date');
            document.getElementById('mark-date-label').textContent = btn.getAttribute('data-mark-date-label');
            var current = btn.getAttribute('data-mark-current') || 'present';
            form.querySelector('input[name=mark][value=' + current + ']').checked = true;
            document.getElementById('mark_in').value = btn.getAttribute('data-mark-in') || '';
            document.getElementById('mark_out').value = btn.getAttribute('data-mark-out') || '';
            document.getElementById('mark_note').value = '';
            form.querySelectorAll('.error').forEach(function (el) { el.remove(); });
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            toggleTimes();
        });
    })();
</script>
@endpush

@if ($canAddWfh)
@push('scripts')
<script>
    // Point the shared "Add work from home" pop-up at the employee whose button was clicked.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-wfh-action]');
        if (!btn) return;
        var form = document.getElementById('wfh-form');
        form.action = btn.getAttribute('data-wfh-action');
        document.getElementById('wfh-staff-id').value = btn.getAttribute('data-wfh-staff-id');
        document.getElementById('wfh-staff-name').value = btn.getAttribute('data-wfh-name');
        document.getElementById('wfh-name').textContent = btn.getAttribute('data-wfh-name');
        form.querySelectorAll('.error').forEach(function (el) { el.remove(); });
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    });
</script>
@endpush
@endif
