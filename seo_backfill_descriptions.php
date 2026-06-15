<?php
/**
 * SEO description backfill for thin listings.
 *
 * Synthesises a unique, human-readable property description from existing
 * structured fields (subtype, sale/rent, location, price, beds/baths,
 * area, furnishing, amenities) for every active+public listing whose
 * Property.description is under 200 characters — the "thin content" Google
 * soft-404s / leaves not-indexed.
 *
 * SAFETY:
 *   - DRY-RUN by default: writes nothing to the DB, dumps every proposed
 *     change to storage/app/seo-desc-dryrun.csv for review.
 *   - --write only after you've reviewed the CSV.
 *   - Updates Property.description via direct Eloquent saveQuietly() — never
 *     goes through ListingService (so the Listing slug is never regenerated)
 *     and writes no audit rows. updated_at still bumps so sitemap lastmod
 *     refreshes and Google re-crawls the richer page.
 *   - Existing (short) description is PRESERVED and appended below the new
 *     synthesised intro, so no agent-entered detail is lost.
 *   - Idempotent: once a row passes 200 chars it drops out of the query.
 *
 * RUN (from the Laravel root, e.g. /var/www/html):
 *   php seo_backfill_descriptions.php              # dry-run -> CSV
 *   php seo_backfill_descriptions.php --limit=20   # dry-run, first 20 only
 *   php seo_backfill_descriptions.php --write --limit=20  # write just 20 (staged)
 *   php seo_backfill_descriptions.php --write       # real write (all)
 *   php seo_backfill_descriptions.php --restore=storage/app/seo-desc-backup-XXX.csv
 *
 * ROLLBACK: every --write run first dumps each row's ORIGINAL description to
 *   storage/app/seo-desc-backup-{timestamp}.csv before changing it. To undo,
 *   re-run with --restore=<that file> and it writes the originals straight back.
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
// Reads a backup CSV (listing_id, property_id, original_description) produced
// by a prior --write run and writes the originals straight back.
if ($RESTORE) {
    if (!is_file($RESTORE)) { fwrite(STDERR, "restore file not found: {$RESTORE}\n"); exit(1); }
    $fh = fopen($RESTORE, 'r');
    $header = fgetcsv($fh);
    $n = 0;
    while ($row = fgetcsv($fh)) {
        [$listingId, $propertyId, $orig] = [$row[0] ?? null, $row[1] ?? null, $row[2] ?? ''];
        $p = Property::find($propertyId);
        if (!$p) continue;
        $p->description = $orig;
        $p->saveQuietly();
        $n++;
    }
    fclose($fh);
    echo "restored {$n} descriptions from {$RESTORE}\n";
    exit(0);
}

// --- tiny lookup tables, loaded once ---------------------------------------
$categories = Category::pluck('name', 'id')->all();      // 1 For Sale, 2 For Rent, 3 Foreclosure
$types      = PropertyType::pluck('name', 'id')->all();  // 1 Condominium .. 4 Commercial

// --- helpers ---------------------------------------------------------------

/**
 * "23500000.00" -> "PHP 23,500,000" (+ " per month" for rentals).
 * Returns null (omit) when the figure can't be stated truthfully:
 *   - Land: price is stored inconsistently (per-sqm vs total) -> omit.
 *   - Rent under PHP 1,000: almost always a data-entry typo (e.g. "20"
 *     meaning 20k) -> omit rather than print "PHP 20 per month".
 */
function fmtPrice($price, int $categoryId, bool $isLand): ?string
{
    $n = (float) $price;
    if ($n <= 0) return null;
    if ($isLand) return null;
    if ($categoryId === 2 && $n < 1000) return null;
    $s = 'PHP ' . number_format($n, 0);
    if ($categoryId === 2) $s .= ' per month';
    return $s;
}

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
    $n = count($parts);
    if ($n === 0) return [null, null];
    if ($n === 1) return [$parts[0], null];
    return [$parts[$n - 2], $parts[$n - 1]]; // city, province
}

function saleWord(int $categoryId): string
{
    return match ($categoryId) {
        2       => 'for rent',
        3       => 'foreclosed property for sale',
        default => 'for sale',
    };
}

