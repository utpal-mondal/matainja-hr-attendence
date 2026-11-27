<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryComponent extends Model
{
    public const TYPES = ['earning' => 'Earning', 'deduction' => 'Deduction'];

    public const CALCULATIONS = [
        'fixed' => 'Fixed amount',
        'percent_of_gross' => '% of monthly gross',
        'percent_of_basic' => '% of Basic',
        'balance' => 'Balance of gross',
        'statutory' => 'Statutory formula (PF / ESI)',
    ];

    /** Code of the component that "% of Basic" refers to. */
    public const BASIC_CODE = 'BASIC';

    /** Deduction components worked out by the statutory formula each payroll month. */
    public const PF_CODE = 'PF';

    public const ESI_CODE = 'ESI';

    public const STATUTORY_CODES = [self::PF_CODE, self::ESI_CODE];

    protected $fillable = [
        'name', 'code', 'type', 'calculation', 'value',
        'is_taxable', 'is_prorated', 'sort_order', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'is_taxable' => 'boolean',
            'is_prorated' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function structureItems(): HasMany
    {
        return $this->hasMany(StaffSalaryStructureItem::class);
    }

    /** @param  Builder<SalaryComponent>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN type = 'earning' THEN 0 ELSE 1 END")->orderBy('sort_order')->orderBy('name');
    }

    /** "40% of Basic", "₹1,600 fixed", "Balance of gross", "12% of Basic (PF wage up to ₹15,000)" */
    public function getRuleLabelAttribute(): string
    {
        $value = Tax::formatRate($this->value);

        if ($this->calculation === 'statutory') {
            return match (strtoupper($this->code)) {
                self::PF_CODE => Tax::formatRate(config('payroll.pf.employee_rate')).'% of Basic (PF wage up to ₹'.number_format(config('payroll.pf.wage_ceiling')).')',
                self::ESI_CODE => Tax::formatRate(config('payroll.esi.employee_rate')).'% of earnings (gross up to ₹'.number_format(config('payroll.esi.wage_limit')).')',
                default => 'Statutory formula',
            };
        }

        return match ($this->calculation) {
            'percent_of_gross' => "{$value}% of gross",
            'percent_of_basic' => "{$value}% of Basic",
            'balance' => 'Balance of gross',
            default => '₹'.number_format((float) $this->value, 2).' fixed',
        };
    }
}
