<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PayslipExportService;
use App\Services\SalaryStructureService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalaryComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PfEcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->seed([RolePermissionSeeder::class, SalaryComponentSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $profile */
    private function employee(string $name, float $gross, array $profile = [], array $absentDays = [], bool $withPf = true): Staff
    {
        $staff = Staff::create(['employee_code' => 'EMP-'.Staff::count() + 1, 'first_name' => $name, 'employment_status' => 'active', 'joining_date' => '2025-01-01']);
        $staff->payrollProfile()->create($profile);
        $components = SalaryComponent::ordered()->get();
        $service = app(SalaryStructureService::class);
        $amounts = $service->split($gross, $components);
        if ($gross > 21000) {
            $amounts[$components->firstWhere('code', 'ESI')->id] = 0; // no ESI above ₹21,000, as an admin would set it
        }
        if (! $withPf) {
            $amounts[$components->firstWhere('code', 'PF')->id] = 0;
        }
        $service->createRevision($staff, '2026-04-01', $components, $amounts);
        for ($d = CarbonImmutable::create(2026, 10, 1); $d->month === 10; $d = $d->addDay()) {
            if (! $d->isSunday() && ! in_array($d->day, $absentDays, true)) {
                Attendance::create(['staff_id' => $staff->id, 'work_date' => $d->toDateString(), 'status' => 'present']);
            }
        }

        return $staff;
    }

    private function lockedOctober(): PayrollRun
    {
        $service = app(PayrollService::class);
        $run = $service->createRun(2026, 10, CarbonImmutable::now());
        $service->lock($run);

        return $run;
    }

    public function test_ecr_has_one_line_per_member_in_the_epfo_format(): void
    {
        $this->employee("Asha O'Roy-Dás", 30000, ['uan' => '100123456789']);
        $this->employee('Rich', 80000, ['uan' => '100987654321', 'pf_on_full_basic' => true]);
        $this->employee('Bimal', 30000);                                   // no UAN
        $this->employee('Optout', 30000, ['uan' => '100555555555'], withPf: false);
        $run = $this->lockedOctober();

        $response = $this->get(route('admin.payroll.ecr', $run))->assertOk();
        $this->assertStringContainsString('attachment; filename=ECR-2026-10.txt', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('text/plain', $response->headers->get('content-type'));

        // UAN, name, gross, EPF wages, EPS wages, EDLI wages, EE share, EPS share, ER EPF share, NCP days, refund
        $this->assertSame([
            '100123456789#~#ASHA O ROY DAS#~#30000#~#15000#~#15000#~#15000#~#1800#~#1250#~#550#~#0#~#0',
            '100987654321#~#RICH#~#80000#~#40000#~#15000#~#15000#~#4800#~#1250#~#3550#~#0#~#0',
        ], explode("\n", trim($response->getContent())));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.ecr_exported']);

        $ecr = app(PayslipExportService::class)->ecr($run);
        $this->assertSame([['name' => 'Bimal (EMP-3)', 'reason' => 'No 12-digit UAN']], $ecr['left_out']);
        $this->assertSame(['members' => 2, 'epf_wages' => 55000, 'eps_wages' => 30000, 'employee_share' => 6600, 'eps_share' => 2500, 'employer_epf_share' => 4100], $ecr['totals']);
    }

    public function test_lop_days_are_ncp_days_and_wages_are_the_earned_amounts(): void
    {
        $this->employee('Asha', 30000, ['uan' => '100123456789'], absentDays: [5, 6]);

        $lines = app(PayslipExportService::class)->ecr($this->lockedOctober())['lines'];

        // 29 of 31 days paid: Basic 14,032 (under the ceiling), PF 1,684, EPS 1,169.
        $this->assertSame(['100123456789#~#ASHA#~#28064#~#14032#~#14032#~#14032#~#1684#~#1169#~#515#~#2#~#0'], $lines);
    }

    public function test_ecr_needs_a_locked_payroll_with_filable_members(): void
    {
        $this->employee('Asha', 30000, ['uan' => '100123456789']);
        $run = app(PayrollService::class)->createRun(2026, 10, CarbonImmutable::now());

        $this->get(route('admin.payroll.ecr', $run))->assertSessionHas('error');
        $this->get(route('admin.payroll.show', $run))->assertOk()->assertSee('Lock the payroll to download the ECR file');

        $run->payslips()->update(['uan' => null]);
        app(PayrollService::class)->lock($run);
        $this->get(route('admin.payroll.ecr', $run))->assertSessionHas('error', 'No one in this payroll can be filed: nobody has PF deducted with a 12-digit UAN.');
        $this->get(route('admin.payroll.show', $run))->assertOk()->assertSee('Left out of the ECR');
    }
}
