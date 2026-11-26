<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BreakController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ShiftAssignmentController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\TaxController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WfhController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public
    Route::get('/health', HealthController::class)->name('health');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('auth.login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1')->name('auth.forgot');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1')->name('auth.reset');

    Route::middleware('auth:sanctum')->group(function () {

        // Auth & account
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logoutAll');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->name('auth.changePassword');
        Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
        Route::get('/me/permissions', [AuthController::class, 'permissions'])->name('me.permissions');
        Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

        // Staff & organisation
        Route::apiResource('staff', StaffController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('/staff/{staff}/documents', [StaffController::class, 'documents'])->name('staff.documents');
        Route::get('/staff/{staff}/assets', [AssetController::class, 'staffAssets'])->name('staff.assets');
        Route::get('/staff/{staff}/shift-assignments', [ShiftAssignmentController::class, 'index'])->name('staff.shiftAssignments.index');
        Route::post('/staff/{staff}/shift-assignments', [ShiftAssignmentController::class, 'store'])->name('staff.shiftAssignments.store');
        Route::delete('/shift-assignments/{assignment}', [ShiftAssignmentController::class, 'destroy'])->name('shiftAssignments.destroy');

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])->middleware('role:Super Admin')->name('branches.store');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('role:Super Admin')->name('branches.update');

        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->middleware('role:Super Admin,HR Admin')->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('role:Super Admin,HR Admin')->name('departments.update');

        Route::get('/designations', [DesignationController::class, 'index'])->name('designations.index');
        Route::post('/designations', [DesignationController::class, 'store'])->middleware('role:Super Admin,HR Admin')->name('designations.store');

        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'store'])->middleware('role:Super Admin,HR Admin')->name('teams.store');

        // Attendance & breaks
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.checkIn');
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.checkOut');
        Route::get('/attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
        Route::get('/attendance/team/today', [AttendanceController::class, 'teamToday'])->middleware('role:Manager,HR Admin,Super Admin')->name('attendance.teamToday');
        Route::get('/attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
        Route::get('/attendance/corrections', [AttendanceCorrectionController::class, 'index'])->name('attendance.corrections.index');
        Route::post('/attendance/corrections', [AttendanceCorrectionController::class, 'store'])->name('attendance.corrections.store');
        Route::post('/attendance/corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->middleware('role:Manager,HR Admin,Super Admin')->name('attendance.corrections.approve');
        Route::post('/attendance/corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->middleware('role:Manager,HR Admin,Super Admin')->name('attendance.corrections.reject');
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('/attendance/{attendance}', [AttendanceController::class, 'show'])->name('attendance.show');
        Route::get('/attendance/{attendance}/breaks', [BreakController::class, 'index'])->name('breaks.index');
        Route::post('/attendance/{attendance}/breaks/start', [BreakController::class, 'start'])->name('breaks.start');
        Route::post('/attendance/{attendance}/breaks/end', [BreakController::class, 'end'])->name('breaks.end');

        // Shifts, schedules & holidays
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('/shifts', [ShiftController::class, 'store'])->middleware('role:Super Admin,HR Admin')->name('shifts.store');
        Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
        Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->middleware('role:Super Admin,HR Admin')->name('shifts.update');
        Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('/holidays', [HolidayController::class, 'store'])->middleware('role:Super Admin,HR Admin')->name('holidays.store');
        Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])->middleware('role:Super Admin,HR Admin')->name('holidays.update');
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('role:Super Admin,HR Admin')->name('holidays.destroy');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

        // Leave & WFH
        Route::get('/leave/types', [LeaveController::class, 'types'])->name('leave.types');
        Route::get('/leave/balances', [LeaveController::class, 'balances'])->name('leave.balances');
        Route::get('/leave/approvals/pending', [LeaveController::class, 'pendingApprovals'])->middleware('role:Manager,HR Admin,Super Admin')->name('leave.approvals.pending');
        Route::get('/leave/requests', [LeaveController::class, 'index'])->name('leave.requests.index');
        Route::post('/leave/requests', [LeaveController::class, 'store'])->name('leave.requests.store');
        Route::get('/leave/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('leave.requests.show');
        Route::post('/leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.requests.cancel');
        Route::post('/leave/requests/{leaveRequest}/withdraw', [LeaveController::class, 'withdraw'])->name('leave.requests.withdraw');
        Route::post('/leave/requests/{leaveRequest}/approve', [LeaveController::class, 'approve'])->middleware('role:Manager,HR Admin,Super Admin')->name('leave.requests.approve');
        Route::post('/leave/requests/{leaveRequest}/reject', [LeaveController::class, 'reject'])->middleware('role:Manager,HR Admin,Super Admin')->name('leave.requests.reject');

        Route::get('/wfh/requests', [WfhController::class, 'index'])->name('wfh.index');
        Route::post('/wfh/requests', [WfhController::class, 'store'])->name('wfh.store');
        Route::post('/wfh/requests/{wfhRequest}/approve', [WfhController::class, 'approve'])->middleware('role:Manager,HR Admin,Super Admin')->name('wfh.approve');
        Route::post('/wfh/requests/{wfhRequest}/reject', [WfhController::class, 'reject'])->middleware('role:Manager,HR Admin,Super Admin')->name('wfh.reject');

        // Dashboards & reports
        Route::get('/dashboard/employee', [DashboardController::class, 'employee'])->name('dashboard.employee');
        Route::get('/dashboard/manager', [DashboardController::class, 'manager'])->middleware('role:Manager,HR Admin,Super Admin')->name('dashboard.manager');
        Route::get('/dashboard/hr', [DashboardController::class, 'hr'])->middleware('role:HR Admin,Super Admin')->name('dashboard.hr');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/attendance', [ReportController::class, 'attendance'])->name('attendance');
            Route::get('/late-arrivals', [ReportController::class, 'lateArrivals'])->name('lateArrivals');
            Route::get('/absences', [ReportController::class, 'absences'])->name('absences');
            Route::get('/overtime', [ReportController::class, 'overtime'])->name('overtime');
            Route::get('/leave', [ReportController::class, 'leave'])->name('leave');
            Route::get('/headcount', [ReportController::class, 'headcount'])->middleware('role:HR Admin,Super Admin')->name('headcount');
            Route::post('/exports', [ReportController::class, 'storeExport'])->name('exports.store');
            Route::get('/exports/{export}', [ReportController::class, 'showExport'])->name('exports.show');
            Route::get('/exports/{export}/download', [ReportController::class, 'downloadExport'])->name('exports.download');
        });

        // Announcements & notifications
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('role:HR Admin,Super Admin')->name('announcements.store');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::match(['put', 'patch'], '/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->middleware('role:HR Admin,Super Admin')->name('announcements.publish');
        Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'markRead'])->name('announcements.read');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::get('/notification-preferences', [NotificationController::class, 'getPreferences'])->name('notificationPreferences.get');
        Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notificationPreferences.update');

        // Documents
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        // Expenses
        Route::get('/expense/categories', [ExpenseController::class, 'categories'])->name('expenses.categories');
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::post('/expenses/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
        Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('role:Manager,HR Admin,Super Admin')->name('expenses.approve');
        Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('role:Manager,HR Admin,Super Admin')->name('expenses.reject');
        Route::post('/expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->middleware('role:HR Admin,Super Admin')->name('expenses.markPaid');

        // Meetings
        Route::get('/meeting-rooms', [MeetingController::class, 'rooms'])->name('meetingRooms.index');
        Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
        Route::get('/meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
        Route::patch('/meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');
        Route::post('/meetings/{meeting}/cancel', [MeetingController::class, 'cancel'])->name('meetings.cancel');
        Route::post('/meetings/{meeting}/response', [MeetingController::class, 'respond'])->name('meetings.response');

        // Assets (managed by Super Admin, HR Admin and Managers)
        Route::middleware('role:Manager,HR Admin,Super Admin')->group(function () {
            Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
            Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
            Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
            Route::patch('/assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
            Route::post('/assets/{asset}/image', [AssetController::class, 'uploadImage'])->name('assets.image.upload');
            Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
            Route::post('/assets/{asset}/return', [AssetController::class, 'returnAsset'])->name('assets.return');
        });
        // Also readable by the employee the asset is assigned to.
        Route::get('/assets/{asset}/image', [AssetController::class, 'image'])->name('assets.image');

        // Clients
        Route::get('/clients', [ClientController::class, 'index'])->middleware('role:Manager,HR Admin,Super Admin')->name('clients.index');
        Route::post('/clients', [ClientController::class, 'store'])->middleware('role:HR Admin,Super Admin')->name('clients.store');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('role:Manager,HR Admin,Super Admin')->name('clients.show');
        Route::match(['put', 'patch'], '/clients/{client}', [ClientController::class, 'update'])->middleware('role:HR Admin,Super Admin')->name('clients.update');
        Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('role:HR Admin,Super Admin')->name('clients.destroy');

        // Tax rates that can be applied to invoices
        Route::get('/taxes', [TaxController::class, 'index'])->middleware('role:Manager,HR Admin,Super Admin')->name('taxes.index');
        Route::post('/taxes', [TaxController::class, 'store'])->middleware('role:HR Admin,Super Admin')->name('taxes.store');
        Route::get('/taxes/{tax}', [TaxController::class, 'show'])->middleware('role:Manager,HR Admin,Super Admin')->name('taxes.show');
        Route::match(['put', 'patch'], '/taxes/{tax}', [TaxController::class, 'update'])->middleware('role:HR Admin,Super Admin')->name('taxes.update');
        Route::delete('/taxes/{tax}', [TaxController::class, 'destroy'])->middleware('role:HR Admin,Super Admin')->name('taxes.destroy');

        // Invoices (billed to clients)
        Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('role:Manager,HR Admin,Super Admin')->name('invoices.index');
        Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('role:HR Admin,Super Admin')->name('invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('role:Manager,HR Admin,Super Admin')->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('role:Manager,HR Admin,Super Admin')->name('invoices.pdf');
        Route::match(['put', 'patch'], '/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('role:HR Admin,Super Admin')->name('invoices.update');
        Route::post('/invoices/{invoice}/status', [InvoiceController::class, 'status'])->middleware('role:HR Admin,Super Admin')->name('invoices.status');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('role:HR Admin,Super Admin')->name('invoices.destroy');

        // Helpdesk
        Route::get('/ticket-categories', [TicketController::class, 'categories'])->name('tickets.categories');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/comments', [TicketController::class, 'comment'])->name('tickets.comment');
        Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('role:HR Admin,Super Admin')->name('tickets.assign');
        Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->middleware('role:HR Admin,Super Admin')->name('tickets.status');
        Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

        // Administration
        Route::prefix('admin')->name('admin.')->middleware('role:Super Admin')->group(function () {
            Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::post('/users/{user}/roles', [Admin\UserController::class, 'assignRoles'])->name('users.roles');
            Route::get('/roles', [Admin\RoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [Admin\RoleController::class, 'store'])->name('roles.store');
            Route::put('/roles/{role}', [Admin\RoleController::class, 'update'])->name('roles.update');
            Route::get('/settings', [Admin\SettingController::class, 'index'])->name('settings.index');
            Route::put('/settings', [Admin\SettingController::class, 'update'])->name('settings.update');
            Route::get('/audit-logs', [Admin\AuditLogController::class, 'index'])->name('auditLogs.index');
        });
    });
});
