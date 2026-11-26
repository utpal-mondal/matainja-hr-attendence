<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_registers_an_asset_with_an_image_and_can_read_it_back(): void
    {
        Sanctum::actingAs($this->userWithRole('HR Admin'));

        $res = $this->post('/api/v1/assets', [
            'asset_tag' => 'LAP-001',
            'name' => 'Dell Latitude',
            'category' => 'Laptop',
            'image' => UploadedFile::fake()->image('laptop.jpg', 640, 480),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertTrue($res->json('data.has_image'));
        $this->assertArrayNotHasKey('image_path', $res->json('data'));

        $asset = Asset::sole();
        Storage::disk('local')->assertExists($asset->image_path);

        $this->get("/api/v1/assets/{$asset->id}/image")->assertOk();
        $this->getJson('/api/v1/assets')->assertOk()->assertJsonPath('data.data.0.has_image', true);
    }

    public function test_image_is_optional(): void
    {
        Sanctum::actingAs($this->userWithRole('Super Admin'));

        $this->postJson('/api/v1/assets', ['asset_tag' => 'MON-1', 'name' => 'Monitor'])
            ->assertCreated()
            ->assertJsonPath('data.has_image', false);

        $this->getJson('/api/v1/assets/'.Asset::sole()->id.'/image')->assertNotFound();
    }

    public function test_a_manager_can_add_an_asset_and_replace_its_image(): void
    {
        Sanctum::actingAs($this->userWithRole('Manager'));

        $this->postJson('/api/v1/assets', ['asset_tag' => 'PH-1', 'name' => 'Phone'])->assertCreated();
        $asset = Asset::sole();

        $this->post("/api/v1/assets/{$asset->id}/image", ['image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertOk();
        $first = $asset->fresh()->image_path;
        $this->post("/api/v1/assets/{$asset->id}/image", ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($asset->fresh()->image_path);
    }

    public function test_non_images_are_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('HR Admin'));

        $this->post('/api/v1/assets', [
            'asset_tag' => 'X-1',
            'name' => 'Thing',
            'image' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_an_employee_sees_only_the_image_of_an_asset_assigned_to_them(): void
    {
        $employee = $this->userWithRole('Employee');
        $staff = Staff::create(['employee_code' => 'E-1', 'first_name' => 'Asha', 'employment_status' => 'active', 'user_id' => $employee->id]);

        Storage::disk('local')->put('assets/a.png', 'png');
        $mine = Asset::create(['asset_tag' => 'A-1', 'name' => 'Mine', 'image_path' => 'assets/a.png']);
        $other = Asset::create(['asset_tag' => 'A-2', 'name' => 'Other', 'image_path' => 'assets/a.png']);
        $mine->assignments()->create(['staff_id' => $staff->id, 'assigned_at' => now()]);

        Sanctum::actingAs($employee);
        $this->get("/api/v1/assets/{$mine->id}/image")->assertOk();
        $this->getJson("/api/v1/assets/{$other->id}/image")->assertNotFound();
        $this->postJson('/api/v1/assets', ['asset_tag' => 'A-3', 'name' => 'Nope'])->assertForbidden();
    }
}
