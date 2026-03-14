<?php

namespace App\Http\Controllers;

use App\Services\AiAnalyticsService;
use Inertia\Inertia;

class AiAnalyticsController extends Controller
{
    public function __construct(
        protected AiAnalyticsService $analytics
    ) {}

    public function index()
    {
        return Inertia::render('AiAnalytics/Dashboard', [
            'analytics' => $this->analytics->dashboard()
        ]);
    }
}
