<?php

namespace App\Services;

use App\Models\SalaryComponent;
use App\Models\Staff;
use App\Models\StaffSalaryStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds and saves employee salary revisions from the salary components.
 */
class SalaryStructureService
{
    /**
     * Split a monthly gross into component amounts, in this order: fixed
     * amounts, % of gross, % of Basic, then whatever is left goes to the
     * (first) "balance" earning. Percentages are rounded to whole rupees.
     *
     * @param  Collection<int, SalaryComponent>  $components
     * @return array<int, float> component id => monthly amount
     */
    public function split(float $monthlyGross, Collection $components): array
    {
        $amounts = [];

        foreach ($components as $component) {
            $amounts[$component->id] = match ($component->calculation) {
                'fixed' => round((float) $component->value, 2),
                'percent_of_gross' => round($monthlyGross * (float) $component->value / 100),
                default => 0.0,
            };
        }

        $basic = $components->first(fn (SalaryComponent $c) => strtoupper($c->code) === SalaryComponent::BASIC_CODE);
        $basicAmount = $basic ? $amounts[$basic->id] : 0.0;
        foreach ($components->where('calculation', 'percent_of_basic') as $component) {
            $amounts[$component->id] = round($basicAmount * (float) $component->value / 100);
        }

        $balance = $components->first(fn (SalaryComponent $c) => $c->calculation === 'balance' && $c->type === 'earning');
        if ($balance) {
            $otherEarnings = $components->where('type', 'earning')->where('id', '!=', $balance->id)
                ->sum(fn (SalaryComponent $c) => $amounts[$c->id]);
            $amounts[$balance->id] = round(max($monthlyGross - $otherEarnings, 0), 2);
        }

        return $amounts;
    }

    /**
     * Save a new revision with a copy of each component's details, so later
     * edits to a component never change this salary. Zero amounts are skipped.
     *
     * @param  Collection<int, SalaryComponent>  $components
     * @param  array<int|string, float|int|string|null>  $amounts  component id => monthly amount
     */
    public function createRevision(Staff $staff, string $effectiveFrom, Collection $components, array $amounts, ?string $notes = null): StaffSalaryStructure
    {
        return DB::transaction(function () use ($staff, $effectiveFrom, $components, $amounts, $notes) {
            $items = $components
                ->map(fn (SalaryComponent $c) => [
                    'salary_component_id' => $c->id,
                    'name' => $c->name,
                    'code' => $c->code,
                    'type' => $c->type,
                    'monthly_amount' => round((float) ($amounts[$c->id] ?? 0), 2),
                    'is_taxable' => $c->is_taxable,
                    'is_prorated' => $c->is_prorated,
                    'sort_order' => $c->sort_order,
                ])
                ->filter(fn (array $item) => $item['monthly_amount'] > 0)
                ->values();

            $structure = $staff->salaryStructures()->create([
                'effective_from' => $effectiveFrom,
                'monthly_gross' => round($items->where('type', 'earning')->sum('monthly_amount'), 2),
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);
            $structure->items()->createMany($items->all());

            return $structure->load('items');
        });
    }
}
