<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\V1\AttendanceCorrectionController;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Attendance regularization: ask HR / the manager to fix a day's check-in or
 * check-out. Requests are the same "attendance corrections" the mobile app
 * sends, so they show up in the admin Approvals screen.
 */
class RegularizationController extends PortalController
{
    public function index(Request $request): View
    {
        $staff = $this->staff();
        $today = $this->today();

        $date = $request->query('date');
        $date = $date && strtotime($date) && $date <= $today->toDateString() ? CarbonImmutable::parse($date)->toDateString() : null;

        return view('portal.regularization', [
            'tz' => $this->tz(),
            'today' => $today,
            'selectedDate' => $date,
            'recorded' => $date ? Attendance::where('staff_id', $staff->id)->whereDate('work_date', $date)->first() : null,
            'requests' => AttendanceCorrectionRequest::with(['attendance', 'reviewer:id,name'])
                ->where('staff_id', $staff->id)->latest()->paginate(15),
        ]);
    }

    public function store(Request $request, AttendanceService $attendance, AttendanceCorrectionController $api): RedirectResponse
    {
        $staff = $this->staff();
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'check_in' => ['nullable', 'date_format:H:i', 'required_without:check_out'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'work_date.before_or_equal' => 'You can only regularize today or a past day.',
            'check_in.required_without' => 'Enter the correct check-in time, check-out time, or both.',
        ]);

        $workDate = $data['work_date'];
        if (AttendanceCorrectionRequest::where('staff_id', $staff->id)->whereDate('work_date', $workDate)->where('status', 'pending')->exists()) {
            return back()->withInput()->with('error', 'You already have a pending regularization request for this day.');
        }

        // Times are entered in office time; requests store them in the app timezone, like app punches.
        $tz = $this->tz();
        $in = isset($data['check_in']) ? CarbonImmutable::parse("{$workDate} {$data['check_in']}", $tz) : null;
        $out = isset($data['check_out']) ? CarbonImmutable::parse("{$workDate} {$data['check_out']}", $tz) : null;
        if ($in && $out && $out->lte($in)) {
            if (! $attendance->currentShiftAssignment($staff, $workDate)?->shift?->crosses_midnight) {
                throw ValidationException::withMessages(['check_out' => 'Check-out must be after check-in.']);
            }
            $out = $out->addDay(); // night shift ending after midnight
        }

        $appTz = config('app.timezone');
        $request->merge([
            'attendance_id' => Attendance::where('staff_id', $staff->id)->whereDate('work_date', $workDate)->value('id'),
            'requested_values' => array_filter([
                'check_in_at' => $in?->setTimezone($appTz)->format('Y-m-d H:i:s'),
                'check_out_at' => $out?->setTimezone($appTz)->format('Y-m-d H:i:s'),
            ]),
        ]);

        return $this->fromApi($api->store($request), 'Regularization request sent for approval.', route('portal.regularization.index'));
    }
}
