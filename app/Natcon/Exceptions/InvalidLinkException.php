<?php

namespace App\Natcon\Exceptions;

use RuntimeException;

/**
 * The token doesn't resolve to a live recipient — unknown, rotated, the recipient
 * was soft-deleted, or the event was deactivated.
 *
 * All of those deliberately produce the SAME 404 body. Distinguishing them would
 * turn the endpoint into an oracle telling anyone with a token guess whether a
 * given awardee exists.
 */
class InvalidLinkException extends RuntimeException
{
    public function __construct(string $message = 'Invalid link.')
    {
        parent::__construct($message);
    }
}
