<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollAdjustment;
use App\Models\Staff;
use App\Models\StaffPayrollProfile;
use App\Models\StaffSalaryStructure;
use App\Models\StaffSalaryStructureItem;
use App\Services\IncomeTaxCalculator;
use App\Services\PayDaysCalculator;
use App\Services\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private const NO_YTD = ['taxable' => 0.0, 'tds' => 0.0, 'pf' => 0.0, 'pt' => 0.0];

    /**
     * @param  array<string, float>  $earnings  code => monthly amount
     * @param  array<string, float>  $deductions  code => monthly amount (PF, ESI, ...), cut for LOP
     */
    private function structure(array $earnings, array $deductions = []): StaffSalaryStructure
    {
        $structure = new StaffSalaryStructure(['monthly_gross' => array_sum($earnings)]);
        $items = collect($earnings)->map(fn ($amount, $code) => new StaffSalaryStructureItem([
            'code' => $code, 'name' => $code, 'type' => 'earning', 'monthly_amount' => $amount,
            'is_taxable' => true, 'is_prorated' => true, 'sort_order' => 0,
        ]))->values();
        foreach ($deductions as $code => $amount) {
            $items->push(new StaffSalaryStructureItem([
                'code' => $code, 'name' => $code === 'PF' ? 'Provident Fund' : $code, 'type' => 'deduction', 'monthly_amount' => $amount,
                'is_taxable' => false, 'is_prorated' => true, 'sort_order' => 0,
            ]));
        }
        $structure->setRelation('items', new EloquentCollection($items));

        return $structure;
    }

    /** @return array<string, mixed> */
    private function calculate(StaffSalaryStructure $structure, float $lopDays = 0, int $periodDays = 30, array $ytd = self::NO_YTD, int $monthsLeft = 12, ?StaffPayrollProfile $profile = null, array $adjustments = [], int $overtimeMinutes = 0, float $overtimeMultiplier = 0, float $esiEmployerRate = 0, ?float $esiOverride = null): array
    {
        return app(PayrollCalculator::class)->calculate($structure, $profile, $periodDays, $lopDays, $overtimeMinutes,
            $overtimeMultiplier, collect($adjustments), 'WB', $ytd, $monthsLeft, null, $esiOverride, $esiEmployerRate);
    }

    public function test_pf_from_the_salary_is_deducted_and_matched_by_the_employer(): void
    {
        $result = $this->calculate($this->structure(['BASIC' => 15000, 'HRA' => 6000, 'SPECIAL' => 9000], ['PF' => 1800]));

        $this->assertSame(30000.0, $result['gross_earnings']);
        $this->assertSame(1800.0, $result['employee_pf']);
        $this->assertSame(1800.0, $result['employer_pf']);
        $this->assertSame(1250.0, $result['employer_eps']);
        $this->assertSame(15000.0, $result['pf_wages']);
        $this->assertSame(0.0, $result['employee_esi']);
        $this->assertSame(150.0, $result['professional_tax']);
        $this->assertSame(0.0, $result['tds']);
        $this->assertSame(28050.0, $result['net_pay']);
    }

    public function test_lop_days_cut_earnings_pf_and_esi(): void
    {
        $result = $this->calculate($this->structure(['BASIC' => 10000, 'HRA' => 4000, 'SPECIAL' => 6000], ['PF' => 1200, 'ESI' => 150]), lopDays: 3);

        $this->assertSame(18000.0, $result['gross_earnings']);
        $this->assertSame(1080.0, $result['employee_pf']);
        $this->assertSame(135.0, $result['employee_esi']);
        $this->assertSame(0.0, $result['employer_esi']);
        $this->assertSame(130.0, $result['professional_tax']);
        $this->assertSame(16655.0, $result['net_pay']);
    }

    public function test_employer_esi_is_its_rate_on_earnings_and_overtime_for_those_who_pay_esi(): void
    {
        $bonus = new PayrollAdjustment(['type' => 'earning', 'name' => 'Bonus', 'amount' => 5000, 'is_taxable' => true]);
        $structure = $this->structure(['BASIC' => 10000, 'HRA' => 4000, 'SPECIAL' => 6000], ['ESI' => 150]);

        // 18,000 earned (3 LOP days) + 400 overtime; the bonus is left out. 3.25% of 18,400 = 598.
        $result = $this->calculate($structure, lopDays: 3, adjustments: [$bonus], overtimeMinutes: 288, overtimeMultiplier: 1, esiEmployerRate: 3.25);
        $this->assertSame(135.0, $result['employee_esi']);
        $this->assertSame(598.0, $result['employer_esi']);

        $this->assertSame(0.0, $this->calculate($this->structure(['BASIC' => 10000, 'SPECIAL' => 10000]), esiEmployerRate: 3.25)['employer_esi']);
        $this->assertSame(0.0, $this->calculate($structure, esiEmployerRate: 3.25, esiOverride: 0)['employer_esi']);
        $this->assertSame(0.0, $this->calculate($structure)['employer_esi']);
    }

    public function test_pf_and_esi_are_the_amounts_on_the_salary_whatever_the_old_limits(): void
    {
        // A changed rate (10% of Basic) and ESI above the old ₹21,000 limit are deducted as set.
        $structure = $this->structure(['BASIC' => 40000, 'SPECIAL' => 10000], ['PF' => 4000, 'ESI' => 375]);
        $profile = new StaffPayrollProfile(['pf_on_full_basic' => true, 'pt_applicable' => false, 'tax_regime' => 'new']);

        $result = $this->calculate($structure, profile: $profile);
        $this->assertSame(4000.0, $result['employee_pf']);
        $this->assertSame(4000.0, $result['employer_pf']);
        $this->assertSame(1250.0, $result['employer_eps']);
        $this->assertSame(40000.0, $result['pf_wages']);
        $this->assertSame(375.0, $result['employee_esi']);
        $this->assertSame(1, count(array_keys(array_column($result['items'], 'code'), 'PF')));
        $this->assertSame(4375.0, $result['total_deductions']);
    }

    public function test_no_pf_or_esi_without_their_components(): void
    {
        $result = $this->calculate($this->structure(['BASIC' => 10000, 'HRA' => 4000, 'SPECIAL' => 6000]));

        $this->assertSame(0.0, $result['employee_pf']);
        $this->assertSame(0.0, $result['employer_pf']);
        $this->assertSame(0.0, $result['pf_wages']);
        $this->assertSame(0.0, $result['employee_esi']);
        $this->assertSame(['BASIC', 'HRA', 'SPECIAL', 'PT'], array_column($result['items'], 'code'));
    }

    public function test_tds_spreads_the_projected_annual_tax_over_the_months_left(): void
    {
        $structure = $this->structure(['BASIC' => 100000, 'HRA' => 40000, 'SPECIAL' => 60000]);

        // April: 24 lakh a year -> ₹2,92,500 tax, spread over 12 months.
        $this->assertSame(24375.0, $this->calculate($structure)['tds']);

        // October, after 6 months paid with ₹1,00,000 TDS already deducted.
        $ytd = ['taxable' => 1200000.0, 'tds' => 100000.0, 'pf' => 10800.0, 'pt' => 1200.0];
        $this->assertSame(32083.0, $this->calculate($structure, ytd: $ytd, monthsLeft: 6)['tds']);
    }

    public function test_new_regime_tax_with_rebate_and_marginal_relief(): void
    {
        $tax = app(IncomeTaxCalculator::class);

        $this->assertSame(292500.0, $tax->annualTax(2400000, 'new'));
        $this->assertSame(0.0, $tax->annualTax(1275000, 'new'));      // 12 lakh taxable after standard deduction
        $this->assertSame(10400.0, $tax->annualTax(1285000, 'new'));   // marginal relief: tax limited to the ₹10,000 over 12 lakh
    }

    public function test_old_regime_allows_pf_and_pt_deductions(): void
    {
        $this->assertSame(101608.0, app(IncomeTaxCalculator::class)->annualTax(1000000, 'old', 21600, 2400));
        $this->assertSame(0.0, app(IncomeTaxCalculator::class)->annualTax(550000, 'old'));
    }

    public function test_overtime_and_adjustments_are_added(): void
    {
        $bonus = new PayrollAdjustment(['type' => 'earning', 'name' => 'Diwali bonus', 'amount' => 5000, 'is_taxable' => true]);
        $advance = new PayrollAdjustment(['type' => 'deduction', 'name' => 'Advance recovery', 'amount' => 2000]);

        $result = $this->calculate($this->structure(['BASIC' => 15000, 'SPECIAL' => 15000], ['PF' => 1800]),
            adjustments: [$bonus, $advance], overtimeMinutes: 120, overtimeMultiplier: 2);

        $this->assertSame(35500.0, $result['gross_earnings']);  // 30,000 + 500 overtime + 5,000 bonus
        $this->assertSame(['BASIC', 'SPECIAL', 'OT', 'ADJ', 'PF', 'PT', 'ADJ'], array_column($result['items'], 'code'));
        $this->assertSame(31550.0, $result['net_pay']);  // − PF 1,800 − PT 150 − advance 2,000
    }

    public function test_pay_days_from_attendance_leave_holidays_and_weekly_offs(): void
    {
        // November 2026: Sundays are the 1st, 8th, 15th, 22nd and 29th.
        $start = CarbonImmutable::create(2026, 11, 1);
        $end = $start->endOfMonth()->startOfDay();
        $skip = ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-24'];

        $attendance = collect();
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            if ($d->isSunday() || in_array($d->toDateString(), $skip, true)) {
                continue;
            }
            $attendance->push(new Attendance(['work_date' => $d, 'status' => $d->day === 14 ? 'half_day' : 'present', 'overtime_minutes' => 10]));
        }
        $leaves = collect([
            $this->leave('2026-11-11', '2026-11-12', paid: true),
            $this->leave('2026-11-13', '2026-11-13', paid: false),
        ]);

        $days = app(PayDaysCalculator::class)->calculate(new Staff, $start, $end, $attendance, $leaves,
            ['2026-11-24'], [7], CarbonImmutable::create(2026, 12, 5));

        $this->assertEquals(19.5, $days['present']);
        $this->assertEquals(5, $days['weekly_offs']);
        $this->assertEquals(1, $days['holidays']);
        $this->assertEquals(2, $days['paid_leave']);
        $this->assertEquals(1, $days['unpaid_leave']);
        $this->assertEquals(1.5, $days['absent']);   // the 10th plus half of the 14th
        $this->assertEquals(2.5, $days['lop_days']);
        $this->assertSame(200, $days['overtime_minutes']);
    }

    public function test_days_before_joining_are_unpaid_and_future_days_are_paid(): void
    {
        $start = CarbonImmutable::create(2026, 11, 1);
        $end = $start->endOfMonth()->startOfDay();
        $staff = new Staff(['joining_date' => '2026-11-16']);

        $days = app(PayDaysCalculator::class)->calculate($staff, $start, $end, collect(), collect(), [], [7], CarbonImmutable::create(2026, 11, 15));

        $this->assertEquals(15, $days['not_employed']);
        $this->assertEquals(2, $days['weekly_offs']);
        $this->assertEquals(13, $days['upcoming']);
        $this->assertEquals(15, $days['lop_days']);
    }

    private function leave(string $from, string $to, bool $paid): LeaveRequest
    {
        $leave = new LeaveRequest(['from_date' => $from, 'to_date' => $to, 'partial_day' => false, 'status' => 'approved']);
        $leave->setRelation('leaveType', new LeaveType(['paid' => $paid]));

        return $leave;
    }
}
