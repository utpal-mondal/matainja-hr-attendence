<?php

namespace App\Http\Controllers\Portal;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\WfhRequest;
use App\Services\AttendanceService;
use Illuminate\View\View;

class DashboardController extends PortalController
{
    public function __construct(private AttendanceService $attendance) {}

    public function index(): View
    {
        $staff = $this->staff();
        $today = $this->today();
        $workDate = $this->attendance->workDate($staff, now()->toImmutable());

        $monthCounts = Attendance::where('staff_id', $staff->id)
            ->whereDate('work_date', '>=', $today->startOfMonth()->toDateString())->whereDate('work_date', '<=', $today->toDateString())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')
            ->pluck('c', 'status');

        return view('portal.dashboard', [
            'staff' => $staff,
            'tz' => $this->tz(),
            'today' => $today,
            'todayAttendance' => Attendance::with('openBreak')->where('staff_id', $staff->id)->whereDate('work_date', $workDate)->first(),
            'shift' => $this->attendance->currentShiftAssignment($staff, $workDate)?->shift,
            'monthCounts' => $monthCounts,
            'balances' => LeaveBalance::with('leaveType')->where('staff_id', $staff->id)->where('year', $today->year)->get(),
            'pending' => [
                'Leave' => [LeaveRequest::where('staff_id', $staff->id)->where('status', 'pending')->count(), route('portal.leave.index')],
                'Work from home' => [WfhRequest::where('staff_id', $staff->id)->where('status', 'pending')->count(), route('portal.wfh.index')],
                'Regularization' => [AttendanceCorrectionRequest::where('staff_id', $staff->id)->where('status', 'pending')->count(), route('portal.regularization.index')],
            ],
            'upcomingHolidays' => $this->holidays()->whereDate('holiday_date', '>=', $today->toDateString())->limit(5)->get(),
            'latestPayslip' => Payslip::released()->with('run')->where('payslips.staff_id', $staff->id)->first(),
        ]);
    }
}
