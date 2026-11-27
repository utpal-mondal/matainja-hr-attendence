<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Support\ApiResponder;
use App\Support\ReferenceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = $request->user()->staff;
        $month = $request->query('month', now()->format('Y-m'));
        $branchId = $staff?->branch_id;

        [$year, $mon] = array_map('intval', explode('-', $month));

        $holidays = collect(ReferenceCache::get('holidays', fn () => Holiday::whereYear('holiday_date', $year)
            ->whereMonth('holiday_date', $mon)
            ->orderBy('holiday_date')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get()
            ->map(fn ($h) => [
                'type' => 'holiday',
                'date' => $h->holiday_date->toDateString(),
                'name' => $h->name,
            ])
            ->all(), "calendar:y{$year}:m{$mon}:b".($branchId ?? 'none')));

        $leaves = $staff
            ? LeaveRequest::where('staff_id', $staff->id)
                ->where('status', 'approved')
                ->where(fn ($q) => $q->whereYear('from_date', $year)->whereMonth('from_date', $mon)
                    ->orWhereYear('to_date', $year)->whereMonth('to_date', $mon))
                ->get()
                ->map(fn ($l) => [
                    'type' => 'leave',
                    'from' => $l->from_date->toDateString(),
                    'to' => $l->to_date->toDateString(),
                    'leave_type' => $l->leaveType?->name,
                ])
            : collect();

        return ApiResponder::success([
            'month' => $month,
            'events' => $holidays->concat($leaves)->values(),
        ], 'Calendar retrieved successfully');
    }
}
