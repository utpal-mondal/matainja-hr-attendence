<?php

namespace App\Http\Controllers\Portal;

use App\Models\AuditLog;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The employee's own details. Everything is managed by HR except the phone
 * number and the password.
 */
class ProfileController extends PortalController
{
    public function show(Request $request, AttendanceService $attendance): View
    {
        $staff = $this->staff()->load(['department', 'designation', 'branch', 'team', 'manager']);

        return view('portal.profile', [
            'staff' => $staff,
            'user' => $request->user(),
            'roles' => $request->user()->roles()->pluck('name'),
            'shift' => $attendance->currentShiftAssignment($staff, $this->today()->toDateString())?->shift,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('profile', [
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
        ], ['phone.regex' => 'The phone number may only contain digits, spaces and + ( ) -.']);

        $staff = $this->staff();
        $before = $staff->only('phone');
        $staff->update(['phone' => $data['phone'] ?? null]);
        AuditLog::record('portal.profile_updated', $staff, $before, $staff->only('phone'));

        return redirect()->route('portal.profile')->with('success', 'Your phone number has been updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['current_password.current_password' => 'The current password is incorrect.']);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        // Same as changing it in the app: other sign-ins (the mobile app) have to sign in again.
        $user->tokens()->delete();
        $user->staff?->update(['initial_password' => null]);
        AuditLog::record('portal.password_changed', $user);

        return redirect()->route('portal.profile')->with('success', 'Password changed. Use the new password next time you sign in, including in the mobile app.');
    }
}
