<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminServiceController extends Controller
{
    private function clearServiceCache(): void
    {
        Cache::forget('active_services_all');
        Cache::forget('active_services_print');
        Cache::forget('active_services_pan');
        Cache::forget('active_services_pan_find');
        Cache::forget('active_services_document');
    }

    /**
     * List all services (active and inactive)
     */
    public function index()
    {
        $services = Service::orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $services,
        ]);
    }

    /**
     * Create new service
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:print,pan_find,document',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'required_fields' => 'nullable|array',
        ]);

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);

        $service = Service::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'category' => $validated['category'],
            'price' => $validated['price'],
            'description' => $validated['description'] ?? null,
            'required_fields' => $validated['required_fields'] ?? null,
            'is_active' => true,
        ]);

        $this->clearServiceCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Service created successfully.',
            'data' => $service,
        ], 201);
    }

    /**
     * Update service
     */
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:print,pan_find,document',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'required_fields' => 'nullable|array',
        ]);

        $service->update($validated);
        Cache::forget("service_detail_{$id}");
        $this->clearServiceCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Service updated successfully.',
            'data' => $service,
        ]);
    }

    /**
     * Toggle service active/inactive
     */
    public function toggle($id)
    {
        $service = Service::findOrFail($id);
        $service->is_active = !$service->is_active;
        $service->save();

        Cache::forget("service_detail_{$id}");
        $this->clearServiceCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Service status updated.',
            'data' => $service,
        ]);
    }
}
