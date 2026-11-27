<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Models\StaffPayrollProfile;
use App\Models\StaffSalaryStructure;
use App\Services\SalaryStructureService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * An employee's salary page: statutory & bank details and salary revisions.
 */
class StaffSalaryController extends AdminController
{
    public function __construct(private SalaryStructureService $salaries) {}

    public function show(Request $request, Staff $staff): View
    {
        $staff->load(['payrollProfile', 'salaryStructures.items', 'salaryStructures.creator:id,name']);
        $current = $staff->salaryStructureOn($this->today());
        $components = $this->components($current);

        // Amounts in the "new revision" form: split a gross the admin typed,
        // otherwise start from the current salary.
        $gross = $request->query('gross');
        if (is_numeric($gross) && $gross >= 0) {
            $proposed = $this->salaries->split((float) $gross, $components, (bool) $staff->payrollProfile?->pf_on_full_basic);
        } else {
            $byComponent = $current?->items->pluck('monthly_amount', 'salary_component_id') ?? collect();
            $proposed = $components->mapWithKeys(fn ($c) => [$c->id => (float) ($byComponent[$c->id] ?? 0)])->all();
        }

        return view('admin.staff.salary', [
            'staff' => $staff,
            'profile' => $staff->payrollProfile ?? new StaffPayrollProfile(['tax_regime' => 'new', 'pf_applicable' => true, 'esi_applicable' => true, 'pt_applicable' => true]),
            'current' => $current,
            'components' => $components,
            'proposed' => $proposed,
            'gross' => is_numeric($gross) ? (float) $gross : null,
            'regimes' => StaffPayrollProfile::TAX_REGIMES,
            'payslips' => $staff->payslips()->with('run')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->orderByDesc('payroll_runs.year')->orderByDesc('payroll_runs.month')
                ->select('payslips.*')->limit(24)->get(),
        ]);
    }

    public function updateProfile(Request $request, Staff $staff): RedirectResponse
    {
        $request->merge([
            'pan' => $this->upper($request->input('pan')),
            'bank_ifsc' => $this->upper($request->input('bank_ifsc')),
            'bank_account_number' => preg_replace('/\s+/', '', (string) $request->input('bank_account_number')) ?: null,
        ]);

        $data = $request->validate([
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'uan' => ['nullable', 'digits:12'],
            'pf_number' => ['nullable', 'string', 'max:30'],
            'esi_number' => ['nullable', 'regex:/^\d{10}(\d{7})?$/'],
            'tax_regime' => ['required', Rule::in(array_keys(StaffPayrollProfile::TAX_REGIMES))],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'regex:/^\d{9,18}$/'],
            'bank_ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ], [
            'pan.regex' => 'PAN must look like ABCDE1234F.',
            'esi_number.regex' => 'ESI (IP) number must be 10 or 17 digits.',
            'bank_account_number.regex' => 'Account number must be 9 to 18 digits.',
            'bank_ifsc.regex' => 'IFSC must look like SBIN0001234.',
        ]);

        // PF and ESI are switched on or off by including their salary components.
        foreach (['pf_on_full_basic', 'pt_applicable'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        // An empty account number box keeps the saved number.
        if ($data['bank_account_number'] === null) {
            unset($data['bank_account_number']);
        }

        $profile = $staff->payrollProfile ?? $staff->payrollProfile()->make();
        $before = $profile->exists ? $profile->toArray() : null;
        $profile->fill($data)->save();
        AuditLog::record('staff.payroll_profile_updated', $staff, $before, $profile->fresh()->toArray());

        return redirect()->route('admin.staff.salary', $staff)->with('success', 'Statutory and bank details saved.');
    }

    public function storeStructure(Request $request, Staff $staff): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['bail', 'required', 'date', function (string $attribute, mixed $value, \Closure $fail) use ($staff) {
                if ($staff->salaryStructures()->whereDate('effective_from', Carbon::parse($value)->toDateString())->exists()) {
                    $fail('This employee already has a salary revision starting on that date. Delete it first to replace it.');
                }
            }],
            'amounts' => ['required', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $components = $this->components($staff->salaryStructureOn($this->today()));
        $amounts = array_intersect_key($data['amounts'], $components->keyBy('id')->all());
        $hasEarning = $components->where('type', 'earning')->contains(fn ($c) => (float) ($amounts[$c->id] ?? 0) > 0);
        if (! $hasEarning) {
            throw ValidationException::withMessages(['amounts' => 'Enter at least one earning amount.']);
        }

        $structure = $this->salaries->createRevision($staff, $data['effective_from'], $components, $amounts, $data['notes'] ?? null);
        AuditLog::record('staff.salary_revised', $staff, null, $structure->toArray());

        return redirect()->route('admin.staff.salary', $staff)
            ->with('success', 'Salary revision saved: ₹'.number_format((float) $structure->monthly_gross, 2).' a month from '.$structure->effective_from->format('d M Y').'.');
    }

    public function destroyStructure(Staff $staff, StaffSalaryStructure $salaryStructure): RedirectResponse
    {
        $before = $salaryStructure->load('items')->toArray();
        $salaryStructure->delete();
        AuditLog::record('staff.salary_revision_deleted', $staff, $before);

        return redirect()->route('admin.staff.salary', $staff)->with('success', 'Salary revision deleted.');
    }

    /**
     * Active components, plus any turned-off one still used in the current salary.
     *
     * @return Collection<int, SalaryComponent>
     */
    private function components(?StaffSalaryStructure $current = null): Collection
    {
        $inUse = $current?->items->pluck('salary_component_id')->filter()->all() ?? [];

        return SalaryComponent::where('is_active', true)->orWhereIn('id', $inUse)->ordered()->get();
    }

    private function upper(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
