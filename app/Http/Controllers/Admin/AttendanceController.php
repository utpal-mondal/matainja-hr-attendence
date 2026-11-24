<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\WfhRequest;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends AdminController
{
    public const FILTERS = [
        'in' => 'Checked in', 'late' => 'Late', 'wfh' => 'WFH', 'leave' => 'On leave', 'absent' => 'Not in',
    ];

    /** What an admin can mark a day as. */
    public const MARKS = ['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent'];

    public function index(Request $request, AttendanceService $service): View
    {
        $date = $this->date($request);
        $rows = $this->rows($request, $date);

        $isWfhDay = in_array($date->isoWeekday(), $service->wfhWeekdays(), true);
        $holiday = Holiday::whereDate('holiday_date', $date->toDateString())->whereNull('branch_id')->first();

        $all = $this->rows($request, $date, false);

        return view('admin.attendance.index', [
            'date' => $date,
            'today' => $this->today(),
            'rows' => $rows,
            'counts' => collect(self::FILTERS)->keys()->mapWithKeys(fn ($k) => [$k => $all->where('bucket', $k)->count()
                + ($k === 'in' ? $all->whereIn('bucket', ['late', 'wfh'])->count() : 0)]),
            'total' => $all->count(),
            'filters' => self::FILTERS,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'isWfhDay' => $isWfhDay,
            'leaveTypes' => LeaveType::orderBy('name')->get(['id', 'name', 'paid']),
            'holiday' => $holiday,
            'tz' => $this->tz(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $date = $this->date($request);
        $rows = $this->rows($request, $date);
        $tz = $this->tz();

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee code', 'Name', 'Department', 'Branch', 'Status', 'Check-in', 'Check-out',
                'Worked (h)', 'Late (min)', 'Check-in location', 'Check-out location', 'Distance from office at check-in (m)']);
            foreach ($rows as $r) {
                $a = $r['attendance'];
                fputcsv($out, [
                    $r['staff']->employee_code, $r['staff']->full_name,
                    $r['staff']->department?->name, $r['staff']->branch?->name,
                    $r['label'],
                    $a?->check_in_at?->setTimezone($tz)->format('H:i'),
                    $a?->check_out_at?->setTimezone($tz)->format('H:i'),
                    $a?->worked_minutes ? round($a->worked_minutes / 60, 2) : '',
                    $a?->late_minutes ?: '',
                    $r['in_location'] ? $r['in_location']['latitude'].','.$r['in_location']['longitude'] : '',
                    $r['out_location'] ? $r['out_location']['latitude'].','.$r['out_location']['longitude'] : '',
                    $r['in_location']['distance_from_office_meters'] ?? '',
                ]);
            }
            fclose($out);
        }, 'attendance-'.$date->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Mark or correct one employee's attendance for a day: for staff without
     * the app, or a missed check-out. Not allowed once that month's payroll is locked.
     */
    public function mark(Request $request, Staff $staff, AttendanceService $service): RedirectResponse
    {
        $today = $this->today()->toDateString();
        $data = $request->validateWithBag('mark', [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'mark' => ['required', Rule::in(array_keys(self::MARKS))],
            'check_in' => ['nullable', 'required_if:mark,present', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'date.before_or_equal' => 'Attendance can\'t be marked for a future day.',
            'check_in.required_if' => 'Enter the check-in time.',
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $name = $staff->full_name;

        if ($staff->joining_date && $date->lt($staff->joining_date)) {
            return back()->withInput()->withErrors(['date' => "{$name} joined on {$staff->joining_date->format('d M Y')}."], 'mark');
        }
        $run = PayrollRun::where('year', $date->year)->where('month', $date->month)->first();
        if ($run && ! $run->isDraft()) {
            return back()->with('error', "The {$run->label} payroll is {$run->status}, so its attendance can't change. Reopen the payroll first.");
        }

        try {
            $attendance = $service->markManually($staff, $data['date'], $data['mark'], $data['check_in'] ?? null, $data['check_out'] ?? null, $data['note'] ?? null);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors(), 'mark');
        }
        AuditLog::record('attendance.marked_by_admin', $attendance, null, [
            'staff_id' => $staff->id, 'date' => $data['date'], 'mark' => $data['mark'],
            'check_in' => $data['check_in'] ?? null, 'check_out' => $data['check_out'] ?? null, 'note' => $data['note'] ?? null,
        ]);

        $message = "Attendance for {$name} on {$date->format('d M Y')} saved as ".strtolower(self::MARKS[$data['mark']]).'.';
        if ($run) {
            $message .= " Recalculate the {$run->label} payroll to include it.";
        }

        return back()->with('success', $message);
    }

    private function date(Request $request): CarbonImmutable
    {
        try {
            return $request->query('date')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('date'), $this->tz())->startOfDay()
                : $this->today();
        } catch (\Throwable) {
            return $this->today();
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(Request $request, CarbonImmutable $date, bool $applyStatus = true): Collection
    {
        $day = $date->toDateString();

        $staff = Staff::with(['department:id,name', 'branch', 'designation:id,title'])
            ->whereIn('employment_status', ['active', 'probation'])
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $day))
            ->when($request->query('branch'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('department'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->orderBy('first_name')->get();

        $attendance = Attendance::with(['events', 'shift'])
            ->whereDate('work_date', $day)
            ->whereIn('staff_id', $staff->pluck('id'))
            ->get()->keyBy('staff_id');

        $onLeave = LeaveRequest::with('leaveType:id,name')
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)
            ->whereIn('staff_id', $staff->pluck('id'))
            ->get()->keyBy('staff_id');

        $wfhApproved = WfhRequest::where('status', 'approved')
            ->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)
            ->whereIn('staff_id', $staff->pluck('id'))
            ->pluck('staff_id')->flip();

        $isPast = $date->lt($this->today());

        $rows = $staff->map(function (Staff $s) use ($attendance, $onLeave, $wfhApproved, $isPast) {
            $a = $attendance->get($s->id);
            $resource = [];
            if ($a) {
                $a->setRelation('staff', $s);
                $resource = (new AttendanceResource($a))->resolve(request());
            }
            $leave = $onLeave->get($s->id);

            [$bucket, $label] = match (true) {
                $a && $a->status === 'wfh' => ['wfh', 'WFH'],
                $a && $a->status === 'late' => ['late', 'Late'],
                $a && $a->check_in_at !== null => ['in', ucfirst(str_replace('_', ' ', $a->status ?: 'present'))],
                // Marked by an admin without punch times: show what was marked, not "Not checked in".
                $a && $a->status === 'half_day' => ['in', 'Half day'],
                $a && $a->status === 'absent' => ['absent', 'Absent'],
                $leave !== null => ['leave', 'On leave'.($leave->leaveType ? ' · '.$leave->leaveType->name : '')],
                default => ['absent', $isPast ? 'Absent' : 'Not checked in'],
            };

            return [
                'staff' => $s,
                'attendance' => $a,
                'bucket' => $bucket,
                'label' => $label,
                'wfh_approved' => $wfhApproved->has($s->id),
                'on_leave' => $leave !== null,
                'in_location' => $resource['check_in_location'] ?? null,
                'out_location' => $resource['check_out_location'] ?? null,
            ];
        });

        $filter = $applyStatus ? $request->query('status') : null;
        if ($filter === 'in') {
            $rows = $rows->whereIn('bucket', ['in', 'late', 'wfh']);
        } elseif ($filter) {
            $rows = $rows->where('bucket', $filter);
        }

        return $rows->values();
    }
}
