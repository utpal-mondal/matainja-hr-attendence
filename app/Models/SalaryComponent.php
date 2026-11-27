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
    ];

    /** Code of the component that "% of Basic" refers to. */
    public const BASIC_CODE = 'BASIC';

    /** Deductions payroll records as the employee's PF and ESI (ECR file, totals, 80C). */
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

    /** "40% of Basic", "₹1,600 fixed", "Balance of gross" */
    public function getRuleLabelAttribute(): string
    {
        $value = Tax::formatRate($this->value);

        return match ($this->calculation) {
            'percent_of_gross' => "{$value}% of gross",
            'percent_of_basic' => "{$value}% of Basic",
            'balance' => 'Balance of gross',
            default => '₹'.number_format((float) $this->value, 2).' fixed',
        };
    }
}
