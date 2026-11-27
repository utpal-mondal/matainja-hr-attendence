<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\PayrollRun;
use App\Models\Payslip;
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

class PayslipExportTest extends TestCase
{
    use RefreshDatabase;

    private PayrollRun $run;

    private Staff $asha;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->seed([RolePermissionSeeder::class, SalaryComponentSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        // Net pay ₹28,050 for October 2026 (₹30,000 gross, full attendance).
        $this->asha = $this->employee('EMP-301', 'Asha');
        $this->asha->payrollProfile()->create([
            'pan' => 'ABCDE1234F', 'uan' => '100123456789', 'bank_name' => 'SBI', 'bank_account_name' => 'Asha Roy',
            'bank_account_number' => '123456789012', 'bank_ifsc' => 'SBIN0001234',
        ]);
        $this->employee('EMP-302', 'Bimal'); // no bank details

        $this->run = app(PayrollService::class)->createRun(2026, 10, CarbonImmutable::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $name): Staff
    {
        $staff = Staff::create(['employee_code' => $code, 'first_name' => $name, 'employment_status' => 'active', 'joining_date' => '2025-01-01']);
        $components = SalaryComponent::ordered()->get();
        $service = app(SalaryStructureService::class);
        $amounts = $service->split(30000, $components);
        $amounts[$components->firstWhere('code', 'ESI')->id] = 0; // no ESI above ₹21,000, as an admin would set it
        $service->createRevision($staff, '2026-04-01', $components, $amounts);
        for ($d = CarbonImmutable::create(2026, 10, 1); $d->month === 10; $d = $d->addDay()) {
            if (! $d->isSunday()) {
                Attendance::create(['staff_id' => $staff->id, 'work_date' => $d->toDateString(), 'status' => 'present']);
            }
        }

        return $staff;
    }

    private function ashaPayslip(): Payslip
    {
        return $this->run->payslips()->where('staff_id', $this->asha->id)->sole();
    }

    /** @return list<list<string>> */
    private function csvRows(string $content): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", $content))));
    }

    public function test_payslip_pdf_downloads_and_shows_the_figures(): void
    {
        $payslip = $this->ashaPayslip();

        $response = $this->get(route('admin.payroll.payslips.pdf', [$this->run, $payslip]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment; filename=payslip-2026-10-emp-301.pdf', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('inline', $this->get(route('admin.payroll.payslips.pdf', [$this->run, $payslip, 'inline' => 1]))->headers->get('content-disposition'));

        $html = view('payslips.pdf', app(PayslipExportService::class)->viewData($this->run, $this->run->payslips()->whereKey($payslip->id)->get()))->render();

        $this->assertStringContainsString('PAY SLIP', $html);
        $this->assertStringContainsString('Period of Payment: Oct’26', $html);
        $this->assertStringContainsString('A/C NO: 123456789012', $html);
        $this->assertStringContainsString('IFSC: SBIN0001234', $html);
        $this->assertStringContainsString('28050.00', $html);
        $this->assertStringContainsString('<td>EPF</td>', $html);
        $this->assertStringContainsString('<td>ESI</td><td class="amt">0.00</td>', $html);
        $this->assertStringContainsString('Received By: '.$payslip->employee_name, $html);
        $this->assertStringContainsString('DRAFT', $html);
    }

    public function test_whole_run_downloads_as_one_pdf(): void
    {
        $response = $this->get(route('admin.payroll.pdf', $this->run))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString('payslips-2026-10.pdf', $response->headers->get('content-disposition'));
    }

    public function test_bank_file_needs_a_locked_payroll_and_flags_missing_bank_details(): void
    {
        $this->get(route('admin.payroll.bank', $this->run))->assertSessionHas('error');

        app(PayrollService::class)->lock($this->run);
        $response = $this->get(route('admin.payroll.bank', $this->run))->assertOk();
        $this->assertStringContainsString('bank-transfer-2026-10.csv', $response->headers->get('content-disposition'));

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Employee code', 'Employee name', 'Account holder', 'Account number', 'IFSC', 'Bank', 'Amount', 'Narration', 'Problem'], $rows[0]);
        $this->assertSame(['EMP-301', 'Asha', 'Asha Roy', '123456789012', 'SBIN0001234', 'SBI', '28050.00', 'Salary October 2026', ''], $rows[1]);
        $this->assertSame('No bank account number', $rows[2][8]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.bank_file_exported']);
    }

    public function test_register_has_a_column_per_earning_and_the_statutory_figures(): void
    {
        $rows = $this->csvRows($this->get(route('admin.payroll.register', $this->run))->assertOk()->streamedContent());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('Asha', $row['Employee name']);
        $this->assertSame('15000.00', $row['Basic']);
        $this->assertSame('6000.00', $row['House Rent Allowance']);
        $this->assertSame('30000.00', $row['Gross earnings']);
        $this->assertSame('1800.00', $row['PF (employee)']);
        $this->assertSame('150.00', $row['Professional Tax']);
        $this->assertSame('28050.00', $row['Net pay']);
        $this->assertSame('1250.00', $row['of which EPS']);
    }

    public function test_csv_cells_that_look_like_formulas_are_neutralised(): void
    {
        $this->asha->payrollProfile->update(['bank_account_name' => '=HYPERLINK("http://x")']);
        app(PayrollService::class)->lock($this->run);

        $rows = $this->csvRows($this->get(route('admin.payroll.bank', $this->run))->streamedContent());

        $this->assertSame("'=HYPERLINK(\"http://x\")", $rows[1][2]);
    }

    public function test_salary_page_lists_the_employees_payslips(): void
    {
        $this->get(route('admin.staff.salary', $this->asha))->assertOk()
            ->assertSee('October 2026')
            ->assertSee(route('admin.payroll.payslips.pdf', [$this->run, $this->ashaPayslip()]));
    }
}
