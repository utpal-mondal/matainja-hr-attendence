<?php

namespace Tests\Feature;

use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Models\StaffSalaryStructure;
use App\Models\User;
use App\Services\SalaryStructureService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalaryComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffSalaryTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SalaryComponentSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->staff = Staff::create(['employee_code' => 'EMP-201', 'first_name' => 'Asha', 'last_name' => 'Roy', 'employment_status' => 'active']);
    }

    /** @return array<string, int> component code => id */
    private function ids(): array
    {
        return SalaryComponent::pluck('id', 'code')->all();
    }

    public function test_gross_is_split_into_basic_hra_and_balance(): void
    {
        $ids = $this->ids();

        $amounts = app(SalaryStructureService::class)->split(30000, SalaryComponent::ordered()->get());

        $this->assertSame(15000.0, $amounts[$ids['BASIC']]);
        $this->assertSame(6000.0, $amounts[$ids['HRA']]);
        $this->assertSame(9000.0, $amounts[$ids['SPECIAL']]);
    }

    public function test_split_gives_pf_and_esi_their_rates_with_no_built_in_limits(): void
    {
        $ids = $this->ids();
        $service = app(SalaryStructureService::class);

        $small = $service->split(20000, SalaryComponent::ordered()->get());
        $this->assertSame(1200.0, $small[$ids['PF']]);   // 12% of Basic 10,000
        $this->assertSame(150.0, $small[$ids['ESI']]);   // 0.75% of 20,000

        SalaryComponent::where('code', 'PF')->update(['value' => 10]);
        $large = $service->split(40000, SalaryComponent::ordered()->get());
        $this->assertSame(2000.0, $large[$ids['PF']]);   // the changed rate, on the full Basic 20,000
        $this->assertSame(300.0, $large[$ids['ESI']]);
    }

    public function test_revision_saves_pf_and_esi_as_entered_and_leaves_out_zero(): void
    {
        $ids = $this->ids();

        $this->post(route('admin.staff.salary.store', $this->staff), [
            'effective_from' => '2026-04-01',
            'amounts' => [$ids['BASIC'] => 8000, $ids['HRA'] => 3200, $ids['SPECIAL'] => 4800, $ids['PF'] => 999, $ids['ESI'] => 0],
        ])->assertSessionHasNoErrors();

        $structure = $this->staff->salaryStructures()->sole();
        $items = $structure->items->keyBy('code');
        $this->assertSame('999.00', $items['PF']->monthly_amount);
        $this->assertSame('deduction', $items['PF']->type);
        $this->assertTrue($items['PF']->is_prorated);
        $this->assertArrayNotHasKey('ESI', $items->all());
        $this->assertSame('16000.00', $structure->monthly_gross);
    }

    public function test_pf_and_esi_codes_must_be_deductions_with_any_rate(): void
    {
        SalaryComponent::whereIn('code', ['PF', 'ESI'])->delete();

        $this->post(route('admin.org.store', 'salary-components'), [
            'name' => 'PF', 'code' => 'pf', 'type' => 'earning', 'calculation' => 'percent_of_basic', 'value' => 12, 'sort_order' => 1,
        ])->assertSessionHasErrors('type');
        $this->post(route('admin.org.store', 'salary-components'), [
            'name' => 'Provident Fund', 'code' => 'PF', 'type' => 'deduction', 'calculation' => 'percent_of_basic', 'value' => 10, 'sort_order' => 1, 'is_prorated' => '1', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.org.index', 'salary-components'))->assertOk()->assertSee('10% of Basic');
    }

    public function test_balance_never_goes_negative_when_fixed_parts_exceed_gross(): void
    {
        $conveyance = SalaryComponent::create(['name' => 'Conveyance', 'code' => 'CONV', 'type' => 'earning', 'calculation' => 'fixed', 'value' => 9000]);
        $canteen = SalaryComponent::create(['name' => 'Canteen', 'code' => 'CANTEEN', 'type' => 'deduction', 'calculation' => 'fixed', 'value' => 500]);

        $amounts = app(SalaryStructureService::class)->split(10000, SalaryComponent::ordered()->get());

        $this->assertSame(9000.0, $amounts[$conveyance->id]);
        $this->assertSame(0.0, $amounts[$this->ids()['SPECIAL']]);
        $this->assertSame(500.0, $amounts[$canteen->id]);
    }

    public function test_salary_page_shows_the_split_of_a_typed_gross(): void
    {
        $this->get(route('admin.staff.salary', [$this->staff, 'gross' => 30000]))
            ->assertOk()
            ->assertSee('No salary set yet.')
            ->assertSee('name="amounts['.$this->ids()['BASIC'].']"', false)
            ->assertSee('value="15000"', false);
    }

    public function test_saving_a_revision_keeps_a_copy_of_each_component(): void
    {
        $ids = $this->ids();

        $this->post(route('admin.staff.salary.store', $this->staff), [
            'effective_from' => '2026-04-01',
            'amounts' => [$ids['BASIC'] => 20000, $ids['HRA'] => 8000, $ids['SPECIAL'] => 0],
            'notes' => 'Joining salary',
        ])->assertRedirect(route('admin.staff.salary', $this->staff))
            ->assertSessionHas('success', 'Salary revision saved: ₹28,000.00 a month from 01 Apr 2026.');

        $structure = $this->staff->salaryStructures()->with('items')->sole();
        $this->assertSame('28000.00', $structure->monthly_gross);
        $this->assertSame(['BASIC', 'HRA'], $structure->items->pluck('code')->all());

        SalaryComponent::find($ids['HRA'])->update(['name' => 'Renamed HRA']);
        $this->assertSame('House Rent Allowance', $structure->items()->where('code', 'HRA')->value('name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.salary_revised']);
    }

    public function test_revision_needs_an_earning_and_a_new_start_date(): void
    {
        $ids = $this->ids();

        $this->post(route('admin.staff.salary.store', $this->staff), [
            'effective_from' => '2026-04-01', 'amounts' => [$ids['BASIC'] => 0],
        ])->assertSessionHasErrors('amounts');

        StaffSalaryStructure::create(['staff_id' => $this->staff->id, 'effective_from' => '2026-04-01', 'monthly_gross' => 1000]);
        $this->post(route('admin.staff.salary.store', $this->staff), [
            'effective_from' => '2026-04-01', 'amounts' => [$ids['BASIC'] => 1000],
        ])->assertSessionHasErrors('effective_from');
    }

    public function test_salary_in_force_is_the_latest_revision_started_by_that_date(): void
    {
        foreach (['2025-04-01' => 20000, '2026-04-01' => 25000, '2026-12-01' => 30000] as $from => $gross) {
            StaffSalaryStructure::create(['staff_id' => $this->staff->id, 'effective_from' => $from, 'monthly_gross' => $gross]);
        }

        $this->assertSame('25000.00', $this->staff->salaryStructureOn(now()->setDate(2026, 11, 30))->monthly_gross);
        $this->assertSame('30000.00', $this->staff->salaryStructureOn(now()->setDate(2026, 12, 1))->monthly_gross);
        $this->assertNull($this->staff->salaryStructureOn(now()->setDate(2025, 3, 31)));
    }

    public function test_statutory_and_bank_details_are_saved_with_the_account_number_encrypted(): void
    {
        $this->put(route('admin.staff.salary.profile', $this->staff), [
            'pan' => 'abcde1234f', 'uan' => '100123456789', 'tax_regime' => 'old',
            'pf_on_full_basic' => '0', 'pt_applicable' => '0',
            'bank_name' => 'SBI', 'bank_account_number' => '1234 5678 9012', 'bank_ifsc' => 'sbin0001234',
        ])->assertRedirect(route('admin.staff.salary', $this->staff))->assertSessionHasNoErrors();

        $profile = $this->staff->payrollProfile()->sole();
        $this->assertSame('ABCDE1234F', $profile->pan);
        $this->assertSame('SBIN0001234', $profile->bank_ifsc);
        $this->assertSame('123456789012', $profile->bank_account_number);
        $this->assertFalse($profile->pt_applicable);
        $this->assertFalse($profile->pf_on_full_basic);
        $this->assertNotSame('123456789012', DB::table('staff_payroll_profiles')->value('bank_account_number'));
        $this->assertArrayNotHasKey('bank_account_number', $profile->toArray());
        $this->assertSame('XXXXXXXX9012', $profile->masked_account_number);

        // Leaving the account number empty keeps the saved one.
        $this->put(route('admin.staff.salary.profile', $this->staff), ['tax_regime' => 'new', 'bank_account_number' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('123456789012', $profile->fresh()->bank_account_number);
    }

    public function test_invalid_pan_and_ifsc_are_rejected(): void
    {
        $this->put(route('admin.staff.salary.profile', $this->staff), ['pan' => 'ABC123', 'bank_ifsc' => 'SBIN1234', 'tax_regime' => 'new'])
            ->assertSessionHasErrors(['pan', 'bank_ifsc']);

        $this->assertDatabaseCount('staff_payroll_profiles', 0);
    }

    public function test_a_revision_can_only_be_deleted_from_its_own_employee(): void
    {
        $other = Staff::create(['employee_code' => 'EMP-202', 'first_name' => 'Other']);
        $revision = StaffSalaryStructure::create(['staff_id' => $other->id, 'effective_from' => '2026-04-01', 'monthly_gross' => 1000]);

        $this->delete(route('admin.staff.salary.destroy', [$this->staff, $revision]))->assertNotFound();

        $this->delete(route('admin.staff.salary.destroy', [$other, $revision]))->assertSessionHas('success', 'Salary revision deleted.');
        $this->assertModelMissing($revision);
    }

    public function test_salary_components_are_managed_on_the_organisation_screen(): void
    {
        $this->get(route('admin.org.index', 'salary-components'))->assertOk()->assertSee('House Rent Allowance')->assertSee('40% of Basic');

        $this->post(route('admin.org.store', 'salary-components'), [
            'name' => 'Loan recovery', 'code' => 'LOAN', 'type' => 'deduction', 'calculation' => 'balance', 'value' => 0, 'sort_order' => 1,
        ])->assertSessionHasErrors('calculation');

        $this->post(route('admin.org.store', 'salary-components'), [
            'name' => 'Bonus', 'code' => 'BONUS', 'type' => 'earning', 'calculation' => 'percent_of_basic', 'value' => 120, 'sort_order' => 1,
        ])->assertSessionHasErrors('value');

        $this->post(route('admin.org.store', 'salary-components'), [
            'name' => 'Conveyance', 'code' => 'CONV', 'type' => 'earning', 'calculation' => 'fixed', 'value' => 1600, 'sort_order' => 3, 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('salary_components', ['code' => 'CONV', 'is_active' => true, 'is_taxable' => false]);
    }

    public function test_component_used_in_a_salary_cannot_be_deleted(): void
    {
        $ids = $this->ids();
        app(SalaryStructureService::class)->createRevision($this->staff, '2026-04-01', SalaryComponent::ordered()->get(), [$ids['BASIC'] => 10000]);

        $this->delete(route('admin.org.destroy', ['salary-components', $ids['BASIC']]))->assertSessionHas('error');
        $this->assertDatabaseHas('salary_components', ['id' => $ids['BASIC']]);
    }

    public function test_salary_history_blocks_a_plain_employee_delete(): void
    {
        StaffSalaryStructure::create(['staff_id' => $this->staff->id, 'effective_from' => '2026-04-01', 'monthly_gross' => 1000]);

        $this->assertSame(['1 salary revision'], $this->staff->deletionBlockers());
    }

    public function test_guests_cannot_open_the_salary_page(): void
    {
        auth()->logout();

        $this->get(route('admin.staff.salary', $this->staff))->assertRedirect(route('login'));
    }
}
