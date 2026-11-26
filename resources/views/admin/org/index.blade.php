@extends('admin.layout')
@section('title', $def['title'])

@section('content')
    @include('admin.org._tabs')

    @isset($def['intro'])<p class="muted" style="margin-top:-6px">{{ $def['intro'] }}</p>@endisset

    @if (! empty($def['modal']))
        {{-- Add form in a pop-up. Re-opens by itself when the last submit failed validation. --}}
        <dialog id="add-record-dialog" class="dialog-wide" @if ($errors->any()) data-open-on-load @endif aria-labelledby="add-record-title">
            <form method="POST" action="{{ route('admin.org.store', $type) }}">
                @csrf
                <div class="card-head">
                    <h2 id="add-record-title">Add {{ strtolower($def['singular']) }}</h2>
                    <button type="button" class="btn sm ghost" data-close-dialog aria-label="Close">✕</button>
                </div>
                <div class="card-body">
                    @include('admin.org._fields', ['record' => $blank])
                </div>
                <div class="card-body actions" style="justify-content:flex-end;border-top:1px solid var(--border)">
                    <button type="button" class="btn" data-close-dialog>Cancel</button>
                    <button class="btn primary" type="submit">Add {{ strtolower($def['singular']) }}</button>
                </div>
            </form>
        </dialog>
    @else
        <details class="card" @if ($errors->any() || $rows->total() === 0) open @endif style="margin-bottom:16px">
            <summary class="card-head"><h2>+ Add {{ strtolower($def['singular']) }}</h2><span class="muted small">Click to {{ $errors->any() ? 'hide' : 'open' }}</span></summary>
            <form method="POST" action="{{ route('admin.org.store', $type) }}" class="card-body">
                @csrf
                @include('admin.org._fields', ['record' => $blank])
                <div class="actions" style="justify-content:flex-end">
                    <button class="btn primary" type="submit">Add {{ strtolower($def['singular']) }}</button>
                </div>
            </form>
        </details>
    @endif

    @if ($type === 'holidays')
        @php $skipped = session('holiday_import_skipped', []); @endphp
        @if ($skipped)
            <div class="alert error" role="alert">
                <div>
                    <strong>These rows were not imported:</strong>
                    <ul>
                        @foreach ($skipped as $row)
                            <li>Line {{ $row['line'] }} · {{ $row['name'] }}: {{ $row['reason'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
        <details class="card" style="margin-bottom:16px" @if ($skipped || $errors->has('file')) open @endif>
            <summary class="card-head"><h2>⇪ Import holidays from CSV</h2><span class="muted small">Add a whole year's list at once</span></summary>
            <form method="POST" action="{{ route('admin.holidays.import') }}" enctype="multipart/form-data" class="card-body">
                @csrf
                <p class="muted" style="margin:0 0 12px">
                    Columns: <strong>Date</strong> and <strong>Name</strong> (required), <strong>Type</strong> (Public, Optional or Company; empty = Public)
                    and <strong>Branch</strong> (branch name or code; empty = all branches). Dates like 26/01/{{ now()->year + 1 }}, {{ now()->year + 1 }}-01-26 or 26 January {{ now()->year + 1 }}.
                    Days that already have a holiday for the same branch are skipped.
                </p>
                <div class="toolbar">
                    <input type="file" name="file" accept=".csv,text/csv" required class="{{ $errors->has('file') ? 'is-invalid' : '' }}">
                    <button class="btn primary" type="submit">Import</button>
                    <a class="btn ghost" href="{{ route('admin.holidays.import.template') }}">Download template</a>
                </div>
            </form>
        </details>
    @endif

    <div class="card">
        <div class="card-head">
            <h2>{{ $def['title'] }} <span class="muted small">({{ $rows->total() }})</span></h2>
            <div class="actions">
                <form method="GET" class="toolbar">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($def['title']) }}">
                </form>
                @if (! empty($def['modal']))
                    <button type="button" class="btn primary" data-open-dialog="add-record-dialog">+ Add {{ strtolower($def['singular']) }}</button>
                @endif
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    @foreach ($def['columns'] as $col)<th>{{ is_array($col) ? $col[0] : $col }}</th>@endforeach
                    <th class="right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($def['columns'] as $key => $col)
                            @php $val = is_array($col) ? ($col[1])($row) : $row->{$key}; @endphp
                            <td>
                                @if ($key === 'status')
                                    <span class="badge {{ $val === 'active' ? 'ok' : '' }}">{{ ucfirst($val) }}</span>
                                @elseif ($loop->first)
                                    <strong>{{ $val }}</strong>
                                @else
                                    {{ $val === null || $val === '' ? '—' : $val }}
                                @endif
                            </td>
                        @endforeach
                        <td class="right nowrap">
                            <a class="btn sm" href="{{ route('admin.org.edit', [$type, $row->id]) }}">Edit</a>
                            <form class="inline" method="POST" action="{{ route('admin.org.destroy', [$type, $row->id]) }}" data-confirm="Delete this {{ strtolower($def['singular']) }}? This cannot be undone.">
                                @csrf @method('DELETE')
                                <button class="btn sm danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($def['columns']) + 1 }}" class="empty">Nothing here yet. Add the first {{ strtolower($def['singular']) }} {{ empty($def['modal']) ? 'above' : 'with the button above' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links('admin.partials.pagination') }}
    </div>
@endsection
