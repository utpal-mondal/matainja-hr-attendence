<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The real app runs CACHE_STORE=database, whose increment() ignores missing keys.
        config(['cache.default' => 'database']);
        $this->seed(RolePermissionSeeder::class);
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

    public function test_cached_reference_data_is_refreshed_after_a_change_with_the_database_cache(): void
    {
        Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30']);
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonCount(1, 'data'); // now cached

        Shift::create(['name' => 'Night', 'start_time' => '21:00', 'end_time' => '06:00', 'crosses_midnight' => true]);

        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonCount(2, 'data');
    }
}
