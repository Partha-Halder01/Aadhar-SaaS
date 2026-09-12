<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    /**
     * List active service categories with service count
     */
    public function categories(Request $request)
    {
        $categories = Cache::remember('active_service_categories', 120, function () {
            return ServiceCategory::where('is_active', true)
                ->withCount(['services' => function ($q) {
                    $q->where('is_active', true);
                }])
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->toArray();
        });

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    /**
     * List active services with optional category filter & caching
     */
    public function index(Request $request)
    {
        $category = $request->query('category', 'all');

        $query = Service::where('is_active', true);

        if ($category !== 'all' && !empty($category)) {
            if ($category === 'pan' || $category === 'pan_find') {
                $query->whereIn('category', ['pan', 'pan_find']);
            } elseif ($category === 'print' || $category === 'print_doc') {
                $query->whereIn('category', ['print', 'print_doc']);
            } else {
                $query->where('category', $category);
            }
        }

        $services = $query->orderBy('id', 'asc')->get()->toArray();

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
