<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;
class StoreListingRequest extends FormRequest
{
    public function user($guard = null)
    {
        return auth('sanctum')->user();
    }

    public function rules(): array
    {
        return [
            'property_type_id' => 'required|exists:property_types,id',
            'property_subtype_id' => 'required|exists:property_subtypes,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'price' => 'required|numeric|min:0',
            'bedroom_count' => 'nullable|integer|min:0',
            'bathroom_count' => 'nullable|integer|min:0',
            'garage_count' => 'nullable|integer|min:0',
            'lot_area' => 'nullable|numeric|min:0',
            'floor_area' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'string|url',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string',
            'geo_coordinates' => 'nullable|array',
            'is_project' => 'boolean',
            'furnishing_name' => 'nullable|string',
            'category_name' => 'nullable|string',
            'code' => 'required|string|unique:listings,code',
            'status' => 'nullable|in:active,inactive,sold,rented',
            'slug' => 'nullable|string|unique:listings,slug',
            'featured_photo' => ['nullable', function ($attribute, $value, $fail) {
                if (is_string($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail('The '.$attribute.' must be a valid URL.');
                    }
                } elseif (is_array($value)) {
                    foreach ($value as $url) {
                        if (!filter_var($url, FILTER_VALIDATE_URL)) {
                            $fail('Each '.$attribute.' must be a valid URL.');
                        }
                    }
                } else {
                    $fail('The '.$attribute.' must be a string or an array of URLs.');
                }
            }],
            'is_featured' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'property_type_id.required' => 'Please select a property type',
            'property_subtype_id.required' => 'Please select a property subtype',
            'name.required' => 'Listing name is required',
            'price.required' => 'Price is required',
            'code.unique' => 'This listing code is already in use',
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Only agents can create listings'
        );
    }
}