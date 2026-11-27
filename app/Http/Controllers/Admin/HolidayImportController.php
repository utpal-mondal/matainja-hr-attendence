<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Services\HolidayImportService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk-add holidays from a CSV on the Organisation → Holidays screen.
 */
class HolidayImportController extends AdminController
{
    public function store(Request $request, HolidayImportService $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ], ['file.mimes' => 'Upload a .csv file (in Excel: File → Save As → CSV).']);

        // One summary notification instead of one per imported row.
        $result = NotificationService::muted(fn () => $importer->import($request->file('file')->getRealPath()));
        if ($result['error']) {
            return back()->with('error', $result['error']);
        }
        if ($result['created'] > 0) {
            $notifications = app(NotificationService::class);
            $notifications->notify($notifications->everyone(), 'holiday', 'Holiday list updated',
                $result['created'] === 1 ? '1 new holiday was added to the calendar.' : "{$result['created']} new holidays were added to the calendar.");
        }

        AuditLog::record('holidays.imported', null, null, [
            'file' => $request->file('file')->getClientOriginalName(),
            'created' => $result['created'],
            'skipped' => count($result['skipped']),
        ]);

        $skipped = count($result['skipped']);
        $message = $result['created'] === 1 ? '1 holiday added.' : "{$result['created']} holidays added.";
        if ($skipped) {
            $message .= " {$skipped} row".($skipped === 1 ? ' was' : 's were').' skipped (see below).';
        }

        return redirect()->route('admin.org.index', 'holidays')
            ->with($result['created'] > 0 ? 'success' : 'error', $message)
            ->with('holiday_import_skipped', $result['skipped']);
    }

    public function template(): StreamedResponse
    {
        $year = (int) now()->year + 1;

        return response()->streamDownload(function () use ($year) {
            $out = fopen('php://output', 'w');
            fputcsv($out, HolidayImportService::TEMPLATE_HEADERS);
            fputcsv($out, ["01/01/{$year}", "New Year's Day", 'Company', '']);
            fputcsv($out, ["26/01/{$year}", 'Republic Day', 'Public', '']);
            fputcsv($out, ["15/08/{$year}", 'Independence Day', 'Public', '']);
            fclose($out);
        }, 'holiday-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
