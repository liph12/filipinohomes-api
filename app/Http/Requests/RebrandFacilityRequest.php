<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename a facility (FacilityRebrandService applies the invariant: old name →
 * aliases; optional deliberate slug change → old slug into former_slugs for
 * the frontend 301).
 */
class RebrandFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route sits inside the RoleMiddleware:admin group.
        return true;
    }

    public function rules(): array
    {
        return [
            'new_name' => 'required|string|max:160',
            // Omit (or send null) to keep the current slug — the default and
            // recommended path. Only a deliberate slug change passes this.
            'new_slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_slug.regex' => 'Slugs are lowercase words separated by single hyphens (e.g. "sm-j-mall").',
        ];
    }
}
