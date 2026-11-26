<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

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
     * Holidays that apply to this employee: company-wide ones and their branch's.
     *
     * @return Builder<Holiday>
     */
    protected function holidays(): Builder
    {
        $branchId = $this->staff()->branch_id;

        return Holiday::query()
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderBy('holiday_date');
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
