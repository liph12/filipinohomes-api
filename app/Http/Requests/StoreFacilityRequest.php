<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a curated SEO facility. `slug` is deliberately NOT accepted from the
 * client — the controller derives it from the name (stable URL identity), and
 * later renames go through the rebrand endpoint which preserves slug history.
 */
class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route sits inside the RoleMiddleware:admin group.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:160',
            'category' => 'required|in:mall,school,hospital',
            'city'     => 'required|string|max:96',
            'province' => 'required|string|max:96',
            // Optional manual coordinates — the fallback when inline geocoding
            // fails or the admin already knows the exact pin.
            'lat'      => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng'      => 'nullable|numeric|between:-180,180|required_with:lat',
        ];
    }
}
