<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Staff::query()->with(['department', 'designation', 'branch', 'team', 'manager', 'currentShiftAssignment.shift']);

        $user = $request->user();

        // Managers only see their reporting team; employees see themselves.
        if ($user->hasRole('Manager') && ! $user->isAdmin()) {
            $query->where('manager_id', $user->staff?->id ?? -1);
        } elseif (! $user->isAdmin() && ! $user->hasPermissionTo('employees.view_all')) {
            $query->where('id', $user->staff?->id ?? -1);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        foreach (['department_id', 'branch_id', 'employment_status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter === 'employment_status' ? 'employment_status' : $filter, $value);
            }
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $staff = $query->paginate($perPage);

        return ApiResponder::success(
            StaffResource::collection($staff)->resolve(),
            'Staff retrieved successfully',
            200,
            ['pagination' => $this->pagination($staff)]
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $staff = DB::transaction(function () use ($validated, $request) {
            $userId = null;
            $plainPassword = null;

            if ($request->boolean('create_login')) {
                $plainPassword = $validated['password'] ?? 'matainja_'.Str::random(6);
                $user = User::create([
                    'name' => trim($validated['first_name'].' '.($validated['last_name'] ?? '')),
                    'email' => $validated['email'],
                    'password' => $plainPassword,
                ]);
                $user->assignRole($validated['role'] ?? 'Employee');
                $userId = $user->id;
            }

            return Staff::create(array_merge($validated, [
                'user_id' => $userId,
                'initial_password' => $plainPassword,
            ]));
        });

        AuditLog::record('staff.created', $staff, null, $staff->toArray());

        return ApiResponder::success(
            new StaffResource($staff->load(['department', 'designation', 'branch', 'team', 'manager', 'currentShiftAssignment.shift'])),
            'Staff created successfully',
            201
        );
    }

    public function show(Request $request, Staff $staff): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()
            && $staff->id !== $user->staff?->id
            && $staff->manager_id !== $user->staff?->id) {
            return ApiResponder::error('Not found.', 404);
        }

        return ApiResponder::success(
            new StaffResource($staff->load(['department', 'designation', 'branch', 'team', 'manager', 'currentShiftAssignment.shift', 'user'])),
            'Staff retrieved successfully'
        );
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $before = $staff->toArray();
        $validated = $request->validated();

        DB::transaction(function () use ($staff, $validated, $request) {
            if ($request->boolean('create_login') && ! $staff->user_id) {
                $plainPassword = $validated['password'] ?? 'matainja_'.Str::random(6);
                $user = User::create([
                    'name' => $staff->full_name,
                    'email' => $validated['email'] ?? $staff->email,
                    'password' => $plainPassword,
                ]);
                $user->assignRole($validated['role'] ?? 'Employee');
                $staff->user_id = $user->id;
                $staff->initial_password = $plainPassword;
            } elseif (! empty($validated['password']) && $staff->user) {
                $staff->user->update(['password' => $validated['password']]);
                $staff->user->tokens()->delete();
                $staff->initial_password = $validated['password'];
            }

            $staff->update(collect($validated)->except(['password', 'create_login', 'role'])->all());
        });

        AuditLog::record('staff.updated', $staff, $before, $staff->fresh()->toArray());

        return ApiResponder::success(
            new StaffResource($staff->load(['department', 'designation', 'branch', 'team', 'manager', 'currentShiftAssignment.shift'])),
            'Staff updated successfully'
        );
    }

    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('employees.deactivate')) {
            return ApiResponder::error('You do not have permission to perform this action.', 403);
        }

        // Prefer deactivate/archive over destructive delete.
        $staff->update(['employment_status' => 'terminated']);
        $staff->user?->tokens()->delete();

        AuditLog::record('staff.deactivated', $staff);

        return ApiResponder::success(null, 'Staff deactivated successfully');
    }

    public function documents(Request $request, Staff $staff): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $staff->id !== $user->staff?->id) {
            return ApiResponder::error('Not found.', 404);
        }

        $documents = $staff->id === $user->staff?->id || $user->isAdmin()
            ? $staff->documents()->latest()->paginate(20)
            : collect();

        return ApiResponder::success($documents, 'Documents retrieved successfully');
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
