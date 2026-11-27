<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PF and ESI stop using a built-in statutory formula: they become ordinary
 * deduction components with a rate the admin can change (12% of Basic and
 * 0.75% of gross to start), cut for loss-of-pay days. Amounts already on
 * salaries stay as they are.
 */
return new class extends Migration
{
    /** @var array<string, array{calculation: string, value: float}> */
    private const RULES = [
        'PF' => ['calculation' => 'percent_of_basic', 'value' => 12],
        'ESI' => ['calculation' => 'percent_of_gross', 'value' => 0.75],
    ];

    public function up(): void
    {
        foreach (self::RULES as $code => $rule) {
            $ids = DB::table('salary_components')->where('code', $code)->where('calculation', 'statutory')->pluck('id');
            DB::table('salary_components')->whereIn('id', $ids)->update($rule + ['is_prorated' => true, 'updated_at' => now()]);
            DB::table('staff_salary_structure_items')->whereIn('salary_component_id', $ids)->update(['is_prorated' => true]);
        }
    }

    public function down(): void
    {
        foreach (self::RULES as $code => $rule) {
            $ids = DB::table('salary_components')->where('code', $code)->where('calculation', $rule['calculation'])->pluck('id');
            DB::table('salary_components')->whereIn('id', $ids)->update(['calculation' => 'statutory', 'value' => 0, 'is_prorated' => false, 'updated_at' => now()]);
            DB::table('staff_salary_structure_items')->whereIn('salary_component_id', $ids)->update(['is_prorated' => false]);
        }
    }
};
