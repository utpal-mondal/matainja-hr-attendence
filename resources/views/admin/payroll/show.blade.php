@extends('admin.layout')
@section('title', 'Payroll · '.$run->label)

@section('content')
    @php
        $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
        $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    @endphp

    <div class="page-head">
        <div>
            <a class="small" href="{{ route('admin.payroll.index') }}">← Payroll</a>
            <h1 style="margin-top:4px">{{ $run->label }} @include('admin.payroll._status', ['status' => $run->status])</h1>
            <p>
                {{ $run->period_start->format('d M') }} – {{ $run->period_end->format('d M Y') }}
                @if ($run->processed_at) · calculated {{ $run->processed_at->format('d M Y, H:i') }}{{ $run->processor ? ' by '.$run->processor->name : '' }}@endif
                @if ($run->locked_at) · locked {{ $run->locked_at->format('d M Y') }}{{ $run->locker ? ' by '.$run->locker->name : '' }}@endif
                @if ($run->paid_on) · paid {{ $run->paid_on->format('d M Y') }}{{ $run->payment_reference ? ' (ref '.$run->payment_reference.')' : '' }}@endif
            </p>
        </div>
        <div class="actions">
            @if ($run->employee_count > 0)
                <a class="btn" href="{{ route('admin.payroll.pdf', $run) }}">Payslips PDF</a>
                <a class="btn" href="{{ route('admin.payroll.register', $run) }}">Register CSV</a>
                @if ($run->isDraft())
                    <span class="btn" aria-disabled="true" style="opacity:.55;cursor:not-allowed" title="Lock the payroll first, so the amounts can't change after the transfer">Bank file CSV</span>
                @else
                    <a class="btn" href="{{ route('admin.payroll.bank', $run) }}" title="Contains full account numbers. Downloads are recorded in the audit log.">Bank file CSV</a>
                @endif
            @endif
            @if ($run->isDraft())
                <form class="inline" method="POST" action="{{ route('admin.payroll.recalculate', $run) }}" data-confirm="Recalculate every payslip from the latest salaries, attendance and leave? LOP overrides and adjustments are kept.">
                    @csrf<button class="btn" type="submit">Recalculate</button>
                </form>
                <form class="inline" method="POST" action="{{ route('admin.payroll.lock', $run) }}" data-confirm="Lock the {{ $run->label }} payroll? The figures can't change after this unless you reopen it.">
                    @csrf<button class="btn primary" type="submit" @disabled($run->employee_count === 0)>Lock payroll</button>
                </form>
            @elseif ($run->status === 'locked')
                <form class="inline" method="POST" action="{{ route('admin.payroll.reopen', $run) }}" data-confirm="Reopen the {{ $run->label }} payroll as a draft so it can be changed?">
                    @csrf<button class="btn" type="submit">Reopen</button>
                </form>
                <button class="btn success" type="button" data-open-dialog="paid-dialog">Mark as paid</button>
            @endif
        </div>
    </div>

    <div class="stats">
        <div class="card stat"><div class="label">Employees</div><div class="value">{{ $run->employee_count }}</div><div class="sub">{{ count($run->skipped ?? []) }} not included</div></div>
        <div class="card stat"><div class="label">Gross pay</div><div class="value">{{ $money($run->total_gross) }}</div><div class="sub">before deductions</div></div>
        <div class="card stat"><div class="label">Deductions</div><div class="value">{{ $money($run->total_deductions) }}</div><div class="sub">PF {{ $money($statutory->pf) }} · ESI {{ $money($statutory->esi) }} · PT {{ $money($statutory->pt) }} · TDS {{ $money($statutory->tds) }}</div></div>
        <div class="card stat"><div class="label">Net pay</div><div class="value">{{ $money($run->total_net) }}</div><div class="sub">to transfer</div></div>
        <div class="card stat"><div class="label">Employer cost</div><div class="value">{{ $money($run->total_employer_contributions) }}</div><div class="sub">PF {{ $money($statutory->employer_pf) }} · ESI {{ $money($statutory->employer_esi) }}</div></div>
    </div>

    @if (! empty($run->skipped))
        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h2>Not included <span class="muted small">({{ count($run->skipped) }})</span></h2></div>
            <ul class="list">
                @foreach ($run->skipped as $skip)
                    <li>
                        <span>{{ $skip['name'] }}</span>
                        <span class="muted small">{{ $skip['reason'] }} · <a href="{{ route('admin.staff.salary', $skip['staff_id']) }}">Set salary</a></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($ecr['totals']['members'] > 0 || $ecr['left_out'] !== [])
        @php $whole = fn ($v) => '₹'.number_format($v); @endphp
        <div class="card" style="margin-bottom:16px">
            <div class="card-head">
                <h2>PF ECR <span class="muted small">EPFO Unified Portal upload</span></h2>
                @if ($run->isDraft())
                    <span class="muted small">Lock the payroll to download the ECR file</span>
                @elseif ($ecr['totals']['members'] > 0)
                    <a class="btn primary" href="{{ route('admin.payroll.ecr', $run) }}">Download ECR file</a>
                @endif
            </div>
            <div class="card-body">
                <dl class="kv" style="margin:0">
                    <dt>Members in file</dt><dd>{{ $ecr['totals']['members'] }}</dd>
                    <dt>EPF wages</dt><dd>{{ $whole($ecr['totals']['epf_wages']) }} · EPS / EDLI wages {{ $whole($ecr['totals']['eps_wages']) }}</dd>
                    <dt>Employee share (A/c 1)</dt><dd>{{ $whole($ecr['totals']['employee_share']) }}</dd>
                    <dt>Employer EPF share (A/c 1)</dt><dd>{{ $whole($ecr['totals']['employer_epf_share']) }}</dd>
                    <dt>Employer pension EPS (A/c 10)</dt><dd>{{ $whole($ecr['totals']['eps_share']) }}</dd>
                </dl>
                <p class="hint" style="margin:10px 0 0">The portal adds the EDLI contribution and admin charges to the challan.</p>
            </div>
            @if ($ecr['left_out'] !== [])
                <div class="card-head" style="border-top:1px solid var(--border)"><h2>Left out of the ECR <span class="muted small">({{ count($ecr['left_out']) }})</span></h2></div>
                <ul class="list">
                    @foreach ($ecr['left_out'] as $member)
                        <li><span>{{ $member['name'] }}</span><span class="badge warn">{{ $member['reason'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="card-head">
            <h2>Payslips</h2>
            <form method="GET" class="toolbar"><input type="search" name="q" value="{{ request('q') }}" placeholder="Search employee"></form>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Employee</th><th class="right">Paid days</th><th class="right">LOP</th><th class="right">Gross</th>
                    <th class="right">PF</th><th class="right">ESI</th><th class="right">PT</th><th class="right">TDS</th>
                    <th class="right">Net pay</th><th class="right"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($payslips as $p)
                    <tr>
                        <td>
                            <strong>{{ $p->employee_name }}</strong>
                            <div class="muted small">{{ $p->employee_code ?? '—' }}{{ $p->designation ? ' · '.$p->designation : '' }}</div>
                        </td>
                        <td class="right">{{ $days($p->paid_days) }} / {{ $p->period_days }}</td>
                        <td class="right">
                            {{ $days($p->lop_days) }}
                            @if ($p->lop_days_override !== null)<span class="badge warn" title="Changed by an admin (attendance says {{ $days($p->lop_days_calculated) }})">edited</span>@endif
                        </td>
                        <td class="right">{{ $money($p->gross_earnings) }}</td>
                        <td class="right">{{ $money($p->employee_pf) }}@if ($p->employee_pf_override !== null) <span class="badge warn" title="Set by an admin">edited</span>@endif</td>
                        <td class="right">{{ $money($p->employee_esi) }}@if ($p->employee_esi_override !== null) <span class="badge warn" title="Set by an admin">edited</span>@endif</td>
                        <td class="right">{{ $money($p->professional_tax) }}</td>
                        <td class="right">{{ $money($p->tds) }}</td>
                        <td class="right">
                            <strong>{{ $money($p->net_pay) }}</strong>
                            @if ($p->adjustments_count)<div class="muted small">{{ $p->adjustments_count }} adjustment{{ $p->adjustments_count === 1 ? '' : 's' }}</div>@endif
                        </td>
                        <td class="right"><a class="btn sm" href="{{ route('admin.payroll.payslips.show', [$run, $p]) }}">{{ $run->isDraft() ? 'Review' : 'View' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">No payslips{{ request('q') ? ' match your search' : '' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payslips->links('admin.partials.pagination') }}
    </div>

    @if ($run->isDraft())
        <div class="card" style="margin-top:16px;border-color:#f1c3bd">
            <div class="card-body actions" style="justify-content:space-between">
                <span class="muted">Started the wrong month? Delete this draft, along with its adjustments.</span>
                <form method="POST" action="{{ route('admin.payroll.destroy', $run) }}" data-confirm="Delete the draft {{ $run->label }} payroll and its adjustments? This can't be undone.">
                    @csrf @method('DELETE')
                    <button class="btn danger" type="submit">Delete draft</button>
                </form>
            </div>
        </div>
    @endif

    @if ($run->status === 'locked')
        <dialog id="paid-dialog" aria-labelledby="paid-title">
            <form method="POST" action="{{ route('admin.payroll.paid', $run) }}" class="card-body">
                @csrf
                <h2 id="paid-title">Mark {{ $run->label }} as paid</h2>
                <div class="field">
                    <label for="paid_on">Paid on *</label>
                    <input id="paid_on" type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" required>
                </div>
                <div class="field">
                    <label for="payment_reference">Bank / transfer reference</label>
                    <input id="payment_reference" type="text" name="payment_reference" value="{{ old('payment_reference') }}" maxlength="255">
                </div>
                <div class="actions" style="justify-content:flex-end">
                    <button type="button" class="btn" data-close-dialog>Cancel</button>
                    <button type="submit" class="btn success">Mark as paid</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection
