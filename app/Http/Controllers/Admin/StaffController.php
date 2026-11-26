<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends AdminController
{
    public const STATUSES = ['active', 'probation', 'suspended', 'resigned', 'terminated'];

    public function index(Request $request): View
    {
        $staff = Staff::with(['department:id,name', 'designation:id,title', 'branch:id,name', 'manager:id,first_name,last_name,name', 'user.roles:id,name'])
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->orWhere('employee_code', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($request->query('department'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->query('branch'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('status'), fn ($q, $s) => $q->where('employment_status', $s))
            ->orderBy('first_name')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.staff.index', [
            'staff' => $staff,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'statuses' => self::STATUSES,
            'counts' => Staff::selectRaw('employment_status, COUNT(*) as c')->groupBy('employment_status')->pluck('c', 'employment_status'),
        ]);
    }

    public function create(): View
    {
        $staff = new Staff(['employment_status' => 'active', 'joining_date' => $this->today()]);

        return view('admin.staff.form', array_merge($this->formData($staff), ['nextCode' => $this->nextEmployeeCode()]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $staff = DB::transaction(function () use ($data, $request) {
            $userId = null;
            $plain = null;

            if ($request->boolean('create_login')) {
                $plain = $data['password'] ?? 'matainja_'.Str::random(6);
                $user = User::create([
                    'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                    'email' => $data['email'],
                    'password' => $plain,
                ]);
                $user->assignRole($data['role'] ?? 'Employee');
                $userId = $user->id;
            }

            $staff = Staff::create(array_merge($this->staffFields($data), [
                'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                'user_id' => $userId,
                'initial_password' => $plain,
            ]));
            $this->syncShift($staff, $data['shift_id'] ?? null);

            return $staff;
        });

        AuditLog::record('staff.created', $staff, null, $staff->toArray());

        $message = "Employee {$staff->full_name} added.";
        if ($staff->user_id) {
            $message .= " App login: {$staff->email} / password: {$staff->initial_password}";
        }

        return redirect()->route('admin.staff.edit', $staff)->with('success', $message);
    }

    public function edit(Staff $staff): View
    {
        $staff->load(['user.roles', 'shiftAssignments.shift']);

        return view('admin.staff.form', [
            ...$this->formData($staff),
            // Current and upcoming first, then the most recent past ones.
            'wfhRequests' => $staff->wfhRequests()->with('approver:id,name')
                ->orderByRaw('CASE WHEN to_date >= ? THEN 0 ELSE 1 END', [$this->today()->toDateString()])
                ->orderByDesc('from_date')->limit(10)->get(),
            'today' => $this->today(),
            'leaveRequests' => $staff->leaveRequests()->with(['leaveType:id,name,paid', 'approvals.approver:id,name'])
                ->orderByDesc('from_date')->limit(10)->get(),
            'leaveTypes' => LeaveType::orderBy('name')->get(['id', 'name', 'paid']),
            'leaveBalances' => app(LeaveService::class)->balanceLabels($staff, $this->today()->year),
        ]);
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $data = $this->validated($request, $staff);
        $before = $staff->toArray();
        $notice = '';

        DB::transaction(function () use ($staff, $data, $request, &$notice) {
            $staff->fill($this->staffFields($data));
            $staff->name = trim($data['first_name'].' '.($data['last_name'] ?? ''));

            if ($staff->user) {
                $staff->user->update(['name' => $staff->name, 'email' => $data['email']]);
                if (! empty($data['role'])) {
                    $this->replacePrimaryRole($staff->user, $data['role']);
                }
                if (! empty($data['password'])) {
                    $staff->user->update(['password' => $data['password']]);
                    $staff->user->tokens()->delete();
                    $staff->initial_password = $data['password'];
                    $notice = 'Password changed; the employee was signed out of the app.';
                }
                if (in_array($data['employment_status'], ['resigned', 'terminated', 'suspended'], true)) {
                    $staff->user->tokens()->delete();
                }
            } elseif ($request->boolean('create_login')) {
                $plain = $data['password'] ?? 'matainja_'.Str::random(6);
                $user = User::create(['name' => $staff->name, 'email' => $data['email'], 'password' => $plain]);
                $user->assignRole($data['role'] ?? 'Employee');
                $staff->user_id = $user->id;
                $staff->initial_password = $plain;
                $notice = "App login created: {$data['email']} / password: {$plain}";
            }

            $staff->save();
            $this->syncShift($staff, $data['shift_id'] ?? null);
        });

        AuditLog::record('staff.updated', $staff, $before, $staff->fresh()->toArray());

        return back()->with('success', trim("Employee saved. {$notice}"));
    }

    public function deactivate(Staff $staff): RedirectResponse
    {
        if ($staff->user_id && $staff->user_id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $staff->update(['employment_status' => 'terminated']);
        $staff->user?->tokens()->delete();
        AuditLog::record('staff.deactivated', $staff);

        return back()->with('success', "{$staff->full_name} was deactivated and signed out of the app.");
    }

    /**
     * Permanently delete an employee and their app login. When they have history
     * (see Staff::deletionBlockers()) the admin must ask for a force delete and type
     * the employee code to confirm; the history is then erased with them.
     */
    public function destroy(Request $request, Staff $staff): RedirectResponse
    {
        if ($staff->user_id && $staff->user_id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $blockers = $staff->deletionBlockers();
        if ($blockers !== []) {
            $list = implode(', ', $blockers);

            if (! $request->boolean('force')) {
                return redirect()->to(route('admin.staff.edit', $staff).'#delete')
                    ->with('error', "{$staff->full_name} has {$list}. To delete them anyway, type their employee code below and force delete, or deactivate them instead to keep the history.");
            }

            if (trim((string) $request->input('confirm_code')) !== $staff->deleteConfirmationValue()) {
                $what = $staff->employee_code ? 'employee code' : 'email';

                return back()->with('error', "Not deleted: type the {$what} {$staff->deleteConfirmationValue()} exactly to confirm.");
            }
        }

        $before = [...$staff->load('user')->toArray(), 'erased_history' => $blockers];
        $name = $staff->full_name;

        DB::transaction(function () use ($staff) {
            // These columns have no foreign key, so clear them by hand.
            Department::where('head_staff_id', $staff->id)->update(['head_staff_id' => null]);
            Team::where('manager_id', $staff->id)->update(['manager_id' => null]);

            $user = $staff->user;
            $staff->delete(); // its history cascades (attendance, leave, claims...); reports lose their manager
            if ($user) {
                $user->tokens()->delete(); // signed out of the app
                $user->roles()->detach();
                $user->delete();
            }
        });

        AuditLog::record($blockers === [] ? 'staff.deleted' : 'staff.force_deleted', null, $before);

        return redirect()->route('admin.staff.index')->with('success', "{$name} was deleted".($before['user'] ? ', along with their app login.' : '.'));
    }

    public function reactivate(Staff $staff): RedirectResponse
    {
        $staff->update(['employment_status' => 'active']);
        AuditLog::record('staff.reactivated', $staff);

        return back()->with('success', "{$staff->full_name} is active again.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Staff $staff = null): array
    {
        $userId = $staff?->user_id;

        return $request->validate([
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('staff', 'employee_code')->ignore($staff)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('staff', 'email')->ignore($staff),
                Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'team_id' => ['nullable', 'exists:teams,id'],
            'manager_id' => array_values(array_filter(['nullable', 'exists:staff,id', $staff ? 'not_in:'.$staff->id : null])),
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'joining_date' => ['nullable', 'date'],
            'employment_status' => ['required', Rule::in(self::STATUSES)],
            'create_login' => ['boolean'],
            'web_login_enabled' => ['boolean'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
    }

    /** @return array<string, mixed> */
    private function staffFields(array $data): array
    {
        return collect($data)->only([
            'employee_code', 'first_name', 'last_name', 'email', 'phone', 'position',
            'department_id', 'designation_id', 'branch_id', 'team_id', 'manager_id',
            'joining_date', 'employment_status',
        ])->all() + ['web_login_enabled' => (bool) ($data['web_login_enabled'] ?? false)];
    }

    /** Start a new shift assignment from today when the shift changes. */
    private function syncShift(Staff $staff, mixed $shiftId): void
    {
        if (! $shiftId) {
            return;
        }
        $today = $this->today()->toDateString();
        $current = $staff->shiftAssignments()->whereNull('effective_to')->latest('effective_from')->first();
        if ($current && (int) $current->shift_id === (int) $shiftId) {
            return;
        }
        $staff->shiftAssignments()->whereNull('effective_to')->update(['effective_to' => $today]);
        $staff->shiftAssignments()->create(['shift_id' => $shiftId, 'effective_from' => $today]);
    }

    /** Swap the user's main role (Super Admin / HR Admin / Manager / Employee). */
    private function replacePrimaryRole(User $user, string $roleName): void
    {
        if ($user->hasRole($roleName)) {
            return;
        }
        if ($user->id === auth()->id()) {
            return; // never remove your own Super Admin access from here
        }
        $role = Role::where('name', $roleName)->firstOrFail();
        $before = $user->roles->pluck('name')->all();
        $user->roles()->sync([$role->id => ['model_type' => User::class]]);
        AuditLog::record('user.roles_updated', $user, ['roles' => $before], ['roles' => [$roleName]]);
    }

    private function nextEmployeeCode(): string
    {
        return Staff::nextEmployeeCode();
    }

    /** @return array<string, mixed> */
    private function formData(Staff $staff): array
    {
        $currentShift = $staff->exists
            ? $staff->shiftAssignments()->whereNull('effective_to')->latest('effective_from')->value('shift_id')
            : Shift::orderBy('id')->value('id');

        return [
            'staff' => $staff,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'designations' => Designation::orderBy('title')->get(['id', 'title']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::orderBy('name')->get(),
            'managers' => Staff::whereIn('employment_status', ['active', 'probation'])
                ->when($staff->exists, fn ($q) => $q->where('id', '!=', $staff->id))
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'name', 'employee_code']),
            'roles' => Role::orderBy('id')->pluck('name'),
            'statuses' => self::STATUSES,
            'currentShift' => $currentShift,
            'currentRole' => $staff->user?->roles->first()?->name,
            'nextCode' => null,
        ];
    }
}
