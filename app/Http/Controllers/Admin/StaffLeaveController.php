<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Leave added (already approved) or cancelled by an admin, from the
 * employee page or the attendance list.
 */
class StaffLeaveController extends AdminController
{
    public function __construct(private LeaveService $leave) {}

    public function store(Request $request, Staff $staff): RedirectResponse
    {
        if (in_array($staff->employment_status, ['resigned', 'terminated'], true)) {
            return back()->with('error', "{$staff->full_name} is {$staff->employment_status}; leave can't be added.");
        }

        $data = $request->validateWithBag('leave', [
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'to_date.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        try {
            $leave = $this->leave->addApproved($staff, LeaveType::findOrFail($data['leave_type_id']), $data['from_date'], $data['to_date'],
                $request->boolean('partial_day'), $data['reason'] ?? null, $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors(), 'leave');
        }
        AuditLog::record('leave.added_by_admin', $leave, null, $leave->toArray());

        $message = "{$leave->leaveType->name} added for {$staff->full_name}: {$this->leave->period($leave)}"
            .' ('.rtrim(rtrim(number_format((float) $leave->total_days, 1), '0'), '.').' '.((float) $leave->total_days === 1.0 ? 'day' : 'days').').';
        if ($request->input('return_to') === 'attendance') {
            return back()->with('success', $message);
        }

        return redirect()->to(route('admin.staff.edit', $staff).'#leave')->with('success', $message);
    }

    public function cancel(Staff $staff, LeaveRequest $leaveRequest): RedirectResponse
    {
        $before = $leaveRequest->toArray();
        try {
            $this->leave->cancel($leaveRequest);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }
        AuditLog::record('leave.cancelled_by_admin', $leaveRequest, $before, $leaveRequest->fresh()->toArray());

        return redirect()->to(route('admin.staff.edit', $staff).'#leave')
            ->with('success', "Leave {$this->leave->period($leaveRequest)} for {$staff->full_name} cancelled; the days are back in the balance.");
    }
}
