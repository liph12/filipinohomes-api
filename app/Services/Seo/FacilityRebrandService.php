<?php

namespace App\Services\Seo;

use App\Models\Facility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE one place the facility rebrand invariant lives (the rule that kept
 * "J Centre Mall → SM J Mall" from forking its URL):
 *
 *   - rename = change `name`, KEEP `slug`, append the old name to `aliases`
 *     (search still matches the old name — aliases are denormalized into
 *     facility_listing_counts on the next compute)
 *   - a DELIBERATE slug change additionally appends the old slug to
 *     `former_slugs`, which the frontend 301s to the current URL
 *
 * Controllers never mutate name/slug directly — UpdateFacilityRequest
 * prohibits both fields, so this service can't be bypassed.
 */
class FacilityRebrandService
{
    public function rebrand(Facility $facility, string $newName, ?string $newSlug = null): Facility
    {
        return DB::transaction(function () use ($facility, $newName, $newSlug) {
            $oldName = $facility->name;
            $oldSlug = $facility->slug;

            $data = ['name' => $newName];

            // Keep the old name findable, dedup case-insensitively, and never
            // list the current name as its own alias.
            $data['aliases'] = collect($facility->aliases ?? [])
                ->push($oldName)
                ->unique(fn ($v) => mb_strtolower(trim((string) $v)))
                ->reject(fn ($v) => mb_strtolower(trim((string) $v)) === mb_strtolower(trim($newName)))
                ->values()
                ->all();

            $slugChanged = $newSlug !== null && $newSlug !== '' && $newSlug !== $oldSlug;
            if ($slugChanged) {
                if (Facility::slugInUse($newSlug, $facility->id)) {
                    throw ValidationException::withMessages([
                        'new_slug' => "The slug \"{$newSlug}\" is already used by another facility (as its current or former slug).",
                    ]);
                }
                $data['slug'] = $newSlug;
                $data['former_slugs'] = collect($facility->former_slugs ?? [])
                    ->push($oldSlug)
                    ->unique()
                    ->reject(fn ($v) => $v === $newSlug)
                    ->values()
                    ->all();
            }

            // Readable audit line for the activity log ('seo' category).
            $facility->auditDescription = $slugChanged
                ? "Rebranded facility: {$oldName} → {$newName} (slug {$oldSlug} → {$newSlug}, old slug kept as 301)"
                : "Rebranded facility: {$oldName} → {$newName} (slug unchanged)";
            $facility->auditSource = 'seo_admin';

            $facility->update($data);

            return $facility->refresh();
        });
    }
}
