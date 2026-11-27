@extends('admin.layout')
@section('title', 'Settings')

@section('content')
    @php
        $enforcedRaw = $stored['attendance.geofence_enforced']->value ?? null;
        $enforced = $enforcedRaw === null || filter_var($enforcedRaw, FILTER_VALIDATE_BOOLEAN);
        $radius = $stored['attendance.geofence_radius_meters']->value ?? null;
        $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    @endphp
    <div class="page-head">
        <div>
            <h1>Settings</h1>
            <p>Company-wide rules the mobile app and API follow.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="grid grid-2" style="align-items:start">
            <div class="stack">
                <div class="card">
                    <div class="card-head"><h2>Attendance & WFH</h2></div>
                    <div class="card-body">
                        <div class="field">
                            <label>Work-from-home weekdays</label>
                            <div class="toolbar" style="gap:14px">
                                @foreach ($days as $n => $day)
                                    <label class="check" style="font-weight:400"><input type="checkbox" name="wfh_weekdays[]" value="{{ $n }}" @checked(in_array($n, $wfhDays, true))> {{ $day }}</label>
                                @endforeach
                            </div>
                            <div class="hint">On these days everyone may check in from anywhere (no geofence) and the day is stored as WFH. Approved WFH requests work the same on any day.
                                The app's on-screen "WFH day" notice is built for Wednesday, so if you change this, update the app too.</div>
                        </div>
                        <div class="field">
                            <input type="hidden" name="geofence_enforced" value="0">
                            <label class="check"><input type="checkbox" name="geofence_enforced" value="1" @checked($enforced)> Enforce the office geofence on office days</label>
                            <div class="hint">When on, staff must be inside their branch radius to check in or out. Branches without coordinates are never enforced.</div>
                        </div>
                        <div class="field" style="max-width:260px">
                            <label for="radius">Default geofence radius (metres)</label>
                            <input id="radius" type="number" name="geofence_radius_meters" min="10" max="100000" value="{{ old('geofence_radius_meters', $radius) }}" placeholder="100">
                            <div class="hint">Used when a branch has no radius of its own. Default 100 m.</div>
                        </div>
                    </div>
                </div>

                <div class="card" id="payroll">
                    <div class="card-head"><h2>Payroll</h2></div>
                    <div class="card-body form-grid">
                        <div class="field wide">
                            <label>Weekly off days</label>
                            <div class="toolbar" style="gap:14px">
                                @foreach ($days as $n => $day)
                                    <label class="check" style="font-weight:400"><input type="checkbox" name="weekly_off_days[]" value="{{ $n }}" @checked(in_array($n, $payroll['weekly_off_days'], true))> {{ $day }}</label>
                                @endforeach
                            </div>
                            <div class="hint">Paid days off. Any other day without attendance or approved leave counts as loss of pay.</div>
                        </div>
                        <div class="field">
                            <label for="lop_basis">Daily rate for loss of pay</label>
                            <select id="lop_basis" name="lop_basis">
                                @foreach (\App\Services\PayrollService::LOP_BASES as $value => $label)<option value="{{ $value }}" @selected($payroll['lop_basis'] === $value)>Monthly salary ÷ {{ strtolower($label) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="pt_state">Professional Tax state</label>
                            <select id="pt_state" name="pt_state">
                                @foreach (config('payroll.professional_tax.states') as $code => $state)<option value="{{ $code }}" @selected($payroll['pt_state'] === $code)>{{ $state['name'] }}</option>@endforeach
                                <option value="none" @selected($payroll['pt_state'] === null)>No Professional Tax</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="overtime_multiplier">Overtime pay rate</label>
                            <input id="overtime_multiplier" type="number" name="overtime_multiplier" min="0" max="5" step="0.25" value="{{ old('overtime_multiplier', $payroll['overtime_multiplier']) }}">
                            <div class="hint">× the hourly rate (gross ÷ days ÷ {{ config('payroll.overtime_hours_per_day') }} h). 0 = overtime is not paid; 2 = double rate.</div>
                        </div>
                        <div class="field">
                            <label for="esi_employer_rate">Employer ESI rate (%)</label>
                            <input id="esi_employer_rate" type="number" name="esi_employer_rate" min="0" max="100" step="0.01" value="{{ old('esi_employer_rate', \App\Models\Tax::formatRate($payroll['esi_employer_rate'])) }}">
                            @error('esi_employer_rate')<div class="error">{{ $message }}</div>@enderror
                            <div class="hint">% of the month's earnings and overtime, paid by the company for everyone who has ESI deducted. 0 = none. Not taken from pay.</div>
                        </div>
                        <div class="hint wide">Employee PF and ESI are set as salary components (Organisation → Salary components). PT, pension (EPS) and income-tax rates are kept in config/payroll.php; update them when the rules change.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Invoicing</h2></div>
                <div class="card-body form-grid">
                    @php $logo = app(\App\Services\InvoiceService::class)->logoDataUri(); @endphp
                    <div class="field wide">
                        <label for="company_logo">Organisation logo</label>
                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                            <div class="logo-preview" id="logo-preview">
                                @if ($logo)<img src="{{ $logo }}" alt="Current logo">@else<span class="muted small">No logo</span>@endif
                            </div>
                            <div style="flex:1;min-width:220px">
                                <input id="company_logo" type="file" name="company_logo" accept="image/png,image/jpeg">
                                @if ($logo)
                                    <label class="check" style="font-weight:400;margin-top:8px"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
                                @endif
                                <div class="hint">PNG or JPG, up to 1 MB. A wide logo with a transparent background looks best. Shown above the organisation name on invoices and PDFs.</div>
                                @error('company_logo')<div class="error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    @foreach (\App\Http\Controllers\Admin\SettingController::invoicingKeys() as $key => $label)
                        @php $alias = str_replace('.', '_', $key); $val = old('invoicing.'.$alias, $stored[$key]->value ?? ''); @endphp
                        <div class="field {{ in_array($key, ['company.address', 'invoice.default_terms'], true) ? 'wide' : '' }}">
                            <label for="inv_{{ $alias }}">{{ $label }}</label>
                            @if (in_array($key, ['company.address', 'invoice.default_terms'], true))
                                <textarea id="inv_{{ $alias }}" name="invoicing[{{ $alias }}]" maxlength="2000">{{ $val }}</textarea>
                            @else
                                <input id="inv_{{ $alias }}" type="{{ $key === 'invoice.due_days' ? 'number' : 'text' }}" name="invoicing[{{ $alias }}]" value="{{ $val }}"
                                       placeholder="{{ ['company.name' => config('app.name'), 'invoice.prefix' => 'INV', 'invoice.due_days' => '15'][$key] ?? '' }}">
                            @endif
                        </div>
                    @endforeach
                    <div class="hint wide">Printed at the top of every invoice. Numbers follow GST rules and restart every April, e.g. PREFIX/{{ \App\Services\InvoiceService::financialYear(\Carbon\CarbonImmutable::today()) }}/0001. The prefix can be up to {{ \App\Services\InvoiceService::PREFIX_MAX_LENGTH }} letters or digits.</div>
                </div>
            </div>
        </div>
        <div class="actions" style="justify-content:flex-end;margin-top:16px">
            <button class="btn primary" type="submit">Save settings</button>
        </div>
    </form>
@endsection

@push('styles')
<style>
    .logo-preview { width: 160px; height: 72px; border: 1px dashed var(--border); border-radius: 8px; display: grid; place-items: center; background: #fafbfc; overflow: hidden; }
    .logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
</style>
@endpush

@push('scripts')
<script>
    // Show the chosen logo straight away, before saving.
    document.getElementById('company_logo').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var img = document.createElement('img');
        img.alt = 'New logo';
        img.src = URL.createObjectURL(file);
        document.getElementById('logo-preview').replaceChildren(img);
    });
</script>
@endpush
