@extends('portal.layout')
@section('title', 'Work from home')

@section('content')
    @php $statusBadge = ['approved' => 'ok', 'rejected' => 'bad', 'pending' => 'warn']; @endphp

    <div class="page-head">
        <div>
            <h1>Work from home</h1>
            <p>Ask to work from home on upcoming days. On approved days, your app check-in counts as work from home.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>New request</h2></div>
            <form method="POST" action="{{ route('portal.wfh.store') }}" class="card-body">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="from_date">From</label>
                        <input id="from_date" name="from_date" type="date" required min="{{ $today->toDateString() }}" value="{{ old('from_date') }}"
                               class="{{ $errors->has('from_date') ? 'is-invalid' : '' }}">
                    </div>
                    <div class="field">
                        <label for="to_date">To</label>
                        <input id="to_date" name="to_date" type="date" required min="{{ $today->toDateString() }}" value="{{ old('to_date') }}"
                               class="{{ $errors->has('to_date') ? 'is-invalid' : '' }}">
                    </div>
                </div>
                <div class="field">
                    <label for="reason">Reason</label>
                    <textarea id="reason" name="reason" required maxlength="2000" class="{{ $errors->has('reason') ? 'is-invalid' : '' }}">{{ old('reason') }}</textarea>
                </div>
                <button class="btn primary" type="submit">Send for approval</button>
            </form>
        </div>
        <div class="card">
            <div class="card-head"><h2>Good to know</h2></div>
            <div class="card-body">
                <ul style="margin:0;padding-left:18px;line-height:1.7">
                    <li>Requests can be for today or later.</li>
                    <li>You still check in and out from the mobile app on WFH days.</li>
                    <li>Company-wide WFH weekdays don't need a request.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-head"><h2>My WFH requests</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($requests as $item)
                    <tr>
                        <td class="nowrap"><strong>{{ $item->from_date->format('d M Y') }}</strong>@if (! $item->from_date->equalTo($item->to_date)) → <strong>{{ $item->to_date->format('d M Y') }}</strong>@endif</td>
                        <td>{{ (int) $item->from_date->diffInDays($item->to_date) + 1 }}</td>
                        <td style="max-width:320px">{{ $item->reason }}</td>
                        <td>
                            <span class="badge {{ $statusBadge[$item->status] ?? '' }}">{{ ucfirst($item->status) }}</span>
                            @if ($item->approver)<span class="sub">by {{ $item->approver->name }}</span>@endif
                            @if ($item->approver_comment)<span class="sub">“{{ $item->approver_comment }}”</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No work-from-home requests yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links('admin.partials.pagination') }}
    </div>
@endsection
