<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\SalaryComponent;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Tax;
use App\Models\Team;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One screen per organisation lookup (branches, departments, ...), driven by
 * the definitions in resources(): list + add form, edit, delete.
 */
class OrgController extends AdminController
{
    public function index(Request $request, string $type): View
    {
        $def = $this->definition($type);
        $query = $def['model']::query()->with($def['with'] ?? []);
        if (isset($def['count'])) {
            $query->withCount($def['count']);
        }
        if ($search = $request->query('q')) {
            $query->where($def['search'], 'like', "%{$search}%");
        }
        foreach ($def['order'] as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return view('admin.org.index', [
            'type' => $type,
            'def' => $def,
            'rows' => $query->paginate(30)->withQueryString(),
            'tabs' => $this->tabs(),
            'blank' => new $def['model'],
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $def = $this->definition($type);
        $data = $this->validated($request, $def);
        $record = $def['model']::create($data);
        AuditLog::record("{$type}.created", $record, null, $record->toArray());

        return back()->with('success', "{$def['singular']} added.");
    }

    public function edit(string $type, int $id): View
    {
        $def = $this->definition($type);

        return view('admin.org.edit', [
            'type' => $type,
            'def' => $def,
            'record' => $def['model']::findOrFail($id),
            'tabs' => $this->tabs(),
        ]);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $def = $this->definition($type);
        $record = $def['model']::findOrFail($id);
        $before = $record->toArray();
        $record->update($this->validated($request, $def, $record));
        AuditLog::record("{$type}.updated", $record, $before, $record->fresh()->toArray());

        return redirect()->route('admin.org.index', $type)->with('success', "{$def['singular']} saved.");
    }

    public function destroy(string $type, int $id): RedirectResponse
    {
        $def = $this->definition($type);
        $record = $def['model']::findOrFail($id);

        foreach ($def['guard'] ?? [] as $relation => $label) {
            if ($record->{$relation}()->exists()) {
                return back()->with('error', "Can't delete this {$def['singular']}: it still has {$label}. Move them first.");
            }
        }

        try {
            $record->delete();
        } catch (QueryException) {
            return back()->with('error', "Can't delete this {$def['singular']} because other records use it.");
        }
        AuditLog::record("{$type}.deleted", null, $record->toArray());

        return back()->with('success', "{$def['singular']} deleted.");
    }

    /** Value to show in a form input. */
    public static function inputValue(Model $record, string $field): mixed
    {
        $value = old($field, $record->{$field});
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return substr($value, 0, 5);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, array $def, ?Model $record = null): array
    {
        $rules = [];
        foreach ($def['fields'] as $name => $field) {
            $fieldRules = is_callable($field['rules']) ? ($field['rules'])($record) : $field['rules'];
            $rules[$name] = $fieldRules;
        }
        $data = $request->validate($rules);
        foreach ($def['fields'] as $name => $field) {
            if (($field['type'] ?? 'text') === 'checkbox') {
                $data[$name] = $request->boolean($name);
            }
        }

        return $data;
    }

    /** @return array<string, string> */
    private function tabs(): array
    {
        // Clients have their own sidebar entry, so they stay out of the Organisation tabs.
        return collect($this->resources())->except('clients')->map(fn ($d) => $d['title'])->all();
    }

    /** @return array<string, mixed> */
    private function definition(string $type): array
    {
        return $this->resources()[$type] ?? abort(404);
    }

    /** @return array<string, array<string, mixed>> */
    private function resources(): array
    {
        $staffOptions = fn () => Staff::whereIn('employment_status', ['active', 'probation'])->orderBy('first_name')
            ->get()->mapWithKeys(fn ($s) => [$s->id => $s->name_with_code])->all();

        return [
            'branches' => [
                'model' => Branch::class, 'title' => 'Branches', 'singular' => 'Branch',
                'intro' => 'Offices and their check-in geofence. Staff must be within the radius to punch in on office days (WFH days skip it).',
                'search' => 'name', 'order' => ['name' => 'asc'], 'count' => ['staff'],
                'guard' => ['staff' => 'employees'],
                'columns' => [
                    'name' => 'Name', 'code' => 'Code', 'address' => 'Address',
                    'geofence' => ['Geofence', fn ($r) => $r->latitude !== null && $r->longitude !== null
                        ? sprintf('%.5f, %.5f · %s m', $r->latitude, $r->longitude, $r->geofence_radius_meters ?: 'default')
                        : 'Not set'],
                    'timezone' => 'Timezone', 'staff_count' => 'Staff', 'status' => 'Status',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'code' => ['label' => 'Code', 'rules' => fn ($r) => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($r)]],
                    'address' => ['label' => 'Address', 'rules' => ['nullable', 'string', 'max:255'], 'wide' => true],
                    'latitude' => ['label' => 'Latitude', 'type' => 'number', 'step' => 'any', 'rules' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude']],
                    'longitude' => ['label' => 'Longitude', 'type' => 'number', 'step' => 'any', 'rules' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude']],
                    'geofence_radius_meters' => ['label' => 'Geofence radius (m)', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:10', 'max:100000'], 'hint' => 'Empty = company default'],
                    'timezone' => ['label' => 'Timezone', 'type' => 'select', 'options' => fn () => array_combine($z = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'UTC'], $z), 'rules' => ['required', 'timezone']],
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => fn () => ['active' => 'Active', 'inactive' => 'Inactive'], 'rules' => ['required', 'in:active,inactive']],
                ],
                'defaults' => ['timezone' => 'Asia/Kolkata', 'status' => 'active'],
            ],
            'departments' => [
                'model' => Department::class, 'title' => 'Departments', 'singular' => 'Department',
                'search' => 'name', 'order' => ['name' => 'asc'], 'count' => ['staff', 'teams'], 'with' => ['head'],
                'guard' => ['staff' => 'employees', 'teams' => 'teams'],
                'columns' => [
                    'name' => 'Name', 'code' => 'Code',
                    'head' => ['Head', fn ($r) => $r->head?->full_name ?? '—'],
                    'staff_count' => 'Staff', 'teams_count' => 'Teams',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'code' => ['label' => 'Code', 'rules' => fn ($r) => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->ignore($r)]],
                    'head_staff_id' => ['label' => 'Department head', 'type' => 'select', 'nullable' => true, 'options' => $staffOptions, 'rules' => ['nullable', 'exists:staff,id']],
                ],
            ],
            'designations' => [
                'model' => Designation::class, 'title' => 'Designations', 'singular' => 'Designation',
                'search' => 'title', 'order' => ['level' => 'asc', 'title' => 'asc'], 'count' => ['staff'],
                'guard' => ['staff' => 'employees'],
                'columns' => ['title' => 'Title', 'level' => 'Level', 'staff_count' => 'Staff'],
                'fields' => [
                    'title' => ['label' => 'Title', 'rules' => ['required', 'string', 'max:255']],
                    'level' => ['label' => 'Level', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:100']],
                ],
                'defaults' => ['level' => 1],
            ],
            'teams' => [
                'model' => Team::class, 'title' => 'Teams', 'singular' => 'Team',
                'search' => 'name', 'order' => ['name' => 'asc'], 'count' => ['members'], 'with' => ['department', 'manager'],
                'guard' => ['members' => 'members'],
                'columns' => [
                    'name' => 'Name',
                    'department' => ['Department', fn ($r) => $r->department?->name ?? '—'],
                    'manager' => ['Manager', fn ($r) => $r->manager?->full_name ?? '—'],
                    'members_count' => 'Members',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'department_id' => ['label' => 'Department', 'type' => 'select', 'options' => fn () => Department::orderBy('name')->pluck('name', 'id')->all(), 'rules' => ['required', 'exists:departments,id']],
                    'manager_id' => ['label' => 'Manager', 'type' => 'select', 'nullable' => true, 'options' => $staffOptions, 'rules' => ['nullable', 'exists:staff,id']],
                ],
            ],
            'shifts' => [
                'model' => Shift::class, 'title' => 'Shifts', 'singular' => 'Shift',
                'search' => 'name', 'order' => ['start_time' => 'asc'], 'count' => ['assignments'],
                'guard' => ['assignments' => 'employees assigned'],
                'columns' => [
                    'name' => 'Name',
                    'hours' => ['Hours', fn ($r) => substr((string) $r->start_time, 0, 5).' – '.substr((string) $r->end_time, 0, 5).($r->crosses_midnight ? ' (+1 day)' : '')],
                    'grace_minutes' => 'Grace (min)', 'break_policy' => 'Break policy', 'assignments_count' => 'Assignments',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'start_time' => ['label' => 'Start time', 'type' => 'time', 'rules' => ['required', 'date_format:H:i']],
                    'end_time' => ['label' => 'End time', 'type' => 'time', 'rules' => ['required', 'date_format:H:i']],
                    'grace_minutes' => ['label' => 'Late grace (minutes)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:240']],
                    'break_policy' => ['label' => 'Break policy', 'rules' => ['nullable', 'string', 'max:255'], 'hint' => 'e.g. 60 min lunch'],
                    'crosses_midnight' => ['label' => 'Ends after midnight', 'type' => 'checkbox', 'rules' => ['nullable']],
                ],
                'defaults' => ['start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15],
            ],
            'holidays' => [
                'model' => Holiday::class, 'title' => 'Holidays', 'singular' => 'Holiday',
                'search' => 'name', 'order' => ['holiday_date' => 'desc'], 'with' => ['branch'],
                'columns' => [
                    'holiday_date' => ['Date', fn ($r) => $r->holiday_date?->format('D, d M Y')],
                    'name' => 'Name', 'type' => 'Type',
                    'branch' => ['Branch', fn ($r) => $r->branch?->name ?? 'All branches'],
                ],
                'fields' => [
                    'holiday_date' => ['label' => 'Date', 'type' => 'date', 'rules' => ['required', 'date']],
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'type' => ['label' => 'Type', 'type' => 'select', 'options' => fn () => ['public' => 'Public', 'optional' => 'Optional', 'company' => 'Company'], 'rules' => ['required', 'in:public,optional,company']],
                    'branch_id' => ['label' => 'Branch', 'type' => 'select', 'nullable' => true, 'nullLabel' => 'All branches', 'options' => fn () => Branch::orderBy('name')->pluck('name', 'id')->all(), 'rules' => ['nullable', 'exists:branches,id']],
                ],
                'defaults' => ['type' => 'public'],
            ],
            'leave-types' => [
                'model' => LeaveType::class, 'title' => 'Leave types', 'singular' => 'Leave type',
                'search' => 'name', 'order' => ['name' => 'asc'], 'count' => ['requests'],
                'guard' => ['requests' => 'leave requests'],
                'columns' => [
                    'name' => 'Name', 'code' => 'Code',
                    'paid' => ['Paid', fn ($r) => $r->paid ? 'Paid' : 'Unpaid'],
                    'requires_document' => ['Document', fn ($r) => $r->requires_document ? 'Required' : '—'],
                    'max_days' => ['Max days / year', fn ($r) => $r->max_days ?? 'No limit'],
                    'requests_count' => 'Requests',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'code' => ['label' => 'Code', 'rules' => fn ($r) => ['required', 'string', 'max:20', Rule::unique('leave_types', 'code')->ignore($r)]],
                    'max_days' => ['label' => 'Max days / year', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:0', 'max:366']],
                    'paid' => ['label' => 'Paid leave', 'type' => 'checkbox', 'rules' => ['nullable']],
                    'requires_document' => ['label' => 'Needs a document', 'type' => 'checkbox', 'rules' => ['nullable']],
                ],
                'defaults' => ['paid' => true],
            ],
            'taxes' => [
                'model' => Tax::class, 'title' => 'Taxes', 'singular' => 'Tax',
                'intro' => 'Tax rates you can tick on an invoice. "Default" taxes are ticked on new invoices. Changing a rate only affects invoices you create or edit afterwards.',
                'search' => 'name', 'order' => ['is_active' => 'desc', 'name' => 'asc', 'rate' => 'asc'],
                'columns' => [
                    'name' => 'Name',
                    'rate' => ['Rate', fn ($r) => Tax::formatRate($r->rate).'%'],
                    'description' => ['Description', fn ($r) => $r->description ?: '—'],
                    'is_default' => ['Default', fn ($r) => $r->is_default ? 'Yes' : '—'],
                    'is_active' => ['Status', fn ($r) => $r->is_active ? 'Active' : 'Turned off'],
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:100'], 'hint' => 'e.g. CGST, SGST, IGST, GST'],
                    'rate' => ['label' => 'Rate (%)', 'type' => 'number', 'step' => '0.01', 'rules' => fn ($r) => ['required', 'numeric', 'min:0', 'max:100',
                        Rule::unique('taxes', 'rate')->where('name', request('name'))->ignore($r)]],
                    'description' => ['label' => 'Description', 'rules' => ['nullable', 'string', 'max:255'], 'wide' => true, 'hint' => 'e.g. Central GST for sales within West Bengal'],
                    'is_default' => ['label' => 'Tick on new invoices', 'type' => 'checkbox', 'rules' => ['nullable']],
                    'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'rules' => ['nullable']],
                ],
                'defaults' => ['is_active' => true],
            ],
            'salary-components' => [
                'model' => SalaryComponent::class, 'title' => 'Salary components', 'singular' => 'Salary component',
                'intro' => 'The earnings (and fixed deductions) an employee\'s salary is split into. "Balance of gross" takes whatever is left, so the parts always add up. PF and ESI are deductions with codes PF / ESI and the "Statutory formula" rule: payroll deducts them, using the statutory rates and limits, for everyone whose salary includes them. Professional Tax and TDS are worked out by payroll; don\'t add them here. Changes only affect salary revisions saved afterwards.',
                'search' => 'name', 'order' => ['is_active' => 'desc', 'type' => 'desc', 'sort_order' => 'asc', 'name' => 'asc'],
                'guard' => ['structureItems' => 'salary revisions using it (turn it off instead)'],
                'columns' => [
                    'name' => 'Name', 'code' => 'Code',
                    'type' => ['Type', fn ($r) => SalaryComponent::TYPES[$r->type] ?? $r->type],
                    'calculation' => ['Rule', fn ($r) => $r->rule_label],
                    'is_taxable' => ['Taxable', fn ($r) => $r->is_taxable ? 'Yes' : 'No'],
                    'is_prorated' => ['Cut for LOP', fn ($r) => $r->is_prorated ? 'Yes' : 'No'],
                    'sort_order' => 'Order',
                    'is_active' => ['Status', fn ($r) => $r->is_active ? 'Active' : 'Turned off'],
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:100'], 'hint' => 'e.g. Basic, HRA, Special Allowance'],
                    'code' => ['label' => 'Code', 'rules' => fn ($r) => ['required', 'alpha_dash', 'max:30', Rule::unique('salary_components', 'code')->ignore($r)],
                        'hint' => 'Use BASIC for Basic pay: "% of Basic" and PF use it. PF and ESI are kept for the statutory deductions'],
                    'type' => ['label' => 'Type', 'type' => 'select', 'options' => fn () => SalaryComponent::TYPES, 'rules' => ['required', Rule::in(array_keys(SalaryComponent::TYPES))]],
                    'calculation' => ['label' => 'Calculation', 'type' => 'select', 'options' => fn () => SalaryComponent::CALCULATIONS, 'rules' => ['required', Rule::in(array_keys(SalaryComponent::CALCULATIONS)),
                        function (string $attribute, mixed $value, \Closure $fail) {
                            if ($value === 'balance' && request('type') !== 'earning') {
                                $fail('Only an earning can take the balance of gross.');
                            }
                            $isStatutoryCode = in_array(strtoupper((string) request('code')), SalaryComponent::STATUTORY_CODES, true);
                            if ($value === 'statutory' && ! $isStatutoryCode) {
                                $fail('The statutory formula is only for the PF and ESI components (code PF or ESI).');
                            } elseif ($isStatutoryCode && ($value !== 'statutory' || request('type') !== 'deduction')) {
                                $fail('PF and ESI must be deductions with the "Statutory formula" rule.');
                            }
                        }]],
                    'value' => ['label' => 'Amount (₹) or percent', 'type' => 'number', 'step' => '0.01', 'rules' => ['required', 'numeric', 'min:0', 'max:99999999',
                        function (string $attribute, mixed $value, \Closure $fail) {
                            if (str_starts_with((string) request('calculation'), 'percent_') && $value > 100) {
                                $fail('A percentage can\'t be more than 100.');
                            }
                        }], 'hint' => 'Ignored for "Balance of gross" and "Statutory formula"'],
                    'sort_order' => ['label' => 'Order on payslip', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:1000']],
                    'is_taxable' => ['label' => 'Taxable (counts for TDS)', 'type' => 'checkbox', 'rules' => ['nullable']],
                    'is_prorated' => ['label' => 'Cut for loss-of-pay days', 'type' => 'checkbox', 'rules' => ['nullable']],
                    'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'rules' => ['nullable']],
                ],
                'defaults' => ['type' => 'earning', 'calculation' => 'fixed', 'value' => 0, 'sort_order' => 10, 'is_taxable' => true, 'is_prorated' => true, 'is_active' => true],
            ],
            'clients' => [
                'model' => Client::class, 'title' => 'Clients', 'singular' => 'Client', 'modal' => true,
                'search' => 'name', 'order' => ['name' => 'asc'],
                'guard' => ['invoices' => 'invoices (mark the client inactive instead)'],
                'columns' => [
                    'name' => 'Name', 'code' => 'Code', 'contact_person' => 'Contact person',
                    'email' => 'Email', 'phone' => 'Phone', 'city' => 'City', 'status' => 'Status',
                ],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'code' => ['label' => 'Code', 'rules' => fn ($r) => ['required', 'string', 'max:50', Rule::unique('clients', 'code')->ignore($r)]],
                    'contact_person' => ['label' => 'Contact person', 'rules' => ['nullable', 'string', 'max:255']],
                    'email' => ['label' => 'Email', 'type' => 'email', 'rules' => ['nullable', 'email', 'max:255']],
                    'phone' => ['label' => 'Phone', 'rules' => ['nullable', 'string', 'max:30']],
                    'website' => ['label' => 'Website', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255'], 'hint' => 'Include https://'],
                    'tax_id' => ['label' => 'GSTIN / Tax ID', 'rules' => ['nullable', 'string', 'max:50']],
                    'address' => ['label' => 'Address', 'rules' => ['nullable', 'string', 'max:255'], 'wide' => true],
                    'city' => ['label' => 'City', 'rules' => ['nullable', 'string', 'max:100']],
                    'country' => ['label' => 'Country', 'rules' => ['nullable', 'string', 'max:100']],
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => fn () => ['active' => 'Active', 'inactive' => 'Inactive'], 'rules' => ['required', 'in:active,inactive']],
                    'notes' => ['label' => 'Notes', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:5000'], 'wide' => true],
                ],
                'defaults' => ['status' => 'active', 'country' => 'India'],
            ],
        ];
    }
}
