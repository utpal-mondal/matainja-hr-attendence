<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::orderBy('holiday_date');

        if ($year = $request->query('year')) {
            $query->whereYear('holiday_date', $year);
        }
        if ($branchId = $request->query('branch_id')) {
            $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
        }

        // Read straight from the database (no cache), so a holiday added on
        // the admin website shows in the app on the next request.
        return ApiResponder::success($query->get(), 'Holidays retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'holiday_date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'in:public,restricted,company'],
        ]);

        return ApiResponder::success(Holiday::create($validated), 'Holiday created successfully', 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $validated = $request->validate([
            'holiday_date' => ['sometimes', 'date'],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'in:public,restricted,company'],
        ]);

        $holiday->update($validated);

        return ApiResponder::success($holiday, 'Holiday updated successfully');
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        if ($holiday->holiday_date->isPast()) {
            return ApiResponder::error('Past holidays cannot be removed.', 409);
        }

        $holiday->delete();

        return ApiResponder::success(null, 'Holiday removed');
    }
}
