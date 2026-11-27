@extends('admin.layout')
@section('title', 'Command runner')

@section('content')
    @php($result = session('consoleResult'))
    <div class="page-head">
        <div>
            <h1>Command runner</h1>
            <p>Run Artisan or shell commands on this server, e.g. <span class="mono">php artisan migrate</span> or <span class="mono">git pull</span>. Every run is recorded in the audit log.</p>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.console.run') }}"
                      data-confirm="Run this command on the live server?">
                    @csrf
                    <div class="field">
                        <label for="command">Command</label>
                        <input id="command" name="command" class="mono" list="artisan-commands" autocomplete="off" required maxlength="500"
                               value="{{ old('command', $result['command'] ?? 'php artisan ') }}" placeholder="php artisan migrate  ·  git pull  ·  composer install" autofocus>
                        <datalist id="artisan-commands">
                            @foreach ($commands as $name)<option value="php artisan {{ $name }}">@endforeach
                        </datalist>
                        <div class="hint">
                            Commands starting with <span class="mono">php artisan</span> run through Artisan; anything else runs in the server's shell from the project folder
                            (stopped after {{ \App\Http\Controllers\Admin\ConsoleController::TIMEOUT_SECONDS / 60 }} minutes, so avoid commands that never exit, like <span class="mono">npm run dev</span>).
                            Nothing can answer prompts: on production, add <span class="mono">--force</span> to Artisan commands that ask for confirmation (such as <span class="mono">migrate</span>).
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn primary" type="submit">Run command</button>
                    </div>
                </form>
            </div>
        </div>

        @if ($result)
            <div class="card">
                <div class="card-head">
                    <h2 class="mono">{{ $result['command'] }}</h2>
                    <span class="badge {{ $result['exitCode'] === 0 ? 'ok' : 'bad' }}">
                        {{ $result['exitCode'] === 0 ? 'Succeeded' : 'Failed (exit '.$result['exitCode'].')' }} · {{ $result['seconds'] }}s
                    </span>
                </div>
                <div class="card-body">
                    <pre class="mono" style="white-space:pre-wrap;margin:0;max-height:520px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px">{{ $result['output'] !== '' ? $result['output'] : '(no output)' }}</pre>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>Recent runs</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Who</th><th>Command</th><th>Result</th></tr></thead>
                    <tbody>
                    @forelse ($recentRuns as $run)
                        <tr>
                            <td class="nowrap">{{ $run->created_at?->setTimezone($tz)->format('d M Y, h:i A') }}</td>
                            <td>{{ $run->actor?->name ?? 'System' }}</td>
                            <td class="mono"><span class="badge {{ ($run->after['type'] ?? 'artisan') === 'shell' ? 'warn' : 'brand' }}">{{ $run->after['type'] ?? 'artisan' }}</span> {{ $run->after['command'] ?? '—' }}</td>
                            <td>
                                @if (($run->after['exit_code'] ?? 1) === 0)
                                    <span class="badge ok">OK</span>
                                @else
                                    <span class="badge bad">Exit {{ $run->after['exit_code'] ?? '?' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No commands run yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
