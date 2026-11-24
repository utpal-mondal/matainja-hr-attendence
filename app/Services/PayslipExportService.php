<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\Payslip;
use Barryvdh\DomPDF\PDF;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Payslip PDFs and the payroll files that go to the bank and the accountant.
 */
class PayslipExportService
{
    public function __construct(private InvoiceService $invoices) {}

    /**
     * One payslip per page.
     *
     * @param  Collection<int, Payslip>  $payslips  of the same run
     */
    public function pdf(PayrollRun $run, Collection $payslips): PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('payslips.pdf', $this->viewData($run, $payslips))->setPaper('a4');
    }

    /**
     * What the payslips.pdf view needs.
     *
     * @param  Collection<int, Payslip>  $payslips
     * @return array{run: PayrollRun, payslips: Collection<int, Payslip>, company: array<string, string|null>}
     */
    public function viewData(PayrollRun $run, Collection $payslips): array
    {
        return [
            'run' => $run,
            'payslips' => $payslips->loadMissing(['items', 'staff.payrollProfile']),
            'company' => $this->invoices->company(),
        ];
    }

    /** e.g. payslip-2026-11-EMP-001.pdf, or payslips-2026-11.pdf for the whole run. */
    public function pdfFilename(PayrollRun $run, ?Payslip $payslip = null): string
    {
        $month = sprintf('%04d-%02d', $run->year, $run->month);
        if (! $payslip) {
            return "payslips-{$month}.pdf";
        }
        $who = Str::slug($payslip->employee_code ?: $payslip->employee_name) ?: (string) $payslip->staff_id;

        return "payslip-{$month}-{$who}.pdf";
    }

    /**
     * Net pay per employee with their bank details, for the salary transfer.
     *
     * @return array{0: list<string>, 1: list<list<string|float|null>>, 2: int} header, rows, employees missing bank details
     */
    public function bankTransfer(PayrollRun $run): array
    {
        $header = ['Employee code', 'Employee name', 'Account holder', 'Account number', 'IFSC', 'Bank', 'Amount', 'Narration', 'Problem'];
        $narration = 'Salary '.$run->label;
        $missing = 0;

        $rows = $run->payslips()->with('staff.payrollProfile')->where('net_pay', '>', 0)->orderBy('employee_name')->get()
            ->map(function (Payslip $p) use ($narration, &$missing) {
                $profile = $p->staff?->payrollProfile;
                $problem = match (true) {
                    ! $profile?->bank_account_number => 'No bank account number',
                    ! $profile->bank_ifsc => 'No IFSC',
                    default => null,
                };
                if ($problem) {
                    $missing++;
                }

                return [
                    $p->employee_code, $p->employee_name,
                    $profile?->bank_account_name ?: $p->employee_name,
                    $profile?->bank_account_number, $profile?->bank_ifsc, $profile?->bank_name,
                    number_format((float) $p->net_pay, 2, '.', ''), $narration, $problem,
                ];
            })->all();

        return [$header, $rows, $missing];
    }

    /**
     * Every figure of every payslip: one column per earning, then the
     * statutory deductions and employer contributions.
     *
     * @return array{0: list<string>, 1: list<list<string|float|null>>}
     */
    public function register(PayrollRun $run): array
    {
        $payslips = $run->payslips()->with('items')->orderBy('employee_name')->get();

        // Earning columns in payslip order, e.g. Basic, House Rent Allowance, Special Allowance, Overtime, Diwali bonus.
        $earningNames = $payslips->flatMap(fn (Payslip $p) => $p->items->where('type', 'earning'))
            ->sortBy('sort_order')->pluck('name')->unique()->values();

        $header = [
            'Employee code', 'Employee name', 'Department', 'Designation', 'PAN', 'UAN', 'Tax regime',
            'Days in period', 'Paid days', 'LOP days',
            ...$earningNames->all(),
            'Gross earnings', 'Taxable earnings', 'PF (employee)', 'ESI (employee)', 'Professional Tax', 'TDS', 'Other deductions',
            'Total deductions', 'Net pay', 'PF (employer)', 'of which EPS', 'ESI (employer)',
        ];

        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $rows = $payslips->map(function (Payslip $p) use ($earningNames, $money) {
            $earnings = $p->items->where('type', 'earning')->groupBy('name')->map(fn ($items) => $items->sum('amount'));
            $other = $p->items->where('type', 'deduction')->where('source', '!=', 'statutory')->sum('amount');

            return [
                $p->employee_code, $p->employee_name, $p->department, $p->designation, $p->pan, $p->uan, $p->tax_regime,
                $p->period_days, (float) $p->paid_days, (float) $p->lop_days,
                ...$earningNames->map(fn ($name) => $money($earnings[$name] ?? 0))->all(),
                $money($p->gross_earnings), $money($p->taxable_earnings), $money($p->employee_pf), $money($p->employee_esi),
                $money($p->professional_tax), $money($p->tds), $money($other),
                $money($p->total_deductions), $money($p->net_pay), $money($p->employer_pf), $money($p->employer_eps), $money($p->employer_esi),
            ];
        })->all();

        return [$header, $rows];
    }

    /**
     * EPFO ECR 2.0 text file: one line per member, 11 fields joined by
     * "#~#", whole rupees, no header. Members without a valid UAN can't be
     * filed and are returned as left out instead.
     *
     * @return array{lines: list<string>, left_out: list<array{name: string, reason: string}>, totals: array{members: int, epf_wages: int, eps_wages: int, employee_share: int, eps_share: int, employer_epf_share: int}}
     */
    public function ecr(PayrollRun $run): array
    {
        $lines = [];
        $leftOut = [];
        $totals = array_fill_keys(['members', 'epf_wages', 'eps_wages', 'employee_share', 'eps_share', 'employer_epf_share'], 0);

        $payslips = $run->payslips()->where('employee_pf', '>', 0)->orderBy('employee_name')->get();
        foreach ($payslips as $p) {
            $uan = preg_replace('/\D/', '', (string) $p->uan);
            $reason = match (true) {
                strlen($uan) !== 12 => 'No 12-digit UAN',
                (float) $p->pf_wages <= 0 => 'Calculated before PF wages were stored: reopen and recalculate this payroll',
                default => null,
            };
            if ($reason) {
                $leftOut[] = ['name' => $p->employee_code ? "{$p->employee_name} ({$p->employee_code})" : $p->employee_name, 'reason' => $reason];

                continue;
            }

            $epfWages = (int) round((float) $p->pf_wages);
            $epsWages = (int) round((float) $p->eps_wages);
            $employeeShare = (int) round((float) $p->employee_pf);
            $epsShare = (int) round((float) $p->employer_eps);
            $employerEpfShare = (int) round((float) $p->employer_pf) - $epsShare;

            $lines[] = implode('#~#', [
                $uan,
                $this->ecrName($p->employee_name),
                (int) round((float) $p->gross_earnings),
                $epfWages,
                $epsWages,
                $epsWages,                          // EDLI wages: same ceiling as EPS
                $employeeShare,
                $epsShare,
                $employerEpfShare,                  // EPF-EPS difference
                (int) round((float) $p->lop_days),  // NCP (non-contributing) days
                0,                                  // refund of advances
            ]);

            $totals['members']++;
            $totals['epf_wages'] += $epfWages;
            $totals['eps_wages'] += $epsWages;
            $totals['employee_share'] += $employeeShare;
            $totals['eps_share'] += $epsShare;
            $totals['employer_epf_share'] += $employerEpfShare;
        }

        return ['lines' => $lines, 'left_out' => $leftOut, 'totals' => $totals];
    }

    /** e.g. ECR-2026-10.txt */
    public function ecrFilename(PayrollRun $run): string
    {
        return sprintf('ECR-%04d-%02d.txt', $run->year, $run->month);
    }

    /** Member name as EPFO accepts it: letters, spaces and dots, upper case. */
    private function ecrName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z .]/', ' ', Str::ascii($name));

        return strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $clean)));
    }

    /**
     * Write CSV rows, neutralising text that a spreadsheet would run as a
     * formula (=, +, -, @ at the start).
     *
     * @param  resource  $handle
     * @param  list<string|int|float|null>  $row
     */
    public static function writeCsvRow($handle, array $row): void
    {
        fputcsv($handle, array_map(
            fn ($cell) => is_string($cell) && ! is_numeric($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell,
            $row,
        ));
    }
}
