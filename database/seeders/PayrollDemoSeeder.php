<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Services\PayrollService;
use App\Services\SalaryStructureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo payroll for the demo logins: salaries, statutory & bank details,
 * attendance and leave for the last two full months, a paid payroll for the
 * earlier month and a draft (with adjustments) for the latest one.
 * Demo-only (see DemoData). Safe to run again: nothing that exists is changed.
 */
class PayrollDemoSeeder extends Seeder
{
    /**
     * Demo login email => salary and payroll details (all numbers are fake).
     *
     * @var array<string, array{gross: int, profile: array<string, mixed>}>
     */
    public const SALARIES = [
        'admin@example.com' => ['gross' => 120000, 'profile' => [
            'pan' => 'AAAPA0001A', 'uan' => '100000000001', 'tax_regime' => 'old', 'pf_on_full_basic' => true,
            'bank_name' => 'State Bank of India', 'bank_account_number' => '00001234560001', 'bank_ifsc' => 'SBIN0000001',
        ]],
        'hr@example.com' => ['gross' => 45000, 'profile' => [
            'pan' => 'AAAPH0002B', 'uan' => '100000000002',
            'bank_name' => 'HDFC Bank', 'bank_account_number' => '50100012345602', 'bank_ifsc' => 'HDFC0000002',
        ]],
        'manager@example.com' => ['gross' => 75000, 'profile' => [
            'pan' => 'AAAPM0003C', 'uan' => '100000000003',
            'bank_name' => 'ICICI Bank', 'bank_account_number' => '000101234503', 'bank_ifsc' => 'ICIC0000003',
        ]],
        'employee@example.com' => ['gross' => 18000, 'profile' => [
            'pan' => 'AAAPE0004D', 'uan' => '100000000004',
            'bank_name' => 'Axis Bank', 'bank_account_number' => '917010012345604', 'bank_ifsc' => 'UTIB0000004',
        ]],
    ];

    public function run(SalaryStructureService $salaries, PayrollService $payroll): void
    {
        $this->call([SalaryComponentSeeder::class, DemoUserSeeder::class]);

        if (! DemoData::allowed()) {
            $this->command?->warn(DemoData::skippedMessage('demo payroll'));

            return;
        }

        $today = CarbonImmutable::today();
        $latest = $today->startOfMonth()->subMonth();
        $earlier = $latest->subMonth();
        $components = SalaryComponent::where('is_active', true)->ordered()->get();

        $staff = Staff::whereIn('email', array_keys(self::SALARIES))->get()->keyBy('email');
        foreach (self::SALARIES as $email => $setup) {
            $employee = $staff->get($email);
            if (! $employee) {
                continue;
            }
            $employee->payrollProfile()->firstOrCreate([], $setup['profile'] + ['bank_account_name' => $employee->full_name]);
            if (! $employee->salaryStructures()->exists()) {
                $amounts = $salaries->split($setup['gross'], $components);
                // ESI is only paid on salaries up to ₹21,000.
                $esi = $components->firstWhere('code', SalaryComponent::ESI_CODE);
                if ($esi && $setup['gross'] > 21000) {
                    $amounts[$esi->id] = 0;
                }
                $salaries->createRevision($employee, $employee->joining_date?->toDateString() ?? $earlier->toDateString(),
                    $components, $amounts, 'Demo salary');
            }
            foreach ([$earlier, $latest] as $month) {
                $this->attendance($employee, $month, $payroll->weeklyOffDays(), withLeave: $month->eq($latest));
            }
        }

        if ($employee = $staff->get('employee@example.com')) {
            $this->leave($employee, $latest, 'CL', 2, $payroll->weeklyOffDays());   // two days paid leave
            $this->leave($employee, $latest, 'LOP', 1, $payroll->weeklyOffDays());  // one day unpaid
        }

        $this->payrollRuns($payroll, $earlier, $latest, $today, $staff);
    }

