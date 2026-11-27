<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        config(['cache.default' => 'database']); // The real app runs CACHE_STORE=database, whose increment() ignores missing keys.
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Staff
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $branch = Branch::create(['name' => 'HQ', 'code' => 'HQ', 'timezone' => 'Asia/Kolkata']);

        return Staff::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP'.$user->id,
            'first_name' => 'Riya',
            'last_name' => 'Sen',
            'email' => $user->email,
            'branch_id' => $branch->id,
            'employment_status' => 'active',
            'web_login_enabled' => true,
        ]);
    }

    public function test_a_holiday_added_in_the_admin_panel_shows_in_the_api_at_once_with_the_database_cache(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        Sanctum::actingAs($employee);
    }

    public function test_holidays_are_never_cached(): void
    {
        Holiday::create(['holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'public']);
        $this->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(1, 'data');

        // Even a row written without model events (raw SQL, an import...) shows at once.
        DB::table('holidays')->insert([
            'holiday_date' => '2026-12-31', 'name' => 'Year end', 'type' => 'company',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_portal_holiday_pages_are_cached_and_show_a_new_holiday_at_once(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff->user);
        $christmas = Holiday::create(['holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'public']);

        $this->get('/portal/holidays')->assertOk()->assertSee('Christmas'); // now cached
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%ref:holidays:%portal:y2026%')->count());

        Holiday::create(['holiday_date' => '2026-12-31', 'name' => 'Year end', 'type' => 'company', 'branch_id' => $staff->branch_id]);
        Holiday::create(['holiday_date' => '2026-11-20', 'name' => 'Other office day', 'type' => 'company',
            'branch_id' => Branch::create(['name' => 'Pune', 'code' => 'PN', 'timezone' => 'Asia/Kolkata'])->id]);

        $this->get('/portal/holidays')->assertSee('Christmas')->assertSee('Year end')->assertDontSee('Other office day');
        $this->get('/portal')->assertSee('Christmas')->assertSee('Year end');

        $christmas->delete();
        $this->get('/portal/holidays')->assertDontSee('Christmas')->assertSee('Year end');
    }

    public function test_the_calendar_holidays_are_cached_and_show_a_new_holiday_at_once(): void
    {
        $staff = $this->employee();
        Sanctum::actingAs($staff->user);
        Holiday::create(['holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'public']);

        $this->getJson('/api/v1/calendar?month=2026-12')->assertOk()->assertJsonCount(1, 'data.events'); // now cached
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%ref:holidays:%calendar:y2026:m12%')->count());

        Holiday::create(['holiday_date' => '2026-12-31', 'name' => 'Year end', 'type' => 'company']);

        $this->getJson('/api/v1/calendar?month=2026-12')->assertOk()
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.1.name', 'Year end');
    }
}
