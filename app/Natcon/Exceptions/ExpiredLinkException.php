<?php

namespace App\Natcon\Exceptions;

use RuntimeException;

/**
 * The token was real but has aged out (deadline + grace period).
 *
 * Separate from InvalidLinkException so the controller can answer 410 Gone
 * instead of 404, which lets the frontend show "your link expired, here's a new
 * one" rather than a dead end. That distinction is safe to leak: the holder of an
 * expired token already had a valid one.
 */
class ExpiredLinkException extends RuntimeException
{
    public function __construct(string $message = 'This link has expired.')
    {
        parent::__construct($message);
    }
}
