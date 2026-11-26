<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Dates as people type them in spreadsheets: day-first (19.06.2025,
 * 17/12/22), ISO (2025-06-19) or text ("9th October 2026").
 */
class CsvDate
{
    /**
     * @return string|null Y-m-d, or null when the value is not a real date in the allowed years
     */
    public static function parse(string $value, int $minYear = 1950, ?int $maxYear = null): ?string
    {
        $value = trim($value);
        $maxYear ??= (int) now()->year + 1;

        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year < 100) {
                $year += $year <= ((int) now()->format('y') + 1) ? 2000 : 1900;
            }
        } elseif (preg_match('/[a-z]/i', $value) && ($time = strtotime(preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $value))) !== false) {
            [$year, $month, $day] = array_map('intval', explode('-', date('Y-m-d', $time)));
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year) || $year < $minYear || $year > $maxYear) {
            return null;
        }

        return Carbon::create($year, $month, $day)->toDateString();
    }
}
