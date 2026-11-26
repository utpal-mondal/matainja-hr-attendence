<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureEmployeePortal;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The one web sign-in page. Super Admins land in the admin panel, everyone
 * else in the employee portal (if they have an active employee profile).
 */
class LoginController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('web');
        $failed = fn (string $message) => back()->withInput($request->only('email', 'remember'))->withErrors(['email' => $message]);

        if (! $guard->validate($credentials)) {
            return $failed('These credentials do not match our records.');
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();
        $isSuperAdmin = $user->hasRole('Super Admin');

        if (! $isSuperAdmin && ($refusal = EnsureEmployeePortal::refusal($user))) {
            return $failed($refusal);
        }

        $guard->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        AuditLog::record($isSuperAdmin ? 'admin_panel.login' : 'portal.login', $user);

        // Go back to the page they were sent here from, but only inside their own area.
        $home = $isSuperAdmin ? route('admin.dashboard') : route('portal.dashboard');
        $area = url($isSuperAdmin ? '/admin' : '/portal');
        $intended = $request->session()->pull('url.intended');

        return redirect()->to($intended && str_starts_with($intended, $area) ? $intended : $home);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}
