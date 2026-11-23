<?php

namespace App\Mcp\Support;

use RuntimeException;

/**
 * What leaves the MCP server, last check before it does.
 *
 * The numbers and records come from FH One's own services, which already leave
 * out every phone number and email address. This is the second lock on that
 * door, so a mistake in one tool cannot become a leak: fields that carry
 * contact details or secrets are dropped wherever they sit, an email address
 * or a Philippine mobile number written inside a text is blanked, and an
 * answer too big to be useful is refused rather than sent.
 */
final class McpOutput
{
    /** Field names that never go out, however deep they are. */
    private const NEVER = '/(^|_)(e?mail|phone|mobile|msisdn|whats_?app|viber|password|passwd|secret|token|otp|api_?key|birth_?date|birthday)(_|$)/i';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * +63 9xx xxx xxxx, 09xx xxx xxxx, 9xxxxxxxxx, with spaces or dashes between the groups. Not a longer run of
     * digits and not one with decimals after it, so a price like 9500000000.50 is left alone; a number that ends a
     * sentence ("call 0917 123 4567.") is still caught.
     */
    private const MOBILE = '/(?<!\d)(?<!\d\.)(?:\+?63[\s\-]?|0)?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)(?!\.\d)/';

    /** @return mixed the same thing with those fields and details taken out */
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::NEVER, $key) === 1) {
                    continue;
                }

                $clean[$key] = self::clean($item);
            }

            return $clean;
        }

        if (is_string($value)) {
            return preg_replace([self::EMAIL, self::MOBILE], ['[email removed]', '[number removed]'], $value) ?? $value;
        }

        return $value;
    }

    /**
     * The JSON that goes to Claude.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException when it is bigger than config('fhmcp.max_result_bytes')
     */
    public static function json(array $payload): string
    {
        $json = json_encode(self::clean($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $max = (int) config('fhmcp.max_result_bytes', 60000);

        if (strlen($json) > $max) {
            throw new RuntimeException('That answer is too big to send ('.number_format(strlen($json)).' bytes). Ask for fewer rows, a shorter period or a narrower filter.');
        }

        return $json;
    }
}
