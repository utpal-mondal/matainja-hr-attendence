<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Staff $staff;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00'); // Tuesday
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Boss']);
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);

        $this->casual = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL', 'paid' => true, 'max_days' => 12]);
        LeaveType::create(['name' => 'Loss of Pay', 'code' => 'LOP', 'paid' => false]);
        $this->staff = Staff::create(['employee_code' => 'EMP-601', 'first_name' => 'Rina', 'last_name' => 'Sen', 'employment_status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function addLeave(array $data = []): TestResponse
    {
        return $this->post(route('admin.staff.leave.store', $this->staff), $data + [
            'leave_type_id' => $this->casual->id, 'from_date' => '2026-11-23', 'to_date' => '2026-11-24',
        ]);
    }

    private function used(): float
    {
        return (float) LeaveBalance::where('staff_id', $this->staff->id)->where('leave_type_id', $this->casual->id)->value('used');
    }

    public function test_admin_adds_approved_leave_that_uses_the_balance_and_shows_on_attendance(): void
    {
        $this->get(route('admin.staff.edit', $this->staff))->assertOk()->assertSee('Add leave')->assertSee('No leave yet.');

        $this->addLeave(['reason' => 'Fever'])
            ->assertRedirect(route('admin.staff.edit', $this->staff).'#leave')
            ->assertSessionHas('success', 'Casual Leave added for Rina Sen: 23 Nov – 24 Nov 2026 (2 days).');

        $leave = $this->staff->leaveRequests()->sole();
        $this->assertSame('approved', $leave->status);
        $this->assertSame('2.0', $leave->total_days);
        $approval = $leave->approvals()->sole();
        $this->assertSame($this->admin->id, $approval->approver_id);
        $this->assertSame('approved', $approval->decision);
        $this->assertSame(2.0, $this->used());
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.added_by_admin']);

        $this->get(route('admin.staff.edit', $this->staff))->assertSee('Fever')->assertSee('Boss (added by admin)')->assertSee('Casual Leave 2 used');
        $this->get(route('admin.attendance.index', ['date' => '2026-11-23']))->assertSee('On leave · Casual Leave');
    }

    public function test_half_day_counts_half_and_must_be_a_single_date(): void
    {
        $this->addLeave(['partial_day' => '1'])->assertSessionHasErrorsIn('leave', 'partial_day');

        $this->addLeave(['partial_day' => '1', 'to_date' => '2026-11-23'])
            ->assertSessionHas('success', 'Casual Leave added for Rina Sen: 23 Nov 2026 (half day) (0.5 days).');
        $this->assertSame(0.5, $this->used());
    }

    public function test_overlapping_leave_and_locked_payroll_months_are_refused(): void
    {
        LeaveRequest::create(['staff_id' => $this->staff->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-11-24',
            'to_date' => '2026-11-25', 'total_days' => 2, 'status' => 'pending']);
        $this->addLeave()->assertSessionHasErrorsIn('leave', ['from_date' => 'Overlaps a pending request for Casual Leave (24 Nov – 25 Nov 2026). Approve, reject or cancel that one first.']);

        PayrollRun::create(['year' => 2026, 'month' => 10, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'paid']);
        $this->addLeave(['from_date' => '2026-10-30', 'to_date' => '2026-11-02'])
            ->assertSessionHasErrorsIn('leave', ['from_date' => "The October 2026 payroll is paid, so leave in that month can't change. Reopen the payroll first."]);

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_former_employees_and_days_before_joining_are_refused(): void
    {
        $this->staff->update(['joining_date' => '2026-11-24']);
        $this->addLeave()->assertSessionHasErrorsIn('leave', 'from_date');

        $this->staff->update(['employment_status' => 'terminated']);
        $this->addLeave(['from_date' => '2026-11-25', 'to_date' => '2026-11-25'])
            ->assertSessionHas('error', "Rina Sen is terminated; leave can't be added.");
        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_cancelling_gives_the_days_back_unless_the_month_is_locked(): void
    {
        $this->addLeave();
        $leave = $this->staff->leaveRequests()->sole();

        $run = PayrollRun::create(['year' => 2026, 'month' => 11, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'locked']);
        $this->post(route('admin.staff.leave.cancel', [$this->staff, $leave]))->assertSessionHas('error');
        $this->assertSame('approved', $leave->fresh()->status);

        $run->update(['status' => 'draft']);
        $this->post(route('admin.staff.leave.cancel', [$this->staff, $leave]))
            ->assertSessionHas('success', 'Leave 23 Nov – 24 Nov 2026 for Rina Sen cancelled; the days are back in the balance.');
        $this->assertSame('cancelled', $leave->fresh()->status);
        $this->assertSame(0.0, $this->used());

        $other = Staff::create(['employee_code' => 'EMP-602', 'first_name' => 'Other']);
        $this->post(route('admin.staff.leave.cancel', [$other, $leave]))->assertNotFound();
    }

    public function test_leave_from_the_attendance_list_returns_there_and_a_failure_reopens_the_pop_up(): void
    {
        $list = route('admin.attendance.index', ['date' => '2026-11-23']);
        $this->get($list)->assertSee('data-leave-action="'.route('admin.staff.leave.store', $this->staff).'"', false);

        $failed = $this->from($list)->followingRedirects()->post(route('admin.staff.leave.store', $this->staff), [
            'leave_type_id' => $this->casual->id, 'from_date' => '2026-11-23', 'to_date' => '2026-11-20', 'return_to' => 'attendance',
            'leave_staff_id' => $this->staff->id, 'leave_staff_name' => 'Rina Sen',
        ])->assertOk()->assertSee('The end date must be on or after the start date.');
        $this->assertMatchesRegularExpression('/id="leave-dialog" aria-labelledby="leave-title"\s+data-open-on-load/', $failed->getContent());

        $this->from($list)->post(route('admin.staff.leave.store', $this->staff), [
            'leave_type_id' => $this->casual->id, 'from_date' => '2026-11-23', 'to_date' => '2026-11-23', 'return_to' => 'attendance',
        ])->assertRedirect($list)->assertSessionHas('success');

        $this->get($list)->assertDontSee('data-leave-action="'.route('admin.staff.leave.store', $this->staff).'"', false);
    }
}
