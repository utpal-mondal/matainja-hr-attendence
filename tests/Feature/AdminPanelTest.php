<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\AttendanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_sidebar_and_sign_in_show_the_organisation_logo_once_uploaded(): void
    {
        Storage::fake('local');
        $this->get(route('login'))->assertSee('M17 11V3H7v4H3v14h8v-4h2v4h8V11h-4z', false)->assertDontSee('has-logo');
        $this->get(route('admin.logo'))->assertNotFound();

        $this->actingAs($this->superAdmin());
        $this->put(route('admin.settings.update'), ['company_logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSessionHasNoErrors();
        $path = CompanySetting::where('key', 'company.logo')->value('value');
        $logoUrl = route('admin.logo', ['v' => substr(md5($path), 0, 8)]);

        $this->get(route('admin.dashboard'))->assertSee('brand-mark has-logo', false)->assertSee($logoUrl, false);
        $this->get(route('admin.logo'))->assertOk()->assertHeader('content-type', 'image/png');

        auth()->logout();
        $this->get(route('login'))->assertSee($logoUrl, false);
        $this->get(route('admin.logo'))->assertOk(); // public, like the invoices it's printed on
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'secret123']);
        $user->assignRole('Super Admin');

        return $user;
    }

    private function employee(string $role = 'Employee', array $attributes = []): Staff
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return Staff::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'EMP'.$user->id,
            'first_name' => 'Riya',
            'last_name' => 'Sen',
            'email' => $user->email,
        ], $attributes));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertRedirect('/login'); // old bookmark
    }

    public function test_api_guests_still_get_json_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_super_admin_can_sign_in_and_out(): void
    {
        $this->superAdmin();

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')->assertOk()->assertSee('Dashboard');

        $this->post('/admin/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_other_roles_sign_in_to_the_employee_portal_not_the_admin_panel(): void
    {
        $staff = $this->employee('HR Admin', ['web_login_enabled' => true]);
        $staff->user->update(['password' => 'secret123']);

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertRedirect(route('portal.dashboard'));
        $this->get('/admin')->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($staff->user);

        // Without an employee profile there is nowhere to go: signed out.
        $hrOnly = User::factory()->create(['password' => 'secret123']);
        $hrOnly->assignRole('HR Admin');
        auth()->logout();
        $this->post('/login', ['email' => $hrOnly->email, 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($hrOnly)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_super_admin_is_taken_back_to_the_admin_page_they_asked_for(): void
    {
        $this->superAdmin();

        $this->get('/admin/payroll')->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'secret123'])
            ->assertRedirect(url('/admin/payroll'));
    }

    public function test_every_page_renders(): void
    {
        $admin = $this->superAdmin();
        $staff = $this->employee();
        Branch::create(['name' => 'HQ', 'code' => 'HQ', 'timezone' => 'Asia/Kolkata', 'latitude' => 22.57, 'longitude' => 88.36]);
        Attendance::create(['staff_id' => $staff->id, 'work_date' => now('Asia/Kolkata')->toDateString(), 'check_in_at' => now(), 'status' => 'present']);

        $this->actingAs($admin);
        $pages = ['/admin', '/admin/employees', '/admin/employees/create', "/admin/employees/{$staff->id}/edit",
            '/admin/users', '/admin/roles', '/admin/attendance', '/admin/attendance?status=in', '/admin/approvals',
            '/admin/approvals?kind=wfh', '/admin/approvals?kind=correction', '/admin/approvals?status=decided',
            '/admin/settings', '/admin/audit-log'];
        foreach (['branches', 'departments', 'designations', 'teams', 'shifts', 'holidays', 'leave-types'] as $type) {
            $pages[] = "/admin/organisation/{$type}";
        }
        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/admin/attendance/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/admin/organisation/nope')->assertNotFound();
    }

    public function test_sidebar_links_to_telescope_only_when_it_is_available(): void
    {
        $this->actingAs($this->superAdmin());

        // Telescope is registered only in the local environment, not in tests.
        $this->get('/admin')->assertOk()->assertDontSee('>Telescope', false);

        Route::get('/telescope/{view?}', fn () => 'telescope')->name('telescope');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/admin')->assertOk()
            ->assertSee('href="'.route('telescope').'" target="_blank"', false)
            ->assertSee('Telescope');
    }

    public function test_add_employee_with_login_role_and_shift(): void
    {
        $this->actingAs($this->superAdmin());
        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $shift = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15]);

        $this->post('/admin/employees', [
            'employee_code' => 'EMP900', 'first_name' => 'Amit', 'last_name' => 'Roy', 'email' => 'amit@example.com',
            'department_id' => $dept->id, 'shift_id' => $shift->id, 'employment_status' => 'active',
            'create_login' => '1', 'role' => 'Manager', 'password' => 'welcome123',
        ])->assertRedirect()->assertSessionHas('success');

        $staff = Staff::where('employee_code', 'EMP900')->firstOrFail();
        $this->assertSame('Amit Roy', $staff->name);
        $this->assertTrue($staff->user->hasRole('Manager'));
        $this->assertSame($shift->id, $staff->shiftAssignments()->first()->shift_id);
        $this->assertTrue(auth()->validate(['email' => 'amit@example.com', 'password' => 'welcome123']));
    }

    public function test_deactivate_signs_the_employee_out(): void
    {
        $this->actingAs($this->superAdmin());
        $staff = $this->employee();
        $staff->user->createToken('phone');

        $this->post("/admin/employees/{$staff->id}/deactivate")->assertSessionHas('success');

        $this->assertSame('terminated', $staff->fresh()->employment_status);
        $this->assertSame(0, $staff->user->tokens()->count());
    }

    public function test_cannot_remove_own_super_admin_role(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->put("/admin/users/{$admin->id}/roles", ['roles' => ['Employee']])
            ->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->hasRole('Super Admin'));
    }

    public function test_role_permissions_update_and_super_admin_stays_complete(): void
    {
        $this->actingAs($this->superAdmin());
        $manager = Role::where('name', 'Manager')->first();

        $this->put("/admin/roles/{$manager->id}", ['permissions' => ['leave.approve']])->assertSessionHas('success');
        $this->assertSame(['leave.approve'], $manager->fresh()->permissions->pluck('name')->all());

        $super = Role::where('name', 'Super Admin')->first();
        $this->put("/admin/roles/{$super->id}", ['permissions' => []]);
        $this->assertGreaterThan(5, $super->fresh()->permissions()->count());
    }

    public function test_branch_crud_and_delete_guard(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/admin/organisation/branches', [
            'name' => 'Salt Lake', 'code' => 'SLK', 'latitude' => '22.58', 'longitude' => '88.41',
            'geofence_radius_meters' => '150', 'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ])->assertSessionHas('success');
        $branch = Branch::where('code', 'SLK')->firstOrFail();
        $this->assertSame(150, $branch->geofence_radius_meters);

        $this->put("/admin/organisation/branches/{$branch->id}", [
            'name' => 'Salt Lake Sector V', 'code' => 'SLK', 'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ])->assertRedirect(route('admin.org.index', 'branches'));
        $this->assertSame('Salt Lake Sector V', $branch->fresh()->name);

        $this->employee('Employee', ['branch_id' => $branch->id]);
        $this->delete("/admin/organisation/branches/{$branch->id}")->assertSessionHas('error');
        $this->assertNotNull($branch->fresh());
    }

    public function test_shift_times_and_checkbox_save(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/admin/organisation/shifts', [
            'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => '10', 'crosses_midnight' => '1',
        ])->assertSessionHas('success');
        $this->assertTrue(Shift::where('name', 'Night')->first()->crosses_midnight);
    }

    public function test_leave_approval_reuses_api_rules(): void
    {
        $this->actingAs($this->superAdmin());
        $staff = $this->employee();
        $type = LeaveType::create(['name' => 'Casual', 'code' => 'CL', 'paid' => true, 'max_days' => 12]);
        LeaveBalance::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => 2026, 'opening' => 12, 'pending' => 2]);
        $leave = LeaveRequest::create([
            'staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from_date' => '2026-12-01',
            'to_date' => '2026-12-02', 'total_days' => 2, 'status' => 'pending', 'reason' => 'Family',
        ]);

        $this->post("/admin/approvals/leave/{$leave->id}/approve")->assertSessionHas('success');
        $this->assertSame('approved', $leave->fresh()->status);

        // Deciding twice reports the API's conflict message instead of failing.
        $this->post("/admin/approvals/leave/{$leave->id}/approve")->assertSessionHas('error');
    }

    public function test_reject_needs_a_reason(): void
    {
        $this->actingAs($this->superAdmin());
        $staff = $this->employee();
        $wfh = WfhRequest::create(['staff_id' => $staff->id, 'from_date' => '2026-12-03', 'to_date' => '2026-12-03', 'status' => 'pending']);

        $this->post("/admin/approvals/wfh/{$wfh->id}/reject")->assertSessionHasErrors('comment');
        $this->post("/admin/approvals/wfh/{$wfh->id}/reject", ['comment' => 'Client visit'])->assertSessionHas('success');
        $this->assertSame('rejected', $wfh->fresh()->status);
        $this->assertSame('Client visit', $wfh->fresh()->approver_comment);
    }

    public function test_settings_save_wfh_days_and_geofence(): void
    {
        $this->actingAs($this->superAdmin());
        $this->put('/admin/settings', ['wfh_weekdays' => ['3', '5'], 'geofence_enforced' => '0', 'geofence_radius_meters' => '200'])
            ->assertSessionHas('success');

        $this->assertSame('3,5', CompanySetting::where('key', 'attendance.wfh_weekdays')->value('value'));
        $this->assertSame('false', CompanySetting::where('key', 'attendance.geofence_enforced')->value('value'));
        $this->assertSame([3, 5], app(AttendanceService::class)->wfhWeekdays());
    }
}
