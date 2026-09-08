<?php

namespace App\Http\Controllers\Api\Platform\Analytics;

use App\Domain\Analytics\Services\AnalyticsReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private readonly AnalyticsReconciliationService $reconciliation) {}

    public function index(Request $request)
    {
        $request->validate(['tenant_id' => 'required|uuid', 'business_date' => 'required|date']);

        return $this->ok($this->reconciliation->reconcile($request->input('tenant_id'), $request->input('business_date')));
    }
}
