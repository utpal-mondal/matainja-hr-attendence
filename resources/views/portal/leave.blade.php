@extends('portal.layout')
@section('title', 'Leave')

@section('content')
    @php
        $statusBadge = ['approved' => 'ok', 'rejected' => 'bad', 'pending' => 'warn', 'withdrawn' => '', 'cancelled' => ''];
        $days = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    @endphp

    <div class="page-head">
        <div>
            <h1>Leave</h1>
            <p>Your {{ $today->year }} balances, requests and their approval status.</p>
        </div>
        <div class="actions"><a class="btn primary" href="#apply">Apply for leave</a></div>
    </div>

    <div class="stats">
        @forelse ($balances as $b)
            <div class="card stat">
                <div class="label">{{ $b->leaveType?->name }}</div>
                <div class="value">{{ $days($b->available) }}</div>
                <div class="sub">{{ $days((float) $b->opening + (float) $b->accrued) }} total · {{ $days($b->used) }} used · {{ $days($b->pending) }} pending</div>
            </div>
        @empty
            <div class="card stat"><div class="label">Leave balance</div><div class="sub">No balances set up yet. Please contact HR.</div></div>
        @endforelse
    </div>

    <div class="grid grid-2">
        <div class="card" id="apply">
            <div class="card-head"><h2>Apply for leave</h2></div>
            <form method="POST" action="{{ route('portal.leave.store') }}" enctype="multipart/form-data" class="card-body">
                @csrf
                <div class="field">
                    <label for="leave_type_id">Leave type</label>
                    <select id="leave_type_id" name="leave_type_id" required class="{{ $errors->has('leave_type_id') ? 'is-invalid' : '' }}">
                        <option value="">Choose…</option>
                        @foreach ($leaveTypes as $type)
                            <option value="{{ $type->id }}" @selected(old('leave_type_id') == $type->id)>{{ $type->name }}{{ $type->requires_document ? ' (document needed)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="from_date">From</label>
                        <input id="from_date" name="from_date" type="date" required value="{{ old('from_date') }}" class="{{ $errors->has('from_date') ? 'is-invalid' : '' }}">
                    </div>
                    <div class="field">
                        <label for="to_date">To</label>
                        <input id="to_date" name="to_date" type="date" required value="{{ old('to_date') }}" class="{{ $errors->has('to_date') ? 'is-invalid' : '' }}">
                    </div>
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="partial_day" value="1" @checked(old('partial_day'))> Half day (single date only)</label>
                </div>
                <div class="field">
                    <label for="reason">Reason</label>
                    <textarea id="reason" name="reason" maxlength="2000">{{ old('reason') }}</textarea>
                </div>
                <div class="field">
                    <label for="attachment">Attachment (optional)</label>
                    <input id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="hint">PDF, JPG or PNG, up to 5 MB, e.g. a medical certificate.</div>
                </div>
                <button class="btn primary" type="submit">Send for approval</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h2>Good to know</h2></div>
            <div class="card-body">
                <ul style="margin:0;padding-left:18px;line-height:1.7">
                    <li>Requests go to your manager first, or to HR if you have no manager.</li>
                    <li>Requested days are held as <em>pending</em> in your balance until approved or rejected.</li>
                    <li>You can withdraw a pending request, or cancel approved leave that hasn't started yet.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-head"><h2>My leave requests</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Leave</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th class="right"></th></tr></thead>
                <tbody>
                @forelse ($requests as $item)
                    @php $decided = $item->approvals->whereNotNull('decision')->last(); @endphp
                    <tr>
                        <td><strong>{{ $item->leaveType?->name ?? 'Leave' }}</strong><span class="sub">applied {{ $item->created_at?->setTimezone($tz)->format('d M Y') }}</span></td>
                        <td class="nowrap">{{ $item->from_date->format('d M Y') }}@if (! $item->from_date->equalTo($item->to_date)) → {{ $item->to_date->format('d M Y') }}@endif</td>
                        <td>{{ $days($item->total_days) }}{{ $item->partial_day ? ' (half)' : '' }}</td>
                        <td style="max-width:260px">{{ $item->reason ?: '—' }}</td>
                        <td>
                            <span class="badge {{ $statusBadge[$item->status] ?? '' }}">{{ ucfirst($item->status) }}</span>
                            @if ($decided?->approver)<span class="sub">by {{ $decided->approver->name }}</span>@endif
                            @if ($decided?->comments)<span class="sub">“{{ $decided->comments }}”</span>@endif
                        </td>
                        <td class="right nowrap">
                            @if ($item->status === 'pending')
                                <form method="POST" action="{{ route('portal.leave.withdraw', $item) }}" data-confirm="Withdraw this leave request?">
                                    @csrf
                                    <button class="btn sm" type="submit">Withdraw</button>
                                </form>
                            @elseif ($item->status === 'approved' && $item->from_date->toDateString() >= $today->toDateString())
                                <form method="POST" action="{{ route('portal.leave.cancel', $item) }}" data-confirm="Cancel this approved leave? The days go back to your balance.">
                                    @csrf
                                    <button class="btn sm danger" type="submit">Cancel</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No leave requests yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links('admin.partials.pagination') }}
    </div>
@endsection
