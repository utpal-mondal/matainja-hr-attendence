<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Holidays for the employee's branch (plus company-wide ones) in a year.
 */
class HolidayController extends PortalController
{
    public function index(Request $request): View
    {
        $currentYear = $this->today()->year;
        $year = (int) $request->query('year', $currentYear);
        $year = $year >= 2000 && $year <= $currentYear + 1 ? $year : $currentYear;

        return view('portal.holidays', [
            'year' => $year,
            'currentYear' => $currentYear,
            'today' => $this->today(),
            'holidays' => $this->holidaysInYear($year),
        ]);
    }
}
