<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Runs commands on the server from the Super Admin panel. Input starting with
 * "php artisan" or "artisan" runs in-process through Artisan; anything else runs
 * in the OS shell from the project root. Every run is audit-logged.
 */
class ConsoleController extends AdminController
{
    /**
     * Artisan commands that never finish, wait for keyboard input, or would take the
     * panel down with them, so they cannot be run from a web request.
     *
     * @var list<string>
     */
    public const BLOCKED_COMMANDS = [
        'serve', 'tinker', 'pail', 'down',
        'queue:work', 'queue:listen', 'schedule:work', 'schedule:run',
        'horizon', 'octane:start', 'reverb:start', 'sail:install',
    ];

    /** Seconds a command may run before it is stopped. */
    public const TIMEOUT_SECONDS = 300;

    /** Longest output kept in the session for display, in characters. */
    private const MAX_OUTPUT_LENGTH = 100_000;

    public function index(): View
    {
        return view('admin.console.index', [
            'commands' => collect(Artisan::all())->keys()
                ->reject(fn (string $name) => in_array($name, self::BLOCKED_COMMANDS, true) || str_starts_with($name, '_'))
                ->sort()->values(),
            'recentRuns' => AuditLog::with('actor:id,name')->where('action', 'console.ran')->latest('id')->take(10)->get(),
            'tz' => $this->tz(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate(['command' => ['required', 'string', 'max:2000']]);

        $command = trim($validated['command']);
        $artisanCommandLine = $this->artisanCommandLine($command);

        if ($artisanCommandLine !== null) {
            $commandName = Str::before($artisanCommandLine, ' ');

            if ($artisanCommandLine === '') {
                return back()->withInput()->with('error', 'Type an Artisan command after "php artisan", e.g. "php artisan migrate".');
            }
            if (! array_key_exists($commandName, Artisan::all())) {
                return back()->withInput()->with('error', "\"{$commandName}\" is not an Artisan command.");
            }
            if (in_array($commandName, self::BLOCKED_COMMANDS, true)) {
                return back()->withInput()->with('error', "\"{$commandName}\" can't be run from the browser: it runs forever, waits for input, or would take this panel offline.");
            }
        }

        set_time_limit(self::TIMEOUT_SECONDS + 30);
        $startedAt = microtime(true);

        [$exitCode, $output] = $artisanCommandLine !== null
            ? $this->runArtisan($artisanCommandLine)
            : $this->runShell($command);

        $seconds = round(microtime(true) - $startedAt, 2);
        $type = $artisanCommandLine !== null ? 'artisan' : 'shell';
        $displayCommand = $artisanCommandLine !== null ? 'php artisan '.$artisanCommandLine : $command;

        AuditLog::record('console.ran', null, null, [
            'type' => $type,
            'command' => $displayCommand,
            'exit_code' => $exitCode,
            'seconds' => $seconds,
        ]);

        return back()->withInput()->with('consoleResult', [
            'type' => $type,
            'command' => $displayCommand,
            'exitCode' => $exitCode,
            'seconds' => $seconds,
            'output' => Str::limit(trim($output), self::MAX_OUTPUT_LENGTH, "\n… output truncated"),
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runArtisan(string $commandLine): array
    {
        try {
            $exitCode = Artisan::call($commandLine.' --no-interaction');

            return [$exitCode, Artisan::output()];
        } catch (Throwable $e) {
            return [1, $e->getMessage()];
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runShell(string $command): array
    {
        try {
            $result = Process::path(base_path())->timeout(self::TIMEOUT_SECONDS)->run($command);

            return [$result->exitCode() ?? 1, trim($result->output()."\n".$result->errorOutput())];
        } catch (Throwable $e) {
            return [1, $e->getMessage()];
        }
    }

    /**
     * The Artisan part of "php artisan migrate" / "artisan migrate" ("migrate"),
     * or null when the input is a shell command. Input that chains, pipes or
     * redirects (&&, |, ;, >, <) always goes to the shell.
     */
    private function artisanCommandLine(string $input): ?string
    {
        $commandLine = preg_replace('/\s+/', ' ', $input);

        if (preg_match('/[|&;<>`]/', $commandLine) || ! preg_match('/^(php\s+)?artisan(\s+|$)/i', $commandLine, $match)) {
            return null;
        }

        return trim(substr($commandLine, strlen($match[0])));
    }
}
