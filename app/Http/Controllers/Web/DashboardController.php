<?php

namespace App\Http\Controllers\Web;

use App\Models\ProductionBatch;
use App\Services\DashboardWebService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardWebService $dashboard,
    ) {}

    public function index(): View
    {
        $this->authorizePermission('dashboard.view');

        $data = $this->dashboard->build();

        $recentBatches = ProductionBatch::query()
            ->latest()
            ->limit(8)
            ->get();

        return view('dashboard.index', [
            ...$data,
            'recentBatches' => $recentBatches,
        ]);
    }
}
