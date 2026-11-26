<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\V1\LeaveController as ApiLeaveController;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Leave balances, history and applying for leave. Applying, withdrawing and
 * cancelling go through the API controller, so balances and approvers work
 * exactly as they do from the mobile app.
 */
class LeaveController extends PortalController
{
    public function index(): View
    {
        $staff = $this->staff();

        return view('portal.leave', [
            'tz' => $this->tz(),
            'today' => $this->today(),
            'balances' => LeaveBalance::with('leaveType')->where('staff_id', $staff->id)->where('year', $this->today()->year)->get(),
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'requests' => LeaveRequest::with(['leaveType:id,name', 'approvals.approver:id,name'])
                ->where('staff_id', $staff->id)->latest()->paginate(15),
        ]);
    }

    public function store(StoreLeaveRequest $request, ApiLeaveController $api): RedirectResponse
    {
        if ($request->boolean('partial_day') && $request->input('from_date') !== $request->input('to_date')) {
            return back()->withInput()->withErrors(['partial_day' => 'A half day must start and end on the same date.']);
        }

        return $this->fromApi($api->store($request), 'Leave request sent for approval.', route('portal.leave.index'));
    }

    public function withdraw(Request $request, LeaveRequest $leaveRequest, ApiLeaveController $api): RedirectResponse
    {
        $this->ensureOwn($leaveRequest);

        return $this->fromApi($api->withdraw($request, $leaveRequest), 'Leave request withdrawn.', route('portal.leave.index'));
    }

    /** Cancel approved leave that has not started yet; the days go back to the balance. */
    public function cancel(Request $request, LeaveRequest $leaveRequest, ApiLeaveController $api): RedirectResponse
    {
        $this->ensureOwn($leaveRequest);

        if ($leaveRequest->from_date->toDateString() < $this->today()->toDateString()) {
            return back()->with('error', 'Leave that has already started cannot be cancelled here. Please contact HR.');
        }

        return $this->fromApi($api->cancel($request, $leaveRequest), 'Leave cancelled. The days are back in your balance.', route('portal.leave.index'));
    }

    private function ensureOwn(LeaveRequest $leaveRequest): void
    {
        abort_unless($leaveRequest->staff_id === $this->staff()->id, 404);
    }
}
