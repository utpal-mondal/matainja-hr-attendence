<?php

use Database\Seeders\SalaryComponentSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PF and ESI move from the payroll profile flags to salary components: an
 * employee pays them when their salary includes them. Existing salary
 * revisions get PF / ESI items wherever the old flags deducted them, so
 * payroll keeps the same figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A fresh install gets its components from the seeder.
        if (! DB::table('salary_components')->exists()) {
            return;
        }

        $componentIds = [];
        foreach (SalaryComponentSeeder::STATUTORY as $component) {
            $existing = DB::table('salary_components')->where('code', $component['code'])->first();
            $componentIds[] = $existing->id
                ?? DB::table('salary_components')->insertGetId($component + ['created_at' => now(), 'updated_at' => now()]);
        }
        $components = DB::table('salary_components')->whereIn('id', $componentIds)->get()->keyBy('code');

        $profiles = DB::table('staff_payroll_profiles')->get()->keyBy('staff_id');
        $ceiling = (float) config('payroll.pf.wage_ceiling');

        foreach (DB::table('staff_salary_structures')->get() as $structure) {
            $items = DB::table('staff_salary_structure_items')->where('staff_salary_structure_id', $structure->id)->get();
            $profile = $profiles->get($structure->staff_id);
            $basic = (float) $items->first(fn ($item) => $item->type === 'earning' && strtoupper($item->code) === 'BASIC')?->monthly_amount;
            $gross = (float) $structure->monthly_gross;

            $amounts = [];
            if (($profile->pf_applicable ?? true) && $basic > 0) {
                $wage = round(($profile->pf_on_full_basic ?? false) ? $basic : min($basic, $ceiling));
                $amounts['PF'] = round($wage * config('payroll.pf.employee_rate') / 100);
            }
            if (($profile->esi_applicable ?? true) && $gross > 0 && $gross <= config('payroll.esi.wage_limit')) {
                $amounts['ESI'] = ceil($gross * config('payroll.esi.employee_rate') / 100);
            }

            foreach ($amounts as $code => $amount) {
                if ($amount <= 0 || $items->contains(fn ($item) => $item->type === 'deduction' && strtoupper($item->code) === $code)) {
                    continue;
                }
                $component = $components[$code];
                DB::table('staff_salary_structure_items')->insert([
                    'staff_salary_structure_id' => $structure->id,
                    'salary_component_id' => $component->id,
                    'name' => $component->name,
                    'code' => $component->code,
                    'type' => 'deduction',
                    'monthly_amount' => $amount,
                    'is_taxable' => false,
                    'is_prorated' => false,
                    'sort_order' => $component->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('salary_components')->where('calculation', 'statutory')->pluck('id');
        DB::table('staff_salary_structure_items')->whereIn('salary_component_id', $ids)->delete();
        DB::table('salary_components')->whereIn('id', $ids)->delete();
    }
};
