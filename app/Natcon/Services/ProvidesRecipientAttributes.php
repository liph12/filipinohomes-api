<?php

namespace App\Natcon\Services;

/**
 * A RecipientSource that knows more about a person than their email address.
 *
 * ─── Why this is a second interface rather than a wider first one ───────────
 *
 * RecipientSource::emails() yields email strings and nothing else, because the
 * design assumed any bulk list would be addresses and that everything else would
 * be filled in afterwards by get-awardee/{email}.
 *
 * LR's qualifiers list broke that assumption in a good way: it already carries
 * the display name, the team, the sales total and the sales-confirmation status.
 * Discarding those and re-fetching them one address at a time would be 285
 * lookups at 30/min — about ten minutes — to recover data we were handed in a
 * single 288KB response.
 *
 * Widening RecipientSource itself would force ManualListSource (and any future
 * CSV source) to implement a method it has no answer for. So sources opt in, and
 * RecipientImportService checks with instanceof. A source that doesn't implement
 * this behaves exactly as it always did.
 *
 * Note this does NOT replace get-awardee: photos come only from there, and the
 * qualifiers payload has none. The two are complementary — this fills names
 * instantly, natcon:hydrate-awardees fills photos patiently.
 */
interface ProvidesRecipientAttributes
{
    /**
     * Columns to set on natcon_recipients for one email, or [] if unknown.
     *
     * Only ever LR-owned fields. Nothing here may touch email, status,
     * responded_at, photos, notes or the require-new-photo flag — a re-sync must
     * not undo a decision a person made.
     *
     * @return array<string,mixed>
     */
    public function attributesFor(string $email): array;
}
