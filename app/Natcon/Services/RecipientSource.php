<?php

namespace App\Natcon\Services;

/**
 * Where a batch of awardee emails comes from.
 *
 * This is the seam that lets the recipient list change origin without touching
 * the import pipeline, and it did its job: the list started out pasted in by
 * hand, and when Leuterio Realty published their qualifiers endpoint the only
 * new code was LrQualifiersSource. The controller, the dedupe, the MX
 * pre-validation, the batch bookkeeping and the LR hydration all stayed put.
 *
 * One thing the original design did not anticipate: a bulk list that carries
 * more than addresses. A source with names, teams and sales figures also
 * implements ProvidesRecipientAttributes — see that interface for why it is
 * separate rather than folded in here.
 */
interface RecipientSource
{
    /**
     * Raw, unnormalized email strings. Yielded rather than returned so a future
     * bulk source can stream a large list instead of materializing it.
     *
     * @return iterable<string>
     */
    public function emails(): iterable;

    /** Stored on natcon_recipients.source — manual | paste | csv | lr_bulk. */
    public function label(): string;
}