    /**
     * Present on working days (late now and then), with one absent day for
     * the demo employee, plus their leave days in the month that has leave.
     * Days that already have attendance are left alone.
     *
     * @param  list<int>  $weeklyOffs
     */
    private function attendance(Staff $staff, CarbonImmutable $month, array $weeklyOffs, bool $withLeave): void
    {
        $existing = Attendance::where('staff_id', $staff->id)
            ->whereDate('work_date', '>=', $month->toDateString())->whereDate('work_date', '<=', $month->endOfMonth()->toDateString())
            ->pluck('work_date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();

        $working = 0;
        for ($day = $month; $day->month === $month->month; $day = $day->addDay()) {
            if (in_array($day->isoWeekday(), $weeklyOffs, true)) {
                continue;
            }
            $working++;
            // The demo employee misses their 6th working day; leave is added on the 10th–12th.
            $skip = $staff->email === 'employee@example.com' && ($working === 6 || ($withLeave && in_array($working, [10, 11, 12], true)));
            if ($skip || in_array($day->toDateString(), $existing, true)) {
                continue;
            }

            $late = $working % 7 === 0;
            $checkIn = $day->setTime(9, $late ? 52 : 25);
            Attendance::create([
                'staff_id' => $staff->id,
                'work_date' => $day->toDateString(),
                'check_in_at' => $checkIn,
                'check_out_at' => $day->setTime(18, 35),
                'status' => $late ? 'late' : 'present',
                'late_minutes' => $late ? 22 : 0,
                'worked_minutes' => $checkIn->diffInMinutes($day->setTime(18, 35)),
                'overtime_minutes' => $working % 5 === 0 ? 45 : 0,
                'source' => 'manual',
            ]);
        }
    }

    /**
     * Approved leave from the demo employee's 10th (paid, 2 days) or 12th (unpaid) working day.
     *
     * @param  list<int>  $offs
     */
    private function leave(Staff $staff, CarbonImmutable $month, string $typeCode, int $days, array $offs): void
    {
        $type = LeaveType::where('code', $typeCode)->first();
        if (! $type || $staff->leaveRequests()->where('leave_type_id', $type->id)->whereDate('from_date', '>=', $month->toDateString())
            ->whereDate('from_date', '<=', $month->endOfMonth()->toDateString())->exists()) {
            return;
        }

        $workingDays = collect();
        for ($day = $month; $day->month === $month->month; $day = $day->addDay()) {
            if (! in_array($day->isoWeekday(), $offs, true)) {
                $workingDays->push($day);
            }
        }
        $from = $workingDays->get($typeCode === 'LOP' ? 11 : 9);
        $to = $workingDays->get(($typeCode === 'LOP' ? 11 : 9) + $days - 1);
        if (! $from || ! $to) {
            return;
        }

        $staff->leaveRequests()->create([
            'leave_type_id' => $type->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'total_days' => $days,
            'reason' => $typeCode === 'LOP' ? 'Personal work (demo)' : 'Family function (demo)',
            'status' => 'approved',
        ]);
    }

    /**
     * Earlier month: locked and paid. Latest month: a draft with a bonus and
     * an advance recovery to review.
     *
     * @param  Collection<string, Staff>  $staff
     */
    private function payrollRuns(PayrollService $payroll, CarbonImmutable $earlier, CarbonImmutable $latest, CarbonImmutable $today, $staff): void
    {
        $exists = fn (CarbonImmutable $m) => PayrollRun::where('year', $m->year)->where('month', $m->month)->exists();

        if (! $exists($earlier)) {
            $run = $payroll->createRun($earlier->year, $earlier->month, $today);
            $payroll->lock($run);
            $payroll->markPaid($run, $latest->toDateString(), 'DEMO-NEFT-'.$earlier->format('Ym'));
        }

        // Fill the latest month when it's missing, or is a draft nobody has put anything in yet.
        $run = PayrollRun::where('year', $latest->year)->where('month', $latest->month)->first();
        $isEmptyDraft = $run && $run->isDraft() && ! $run->payslips()->exists() && ! $run->adjustments()->exists();
        if (! $run || $isEmptyDraft) {
            $run ??= $payroll->createRun($latest->year, $latest->month, $today);
            $adjustments = [
                ['manager@example.com', 'earning', 'Festival bonus', 5000, true],
                ['employee@example.com', 'deduction', 'Salary advance recovery', 1500, false],
            ];
            foreach ($adjustments as [$email, $type, $name, $amount, $taxable]) {
                if ($employee = $staff->get($email)) {
                    $run->adjustments()->create(['staff_id' => $employee->id, 'type' => $type, 'name' => $name, 'amount' => $amount, 'is_taxable' => $taxable]);
                }
            }
            $payroll->process($run, $today);
        }
    }
}
