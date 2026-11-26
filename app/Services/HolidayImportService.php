<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Holiday;
use App\Support\CsvDate;
use Illuminate\Support\Facades\DB;
use SplFileObject;

/**
 * Bulk-add holidays from a CSV with Date, Name, Type and Branch columns.
 * Good rows are added; the rest are reported back with the reason.
 */
class HolidayImportService
{
    public const TEMPLATE_HEADERS = ['Date', 'Name', 'Type', 'Branch'];

    public const MAX_ROWS = 500;

    /** Header spellings people use, after lower-casing and removing spaces/punctuation. */
    private const HEADER_ALIASES = [
        'holiday_date' => ['date', 'holidaydate', 'day', 'on'],
        'name' => ['name', 'holiday', 'holidayname', 'occasion', 'festival', 'description', 'title'],
        'type' => ['type', 'holidaytype', 'category', 'kind'],
        'branch' => ['branch', 'branchname', 'branchcode', 'office', 'location'],
    ];

    /** What people write in the Type column => the stored type. Blank means public. */
    private const TYPE_ALIASES = [
        'public' => 'public', 'national' => 'public', 'gazetted' => 'public', 'govt' => 'public', 'government' => 'public', 'mandatory' => 'public',
        'optional' => 'optional', 'restricted' => 'optional', 'rh' => 'optional', 'floating' => 'optional',
        'company' => 'company', 'office' => 'company', 'organisation' => 'company', 'organization' => 'company',
    ];

    /** Branch values that mean "every branch". */
    private const ALL_BRANCHES = ['', 'all', 'allbranches', 'everyone', 'company', 'companywide', 'na', '-'];

    /**
     * @return array{error: ?string, created: int, skipped: list<array{line: int, name: string, reason: string}>}
     */
    public function import(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $columns = null;
        $raw = [];
        foreach ($file as $index => $cells) {
            if (! is_array($cells) || $cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue;
            }
            if ($columns === null) {
                $columns = $this->mapHeaders($cells);
                if (! isset($columns['holiday_date'], $columns['name'])) {
                    return $this->failed('The file needs at least a "Date" and a "Name" column. Download the template to see the expected columns.');
                }

                continue;
            }

            $values = [];
            foreach ($columns as $field => $position) {
                $values[$field] = trim((string) ($cells[$position] ?? ''));
            }
            $raw[] = ['line' => $index + 1, 'values' => $values];
        }

        if ($raw === []) {
            return $this->failed('The file has no holiday rows.');
        }
        if (count($raw) > self::MAX_ROWS) {
            return $this->failed('Import up to '.self::MAX_ROWS.' holidays at a time; split the file.');
        }

        return DB::transaction(fn () => $this->createRows($raw));
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $raw
     * @return array{error: null, created: int, skipped: list<array{line: int, name: string, reason: string}>}
     */
    private function createRows(array $raw): array
    {
        $branches = [];
        foreach (Branch::get(['id', 'name', 'code']) as $branch) {
            $branches[$this->key($branch->name)] = $branch->id;
            if ($branch->code) {
                $branches[$this->key($branch->code)] = $branch->id;
            }
        }
        // date|branch id ('' = all branches) of holidays already listed, plus the ones this file adds.
        $taken = Holiday::get(['holiday_date', 'branch_id'])
            ->mapWithKeys(fn (Holiday $h) => [$h->holiday_date->toDateString().'|'.$h->branch_id => true])->all();

        $created = 0;
        $skipped = [];
        $year = (int) now()->year;
        foreach ($raw as ['line' => $line, 'values' => $v]) {
            $name = preg_replace('/\s+/', ' ', $v['name'] ?? '');
            $skip = function (string $reason) use (&$skipped, $line, $name) {
                $skipped[] = ['line' => $line, 'name' => $name !== '' ? $name : '(no name)', 'reason' => $reason];
            };

            $date = CsvDate::parse($v['holiday_date'] ?? '', $year - 5, $year + 5);
            $type = self::TYPE_ALIASES[$this->key($v['type'] ?? '')] ?? (($v['type'] ?? '') === '' ? 'public' : null);
            $branchKey = $this->key($v['branch'] ?? '');
            $branchId = in_array($branchKey, self::ALL_BRANCHES, true) ? null : ($branches[$branchKey] ?? false);

            $reason = match (true) {
                $name === '' => 'The name is missing.',
                mb_strlen($name) > 255 => 'The name is longer than 255 characters.',
                $date === null => ($v['holiday_date'] ?? '') === ''
                    ? 'The date is missing.'
                    : "\"{$v['holiday_date']}\" is not a date between ".($year - 5).' and '.($year + 5).' (use e.g. 26/01/'.($year + 1).').',
                $type === null => "Type \"{$v['type']}\" is not Public, Optional or Company.",
                $branchId === false => "There is no branch called \"{$v['branch']}\". Use a branch name or code, or leave it empty for all branches.",
                isset($taken[$date.'|'.$branchId]) => 'A holiday is already listed on '.date('d M Y', strtotime($date)).($branchId ? ' for this branch.' : ' for all branches.'),
                default => null,
            };
            if ($reason !== null) {
                $skip($reason);

                continue;
            }

            Holiday::create(['holiday_date' => $date, 'name' => $name, 'type' => $type, 'branch_id' => $branchId]);
            $taken[$date.'|'.$branchId] = true;
            $created++;
        }

        return ['error' => null, 'created' => $created, 'skipped' => $skipped];
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
            $key = $this->key(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header));
            if (isset($lookup[$key]) && ! isset($columns[$lookup[$key]])) {
                $columns[$lookup[$key]] = $position;
            }
        }

        return $columns;
    }

    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }

    /** @return array{error: string, created: int, skipped: list<array{line: int, name: string, reason: string}>} */
    private function failed(string $message): array
    {
        return ['error' => $message, 'created' => 0, 'skipped' => []];
    }
}
