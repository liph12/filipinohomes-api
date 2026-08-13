<?php

namespace App\Natcon\Services\Sources;

use App\Natcon\Services\RecipientSource;
use RuntimeException;

/**
 * Placeholder for Leuterio Realty's bulk awardee list.
 *
 * As of 2026-08-12 no such endpoint exists — the only NATCON route LR expose is
 * get-awardee/{email}, and every plausible list variant (get-awardees, awardees,
 * teams, get-teams) returns a real 404. Recipients are entered by hand until
 * they ship one.
 *
 * When they do, fill in emails() and nothing else changes: the import service,
 * the dedupe, the MX pre-validation, the batch bookkeeping and the LR hydration
 * are all already written against this interface.
 */
final class LrBulkListSource implements RecipientSource
{
    public function __construct(private ?string $endpoint = null) {}

    public function emails(): iterable
    {
        throw new RuntimeException(
            'Leuterio Realty has not published a bulk awardee list endpoint yet. '
            . 'Import the list manually, or fill in LrBulkListSource::emails() once '
            . 'the endpoint URL is known.'
        );
    }

    public function label(): string
    {
        return 'lr_bulk';
    }
}
