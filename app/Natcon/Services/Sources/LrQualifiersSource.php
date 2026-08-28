<?php

namespace App\Natcon\Services\Sources;

use App\Natcon\Models\Recipient;
use App\Natcon\Services\ProvidesRecipientAttributes;
use App\Natcon\Services\RecipientSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Leuterio Realty's NATCON qualifiers list.
 *
 * This is the bulk endpoint the campaign was built to wait for — see the
 * RecipientSource docblock. It replaces LrBulkListSource, which existed only to
 * explain that no such endpoint had been published yet.
 *
 * ─── Shape, measured against the live response rather than assumed ──────────
 *
 *   285 records · 288KB · ~1.7s   → one request, decoded once. No streaming.
 *   member[]                      → always exactly one entry, never empty
 *   member[0].email               → present on all 285, all distinct
 *   sales_team_member             → never null; 31 distinct teams
 *   natcon_confirmation           → NULL on 8 records. Read defensively.
 *   completename                  → 116 of 285 are couples; 66 have double spaces
 *
 * ⚠️ natcon_confirmation.logs is a JSON *string*, not an array. We have no use
 *    for it, so it is left alone rather than half-decoded into the payload where
 *    it would look structured and not be.
 */
final class LrQualifiersSource implements RecipientSource, ProvidesRecipientAttributes
{
    /** @var array<string,array<string,mixed>> email => qualifier record */
    private array $byEmail;

    /**
     * @param array<int,array<string,mixed>>|null $records Pre-fetched, for tests.
     */
    public function __construct(?array $records = null)
    {
        $this->byEmail = $this->index($records ?? $this->fetch());
    }

    public function label(): string
    {
        return 'lr_qualifiers';
    }

    /** @return iterable<string> */
    public function emails(): iterable
    {
        foreach (array_keys($this->byEmail) as $email) {
            yield $email;
        }
    }

    /** How many qualifiers the list actually held — shown in the preview. */
    public function count(): int
    {
        return count($this->byEmail);
    }

    /**
     * @return array<string,mixed>
     */
    public function attributesFor(string $email): array
    {
        $record = $this->byEmail[strtolower(trim($email))] ?? null;

        if (! $record) {
            return [];
        }

        $member = $record['member'][0] ?? [];
        $team   = $record['sales_team_member']['sales_team'] ?? [];

        return [
            // Whole, never split: 116 of 285 are couples and any split is wrong.
            // tidyName collapses the double spaces and un-shouts the 3 all-caps
            // names without touching the 282 that are already correct.
            'display_name' => Recipient::tidyName($member['completename'] ?? null),

            'team' => isset($team['teamname'])
                ? mb_substr(trim((string) $team['teamname']), 0, 191)
                : null,

            // LR's "state" is a Philippine province ("Davao del Sur", "Cebu").
            // Kept under the upstream name so the column, the API field and
            // the LR key all match; only the admin UI relabels it "Province".
            'state' => $this->str($member['state'] ?? null, 191),

            'total_sales' => isset($record['totalsales']) && is_numeric($record['totalsales'])
                ? round((float) $record['totalsales'], 2)
                : null,

            // ⚠️ null on 8 of 285. `?? null` rather than assuming the object.
            'lr_confirmation_status' => $this->str(
                $member['natcon_confirmation']['status'] ?? null,
                16,
            ),

            'lr_awardee_id' => isset($record['agentid']) ? (int) $record['agentid'] : null,

            // The rest — team id, logo, isleader, datejoined — kept whole so none
            // of it needs a column of its own.
            'qualifier_payload' => $record,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch(): array
    {
        $config = (array) config('natcon.qualifiers');

        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->get((string) ($config['url'] ?? ''), [
                'all'   => 'true',
                // LR's own pagination lever. Their list is a few hundred rows, so
                // this simply says "no page limit" rather than expressing a real
                // expectation about size.
                'limit' => 43000000,
                // The qualifying sales window. In config, not literals, because
                // it WILL be different for 2027 and should move without a deploy.
                'from'      => (string) ($config['from'] ?? ''),
                'lastdateX' => (string) ($config['lastdate_x'] ?? ''),
                'lastdateY' => (string) ($config['lastdate_y'] ?? ''),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Leuterio Realty's qualifiers list returned HTTP {$response->status()}. Nothing was imported."
            );
        }

        $records = $response->json();

        // A bare array is the documented shape. Anything else — an error object,
        // an HTML error page decoded to null — must stop here rather than import
        // zero rows and report success.
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException(
                "Leuterio Realty's qualifiers list came back in an unexpected shape. Nothing was imported."
            );
        }

        return $records;
    }

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @return array<string,array<string,mixed>>
     */
    private function index(array $records): array
    {
        $byEmail = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $email = strtolower(trim((string) ($record['member'][0]['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            // Last one wins. The live list has no duplicates, but if LR ever ship
            // one, the later record is the more recent state of that agent.
            $byEmail[$email] = $record;
        }

        return $byEmail;
    }

    private function str($value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }
}
