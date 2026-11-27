<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\Portal;
use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

/*
| One sign-in page for everyone: Super Admins go on to the admin panel,
| employees to the employee portal. Signed-in users are sent straight home.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
});

// Old sign-in addresses, kept so bookmarks still work.
Route::redirect('/admin/login', '/login');
Route::redirect('/portal/login', '/login');

/*
| Super Admin web panel (/admin). Session auth on the "web" guard; the
| mobile app keeps using the Sanctum token API under /api/v1.
*/
Route::prefix('admin')->name('admin.')->group(function () {
    // Organisation logo for the sidebar and the sign-in page (public: it's on every invoice).
    Route::get('/logo', [Admin\SettingController::class, 'logo'])->name('logo');

    Route::middleware(['auth', 'super.admin'])->group(function () {
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        // Employees
        Route::get('/employees', [Admin\StaffController::class, 'index'])->name('staff.index');
        Route::get('/employees/create', [Admin\StaffController::class, 'create'])->name('staff.create');
        Route::get('/employees/import', [Admin\StaffImportController::class, 'create'])->name('staff.import');
        Route::post('/employees/import', [Admin\StaffImportController::class, 'upload'])->name('staff.import.upload');
        Route::get('/employees/import/preview', [Admin\StaffImportController::class, 'preview'])->name('staff.import.preview');
        Route::post('/employees/import/confirm', [Admin\StaffImportController::class, 'store'])->name('staff.import.store');
        Route::get('/employees/import/result', [Admin\StaffImportController::class, 'result'])->name('staff.import.result');
        Route::get('/employees/import/logins.csv', [Admin\StaffImportController::class, 'logins'])->name('staff.import.logins');
        Route::get('/employees/import/template.csv', [Admin\StaffImportController::class, 'template'])->name('staff.import.template');
        Route::post('/employees', [Admin\StaffController::class, 'store'])->name('staff.store');
        Route::get('/employees/{staff}/edit', [Admin\StaffController::class, 'edit'])->name('staff.edit');
        Route::put('/employees/{staff}', [Admin\StaffController::class, 'update'])->name('staff.update');
        Route::post('/employees/{staff}/deactivate', [Admin\StaffController::class, 'deactivate'])->name('staff.deactivate');
        Route::post('/employees/{staff}/reactivate', [Admin\StaffController::class, 'reactivate'])->name('staff.reactivate');
        Route::delete('/employees/{staff}', [Admin\StaffController::class, 'destroy'])->name('staff.destroy');

        // Work from home added by an admin
        Route::post('/employees/{staff}/wfh', [Admin\StaffWfhController::class, 'store'])->name('staff.wfh.store');
        Route::post('/employees/{staff}/wfh/{wfhRequest}/cancel', [Admin\StaffWfhController::class, 'cancel'])
            ->scopeBindings()->name('staff.wfh.cancel');

        // Leave added by an admin (approved straight away)
        Route::post('/employees/{staff}/leave', [Admin\StaffLeaveController::class, 'store'])->name('staff.leave.store');
        Route::post('/employees/{staff}/leave/{leaveRequest}/cancel', [Admin\StaffLeaveController::class, 'cancel'])
            ->scopeBindings()->name('staff.leave.cancel');

        // Employee salary (payroll setup)
        Route::get('/employees/{staff}/salary', [Admin\StaffSalaryController::class, 'show'])->name('staff.salary');
        Route::put('/employees/{staff}/salary/profile', [Admin\StaffSalaryController::class, 'updateProfile'])->name('staff.salary.profile');
        Route::post('/employees/{staff}/salary/revisions', [Admin\StaffSalaryController::class, 'storeStructure'])->name('staff.salary.store');
        Route::delete('/employees/{staff}/salary/revisions/{salaryStructure}', [Admin\StaffSalaryController::class, 'destroyStructure'])
            ->scopeBindings()->name('staff.salary.destroy');

        // Users & roles
        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}/roles', [Admin\UserController::class, 'updateRoles'])->name('users.roles');
        Route::post('/users/{user}/password', [Admin\UserController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/sign-out', [Admin\UserController::class, 'revokeTokens'])->name('users.revoke');
        Route::get('/roles', [Admin\RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [Admin\RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [Admin\RoleController::class, 'update'])->name('roles.update');

        // Organisation setup (branches, departments, designations, teams, shifts, holidays, leave types)
        Route::post('/organisation/holidays/import', [Admin\HolidayImportController::class, 'store'])->name('holidays.import');
        Route::get('/organisation/holidays/import/template.csv', [Admin\HolidayImportController::class, 'template'])->name('holidays.import.template');
        Route::get('/organisation/{type}', [Admin\OrgController::class, 'index'])->name('org.index');
        Route::post('/organisation/{type}', [Admin\OrgController::class, 'store'])->name('org.store');
        Route::get('/organisation/{type}/{id}/edit', [Admin\OrgController::class, 'edit'])->name('org.edit');
        Route::put('/organisation/{type}/{id}', [Admin\OrgController::class, 'update'])->name('org.update');
        Route::delete('/organisation/{type}/{id}', [Admin\OrgController::class, 'destroy'])->name('org.destroy');

        // Invoices
        Route::get('/invoices', [Admin\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [Admin\InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [Admin\InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [Admin\InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [Admin\InvoiceController::class, 'pdf'])->name('invoices.pdf');
        // Old browser-print link, kept so bookmarks still work.
        Route::get('/invoices/{invoice}/print', fn (Invoice $invoice) => redirect()->route('admin.invoices.pdf', ['invoice' => $invoice, 'inline' => 1]));
        Route::get('/invoices/{invoice}/edit', [Admin\InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [Admin\InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('/invoices/{invoice}/status', [Admin\InvoiceController::class, 'status'])->name('invoices.status');
        Route::delete('/invoices/{invoice}', [Admin\InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Payroll
        Route::get('/payroll', [Admin\PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [Admin\PayrollController::class, 'store'])->name('payroll.store');
        Route::get('/payroll/{payrollRun}', [Admin\PayrollController::class, 'show'])->name('payroll.show');
        Route::post('/payroll/{payrollRun}/recalculate', [Admin\PayrollController::class, 'recalculate'])->name('payroll.recalculate');
        Route::post('/payroll/{payrollRun}/lock', [Admin\PayrollController::class, 'lock'])->name('payroll.lock');
        Route::post('/payroll/{payrollRun}/reopen', [Admin\PayrollController::class, 'reopen'])->name('payroll.reopen');
        Route::post('/payroll/{payrollRun}/paid', [Admin\PayrollController::class, 'markPaid'])->name('payroll.paid');
        Route::delete('/payroll/{payrollRun}', [Admin\PayrollController::class, 'destroy'])->name('payroll.destroy');
        Route::get('/payroll/{payrollRun}/payslips.pdf', [Admin\PayrollController::class, 'pdf'])->name('payroll.pdf');
        Route::get('/payroll/{payrollRun}/bank-transfer.csv', [Admin\PayrollController::class, 'bankTransfer'])->name('payroll.bank');
        Route::get('/payroll/{payrollRun}/register.csv', [Admin\PayrollController::class, 'register'])->name('payroll.register');
        Route::get('/payroll/{payrollRun}/ecr.txt', [Admin\PayrollController::class, 'ecr'])->name('payroll.ecr');
        Route::scopeBindings()->group(function () {
            Route::get('/payroll/{payrollRun}/payslips/{payslip}', [Admin\PayslipController::class, 'show'])->name('payroll.payslips.show');
            Route::get('/payroll/{payrollRun}/payslips/{payslip}/pdf', [Admin\PayslipController::class, 'pdf'])->name('payroll.payslips.pdf');
            Route::put('/payroll/{payrollRun}/payslips/{payslip}/lop', [Admin\PayslipController::class, 'updateLop'])->name('payroll.payslips.lop');
            Route::post('/payroll/{payrollRun}/payslips/{payslip}/adjustments', [Admin\PayslipController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
            Route::delete('/payroll/{payrollRun}/adjustments/{adjustment}', [Admin\PayslipController::class, 'destroyAdjustment'])->name('payroll.adjustments.destroy');
        });

        // Attendance & approvals
        Route::get('/attendance', [Admin\AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('/attendance/export', [Admin\AttendanceController::class, 'export'])->name('attendance.export');
        Route::post('/attendance/{staff}/mark', [Admin\AttendanceController::class, 'mark'])->name('attendance.mark');
        Route::get('/approvals', [Admin\ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{kind}/{id}/{decision}', [Admin\ApprovalController::class, 'decide'])
            ->whereIn('kind', ['leave', 'wfh', 'correction'])
            ->whereIn('decision', ['approve', 'reject'])
            ->name('approvals.decide');

        // Settings & audit log
        Route::get('/settings', [Admin\SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [Admin\SettingController::class, 'update'])->name('settings.update');
        Route::get('/audit-log', [Admin\AuditLogController::class, 'index'])->name('audit.index');
    });
});

/*
| Employee web portal (/portal). Same "web" session guard and the same
| password as the mobile app, for any active employee. It is view-and-request
| only: check-in and check-out stay in the mobile app.
*/
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware(['auth', 'employee.portal'])->group(function () {
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        Route::get('/', [Portal\DashboardController::class, 'index'])->name('dashboard');

        Route::get('/profile', [Portal\ProfileController::class, 'show'])->name('profile');
        Route::put('/profile', [Portal\ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [Portal\ProfileController::class, 'updatePassword'])->name('profile.password');

        Route::get('/attendance', [Portal\AttendanceController::class, 'index'])->name('attendance');

        Route::get('/leave', [Portal\LeaveController::class, 'index'])->name('leave.index');
        Route::post('/leave', [Portal\LeaveController::class, 'store'])->name('leave.store');
        Route::post('/leave/{leaveRequest}/withdraw', [Portal\LeaveController::class, 'withdraw'])->name('leave.withdraw');
        Route::post('/leave/{leaveRequest}/cancel', [Portal\LeaveController::class, 'cancel'])->name('leave.cancel');

        Route::get('/wfh', [Portal\WfhController::class, 'index'])->name('wfh.index');
        Route::post('/wfh', [Portal\WfhController::class, 'store'])->name('wfh.store');

        Route::get('/regularization', [Portal\RegularizationController::class, 'index'])->name('regularization.index');
        Route::post('/regularization', [Portal\RegularizationController::class, 'store'])->name('regularization.store');

        Route::get('/payslips', [Portal\PayslipController::class, 'index'])->name('payslips.index');
        Route::get('/payslips/{payslip}/pdf', [Portal\PayslipController::class, 'pdf'])->name('payslips.pdf');

        Route::get('/holidays', [Portal\HolidayController::class, 'index'])->name('holidays');
    });
});
