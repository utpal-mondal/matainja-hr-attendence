<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttendanceMarkTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00'); // Tuesday
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $shift = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15]);
        $this->staff = Staff::create(['employee_code' => 'EMP-501', 'first_name' => 'Gopal', 'last_name' => 'Das', 'employment_status' => 'active']);
        $this->staff->shiftAssignments()->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, string|null>  $data */
    private function mark(array $data): TestResponse
    {
        return $this->from(route('admin.attendance.index', ['date' => $data['date'] ?? '2026-11-23']))
            ->post(route('admin.attendance.mark', $this->staff), $data + ['date' => '2026-11-23']);
    }

    private function day(string $date = '2026-11-23'): ?Attendance
    {
        return Attendance::where('staff_id', $this->staff->id)->whereDate('work_date', $date)->first();
    }

    private function localTime(?CarbonImmutable $time): ?string
    {
        return $time?->setTimezone(config('app.timezone'))->format('H:i');
    }

    public function test_marking_present_works_out_worked_and_overtime_minutes(): void
    {
        $this->mark(['mark' => 'present', 'check_in' => '09:20', 'check_out' => '18:40', 'note' => 'No smartphone'])
            ->assertRedirect(route('admin.attendance.index', ['date' => '2026-11-23']))
            ->assertSessionHas('success', 'Attendance for Gopal Das on 23 Nov 2026 saved as present.');

        $day = $this->day();
        $this->assertSame('present', $day->status);
        $this->assertSame('manual', $day->source);
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertSame(560, (int) $day->worked_minutes);
        $this->assertSame(20, (int) $day->overtime_minutes);
        $this->assertSame('09:20', $this->localTime(CarbonImmutable::parse($day->check_in_at)));

        $event = $day->events()->sole();
        $this->assertSame('correction', $event->event_type);
        $this->assertSame('No smartphone', $event->metadata['note']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.marked_by_admin']);
    }

    public function test_check_in_after_the_grace_period_is_late(): void
    {
        $this->mark(['mark' => 'present', 'check_in' => '10:00'])->assertSessionHas('success');

        $day = $this->day();
        $this->assertSame('late', $day->status);
        $this->assertSame(15, (int) $day->late_minutes);   // shift 09:30 + 15 min grace
        $this->assertNull($day->check_out_at);
    }

    public function test_a_missed_app_check_out_can_be_completed(): void
    {
        $appPunch = Attendance::create([
            'staff_id' => $this->staff->id, 'work_date' => '2026-11-23', 'status' => 'present',
            'check_in_at' => CarbonImmutable::parse('2026-11-23 09:30', config('app.timezone')), 'source' => 'app',
        ]);

        $this->mark(['mark' => 'present', 'check_in' => '09:30', 'check_out' => '18:00'])->assertSessionHas('success');

        $this->assertSame(1, Attendance::where('staff_id', $this->staff->id)->count());
        $day = $appPunch->fresh();
        $this->assertSame('18:00', $this->localTime(CarbonImmutable::parse($day->check_out_at)));
        $this->assertSame(510, (int) $day->worked_minutes);
        $this->assertSame('present', $day->events()->sole()->metadata['before']['status']);
    }

    public function test_half_day_and_absent(): void
    {
        $this->mark(['mark' => 'half_day', 'check_in' => '09:30', 'check_out' => '13:30'])->assertSessionHas('success');
        $this->assertSame('half_day', $this->day()->status);
        $this->assertSame(240, (int) $this->day()->worked_minutes);

        $this->mark(['mark' => 'absent', 'check_in' => '09:30'])->assertSessionHas('success');
        $day = $this->day();
        $this->assertSame('absent', $day->status);
        $this->assertNull($day->check_in_at);
        $this->assertSame(0, (int) $day->worked_minutes);
    }

    public function test_marked_absent_today_shows_absent_not_not_checked_in(): void
    {
        $today = route('admin.attendance.index');
        $this->get($today)->assertSee('Not checked in');

        $this->from($today)->post(route('admin.attendance.mark', $this->staff), ['date' => '2026-11-24', 'mark' => 'absent']);
        $this->get($today)->assertSee('>Absent</span>', false)->assertDontSee('Not checked in');

        $this->from($today)->post(route('admin.attendance.mark', $this->staff), ['date' => '2026-11-24', 'mark' => 'half_day']);
        $this->get($today)->assertSee('>Half day</span>', false);
    }

    public function test_invalid_entries_are_refused(): void
    {
        $this->mark(['date' => '2026-11-25', 'mark' => 'present', 'check_in' => '09:30'])->assertSessionHasErrorsIn('mark', 'date');
        $this->mark(['mark' => 'present'])->assertSessionHasErrorsIn('mark', ['check_in' => 'Enter the check-in time.']);
        $this->mark(['mark' => 'present', 'check_in' => '18:00', 'check_out' => '09:00'])
            ->assertSessionHasErrorsIn('mark', ['check_out' => 'Check-out must be after check-in.']);

        $this->staff->update(['joining_date' => '2026-11-24']);
        $this->mark(['mark' => 'present', 'check_in' => '09:30'])->assertSessionHasErrorsIn('mark', 'date');

        $this->assertNull($this->day());
    }

    public function test_locked_payroll_month_cannot_change_but_a_draft_asks_for_a_recalculation(): void
    {
        $run = PayrollRun::create(['year' => 2026, 'month' => 11, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'locked']);

        $this->mark(['mark' => 'present', 'check_in' => '09:30'])
            ->assertSessionHas('error', "The November 2026 payroll is locked, so its attendance can't change. Reopen the payroll first.");
        $this->assertNull($this->day());

        $run->update(['status' => 'draft']);
        $this->mark(['mark' => 'present', 'check_in' => '09:30'])
            ->assertSessionHas('success', 'Attendance for Gopal Das on 23 Nov 2026 saved as present. Recalculate the November 2026 payroll to include it.');
    }

    public function test_attendance_list_shows_mark_and_edit_buttons_and_reopens_a_failed_entry(): void
    {
        $url = route('admin.attendance.index', ['date' => '2026-11-23']);
        $this->get($url)->assertOk()
            ->assertSee('data-mark-action="'.route('admin.attendance.mark', $this->staff).'"', false)
            ->assertSee('>Mark</button>', false);

        $failed = $this->from($url)->followingRedirects()->post(route('admin.attendance.mark', $this->staff), [
            'date' => '2026-11-23', 'mark' => 'present', 'mark_staff_id' => $this->staff->id, 'mark_staff_name' => 'Gopal Das',
        ])->assertOk()->assertSee('Enter the check-in time.');
        $this->assertMatchesRegularExpression('/id="mark-dialog" aria-labelledby="mark-title"\s+data-open-on-load/', $failed->getContent());
        $this->assertStringContainsString('action="'.route('admin.attendance.mark', $this->staff).'"', $failed->getContent());

        $this->mark(['mark' => 'present', 'check_in' => '09:20', 'check_out' => '18:30']);
        $this->get($url)->assertSee('data-mark-in="09:20"', false)->assertSee('>Edit</button>', false);
    }
}
