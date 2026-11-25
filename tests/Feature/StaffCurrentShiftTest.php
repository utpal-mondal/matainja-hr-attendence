<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffShiftAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffCurrentShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin);
    }

    private function staff(string $code): Staff
    {
        return Staff::create(['employee_code' => $code, 'first_name' => $code, 'last_name' => 'Test', 'email' => "$code@example.com"]);
    }

    public function test_staff_list_includes_each_persons_current_shift(): void
    {
        $old = Shift::create(['name' => 'Old', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15]);
        $new = Shift::create(['name' => 'Day', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 15]);

        $changed = $this->staff('E1');
        // Old shift closed today, new one starts today: the new one must win.
        StaffShiftAssignment::create(['staff_id' => $changed->id, 'shift_id' => $old->id, 'effective_from' => now()->subMonth(), 'effective_to' => now()]);
        StaffShiftAssignment::create(['staff_id' => $changed->id, 'shift_id' => $new->id, 'effective_from' => now()]);

        $future = $this->staff('E2');
        StaffShiftAssignment::create(['staff_id' => $future->id, 'shift_id' => $new->id, 'effective_from' => now()->addWeek()]);

        $rows = collect($this->getJson('/api/v1/staff?per_page=100')->assertOk()->json('data'))->keyBy('employee_code');

        $this->assertSame('Day', $rows['E1']['shift']['name']);
        $this->assertStringStartsWith('10:30', $rows['E1']['shift']['start_time']);
        $this->assertStringStartsWith('19:30', $rows['E1']['shift']['end_time']);
        $this->assertNull($rows['E2']['shift']); // not started yet = not assigned

        $this->getJson("/api/v1/staff/{$changed->id}")->assertOk()->assertJsonPath('data.shift.name', 'Day');
    }
}
