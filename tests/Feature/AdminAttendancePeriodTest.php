<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAttendancePeriodTest extends TestCase
{
    use RefreshDatabase;

    private Staff $asif;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 06:00:00'); // Thursday, 11:30 in Kolkata
        $this->seed(RolePermissionSeeder::class);
        Branch::create(['name' => 'HQ', 'code' => 'HQ', 'timezone' => 'Asia/Kolkata']);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->asif = $this->employee('Asif', 'Ali', 'EMP-101', '2026-01-01');
        $this->employee('Bina', 'Das', 'EMP-102', '2026-01-01');

        Attendance::create(['staff_id' => $this->asif->id, 'work_date' => '2026-11-24', 'check_in_at' => '2026-11-24 03:55:00',
            'check_out_at' => '2026-11-24 12:40:00', 'status' => 'present', 'worked_minutes' => 525]);
        Attendance::create(['staff_id' => $this->asif->id, 'work_date' => '2026-10-15', 'check_in_at' => '2026-10-15 04:30:00',
            'status' => 'late', 'late_minutes' => 20]);
        Holiday::create(['holiday_date' => '2026-11-20', 'name' => 'Founders Day', 'type' => 'company']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $first, string $last, string $code, string $joined): Staff
    {
        return Staff::create(['employee_code' => $code, 'first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}",
            'employment_status' => 'active', 'joining_date' => $joined]);
    }

    public function test_single_day_stays_the_default(): void
    {
        $this->get('/admin/attendance?date=2026-11-24&q=Asif+Ali')
            ->assertOk()
            ->assertSee('Tuesday, 24 November 2026')
            ->assertSee('Next day ›')
            ->assertSee('<option value="day" selected', false);
    }

    public function test_this_month_lists_every_day_for_the_searched_employee(): void
    {
        $response = $this->get('/admin/attendance?period=this_month&q=Asif+Ali')->assertOk();

        $response->assertSee('This month · 01 Nov 2026 – 26 Nov 2026 · 26 days')
            ->assertSee('24 Nov 2026')->assertSee('09:25 AM')->assertSee('01 Nov 2026')
            ->assertSee('Holiday · Founders Day')
            ->assertSee('Weekly off') // Sundays, the default weekly off
            ->assertDontSee('Bina Das')
            ->assertDontSee('27 Nov 2026')
            ->assertDontSee('Next day ›');
    }

    public function test_status_card_filters_the_whole_range(): void
    {
        $this->get('/admin/attendance?period=this_month&q=Asif+Ali&status=in')
            ->assertOk()
            ->assertSee('24 Nov 2026')
            ->assertDontSee('23 Nov 2026')
            ->assertSee('Showing 1–1 of 1');

        // Holidays and Sundays are not counted as absent.
        $this->get('/admin/attendance?period=this_month&q=Asif+Ali&status=absent')
            ->assertDontSee('20 Nov 2026')->assertDontSee('22 Nov 2026')->assertSee('23 Nov 2026');
    }

    public function test_last_month_covers_the_whole_previous_month(): void
    {
        $this->get('/admin/attendance?period=last_month&q=Asif')
            ->assertOk()
            ->assertSee('Last month · 01 Oct 2026 – 31 Oct 2026 · 31 days')
            ->assertSee('15 Oct 2026')->assertSee('Late')
            ->assertDontSee('24 Nov 2026');
    }

    public function test_this_year_is_paginated(): void
    {
        $this->get('/admin/attendance?period=this_year')
            ->assertOk()
            ->assertSee('This year · 01 Jan 2026 – 26 Nov 2026 · 330 days')
            ->assertSee('Showing 1–50 of 660')
            ->assertSee('page=2', false);
    }

    public function test_custom_range_is_ordered_and_never_reaches_the_future(): void
    {
        $this->get('/admin/attendance?period=custom&from=2026-11-22&to=2026-11-20&q=Asif')
            ->assertSee('Custom range · 20 Nov 2026 – 22 Nov 2026 · 3 days');

        $this->get('/admin/attendance?period=custom&from=2026-11-25&to=2027-01-31&q=Asif')
            ->assertSee('Custom range · 25 Nov 2026 – 26 Nov 2026 · 2 days');

        $this->get('/admin/attendance?period=custom&from=2020-01-01&to=2026-11-26&q=Asif')
            ->assertSee('26 Nov 2025 – 26 Nov 2026 · 366 days');
    }

    public function test_days_before_joining_are_left_out(): void
    {
        $this->employee('Chetan', 'Roy', 'EMP-103', '2026-11-25');

        $this->get('/admin/attendance?period=this_month&q=Chetan')
            ->assertSee('Showing 1–2 of 2')
            ->assertSee('25 Nov 2026')->assertDontSee('24 Nov 2026');
    }

    public function test_export_follows_the_period(): void
    {
        $response = $this->get('/admin/attendance/export?period=custom&from=2026-11-23&to=2026-11-24&q=Asif')->assertOk();

        $this->assertStringContainsString('attendance-2026-11-23-to-2026-11-24.csv', $response->headers->get('content-disposition'));
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Date,', $lines[0]);
        $this->assertStringStartsWith('2026-11-24,EMP-101,"Asif Ali"', $lines[1]);
        $this->assertStringStartsWith('2026-11-23,EMP-101,"Asif Ali"', $lines[2]);
    }
}
