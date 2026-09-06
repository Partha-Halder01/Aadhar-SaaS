<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LandingPageService;
use Illuminate\Http\JsonResponse;

class LandingPageController extends Controller
{
    protected LandingPageService $service;

    public function __construct(LandingPageService $service)
    {
        $this->service = $service;
    }

    /**
     * Get public landing page configuration
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->service->getConfig(),
        ]);
    }
}
