<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave added by an admin: stored as an approved leave request with the
 * admin as approver, counted against the balance exactly like a request
 * approved in the app (days counted the app's way, balance year = start year).
 */
class LeaveService
{
    public function addApproved(Staff $staff, LeaveType $type, string $fromDate, string $toDate, bool $halfDay, ?string $reason, User $approver): LeaveRequest
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();
        if ($halfDay && ! $from->eq($to)) {
            throw ValidationException::withMessages(['partial_day' => 'A half day must start and end on the same date.']);
        }
        if ($staff->joining_date && $from->lt($staff->joining_date)) {
            throw ValidationException::withMessages(['from_date' => "{$staff->full_name} joined on {$staff->joining_date->format('d M Y')}."]);
        }
        $this->ensurePayrollOpen($from, $to);

        $overlap = $staff->leaveRequests()->with('leaveType:id,name')->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
            ->orderBy('from_date')->first();
        if ($overlap) {
            $what = ($overlap->status === 'pending' ? 'a pending request for ' : '').($overlap->leaveType?->name ?? 'other leave');
            throw ValidationException::withMessages([
                'from_date' => "Overlaps {$what} ({$this->period($overlap)}). Approve, reject or cancel that one first.",
            ]);
        }

        $days = $halfDay ? 0.5 : $from->diffInDays($to) + 1;

        return DB::transaction(function () use ($staff, $type, $from, $to, $halfDay, $reason, $approver, $days) {
            $leave = $staff->leaveRequests()->create([
                'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'partial_day' => $halfDay,
                'total_days' => $days,
                'reason' => $reason,
                'status' => 'approved',
            ]);
            $leave->approvals()->create([
                'approver_id' => $approver->id,
                'decision' => 'approved',
                'comments' => 'Added by admin',
                'decided_at' => now(),
                'step_order' => 1,
            ]);
            LeaveBalance::firstOrCreate(
                ['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => $from->year],
                ['opening' => 0, 'accrued' => 0, 'used' => 0, 'pending' => 0],
            )->increment('used', $days);

            return $leave->load('leaveType');
        });
    }

    /** Cancel approved leave and give the days back to the balance. */
    public function cancel(LeaveRequest $leave): void
    {
        if ($leave->status !== 'approved') {
            throw ValidationException::withMessages(['leave' => 'Only approved leave can be cancelled.']);
        }
        $this->ensurePayrollOpen($leave->from_date->toImmutable(), $leave->to_date->toImmutable());

        DB::transaction(function () use ($leave) {
            LeaveBalance::where('staff_id', $leave->staff_id)->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $leave->from_date->year)->decrement('used', (float) $leave->total_days);
            $leave->update(['status' => 'cancelled']);
        });
    }

    /**
     * This year's balance per leave type as a short label: "8 left" when an
     * entitlement (opening + accrued) is set, otherwise "2 used".
     *
     * @return array<int, string> leave type id => label
     */
    public function balanceLabels(Staff $staff, int $year): array
    {
        $days = fn (float $d) => rtrim(rtrim(number_format($d, 1), '0'), '.');

        return LeaveBalance::where('staff_id', $staff->id)->where('year', $year)->get()
            ->mapWithKeys(function (LeaveBalance $b) use ($days) {
                $entitled = (float) $b->opening + (float) $b->accrued;
                $taken = (float) $b->used + (float) $b->pending;

                return [$b->leave_type_id => $entitled > 0 ? $days($entitled - $taken).' left' : $days($taken).' used'];
            })->all();
    }

    /** "26 Nov 2026" or "26 Nov – 28 Nov 2026". */
    public function period(LeaveRequest $leave): string
    {
        return $leave->from_date->isSameDay($leave->to_date)
            ? $leave->from_date->format('d M Y').($leave->partial_day ? ' (half day)' : '')
            : $leave->from_date->format('d M').' – '.$leave->to_date->format('d M Y');
    }

    /** Leave can't change a month whose payroll is locked or paid. */
    private function ensurePayrollOpen(CarbonImmutable $from, CarbonImmutable $to): void
    {
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $run = PayrollRun::where('year', $month->year)->where('month', $month->month)->first();
            if ($run && ! $run->isDraft()) {
                throw ValidationException::withMessages(['from_date' => "The {$run->label} payroll is {$run->status}, so leave in that month can't change. Reopen the payroll first."]);
            }
        }
    }
}
