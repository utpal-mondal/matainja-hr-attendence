<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AuditLog;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\ApiResponder;
use App\Support\ReferenceCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveController extends Controller
{
    public function types(): JsonResponse
    {
        return ApiResponder::success(ReferenceCache::get('leave_types', fn () => LeaveType::all()), 'Leave types retrieved');
    }

    public function balances(Request $request): JsonResponse
    {
        $staffId = $request->query('staff_id', $request->user()->staff?->id);

        if (! $request->user()->isAdmin() && $staffId != $request->user()->staff?->id) {
            return ApiResponder::error('You do not have permission to perform this action.', 403);
        }

        $balances = LeaveBalance::with('leaveType')
            ->where('staff_id', $staffId)
            ->where('year', $request->query('year', now()->year))
            ->get()
            ->map(fn ($b) => [
                'leave_type' => $b->leaveType?->only('id', 'name', 'code'),
                'year' => $b->year,
                'opening' => $b->opening,
                'accrued' => $b->accrued,
                'used' => $b->used,
                'pending' => $b->pending,
                'available' => $b->available,
            ]);

        return ApiResponder::success($balances, 'Leave balances retrieved');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = LeaveRequest::with(['staff', 'leaveType'])->latest();

        if ($user->hasRole('Manager') && ! $user->isAdmin()) {
            $teamIds = $user->staff?->subordinates()->pluck('id')->push($user->staff->id) ?? collect([-1]);
            $query->whereIn('staff_id', $teamIds);
        } elseif (! $user->isAdmin()) {
            $query->where('staff_id', $user->staff?->id ?? -1);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $requests = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponder::success(
            LeaveRequestResource::collection($requests)->resolve(),
            'Leave requests retrieved'
        );
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $staff = $request->user()->staff;

        $from = Carbon::parse($validated['from_date']);
        $to = Carbon::parse($validated['to_date']);
        $totalDays = $request->boolean('partial_day') ? 0.5 : $from->diffInDays($to) + 1;

        $overlap = LeaveRequest::where('staff_id', $staff->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(fn ($q) => $q->whereBetween('from_date', [$from, $to])
                ->orWhereBetween('to_date', [$from, $to])
                ->orWhere(fn ($q2) => $q2->where('from_date', '<=', $from)->where('to_date', '>=', $to)))
            ->exists();

        if ($overlap) {
            return ApiResponder::error('This request overlaps an existing leave request.', 409);
        }

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('leave-attachments', 'local')
            : null;

        $leaveRequest = DB::transaction(function () use ($staff, $validated, $totalDays, $attachmentPath, $from) {
            $leaveRequest = LeaveRequest::create([
                'staff_id' => $staff->id,
                'leave_type_id' => $validated['leave_type_id'],
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'partial_day' => $validated['partial_day'] ?? false,
                'total_days' => $totalDays,
                'reason' => $validated['reason'] ?? null,
                'attachment_path' => $attachmentPath,
                'status' => 'pending',
            ]);

            // First approval step goes to the staff member's manager; fall back to any HR admin.
            $approver = $staff->manager?->user
                ?? User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Admin', 'Super Admin']))->first();

            if ($approver) {
                LeaveApproval::create([
                    'leave_request_id' => $leaveRequest->id,
                    'approver_id' => $approver->id,
                    'step_order' => 1,
                ]);
            }

            LeaveBalance::firstOrCreate(
                [
                    'staff_id' => $staff->id,
                    'leave_type_id' => $validated['leave_type_id'],
                    'year' => $from->year ?? now()->year,
                ],
                ['opening' => 0, 'accrued' => 0, 'used' => 0, 'pending' => 0]
            )->increment('pending', $totalDays);

            return $leaveRequest;
        });

        return ApiResponder::success(
            new LeaveRequestResource($leaveRequest->load(['leaveType', 'approvals.approver'])),
            'Leave request submitted',
            201
        );
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($denied = $this->authorizeAccess($request, $leaveRequest)) {
            return $denied;
        }

        return ApiResponder::success(
            new LeaveRequestResource($leaveRequest->load(['staff', 'leaveType', 'approvals.approver'])),
            'Leave request retrieved'
        );
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'approved') {
            return ApiResponder::error('Only approved leave can be cancelled.', 409);
        }

        if ($denied = $this->authorizeAccess($request, $leaveRequest)) {
            return $denied;
        }

        DB::transaction(function () use ($leaveRequest) {
            $this->releaseBalance($leaveRequest, 'used');
            $leaveRequest->update(['status' => 'cancelled']);
        });

        return ApiResponder::success(new LeaveRequestResource($leaveRequest->fresh()), 'Leave cancelled');
    }

    public function withdraw(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->staff_id !== $request->user()->staff?->id) {
            return ApiResponder::error('Not found.', 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return ApiResponder::error('Only pending requests can be withdrawn.', 409);
        }

        DB::transaction(function () use ($leaveRequest) {
            $this->releaseBalance($leaveRequest, 'pending');
            $leaveRequest->update(['status' => 'withdrawn']);
        });

        return ApiResponder::success(new LeaveRequestResource($leaveRequest->fresh()), 'Leave request withdrawn');
    }

    public function pendingApprovals(Request $request): JsonResponse
    {
        $approvals = LeaveApproval::with('leaveRequest.staff', 'leaveRequest.leaveType')
            ->where('approver_id', $request->user()->id)
            ->whereNull('decision')
            ->whereHas('leaveRequest', fn ($q) => $q->where('status', 'pending'))
            ->latest()
            ->get();

        return ApiResponder::success($approvals, 'Pending approvals retrieved');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->decide($request, $leaveRequest, 'approved');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $request->validate(['comments' => ['required', 'string', 'max:1000']]);

        return $this->decide($request, $leaveRequest, 'rejected');
    }

    private function decide(Request $request, LeaveRequest $leaveRequest, string $decision): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return ApiResponder::error('This request is no longer pending.', 409);
        }

        $approval = $leaveRequest->approvals()
            ->where('approver_id', $request->user()->id)
            ->whereNull('decision')
            ->first();

        // The request was routed when it was submitted; the employee's
        // current manager can still decide it (their queue shows it).
        $isCurrentManager = $request->user()->staff
            && $leaveRequest->staff?->manager_id === $request->user()->staff->id;

        if (! $approval && ! $request->user()->isAdmin() && ! $isCurrentManager) {
            return ApiResponder::error('You are not an approver for this request.', 403);
        }

        DB::transaction(function () use ($approval, $leaveRequest, $request, $decision) {
            $approval?->update([
                'decision' => $decision,
                'comments' => $request->input('comments'),
                'decided_at' => now(),
            ]);

            if ($decision === 'approved') {
                $this->releaseBalance($leaveRequest, 'pending');
                $this->consumeBalance($leaveRequest, 'used');
                $leaveRequest->update(['status' => 'approved']);
            } else {
                $this->releaseBalance($leaveRequest, 'pending');
                $leaveRequest->update(['status' => 'rejected']);
            }
        });

        AuditLog::record("leave.{$decision}", $leaveRequest);

        return ApiResponder::success(
            new LeaveRequestResource($leaveRequest->fresh()->load(['leaveType', 'approvals.approver'])),
            "Leave request {$decision}"
        );
    }

    private function releaseBalance(LeaveRequest $leaveRequest, string $column): void
    {
        LeaveBalance::where('staff_id', $leaveRequest->staff_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', $leaveRequest->from_date->year)
            ->decrement($column, (float) $leaveRequest->total_days);
    }

    private function consumeBalance(LeaveRequest $leaveRequest, string $column): void
    {
        LeaveBalance::where('staff_id', $leaveRequest->staff_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', $leaveRequest->from_date->year)
            ->increment($column, (float) $leaveRequest->total_days);
    }

    private function authorizeAccess(Request $request, LeaveRequest $leaveRequest): ?JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()
            || $leaveRequest->staff_id === $user->staff?->id
            || $leaveRequest->staff?->manager_id === $user->staff?->id
            || $leaveRequest->approvals()->where('approver_id', $user->id)->exists()) {
            return null;
        }

        return ApiResponder::error('Not found.', 404);
    }
}
