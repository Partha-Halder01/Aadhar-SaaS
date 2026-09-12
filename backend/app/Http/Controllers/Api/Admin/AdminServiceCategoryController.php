<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminServiceCategoryController extends Controller
{
    private function clearCategoryCache(): void
    {
        Cache::forget('active_service_categories');
        Cache::forget('active_services_all');
    }

    /**
     * List all categories (with service count)
     */
    public function index()
    {
        // First ensure any distinct category in services table exists in service_categories
        $serviceCategories = Service::distinct()->pluck('category')->filter()->values();
        foreach ($serviceCategories as $catSlug) {
            $catSlugClean = strtolower(trim(preg_replace('/\s+/', '_', $catSlug)));
            if (!ServiceCategory::where('slug', $catSlugClean)->exists()) {
                ServiceCategory::create([
                    'slug' => $catSlugClean,
                    'name' => ucwords(str_replace('_', ' ', $catSlugClean)) . ' Services',
                    'description' => 'Available citizen services under ' . ucwords(str_replace('_', ' ', $catSlugClean)),
                    'icon' => 'fa-solid fa-layer-group',
                    'icon_bg' => '#eff6ff',
                    'icon_color' => '#0066cc',
                    'is_active' => true,
                ]);
            }
        }

        $categories = ServiceCategory::withCount('services')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    /**
     * Create a new category
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:service_categories,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'icon_bg' => 'nullable|string|max:50',
            'icon_color' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        $slug = !empty($validated['slug'])
            ? strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', $validated['slug'])))
            : strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', Str::slug($validated['name']))));

        // Ensure unique slug
        $baseSlug = $slug;
        $counter = 1;
        while (ServiceCategory::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}_{$counter}";
            $counter++;
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $request->validate([
                'image' => 'file|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);
            $imagePath = $request->file('image')->store('categories', 'public');
        } elseif ($request->filled('image') && is_string($request->input('image'))) {
            $imagePath = $request->input('image');
        }

        $category = ServiceCategory::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'image' => $imagePath,
            'icon' => $validated['icon'] ?? 'fa-solid fa-layer-group',
            'icon_bg' => $validated['icon_bg'] ?? '#eff6ff',
            'icon_color' => $validated['icon_color'] ?? '#0066cc',
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        $this->clearCategoryCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Category created successfully.',
            'data' => $category,
        ], 201);
    }

    /**
     * Update an existing category
     */
    public function update(Request $request, $id)
    {
        $category = ServiceCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'icon_bg' => 'nullable|string|max:50',
            'icon_color' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        if ($request->hasFile('image')) {
            $request->validate([
                'image' => 'file|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);
            if ($category->image && !str_starts_with($category->image, 'http')) {
                Storage::disk('public')->delete($category->image);
            }
            $validated['image'] = $request->file('image')->store('categories', 'public');
        } elseif ($request->input('remove_image') === '1' || $request->input('remove_image') === true || $request->input('remove_image') === 'true') {
            if ($category->image && !str_starts_with($category->image, 'http')) {
                Storage::disk('public')->delete($category->image);
            }
            $validated['image'] = null;
        }

        $category->update($validated);
        $this->clearCategoryCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Category updated successfully.',
            'data' => $category->fresh(),
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggle($id)
    {
        $category = ServiceCategory::findOrFail($id);
        $category->is_active = !$category->is_active;
        $category->save();

        $this->clearCategoryCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Category status updated.',
            'data' => $category,
        ]);
    }
}
