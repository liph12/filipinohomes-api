<?php
/**
 * SEO name backfill for placeholder-named listings.
 *
 * A pool of real, trafficked listings carry lazy placeholder names
 * ("Title", "qwe", "429", bare numbers like "1602"/"0000"). The page
 * <title> and H1 derive from Listing.name, so these rank for nothing.
 * This synthesises a real, human-readable name from structured fields
 * (subtype + beds + sale/rent + location), e.g.
 *   "Residential Lot for Sale in Lapu-Lapu City"
 *   "3-Bedroom House and Lot for Sale in Liloan, Cebu"
 *
 * SAFETY:
 *   - DRY-RUN by default: writes nothing, dumps proposals to
 *     storage/app/seo-name-dryrun.csv for review.
 *   - --write only after you've reviewed the CSV.
 *   - Writes Listing.name (+ Property.name) via saveQuietly() so the
 *     Listing slug is NEVER regenerated (slug regen lives in the model's
 *     creating/created events + ListingService, both event-driven).
 *     => URL stays /title-25650, no redirect, no 404 risk. No audit row,
 *     no IndexNow ping; updated_at still bumps so sitemap lastmod refreshes.
 *   - Only touches names that match the placeholder signature; a real
 *     name is never overwritten.
 *   - Skips when we can't build a confident name (no subtype/type, or no
 *     location) rather than writing filler.
 *
 * RUN (from the Laravel root, e.g. /var/www/html):
 *   php seo_backfill_names.php                    # dry-run -> CSV
 *   php seo_backfill_names.php --limit=20         # dry-run, first 20 only
 *   php seo_backfill_names.php --write --limit=20 # write just 20 (staged)
 *   php seo_backfill_names.php --write            # real write (all)
 *   php seo_backfill_names.php --restore=storage/app/seo-name-backup-XXX.csv
 *
 * ROLLBACK: every --write run first dumps each row's ORIGINAL names to
 *   storage/app/seo-name-backup-{timestamp}.csv. Undo with --restore=<file>.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Listing;
use App\Models\Property;
use App\Models\Category;
use App\Models\PropertyType;

$WRITE = in_array('--write', $argv, true);
$LIMIT = null;
$RESTORE = null;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $LIMIT = (int) $m[1];
    if (preg_match('/^--restore=(.+)$/', $a, $m)) $RESTORE = $m[1];
}

// --- ROLLBACK MODE ---------------------------------------------------------
// Reads a backup CSV (listing_id, property_id, orig_listing_name,
// orig_property_name) from a prior --write run and writes the originals back.
if ($RESTORE) {
    if (!is_file($RESTORE)) { fwrite(STDERR, "restore file not found: {$RESTORE}\n"); exit(1); }
    $fh = fopen($RESTORE, 'r');
    fgetcsv($fh); // header
    $n = 0;
    while ($row = fgetcsv($fh)) {
        [$listingId, $propertyId, $origL, $origP] =
            [$row[0] ?? null, $row[1] ?? null, $row[2] ?? '', $row[3] ?? ''];
        $l = Listing::find($listingId);
        if ($l) { $l->name = $origL; $l->saveQuietly(); }
        $p = Property::find($propertyId);
        if ($p) { $p->name = $origP; $p->saveQuietly(); }
        $n++;
    }
    fclose($fh);
    echo "restored {$n} listing names from {$RESTORE}\n";
    exit(0);
}

// --- lookup tables ----------------------------------------------------------
$types = PropertyType::pluck('name', 'id')->all();  // 1 Condominium .. 4 Commercial

// --- placeholder signature --------------------------------------------------
// Names we treat as lazy placeholders. Kept narrow on purpose so a real
// name is never caught: anything starting "title", a pure 1-5 digit number,
// or a short keyboard-mash test word.
$TEST_WORDS = ['qwe','qwer','qwert','qwerty','asd','asdf','asdfg','test','testing','aaa','xxx','zzz','sample','demo'];

function isPlaceholderName(?string $name, array $testWords): bool
{
    $n = trim(mb_strtolower((string) $name));
    if ($n === '') return true;
    if (str_starts_with($n, 'title')) return true;       // "Title", "Title in my name", ...
    if (preg_match('/^[0-9]{1,5}$/', $n)) return true;    // "429", "1602", "0000"
    if (in_array($n, $testWords, true)) return true;
    return false;
}

// --- helpers (shared logic with the description backfill) -------------------

/** Parse "Brgy, City, Province, 1702, Philippines" -> [city, province]. */
function parseLocation(?string $address): array
{
    if (!$address) return [null, null];
    $parts = array_map('trim', explode(',', $address));
    $parts = array_values(array_filter($parts, function ($p) {
        if ($p === '') return false;
        if (strcasecmp($p, 'Philippines') === 0) return false;
        if (preg_match('/^\d{3,5}$/', $p)) return false; // zip
        return true;
    }));
    $parts = array_map(
        fn($p) => preg_replace('/^(Lalawigan|Lungsod|Bayan|Barangay)\s+ng\s+/iu', '', $p),
        $parts
    );
    $n = count($parts);
    if ($n === 0) return [null, null];
    if ($n === 1) return [$parts[0], null];
    return [$parts[$n - 2], $parts[$n - 1]]; // city, province
}

