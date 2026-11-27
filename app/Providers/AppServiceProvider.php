<?php

namespace App\Providers;

use App\Models\AttendanceCorrectionRequest;
use App\Models\CompanySetting;
use App\Models\LeaveRequest;
use App\Models\WfhRequest;
use App\Services\InvoiceService;
use App\Services\NotificationEvents;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Telescope is a dev-only dependency, so it is only registered locally.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // In-app + phone push notifications for leave, WFH, holidays, shifts...
        NotificationEvents::register();

        // Pending-approvals badge in the admin panel sidebar.
        View::composer('admin.layout', function ($view) {
            $view->with('pendingApprovals',
                LeaveRequest::where('status', 'pending')->count()
                + WfhRequest::where('status', 'pending')->count()
                + AttendanceCorrectionRequest::where('status', 'pending')->count());
        });

        // Organisation logo from Settings for the admin sidebar and sign-in page.
        View::composer(['admin.layout', 'portal.layout', 'auth.login'], function ($view) {
            $logoPath = CompanySetting::where('key', InvoiceService::SETTING_LOGO)->value('value');

            $view->with('brandLogoUrl', $logoPath && Storage::disk('local')->exists($logoPath)
                ? route('admin.logo', ['v' => substr(md5($logoPath), 0, 8)])
                : null);
        });
    }
}
