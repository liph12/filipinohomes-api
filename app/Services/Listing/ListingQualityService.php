<?php

namespace App\Services\Listing;

use App\Support\PhilippinePin;

/**
 * Listing Insights → Quality: how complete and how checked the listings are.
 *
 *   ats           authority-to-sell status of ALL listings in the window. A
 *                 listing counts as having one only when its property carries
 *                 files: `ats_status` defaults to 'approve' on every property,
 *                 so the column alone would call every listing without an ATS
 *                 "approved".
 *   completeness  the four things a listing page needs to be worth reading,
 *                 over ACTIVE listings in the window (a sold or rented listing
 *                 is no longer being sold with this page).
 *
 * The thresholds are the ones the rest of the product already uses: five photos
 * is the YouTube slideshow gate (ProcessYoutubeUploads), a 120-character
 * description is the search ranking's "real description" (DataLayerService),
 * the pin is the Philippines box the listing form enforces (PhilippinePin), and
 * a usable area is the 10 sqm Data Quality and Price Insights both use — lot or
 * floor for Land and Commercial, floor for everything else.
 *
 * One pass over the listings; the SQL runs unchanged on MySQL and SQLite.
 */
class ListingQualityService extends ListingInsightsService
{
    public const MIN_PHOTOS = 5;

    public const MIN_DESCRIPTION_CHARS = 120;

    public const MIN_AREA_SQM = 10;

    /**
     * @param  array<string, mixed>  $filters  date_start, date_end, province_id, city_id, … as configure() reads them
     * @return array<string, mixed>
     */
    public function quality(array $filters, ?array $agentIds = null): array
    {
        $this->configure($filters, $agentIds);
        $this->dateBasis = 'created';

        $active = "properties.status = 'active'";
        $photos = 'COALESCE('.$this->jsonLengthExpr('properties.photos').', 0) >= '.self::MIN_PHOTOS;
        $description = $this->charLengthExpr("COALESCE(properties.description, '')").' >= '.self::MIN_DESCRIPTION_CHARS;
        $pin = '('.$this->geoLat().') BETWEEN '.PhilippinePin::SOUTH.' AND '.PhilippinePin::NORTH
            .' AND ('.$this->geoLng().') BETWEEN '.PhilippinePin::WEST.' AND '.PhilippinePin::EAST;
        $sqm = self::MIN_AREA_SQM;
        $area = "(CASE WHEN pt.name IN ('Land', 'Commercial')"
            ." THEN (COALESCE(pa.lot_area, 0) >= {$sqm} OR COALESCE(pa.floor_area, 0) >= {$sqm})"
            ." ELSE COALESCE(pa.floor_area, 0) >= {$sqm} END)";

        $ats = fn (string $status) => "SUM(CASE WHEN properties.has_ats_files = 1 AND properties.ats_status = '{$status}' THEN 1 ELSE 0 END)";

        $row = $this->slimListingQuery()
            ->leftJoin('property_attributes as pa', 'pa.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes as pst', 'pst.id', '=', 'pa.property_subtype_id')
            ->leftJoin('property_types as pt', 'pt.id', '=', 'pst.property_type_id')
            ->selectRaw("
                COUNT(listings.id) as total,
                {$ats('approve')} as ats_approve,
                {$ats('pending')} as ats_pending,
                {$ats('expired')} as ats_expired,
                {$ats('rejected')} as ats_rejected,
                SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) as base,
                SUM(CASE WHEN {$active} AND {$photos} THEN 1 ELSE 0 END) as photos,
                SUM(CASE WHEN {$active} AND {$description} THEN 1 ELSE 0 END) as description,
                SUM(CASE WHEN {$active} AND {$pin} THEN 1 ELSE 0 END) as map_pin,
                SUM(CASE WHEN {$active} AND {$area} THEN 1 ELSE 0 END) as area,
                SUM(CASE WHEN {$active} AND {$photos} AND {$description} AND {$pin} AND {$area} THEN 1 ELSE 0 END) as complete
            ")
            ->first();

        $total = (int) ($row->total ?? 0);
        $withAts = [
            'approve' => (int) ($row->ats_approve ?? 0),
            'pending' => (int) ($row->ats_pending ?? 0),
            'expired' => (int) ($row->ats_expired ?? 0),
            'rejected' => (int) ($row->ats_rejected ?? 0),
        ];

        return [
            'range' => $this->rangeMeta(),
            'total' => $total,
            'ats' => $withAts + ['none' => $total - array_sum($withAts)],
            'completeness' => [
                'base' => (int) ($row->base ?? 0),
                'photos' => (int) ($row->photos ?? 0),
                'description' => (int) ($row->description ?? 0),
                'map_pin' => (int) ($row->map_pin ?? 0),
                'area' => (int) ($row->area ?? 0),
                'complete' => (int) ($row->complete ?? 0),
            ],
            // The rules the numbers apply, so the page can state them without copying them.
            'thresholds' => [
                'photos' => self::MIN_PHOTOS,
                'description_chars' => self::MIN_DESCRIPTION_CHARS,
                'area_sqm' => self::MIN_AREA_SQM,
            ],
        ];
    }
}
