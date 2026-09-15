<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminServiceController extends Controller
{
    private const FIELD_TYPES = ['text', 'number', 'tel', 'email', 'date', 'textarea', 'select', 'file'];

    private const MAX_FIELDS = 30;

    /**
     * Clean the admin-defined customer input fields for a service into a safe, predictable schema
     */
    private function normalizeRequiredFields($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $raw = null;
            }
        }

        if (!is_array($raw)) {
            throw ValidationException::withMessages(['required_fields' => ['Customer input fields are not valid.']]);
        }

        if (count($raw) > self::MAX_FIELDS) {
            throw ValidationException::withMessages(['required_fields' => ['A service can have at most ' . self::MAX_FIELDS . ' input fields.']]);
        }

        $fields = [];
        $usedNames = [];

        foreach (array_values($raw) as $index => $field) {
            $position = $index + 1;
            $label = is_array($field) ? Str::limit(trim(strip_tags((string) ($field['label'] ?? ''))), 100, '') : '';

            if ($label === '') {
                throw ValidationException::withMessages(['required_fields' => ["Field #{$position} needs a label."]]);
            }

            $type = in_array($field['type'] ?? 'text', self::FIELD_TYPES, true) ? $field['type'] : 'text';

            // Keep an existing field's key stable so earlier orders still line up; otherwise derive it from the label
            $baseName = Str::limit(Str::slug((string) ($field['name'] ?? '') ?: $label, '_'), 50, '') ?: 'field';
            $name = $baseName;
            for ($suffix = 2; in_array($name, $usedNames, true); $suffix++) {
                $name = "{$baseName}_{$suffix}";
            }
            $usedNames[] = $name;

            $entry = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'placeholder' => Str::limit(trim(strip_tags((string) ($field['placeholder'] ?? ''))), 150, ''),
            ];

            if ($type === 'select') {
                $options = $field['options'] ?? [];
                if (is_string($options)) {
                    $options = explode(',', $options);
                }
                $options = array_values(array_unique(array_filter(
                    array_map(fn ($option) => Str::limit(trim(strip_tags((string) $option)), 100, ''), (array) $options),
                    fn ($option) => $option !== ''
                )));

                if (!$options) {
                    throw ValidationException::withMessages(['required_fields' => ["Dropdown field \"{$label}\" needs at least one option."]]);
                }
                $entry['options'] = array_slice($options, 0, 50);
            }

            $fields[] = $entry;
        }

        return $fields;
    }

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

    private function isSafeImageUrl($value): bool
    {
        return is_string($value) && str_starts_with(strtolower($value), 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false;
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

        $validated['required_fields'] = $this->normalizeRequiredFields($request->input('required_fields'));

        $iconImagePath = null;
        if ($request->hasFile('icon_image')) {
            $request->validate([
                'icon_image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);
            $iconImagePath = $request->file('icon_image')->store('services/icons', 'public');
        } elseif ($this->isSafeImageUrl($request->input('icon_image'))) {
            $iconImagePath = $request->input('icon_image');
        }

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);

        $service = Service::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'category' => strtolower(trim(preg_replace('/\s+/', '_', $validated['category']))),
            'price' => $validated['price'],
            'description' => $validated['description'] ?? null,
            'required_fields' => $validated['required_fields'],
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

        // Only touch the field list when the admin form sends it, so partial updates don't wipe it
        if ($request->has('required_fields')) {
            $validated['required_fields'] = $this->normalizeRequiredFields($request->input('required_fields'));
        } else {
            unset($validated['required_fields']);
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
        } elseif ($this->isSafeImageUrl($request->input('icon_image'))) {
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
