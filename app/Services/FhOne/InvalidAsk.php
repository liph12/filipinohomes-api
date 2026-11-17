<?php

namespace App\Services\FhOne;

use InvalidArgumentException;

/**
 * FH One asked for something that can't be answered as asked: a filter the
 * metric doesn't take, or a split it can't make. The caller's mistake, so it
 * becomes a 422 on the named field instead of a crash.
 */
final class InvalidAsk extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
