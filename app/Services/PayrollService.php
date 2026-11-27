<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Staff;
use App\Models\StaffSalaryStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Runs monthly payroll: creates the run, works out every employee's payslip
 * from their salary, attendance and leave, and moves the run from draft to
 * locked to paid.
 */
class PayrollService
{
    public const SETTING_WEEKLY_OFF_DAYS = 'payroll.weekly_off_days';

    public const SETTING_LOP_BASIS = 'payroll.lop_basis';

    public const SETTING_PT_STATE = 'payroll.pt_state';

    public const SETTING_OVERTIME_MULTIPLIER = 'payroll.overtime_multiplier';

    public const SETTING_ESI_EMPLOYER_RATE = 'payroll.esi_employer_rate';

    public const DEFAULT_WEEKLY_OFF_DAYS = '7';

    public const LOP_BASES = ['calendar' => 'Calendar days in the month', 'fixed_30' => 'Fixed 30 days'];

    /** Employees paid by a run. Resigned / terminated staff are settled outside payroll for now. */
    public const PAYABLE_STATUSES = ['active', 'probation'];

    public function __construct(
        private PayDaysCalculator $payDays,
        private PayrollCalculator $calculator,
    ) {}

    public function createRun(int $year, int $month, CarbonImmutable $today): PayrollRun
    {
        $start = CarbonImmutable::create($year, $month, 1);

        $run = PayrollRun::create([
            'year' => $year,
            'month' => $month,
            'period_start' => $start,
            'period_end' => $start->endOfMonth()->startOfDay(),
            'status' => 'draft',
        ]);

        return $this->process($run, $today);
    }

