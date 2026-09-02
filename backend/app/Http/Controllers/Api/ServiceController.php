<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    /**
     * List active services with optional category filter & caching
     */
    public function index(Request $request)
    {
        $category = $request->query('category', 'all');
        $cacheKey = 'active_services_' . $category;

        $services = Cache::remember($cacheKey, 300, function () use ($category) {
            $query = Service::where('is_active', true);

            if ($category !== 'all') {
                $query->where('category', $category);
            }

            return $query->orderBy('id', 'asc')->get()->toArray();
        });

        return response()->json([
            'status' => 'success',
            'data' => $services,
        ]);
    }

    /**
     * Get single service detail
     */
    public function show($id)
    {
        $service = Cache::remember("service_detail_{$id}", 300, function () use ($id) {
            $s = Service::where('is_active', true)->find($id);
            return $s ? $s->toArray() : null;
        });

        if (!$service) {
            return response()->json([
                'status' => 'error',
                'message' => 'Service not found or inactive.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $service,
        ]);
    }
}