/** Build the synthesised intro paragraph. Returns '' if too little data. */
function buildIntro(array $d): string
{
    $subtype   = $d['subtype'] ?: ($d['type'] ?: 'property');
    $type      = $d['type'] ?: 'property';
    $isLand    = strcasecmp($type, 'Land') === 0;
    $sale      = $d['sale'];
    $loc       = $d['city']
        ? ($d['province'] && strcasecmp($d['city'], $d['province']) !== 0
            ? "{$d['city']}, {$d['province']}"
            : $d['city'])
        : ($d['province'] ?: null);

    // Opener — light rotation keyed on id so the corpus isn't templated.
    $where = $loc ? " in {$loc}" : '';
    $openers = [
        ucfirst($subtype) . " {$sale}{$where}.",
        "Discover this {$subtype} {$sale}{$where}.",
        "Now available: a {$subtype} {$sale}{$where}.",
    ];
    $sentences = [$openers[$d['id'] % 3]];

    // Specs sentence (skip beds/baths for Land).
    $specs = [];
    if (!$isLand) {
        if ($d['beds'] > 0)  $specs[] = $d['beds'] . ' ' . ($d['beds'] == 1 ? 'bedroom' : 'bedrooms');
        if ($d['baths'] > 0) $specs[] = $d['baths'] . ' ' . ($d['baths'] == 1 ? 'bathroom' : 'bathrooms');
        if ($d['garage'] > 0) $specs[] = $d['garage'] . '-car garage';
    }
    if ($d['floor'] > 0) $specs[] = rtrim(rtrim(number_format($d['floor'], 2), '0'), '.') . ' sqm floor area';
    if ($d['lot'] > 0)   $specs[] = rtrim(rtrim(number_format($d['lot'], 2), '0'), '.') . ' sqm lot area';

    if ($specs) {
        $lead = (!$isLand && $d['furnishing'] && strcasecmp($d['furnishing'], 'Unfurnished') !== 0)
            ? "This {$d['furnishing']} {$type} features "
            : "It features ";
        $sentences[] = $lead . naturalList($specs) . '.';
    }

    // Price sentence.
    if ($d['price']) $sentences[] = "It is offered at {$d['price']}.";

    // Amenities sentence.
    if ($d['amenities']) {
        $sentences[] = 'Amenities include ' . naturalList($d['amenities']) . '.';
    }

    // Closer with location + CTA (gives length + a real internal CTA).
    // Use the generic "property" — saying "This Commercial"/"This Land"/
    // "This House" for a townhouse all read wrong.
    $here = $loc ? " in {$loc}" : '';
    $sentences[] = "This property{$here} is presented by Filipino Homes — contact us to schedule a viewing or request full details and pricing.";

    return trim(implode(' ', array_filter($sentences)));
}

/** ["a","b","c"] -> "a, b and c". */
function naturalList(array $items): string
{
    $items = array_values(array_filter(array_map('trim', $items)));
    $n = count($items);
    if ($n === 0) return '';
    if ($n === 1) return $items[0];
    if ($n === 2) return $items[0] . ' and ' . $items[1];
    return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
}

/** Normalise the amenities cast (array of strings or {name} objects). */
function normAmenities($raw): array
{
    if (!is_array($raw) || !$raw) return [];
    $out = [];
    foreach ($raw as $a) {
        if (is_string($a) && trim($a) !== '') $out[] = trim($a);
        elseif (is_array($a) && !empty($a['name'])) $out[] = trim($a['name']);
    }
    return array_slice(array_unique($out), 0, 8);
}

// --- main -------------------------------------------------------------------

$query = Listing::where('visibility', 'public')
    ->whereHas('property', function ($q) {
        $q->where('status', 'active')
          ->whereRaw('CHAR_LENGTH(COALESCE(description, "")) < 200');
    })
    ->with(['property.propertyAttribute.subtype', 'property.furnishing']);

$total = (clone $query)->count();
echo ($WRITE ? '*** WRITE MODE ***' : 'DRY-RUN (no DB writes)') . "  candidates: {$total}\n";

$csvPath = storage_path('app/seo-desc-dryrun.csv');
$csv = $WRITE ? null : fopen($csvPath, 'w');
if ($csv) fputcsv($csv, ['listing_id', 'code', 'slug', 'old_len', 'new_len', 'new_description']);

