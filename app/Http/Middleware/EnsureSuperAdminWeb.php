<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the web admin panel: only signed-in Super Admins get through.
 * Employees are sent to their portal; anyone else is signed out and sent
 * back to the login page.
 */
class EnsureSuperAdminWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Super Admin')) {
            // Employees who wander into /admin are taken to their own portal.
            if ($user && EnsureEmployeePortal::refusal($user) === null) {
                return redirect()->route('portal.dashboard')
                    ->with('error', 'The admin panel is only for Super Admins.');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Only Super Admin accounts can open the admin panel.']);
        }

        return $next($request);
    }
}
