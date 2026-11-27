<?php

namespace App\Services;

use App\Models\PayrollAdjustment;
use App\Models\SalaryComponent;
use App\Models\StaffPayrollProfile;
use App\Models\StaffSalaryStructure;
use App\Models\StaffSalaryStructureItem;
use Illuminate\Support\Collection;

/**
 * Works out one employee's payslip for a month: earnings cut for LOP days,
 * overtime, one-off adjustments, then PF and ESI (the amounts on the salary),
 * Professional Tax and TDS.
 * Pure arithmetic: everything it needs is passed in.
 */
class PayrollCalculator
{
    public function __construct(private IncomeTaxCalculator $incomeTax) {}

    /**
     * @param  Collection<int, PayrollAdjustment>  $adjustments
     * @param  array{taxable: float, tds: float, pf: float, pt: float}  $yearToDate  earlier payslips in this financial year
     * @param  int  $monthsLeft  months left in the financial year, including this one (April = 12, March = 1)
     * @param  float|null  $employeePfOverride  employee PF set by an admin; employer PF and PF wages stay as on the salary
     * @param  float|null  $employeeEsiOverride  employee ESI set by an admin
     * @param  float  $esiEmployerRate  employer ESI, % of the month's salary earnings and overtime, for employees who pay ESI
     * @return array{items: list<array{code: string, name: string, type: string, source: string, amount: float, sort_order: int}>, gross_earnings: float, taxable_earnings: float, employee_pf: float, pf_wages: float, eps_wages: float, employee_esi: float, professional_tax: float, tds: float, total_deductions: float, net_pay: float, employer_pf: float, employer_eps: float, employer_esi: float}
     */
    public function calculate(
        StaffSalaryStructure $structure,
        ?StaffPayrollProfile $profile,
        int $periodDays,
        float $lopDays,
        int $overtimeMinutes,
        float $overtimeMultiplier,
        Collection $adjustments,
        ?string $ptState,
        array $yearToDate,
        int $monthsLeft,
        ?float $employeePfOverride = null,
        ?float $employeeEsiOverride = null,
        float $esiEmployerRate = 0.0,
    ): array {
        $profile ??= new StaffPayrollProfile(['pf_applicable' => true, 'pf_on_full_basic' => false, 'esi_applicable' => true, 'pt_applicable' => true, 'tax_regime' => 'new']);
        $ratio = $periodDays > 0 ? max(min(($periodDays - $lopDays) / $periodDays, 1), 0) : 0;

        $items = [];
        $taxable = 0.0;
        $earnedBasic = 0.0;
        $fullMonthTaxable = 0.0;

        foreach ($structure->items as $item) {
            if ($item->type !== 'earning') {
                continue;
            }
            $monthly = (float) $item->monthly_amount;
            $amount = $item->is_prorated ? round($monthly * $ratio) : $monthly;
            $items[] = $this->line($item->code, $item->name, 'earning', 'salary', $amount, $item->sort_order);
            if ($item->is_taxable) {
                $taxable += $amount;
                $fullMonthTaxable += $monthly;
            }
            if (strtoupper($item->code) === SalaryComponent::BASIC_CODE) {
                $earnedBasic += $amount;
            }
        }

        if ($overtimeMultiplier > 0 && $overtimeMinutes > 0 && $periodDays > 0) {
            $hourly = (float) $structure->monthly_gross / $periodDays / max((int) config('payroll.overtime_hours_per_day', 8), 1);
            $overtime = round($hourly * $overtimeMinutes / 60 * $overtimeMultiplier);
            $items[] = $this->line('OT', 'Overtime', 'earning', 'overtime', $overtime, 900);
            $taxable += $overtime;
        }

        // ESI wages: salary and overtime, not one-off adjustments.
        $esiWages = array_sum(array_column($items, 'amount'));

        foreach ($adjustments->where('type', 'earning') as $adjustment) {
            $items[] = $this->line('ADJ', $adjustment->name, 'earning', 'adjustment', (float) $adjustment->amount, 950);
            if ($adjustment->is_taxable) {
                $taxable += (float) $adjustment->amount;
            }
        }

        $gross = round(array_sum(array_column($items, 'amount')), 2);

        // PF and ESI are the amounts set on the salary (PF / ESI components), cut for LOP like the earnings.
        $pfItem = $this->statutoryItem($structure, SalaryComponent::PF_CODE);
        $esiItem = $this->statutoryItem($structure, SalaryComponent::ESI_CODE);

        $pf = ['employee' => 0.0, 'employer' => 0.0, 'eps' => 0.0, 'wages' => 0.0, 'eps_wages' => 0.0];
        if ($pfItem) {
            $pf = $this->providentFund($this->earnedDeduction($pfItem, $ratio), $earnedBasic, $profile->pf_on_full_basic);
        }
        if ($employeePfOverride !== null) {
            $pf['employee'] = round($employeePfOverride, 2);
        }

        $esi = ['employee' => $esiItem ? $this->earnedDeduction($esiItem, $ratio) : 0.0, 'employer' => 0.0];
        if ($employeeEsiOverride !== null) {
            $esi['employee'] = round($employeeEsiOverride, 2);
        }
        // The employer pays its own rate for everyone who has ESI deducted this month.
        if ($esi['employee'] > 0 && $esiEmployerRate > 0) {
            $esi['employer'] = ceil($esiWages * $esiEmployerRate / 100);
        }

        $pt = $profile->pt_applicable ? $this->professionalTax($gross, $ptState) : 0.0;

        // TDS: project the year's taxable salary, then spread the tax still due over the months left.
        $monthsLeft = max($monthsLeft, 1);
        $futureMonths = $monthsLeft - 1;
        $futurePf = $pfItem ? (float) $pfItem->monthly_amount : 0.0;
        $futurePt = $profile->pt_applicable ? $this->professionalTax((float) $structure->monthly_gross, $ptState) : 0.0;
        $annualTax = $this->incomeTax->annualTax(
            $yearToDate['taxable'] + $taxable + $futureMonths * $fullMonthTaxable,
            $profile->tax_regime ?: 'new',
            $yearToDate['pf'] + $pf['employee'] + $futureMonths * $futurePf,
            $yearToDate['pt'] + $pt + $futureMonths * $futurePt,
        );
        $tds = max(round(($annualTax - $yearToDate['tds']) / $monthsLeft), 0);

        $statutory = [
            [SalaryComponent::PF_CODE, $pfItem->name ?? 'Provident Fund', $pf['employee'], 1],
            [SalaryComponent::ESI_CODE, $esiItem->name ?? 'ESI', $esi['employee'], 2],
            ['PT', 'Professional Tax', $pt, 3],
            ['TDS', 'Income Tax (TDS)', $tds, 4],
        ];
        foreach ($statutory as [$code, $name, $amount, $order]) {
            if ($amount > 0) {
                $items[] = $this->line($code, $name, 'deduction', 'statutory', $amount, $order);
            }
        }
        foreach ($structure->items->where('type', 'deduction') as $item) {
            if ($item === $pfItem || $item === $esiItem) {
                continue;
            }
            $items[] = $this->line($item->code, $item->name, 'deduction', 'salary', (float) $item->monthly_amount, 10 + $item->sort_order);
        }
        foreach ($adjustments->where('type', 'deduction') as $adjustment) {
            $items[] = $this->line('ADJ', $adjustment->name, 'deduction', 'adjustment', (float) $adjustment->amount, 950);
        }

        $deductions = round(array_sum(array_column(array_filter($items, fn ($i) => $i['type'] === 'deduction'), 'amount')), 2);

        return [
            'items' => $items,
            'gross_earnings' => $gross,
            'taxable_earnings' => round($taxable, 2),
            'employee_pf' => $pf['employee'],
            'pf_wages' => $pf['wages'],
            'eps_wages' => $pf['eps_wages'],
            'employee_esi' => $esi['employee'],
            'professional_tax' => $pt,
            'tds' => $tds,
            'total_deductions' => $deductions,
            'net_pay' => round($gross - $deductions, 2),
            'employer_pf' => $pf['employer'],
            'employer_eps' => $pf['eps'],
            'employer_esi' => $esi['employer'],
        ];
    }

