<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Staff;
use App\Models\User;
use App\Support\CsvDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Reads an employee CSV (e.g. a Google Form export), cleans each row and
 * creates the employees. parse() never writes; import() takes its rows.
 */
class StaffImportService
{
    /**
     * Accepted header spellings, compared after lower-casing and removing
     * everything except letters and digits ("Employee code *" -> "employeecode").
     *
     * @var array<string, list<string>>
     */
    public const HEADER_ALIASES = [
        'first_name' => ['firstname', 'first', 'givenname'],
        'last_name' => ['lastname', 'surname', 'last', 'familyname'],
        'email' => ['workemail', 'email', 'emailaddress', 'officialemail', 'officeemail'],
        'phone' => ['phoneno', 'phone', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'contactno', 'contactnumber'],
        'employee_code' => ['employeecode', 'empcode', 'code', 'employeeid', 'empid', 'staffcode'],
        'joining_date' => ['joiningdate', 'dateofjoining', 'doj', 'joindate', 'startdate'],
        'designation' => ['designation'],
        'position' => ['jobtitle', 'position', 'title'],
        'department' => ['department', 'dept'],
    ];

    /** Column headers for the downloadable template. */
    public const TEMPLATE_HEADERS = ['First name', 'Last name', 'Work email', 'Phone No.', 'Employee code', 'Joining date', 'Designation', 'Job title', 'Department'];

    /** Values people type when they don't have an employee code. */
    private const CODE_PLACEHOLDERS = ['na', 'n/a', 'null', 'none', 'nil', 'tbd', 'nill', 'unknown', '-', '--', '0'];

    /**
     * Read and clean the CSV. Rows with errors are returned too (so the admin
     * sees why) but import() skips them.
     *
     * @return array{error: ?string, rows: list<array{line: int, data: array<string, mixed>, warnings: list<string>, errors: list<string>}>}
     */
    public function parse(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $columns = null;
        $raw = [];
        foreach ($file as $index => $cells) {
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }
            if ($columns === null) {
                $columns = $this->mapHeaders($cells);
                if (! isset($columns['first_name'], $columns['email'])) {
                    return ['error' => 'The file needs at least a "First name" and a "Work email" column. Download the template to see the expected columns.', 'rows' => []];
                }

                continue;
            }
            if (implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue;
            }

            $values = [];
            foreach ($columns as $field => $position) {
                $values[$field] = trim((string) ($cells[$position] ?? ''));
            }
            $raw[] = ['line' => $index + 1, 'values' => $values];
        }

        if ($columns === null || $raw === []) {
            return ['error' => 'The file has no employee rows.', 'rows' => []];
        }

