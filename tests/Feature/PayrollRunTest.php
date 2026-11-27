<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\SalaryStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalaryComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    private Staff $asha;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->seed([RolePermissionSeeder::class, SalaryComponentSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        // ₹30,000 a month: Basic 15,000 · HRA 6,000 · Special 9,000. Present every Mon–Sat of October 2026.
        $this->asha = $this->employeeWithSalary('EMP-301', 'Asha', 30000);
        $this->presentAllMonth($this->asha, 2026, 10);
    }

    private function presentAllMonth(Staff $staff, int $year, int $month): void
    {
        for ($d = CarbonImmutable::create($year, $month, 1); $d->month === $month; $d = $d->addDay()) {
            if (! $d->isSunday()) {
                Attendance::create(['staff_id' => $staff->id, 'work_date' => $d->toDateString(), 'status' => 'present', 'check_in_at' => $d->setTime(9, 30)]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employeeWithSalary(string $code, string $name, float $gross): Staff
    {
        $staff = Staff::create(['employee_code' => $code, 'first_name' => $name, 'employment_status' => 'active', 'joining_date' => '2025-01-01']);
        $components = SalaryComponent::ordered()->get();
        $service = app(SalaryStructureService::class);
        $amounts = $service->split($gross, $components);
        if ($gross > 21000) {
            $amounts[$components->firstWhere('code', 'ESI')->id] = 0; // no ESI above ₹21,000, as an admin would set it
        }
        $service->createRevision($staff, '2026-04-01', $components, $amounts);

        return $staff;
    }

    private function runOctober(): PayrollRun
    {
        $this->post(route('admin.payroll.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();

        return PayrollRun::sole();
    }

    public function test_running_a_month_creates_payslips_and_lists_who_was_left_out(): void
    {
        Staff::create(['employee_code' => 'EMP-302', 'first_name' => 'NoSalary', 'employment_status' => 'active']);
        Staff::create(['employee_code' => 'EMP-303', 'first_name' => 'Gone', 'employment_status' => 'resigned']);

        $run = $this->runOctober();

        $this->assertSame('draft', $run->status);
        $this->assertSame(1, $run->employee_count);
        $this->assertSame('28050.00', $run->total_net);
        $this->assertSame([['staff_id' => Staff::where('first_name', 'NoSalary')->value('id'), 'name' => 'NoSalary (EMP-302)', 'reason' => 'No salary set']], $run->skipped);

        $payslip = $run->payslips()->with('items')->sole();
        $this->assertSame(31, $payslip->period_days);
        $this->assertSame('0.0', $payslip->lop_days);
        $this->assertSame(['BASIC', 'HRA', 'SPECIAL', 'PF', 'PT'], $payslip->items->pluck('code')->all());
        $this->assertSame('Asha', $payslip->employee_name);

        $this->get(route('admin.payroll.show', $run))->assertOk()->assertSee('October 2026')->assertSee('NoSalary (EMP-302)')->assertSee('₹28,050.00');
        $this->get(route('admin.payroll.payslips.show', [$run, $payslip]))->assertOk()->assertSee('Provident Fund')->assertSee('31 days paid');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.created']);
    }

    public function test_recalculating_rebuilds_the_not_included_list_instead_of_adding_to_it(): void
    {
        $noSalary = Staff::create(['employee_code' => 'EMP-305', 'first_name' => 'NoSalary', 'employment_status' => 'active']);
        $run = $this->runOctober();

        $this->post(route('admin.payroll.recalculate', $run));
        $this->post(route('admin.payroll.recalculate', $run));
        $this->assertCount(1, $run->fresh()->skipped);

        $this->employeeWithSalary('EMP-306', 'Later', 20000);
        $noSalary->update(['employment_status' => 'resigned']);
        $this->post(route('admin.payroll.recalculate', $run));
        $this->assertSame([], $run->fresh()->skipped);
    }

    public function test_absent_days_without_leave_become_loss_of_pay(): void
    {
        foreach (['2026-10-05', '2026-10-06'] as $day) {
            Attendance::where('staff_id', $this->asha->id)->whereDate('work_date', $day)->delete();
        }

        $payslip = $this->runOctober()->payslips()->sole();

        $this->assertSame('2.0', $payslip->lop_days);
        $this->assertSame('29.0', $payslip->paid_days);
        $this->assertEquals(2, $payslip->day_counts['absent']);
    }

    public function test_admin_can_override_lop_days_and_it_survives_a_recalculation(): void
    {
        $run = $this->runOctober();
        $payslip = $run->payslips()->sole();

        $this->put(route('admin.payroll.payslips.lop', [$run, $payslip]), ['lop_days_override' => 3])->assertSessionHas('success');

        $payslip = $run->payslips()->sole();
        $this->assertSame('3.0', $payslip->lop_days);
        $this->assertSame('0.0', $payslip->lop_days_calculated);
        $this->assertSame('27096.00', $payslip->gross_earnings);
        $this->assertSame('25320.00', $payslip->net_pay);

        $this->post(route('admin.payroll.recalculate', $run))->assertSessionHas('success');
        $this->assertSame('3.0', $run->payslips()->sole()->lop_days);
        $this->assertSame('25320.00', $run->fresh()->total_net);

        $this->put(route('admin.payroll.payslips.lop', [$run, $run->payslips()->sole()]), ['lop_days_override' => ''])->assertSessionHas('success');
        $this->assertSame('28050.00', $run->payslips()->sole()->net_pay);
    }

    public function test_admin_can_override_pf_and_esi_and_it_survives_a_recalculation(): void
    {
        $run = $this->runOctober();

        $this->put(route('admin.payroll.payslips.statutory', [$run, $run->payslips()->sole()]), [
            'employee_pf_override' => 2500, 'employee_esi_override' => 100,
        ])->assertSessionHas('success');

        $payslip = $run->payslips()->sole();
        $this->assertSame('2500.00', $payslip->employee_pf);
        $this->assertSame('100.00', $payslip->employee_esi);
        $this->assertSame('1800.00', $payslip->employer_pf);
        $this->assertSame('975.00', $payslip->employer_esi);
        $this->assertSame('27250.00', $payslip->net_pay);
        $this->assertSame(2500.0, (float) $payslip->items()->where('code', 'PF')->value('amount'));

        $this->post(route('admin.payroll.recalculate', $run))->assertSessionHas('success');
        $this->assertSame('2500.00', $run->payslips()->sole()->employee_pf);
        $this->assertSame('27250.00', $run->fresh()->total_net);

        $this->put(route('admin.payroll.payslips.statutory', [$run, $run->payslips()->sole()]), [
            'employee_pf_override' => '', 'employee_esi_override' => '',
        ])->assertSessionHas('success');
        $payslip = $run->payslips()->sole();
        $this->assertNull($payslip->employee_pf_override);
        $this->assertSame('1800.00', $payslip->employee_pf);
        $this->assertSame('28050.00', $payslip->net_pay);
    }

    public function test_pf_and_esi_override_is_rejected_when_negative_or_the_payroll_is_locked(): void
    {
        $run = $this->runOctober();

        $this->put(route('admin.payroll.payslips.statutory', [$run, $run->payslips()->sole()]), ['employee_pf_override' => -1])
            ->assertSessionHasErrors('employee_pf_override');

        $this->post(route('admin.payroll.lock', $run));
        $this->put(route('admin.payroll.payslips.statutory', [$run, $run->payslips()->sole()]), ['employee_pf_override' => 500])
            ->assertSessionHas('error', 'This payroll is locked. Reopen it to make changes.');
        $this->assertSame('1800.00', $run->payslips()->sole()->employee_pf);
    }

    public function test_one_off_adjustments_are_added_and_removed(): void
    {
        $run = $this->runOctober();

        $this->post(route('admin.payroll.adjustments.store', [$run, $run->payslips()->sole()]), [
            'type' => 'earning', 'name' => 'Diwali bonus', 'amount' => 5000, 'is_taxable' => '1',
        ])->assertSessionHas('success');

        $payslip = $run->payslips()->sole();
        $this->assertSame('35000.00', $payslip->gross_earnings);
        $this->assertSame('35000.00', $payslip->taxable_earnings);
        $this->assertTrue($payslip->items()->where('name', 'Diwali bonus')->exists());

        $this->delete(route('admin.payroll.adjustments.destroy', [$run, $run->adjustments()->sole()]))->assertSessionHas('success');
        $this->assertSame('28050.00', $run->payslips()->sole()->net_pay);
        $this->assertDatabaseCount('payroll_adjustments', 0);
    }

    public function test_locked_payroll_cannot_change_until_reopened_and_must_be_locked_to_be_paid(): void
    {
        $run = $this->runOctober();

        $this->post(route('admin.payroll.paid', $run), ['paid_on' => '2026-11-01'])->assertSessionHas('error', 'Lock the payroll before marking it paid.');

        $this->post(route('admin.payroll.lock', $run))->assertSessionHas('success');
        $this->assertSame('locked', $run->fresh()->status);

        $this->put(route('admin.payroll.payslips.lop', [$run, $run->payslips()->sole()]), ['lop_days_override' => 2])
            ->assertSessionHas('error', 'This payroll is locked. Reopen it to make changes.');
        $this->post(route('admin.payroll.recalculate', $run))->assertSessionHas('error');
        $this->delete(route('admin.payroll.destroy', $run))->assertSessionHas('error');
        $this->assertSame('0.0', $run->payslips()->sole()->lop_days);

        $this->post(route('admin.payroll.reopen', $run))->assertSessionHas('success');
        $this->assertSame('draft', $run->fresh()->status);

        $this->post(route('admin.payroll.lock', $run));
        $this->post(route('admin.payroll.paid', $run), ['paid_on' => '2026-11-01', 'payment_reference' => 'NEFT-123'])->assertSessionHas('success');
        $this->assertSame('paid', $run->fresh()->status);
        $this->post(route('admin.payroll.reopen', $run))->assertSessionHas('error');
    }

    public function test_a_month_can_be_run_once_and_not_in_the_future(): void
    {
        $run = $this->runOctober();

        $this->post(route('admin.payroll.store'), ['month' => '2026-10'])->assertRedirect(route('admin.payroll.show', $run));
        $this->post(route('admin.payroll.store'), ['month' => '2026-12'])->assertSessionHasErrors('month');
        $this->assertDatabaseCount('payroll_runs', 1);

        $this->delete(route('admin.payroll.destroy', $run))->assertRedirect(route('admin.payroll.index'));
        $this->assertDatabaseCount('payslips', 0);
    }

    public function test_tds_counts_what_earlier_months_of_the_year_already_deducted(): void
    {
        $rich = $this->employeeWithSalary('EMP-304', 'Rich', 200000);
        $this->presentAllMonth($rich, 2026, 9);
        $this->presentAllMonth($rich, 2026, 10);
        $service = app(PayrollService::class);
        $today = CarbonImmutable::now();

        $september = $service->createRun(2026, 9, $today);
        $september->payslips()->where('staff_id', $rich->id)->update(['tds' => 50000]);
        $october = $service->createRun(2026, 10, $today);

        // October: ₹2 lakh paid in September + ₹2 lakh now + 5 months projected = ₹14 lakh
        // -> ₹81,900 tax for the year, less ₹50,000 already deducted, spread over the 6 months left.
        $this->assertSame('5317.00', $october->payslips()->where('staff_id', $rich->id)->value('tds'));
    }

    public function test_payroll_settings_are_saved(): void
    {
        $this->put(route('admin.settings.update'), [
            'weekly_off_days' => [6, 7], 'lop_basis' => 'fixed_30', 'pt_state' => 'none', 'overtime_multiplier' => 1.5,
        ])->assertSessionHas('success');

        $service = app(PayrollService::class);
        $this->assertSame([6, 7], $service->weeklyOffDays());
        $this->assertSame('fixed_30', $service->lopBasis());
        $this->assertNull($service->ptState());
        $this->assertSame(1.5, $service->overtimeMultiplier());
        $this->assertSame(3.25, $service->esiEmployerRate());  // not sent: the config rate stays

        $this->put(route('admin.settings.update'), ['lop_basis' => 'calendar', 'pt_state' => 'WB']);
        $this->assertSame([], $service->weeklyOffDays());
        $this->assertSame('', CompanySetting::where('key', PayrollService::SETTING_WEEKLY_OFF_DAYS)->value('value'));
    }

    public function test_employer_esi_rate_is_saved_and_charged_on_payroll(): void
    {
        $service = app(PayrollService::class);

        $this->put(route('admin.settings.update'), ['lop_basis' => 'calendar', 'pt_state' => 'WB', 'esi_employer_rate' => -1])
            ->assertSessionHasErrors('esi_employer_rate');
        $this->put(route('admin.settings.update'), ['weekly_off_days' => [7], 'lop_basis' => 'calendar', 'pt_state' => 'WB', 'esi_employer_rate' => 4])
            ->assertSessionHas('success');
        $this->assertSame(4.0, $service->esiEmployerRate());

        // ₹20,000 a month pays ESI (0.75% = ₹150); the employer adds 4% of the ₹20,000 earned.
        $small = $this->employeeWithSalary('EMP-307', 'Small', 20000);
        $this->presentAllMonth($small, 2026, 10);
        $payslips = $this->runOctober()->payslips()->get()->keyBy('staff_id');

        $this->assertSame('150.00', $payslips[$small->id]->employee_esi);
        $this->assertSame('800.00', $payslips[$small->id]->employer_esi);
        $this->assertSame('0.00', $payslips[$this->asha->id]->employer_esi);  // no ESI above ₹21,000
    }

    public function test_fixed_30_basis_divides_by_30(): void
    {
        CompanySetting::create(['key' => PayrollService::SETTING_LOP_BASIS, 'value' => 'fixed_30', 'data_type' => 'string']);

        $payslip = $this->runOctober()->payslips()->sole();

        $this->assertSame(30, $payslip->period_days);
        $this->assertSame('30000.00', $payslip->gross_earnings);
    }
}
