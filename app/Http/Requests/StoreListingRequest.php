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
            // ATS attachments are optional (when none, agent_ats_remarks is
            // required — see withValidator). The expiration date is ALWAYS
            // required, even without an attachment.
            'ats_expiration_date' => 'required|date',
            'ats_attachments'              => 'nullable|array',
            'ats_attachments.photos'       => 'nullable|array',
            'ats_attachments.photos.*'     => 'string|url',
            'ats_attachments.documents'    => 'nullable|array',
            'ats_attachments.documents.*'  => 'string|url',
            'ats_remarks'                  => 'nullable|string',
            'agent_ats_remarks'            => 'nullable|string|max:2000',
            'is_project' => 'nullable|boolean',
            'project_id' => 'nullable|exists:projects,id',
            'address_id' =>  'nullable|exists:barangays,id',
            'project' => 'nullable|array',
            'project.id' => 'nullable|exists:projects,id',
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
            // Accept flexible structures; normalization happens in service
            'nearby_facilities' => 'nullable|array',
        ];
    }

    /**
     * ATS attachments are optional, but if the listing has NO ATS attachment
     * (no photos and no documents) the agent must explain why in
     * agent_ats_remarks. Enforced here so the rule reads off the actual payload.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $atts   = $this->input('ats_attachments', []);
            $photos = is_array($atts) ? ($atts['photos'] ?? []) : [];
            $docs   = is_array($atts) ? ($atts['documents'] ?? []) : [];
            $hasAttachment = (is_array($photos) && count($photos) > 0)
                || (is_array($docs) && count($docs) > 0);

            $remarks = trim((string) $this->input('agent_ats_remarks', ''));

            if (!$hasAttachment && $remarks === '') {
                $v->errors()->add(
                    'agent_ats_remarks',
                    'ATS remarks are required when there is no ATS attachment.',
                );
            }
        });
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