    /**
     * (Re)calculate the payslips of a draft run. Admin LOP, PF and ESI
     * overrides and adjustments are kept. Pass staff ids to redo only those payslips.
     *
     * @param  list<int>|null  $staffIds
     */
    public function process(PayrollRun $run, CarbonImmutable $today, ?array $staffIds = null): PayrollRun
    {
        $this->ensureDraft($run);

        DB::transaction(function () use ($run, $today, $staffIds) {
            $start = $run->period_start;
            $end = $run->period_end;

            $staff = Staff::with(['payrollProfile', 'designation:id,title', 'department:id,name'])
                ->whereIn('employment_status', self::PAYABLE_STATUSES)
                ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $end->toDateString()))
                ->when($staffIds !== null, fn ($q) => $q->whereIn('id', $staffIds))
                ->orderBy('first_name')->get();
            $ids = $staff->pluck('id');

            $existing = $run->payslips()->when($staffIds !== null, fn ($q) => $q->whereIn('staff_id', $staffIds))->get();
            $overrides = $existing->whereNotNull('lop_days_override')->pluck('lop_days_override', 'staff_id');
            $pfOverrides = $existing->whereNotNull('employee_pf_override')->pluck('employee_pf_override', 'staff_id');
            $esiOverrides = $existing->whereNotNull('employee_esi_override')->pluck('employee_esi_override', 'staff_id');
            $run->payslips()->whereIn('id', $existing->pluck('id'))->delete();

            $structures = StaffSalaryStructure::with('items')->whereIn('staff_id', $ids)
                ->whereDate('effective_from', '<=', $end->toDateString())
                ->orderByDesc('effective_from')->get()->unique('staff_id')->keyBy('staff_id');
            $attendance = Attendance::whereIn('staff_id', $ids)
                ->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())
                ->get()->groupBy('staff_id');
            $leaves = LeaveRequest::with('leaveType:id,paid')->whereIn('staff_id', $ids)->where('status', 'approved')
                ->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $start->toDateString())
                ->get()->groupBy('staff_id');
            $holidays = Holiday::whereDate('holiday_date', '>=', $start->toDateString())->whereDate('holiday_date', '<=', $end->toDateString())->get();
            $adjustments = $run->adjustments()->whereIn('staff_id', $ids)->get()->groupBy('staff_id');
            $yearToDate = $this->yearToDate($run, $ids->all());

            $weeklyOffs = $this->weeklyOffDays();
            $fixed30 = $this->lopBasis() === 'fixed_30';
            $periodDays = $fixed30 ? 30 : $start->daysInMonth;
            $ptState = $this->ptState();
            $overtimeMultiplier = $this->overtimeMultiplier();
            $esiEmployerRate = $this->esiEmployerRate();
            $monthsLeft = 12 - (int) $run->financialYearStart()->diffInMonths($start);

            // A full recalculation rebuilds the list; redoing a few payslips only replaces their entries.
            $skipped = $staffIds === null ? collect() : collect($run->skipped ?? [])->whereNotIn('staff_id', $staffIds);

            foreach ($staff as $employee) {
                $structure = $structures->get($employee->id);
                if (! $structure || (float) $structure->monthly_gross <= 0) {
                    $skipped->push(['staff_id' => $employee->id, 'name' => $employee->name_with_code, 'reason' => 'No salary set']);

                    continue;
                }

                $holidayDates = $holidays->filter(fn (Holiday $h) => $h->branch_id === null || (int) $h->branch_id === (int) $employee->branch_id)
                    ->map(fn (Holiday $h) => $h->holiday_date->toDateString())->values()->all();
                $days = $this->payDays->calculate($employee, $start, $end, $attendance->get($employee->id, collect()),
                    $leaves->get($employee->id, collect()), $holidayDates, $weeklyOffs, $today);

                // On a 30-day basis a 31-day month can't cost more than 30 days; February gets none extra.
                $calculatedLop = min($days['lop_days'], $periodDays);
                $override = $overrides->get($employee->id);
                $lop = $override !== null ? min((float) $override, $periodDays) : $calculatedLop;

                $pfOverride = $pfOverrides->get($employee->id);
                $esiOverride = $esiOverrides->get($employee->id);

                $result = $this->calculator->calculate($structure, $employee->payrollProfile, $periodDays, $lop,
                    $days['overtime_minutes'], $overtimeMultiplier, $adjustments->get($employee->id, collect()),
                    $ptState, $yearToDate[$employee->id] ?? ['taxable' => 0.0, 'tds' => 0.0, 'pf' => 0.0, 'pt' => 0.0], $monthsLeft,
                    $pfOverride !== null ? (float) $pfOverride : null, $esiOverride !== null ? (float) $esiOverride : null, $esiEmployerRate);

                $payslip = $run->payslips()->create([
                    'staff_id' => $employee->id,
                    'staff_salary_structure_id' => $structure->id,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => $employee->full_name,
                    'designation' => $employee->designation?->title ?? $employee->position,
                    'department' => $employee->department?->name,
                    'pan' => $employee->payrollProfile?->pan,
                    'uan' => $employee->payrollProfile?->uan,
                    'tax_regime' => $employee->payrollProfile?->tax_regime ?? 'new',
                    'period_days' => $periodDays,
                    'lop_days_calculated' => $calculatedLop,
                    'lop_days_override' => $override,
                    'employee_pf_override' => $pfOverride,
                    'employee_esi_override' => $esiOverride,
                    'lop_days' => $lop,
                    'paid_days' => max($periodDays - $lop, 0),
                    'day_counts' => collect($days)->except(['lop_days', 'overtime_minutes'])->all(),
                    'overtime_minutes' => $days['overtime_minutes'],
                ] + collect($result)->except('items')->all());
                $payslip->items()->createMany($result['items']);
            }

            $this->refreshTotals($run, $skipped->values()->all());
        });

        return $run->refresh();
    }

    public function lock(PayrollRun $run): void
    {
        $this->ensureDraft($run);
        if (! $run->payslips()->exists()) {
            throw new LogicException('There are no payslips to lock.');
        }
        $run->update(['status' => 'locked', 'locked_at' => now(), 'locked_by' => auth()->id()]);
    }

    public function reopen(PayrollRun $run): void
    {
        if ($run->status !== 'locked') {
            throw new LogicException('Only a locked payroll can be reopened.');
        }
        $run->update(['status' => 'draft', 'locked_at' => null, 'locked_by' => null]);
    }

    public function markPaid(PayrollRun $run, string $paidOn, ?string $reference): void
    {
        if ($run->status !== 'locked') {
            throw new LogicException('Lock the payroll before marking it paid.');
        }
        $run->update(['status' => 'paid', 'paid_on' => $paidOn, 'payment_reference' => $reference]);
    }

    public function ensureDraft(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw new LogicException("The {$run->label} payroll is {$run->status}; reopen it to make changes.");
        }
    }

    /** @return list<int> ISO weekdays that are weekly offs */
    public function weeklyOffDays(): array
    {
        // A saved empty value means "no weekly offs"; only a missing setting falls back to Sunday.
        $raw = (string) (CompanySetting::where('key', self::SETTING_WEEKLY_OFF_DAYS)->value('value') ?? self::DEFAULT_WEEKLY_OFF_DAYS);

        return array_values(array_map('intval', array_filter(explode(',', $raw), 'strlen')));
    }

    public function lopBasis(): string
    {
        $basis = $this->setting(self::SETTING_LOP_BASIS);

        return array_key_exists((string) $basis, self::LOP_BASES) ? $basis : 'calendar';
    }

    /** State code for Professional Tax slabs, or null when PT is not deducted. */
    public function ptState(): ?string
    {
        $state = $this->setting(self::SETTING_PT_STATE) ?? config('payroll.professional_tax.default_state');

        return $state === 'none' ? null : $state;
    }

    /** 0 = overtime is not paid. */
    public function overtimeMultiplier(): float
    {
        return max((float) ($this->setting(self::SETTING_OVERTIME_MULTIPLIER) ?? 0), 0);
    }

    /** Employer ESI, % of earnings; until it is saved in settings, the rate in config/payroll.php. */
    public function esiEmployerRate(): float
    {
        return max((float) ($this->setting(self::SETTING_ESI_EMPLOYER_RATE) ?? config('payroll.esi.employer_rate')), 0);
    }

    /**
     * Totals from this financial year's earlier payslips, for TDS.
     *
     * @param  list<int>  $staffIds
     * @return array<int, array{taxable: float, tds: float, pf: float, pt: float}>
     */
    private function yearToDate(PayrollRun $run, array $staffIds): array
    {
        $fyStart = $run->financialYearStart();
        $key = fn (int $year, int $month) => $year * 100 + $month;

        $runIds = PayrollRun::query()->get(['id', 'year', 'month'])
            ->filter(fn (PayrollRun $r) => $key($r->year, $r->month) >= $key($fyStart->year, $fyStart->month)
                && $key($r->year, $r->month) < $key($run->year, $run->month))
            ->pluck('id');

        return Payslip::whereIn('payroll_run_id', $runIds)->whereIn('staff_id', $staffIds)
            ->selectRaw('staff_id, SUM(taxable_earnings) AS taxable, SUM(tds) AS tds, SUM(employee_pf) AS pf, SUM(professional_tax) AS pt')
            ->groupBy('staff_id')->get()
            ->mapWithKeys(fn ($row) => [$row->staff_id => [
                'taxable' => (float) $row->taxable, 'tds' => (float) $row->tds, 'pf' => (float) $row->pf, 'pt' => (float) $row->pt,
            ]])->all();
    }

    /** @param  list<array{staff_id: int, name: string, reason: string}>  $skipped */
    private function refreshTotals(PayrollRun $run, array $skipped): void
    {
        /** @var Collection<int, Payslip> $payslips */
        $payslips = $run->payslips()->get(['gross_earnings', 'total_deductions', 'net_pay', 'employer_pf', 'employer_esi']);

        $run->update([
            'employee_count' => $payslips->count(),
            'total_gross' => round($payslips->sum('gross_earnings'), 2),
            'total_deductions' => round($payslips->sum('total_deductions'), 2),
            'total_net' => round($payslips->sum('net_pay'), 2),
            'total_employer_contributions' => round($payslips->sum(fn (Payslip $p) => (float) $p->employer_pf + (float) $p->employer_esi), 2),
            'skipped' => $skipped,
            'processed_at' => now(),
            'processed_by' => auth()->id(),
        ]);
    }

    private function setting(string $key): ?string
    {
        $value = CompanySetting::where('key', $key)->value('value');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
