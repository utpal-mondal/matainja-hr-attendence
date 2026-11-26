<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BreakRecord;
use App\Models\CompanySetting;
use App\Models\Staff;
use App\Models\StaffShiftAssignment;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    /**
     * Company setting: comma-separated ISO weekdays (1 = Monday ... 7 = Sunday)
     * that are work-from-home days for everyone. Defaults to Wednesday.
     */
    public const SETTING_WFH_WEEKDAYS = 'attendance.wfh_weekdays';

    public const DEFAULT_WFH_WEEKDAYS = '3';

    public function __construct(private readonly GeofenceService $geofence) {}

    /**
     * Record a check-in for the staff member. One active session per day,
     * enforced with a transaction and a unique (staff_id, work_date) key.
     * Rejected when the punch location is outside the branch geofence, except
     * on work-from-home days (see isWfhDay), where the location is only recorded.
     */
    public function checkIn(Staff $staff, array $meta = []): Attendance
    {
        $isWfh = $this->isWfhDay($staff, $this->workDate($staff, CarbonImmutable::now()));

        if (! $isWfh) {
            $this->geofence->assertWithinFence($staff->branch, $meta, 'check_in');
        }

        return DB::transaction(function () use ($staff, $meta, $isWfh) {
            $now = CarbonImmutable::now();
            $workDate = $this->workDate($staff, $now);

            $existing = Attendance::where('staff_id', $staff->id)
                ->where('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->check_in_at && ! $existing->check_out_at) {
                throw ValidationException::withMessages([
                    'check_in' => ['You are already checked in.'],
                ]);
            }

            if ($existing && $existing->check_in_at && $existing->check_out_at) {
                throw ValidationException::withMessages([
                    'check_in' => ['Attendance is already completed for today.'],
                ]);
            }

            $assignment = $this->currentShiftAssignment($staff, $workDate);
            $lateMinutes = $this->lateMinutes($staff, $assignment, $now, $workDate);

            $attendance = $existing ?? new Attendance([
                'staff_id' => $staff->id,
                'work_date' => $workDate,
            ]);

            $attendance->fill([
                'shift_id' => $assignment?->shift_id,
                'check_in_at' => $now,
                'status' => $isWfh ? 'wfh' : ($lateMinutes > 0 ? 'late' : 'present'),
                'late_minutes' => $lateMinutes,
                'source' => 'app',
            ])->save();

            $attendance->events()->create([
                'event_type' => 'check_in',
                'occurred_at' => $now,
                'latitude' => $meta['latitude'] ?? null,
                'longitude' => $meta['longitude'] ?? null,
                'accuracy_meters' => $meta['accuracy_meters'] ?? null,
                'device_id' => $meta['device_id'] ?? null,
            ]);

            return $attendance;
        });
    }

    /**
     * Record a check-out. Requires an open attendance session.
     */
    public function checkOut(Staff $staff, array $meta = []): Attendance
    {
        return DB::transaction(function () use ($staff, $meta) {
            $now = CarbonImmutable::now();

            $attendance = Attendance::where('staff_id', $staff->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->lockForUpdate()
                ->latest('work_date')
                ->first();

            if (! $attendance) {
                throw ValidationException::withMessages([
                    'check_out' => ['No active attendance session found.'],
                ]);
            }

            if ($attendance->openBreak) {
                throw ValidationException::withMessages([
                    'check_out' => ['End your active break before checking out.'],
                ]);
            }

            // A WFH session (or a WFH day) checks out from wherever the person is.
            $isWfh = $attendance->status === 'wfh'
                || $this->isWfhDay($staff, $attendance->work_date->toDateString());
            if (! $isWfh) {
                $this->geofence->assertWithinFence($staff->branch, $meta, 'check_out');
            }

            $attendance->check_out_at = $now;
            $attendance->worked_minutes = $this->workedMinutes($attendance);
            $attendance->overtime_minutes = $this->overtimeMinutes($attendance);
            $attendance->save();

            $attendance->events()->create([
                'event_type' => 'check_out',
                'occurred_at' => $now,
                'latitude' => $meta['latitude'] ?? null,
                'longitude' => $meta['longitude'] ?? null,
                'accuracy_meters' => $meta['accuracy_meters'] ?? null,
                'device_id' => $meta['device_id'] ?? null,
            ]);

            return $attendance;
        });
    }

    /**
     * Attendance entered by an admin, e.g. for someone without the app or a
     * missed check-out. Times are office-local "H:i". Late, worked and
     * overtime minutes are worked out like an app check-in.
     *
     * @param  'present'|'half_day'|'absent'  $mark
     */
    public function markManually(Staff $staff, string $workDate, string $mark, ?string $checkIn, ?string $checkOut, ?string $note = null): Attendance
    {
        return DB::transaction(function () use ($staff, $workDate, $mark, $checkIn, $checkOut, $note) {
            $tz = $staff->branch?->timezone ?: config('app.timezone');
            $assignment = $this->currentShiftAssignment($staff, $workDate);

            $in = $mark === 'absent' || ! $checkIn ? null : CarbonImmutable::parse("{$workDate} {$checkIn}", $tz);
            $out = $mark === 'absent' || ! $checkOut || ! $in ? null : CarbonImmutable::parse("{$workDate} {$checkOut}", $tz);
            if ($in && $out && $out->lte($in)) {
                if (! $assignment?->shift?->crosses_midnight) {
                    throw ValidationException::withMessages(['check_out' => ['Check-out must be after check-in.']]);
                }
                $out = $out->addDay(); // night shift ending after midnight
            }

            $late = $in && $mark === 'present' ? $this->lateMinutes($staff, $assignment, $in, $workDate) : 0;
            $status = match (true) {
                $mark !== 'present' => $mark,
                $this->isWfhDay($staff, $workDate) => 'wfh',
                $late > 0 => 'late',
                default => 'present',
            };

            $attendance = Attendance::where('staff_id', $staff->id)->whereDate('work_date', $workDate)->lockForUpdate()->first()
                ?? new Attendance(['staff_id' => $staff->id, 'work_date' => $workDate]);
            $before = $attendance->exists ? $attendance->only(['status', 'check_in_at', 'check_out_at']) : null;

            // Stored in the app timezone, like app punches.
            $appTz = config('app.timezone');
            $attendance->fill([
                'shift_id' => $attendance->shift_id ?? $assignment?->shift_id,
                'check_in_at' => $in?->setTimezone($appTz),
                'check_out_at' => $out?->setTimezone($appTz),
                'status' => $status,
                'late_minutes' => $late,
                'source' => 'manual',
            ]);
            $attendance->worked_minutes = $this->workedMinutes($attendance);
            $attendance->overtime_minutes = $mark === 'present' ? $this->overtimeMinutes($attendance) : 0;
            $attendance->save();

            $attendance->events()->create([
                'event_type' => 'correction',
                'occurred_at' => CarbonImmutable::now(),
                'metadata' => ['by_user_id' => auth()->id(), 'mark' => $mark, 'note' => $note, 'before' => $before],
            ]);

            return $attendance;
        });
    }

    /**
     * Apply an approved attendance-correction request. The requested times
     * replace the recorded ones (a missing value keeps what was recorded) and
     * the day is re-evaluated exactly like a punch: shift, WFH / late /
     * present status, late, worked and overtime minutes. The original punches
     * stay in attendance_events.
     *
     * @param  array{check_in_at?: string|null, check_out_at?: string|null}  $values
     * @param  array<string, mixed>  $metadata  extra details for the correction event
     */
    public function applyCorrection(Staff $staff, string $workDate, array $values, ?int $attendanceId = null, array $metadata = []): Attendance
    {
        return DB::transaction(function () use ($staff, $workDate, $values, $attendanceId, $metadata) {
            $attendance = ($attendanceId ? Attendance::whereKey($attendanceId)->lockForUpdate()->first() : null)
                ?? Attendance::where('staff_id', $staff->id)->whereDate('work_date', $workDate)->lockForUpdate()->first()
                ?? new Attendance(['staff_id' => $staff->id, 'work_date' => $workDate]);
            $before = $attendance->exists ? $attendance->only(['status', 'check_in_at', 'check_out_at']) : null;

            // Stored in the app timezone, like app punches.
            $appTz = config('app.timezone');
            $parse = fn ($v) => $v ? CarbonImmutable::parse($v)->setTimezone($appTz) : null;
            $in = $parse($values['check_in_at'] ?? null) ?? $parse($attendance->check_in_at);
            $out = $parse($values['check_out_at'] ?? null) ?? $parse($attendance->check_out_at);
            if ($in && $out && $out->lte($in)) {
                throw ValidationException::withMessages(['check_out_at' => ['Check-out must be after check-in.']]);
            }

            $assignment = $this->currentShiftAssignment($staff, $workDate);
            $late = $in ? $this->lateMinutes($staff, $assignment, $in, $workDate) : 0;
            $status = match (true) {
                ! $in => $attendance->status ?: 'absent',
                $this->isWfhDay($staff, $workDate) => 'wfh',
                $late > 0 => 'late',
                default => 'present',
            };

            $attendance->fill([
                'shift_id' => $attendance->shift_id ?? $assignment?->shift_id,
                'check_in_at' => $in,
                'check_out_at' => $out,
                'status' => $status,
                'late_minutes' => $status === 'late' ? $late : 0,
                'source' => 'correction',
            ]);
            $attendance->worked_minutes = $this->workedMinutes($attendance);
            $attendance->overtime_minutes = $this->overtimeMinutes($attendance);
            $attendance->save();

            $attendance->events()->create([
                'event_type' => 'correction',
                'occurred_at' => CarbonImmutable::now(),
                'metadata' => $metadata + ['before' => $before, 'applied' => array_filter($values)],
            ]);

            return $attendance;
        });
    }

    public function startBreak(Attendance $attendance, string $breakType = 'general'): BreakRecord
    {
        if (! $attendance->check_in_at || $attendance->check_out_at) {
            throw ValidationException::withMessages([
                'break' => ['A break requires an active attendance session.'],
            ]);
        }

        if ($attendance->openBreak) {
            throw ValidationException::withMessages([
                'break' => ['A break is already in progress.'],
            ]);
        }

        $now = CarbonImmutable::now();

        $break = $attendance->breaks()->create([
            'break_type' => $breakType,
            'started_at' => $now,
        ]);

        $attendance->events()->create([
            'event_type' => 'break_start',
            'occurred_at' => $now,
        ]);

        return $break;
    }

    public function endBreak(Attendance $attendance): BreakRecord
    {
        $break = $attendance->openBreak;

        if (! $break) {
            throw ValidationException::withMessages([
                'break' => ['There is no break in progress.'],
            ]);
        }

        $now = CarbonImmutable::now();
        $break->update([
            'ended_at' => $now,
            'duration_minutes' => (int) $break->started_at->diffInMinutes($now),
        ]);

        $attendance->events()->create([
            'event_type' => 'break_end',
            'occurred_at' => $now,
        ]);

        return $break;
    }

    /**
     * Today's work date in the staff branch timezone. Overnight shifts check in
     * after midnight against the previous work date.
     */
    public function workDate(Staff $staff, CarbonImmutable $now): string
    {
        $tz = $staff->branch?->timezone ?: config('app.timezone');

        return $now->setTimezone($tz)->toDateString();
    }

    /**
     * Work-from-home day for this person: a company-wide WFH weekday
     * (Wednesday by default) or a date covered by an approved WFH request.
     */
    public function isWfhDay(Staff $staff, string $workDate): bool
    {
        $weekday = CarbonImmutable::parse($workDate)->isoWeekday();
        if (in_array($weekday, $this->wfhWeekdays(), true)) {
            return true;
        }

        return WfhRequest::where('staff_id', $staff->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $workDate)
            ->whereDate('to_date', '>=', $workDate)
            ->exists();
    }

    /**
     * @return list<int> ISO weekdays that are WFH for everyone
     */
    public function wfhWeekdays(): array
    {
        $raw = CompanySetting::where('key', self::SETTING_WFH_WEEKDAYS)->value('value') ?? self::DEFAULT_WFH_WEEKDAYS;

        return array_values(array_filter(
            array_map('intval', explode(',', (string) $raw)),
            fn (int $d) => $d >= 1 && $d <= 7,
        ));
    }

    public function currentShiftAssignment(Staff $staff, string $workDate): ?StaffShiftAssignment
    {
        return $staff->shiftAssignments()
            ->with('shift')
            ->where('effective_from', '<=', $workDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $workDate))
            ->latest('effective_from')
            ->first();
    }

    private function lateMinutes(Staff $staff, ?StaffShiftAssignment $assignment, CarbonImmutable $now, string $workDate): int
    {
        $shift = $assignment?->shift;

        if (! $shift?->start_time) {
            return 0;
        }

        $tz = $staff->branch?->timezone ?: config('app.timezone');
        $shiftStart = CarbonImmutable::parse(
            $workDate.' '.$shift->start_time,
            $tz
        )->addMinutes((int) $shift->grace_minutes);

        $localNow = $now->setTimezone($tz);

        return $localNow->greaterThan($shiftStart) ? (int) $shiftStart->diffInMinutes($localNow) : 0;
    }

    private function workedMinutes(Attendance $attendance): int
    {
        if (! $attendance->check_in_at || ! $attendance->check_out_at) {
            return 0;
        }

        $elapsed = $attendance->check_in_at->diffInMinutes($attendance->check_out_at);
        $breaks = $attendance->breaks()->whereNotNull('ended_at')->sum('duration_minutes');

        return max(0, (int) $elapsed - (int) $breaks);
    }

    private function overtimeMinutes(Attendance $attendance): int
    {
        $shift = $attendance->shift;

        if (! $shift?->start_time || ! $shift->end_time || ! $attendance->worked_minutes) {
            return 0;
        }

        $scheduled = CarbonImmutable::parse($shift->start_time)
            ->diffInMinutes(CarbonImmutable::parse($shift->end_time));

        if ($shift->crosses_midnight && $scheduled < 0) {
            $scheduled += 1440;
        }

        return max(0, $attendance->worked_minutes - (int) $scheduled);
    }
}
