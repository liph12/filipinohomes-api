<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\PropertySubtype;

class UpdateListingRequest extends FormRequest
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

                foreach ([1, 2, 3, 4] as $n) {
                    if (stripos($subtypeName, "$n Bedroom") !== false) {
                        $bedroomCount = $n;
                        break;
                    }
                }

                if (!is_null($bedroomCount)) {
                    $this->merge(['bedroom_count' => $bedroomCount]);
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            'property_type_id'    => 'sometimes|exists:property_types,id',
            'property_subtype_id' => 'sometimes|exists:property_subtypes,id',
            'name'                => 'sometimes|string|max:255', 
            'address'             => 'sometimes|string',
            'price'               => 'sometimes|numeric|min:0',
            'bedroom_count'       => 'nullable|integer|min:0',
            'bathroom_count'      => 'nullable|integer|min:0',
            'garage_count'        => 'nullable|integer|min:0',
            'lot_area'            => 'nullable|numeric|min:0',
            'floor_area'          => 'nullable|numeric|min:0',
            'description'         => 'nullable|string',
            'photos'              => 'nullable|array',
            'address_id'          => 'nullable|integer',
            'photos.*'            => 'string|url',
            'amenities'           => 'nullable|array',
            'amenities.*'         => 'string',
            'geo_coordinates'     => 'nullable|array',
            'ats_expiration_date' => 'sometimes|date',
            'ats_attachments'              => 'sometimes|array',
            'ats_attachments.photos'       => 'sometimes|array',
            'ats_attachments.photos.*'     => 'string|url',
            'ats_attachments.documents'    => 'sometimes|array',
            'ats_attachments.documents.*'  => 'string|url',
            'ats_remarks'                  => 'nullable|string',
            // Agent's own ATS note. Required only when this edit leaves the ATS
            // attachments empty — enforced in withValidator() below.
            'agent_ats_remarks'            => 'nullable|string',
            'is_project'          => 'nullable|boolean',
            'project_id'          => 'nullable|exists:projects,id',
            'project'             => 'nullable|array',
            'seo_tags'             => 'nullable|array',
            'project.id'          => 'nullable|exists:projects,id',
            'project.name'        => 'nullable|string|max:255',
            'furnishing_id'       => 'nullable|exists:furnishings,id',
            'category_id'         => 'sometimes|exists:categories,id',
            'visibility'          => 'sometimes|in:public,private',
            'status'              => 'sometimes|in:active,rented,sold,leased',
            'featured_photo'      => ['nullable', function ($attribute, $value, $fail) {
                if (is_string($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail("The {$attribute} must be a valid URL.");
                    }
                } elseif (is_array($value)) {
                    foreach ($value as $url) {
                        if (!filter_var($url, FILTER_VALIDATE_URL)) {
                            $fail("Each {$attribute} must be a valid URL.");
                        }
                    }
                } else {
                    $fail("The {$attribute} must be a string or an array of URLs.");
                }
            }],
            'is_featured' => 'sometimes|boolean',
            // Accept flexible structures; normalization happens in service
            'nearby_facilities' => 'sometimes|array',
            'ats_status'        => 'sometimes|in:approve,pending,expired,rejected',
    ];
    } 

    /**
     * If this edit sets ATS attachments but leaves them empty (no photos and
     * no documents), the agent must explain why in agent_ats_remarks. Only
     * enforced when ats_attachments is actually present in the payload, so
     * edits that don't touch ATS aren't falsely blocked.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (!$this->has('ats_attachments')) {
                return;
            }
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

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You are not authorized to update this listing.'
        );
    }
}
