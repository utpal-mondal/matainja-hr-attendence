<?php

use App\Http\Middleware\EnsureEmployeePortal;
use App\Http\Middleware\EnsureSuperAdminWeb;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'super.admin' => EnsureSuperAdminWeb::class,
            'employee.portal' => EnsureEmployeePortal::class,
        ]);

        // Web admin panel and employee portal share one login page; signed-in
        // users hitting it go to their home. API requests keep getting JSON
        // 401s (see shouldRenderJsonWhen below).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();
            $wantsPortal = $request->is('portal', 'portal/*') && $user?->staff;

            return $wantsPortal || ! $user?->hasRole('Super Admin') ? route('portal.dashboard') : route('admin.dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