    /**
     * PF for the ECR file from the employee's PF amount. The employer pays
     * the same; its pension (EPS) part is on the wage ceiling at most and
     * never more than the employer's share.
     *
     * @return array{employee: float, employer: float, eps: float, wages: float, eps_wages: float}
     */
    public function providentFund(float $employeePf, float $earnedBasic, bool $onFullBasic): array
    {
        if ($employeePf <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0, 'eps' => 0.0, 'wages' => 0.0, 'eps_wages' => 0.0];
        }
        $ceiling = (float) config('payroll.pf.wage_ceiling');
        $epsWage = round(min($earnedBasic, $ceiling));

        return [
            'employee' => $employeePf,
            'employer' => $employeePf,
            'eps' => min(round($epsWage * config('payroll.pf.eps_rate') / 100), $employeePf),
            'wages' => round($onFullBasic ? $earnedBasic : min($earnedBasic, $ceiling)),
            'eps_wages' => $epsWage,
        ];
    }

    /** A salary deduction for the month: cut for LOP days when the component says so. */
    private function earnedDeduction(StaffSalaryStructureItem $item, float $ratio): float
    {
        $monthly = (float) $item->monthly_amount;

        return $item->is_prorated ? round($monthly * $ratio) : $monthly;
    }

    /** The salary's PF or ESI deduction component, if it has one. */
    private function statutoryItem(StaffSalaryStructure $structure, string $code): ?StaffSalaryStructureItem
    {
        return $structure->items->first(fn (StaffSalaryStructureItem $item) => $item->type === 'deduction'
            && strtoupper($item->code) === $code && (float) $item->monthly_amount > 0);
    }

    public function professionalTax(float $gross, ?string $state): float
    {
        $slabs = config("payroll.professional_tax.states.{$state}.slabs");
        if (! $state || ! $slabs || $gross <= 0) {
            return 0.0;
        }
        foreach ($slabs as [$upper, $amount]) {
            if ($upper === null || $gross <= $upper) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    /** @return array{code: string, name: string, type: string, source: string, amount: float, sort_order: int} */
    private function line(string $code, string $name, string $type, string $source, float $amount, int $sortOrder): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'source' => $source, 'amount' => round($amount, 2), 'sort_order' => $sortOrder];
    }
}
