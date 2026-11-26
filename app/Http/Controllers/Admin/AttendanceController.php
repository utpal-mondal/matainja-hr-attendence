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
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /** Period filter: one day (default) or a range of days, one row per employee per day. */
    public const PERIODS = [
        'day' => 'Single day', 'this_month' => 'This month', 'last_month' => 'Last month',
        'this_year' => 'This year', 'custom' => 'Custom range',
    ];

    /** Longest custom range, so a page never has to work through more than a year. */
    public const MAX_RANGE_DAYS = 366;

    /** Rows per page when a range is shown. */
    private const RANGE_PER_PAGE = 50;

    public function index(Request $request, AttendanceService $service): View
    {
        [$period, $from, $date] = $this->period($request);
        $ranged = $period !== 'day';

        $all = $this->rows($request, $from, $date, $ranged);
        $filtered = $this->withStatus($all, $request->query('status'));

        if ($ranged) {
            $page = LengthAwarePaginator::resolveCurrentPage();
            $rows = new LengthAwarePaginator(
                $this->withLocations($filtered->forPage($page, self::RANGE_PER_PAGE)->values()),
                $filtered->count(), self::RANGE_PER_PAGE, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $rows = $this->withLocations($filtered);
        }

        $isWfhDay = in_array($date->isoWeekday(), $service->wfhWeekdays(), true);
        $holiday = Holiday::whereDate('holiday_date', $date->toDateString())->whereNull('branch_id')->first();

        return view('admin.attendance.index', [
            'period' => $period,
            'periods' => self::PERIODS,
            'ranged' => $ranged,
            'from' => $from,
            'date' => $date,
            'days' => (int) $from->diffInDays($date) + 1,
            'today' => $this->today(),
            'rows' => $rows,
            'counts' => collect(self::FILTERS)->keys()->mapWithKeys(fn ($k) => [$k => $all->where('bucket', $k)->count()
                + ($k === 'in' ? $all->whereIn('bucket', ['late', 'wfh'])->count() : 0)]),
            // Holidays and weekly offs are not expected working days.
            'total' => $all->whereNotIn('bucket', ['holiday', 'off'])->count(),
            'employeeCount' => $all->pluck('staff.id')->unique()->count(),
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
        [$period, $from, $date] = $this->period($request);
        $rows = $this->withLocations($this->withStatus($this->rows($request, $from, $date, $period !== 'day'), $request->query('status')));
        $tz = $this->tz();
        $filename = $period === 'day'
            ? 'attendance-'.$date->toDateString().'.csv'
            : 'attendance-'.$from->toDateString().'-to-'.$date->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee code', 'Name', 'Department', 'Branch', 'Status', 'Check-in', 'Check-out',
                'Worked (h)', 'Late (min)', 'Check-in location', 'Check-out location', 'Distance from office at check-in (m)']);
            foreach ($rows as $r) {
                $a = $r['attendance'];
                fputcsv($out, [
                    $r['date']->toDateString(),
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
        }, $filename, ['Content-Type' => 'text/csv']);
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

    /**
     * The period being looked at: the chosen preset or custom range, or a
     * single day. Never later than today.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable} [period, first day, last day]
     */
    private function period(Request $request): array
    {
        $today = $this->today();
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'day';
        $lastMonth = $today->startOfMonth()->subMonth();

        [$from, $to] = match ($period) {
            'day' => [$this->date($request), $this->date($request)],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$lastMonth, $lastMonth->endOfMonth()->startOfDay()],
            'this_year' => [$today->startOfYear(), $today],
            'custom' => $this->customRange($request, $today),
        };

        return [$period, $from, $to];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function customRange(Request $request, CarbonImmutable $today): array
    {
        $from = $this->parseDay($request->query('from')) ?? $today->startOfMonth();
        $to = $this->parseDay($request->query('to')) ?? $today;
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($today);
        $from = $from->min($to)->max($to->subDays(self::MAX_RANGE_DAYS - 1));

        return [$from, $to];
    }

    private function date(Request $request): CarbonImmutable
    {
        return $this->parseDay($request->query('date')) ?? $this->today();
    }

    private function parseDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $this->tz())->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One row per employee per day in the period, newest day first. Over a
     * range, holidays and weekly offs without attendance get their own status
     * instead of counting as absent.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Request $request, CarbonImmutable $from, CarbonImmutable $to, bool $ranged = false): Collection
    {
        [$first, $last] = [$from->toDateString(), $to->toDateString()];

        $staff = Staff::with(['department:id,name', 'branch', 'designation:id,title'])
            ->whereIn('employment_status', ['active', 'probation'])
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $last))
            ->when($request->query('branch'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('department'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->orderBy('first_name')->get();
        $ids = $staff->pluck('id');

        $attendance = Attendance::with(['events', 'shift'])
            ->whereDate('work_date', '>=', $first)->whereDate('work_date', '<=', $last)
            ->whereIn('staff_id', $ids)
            ->get()->keyBy(fn (Attendance $a) => $a->staff_id.'|'.$a->work_date->toDateString());

        $overlapping = fn ($query) => $query->where('status', 'approved')
            ->whereDate('from_date', '<=', $last)->whereDate('to_date', '>=', $first)
            ->whereIn('staff_id', $ids);
        $leaves = $overlapping(LeaveRequest::with('leaveType:id,name'))->get()->groupBy('staff_id');
        $wfh = $overlapping(WfhRequest::query())->get()->groupBy('staff_id');
        $covers = fn ($request, string $date) => $request->from_date->toDateString() <= $date && $request->to_date->toDateString() >= $date;

        $holidays = $ranged
            ? Holiday::whereDate('holiday_date', '>=', $first)->whereDate('holiday_date', '<=', $last)->get()
                ->groupBy(fn (Holiday $h) => $h->holiday_date->toDateString())
            : collect();
        $weeklyOffDays = $ranged ? app(PayrollService::class)->weeklyOffDays() : [];
        $today = $this->today();

        $rows = collect();
        for ($day = $to; $day->gte($from); $day = $day->subDay()) {
            $date = $day->toDateString();
            $isPast = $day->lt($today);

            foreach ($staff as $s) {
                if ($s->joining_date && $s->joining_date->toDateString() > $date) {
                    continue;
                }
                $a = $attendance->get($s->id.'|'.$date);
                $leave = $leaves->get($s->id)?->first(fn ($l) => $covers($l, $date));
                $holiday = $holidays->get($date)?->first(fn (Holiday $h) => $h->branch_id === null || $h->branch_id === $s->branch_id);

                [$bucket, $label] = match (true) {
                    $a && $a->status === 'wfh' => ['wfh', 'WFH'],
                    $a && $a->status === 'late' => ['late', 'Late'],
                    $a && $a->check_in_at !== null => ['in', ucfirst(str_replace('_', ' ', $a->status ?: 'present'))],
                    // Marked by an admin without punch times: show what was marked, not "Not checked in".
                    $a && $a->status === 'half_day' => ['in', 'Half day'],
                    $a && $a->status === 'absent' => ['absent', 'Absent'],
                    $leave !== null => ['leave', 'On leave'.($leave->leaveType ? ' · '.$leave->leaveType->name : '')],
                    $holiday !== null => ['holiday', 'Holiday · '.$holiday->name],
                    in_array($day->isoWeekday(), $weeklyOffDays, true) => ['off', 'Weekly off'],
                    default => ['absent', $isPast ? 'Absent' : 'Not checked in'],
                };

                $rows->push([
                    'date' => $day,
                    'staff' => $s,
                    'attendance' => $a,
                    'bucket' => $bucket,
                    'label' => $label,
                    'wfh_approved' => (bool) $wfh->get($s->id)?->contains(fn ($w) => $covers($w, $date)),
                    'on_leave' => $leave !== null,
                    'in_location' => null,
                    'out_location' => null,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Rows matching a status card ("in" also covers late and WFH).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withStatus(Collection $rows, ?string $status): Collection
    {
        return match (true) {
            $status === 'in' => $rows->whereIn('bucket', ['in', 'late', 'wfh'])->values(),
            (bool) $status => $rows->where('bucket', $status)->values(),
            default => $rows,
        };
    }

    /**
     * Add check-in / check-out places to the rows being shown (done only for
     * the visible page, since it reads every punch event).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withLocations(Collection $rows): Collection
    {
        return $rows->map(function (array $row) {
            if ($a = $row['attendance']) {
                $a->setRelation('staff', $row['staff']);
                $resource = (new AttendanceResource($a))->resolve(request());
                $row['in_location'] = $resource['check_in_location'] ?? null;
                $row['out_location'] = $resource['check_out_location'] ?? null;
            }

            return $row;
        });
    }
}