// Rollback backup: in write mode, capture each row's ORIGINAL description
// before we overwrite it. Restore with --restore=<this file>.
$backupPath = storage_path('app/seo-desc-backup-' . date('Ymd-His') . '.csv');
$backup = $WRITE ? fopen($backupPath, 'w') : null;
if ($backup) fputcsv($backup, ['listing_id', 'property_id', 'original_description']);

$done = 0; $skipped = 0; $written = 0;

$query->orderBy('id')->chunkById($LIMIT ? min(200, $LIMIT) : 200, function ($listings) use (
    &$done, &$skipped, &$written, $categories, $types, $WRITE, $csv, $backup, $LIMIT
) {
    foreach ($listings as $listing) {
        if ($LIMIT && $done >= $LIMIT) return false;
        $p = $listing->property;
        if (!$p) { $skipped++; continue; }

        $attr = $p->propertyAttribute;
        $sub  = $attr?->subtype;
        [$city, $province] = parseLocation($p->address);

        $typeName = $sub ? ($types[$sub->property_type_id] ?? null) : null;
        $isLand   = $typeName && strcasecmp($typeName, 'Land') === 0;
        $isCondo  = $typeName && strcasecmp($typeName, 'Condominium') === 0;

        // Sanity caps — drop physically-impossible values rather than print
        // them (e.g. "123 bathrooms", a 114,890,000 sqm lot). 0 = omit.
        $beds   = (int) ($attr->bedroom_count ?? 0);
        $baths  = (int) ($attr->bathroom_count ?? 0);
        $garage = (int) ($attr->garage_count ?? 0);
        $floor  = (float) ($attr->floor_area ?? 0);
        $lot    = (float) ($attr->lot_area ?? 0);
        if ($beds   > 50)        $beds = 0;
        if ($baths  > 50)        $baths = 0;
        if ($garage > 50)        $garage = 0;
        if ($floor  > 50000)     $floor = 0;   // >5 ha of floor: bad data
        if ($lot    > 1000000)   $lot = 0;     // >100 ha: bad data
        if ($isCondo)            $lot = 0;     // condos have no individual lot

        $d = [
            'id'         => $listing->id,
            'type'       => $typeName,
            'subtype'    => $sub?->name,
            'sale'       => saleWord((int) $listing->category_id),
            'city'       => $city,
            'province'   => $province,
            'price'      => fmtPrice($listing->price, (int) $listing->category_id, $isLand),
            'beds'       => $beds,
            'baths'      => $baths,
            'garage'     => $garage,
            'floor'      => $floor,
            'lot'        => $lot,
            'furnishing' => $p->furnishing?->name,
            'amenities'  => normAmenities($p->amenities),
        ];

        $intro = buildIntro($d);

        // Need enough signal to be worth it. If we couldn't build a
        // meaningful intro (no location AND no price AND no specs), skip
        // rather than write filler.
        if (mb_strlen($intro) < 120 || (!$d['city'] && !$d['price'] && $d['beds'] === 0 && $d['floor'] <= 0 && $d['lot'] <= 0)) {
            $skipped++; $done++; continue;
        }

        $existing = trim((string) $p->description);
        $new = $existing !== '' && preg_match('/[A-Za-z0-9]/', $existing)
            ? $intro . "\n\n" . $existing
            : $intro;

        if (mb_strlen($new) < 200) { $skipped++; $done++; continue; }

        if ($WRITE) {
            fputcsv($backup, [$listing->id, $p->id, $existing]); // rollback snapshot first
            $p->description = $new;
            $p->saveQuietly(); // no events -> no audit row, no slug touch; updated_at still bumps
            $written++;
        } else {
            fputcsv($csv, [$listing->id, $listing->code, $listing->slug, mb_strlen($existing), mb_strlen($new), $new]);
        }
        $done++;
    }
});

if ($csv) fclose($csv);
if ($backup) fclose($backup);

echo "processed: {$done}  " . ($WRITE ? "written: {$written}" : "csv rows: " . ($done - $skipped)) . "  skipped: {$skipped}\n";
if (!$WRITE) echo "Review: {$csvPath}\n";
if ($WRITE) echo "Rollback backup: {$backupPath}\n  undo with: php seo_backfill_descriptions.php --restore=" . str_replace(base_path() . '/', '', $backupPath) . "\n";
