<?php

namespace App\Services\McpOAuth;

use RuntimeException;

/**
 * Something wrong with a sign-in request, said the way OAuth says it (RFC 6749
 * section 5.2): an error code and a sentence for a person. The sign-in routes
 * answer with it, because they live at the root of the site where Laravel
 * would otherwise answer with a page.
 */
final class OAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        /** Whether the return address is known to be a good one, so the person can be sent back to it with this error. */
        public readonly bool $redirectable = false,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
