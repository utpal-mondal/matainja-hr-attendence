<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Api\V1\WfhController as ApiWfhController;
use App\Models\WfhRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Work-from-home requests. Submitting goes through the API controller so the
 * rules match the mobile app.
 */
class WfhController extends PortalController
{
    public function index(): View
    {
        return view('portal.wfh', [
            'today' => $this->today(),
            'requests' => WfhRequest::with('approver:id,name')->where('staff_id', $this->staff()->id)->latest()->paginate(15),
        ]);
    }

    public function store(Request $request, ApiWfhController $api): RedirectResponse
    {
        return $this->fromApi($api->store($request), 'Work-from-home request sent for approval.', route('portal.wfh.index'));
    }
}
