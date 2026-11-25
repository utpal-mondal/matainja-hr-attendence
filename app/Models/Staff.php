<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'user_id', 'initial_password', 'employee_code', 'first_name', 'last_name', 'name', 'email',
        'phone', 'position', 'department_id', 'designation_id', 'branch_id',
        'team_id', 'manager_id', 'joining_date', 'employment_status',
    ];

    /**
     * Never serialise the temporary password (e.g. into audit logs); the
     * StaffResource exposes it to admins explicitly.
     *
     * @var list<string>
     */
    protected $hidden = ['initial_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'initial_password' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Staff::class, 'manager_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(StaffShiftAssignment::class);
    }

    /**
     * The assignment in effect today (latest effective_from wins, so the
     * hand-over day of a shift change already shows the new shift). Same rule
     * as AttendanceService::currentShiftAssignment, but eager-loadable for
     * staff lists.
     */
    public function currentShiftAssignment(): HasOne
    {
        $today = now()->toDateString();

        return $this->hasOne(StaffShiftAssignment::class)->ofMany(
            ['effective_from' => 'max', 'id' => 'max'],
            fn ($q) => $q->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today)),
        );
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_staff_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function payrollProfile(): HasOne
    {
        return $this->hasOne(StaffPayrollProfile::class);
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(StaffSalaryStructure::class)->orderByDesc('effective_from');
    }

    public function wfhRequests(): HasMany
    {
        return $this->hasMany(WfhRequest::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** The salary revision in force on the given date (today by default). */
    public function salaryStructureOn(?CarbonInterface $date = null): ?StaffSalaryStructure
    {
        return $this->salaryStructures()
            ->whereDate('effective_from', '<=', ($date ?? now())->toDateString())
            ->with('items')
            ->first();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: ($this->name ?? '');
    }

    /** "Asha Roy (M-140)", or just the name when there is no employee code. */
    public function getNameWithCodeAttribute(): string
    {
        return $this->employee_code ? "{$this->full_name} ({$this->employee_code})" : $this->full_name;
    }

    /** What the admin types to confirm a force delete: the code, or the email when there is no code. */
    public function deleteConfirmationValue(): string
    {
        return (string) ($this->employee_code ?: ($this->email ?: $this->id));
    }

    /**
     * Records that would be lost if this employee (and their app login) were
     * deleted: the database removes them along with the employee or the user.
     * Empty means the employee can be deleted safely.
     *
     * @return list<string> e.g. ["12 attendance days", "1 leave request"]; empty = safe to delete
     */
    public function deletionBlockers(): array
    {
        // [table, column, singular, plural]
        $byStaff = [
            ['attendance', 'staff_id', 'attendance day', 'attendance days'],
            ['attendance_correction_requests', 'staff_id', 'attendance correction', 'attendance corrections'],
            ['leave_requests', 'staff_id', 'leave request', 'leave requests'],
            ['wfh_requests', 'staff_id', 'WFH request', 'WFH requests'],
            ['expense_claims', 'staff_id', 'expense claim', 'expense claims'],
            ['meetings', 'organiser_id', 'meeting organised', 'meetings organised'],
            ['meeting_participants', 'staff_id', 'meeting attended', 'meetings attended'],
            ['asset_assignments', 'staff_id', 'asset assignment', 'asset assignments'],
            ['tickets', 'staff_id', 'helpdesk ticket', 'helpdesk tickets'],
            ['documents', 'owner_staff_id', 'document', 'documents'],
            ['staff_salary_structures', 'staff_id', 'salary revision', 'salary revisions'],
            ['payslips', 'staff_id', 'payslip', 'payslips'],
        ];
        // Approvals and comments the login made on other people's requests.
        $byUser = [
            ['leave_approvals', 'approver_id', 'leave approval given', 'leave approvals given'],
            ['expense_approvals', 'approver_id', 'expense approval given', 'expense approvals given'],
            ['ticket_comments', 'user_id', 'ticket comment', 'ticket comments'],
        ];

        $checks = array_map(fn (array $check) => [...$check, $this->id], $byStaff);
        if ($this->user_id) {
            $checks = [...$checks, ...array_map(fn (array $check) => [...$check, $this->user_id], $byUser)];
        }

        $blockers = [];
        foreach ($checks as [$table, $column, $singular, $plural, $id]) {
            $count = DB::table($table)->where($column, $id)->count();
            if ($count > 0) {
                $blockers[] = $count.' '.($count === 1 ? $singular : $plural);
            }
        }

        return $blockers;
    }

    /** Next unused employee code, following the newest code's prefix (EMP-004 -> EMP-005). */
    public static function nextEmployeeCode(): string
    {
        $codes = static::orderBy('id')->pluck('employee_code')->filter();
        $max = $codes->map(fn ($c) => (int) preg_replace('/\D/', '', (string) $c))->max() ?? 0;
        $prefix = preg_replace('/\d+$/', '', (string) $codes->last()) ?: 'EMP';

        do {
            $code = $prefix.str_pad((string) ++$max, 3, '0', STR_PAD_LEFT);
        } while (static::where('employee_code', $code)->exists());

        return $code;
    }
}
