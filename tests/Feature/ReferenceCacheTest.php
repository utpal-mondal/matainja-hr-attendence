<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    /** The real app runs CACHE_STORE=database, whose increment() ignores missing keys. */
    public function test_a_holiday_added_in_the_admin_panel_shows_in_the_api_at_once_with_the_database_cache(): void
    {
        config(['cache.default' => 'database']);
        $this->seed(RolePermissionSeeder::class);
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        Sanctum::actingAs($employee);

        Holiday::create(['holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'public']);
        $this->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(1, 'data'); // now cached

        // Same as the admin panel's Organisation > Holidays form (a model save).
        Holiday::create(['holiday_date' => '2026-12-31', 'name' => 'Year end', 'type' => 'company']);

        $this->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data');
    }
}
