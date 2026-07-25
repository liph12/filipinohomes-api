<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a facility's non-identity fields. `name` and `slug` are PROHIBITED
 * here on purpose — renames must go through the rebrand endpoint so the
 * aliases/former_slugs invariant (old names stay searchable, old slugs 301)
 * can never be bypassed. `is_active` likewise has its own endpoints
 * (activate/deactivate) so the destructive path gets its own confirmation.
 */
class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route sits inside the RoleMiddleware:admin group.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'prohibited',
            'slug'      => 'prohibited',
            'is_active' => 'prohibited',
            'category'  => 'sometimes|required|in:mall,school,hospital',
            'city'      => 'sometimes|required|string|max:96',
            'province'  => 'sometimes|required|string|max:96',
            'lat'       => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng'       => 'nullable|numeric|between:-180,180|required_with:lat',
        ];
    }

    public function messages(): array
    {
        return [
            'name.prohibited'      => 'Renames go through the rebrand action so the old name/slug history is preserved.',
            'slug.prohibited'      => 'Slugs are never edited directly — use the rebrand action for a deliberate slug change.',
            'is_active.prohibited' => 'Use the activate/deactivate actions to change visibility.',
        ];
    }
}
