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
        Cache::forget(\App\Services\LandingPageService::CACHE_KEY);
        Cache::forget('landing_page_config');
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
            'category' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'required_fields' => 'nullable',
            'icon_type' => 'nullable|string|in:icon,image',
            'icon' => 'nullable|string|max:100',
            'icon_bg' => 'nullable|string|max:50',
            'icon_color' => 'nullable|string|max:50',
            'btn_text' => 'nullable|string|max:100',
            'btn_icon' => 'nullable|string|max:100',
        ]);

        if (isset($validated['required_fields']) && is_string($validated['required_fields'])) {
            $decoded = json_decode($validated['required_fields'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $validated['required_fields'] = $decoded;
            }
        }

        $iconImagePath = null;
        if ($request->hasFile('icon_image')) {
            $request->validate([
                'icon_image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);
            $iconImagePath = $request->file('icon_image')->store('services/icons', 'public');
        } elseif ($request->filled('icon_image') && is_string($request->input('icon_image'))) {
            $iconImagePath = $request->input('icon_image');
        }

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);

        $service = Service::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'category' => strtolower(trim(preg_replace('/\s+/', '_', $validated['category']))),
            'price' => $validated['price'],
            'description' => $validated['description'] ?? null,
            'required_fields' => $validated['required_fields'] ?? null,
            'icon_type' => $validated['icon_type'] ?? 'icon',
            'icon' => $validated['icon'] ?? null,
            'icon_image' => $iconImagePath,
            'icon_bg' => $validated['icon_bg'] ?? null,
            'icon_color' => $validated['icon_color'] ?? null,
            'btn_text' => $validated['btn_text'] ?? null,
            'btn_icon' => $validated['btn_icon'] ?? null,
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
            'category' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'required_fields' => 'nullable',
            'icon_type' => 'nullable|string|in:icon,image',
            'icon' => 'nullable|string|max:100',
            'icon_bg' => 'nullable|string|max:50',
            'icon_color' => 'nullable|string|max:50',
            'btn_text' => 'nullable|string|max:100',
            'btn_icon' => 'nullable|string|max:100',
        ]);

        $validated['category'] = strtolower(trim(preg_replace('/\s+/', '_', $validated['category'])));

        if (isset($validated['required_fields']) && is_string($validated['required_fields'])) {
            $decoded = json_decode($validated['required_fields'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $validated['required_fields'] = $decoded;
            }
        }

        if ($request->hasFile('icon_image')) {
            $request->validate([
                'icon_image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);
            if ($service->icon_image && !str_starts_with($service->icon_image, 'http')) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($service->icon_image);
            }
            $validated['icon_image'] = $request->file('icon_image')->store('services/icons', 'public');
        } elseif ($request->input('remove_icon_image') === '1' || $request->input('remove_icon_image') === true || $request->input('remove_icon_image') === 'true') {
            if ($service->icon_image && !str_starts_with($service->icon_image, 'http')) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($service->icon_image);
            }
            $validated['icon_image'] = null;
        } elseif ($request->has('icon_image') && is_string($request->input('icon_image')) && !empty($request->input('icon_image'))) {
            $validated['icon_image'] = $request->input('icon_image');
        }

        $service->update($validated);
        Cache::forget("service_detail_{$id}");
        $this->clearServiceCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Service updated successfully.',
            'data' => $service->fresh(),
        ]);
    }

    /**
     * Get distinct service categories
     */
    public function categories()
    {
        $categories = Service::distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter()
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
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
