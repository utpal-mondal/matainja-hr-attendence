<?php

namespace Database\Seeders;

use App\Models\SalaryComponent;
use Illuminate\Database\Seeder;

/**
 * A common Indian salary split: Basic 50% of gross, HRA 40% of Basic and
 * Special Allowance for the rest, plus the statutory PF and ESI deductions.
 * Safe to run again: existing components are kept as they are.
 */
class SalaryComponentSeeder extends Seeder
{
    /** PF and ESI deductions, worked out by the statutory formula in payroll. */
    public const STATUTORY = [
        ['code' => 'PF', 'name' => 'Provident Fund', 'type' => 'deduction', 'calculation' => 'statutory', 'value' => 0, 'sort_order' => 1, 'is_taxable' => false, 'is_prorated' => false, 'is_active' => true],
        ['code' => 'ESI', 'name' => 'ESI', 'type' => 'deduction', 'calculation' => 'statutory', 'value' => 0, 'sort_order' => 2, 'is_taxable' => false, 'is_prorated' => false, 'is_active' => true],
    ];

    public function run(): void
    {
        $components = [
            ['code' => 'BASIC', 'name' => 'Basic', 'calculation' => 'percent_of_gross', 'value' => 50, 'sort_order' => 1],
            ['code' => 'HRA', 'name' => 'House Rent Allowance', 'calculation' => 'percent_of_basic', 'value' => 40, 'sort_order' => 2],
            ['code' => 'SPECIAL', 'name' => 'Special Allowance', 'calculation' => 'balance', 'value' => 0, 'sort_order' => 9],
        ];

        foreach ($components as $component) {
            SalaryComponent::firstOrCreate(
                ['code' => $component['code']],
                $component + ['type' => 'earning', 'is_taxable' => true, 'is_prorated' => true, 'is_active' => true],
            );
        }

        foreach (self::STATUTORY as $component) {
            SalaryComponent::firstOrCreate(['code' => $component['code']], $component);
        }
    }
}
