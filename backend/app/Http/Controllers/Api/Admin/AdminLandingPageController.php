<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\LandingPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLandingPageController extends Controller
{
    protected LandingPageService $service;

    public function __construct(LandingPageService $service)
    {
        $this->service = $service;
    }

    /**
     * Get current landing page configuration for admin CMS
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->service->getConfig(),
        ]);
    }

    /**
     * Update landing page configuration
     */
    public function update(Request $request): JsonResponse
    {
        $data = $this->sanitizeLinks($request->all());
        $saved = $this->service->saveConfig($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Landing page content updated successfully.',
            'data' => $saved,
        ]);
    }

    /**
     * Replace unsafe values (e.g. javascript:) in any *_link / *_url field of the CMS payload
     */
    private function sanitizeLinks(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeLinks($value);
            } elseif (is_string($key) && is_string($value) && preg_match('/(_link|_url)$/', $key)) {
                $value = trim($value);
                $data[$key] = preg_match('~^(https?://|tel:|mailto:|#|/(?!/)|[A-Za-z0-9_./-]+\.html(\#.*)?$)~i', $value) ? $value : '#';
            }
        }

        return $data;
    }

    /**
     * Reset landing page configuration to system defaults
     */
    public function reset(): JsonResponse
    {
        $defaults = $this->service->resetConfig();

        return response()->json([
            'status' => 'success',
            'message' => 'Landing page content reset to defaults.',
            'data' => $defaults,
        ]);
    }
}
