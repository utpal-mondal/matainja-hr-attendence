{{--
    Server-rendered payslip PDF (dompdf), one payslip per page, laid out like the company's own payslip:
    address · PAY SLIP · logo, name and bank details, one earnings/deductions table, Paid by / Received by.
    Tables only: dompdf has no flexbox/grid.
--}}
@php
    $amount = fn ($v) => number_format((float) $v, 2, '.', '');
    // Statutory deductions always print, at 0.00 when nothing is due, under the names on the company's slip.
    $statutory = ['PF' => 'EPF', 'ESI' => 'ESI', 'PT' => 'P-tax', 'TDS' => 'TDS (Tax Deducted at Source)'];
    $period = $run->period_start->format('M').'’'.$run->period_start->format('y');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $run->label }}</title>
    <style>
        @page { margin: 14mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }

        .head td { vertical-align: top; }
        .address { font-size: 8pt; line-height: 1.4; padding-top: 14px; }
        .title { text-align: center; color: #2f5597; font-size: 18pt; font-weight: bold; padding-top: 8px; }
        .stamp { display: inline-block; margin-top: 4px; border: 1.5pt solid #b7791f; color: #b7791f; padding: 1px 8px; font-weight: bold; letter-spacing: 1px; font-size: 8pt; }
        .logo { text-align: right; }

        .who { margin-top: 30px; font-size: 11.5pt; }
        .who td { padding: 6px 10px 6px 18px; width: 50%; }

        .slip { margin-top: 50px; border: .75pt solid #111; }
        .slip td { border: .75pt solid #111; padding: 4px 8px; width: 50%; }
        .slip td.amt { text-align: center; }
        .slip tr.section td { text-align: center; font-weight: bold; padding: 7px 8px; }
        .slip tr.line td { padding: 6px 8px; }
        .slip tr.small td { padding: 2px 8px; }
        .slip tr.total td { font-weight: bold; padding: 7px 8px; }
        .u { text-decoration: underline; }
        .b { font-weight: bold; }

        .sign { margin: 60px 0 0 6%; width: 94%; border: .75pt solid #bbb; }
        .sign td { border: .75pt solid #bbb; padding: 6px 8px 24px; width: 50%; }
    </style>
</head>
<body>
@foreach ($payslips as $payslip)
    @php
        $earnings = $payslip->items->where('type', 'earning')->sortBy('sort_order')->values();
        $deductions = $payslip->items->where('type', 'deduction');
        $statutoryAmounts = $deductions->where('source', 'statutory')->pluck('amount', 'code');
        $otherDeductions = $deductions->where('source', '!=', 'statutory')->sortBy('sort_order')->values();
        $profile = $payslip->staff?->payrollProfile;
    @endphp
    <div class="page">
        <table class="head">
            <tr>
                <td class="address" style="width:34%">
                    @if ($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
                    @if ($company['phone'])<span class="b">Phone:</span> {{ $company['phone'] }}<br>@endif
                    @if ($company['email'])<span class="b">Email:</span> {{ $company['email'] }}<br>@endif
                    @if ($company['website']){{ $company['website'] }}@endif
                </td>
                <td class="title" style="width:32%">
                    PAY SLIP
                    @if ($run->isDraft())<br><span class="stamp">DRAFT</span>@endif
                </td>
                <td class="logo" style="width:34%">
                    @if (! empty($company['logo']))
                        <img src="{{ $company['logo'] }}" alt="" style="max-height:60px;max-width:150px">
                    @else
                        <span class="b" style="font-size:12pt">{{ $company['name'] }}</span>
                    @endif
                </td>
            </tr>
        </table>

        <table class="who">
            <tr>
                <td>Name: {{ $payslip->employee_name }}</td>
                <td>A/C NO: {{ $profile?->bank_account_number ?: '—' }}</td>
            </tr>
            <tr>
                <td>Period of Payment: {{ $period }}</td>
                <td>IFSC: {{ $profile?->bank_ifsc ?: '—' }}</td>
            </tr>
        </table>

        <table class="slip">
            <tr class="section">
                <td>Component: <span class="u">Earnings</span></td>
                <td class="u">Amount (₹) (Per Month)</td>
            </tr>
            @foreach ($earnings as $item)
                <tr class="line"><td>{{ $item->name }}</td><td class="amt">{{ $amount($item->amount) }}</td></tr>
            @endforeach

            <tr class="section">
                <td>Component: <span class="u">Deductions</span></td>
                <td class="u">Amount (₹) (Per Month)</td>
            </tr>
            @foreach ($otherDeductions as $item)
                <tr class="small"><td>{{ $item->name }}</td><td class="amt">{{ $amount($item->amount) }}</td></tr>
            @endforeach
            @foreach ($statutory as $code => $label)
                <tr class="small"><td>{{ $label }}</td><td class="amt">{{ $amount($statutoryAmounts[$code] ?? 0) }}</td></tr>
            @endforeach
            <tr class="small">
                <td class="b">Total Deductions</td><td class="amt">{{ $amount($payslip->total_deductions) }}</td>
            </tr>

            <tr class="total">
                <td style="text-align:center">Component: <span class="u">Net Payable Amount</span></td>
                <td class="amt">{{ $amount($payslip->net_pay) }}</td>
            </tr>
            <tr class="total">
                <td style="text-align:center">Component: <span class="u">Monthly Gross</span></td>
                <td class="amt">{{ $amount($payslip->gross_earnings) }}</td>
            </tr>
        </table>

        <table class="sign">
            <tr>
                <td>Paid By: <span class="b">{{ $company['name'] }}</span></td>
                <td>Received By: {{ $payslip->employee_name }}</td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
