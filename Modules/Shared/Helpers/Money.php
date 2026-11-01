<?php

namespace Modules\Shared\Helpers;

use InvalidArgumentException;

/**
 * Money arithmetic with bcmath, never float: (int) (19.99 * 100) gives 1998.
 */
class Money
{
    public static function toCents(string $decimal): int
    {
        if (preg_match('/^-?\d+(\.\d{1,2})?$/D', $decimal) !== 1) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        return (int) bcmul($decimal, '100', 0);
    }

    public static function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }
}