function saleWord(int $categoryId): string
{
    return $categoryId === 2 ? 'for Rent' : 'for Sale';
}

/**
 * Build the synthesised name. Returns '' when there isn't enough signal
 * (need a subtype/type AND a location) so we skip rather than write filler.
 */
function buildName(array $d): string
{
    $subtype = $d['subtype'] ?: ($d['type'] ?: '');
    if ($subtype === '') return '';

    $type   = $d['type'] ?: '';
    $isLand = strcasecmp($type, 'Land') === 0;

    $loc = $d['city']
        ? ($d['province'] && strcasecmp($d['city'], $d['province']) !== 0
            ? "{$d['city']}, {$d['province']}"
            : $d['city'])
        : ($d['province'] ?: null);
    if (!$loc) return '';

    // Bedroom prefix only when meaningful (not Land, sane count).
    $bedPrefix = (!$isLand && $d['beds'] > 0 && $d['beds'] <= 20)
        ? $d['beds'] . '-Bedroom '
        : '';

    return trim("{$bedPrefix}{$subtype} {$d['sale']} in {$loc}");
}

// --- main -------------------------------------------------------------------

// Pull the public+active candidates whose name matches the placeholder
// signature. The SQL prefilter narrows to roughly the right set; the
// PHP isPlaceholderName() is the authoritative gate so we never rename a
// real name that slipped through the LIKE.
$query = Listing::where('visibility', 'public')
    ->whereHas('property', fn ($q) => $q->where('status', 'active'))
    ->where(function ($q) {
        $q->whereRaw('LOWER(TRIM(name)) LIKE "title%"')
          ->orWhereRaw('TRIM(name) REGEXP "^[0-9]{1,5}$"')
          ->orWhereIn(\DB::raw('LOWER(TRIM(name))'),
              ['qwe','qwer','qwert','qwerty','asd','asdf','asdfg','test','testing','aaa','xxx','zzz','sample','demo',''])
          ->orWhereNull('name');
    })
    ->with(['property.propertyAttribute.subtype']);

$total = (clone $query)->count();
echo ($WRITE ? '*** WRITE MODE ***' : 'DRY-RUN (no DB writes)') . "  candidates: {$total}\n";

$csvPath = storage_path('app/seo-name-dryrun.csv');
$csv = $WRITE ? null : fopen($csvPath, 'w');
if ($csv) fputcsv($csv, ['listing_id', 'code', 'slug', 'old_name', 'new_name']);

$backupPath = storage_path('app/seo-name-backup-' . date('Ymd-His') . '.csv');
$backup = $WRITE ? fopen($backupPath, 'w') : null;
if ($backup) fputcsv($backup, ['listing_id', 'property_id', 'orig_listing_name', 'orig_property_name']);

$done = 0; $skipped = 0; $written = 0;

$query->orderBy('id')->chunkById($LIMIT ? min(200, $LIMIT) : 200, function ($listings) use (
    &$done, &$skipped, &$written, $types, $TEST_WORDS, $WRITE, $csv, $backup, $LIMIT
) {
    foreach ($listings as $listing) {
        if ($LIMIT && $done >= $LIMIT) return false;

        // Authoritative placeholder gate — never rename a real name.
        if (!isPlaceholderName($listing->name, $TEST_WORDS)) { continue; }

        $p = $listing->property;
        if (!$p) { $skipped++; continue; }

        $attr = $p->propertyAttribute;
        $sub  = $attr?->subtype;
        [$city, $province] = parseLocation($p->address);

        $typeName = $sub ? ($types[$sub->property_type_id] ?? null) : null;

        $beds = (int) ($attr->bedroom_count ?? 0);
        if ($beds > 20) $beds = 0; // sanity cap

        $d = [
            'type'     => $typeName,
            'subtype'  => $sub?->name,
            'sale'     => saleWord((int) $listing->category_id),
            'city'     => $city,
            'province' => $province,
            'beds'     => $beds,
        ];

        $new = buildName($d);

        // Need a confident, non-trivial name.
        if (mb_strlen($new) < 12) { $skipped++; $done++; continue; }

        if ($WRITE) {
            fputcsv($backup, [$listing->id, $p->id, (string) $listing->name, (string) $p->name]);
            $listing->name = $new;
            $listing->saveQuietly(); // no events -> slug untouched, no IndexNow, no audit; updated_at bumps
            $p->name = $new;
            $p->saveQuietly();
            $written++;
        } else {
            fputcsv($csv, [$listing->id, $listing->code, $listing->slug, (string) $listing->name, $new]);
        }
        $done++;
    }
});

if ($csv) fclose($csv);
if ($backup) fclose($backup);

echo "processed: {$done}  " . ($WRITE ? "written: {$written}" : "csv rows: " . ($done - $skipped)) . "  skipped: {$skipped}\n";
if (!$WRITE) echo "Review: {$csvPath}\n";
if ($WRITE) echo "Rollback backup: {$backupPath}\n  undo with: php seo_backfill_names.php --restore=" . str_replace(base_path() . '/', '', $backupPath) . "\n";
