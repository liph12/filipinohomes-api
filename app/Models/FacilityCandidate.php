<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One POI discovered by the nationwide facility scanner
 * (facilities:scan-candidates), awaiting admin review on the SEO Manage
 * Candidates tile. Deliberately NOT audited via LogsActivity — scans upsert
 * thousands of rows and would spam the activity log; the human approve /
 * dismiss actions are audited from FacilityCandidateController instead.
 */
class FacilityCandidate extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'source',
        'osm_type',
        'osm_id',
        'name',
        'category',
        'lat',
        'lng',
        'city',
        'province',
        'city_id',
        'max_total',
        'clears_floor',
        'cohorts',
        'status',
        'matched_facility_id',
        'approved_facility_id',
        'scanned_at',
    ];

    protected $casts = [
        'lat'          => 'float',
        'lng'          => 'float',
        'max_total'    => 'integer',
        'clears_floor' => 'boolean',
        'cohorts'      => 'array',
        'scanned_at'   => 'datetime',
    ];

    /** Awaiting review and not already matched to an existing facility. */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNull('matched_facility_id');
    }

    public function scopeClearsFloor($query)
    {
        return $query->where('clears_floor', true);
    }

    public function approvedFacility()
    {
        return $this->belongsTo(Facility::class, 'approved_facility_id');
    }
}
