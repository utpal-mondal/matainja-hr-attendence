@extends('portal.layout')
@section('title', 'Regularization')

@section('content')
    @php
        $statusBadge = ['approved' => 'ok', 'rejected' => 'bad', 'pending' => 'warn'];
        $time = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->setTimezone($tz)->format('h:i A') : '—';
    @endphp

    <div class="page-head">
        <div>
            <h1>Regularization</h1>
            <p>Forgot to check in or out, or the time is wrong? Ask for the correct times. Your manager or HR approves it.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card" id="request">
            <div class="card-head"><h2>New request</h2></div>
            <form method="POST" action="{{ route('portal.regularization.store') }}" class="card-body">
                @csrf
                <div class="field">
                    <label for="work_date">Date</label>
                    <input id="work_date" name="work_date" type="date" required max="{{ $today->toDateString() }}"
                           value="{{ old('work_date', $selectedDate) }}" class="{{ $errors->has('work_date') ? 'is-invalid' : '' }}">
                    @if ($selectedDate && ! old('work_date'))
                        <div class="hint">Recorded: in {{ $time($recorded?->check_in_at) }} · out {{ $time($recorded?->check_out_at) }}</div>
                    @endif
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="check_in">Correct check-in</label>
                        <input id="check_in" name="check_in" type="time" value="{{ old('check_in') }}" class="{{ $errors->has('check_in') ? 'is-invalid' : '' }}">
                    </div>
                    <div class="field">
                        <label for="check_out">Correct check-out</label>
                        <input id="check_out" name="check_out" type="time" value="{{ old('check_out') }}" class="{{ $errors->has('check_out') ? 'is-invalid' : '' }}">
                    </div>
                </div>
                <div class="hint" style="margin:-8px 0 14px">Fill in only the time that needs fixing. Times are in office time ({{ $tz }}).</div>
                <div class="field">
                    <label for="reason">Reason</label>
                    <textarea id="reason" name="reason" required maxlength="2000" placeholder="e.g. Phone battery died, checked out at 6:30 PM"
                              class="{{ $errors->has('reason') ? 'is-invalid' : '' }}">{{ old('reason') }}</textarea>
                </div>
                <button class="btn primary" type="submit">Send request</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h2>How it works</h2></div>
            <div class="card-body">
                <ol style="margin:0;padding-left:18px;line-height:1.7">
                    <li>Pick the day and enter the correct check-in and/or check-out time.</li>
                    <li>Your request goes to your manager or HR for approval.</li>
                    <li>Once approved, the day in <a href="{{ route('portal.attendance') }}">My attendance</a> is updated. Your original punches are kept for the record.</li>
                </ol>
                <p class="muted small" style="margin:12px 0 0">One pending request per day. Check-in and check-out themselves are only done from the mobile app.</p>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-head"><h2>My requests</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Requested</th><th>Recorded before</th><th>Reason</th><th>Submitted</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($requests as $item)
                    @php $values = $item->requested_values ?? []; @endphp
                    <tr>
                        <td class="nowrap"><strong>{{ $item->work_date->format('d M Y') }}</strong></td>
                        <td class="small nowrap">
                            @isset($values['check_in_at'])<div><span class="muted">In:</span> {{ $time($values['check_in_at']) }}</div>@endisset
                            @isset($values['check_out_at'])<div><span class="muted">Out:</span> {{ $time($values['check_out_at']) }}</div>@endisset
                        </td>
                        <td class="small nowrap">
                            @if ($item->status === 'pending' && $item->attendance)
                                {{ $time($item->attendance->check_in_at) }} → {{ $time($item->attendance->check_out_at) }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td style="max-width:280px">{{ $item->reason }}</td>
                        <td class="nowrap small">{{ $item->created_at?->setTimezone($tz)->format('d M, h:i A') }}</td>
                        <td>
                            <span class="badge {{ $statusBadge[$item->status] ?? '' }}">{{ ucfirst($item->status) }}</span>
                            @if ($item->reviewer)<span class="sub">by {{ $item->reviewer->name }}</span>@endif
                            @if ($item->review_comment)<span class="sub">“{{ $item->review_comment }}”</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No regularization requests yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links('admin.partials.pagination') }}
    </div>
@endsection
