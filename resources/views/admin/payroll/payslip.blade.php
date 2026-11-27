@extends('admin.layout')
@section('title', 'Payslip · '.$payslip->employee_name)

@section('content')
    @php
        $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
        $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
        $earnings = $payslip->items->where('type', 'earning');
        $deductions = $payslip->items->where('type', 'deduction');
        $counts = $payslip->day_counts ?? [];
        $dayLabels = [
            'present' => 'Worked', 'paid_leave' => 'Paid leave', 'holidays' => 'Holidays', 'weekly_offs' => 'Weekly offs',
            'upcoming' => 'Still to come (paid)', 'absent' => 'Absent (LOP)', 'unpaid_leave' => 'Unpaid leave (LOP)', 'not_employed' => 'Before joining (LOP)',
        ];
        $draft = $run->isDraft();
    @endphp

    <div class="page-head">
        <div>
            <a class="small" href="{{ route('admin.payroll.show', $run) }}">← {{ $run->label }} payroll</a>
            <h1 style="margin-top:4px">{{ $payslip->employee_name }}</h1>
            <p>{{ $payslip->employee_code ?? 'No code' }}{{ $payslip->designation ? ' · '.$payslip->designation : '' }}{{ $payslip->department ? ' · '.$payslip->department : '' }}
                · PAN {{ $payslip->pan ?? '—' }} · UAN {{ $payslip->uan ?? '—' }} · {{ \App\Models\StaffPayrollProfile::TAX_REGIMES[$payslip->tax_regime] ?? $payslip->tax_regime }}</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('admin.staff.salary', $payslip->staff_id) }}">Salary setup</a>
            <a class="btn" href="{{ route('admin.payroll.payslips.pdf', [$run, $payslip, 'inline' => 1]) }}" target="_blank" rel="noopener">View PDF</a>
            <a class="btn primary" href="{{ route('admin.payroll.payslips.pdf', [$run, $payslip]) }}">Download PDF</a>
        </div>
    </div>

    <div class="grid grid-2" style="align-items:start">
        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>Earnings</h2><strong>{{ $money($payslip->gross_earnings) }}</strong></div>
                <ul class="list">
                    @foreach ($earnings as $item)
                        <li><span>{{ $item->name }} @if ($item->source === 'adjustment')<span class="badge info">one-off</span>@endif</span><span>{{ $money($item->amount) }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="card">
                <div class="card-head"><h2>Deductions</h2><strong>{{ $money($payslip->total_deductions) }}</strong></div>
                <ul class="list">
                    @forelse ($deductions as $item)
                        @php
                            $edited = ($item->code === 'PF' && $payslip->employee_pf_override !== null) || ($item->code === 'ESI' && $payslip->employee_esi_override !== null);
                        @endphp
                        <li><span>{{ $item->name }} @if ($item->source === 'adjustment')<span class="badge info">one-off</span>@endif @if ($item->source === 'statutory' && $edited)<span class="badge warn">edited</span>@endif</span><span>{{ $money($item->amount) }}</span></li>
                    @empty
                        <li><span class="muted">No deductions</span><span></span></li>
                    @endforelse
                </ul>
            </div>
            <div class="card card-body actions" style="justify-content:space-between">
                <strong>Net pay</strong>
                <strong style="font-size:1.25em">{{ $money($payslip->net_pay) }}</strong>
            </div>
            @if ((float) $payslip->net_pay < 0)
                <div class="alert error">Deductions are more than the earnings. Check the LOP days and adjustments.</div>
            @endif
            <div class="card">
                <div class="card-head"><h2>Employer contributions</h2><span class="muted small">not deducted from pay</span></div>
                <ul class="list">
                    <li><span>Provident Fund (of which pension EPS {{ $money($payslip->employer_eps) }})</span><span>{{ $money($payslip->employer_pf) }}</span></li>
                    <li><span>ESI</span><span>{{ $money($payslip->employer_esi) }}</span></li>
                </ul>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>Attendance</h2><span>{{ $days($payslip->paid_days) }} of {{ $payslip->period_days }} days paid</span></div>
                <ul class="list">
                    @foreach ($dayLabels as $key => $label)
                        @if (($counts[$key] ?? 0) > 0)<li><span>{{ $label }}</span><span>{{ $days($counts[$key]) }}</span></li>@endif
                    @endforeach
                    @if ($payslip->overtime_minutes > 0)<li><span>Overtime</span><span>{{ intdiv($payslip->overtime_minutes, 60) }}h {{ $payslip->overtime_minutes % 60 }}m</span></li>@endif
                </ul>
                <form method="POST" action="{{ route('admin.payroll.payslips.lop', [$run, $payslip]) }}" class="card-body" style="border-top:1px solid var(--border)">
                    @csrf @method('PUT')
                    <div class="field" style="margin-bottom:8px">
                        <label for="lop_days_override">Loss-of-pay days</label>
                        <input id="lop_days_override" type="number" name="lop_days_override" step="0.5" min="0" max="{{ $payslip->period_days }}"
                               value="{{ old('lop_days_override', $payslip->lop_days_override !== null ? $days($payslip->lop_days_override) : '') }}"
                               placeholder="{{ $days($payslip->lop_days_calculated) }} from attendance" @disabled(! $draft)>
                        <div class="hint">From attendance: {{ $days($payslip->lop_days_calculated) }}. Enter a number to override it (e.g. attendance wasn't recorded), or leave empty to use attendance.</div>
                    </div>
                    @if ($draft)
                        <div class="actions" style="justify-content:flex-end"><button class="btn" type="submit">Update LOP</button></div>
                    @endif
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h2>PF &amp; ESI</h2><span class="muted small">employee share</span></div>
                <form method="POST" action="{{ route('admin.payroll.payslips.statutory', [$run, $payslip]) }}" class="card-body form-grid">
                    @csrf @method('PUT')
                    <div class="field">
                        <label for="employee_pf_override">Provident Fund (₹)</label>
                        <input id="employee_pf_override" type="number" name="employee_pf_override" step="0.01" min="0"
                               value="{{ old('employee_pf_override', $payslip->employee_pf_override) }}"
                               placeholder="{{ $payslip->employee_pf_override === null ? number_format((float) $payslip->employee_pf, 2, '.', '').' calculated' : 'Calculated' }}" @disabled(! $draft)>
                        @error('employee_pf_override')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="employee_esi_override">ESI (₹)</label>
                        <input id="employee_esi_override" type="number" name="employee_esi_override" step="0.01" min="0"
                               value="{{ old('employee_esi_override', $payslip->employee_esi_override) }}"
                               placeholder="{{ $payslip->employee_esi_override === null ? number_format((float) $payslip->employee_esi, 2, '.', '').' calculated' : 'Calculated' }}" @disabled(! $draft)>
                        @error('employee_esi_override')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="hint wide">Enter an amount to deduct instead of the calculated one (0 for none), or leave empty to use the calculation. Employer contributions and PF wages stay as calculated. An overridden PF also counts for TDS and the PF ECR file.</div>
                    @if ($draft)
                        <div class="actions wide" style="justify-content:flex-end"><button class="btn" type="submit">Update PF &amp; ESI</button></div>
                    @endif
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h2>One-off adjustments</h2></div>
                @if ($adjustments->isNotEmpty())
                    <ul class="list">
                        @foreach ($adjustments as $adj)
                            <li>
                                <span>{{ $adj->name }} <span class="muted small">{{ $adj->type === 'earning' ? 'earning'.($adj->is_taxable ? ', taxable' : ', not taxable') : 'deduction' }}</span></span>
                                <span class="nowrap">
                                    {{ $adj->type === 'deduction' ? '− ' : '+ ' }}{{ $money($adj->amount) }}
                                    @if ($draft)
                                        <form class="inline" method="POST" action="{{ route('admin.payroll.adjustments.destroy', [$run, $adj]) }}" data-confirm="Remove {{ $adj->name }}?">
                                            @csrf @method('DELETE')<button class="btn sm ghost" type="submit" aria-label="Remove {{ $adj->name }}">✕</button>
                                        </form>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($draft)
                    <form method="POST" action="{{ route('admin.payroll.adjustments.store', [$run, $payslip]) }}" class="card-body form-grid" style="border-top:1px solid var(--border)">
                        @csrf
                        <div class="field">
                            <label for="adj_type">Type</label>
                            <select id="adj_type" name="type">
                                <option value="earning" @selected(old('type') === 'earning')>Earning (bonus, arrears…)</option>
                                <option value="deduction" @selected(old('type') === 'deduction')>Deduction (advance recovery…)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="adj_amount">Amount (₹)</label>
                            <input id="adj_amount" type="number" name="amount" step="0.01" min="0.01" value="{{ old('amount') }}" required>
                        </div>
                        <div class="field wide">
                            <label for="adj_name">Description</label>
                            <input id="adj_name" type="text" name="name" maxlength="100" value="{{ old('name') }}" placeholder="e.g. Diwali bonus" required>
                        </div>
                        <div class="field wide">
                            <label class="check"><input type="checkbox" name="is_taxable" value="1" @checked(old('is_taxable', true))> Taxable (earnings only, counts for TDS)</label>
                        </div>
                        <div class="actions wide" style="justify-content:flex-end"><button class="btn primary" type="submit">Add adjustment</button></div>
                    </form>
                @elseif ($adjustments->isEmpty())
                    <div class="card-body"><p class="muted" style="margin:0">None.</p></div>
                @endif
            </div>
        </div>
    </div>
@endsection
