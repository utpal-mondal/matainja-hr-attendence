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
    public function __construct(private PayrollCalculator $calculator) {}

    /**
     * Split a monthly gross into component amounts, in this order: fixed
     * amounts, % of gross, % of Basic, then whatever is left goes to the
     * (first) "balance" earning. Percentages are rounded to whole rupees.
     * Statutory PF and ESI get their full-month amount; payroll works them
     * out again each month from what was actually earned.
     *
     * @param  Collection<int, SalaryComponent>  $components
     * @return array<int, float> component id => monthly amount
     */
    public function split(float $monthlyGross, Collection $components, bool $pfOnFullBasic = false): array
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

        $gross = $components->where('type', 'earning')->sum(fn (SalaryComponent $c) => $amounts[$c->id]);
        foreach ($components->where('calculation', 'statutory') as $component) {
            $amounts[$component->id] = $this->statutoryAmount($component->code, $basicAmount, $gross, $pfOnFullBasic);
        }

        return $amounts;
    }

    /** Full-month employee PF or ESI for a salary; 0 when it doesn't apply. */
    public function statutoryAmount(string $code, float $basic, float $gross, bool $pfOnFullBasic = false): float
    {
        return match (strtoupper($code)) {
            SalaryComponent::PF_CODE => $basic > 0 ? $this->calculator->providentFund($basic, $pfOnFullBasic)['employee'] : 0.0,
            SalaryComponent::ESI_CODE => $gross > 0 && $gross <= config('payroll.esi.wage_limit')
                ? (float) ceil($gross * config('payroll.esi.employee_rate') / 100) : 0.0,
            default => 0.0,
        };
    }

    /**
     * Save a new revision with a copy of each component's details, so later
     * edits to a component never change this salary. Zero amounts are skipped,
     * so a PF or ESI left at 0 is not deducted; any other amount is replaced
     * by the formula on the saved Basic and gross.
     *
     * @param  Collection<int, SalaryComponent>  $components
     * @param  array<int|string, float|int|string|null>  $amounts  component id => monthly amount
     */
    public function createRevision(Staff $staff, string $effectiveFrom, Collection $components, array $amounts, ?string $notes = null): StaffSalaryStructure
    {
        $basic = $components->first(fn (SalaryComponent $c) => strtoupper($c->code) === SalaryComponent::BASIC_CODE);
        $basicAmount = $basic ? (float) ($amounts[$basic->id] ?? 0) : 0.0;
        $gross = $components->where('type', 'earning')->sum(fn (SalaryComponent $c) => (float) ($amounts[$c->id] ?? 0));
        $pfOnFullBasic = (bool) $staff->payrollProfile()->value('pf_on_full_basic');
        foreach ($components->where('calculation', 'statutory') as $component) {
            if ((float) ($amounts[$component->id] ?? 0) > 0) {
                $amounts[$component->id] = $this->statutoryAmount($component->code, $basicAmount, $gross, $pfOnFullBasic);
            }
        }

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
