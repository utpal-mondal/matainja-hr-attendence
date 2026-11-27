<?php

namespace App\Http\Controllers\Portal;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveRequest;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The employee's attendance for one month, day by day. View only: punches
 * are made in the mobile app, and fixes go through a regularization request.
 */
class AttendanceController extends PortalController
{
    public function index(Request $request): View
    {
        $staff = $this->staff();
        $today = $this->today();
        $month = $this->month($request->query('month'), $today);
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->min($today);
        [$from, $to] = [$start->toDateString(), $end->toDateString()];

        $records = Attendance::withCount('breaks')->where('staff_id', $staff->id)
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get()
            ->keyBy(fn (Attendance $a) => $a->work_date->toDateString());

        $overlapping = fn ($query) => $query->where('staff_id', $staff->id)->where('status', 'approved')
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from);
        $leaves = $overlapping(LeaveRequest::with('leaveType:id,name'))->get();
        $wfh = $overlapping(WfhRequest::query())->get();

        $holidays = $this->holidaysBetween($start, $end)
            ->keyBy(fn ($h) => $h->holiday_date->toDateString());
        $pendingDates = AttendanceCorrectionRequest::where('staff_id', $staff->id)->where('status', 'pending')
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get()
            ->map(fn ($c) => $c->work_date->toDateString())->flip();

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $leave = $leaves->first(fn ($l) => $l->from_date->toDateString() <= $date && $l->to_date->toDateString() >= $date);
            $days[] = [
                'date' => $day,
                'record' => $records->get($date),
                'holiday' => $holidays->get($date),
                'leave' => $leave,
                'wfh' => $wfh->contains(fn ($w) => $w->from_date->toDateString() <= $date && $w->to_date->toDateString() >= $date),
                'correctionPending' => $pendingDates->has($date),
            ];
        }

        $byStatus = $records->countBy('status');

        return view('portal.attendance', [
            'tz' => $this->tz(),
            'month' => $month,
            'previousMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->lte($today->startOfMonth()) ? $month->addMonth()->format('Y-m') : null,
            'days' => array_reverse($days),
            'summary' => [
                'Present' => ($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0) + ($byStatus['wfh'] ?? 0),
                'Late' => $byStatus['late'] ?? 0,
                'Work from home' => $byStatus['wfh'] ?? 0,
                'Half day' => $byStatus['half_day'] ?? 0,
                'Absent' => $byStatus['absent'] ?? 0,
                'Leave days' => collect($days)->filter(fn ($d) => $d['leave'])->sum(fn ($d) => $d['leave']->partial_day ? 0.5 : 1),
            ],
            'workedMinutes' => (int) $records->sum('worked_minutes'),
        ]);
    }

    /** The requested "YYYY-MM" month, never later than the current one. */
    private function month(?string $value, CarbonImmutable $today): CarbonImmutable
    {
        $current = $today->startOfMonth();

        if (! $value || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return $current;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $value, $this->tz())->min($current);
    }
}
