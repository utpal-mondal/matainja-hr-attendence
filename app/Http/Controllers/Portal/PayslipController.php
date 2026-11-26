<?php

namespace App\Http\Controllers\Portal;

use App\Models\Payslip;
use App\Services\PayslipExportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The employee's own payslips from locked or paid payroll runs.
 */
class PayslipController extends PortalController
{
    public function index(): View
    {
        return view('portal.payslips', [
            'payslips' => Payslip::released()->with('run')->where('payslips.staff_id', $this->staff()->id)->paginate(12),
        ]);
    }

    public function pdf(Request $request, Payslip $payslip, PayslipExportService $exports): Response
    {
        // Someone else's payslip, or one still in a draft run, does not exist as far as the employee is concerned.
        abort_unless($payslip->staff_id === $this->staff()->id && ! $payslip->run->isDraft(), 404);

        $pdf = $exports->pdf($payslip->run, new Collection([$payslip]));
        $name = $exports->pdfFilename($payslip->run, $payslip);

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }
}
