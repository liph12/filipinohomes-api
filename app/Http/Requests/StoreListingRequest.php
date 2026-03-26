<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;
use App\Models\PropertySubtype;
class StoreListingRequest extends FormRequest
{
    public function user($guard = null)
    {
        return auth('sanctum')->user();
    }

    protected function prepareForValidation()
    {
        if (($this->bedroom_count === 0 || is_null($this->bedroom_count)) && $this->property_subtype_id) {
            $subtype = PropertySubtype::find($this->property_subtype_id);
            
            if ($subtype) {
                $bedroomCount = null;
                $subtypeName = $subtype->name;
                
                if (stripos($subtypeName, '1 Bedroom') !== false) {
                    $bedroomCount = 1;
                } elseif (stripos($subtypeName, '2 Bedroom') !== false) {
                    $bedroomCount = 2;
                } elseif (stripos($subtypeName, '3 Bedroom') !== false) {
                    $bedroomCount = 3;
                } elseif (stripos($subtypeName, '4 Bedroom') !== false) {
                    $bedroomCount = 4;
                }
                
                if (!is_null($bedroomCount)) {
                    $this->merge([
                        'bedroom_count' => $bedroomCount
                    ]);
                }
            }
        }
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
            'geo_coordinates' => 'nullable|array:lat,lng',
            'ats_expiration_date' => 'nullable|date',
            'is_project' => 'nullable|boolean',
            'project_id' => 'nullable|integer',
            'address_id' =>  'nullable|exists:barangays,id',
            'project' => 'nullable|array',
            'project.id' => 'nullable|integer',
            'project.name' => 'nullable|string|max:255',
            'furnishing_id' => 'nullable|exists:furnishings,id',
            'category_id' => 'required|exists:categories,id',
            'visibility' => 'in:public,private',
            'seo_tags'   => 'nullable|array',
            'seo_tags.*' => 'string|max:100',
            'status' => 'in:active,rented,sold,leased',
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
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Only agents can create listings'
        );
    }
}