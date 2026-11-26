<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the employee web portal: the signed-in user must be linked to an
 * employee profile that is still active and has web login switched on (the
 * "Allow web login" box on the employee page). Anyone else is signed out and
 * sent back to the login page.
 */
class EnsureEmployeePortal
{
    /** Employment statuses that lose access to the portal (same as the admin "deactivate"). */
    public const BLOCKED_STATUSES = ['resigned', 'terminated', 'suspended'];

    public function handle(Request $request, Closure $next): Response
    {
        $refusal = self::refusal($request->user());

        if ($refusal !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $refusal]);
        }

        return $next($request);
    }

    /** Why this user may not use the portal, or null when they may. */
    public static function refusal(?User $user): ?string
    {
        $staff = $user?->staff;

        if (! $staff) {
            return 'This account is not linked to an employee profile. Please contact HR.';
        }

        if (in_array($staff->employment_status, self::BLOCKED_STATUSES, true)) {
            return 'Your employee account is not active. Please contact HR.';
        }

        if (! $staff->web_login_enabled) {
            return "You don't have web permission. Please contact HR.";
        }

        return null;
    }
}
