<?php

namespace App\Http\Requests;

use App\Models\AssumeDetail;

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
            // ── For Assume (pasalo) ──────────────────────────────────
            // All nullable here; withValidator() below requires the few that
            // make the maths possible, but only when the category is For Assume.
            'assume' => 'nullable|array',
            'assume.stage' => 'nullable|in:equity,loan',
            'assume.total_contract_price' => 'nullable|numeric|min:0',
            'assume.amount_paid_to_date' => 'nullable|numeric|min:0',
            'assume.remaining_equity_monthly' => 'nullable|numeric|min:0',
            'assume.remaining_equity_months' => 'nullable|integer|min:0|max:120',
            'assume.loan_balance' => 'nullable|numeric|min:0',
            'assume.existing_monthly_amortization' => 'nullable|numeric|min:0',
            'assume.transfer_fee' => 'nullable|numeric|min:0',
            'assume.reservation_fee' => 'nullable|numeric|min:0',
            'assume.other_fees' => 'nullable|numeric|min:0',
            'assume.lender_type' => 'nullable|in:bank,pag_ibig,in_house,not_yet',
            'assume.lender_name' => 'nullable|string|max:255',
            'assume.loan_status' => 'nullable|in:not_applied,in_process,approved,released',
            'assume.arrears_status' => 'nullable|in:none,1_2_months,3_plus_months',
            'assume.arrears_amount' => 'nullable|numeric|min:0',
            'assume.developer_allows_transfer' => 'nullable|in:yes_written,pending,no',
            'assume.developer_name' => 'nullable|string|max:255',
            'assume.turnover_status' => 'nullable|in:rfo,under_construction',
            'assume.turnover_date' => 'nullable|date',
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

            // ── For Assume ───────────────────────────────────────────────
            // A pasalo listing's price is only the CASH-OUT. Without the
            // balance the buyer takes over we cannot show the true cost, which
            // is the whole reason this category exists — so require the few
            // fields the computation needs, and nothing more (a long form on a
            // phone is how a category goes unused).
            if (AssumeDetail::isAssumeCategory($this->input('category_id'))) {
                $assume = (array) $this->input('assume', []);
                $stage = $assume['stage'] ?? null;

                if (! in_array($stage, AssumeDetail::STAGES, true)) {
                    $v->errors()->add('assume.stage', 'Select whether the seller is still paying equity or already has a loan.');
                } elseif ($stage === AssumeDetail::STAGE_LOAN) {
                    if (! is_numeric($assume['loan_balance'] ?? null) || (float) $assume['loan_balance'] <= 0) {
                        $v->errors()->add('assume.loan_balance', 'Enter the loan balance the buyer will take over.');
                    }
                } else {
                    $monthly = $assume['remaining_equity_monthly'] ?? null;
                    $months = $assume['remaining_equity_months'] ?? null;
                    if (! is_numeric($monthly) || (float) $monthly <= 0 || ! is_numeric($months) || (int) $months <= 0) {
                        $v->errors()->add('assume.remaining_equity_monthly', 'Enter the remaining equity payment and how many months are left.');
                    }
                }

                // A seller cannot have paid in more than the unit costs.
                $tcp = $assume['total_contract_price'] ?? null;
                $paid = $assume['amount_paid_to_date'] ?? null;
                if (is_numeric($tcp) && is_numeric($paid) && (float) $paid > (float) $tcp) {
                    $v->errors()->add('assume.amount_paid_to_date', 'Amount paid cannot be more than the total contract price.');
                }
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
