<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AuditLog;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AttendanceCorrectionRequest::with(['staff', 'reviewer:id,name'])->latest();

        if (! $user->isAdmin()) {
            $teamIds = $user->hasRole('Manager')
                ? $user->staff?->subordinates()->pluck('id')->push($user->staff->id) ?? collect([-1])
                : collect([$user->staff?->id ?? -1]);
            $query->whereIn('staff_id', $teamIds);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return ApiResponder::success($query->paginate(20), 'Correction requests retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'attendance_id' => ['nullable', 'exists:attendance,id'],
            'requested_values' => ['required', 'array'],
            'requested_values.check_in_at' => ['nullable', 'date'],
            'requested_values.check_out_at' => ['nullable', 'date', 'after:requested_values.check_in_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'requested_values.check_out_at.after' => 'Check-out time must be after check-in time.',
        ]);

        $values = array_filter([
            'check_in_at' => $validated['requested_values']['check_in_at'] ?? null,
            'check_out_at' => $validated['requested_values']['check_out_at'] ?? null,
        ]);
        if ($values === []) {
            throw ValidationException::withMessages(['requested_values' => ['Enter the check-in or check-out time to correct.']]);
        }

        $staffId = $request->user()->staff->id;
        $workDate = Carbon::parse($validated['work_date'])->toDateString();

        $alreadyPending = AttendanceCorrectionRequest::where('staff_id', $staffId)
            ->whereDate('work_date', $workDate)
            ->where('status', 'pending')
            ->exists();
        if ($alreadyPending) {
            throw ValidationException::withMessages(['work_date' => ['You already have a pending correction request for this date.']]);
        }

        $correction = AttendanceCorrectionRequest::create([
            'staff_id' => $staffId,
            'attendance_id' => $validated['attendance_id']
                ?? Attendance::where('staff_id', $staffId)->whereDate('work_date', $workDate)->value('id'),
            'work_date' => $workDate,
            'requested_values' => $values,
            'reason' => $validated['reason'],
        ]);

        return ApiResponder::success($correction, 'Correction request submitted', 201);
    }

    public function approve(Request $request, AttendanceCorrectionRequest $correction): JsonResponse
    {
        if ($correction->status !== 'pending') {
            return ApiResponder::error('This request has already been reviewed.', 409);
        }

        DB::transaction(function () use ($correction, $request) {
            $correction->update([
                'status' => 'approved',
                'reviewer_id' => $request->user()->id,
                'review_comment' => $request->input('comment'),
                'reviewed_at' => now(),
            ]);

            // Apply the correction and re-evaluate the day (status, shift,
            // late / worked / overtime minutes) like a punch. The original
            // punches stay in attendance_events.
            $attendance = $this->attendance->applyCorrection(
                $correction->staff,
                $correction->work_date->toDateString(),
                $correction->requested_values ?? [],
                $correction->attendance_id,
                ['correction_request_id' => $correction->id, 'by_user_id' => $request->user()->id],
            );

            if ($correction->attendance_id !== $attendance->id) {
                $correction->update(['attendance_id' => $attendance->id]);
            }
        });

        AuditLog::record('attendance.correction_approved', $correction);

        return ApiResponder::success($correction->fresh(['attendance']), 'Correction approved');
    }

    public function reject(Request $request, AttendanceCorrectionRequest $correction): JsonResponse
    {
        $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        if ($correction->status !== 'pending') {
            return ApiResponder::error('This request has already been reviewed.', 409);
        }

        $correction->update([
            'status' => 'rejected',
            'reviewer_id' => $request->user()->id,
            'review_comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('attendance.correction_rejected', $correction);

        return ApiResponder::success($correction->fresh(), 'Correction rejected');
    }
}
