<?php
namespace App\Http\Resources;

use App\Services\Listing\AssumeComputation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AgentResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PropertyResource;
use App\Support\VariantUrl;
class ListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = auth('sanctum')->user();
        $isAdmin = $user && optional($user->role)->name === 'admin';
        $isOwner = $user && $this->agent_id === optional($user->agent)->id;
        $canSeeAudit = $isAdmin || $isOwner;

        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'visibility'     => $this->visibility,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => $this->price,
            'featured_photo' => $this->featured_photo,
            // Agent-promoted flyer capture used as the primary og:image
            // (null → the frontend's generated /og/listing card).
            'share_thumbnail_url' => $this->share_thumbnail_url,
            // Per-listing customization of the default card (photo/theme/flip/
            // hide[]/agent) — applied to live data by generateListingMetadata.
            'og_card_options' => $this->og_card_options,
            // Auto-generated YouTube slideshow (youtube:process-uploads).
            // The detail page embeds the player + emits VideoObject schema
            // (uploadDate comes from youtube_video_uploaded_at).
            'youtube_video_id' => $this->youtube_video_id,
            'youtube_video_uploaded_at' => $this->youtube_video_uploaded_at,
            // Responsive srcset per photo (index-aligned with featured_photo),
            // computed by convention — emitted ONLY when this record's variants
            // exist on S3. null → frontend falls back to the single original.
            'featured_photo_srcset' => $this->photos_variants_generated_at
                ? collect($this->featured_photo ?? [])
                    ->map(fn ($u) => is_string($u) ? VariantUrl::srcset($u) : null)
                    ->all()
                : null,
            'is_featured'    => $this->is_featured,
            'featured_until' => $this->featured_until,
            'clicks'         => $this->clicks,
            'inquiries_count' => $this->inquiries_count ?? 0,
            'seo_tags'       => $this->seo_tags,
            'created_at'          => $this->updated_at->diffForHumans(),
            'date_added'          => $this->created_at->toDateString(),
            'is_new'              => $this->created_at?->gte(now()->subDays(14)) ?? false,
            'verification_status'  => $this->verification_status,
            'audit_notes'          => $this->when($canSeeAudit, $this->audit_notes),
            'audit_checklist'      => $this->when($canSeeAudit, $this->audit_checklist),
            'audited_at'           => $this->when($canSeeAudit, $this->audited_at?->toDateTimeString()),
            'agent_edited_fields'  => $this->when($canSeeAudit, $this->agent_edited_fields),
            'audit_edited_fields'  => $this->when($canSeeAudit, $this->audit_edited_fields),
            're_submitted_at'      => $this->when($canSeeAudit, $this->re_submitted_at?->toDateTimeString()),
            // Leuterio Realty website activity — admins only (the "Used in LR" badges).
            'lr_used_at'           => $this->when($isAdmin, $this->lr_used_at?->toDateTimeString()),
            'lr_status_changed_at' => $this->when($isAdmin, $this->lr_status_changed_at?->toDateTimeString()),
            'property'            => new PropertyResource($this->property),
            'category'            => new CategoryResource($this->category),
            'agent'               => new AgentResource($this->agent),
            // "For Assume" money. Raw fields plus every derived figure, so the
            // listing page, the cards and the /pasalo hub all read one source
            // and can never quote different monthlies for the same unit.
            // Absent unless the relation was eager-loaded.
            'assume'              => $this->whenLoaded('assumeDetail', function () {
                if (! $this->assumeDetail) {
                    return null;
                }

                $floorArea = $this->property?->propertyAttribute?->floor_area;

                return array_merge(
                    $this->assumeDetail->only([
                        'stage', 'total_contract_price', 'amount_paid_to_date',
                        'remaining_equity_monthly', 'remaining_equity_months', 'loan_balance',
                        'existing_monthly_amortization', 'transfer_fee', 'reservation_fee',
                        'other_fees', 'lender_type', 'lender_name', 'loan_status',
                        'arrears_status', 'arrears_amount', 'developer_allows_transfer',
                        'developer_name', 'turnover_status',
                    ]),
                    ['turnover_date' => $this->assumeDetail->turnover_date?->toDateString()],
                    ['computed' => app(AssumeComputation::class)->compute(
                        $this->assumeDetail,
                        $floorArea !== null ? (float) $floorArea : null
                    )]
                );
            }),
        ];
    }
}
