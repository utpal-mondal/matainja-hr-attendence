<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_sees_the_command_runner_in_the_sidebar(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.console.index'))
            ->assertOk()
            ->assertSee('Command runner')
            ->assertSee(route('admin.console.index'), false);
    }

    public function test_guests_and_non_admins_cannot_run_commands(): void
    {
        $this->post(route('admin.console.run'), ['command' => 'php artisan about'])->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.console.run'), ['command' => 'php artisan about'])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'console.ran']);
    }

    public function test_runs_an_artisan_command_and_shows_its_output(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.console.run'), ['command' => 'php artisan route:list --name=admin.console'])
            ->assertRedirect()
            ->assertSessionHas('consoleResult', fn (array $result) => $result['exitCode'] === 0
                && $result['command'] === 'php artisan route:list --name=admin.console'
                && str_contains($result['output'], 'admin.console.run'));

        $log = AuditLog::where('action', 'console.ran')->sole();
        $this->assertSame('php artisan route:list --name=admin.console', $log->after['command']);
        $this->assertSame(0, $log->after['exit_code']);
    }

    public function test_accepts_artisan_without_the_php_prefix(): void
    {
        Process::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.console.run'), ['command' => 'artisan about --only=environment'])
            ->assertSessionHas('consoleResult', fn (array $result) => $result['type'] === 'artisan'
                && $result['command'] === 'php artisan about --only=environment');

        Process::assertNothingRan();
    }

    public function test_runs_a_shell_command_from_the_project_root(): void
    {
        Process::fake(['git status' => Process::result(output: 'On branch main', errorOutput: 'a warning', exitCode: 0)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.console.run'), ['command' => 'git status'])
            ->assertSessionHas('consoleResult', fn (array $result) => $result['type'] === 'shell'
                && $result['exitCode'] === 0
                && str_contains($result['output'], 'On branch main')
                && str_contains($result['output'], 'a warning'));

        Process::assertRan(fn (PendingProcess $process) => $process->command === 'git status' && $process->path === base_path());
        $this->assertSame('shell', AuditLog::where('action', 'console.ran')->sole()->after['type']);
    }

    public function test_chained_artisan_commands_go_to_the_shell(): void
    {
        Process::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.console.run'), ['command' => 'php artisan migrate --force && php artisan db:seed --force'])
            ->assertSessionHas('consoleResult', fn (array $result) => $result['type'] === 'shell');

        Process::assertRan('php artisan migrate --force && php artisan db:seed --force');
    }

    public function test_reports_a_failing_shell_command(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'not found', exitCode: 127)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.console.run'), ['command' => 'nope'])
            ->assertSessionHas('consoleResult', fn (array $result) => $result['exitCode'] === 127 && $result['output'] === 'not found');
    }

    public function test_rejects_unknown_and_blocked_artisan_commands(): void
    {
        Process::fake();
        $this->actingAs($this->superAdmin());

        foreach (['php artisan not-a-command', 'php artisan', 'php artisan serve', 'php artisan queue:work'] as $command) {
            $this->post(route('admin.console.run'), ['command' => $command])
                ->assertSessionHas('error')
                ->assertSessionMissing('consoleResult');
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'console.ran']);
        Process::assertNothingRan();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }
}
