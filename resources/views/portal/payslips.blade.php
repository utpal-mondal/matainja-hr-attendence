@extends('portal.layout')
@section('title', 'Payslips')

@section('content')
    @php
        $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
        $days = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    @endphp

    <div class="page-head">
        <div>
            <h1>Payslips</h1>
            <p>Your payslips appear here once HR finalises the month's payroll.</p>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Month</th><th>Paid days</th><th class="right">Gross</th><th class="right">Deductions</th><th class="right">Net pay</th><th>Status</th><th class="right"></th></tr></thead>
                <tbody>
                @forelse ($payslips as $payslip)
                    <tr>
                        <td><strong>{{ $payslip->run->label }}</strong></td>
                        <td>{{ $days($payslip->paid_days) }} / {{ $payslip->period_days }}@if ((float) $payslip->lop_days > 0)<span class="sub">{{ $days($payslip->lop_days) }} LOP</span>@endif</td>
                        <td class="right nowrap">{{ $money($payslip->gross_earnings) }}</td>
                        <td class="right nowrap">{{ $money($payslip->total_deductions) }}</td>
                        <td class="right nowrap"><strong>{{ $money($payslip->net_pay) }}</strong></td>
                        <td>
                            @if ($payslip->run->status === 'paid')
                                <span class="badge ok">Paid</span>@if ($payslip->run->paid_on)<span class="sub">{{ $payslip->run->paid_on->format('d M Y') }}</span>@endif
                            @else
                                <span class="badge info">Processed</span>
                            @endif
                        </td>
                        <td class="right nowrap">
                            <a class="btn sm" href="{{ route('portal.payslips.pdf', [$payslip, 'inline' => 1]) }}" target="_blank" rel="noopener">View</a>
                            <a class="btn sm" href="{{ route('portal.payslips.pdf', $payslip) }}">Download</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No payslips yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payslips->links('admin.partials.pagination') }}
    </div>
@endsection