        return ['error' => null, 'rows' => $this->cleanRows($raw)];
    }

    /**
     * Create the employees from parse() rows. Rows with errors are skipped, and
     * every row is re-checked against the database in case something changed
     * since the preview.
     *
     * @param  list<array{line: int, data: array<string, mixed>, warnings: list<string>, errors: list<string>}>  $rows
     * @param  array{create_logins: bool, role: string, branch_id: ?int, department_id: ?int, shift_id: ?int, employment_status: string}  $options
     * @return array{created: list<array{id: int, name: string, code: ?string, email: string, password: ?string}>, skipped: list<array{line: int, name: string, reason: string}>}
     */
    public function import(array $rows, array $options): array
    {
        $created = [];
        $skipped = [];
        $designationIds = Designation::pluck('id', 'title')->mapWithKeys(fn ($id, $title) => [mb_strtolower($title) => $id])->all();
        $today = now()->toDateString();

        foreach ($rows as $row) {
            $data = $row['data'];
            $name = trim($data['first_name'].' '.($data['last_name'] ?? ''));

            if ($row['errors'] !== []) {
                $skipped[] = ['line' => $row['line'], 'name' => $name, 'reason' => implode(' ', $row['errors'])];

                continue;
            }
            if ($reason = $this->takenReason($data['email'], $data['employee_code'])) {
                $skipped[] = ['line' => $row['line'], 'name' => $name, 'reason' => $reason];

                continue;
            }

            $created[] = DB::transaction(function () use ($data, $name, $options, &$designationIds, $today) {
                $designationId = null;
                if ($data['designation']) {
                    $key = mb_strtolower($data['designation']);
                    $designationIds[$key] ??= Designation::create(['title' => $data['designation']])->id;
                    $designationId = $designationIds[$key];
                }

                $userId = null;
                $password = null;
                if ($options['create_logins']) {
                    $password = 'matainja_'.Str::random(6);
                    $user = User::create(['name' => $name, 'email' => $data['email'], 'password' => $password]);
                    $user->assignRole($options['role']);
                    $userId = $user->id;
                }

                $staff = Staff::create([
                    'user_id' => $userId,
                    'initial_password' => $password,
                    'employee_code' => $data['employee_code'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'name' => $name,
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'position' => $data['position'],
                    'designation_id' => $designationId,
                    'department_id' => $data['department_id'] ?? $options['department_id'],
                    'branch_id' => $options['branch_id'],
                    'joining_date' => $data['joining_date'],
                    'employment_status' => $options['employment_status'],
                ]);

                if ($options['shift_id']) {
                    $staff->shiftAssignments()->create(['shift_id' => $options['shift_id'], 'effective_from' => $today]);
                }

                return ['id' => $staff->id, 'name' => $name, 'code' => $data['employee_code'], 'email' => $data['email'], 'password' => $password];
            });
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param  list<string|null>  $cells
     * @return array<string, int> field => column position
     */
    private function mapHeaders(array $cells): array
    {
        $lookup = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[$alias] = $field;
            }
        }

        $columns = [];
        foreach ($cells as $position => $header) {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
            $key = preg_replace('/[^a-z0-9]/', '', strtolower($header));
            if (isset($lookup[$key]) && ! isset($columns[$lookup[$key]])) {
                $columns[$lookup[$key]] = $position;
            }
        }

        return $columns;
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $raw
     * @return list<array{line: int, data: array<string, mixed>, warnings: list<string>, errors: list<string>}>
     */
    private function cleanRows(array $raw): array
    {
        $existingEmails = array_flip(array_map('strtolower', [
            ...Staff::whereNotNull('email')->pluck('email')->all(),
            ...User::pluck('email')->all(),
        ]));
        $existingCodes = Staff::whereNotNull('employee_code')->pluck('employee_code')
            ->mapWithKeys(fn ($code) => [mb_strtolower($code) => true])->all();
        $designations = Designation::pluck('title')->mapWithKeys(fn ($t) => [mb_strtolower($t) => $t])->all();
        $departments = Department::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();

        $rows = [];
        $seenEmails = [];
        $seenCodes = [];

        foreach ($raw as $i => ['line' => $line, 'values' => $v]) {
            $warnings = [];
            $errors = [];

            $firstName = $this->cleanName($v['first_name'] ?? '');
            $lastName = $this->cleanName($v['last_name'] ?? '') ?: null;
            if ($firstName === '') {
                $errors[] = 'First name is missing.';
            }

            $email = mb_strtolower(trim($v['email'] ?? ''));
            if ($email === '') {
                $errors[] = 'Work email is missing.';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "\"{$email}\" is not a valid email.";
            } elseif (isset($existingEmails[$email])) {
                $errors[] = "{$email} already belongs to an employee or login.";
            } elseif (isset($seenEmails[$email])) {
                $errors[] = "{$email} is repeated (also on line {$seenEmails[$email]}).";
            } else {
                $seenEmails[$email] = $line;
            }

            $phone = preg_replace('/[^\d+]/', '', $v['phone'] ?? '') ?: null;
            if ($phone !== null && strlen($phone) > 30) {
                $warnings[] = 'Phone number is too long, left blank.';
                $phone = null;
            }

            $rawCode = $v['employee_code'] ?? '';
            $code = $this->usableCode($rawCode);
            if ($code !== null && isset($existingCodes[mb_strtolower($code)])) {
                $errors[] = "Employee code {$code} is already used by another employee.";
            } elseif ($code !== null && isset($seenCodes[mb_strtolower($code)])) {
                $warnings[] = "Code {$code} is repeated in the file, so it is left blank.";
                $code = null;
            } elseif ($code === null && $rawCode !== '') {
                $warnings[] = "\"{$rawCode}\" isn't a usable employee code, so it is left blank.";
            }
            if ($code !== null) {
                $seenCodes[mb_strtolower($code)] = true;
            }

            $joiningDate = null;
            if (($v['joining_date'] ?? '') !== '') {
                $joiningDate = $this->parseDate($v['joining_date']);
                if ($joiningDate === null) {
                    $warnings[] = "Couldn't read joining date \"{$v['joining_date']}\", left blank.";
                } elseif ($joiningDate > now()->toDateString()) {
                    $warnings[] = 'Joining date is in the future.';
                }
            }

            $designation = $this->squish($v['designation'] ?? '') ?: null;
            if ($designation !== null) {
                $key = mb_strtolower($designation);
                if (isset($designations[$key])) {
                    $designation = $designations[$key];
                } else {
                    $warnings[] = "New designation \"{$designation}\" will be created.";
                    $designations[$key] = $designation;
                }
            }

            $departmentId = null;
            $department = $this->squish($v['department'] ?? '');
            if ($department !== '') {
                $departmentId = $departments[mb_strtolower($department)] ?? null;
                if ($departmentId === null) {
                    $warnings[] = "Department \"{$department}\" doesn't exist, so the default department is used.";
                }
            }

            $rows[$i] = [
                'line' => $line,
                'data' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'employee_code' => $code,
                    'joining_date' => $joiningDate,
                    'designation' => $designation,
                    'position' => $this->fixCase($this->squish($v['position'] ?? '')) ?: null,
                    'department_id' => $departmentId,
                ],
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return array_values($rows);
    }

    /** Null when the value is blank, a placeholder ("N/A") or a sentence. */
    private function usableCode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || in_array(mb_strtolower($value), self::CODE_PLACEHOLDERS, true) || mb_strlen($value) > 50) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $value) ? mb_strtoupper($value) : null;
    }

    /** Day-first dates (19.06.2025, 17/12/22), ISO dates, or text ("9th October 2026"). */
    private function parseDate(string $value): ?string
    {
        return CsvDate::parse($value);
    }

    private function cleanName(string $value): string
    {
        return $this->fixCase($this->squish($value));
    }

    /** "MAHADEV" / "full stack developer" -> "Mahadev" / "Full Stack Developer"; mixed case is kept. */
    private function fixCase(string $value): string
    {
        if ($value !== '' && ($value === mb_strtoupper($value) || $value === mb_strtolower($value))) {
            return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
        }

        return $value;
    }

    private function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** Why a row can no longer be created (email or code taken since the preview), or null. */
    private function takenReason(string $email, ?string $code): ?string
    {
        if (Staff::where('email', $email)->exists() || User::where('email', $email)->exists()) {
            return "{$email} already belongs to an employee or login.";
        }
        if ($code !== null && Staff::where('employee_code', $code)->exists()) {
            return "Employee code {$code} is already used.";
        }

        return null;
    }
}
