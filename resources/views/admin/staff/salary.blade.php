@extends('admin.layout')
@section('title', 'Salary · '.$staff->full_name)

@section('content')
    @php
        $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
        $p = fn ($field) => old($field, $profile->{$field});
        $today = now()->toDateString();
    @endphp

    <div class="page-head">
        <div>
            <a class="small" href="{{ route('admin.staff.edit', $staff) }}">← {{ $staff->full_name }}</a>
            <h1 style="margin-top:4px">Salary</h1>
            <p>Salary revisions plus the statutory and bank details payroll needs. PF and ESI come from the salary components; they, Professional Tax and TDS are worked out each month in the payroll run.</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('admin.org.index', 'salary-components') }}">Salary components</a>
        </div>
    </div>

    <div class="grid grid-2" style="align-items:start">
        <div class="stack">
            <div class="card">
                <div class="card-head">
                    <h2>Current salary</h2>
                    @if ($current)<span class="muted small">from {{ $current->effective_from->format('d M Y') }}</span>@endif
                </div>
                @if ($current)
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Component</th><th class="right">Monthly</th><th class="right">Yearly</th></tr></thead>
                            <tbody>
                            @foreach ($current->items as $item)
                                <tr>
                                    <td>{{ $item->name }} @if ($item->type === 'deduction')<span class="badge">Deduction</span>@endif</td>
                                    <td class="right">{{ $item->type === 'deduction' ? '− ' : '' }}{{ $money($item->monthly_amount) }}</td>
                                    <td class="right">{{ $money($item->monthly_amount * 12) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td><strong>Gross</strong></td>
                                <td class="right"><strong>{{ $money($current->monthly_gross) }}</strong></td>
                                <td class="right"><strong>{{ $money($current->monthly_gross * 12) }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card-body"><p class="muted" style="margin:0">No salary set yet. Add the first revision below.</p></div>
                @endif
            </div>

            <div class="card" id="revise">
                <div class="card-head"><h2>{{ $current ? 'Revise salary' : 'Set salary' }}</h2></div>
                @if ($components->isEmpty())
                    <div class="card-body">
                        <p style="margin:0">No salary components yet. <a href="{{ route('admin.org.index', 'salary-components') }}">Add Basic, HRA, …</a> first.</p>
                    </div>
                @else
                    <form method="GET" action="{{ route('admin.staff.salary', $staff) }}#revise" class="card-body toolbar" style="border-bottom:1px solid var(--border)">
                        <div class="field" style="margin:0;flex:1">
                            <label for="gross">Split a monthly gross</label>
                            <input id="gross" type="number" name="gross" min="0" step="1" value="{{ $gross }}" placeholder="e.g. 30000">
                        </div>
                        <button class="btn" type="submit" style="align-self:flex-end">Split</button>
                    </form>
                    <form method="POST" action="{{ route('admin.staff.salary.store', $staff) }}" class="card-body">
                        @csrf
                        <div class="form-grid">
                            <div class="field">
                                <label for="effective_from">Effective from *</label>
                                <input id="effective_from" type="date" name="effective_from" value="{{ old('effective_from', $current ? $today : ($staff->joining_date?->format('Y-m-d') ?? $today)) }}" required
                                       class="{{ $errors->has('effective_from') ? 'is-invalid' : '' }}">
                                @error('effective_from')<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="notes">Note</label>
                                <input id="notes" type="text" name="notes" value="{{ old('notes') }}" placeholder="e.g. Annual increment 2026">
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Component</th><th>Rule</th><th class="right">Monthly (₹)</th></tr></thead>
                                <tbody>
                                @foreach ($components as $c)
                                    <tr>
                                        <td>{{ $c->name }} @if ($c->type === 'deduction')<span class="badge">Deduction</span>@endif @unless ($c->is_active)<span class="badge">Turned off</span>@endunless</td>
                                        <td class="muted small">{{ $c->rule_label }}</td>
                                        <td class="right">
                                            <input type="number" name="amounts[{{ $c->id }}]" min="0" step="0.01" style="max-width:140px;text-align:right"
                                                   value="{{ old('amounts.'.$c->id, $proposed[$c->id] ?? 0) }}" aria-label="{{ $c->name }} monthly amount">
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('amounts')<div class="error">{{ $message }}</div>@enderror
                        @error('amounts.*')<div class="error">{{ $message }}</div>@enderror
                        <p class="hint">Gross is the total of the earnings. You can change any amount before saving; components left at 0 are skipped. PF and ESI are always worked out by their formula (and again each payroll month from what was earned): leave them above 0 to deduct them, or set 0 to stop them.</p>
                        <div class="actions" style="justify-content:flex-end">
                            <button class="btn primary" type="submit">Save salary revision</button>
                        </div>
                    </form>
                @endif
            </div>

            @if ($payslips->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Payslips</h2><span class="muted small">last {{ $payslips->count() }}</span></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Month</th><th>Status</th><th class="right">Gross</th><th class="right">Net pay</th><th class="right">Actions</th></tr></thead>
                            <tbody>
                            @foreach ($payslips as $slip)
                                <tr>
                                    <td><a href="{{ route('admin.payroll.payslips.show', [$slip->run, $slip]) }}">{{ $slip->run->label }}</a></td>
                                    <td>@include('admin.payroll._status', ['status' => $slip->run->status])</td>
                                    <td class="right">{{ $money($slip->gross_earnings) }}</td>
                                    <td class="right"><strong>{{ $money($slip->net_pay) }}</strong></td>
                                    <td class="right"><a class="btn sm" href="{{ route('admin.payroll.payslips.pdf', [$slip->run, $slip]) }}">PDF</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($staff->salaryStructures->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Salary history</h2></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>From</th><th class="right">Monthly gross</th><th>Note</th><th>Saved by</th><th class="right">Actions</th></tr></thead>
                            <tbody>
                            @foreach ($staff->salaryStructures as $s)
                                <tr>
                                    <td class="nowrap">
                                        {{ $s->effective_from->format('d M Y') }}
                                        @if ($current?->is($s))<span class="badge ok">Current</span>@elseif ($s->effective_from->isFuture())<span class="badge brand">Upcoming</span>@endif
                                    </td>
                                    <td class="right">{{ $money($s->monthly_gross) }}</td>
                                    <td>{{ $s->notes ?: '—' }}</td>
                                    <td class="muted small">{{ $s->creator?->name ?? '—' }}</td>
                                    <td class="right">
                                        <form class="inline" method="POST" action="{{ route('admin.staff.salary.destroy', [$staff, $s]) }}" data-confirm="Delete the salary revision from {{ $s->effective_from->format('d M Y') }}? This can't be undone.">
                                            @csrf @method('DELETE')
                                            <button class="btn sm ghost" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.staff.salary.profile', $staff) }}" class="stack">
            @csrf @method('PUT')
            <div class="card">
                <div class="card-head"><h2>Statutory details</h2></div>
                <div class="card-body form-grid">
                    @foreach ([
                        'pan' => ['PAN', 'ABCDE1234F'],
                        'uan' => ['UAN (PF)', '12 digits'],
                        'pf_number' => ['PF member ID', 'e.g. WBKOL00123450000012345'],
                        'esi_number' => ['ESI IP number', '10 or 17 digits'],
                    ] as $field => [$label, $placeholder])
                        <div class="field">
                            <label for="{{ $field }}">{{ $label }}</label>
                            <input id="{{ $field }}" type="text" name="{{ $field }}" value="{{ $p($field) }}" placeholder="{{ $placeholder }}"
                                   class="{{ $errors->has($field) ? 'is-invalid' : '' }}">
                            @error($field)<div class="error">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <div class="field">
                        <label for="tax_regime">Income tax regime *</label>
                        <select id="tax_regime" name="tax_regime">
                            @foreach ($regimes as $value => $label)<option value="{{ $value }}" @selected($p('tax_regime') === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <div class="hint">Used to estimate monthly TDS.</div>
                    </div>
                    <div class="field wide">
                        @foreach ([
                            'pf_on_full_basic' => 'PF on full Basic (otherwise capped at the ₹15,000 PF wage ceiling)',
                            'pt_applicable' => 'Deduct Professional Tax',
                        ] as $flag => $label)
                            <input type="hidden" name="{{ $flag }}" value="0">
                            <label class="check"><input type="checkbox" name="{{ $flag }}" value="1" @checked($p($flag))> {{ $label }}</label>
                        @endforeach
                        <div class="hint">PF and ESI are deducted when the salary includes the PF / ESI components. Set them to 0 in a salary revision to stop them.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Bank account</h2></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <label for="bank_name">Bank</label>
                        <input id="bank_name" type="text" name="bank_name" value="{{ $p('bank_name') }}">
                    </div>
                    <div class="field">
                        <label for="bank_account_name">Account holder</label>
                        <input id="bank_account_name" type="text" name="bank_account_name" value="{{ $p('bank_account_name') ?? $staff->full_name }}">
                    </div>
                    <div class="field">
                        <label for="bank_account_number">Account number</label>
                        <input id="bank_account_number" type="text" name="bank_account_number" inputmode="numeric" autocomplete="off" value="{{ old('bank_account_number') }}"
                               placeholder="{{ $profile->masked_account_number ?? '9 to 18 digits' }}" class="{{ $errors->has('bank_account_number') ? 'is-invalid' : '' }}">
                        @if ($profile->masked_account_number)<div class="hint">Saved. Leave empty to keep it.</div>@endif
                        @error('bank_account_number')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="bank_ifsc">IFSC</label>
                        <input id="bank_ifsc" type="text" name="bank_ifsc" value="{{ $p('bank_ifsc') }}" placeholder="SBIN0001234"
                               class="{{ $errors->has('bank_ifsc') ? 'is-invalid' : '' }}">
                        @error('bank_ifsc')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="card card-body actions" style="justify-content:flex-end">
                <button class="btn primary" type="submit">Save statutory & bank details</button>
            </div>
        </form>
    </div>
@endsection
