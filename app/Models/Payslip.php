<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'staff_id', 'staff_salary_structure_id',
        'employee_code', 'employee_name', 'designation', 'department', 'pan', 'uan', 'tax_regime',
        'period_days', 'lop_days_calculated', 'lop_days_override', 'lop_days', 'paid_days', 'day_counts', 'overtime_minutes',
        'gross_earnings', 'taxable_earnings', 'employee_pf', 'employee_pf_override', 'pf_wages', 'eps_wages', 'employee_esi', 'employee_esi_override', 'professional_tax', 'tds',
        'total_deductions', 'net_pay', 'employer_pf', 'employer_eps', 'employer_esi',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_days' => 'integer',
            'lop_days_calculated' => 'decimal:1',
            'lop_days_override' => 'decimal:1',
            'lop_days' => 'decimal:1',
            'paid_days' => 'decimal:1',
            'day_counts' => 'array',
            'overtime_minutes' => 'integer',
            'gross_earnings' => 'decimal:2',
            'taxable_earnings' => 'decimal:2',
            'employee_pf' => 'decimal:2',
            'pf_wages' => 'decimal:2',
            'eps_wages' => 'decimal:2',
            'employee_esi' => 'decimal:2',
            'employee_pf_override' => 'decimal:2',
            'employee_esi_override' => 'decimal:2',
            'professional_tax' => 'decimal:2',
            'tds' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'employer_pf' => 'decimal:2',
            'employer_eps' => 'decimal:2',
            'employer_esi' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(StaffSalaryStructure::class, 'staff_salary_structure_id');
    }

    /**
     * Payslips of locked or paid runs (final figures an employee may see),
     * newest month first. Draft runs can still change, so they stay hidden.
     *
     * @param  Builder<Payslip>  $query
     */
    #[Scope]
    protected function released(Builder $query): void
    {
        $query->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payroll_runs.status', '!=', 'draft')
            ->orderByDesc('payroll_runs.year')->orderByDesc('payroll_runs.month')
            ->select('payslips.*');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderByRaw("CASE WHEN type = 'earning' THEN 0 ELSE 1 END")->orderBy('sort_order');
    }
}
