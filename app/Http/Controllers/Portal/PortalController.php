<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Staff;
use App\Support\ReferenceCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/**
 * Base for the employee web portal. Every page works on the signed-in
 * employee's own records only (EnsureEmployeePortal guarantees there is one).
 */
abstract class PortalController extends Controller
{
    protected function staff(): Staff
    {
        return request()->user()->staff;
    }

    /** The employee's office timezone, used for "today" and for showing punch times. */
    protected function tz(): string
    {
        return once(fn () => $this->staff()->branch?->timezone
            ?: Branch::query()->whereNotNull('timezone')->where('timezone', '!=', '')->orderBy('id')->value('timezone')
            ?: config('app.timezone'));
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tz())->startOfDay();
    }

    /**
     * Holidays that apply to this employee in one year: company-wide ones and
     * their branch's, in date order. Cached per year and branch; any holiday
     * save or delete clears it (see FlushesReferenceCache).
     *
     * @return Collection<int, Holiday>
     */
    protected function holidaysInYear(int $year): Collection
    {
        $branchId = $this->staff()->branch_id;

        return collect(ReferenceCache::get('holidays', fn () => Holiday::query()
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get(), "portal:y{$year}:b".($branchId ?? 'none')));
    }

    /**
     * The employee's holidays from $from to $to (both inclusive), in date order.
     *
     * @return Collection<int, Holiday>
     */
    protected function holidaysBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        [$first, $last] = [$from->toDateString(), $to->toDateString()];

        return collect(range($from->year, $to->year))
            ->flatMap(fn (int $year) => $this->holidaysInYear($year))
            ->filter(fn (Holiday $h) => $h->holiday_date->toDateString() >= $first && $h->holiday_date->toDateString() <= $last)
            ->values();
    }

    /**
     * Turn a JSON response from one of the API controllers (reused so the
     * portal follows exactly the same rules as the mobile app) into a redirect
     * with a flash message. Failures keep the form input.
     */
    protected function fromApi(JsonResponse $response, string $successMessage, string $redirectTo): RedirectResponse
    {
        $data = $response->getData(true);

        if (! $response->isSuccessful() || ! ($data['success'] ?? true)) {
            return back()->withInput()->with('error', $data['message'] ?? 'Something went wrong. Please try again.');
        }

        return redirect()->to($redirectTo)->with('success', $successMessage);
    }
}
