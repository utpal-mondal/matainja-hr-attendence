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
     * @param  list<string>  $statutory  PF / ESI components the salary includes
     */
    private function structure(array $earnings, array $statutory = ['PF', 'ESI']): StaffSalaryStructure
    {
        $structure = new StaffSalaryStructure(['monthly_gross' => array_sum($earnings)]);
        $items = collect($earnings)->map(fn ($amount, $code) => new StaffSalaryStructureItem([
            'code' => $code, 'name' => $code, 'type' => 'earning', 'monthly_amount' => $amount,
            'is_taxable' => true, 'is_prorated' => true, 'sort_order' => 0,
        ]))->values();
        foreach ($statutory as $code) {
            // The stored amount is only the full-month estimate; payroll works it out again.
            $items->push(new StaffSalaryStructureItem([
                'code' => $code, 'name' => $code === 'PF' ? 'Provident Fund' : 'ESI', 'type' => 'deduction', 'monthly_amount' => 1,
                'is_taxable' => false, 'is_prorated' => false, 'sort_order' => 0,
            ]));
        }
        $structure->setRelation('items', new EloquentCollection($items));

        return $structure;
    }

    /** @return array<string, mixed> */
    private function calculate(StaffSalaryStructure $structure, float $lopDays = 0, int $periodDays = 30, array $ytd = self::NO_YTD, int $monthsLeft = 12, ?StaffPayrollProfile $profile = null, array $adjustments = [], int $overtimeMinutes = 0, float $overtimeMultiplier = 0): array
    {
        return app(PayrollCalculator::class)->calculate($structure, $profile, $periodDays, $lopDays, $overtimeMinutes,
            $overtimeMultiplier, collect($adjustments), 'WB', $ytd, $monthsLeft);
    }

    public function test_full_month_above_esi_limit_deducts_capped_pf_and_pt(): void
    {
        $result = $this->calculate($this->structure(['BASIC' => 15000, 'HRA' => 6000, 'SPECIAL' => 9000]));

        $this->assertSame(30000.0, $result['gross_earnings']);
        $this->assertSame(1800.0, $result['employee_pf']);
        $this->assertSame(1800.0, $result['employer_pf']);
        $this->assertSame(1250.0, $result['employer_eps']);
        $this->assertSame(0.0, $result['employee_esi']);
        $this->assertSame(150.0, $result['professional_tax']);
        $this->assertSame(0.0, $result['tds']);
        $this->assertSame(28050.0, $result['net_pay']);
    }

    public function test_lop_days_cut_earnings_and_esi_applies_within_the_limit(): void
    {
        $result = $this->calculate($this->structure(['BASIC' => 10000, 'HRA' => 4000, 'SPECIAL' => 6000]), lopDays: 3);

        $this->assertSame(18000.0, $result['gross_earnings']);
        $this->assertSame(1080.0, $result['employee_pf']);
        $this->assertSame(135.0, $result['employee_esi']);
        $this->assertSame(585.0, $result['employer_esi']);
        $this->assertSame(130.0, $result['professional_tax']);
        $this->assertSame(16655.0, $result['net_pay']);
    }

    public function test_pf_on_full_basic_and_opt_outs(): void
    {
        $structure = $this->structure(['BASIC' => 40000, 'SPECIAL' => 10000]);
        $profile = new StaffPayrollProfile(['pf_applicable' => true, 'pf_on_full_basic' => true, 'esi_applicable' => true, 'pt_applicable' => false, 'tax_regime' => 'new']);

        $result = $this->calculate($structure, profile: $profile);
        $this->assertSame(4800.0, $result['employee_pf']);
        $this->assertSame(1250.0, $result['employer_eps']);
        $this->assertSame(0.0, $result['professional_tax']);

    }

    public function test_pf_and_esi_are_only_deducted_when_the_salary_includes_their_components(): void
    {
        $earnings = ['BASIC' => 10000, 'HRA' => 4000, 'SPECIAL' => 6000];

        $none = $this->calculate($this->structure($earnings, statutory: []));
        $this->assertSame(0.0, $none['employee_pf']);
        $this->assertSame(0.0, $none['employer_pf']);
        $this->assertSame(0.0, $none['employee_esi']);
        $this->assertSame(0.0, $none['employer_esi']);
        $this->assertNotContains('PF', array_column($none['items'], 'code'));

        $esiOnly = $this->calculate($this->structure($earnings, statutory: ['ESI']));
        $this->assertSame(0.0, $esiOnly['employee_pf']);
        $this->assertSame(150.0, $esiOnly['employee_esi']);

        // The formula is used, not the amount stored on the salary, and each appears once.
        $both = $this->calculate($this->structure($earnings));
        $this->assertSame(1200.0, $both['employee_pf']);
        $this->assertSame(150.0, $both['employee_esi']);
        $this->assertSame(1, count(array_keys(array_column($both['items'], 'code'), 'PF')));
        $this->assertSame(1350.0 + 130.0, $both['total_deductions']);
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

        $result = $this->calculate($this->structure(['BASIC' => 15000, 'SPECIAL' => 15000]),
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
