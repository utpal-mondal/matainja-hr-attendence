<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use App\Models\WfhRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeePortalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00'); // Tuesday, 15:30 in Kolkata
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['name' => 'HQ', 'code' => 'HQ', 'timezone' => 'Asia/Kolkata']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = [], string $role = 'Employee'): Staff
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->assignRole($role);

        $staff = Staff::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'EMP'.$user->id,
            'first_name' => 'Riya',
            'last_name' => 'Sen',
            'email' => $user->email,
            'branch_id' => $this->branch->id,
            'employment_status' => 'active',
            'web_login_enabled' => true,
        ], $attributes));
        $shift = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15]);
        $staff->shiftAssignments()->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01']);

        return $staff;
    }

    private function payslip(Staff $staff, int $month, string $status, float $net): Payslip
    {
        $run = PayrollRun::firstOrCreate(['year' => 2026, 'month' => $month], [
            'period_start' => Carbon::create(2026, $month)->startOfMonth(),
            'period_end' => Carbon::create(2026, $month)->endOfMonth(),
            'status' => $status,
        ]);

        return $run->payslips()->create([
            'staff_id' => $staff->id, 'employee_code' => $staff->employee_code, 'employee_name' => $staff->full_name,
            'period_days' => 30, 'paid_days' => 30, 'gross_earnings' => $net + 1000, 'total_deductions' => 1000, 'net_pay' => $net,
        ]);
    }

    public function test_guests_are_sent_to_the_shared_login_page(): void
    {
        $this->get('/portal')->assertRedirect(route('login'));
        $this->get('/portal/attendance')->assertRedirect(route('login'));
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/portal/login')->assertRedirect('/login'); // old bookmark
    }

    public function test_employee_can_sign_in_and_out_with_the_app_password(): void
    {
        $staff = $this->employee();

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($staff->user);

        $this->get('/portal')->assertOk()->assertSee('Hello, Riya')->assertSee('done from the mobile app');
        // A signed-in employee opening the login page goes back to the portal.
        $this->get('/login')->assertRedirect(route('portal.dashboard'));

        $this->post('/portal/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $staff = $this->employee();

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_accounts_without_an_active_employee_profile_cannot_use_the_portal(): void
    {
        $noProfile = User::factory()->create(['password' => 'secret123']);
        $this->post('/login', ['email' => $noProfile->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['email' => 'This account is not linked to an employee profile. Please contact HR.']);
        $this->assertGuest();

        $leaver = $this->employee(['employment_status' => 'terminated']);
        $this->post('/login', ['email' => $leaver->user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['email' => 'Your employee account is not active. Please contact HR.']);
        $this->assertGuest();
    }

    public function test_employee_without_web_permission_cannot_sign_in(): void
    {
        $staff = $this->employee(['web_login_enabled' => false]);

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => "You don't have web permission. Please contact HR."]);
        $this->assertGuest();

        $this->from('/login')->followingRedirects()
            ->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertSee('You don&#039;t have web permission. Please contact HR.', false);
    }

    public function test_switching_web_permission_off_signs_the_employee_out_of_the_portal(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff->user)->get('/portal')->assertOk();

        $staff->update(['web_login_enabled' => false]);
        $staff->user->unsetRelation('staff'); // a real request loads the user afresh

        $this->get('/portal')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_super_admin_can_sign_in_without_the_web_permission_box(): void
    {
        $staff = $this->employee(['web_login_enabled' => false], 'Super Admin');

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_can_turn_web_login_on_and_off_from_the_employee_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $staff = $this->employee(['web_login_enabled' => false]);
        $form = [
            'first_name' => 'Riya', 'last_name' => 'Sen', 'email' => $staff->email, 'employment_status' => 'active',
            'role' => 'Employee',
        ];
        $this->actingAs($admin);

        $this->get("/admin/employees/{$staff->id}/edit")->assertOk()->assertSee('Allow web login (employee portal)');

        $this->put("/admin/employees/{$staff->id}", $form + ['web_login_enabled' => '1'])->assertSessionHas('success');
        $this->assertTrue($staff->fresh()->web_login_enabled);
        $this->get("/admin/employees/{$staff->id}/edit")->assertSee('Web login on');

        // Unticked box: only the hidden "0" is sent.
        $this->put("/admin/employees/{$staff->id}", $form + ['web_login_enabled' => '0'])->assertSessionHas('success');
        $this->assertFalse($staff->fresh()->web_login_enabled);
    }

    public function test_deactivated_employee_is_signed_out_on_the_next_page(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff->user)->get('/portal')->assertOk();

        $staff->update(['employment_status' => 'terminated']);
        $staff->user->unsetRelation('staff'); // a real request loads the user afresh

        $this->get('/portal/attendance')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_employees_opening_the_admin_panel_are_taken_to_their_portal(): void
    {
        $staff = $this->employee();

        $this->actingAs($staff->user)->get('/admin')
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('error', 'The admin panel is only for Super Admins.');
        $this->assertAuthenticatedAs($staff->user);
    }

    public function test_super_admin_with_an_employee_profile_lands_in_the_admin_panel(): void
    {
        $staff = $this->employee([], 'Super Admin');

        $this->post('/login', ['email' => $staff->user->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/login')->assertRedirect(route('admin.dashboard'));
        $this->get('/portal')->assertOk(); // their own portal is still open to them
    }

    public function test_every_portal_page_renders(): void
    {
        $staff = $this->employee();
        $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL']);
        LeaveBalance::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => 2026, 'opening' => 12, 'used' => 2]);
        LeaveRequest::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'total_days' => 1, 'status' => 'approved']);
        WfhRequest::create(['staff_id' => $staff->id, 'from_date' => '2026-11-26', 'to_date' => '2026-11-27', 'reason' => 'Plumber visit']);
        Holiday::create(['holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'public']);
        Attendance::create(['staff_id' => $staff->id, 'work_date' => '2026-11-24', 'check_in_at' => '2026-11-24 04:00:00', 'status' => 'present']);
        AttendanceCorrectionRequest::create(['staff_id' => $staff->id, 'work_date' => '2026-11-20', 'requested_values' => ['check_out_at' => '2026-11-20 13:00:00'], 'reason' => 'Forgot']);
        $this->payslip($staff, 10, 'locked', 28050);

        $this->actingAs($staff->user);
        $pages = ['/portal', '/portal/profile', '/portal/attendance', '/portal/attendance?month=2026-10', '/portal/attendance?month=bad',
            '/portal/regularization', '/portal/regularization?date=2026-11-24', '/portal/leave', '/portal/wfh', '/portal/payslips',
            '/portal/holidays', '/portal/holidays?year=2027'];
        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }

        $this->get('/portal')->assertSee('09:30 AM')->assertSee('Casual Leave')->assertSee('Christmas')->assertSee('₹28,050.00');
        $this->get('/portal/profile')->assertSee($staff->employee_code)->assertSee('HQ')->assertSee('General');
    }

    public function test_attendance_page_shows_own_days_in_office_time_without_a_check_in_button(): void
    {
        $staff = $this->employee();
        $other = $this->employee(['first_name' => 'Other']);
        Attendance::create(['staff_id' => $staff->id, 'work_date' => '2026-11-23', 'check_in_at' => '2026-11-23 04:15:00',
            'check_out_at' => '2026-11-23 13:00:00', 'status' => 'late', 'late_minutes' => 30, 'worked_minutes' => 525]);
        Attendance::create(['staff_id' => $other->id, 'work_date' => '2026-11-23', 'check_in_at' => '2026-11-23 02:01:00', 'status' => 'present']);

        $this->actingAs($staff->user)->get('/portal/attendance')
            ->assertOk()
            ->assertSee('09:45 AM')->assertSee('06:30 PM')->assertSee('8h 45m')->assertSee('30 min')
            ->assertDontSee('07:31 AM')
            ->assertDontSee(route('api.v1.attendance.checkIn'), false)
            ->assertDontSee(route('api.v1.attendance.checkOut'), false)
            ->assertSee(route('portal.regularization.index', ['date' => '2026-11-23']), false);
    }

    public function test_attendance_cannot_be_marked_from_the_portal(): void
    {
        $staff = $this->employee();

        $this->actingAs($staff->user)->post('/portal/attendance')->assertMethodNotAllowed();
        $this->assertSame(0, Attendance::count());
    }

    public function test_regularization_request_is_stored_in_app_time_and_shows_in_admin_approvals(): void
    {
        $staff = $this->employee();
        $attendance = Attendance::create(['staff_id' => $staff->id, 'work_date' => '2026-11-23', 'check_in_at' => '2026-11-23 04:00:00', 'status' => 'present']);
        $this->actingAs($staff->user);

        $this->post('/portal/regularization', ['work_date' => '2026-11-23', 'check_out' => '18:30', 'reason' => 'Phone died'])
            ->assertRedirect(route('portal.regularization.index'))
            ->assertSessionHas('success');

        $request = AttendanceCorrectionRequest::sole();
        $this->assertSame($staff->id, $request->staff_id);
        $this->assertSame($attendance->id, $request->attendance_id);
        $this->assertSame('pending', $request->status);
        $this->assertSame(['check_out_at' => '2026-11-23 13:00:00'], $request->requested_values); // 18:30 IST

        $this->get('/portal/regularization')->assertSee('06:30 PM')->assertSee('Pending');
        $this->get('/portal/attendance')->assertSee('Request pending');

        // One pending request per day.
        $this->post('/portal/regularization', ['work_date' => '2026-11-23', 'check_in' => '09:30', 'reason' => 'Again'])
            ->assertSessionHas('error');
        $this->assertSame(1, AttendanceCorrectionRequest::count());

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin)->get('/admin/approvals?kind=correction')->assertSee('Riya Sen')->assertSee('Phone died');
    }

    public function test_regularization_needs_a_time_a_reason_and_a_past_date(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff->user);

        $this->post('/portal/regularization', ['work_date' => '2026-11-23', 'reason' => 'x'])->assertSessionHasErrors('check_in');
        $this->post('/portal/regularization', ['work_date' => '2026-11-23', 'check_in' => '09:30'])->assertSessionHasErrors('reason');
        $this->post('/portal/regularization', ['work_date' => '2026-11-25', 'check_in' => '09:30', 'reason' => 'x'])->assertSessionHasErrors('work_date');
        $this->post('/portal/regularization', ['work_date' => '2026-11-23', 'check_in' => '18:00', 'check_out' => '09:00', 'reason' => 'x'])
            ->assertSessionHasErrors('check_out');

        $this->assertSame(0, AttendanceCorrectionRequest::count());
    }

    public function test_leave_can_be_applied_for_and_withdrawn(): void
    {
        $staff = $this->employee();
        $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL']);
        LeaveBalance::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => 2026, 'opening' => 12]);
        $this->actingAs($staff->user);

        $this->post('/portal/leave', ['leave_type_id' => $type->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Wedding'])
            ->assertRedirect(route('portal.leave.index'))
            ->assertSessionHas('success');

        $leave = LeaveRequest::sole();
        $this->assertSame('pending', $leave->status);
        $this->assertEquals(2, $leave->total_days);
        $this->assertEquals(2, LeaveBalance::sole()->pending);

        $this->post("/portal/leave/{$leave->id}/withdraw")->assertSessionHas('success');
        $this->assertSame('withdrawn', $leave->fresh()->status);
        $this->assertEquals(0, LeaveBalance::sole()->pending);
    }

    public function test_half_day_leave_must_be_a_single_date(): void
    {
        $staff = $this->employee();
        $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL']);

        $this->actingAs($staff->user)
            ->post('/portal/leave', ['leave_type_id' => $type->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'partial_day' => '1'])
            ->assertSessionHasErrors('partial_day');
        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_approved_leave_can_be_cancelled_only_before_it_starts(): void
    {
        $staff = $this->employee();
        $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL']);
        LeaveBalance::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => 2026, 'opening' => 12, 'used' => 2]);
        $future = LeaveRequest::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-01', 'total_days' => 1, 'status' => 'approved']);
        $past = LeaveRequest::create(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'total_days' => 1, 'status' => 'approved']);
        $this->actingAs($staff->user);

        $this->post("/portal/leave/{$past->id}/cancel")->assertSessionHas('error');
        $this->assertSame('approved', $past->fresh()->status);

        $this->post("/portal/leave/{$future->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $future->fresh()->status);
        $this->assertEquals(1, LeaveBalance::sole()->used);
    }

    public function test_employees_cannot_touch_someone_elses_leave(): void
    {
        $staff = $this->employee();
        $other = $this->employee(['first_name' => 'Other']);
        $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL']);
        $leave = LeaveRequest::create(['staff_id' => $other->id, 'leave_type_id' => $type->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-01', 'total_days' => 1, 'status' => 'pending']);

        $this->actingAs($staff->user)->post("/portal/leave/{$leave->id}/withdraw")->assertNotFound();
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_wfh_request_is_sent_for_approval(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff->user);

        $this->post('/portal/wfh', ['from_date' => '2026-11-26', 'to_date' => '2026-11-27', 'reason' => 'Plumber visit'])
            ->assertRedirect(route('portal.wfh.index'))
            ->assertSessionHas('success');
        $this->assertSame('pending', WfhRequest::where('staff_id', $staff->id)->sole()->status);

        $this->post('/portal/wfh', ['from_date' => '2026-11-20', 'to_date' => '2026-11-20', 'reason' => 'Too late'])
            ->assertSessionHasErrors('from_date');
    }

    public function test_only_own_finalised_payslips_are_visible(): void
    {
        $staff = $this->employee();
        $other = $this->employee(['first_name' => 'Other']);
        $locked = $this->payslip($staff, 10, 'locked', 28050);
        $draft = $this->payslip($staff, 11, 'draft', 31000);
        $othersPayslip = $this->payslip($other, 10, 'locked', 99999);
        $this->actingAs($staff->user);

        $this->get('/portal/payslips')->assertOk()
            ->assertSee('October 2026')->assertSee('₹28,050.00')
            ->assertDontSee('November 2026')->assertDontSee('₹99,999.00');

        $this->get("/portal/payslips/{$locked->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/portal/payslips/{$draft->id}/pdf")->assertNotFound();
        $this->get("/portal/payslips/{$othersPayslip->id}/pdf")->assertNotFound();
    }

    public function test_profile_phone_can_be_updated(): void
    {
        $staff = $this->employee(['phone' => '111']);

        $this->actingAs($staff->user)->put('/portal/profile', ['phone' => '+91 98300 12345'])
            ->assertRedirect(route('portal.profile'))->assertSessionHas('success');
        $this->assertSame('+91 98300 12345', $staff->fresh()->phone);

        $this->put('/portal/profile', ['phone' => 'call me'])->assertSessionHasErrors('phone', null, 'profile');
    }

    public function test_password_change_checks_the_current_password_and_signs_out_the_app(): void
    {
        $staff = $this->employee();
        $staff->user->createToken('phone');
        $this->actingAs($staff->user);

        $this->put('/portal/profile/password', ['current_password' => 'wrong', 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026'])
            ->assertSessionHasErrors('current_password', null, 'password');
        $this->assertTrue(Hash::check('secret123', $staff->user->fresh()->password));

        $this->put('/portal/profile/password', ['current_password' => 'secret123', 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026'])
            ->assertRedirect(route('portal.profile'))->assertSessionHas('success');
        $this->assertTrue(Hash::check('NewPass#2026', $staff->user->fresh()->password));
        $this->assertSame(0, $staff->user->tokens()->count());
    }
}
