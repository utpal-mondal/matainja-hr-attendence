<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\InAppNotification;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $employee;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');
        $this->employee = User::factory()->create();
        $this->employee->assignRole('Employee');

        $managerStaff = Staff::create([
            'employee_code' => 'EMP-701', 'first_name' => 'Mona', 'last_name' => 'Roy',
            'employment_status' => 'active', 'user_id' => $this->manager->id,
        ]);
        $this->staff = Staff::create([
            'employee_code' => 'EMP-702', 'first_name' => 'Rina', 'last_name' => 'Sen',
            'employment_status' => 'active', 'user_id' => $this->employee->id, 'manager_id' => $managerStaff->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function typesFor(User $user): array
    {
        return InAppNotification::where('user_id', $user->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_leave_request_notifies_manager_and_admins_then_the_employee_on_decision(): void
    {
        $type = LeaveType::create(['name' => 'Casual', 'code' => 'CL']);

        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Family function',
        ])->assertCreated()->json('data.id');

        $this->assertSame(['leave_request'], $this->typesFor($this->manager));
        $this->assertSame(['leave_request'], $this->typesFor($this->admin));
        $this->assertSame(['leave_submitted'], $this->typesFor($this->employee), 'only a record of their own action');
        $this->assertNotNull(InAppNotification::where('user_id', $this->employee->id)->first()->read_at, 'saved as read: no badge, no phone pop-up');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/leave/requests/{$id}/approve")->assertOk();

        $this->assertSame(['leave_submitted', 'leave_approved'], $this->typesFor($this->employee));
        $note = InAppNotification::where('user_id', $this->employee->id)->where('type', 'leave_approved')->first();
        $this->assertSame('Leave approved', $note->title);
        $this->assertNull($note->read_at);
        $this->assertSame((string) $id, (string) $note->data['leave_request_id']);
    }

    public function test_the_current_manager_can_approve_leave_routed_before_they_were_assigned(): void
    {
        $type = LeaveType::create(['name' => 'Sick', 'code' => 'SL']);
        $managerStaffId = $this->staff->manager_id;
        $this->staff->update(['manager_id' => null]); // no manager yet: routed to HR

        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->id, 'from_date' => '2026-12-03', 'to_date' => '2026-12-03', 'reason' => 'Fever',
        ])->assertCreated()->json('data.id');

        $this->staff->update(['manager_id' => $managerStaffId]);
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/leave/requests')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/leave/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_wfh_rejection_notifies_the_employee(): void
    {
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/wfh/requests', [
            'from_date' => '2026-11-26', 'to_date' => '2026-11-26', 'reason' => 'Plumber visit',
        ])->assertCreated()->json('data.id');
        $this->assertSame(['wfh_request'], $this->typesFor($this->manager));

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/wfh/requests/{$id}/reject", ['comment' => 'Team offsite'])->assertOk();

        $this->assertSame(['wfh_submitted', 'wfh_rejected'], $this->typesFor($this->employee));
    }

    public function test_mark_all_read_keeps_every_notification_in_the_list(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/holidays', ['holiday_date' => '2026-12-25', 'name' => 'Christmas'])->assertCreated();
        $this->postJson('/api/v1/holidays', ['holiday_date' => '2026-12-31', 'name' => 'Year end'])->assertCreated();

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonCount(2, 'data.notifications');
    }

    public function test_an_admin_editing_someones_attendance_does_not_log_it_as_their_own_action(): void
    {
        Sanctum::actingAs($this->admin);
        \App\Models\Attendance::create([
            'staff_id' => $this->staff->id, 'work_date' => '2026-11-24', 'check_in_at' => '2026-11-24 09:30:00', 'status' => 'present',
        ]);

        $this->assertSame([], $this->typesFor($this->employee));
    }

    public function test_holiday_and_shift_changes_notify_affected_people(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/holidays', ['holiday_date' => '2026-12-25', 'name' => 'Christmas'])->assertCreated();

        $this->assertSame(['holiday'], $this->typesFor($this->employee));
        $this->assertSame(['holiday'], $this->typesFor($this->manager));
        $this->assertSame([], $this->typesFor($this->admin));

        $shift = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30']);
        $this->postJson("/api/v1/staff/{$this->staff->id}/shift-assignments", [
            'shift_id' => $shift->id, 'effective_from' => '2026-11-01',
        ])->assertCreated();
        $this->putJson("/api/v1/shifts/{$shift->id}", ['start_time' => '10:00'])->assertOk();

        $this->assertSame(['holiday', 'shift', 'shift'], $this->typesFor($this->employee));
        $this->assertSame(['holiday'], $this->typesFor($this->manager), 'not on that shift');
    }

    public function test_published_announcement_notifies_everyone_once(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/announcements', ['title' => 'Office closed', 'body' => 'Friday off', 'publish' => true])->assertCreated();

        $this->assertSame(['announcement'], $this->typesFor($this->employee));
        $this->assertSame(['announcement'], $this->typesFor($this->manager));
    }

    public function test_the_app_can_read_new_notifications_from_the_list(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/holidays', ['holiday_date' => '2026-12-25', 'name' => 'Christmas'])->assertCreated();

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.notifications.0.type', 'holiday')
            ->assertJsonPath('data.notifications.0.title', 'New holiday');
    }

    public function test_holiday_rows_created_in_bulk_can_be_muted(): void
    {
        \App\Services\NotificationService::muted(fn () => Holiday::create(['holiday_date' => '2026-12-31', 'name' => 'Year end']));

        $this->assertSame(0, InAppNotification::count());
    }
}
