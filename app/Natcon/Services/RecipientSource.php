<?php

namespace App\Natcon\Services;

/**
 * Where a batch of awardee emails comes from.
 *
 * This is the seam that lets the recipient list change origin without touching
 * the import pipeline. Today the list is entered by hand; Leuterio Realty are
 * expected to expose a bulk awardee endpoint later. When they do, only
 * LrBulkListSource::emails() gets filled in — the controller, the dedupe, the
 * MX pre-validation, the batch bookkeeping and the LR hydration all stay put.
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
