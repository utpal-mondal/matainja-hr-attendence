@extends('portal.layout')
@section('title', 'Holidays')

@section('content')
    @php $typeBadge = ['public' => 'brand', 'optional' => 'warn', 'restricted' => 'warn', 'company' => 'info']; @endphp

    <div class="page-head">
        <div>
            <h1>Holidays {{ $year }}</h1>
            <p>Company-wide holidays and the ones for your branch.</p>
        </div>
        <div class="pills">
            @foreach ([$currentYear - 1, $currentYear, $currentYear + 1] as $y)
                <a href="{{ route('portal.holidays', ['year' => $y]) }}" class="{{ $y === $year ? 'active' : '' }}">{{ $y }}</a>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Day</th><th>Holiday</th><th>Type</th></tr></thead>
                <tbody>
                @forelse ($holidays as $h)
                    @php $past = $h->holiday_date->toDateString() < $today->toDateString(); @endphp
                    <tr style="{{ $past ? 'opacity:.55' : '' }}">
                        <td class="nowrap"><strong>{{ $h->holiday_date->format('d M Y') }}</strong></td>
                        <td>{{ $h->holiday_date->format('l') }}</td>
                        <td>{{ $h->name }}@if ($h->holiday_date->toDateString() === $today->toDateString()) <span class="badge ok">Today</span>@endif</td>
                        <td><span class="badge {{ $typeBadge[$h->type] ?? '' }}">{{ ucfirst($h->type ?? 'public') }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No holidays listed for {{ $year }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
