<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * "Listings by City" — paginated listings in one city that carry ATS
 * attachments, powering the city ATS drill-down drawer.
 */
class ListingByCityService extends ListingInsightsService
{
    /**
     * Paginated listings in one city that have ATS attachments — powers the
     * "Listings by City" ATS drill-down drawer. Each row carries its
     * ats_status + the attachment URLs so the frontend can open them in the
     * shared MediaLightbox.
     *
     * @param array{page?:int, per_page?:int, province_id?:int|null, date_start?:string|null, date_end?:string|null, category?:string, status?:string, ats_status?:string, attachment?:string} $params
     */
    public function listingsForCity(int $cityId, array $params, ?array $agentIds = null): array
    {
        $this->agentIds  = $agentIds;
        $this->dateStart = $params['date_start'] ?? null;
        $this->dateEnd   = $params['date_end'] ?? null;

        $page       = max(1, (int) ($params['page'] ?? 1));
        $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $provinceId = isset($params['province_id']) ? (int) $params['province_id'] : null;

        // Drill-down filters.
        $category   = trim((string) ($params['category'] ?? ''));      // exact categories.name
        $status     = strtolower(trim((string) ($params['status'] ?? '')));    // properties.status
        $atsStatus  = strtolower(trim((string) ($params['ats_status'] ?? ''))); // approve/pending/expired/rejected
        $attachment = strtolower(trim((string) ($params['attachment'] ?? 'with'))); // with|without|all

        $base = $this->baseListingQuery()
            ->where(DB::raw('COALESCE(projects.city_id, property_cities.id)'), $cityId)
            ->when(
                $provinceId !== null,
                fn ($q) => $q->where(
                    DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'),
                    $provinceId
                )
            )
            ->when($category !== '', fn ($q) => $q->where('categories.name', $category))
            ->when($atsStatus !== '', fn ($q) => $q->where('properties.ats_status', $atsStatus))
            ->when($status !== '', function ($q) use ($status) {
                if ($status === 'active') {
                    $q->where(fn ($qq) => $qq->where('properties.status', 'active')->orWhereNull('properties.status'));
                } else {
                    $q->where('properties.status', $status);
                }
            });

        if ($attachment === 'with') {
            $this->withAtsAttachments($base);
        } elseif ($attachment === 'without') {
            $base->where('properties.has_ats_files', 0);
        }

        $totalCount = (clone $base)->count('listings.id');

        $rows = (clone $base)
            ->leftJoin('agents', 'agents.id', '=', 'listings.agent_id')
            ->select(
                'listings.id',
                'listings.code',
                'listings.name as listing_name',
                'listings.slug',
                'listings.featured_photo',
                'categories.name as category_name',
                'properties.status as property_status',
                'properties.ats_status',
                'properties.ats_expiration_date',
                'properties.ats_remarks',
                'properties.ats_attachments',
                'agents.first_name as agent_first',
                'agents.last_name as agent_last',
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name')
            )
            ->orderByDesc('properties.ats_expiration_date')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $listings = $rows->map(function ($row) {
            $attachments = $row->ats_attachments;
            if (is_string($attachments)) {
                $decoded = json_decode($attachments, true);
                $attachments = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($attachments)) {
                $attachments = [];
            }

            $photos    = is_array($attachments['photos'] ?? null) ? array_values($attachments['photos']) : [];
            $documents = is_array($attachments['documents'] ?? null) ? array_values($attachments['documents']) : [];

            // featured_photo may be a JSON array string — normalize to the first URL.
            $featured = $row->featured_photo;
            if (is_string($featured)) {
                $trimmed = trim($featured);
                if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '"')) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $featured = !empty($decoded[0]) ? $decoded[0] : null;
                    } elseif (is_string($decoded)) {
                        $featured = $decoded;
                    }
                }
            } elseif (is_array($featured)) {
                $featured = !empty($featured[0]) ? $featured[0] : null;
            }

            return [
                'id'                  => (int) $row->id,
                'code'                => (string) $row->code,
                'name'                => (string) $row->listing_name,
                'slug'                => $row->slug,
                'image'               => $featured,
                'category_name'       => $row->category_name,
                'property_status'     => $row->property_status,
                'ats_status'          => $row->ats_status,
                'ats_expiration_date' => $row->ats_expiration_date,
                'ats_remarks'         => $row->ats_remarks,
                'ats_photos'          => $photos,
                'ats_documents'       => $documents,
                'agent_name'          => trim(((string) $row->agent_first) . ' ' . ((string) $row->agent_last)),
                'city_name'           => $row->city_name,
                'province_name'       => $row->province_name,
            ];
        });

        return [
            'data' => $listings,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($totalCount / $perPage)),
                'per_page'     => $perPage,
                'total'        => $totalCount,
                'city_id'      => $cityId,
                'province_id'  => $provinceId,
                'category'     => $category ?: null,
                'status'       => $status ?: null,
                'ats_status'   => $atsStatus ?: null,
                'attachment'   => $attachment,
            ],
        ];
    }
}
