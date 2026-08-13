<?php

namespace App\Natcon\Services\Sources;

use App\Natcon\Services\RecipientSource;

/**
 * Emails typed or pasted into the admin.
 *
 * Accepts a raw blob as well as an array, because the realistic input is someone
 * pasting a column out of Excel — which arrives with CRLFs, smart quotes, stray
 * semicolons and the occasional "Name <email>" pair.
 */
final class ManualListSource implements RecipientSource
{
    /** @var array<int,string> */
    private array $emails;

    public function __construct(array $emails, private string $label = 'manual')
    {
        $this->emails = $emails;
    }

    /**
     * Split a pasted blob into candidate addresses.
     *
     * Splits on newlines, commas and semicolons — NOT on whitespace. That matters:
     * a line like "Juan Dela Cruz <juan@x.com>" must yield one address, not four
     * tokens of which three then get reported to the admin as invalid emails. The
     * preflight report is only useful if everything it flags is a real problem.
     */
    public static function fromText(string $text, string $label = 'paste'): self
    {
        $chunks = preg_split('/[\r\n,;]+/u', $text) ?: [];

        return new self(array_values(array_filter(array_map('trim', $chunks))), $label);
    }

    public function emails(): iterable
    {
        foreach ($this->emails as $raw) {
            $value = $this->extract((string) $raw);

            if ($value !== '') {
                yield $value;
            }
        }
    }

    /**
     * Pull one address out of a chunk that may carry a display name around it.
     * Anything with no recognizable address falls through unchanged so the import
     * service still reports it as invalid rather than swallowing it.
     */
    private function extract(string $chunk): string
    {
        // Excel and Word wrap pasted cells in curly quotes often enough to matter.
        $chunk = trim($chunk, "\"'“”‘’ \t\r\n");

        // "Eutequio Rallos <euteq@example.com>"
        if (preg_match('/<([^>]+)>/', $chunk, $m)) {
            return trim($m[1]);
        }

        // "Eutequio Rallos euteq@example.com" — take the token holding the @.
        if (str_contains($chunk, ' ')) {
            foreach (preg_split('/\s+/u', $chunk) ?: [] as $token) {
                if (str_contains($token, '@')) {
                    return trim($token, "\"'“”‘’<>");
                }
            }
        }

        return $chunk;
    }

    public function label(): string
    {
        return $this->label;
    }
}
