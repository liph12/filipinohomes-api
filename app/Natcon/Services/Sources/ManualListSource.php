<?php

namespace App\Natcon\Services\Sources;

use App\Natcon\Models\Recipient;
use App\Natcon\Services\ProvidesRecipientAttributes;
use App\Natcon\Services\RecipientSource;

/**
 * Emails typed or pasted into the admin.
 *
 * Accepts a raw blob as well as an array, because the realistic input is someone
 * pasting a column out of Excel — which arrives with CRLFs, smart quotes, stray
 * semicolons and the occasional "Name <email>" pair.
 *
 * ─── Why this carries names now ──────────────────────────────────────────────
 *
 * It used to yield addresses and drop everything around them, on the assumption
 * that names arrive later from Leuterio Realty. That assumption fails for the
 * people this dialog exists to add: Global Partners and FHI Global are NOT on
 * LR's roster, so get-awardee finds nothing, first_name/last_name stay empty,
 * and Recipient::displayName() falls all the way through to the email address —
 * which v2 then prints on the invitation card in 128px gradient gold.
 *
 * So a pasted name is kept when there is one. It is stored as `display_name`,
 * the same column LR's qualifier list writes, which takes precedence over
 * first/last name in displayName().
 */
final class ManualListSource implements ProvidesRecipientAttributes, RecipientSource
{
    /** @var array<int,string> */
    private array $emails;

    /** email => the name pasted beside it. Filled as emails() walks the list. */
    private array $names = [];

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
     *
     * ⚠️ Nor on the pipe, for the same reason: "Michelle Q. Guinto | m@x.com" is
     *    one person, and splitting there would file the name as a broken address
     *    AND lose which email it belonged to.
     */
    public static function fromText(string $text, string $label = 'paste'): self
    {
        $chunks = preg_split('/[\r\n,;]+/u', $text) ?: [];

        return new self(array_values(array_filter(array_map('trim', $chunks))), $label);
    }

    public function emails(): iterable
    {
        foreach ($this->emails as $raw) {
            [$value, $name] = $this->parse((string) $raw);

            if ($value === '') {
                continue;
            }

            if ($name !== '') {
                $this->names[strtolower($value)] = $name;
            }

            yield $value;
        }
    }

    /**
     * The pasted name, if this chunk had one.
     *
     * Only ever `display_name`, and only when a name was actually typed — an
     * empty string here would defeat displayName()'s fallback chain and leave
     * the row with no name at all rather than with its email.
     *
     * @return array<string,mixed>
     */
    public function attributesFor(string $email): array
    {
        $name = $this->names[strtolower(trim($email))] ?? '';

        return $name !== '' ? ['display_name' => $name] : [];
    }

    /**
     * Pull the address and the name out of one chunk.
     *
     * Handles every shape these lists actually arrive in:
     *   "Juan Dela Cruz <juan@x.com>"   an email client's copy
     *   "Juan Dela Cruz | juan@x.com"   the events team's own lists
     *   "Juan Dela Cruz\tjuan@x.com"    two columns out of Excel or Word
     *   "juan@x.com"                    a bare address
     *
     * Anything with no recognizable address falls through unchanged so the import
     * service still reports it as invalid rather than swallowing it.
     *
     * @return array{0:string,1:string} [email, name]
     */
    private function parse(string $chunk): array
    {
        // Excel and Word wrap pasted cells in curly quotes often enough to matter.
        $chunk = trim($chunk, "\"'“”‘’| \t\r\n");

        // "Eutequio Rallos <euteq@example.com>"
        if (preg_match('/^(.*?)<([^>]+)>/u', $chunk, $m)) {
            return [trim($m[2]), $this->tidy($m[1])];
        }

        // Separated by whitespace or a pipe — take the token holding the @ and
        // treat whatever is left, in order, as the name. preg_match rather than
        // str_contains(' '): a single-word name pasted from a spreadsheet is
        // separated by a TAB and has no space anywhere.
        if (preg_match('/[\s|]/u', $chunk)) {
            $tokens = preg_split('/[\s|]+/u', $chunk) ?: [];
            $email  = '';
            $rest   = [];

            foreach ($tokens as $token) {
                if ($email === '' && str_contains($token, '@')) {
                    $email = trim($token, "\"'“”‘’<>|");

                    continue;
                }

                $rest[] = $token;
            }

            if ($email !== '') {
                return [$email, $this->tidy(implode(' ', $rest))];
            }
        }

        return [$chunk, ''];
    }

    /**
     * A pasted name, cleaned up: separators stripped, spacing collapsed, and
     * ALL-CAPS un-shouted by the same helper the LR sync uses. Rejects anything
     * with no letter in it, so a stray "-" or "1." never becomes someone's name.
     */
    private function tidy(string $name): string
    {
        $name = trim(str_replace(['|', '<', '>'], ' ', $name), " \t\"'“”‘’,.-");

        if (! preg_match('/\p{L}/u', $name)) {
            return '';
        }

        return (string) Recipient::tidyName($name);
    }

    public function label(): string
    {
        return $this->label;
    }
}
