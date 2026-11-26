<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private User $employee;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00'); // Tuesday
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->employee = User::factory()->create();
        $this->employee->assignRole('Employee');

        $this->shift = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15]);
        $this->staff = Staff::create([
            'employee_code' => 'EMP-601', 'first_name' => 'Rina', 'last_name' => 'Sen',
            'employment_status' => 'active', 'user_id' => $this->employee->id,
        ]);
        $this->staff->shiftAssignments()->create(['shift_id' => $this->shift->id, 'effective_from' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submit(string $date, ?string $in, ?string $out): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->employee);

        return $this->postJson('/api/v1/attendance/corrections', [
            'work_date' => $date,
            'requested_values' => ['check_in_at' => $in, 'check_out_at' => $out],
            'reason' => 'Forgot to punch on time',
        ]);
    }

    private function approve(int $id): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/v1/attendance/corrections/{$id}/approve");
    }

    public function test_approving_an_on_time_correction_marks_the_day_present_with_shift_and_worked_minutes(): void
    {
        // An absent day (no punch) gets a full correction.
        Attendance::create(['staff_id' => $this->staff->id, 'work_date' => '2026-11-23', 'status' => 'absent']);

        $id = $this->submit('2026-11-23', '2026-11-23T09:25:00Z', '2026-11-23T18:45:00Z')->assertCreated()->json('data.id');
        $this->approve($id)->assertOk();

        $day = Attendance::where('staff_id', $this->staff->id)->whereDate('work_date', '2026-11-23')->sole();
        $this->assertSame('present', $day->status);
        $this->assertSame('correction', $day->source);
        $this->assertSame($this->shift->id, $day->shift_id);
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertSame(560, (int) $day->worked_minutes);
        $this->assertSame(20, (int) $day->overtime_minutes);
        $this->assertSame($day->id, AttendanceCorrectionRequest::find($id)->attendance_id);
    }

    public function test_a_corrected_check_in_after_the_grace_period_is_late(): void
    {
        $id = $this->submit('2026-11-23', '2026-11-23T10:00:00Z', '2026-11-23T18:30:00Z')->assertCreated()->json('data.id');
        $this->approve($id)->assertOk();

        $day = Attendance::where('staff_id', $this->staff->id)->whereDate('work_date', '2026-11-23')->sole();
        $this->assertSame('late', $day->status);
        $this->assertSame(15, (int) $day->late_minutes);
        $this->assertSame(510, (int) $day->worked_minutes);
    }

    public function test_correcting_a_late_punch_to_on_time_clears_late(): void
    {
        Attendance::create([
            'staff_id' => $this->staff->id, 'work_date' => '2026-11-23', 'status' => 'late', 'late_minutes' => 40,
            'check_in_at' => '2026-11-23 10:25:00',
        ]);

        // Only the missing check-out plus the right check-in.
        $id = $this->submit('2026-11-23', '2026-11-23T09:30:00Z', '2026-11-23T18:30:00Z')->json('data.id');
        $this->approve($id)->assertOk();

        $day = Attendance::where('staff_id', $this->staff->id)->whereDate('work_date', '2026-11-23')->sole();
        $this->assertSame('present', $day->status);
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertSame(540, (int) $day->worked_minutes);
    }

    public function test_a_correction_on_the_wfh_weekday_is_wfh(): void
    {
        $id = $this->submit('2026-11-18', '2026-11-18T10:30:00Z', '2026-11-18T18:30:00Z')->json('data.id'); // Wednesday
        $this->approve($id)->assertOk();

        $this->assertSame('wfh', Attendance::where('staff_id', $this->staff->id)->whereDate('work_date', '2026-11-18')->value('status'));
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $this->submit('2026-11-23', '2026-11-23T18:00:00Z', '2026-11-23T09:00:00Z')
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_values.check_out_at');
    }

    public function test_only_one_pending_request_per_day(): void
    {
        $this->submit('2026-11-23', '2026-11-23T09:30:00Z', '2026-11-23T18:30:00Z')->assertCreated();
        $this->submit('2026-11-23', '2026-11-23T09:30:00Z', '2026-11-23T18:30:00Z')
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_date');
    }
}
