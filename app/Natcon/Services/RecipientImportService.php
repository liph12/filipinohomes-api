<?php

namespace App\Natcon\Services;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use App\Natcon\Models\Suppression;
use App\Natcon\Services\ProvidesRecipientAttributes;
use Illuminate\Support\Str;

/**
 * Turns a RecipientSource into natcon_recipients rows.
 *
 * Deliberately does NOT call Leuterio Realty. LR rate-limits to 60 req/min from
 * a single IP, so 800 inline lookups would either take 25 minutes inside one HTTP
 * request or get most of the list 429'd and silently recorded as "not an awardee".
 * Rows land with lr_lookup_status='pending' and the hourly natcon:hydrate-awardees
 * command fills them in at a safe rate. Import is fast; enrichment is patient.
 */
final class RecipientImportService
{
    /**
     * @param bool $updateExisting Refresh LR-owned fields on rows that already
     *        exist, instead of counting them as skipped. Only meaningful when the
     *        source implements ProvidesRecipientAttributes — a paste of bare
     *        emails has nothing to update WITH.
     *
     * @return array{
     *   batch_id:string, created:int, updated:int, skipped:int, suppressed:int,
     *   invalid:array<int,array{email:string,reason:string}>
     * }
     */
    public function import(
        NatconEvent $event,
        RecipientSource $source,
        ?int $userId = null,
        bool $dryRun = false,
        bool $updateExisting = false,
    ): array {
        $batchId    = (string) Str::uuid();
        $created    = 0;
        $updated    = 0;
        $skipped    = 0;
        $suppressed = 0;
        $invalid    = [];

        // Sources opt in to carrying more than an address. ManualListSource does
        // not implement it and is entirely unaffected by any of this.
        $enriched = $source instanceof ProvidesRecipientAttributes ? $source : null;

        // Within-batch dedupe. The unique index on (event, email) catches the rest,
        // but a paste of 400 rows routinely contains the same person twice and we
        // want that reported as "skipped", not as a swallowed constraint violation.
        $seen = [];

        // One DNS lookup per distinct domain, not per address.
        $mxCache = [];

        // Existing rows for this event, so a re-import reports honestly instead of
        // firing 400 failing inserts.
        // email => id, so an existing row can be UPDATED rather than only
        // recognised. withTrashed because a soft-deleted row still occupies the
        // unique (event, email) index — but it is never updated, see below.
        $existing = Recipient::withTrashed()
            ->where('natcon_event_id', $event->id)
            ->pluck('id', 'email');

        foreach ($source->emails() as $raw) {
            $email = strtolower(trim((string) $raw));

            if ($email === '') {
                continue;
            }

            if (isset($seen[$email])) {
                $skipped++;
                continue;
            }
            $seen[$email] = true;

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
                $invalid[] = ['email' => $raw, 'reason' => 'Not a valid email address'];
                continue;
            }

            // MX pre-validation. This is the cheapest single thing that protects
            // sender reputation: a typo'd domain is a guaranteed hard bounce, and
            // AWS SES puts an account under review above 5% bounces and PAUSES
            // SENDING above 10% — which would take login OTPs down with it.
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if (! array_key_exists($domain, $mxCache)) {
                $mxCache[$domain] = $this->domainAcceptsMail($domain);
            }
            if (! $mxCache[$domain]) {
                $invalid[] = ['email' => $raw, 'reason' => "Domain '{$domain}' has no mail server"];
                continue;
            }

            if (Suppression::suppresses($email)) {
                $suppressed++;
                continue;
            }

            if ($existing->has($email)) {
                $attributes = $enriched?->attributesFor($email) ?? [];

                if (! $updateExisting || ! $attributes) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $updated++;
                    continue;
                }

                $row = Recipient::find($existing->get($email));

                // Soft-deleted rows are left alone. They were removed on purpose,
                // and quietly refreshing one would make it look live again in
                // every admin query that forgets withTrashed().
                if (! $row) {
                    $skipped++;
                    continue;
                }

                // ⚠️ ONLY the LR-owned fields. Not email, not status, not
                //    responded_at, not photos, not notes, not the
                //    require-new-photo flag. A re-sync must never undo a decision
                //    a person made — an awardee who already sent three photos
                //    must still have them afterwards.
                $row->auditSource      = 'lr_qualifiers_sync';
                $row->auditDescription = 'Refreshed from the LR qualifiers list';
                $row->forceFill($attributes)->save();

                $updated++;
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            Recipient::create([
                'natcon_event_id'   => $event->id,
                'email'             => $email,
                'source'            => $source->label(),
                'imported_batch_id' => $batchId,
                'created_by'        => $userId,
                'status'            => Recipient::STATUS_PENDING,
                'lr_lookup_status'  => Recipient::LR_PENDING,
            ] + ($enriched?->attributesFor($email) ?? []));

            // Value is unused for a row created in this run — nothing later in
            // the loop can collide with it, because `seen` already caught that.
            $existing->put($email, 0);
            $created++;
        }

        if (! $dryRun && $created > 0) {
            $event->forceFill([
                'recipients_count' => Recipient::where('natcon_event_id', $event->id)->count(),
            ])->save();
        }

        return [
            'batch_id'   => $batchId,
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'suppressed' => $suppressed,
            'invalid'    => $invalid,
        ];
    }

    /**
     * Does this domain have somewhere to deliver mail?
     *
     * MX first, then A/AAAA — RFC 5321 §5.1 says a host with an address record but
     * no MX is still a valid mail destination, and a few small PH providers rely on
     * exactly that. Fails OPEN on DNS trouble: a resolver hiccup must never silently
     * drop a real awardee from the campaign.
     */
    private function domainAcceptsMail(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        try {
            if (checkdnsrr($domain, 'MX')) {
                return true;
            }

            return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
        } catch (\Throwable $e) {
            return true;
        }
    }
}
