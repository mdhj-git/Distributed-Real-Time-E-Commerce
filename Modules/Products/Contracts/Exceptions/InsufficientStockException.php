<?php

namespace Modules\Products\Contracts\Exceptions;

use Modules\Shared\Exceptions\ApiException;

/**
 * Lives in Contracts because it crosses the module boundary: the inventory
 * implementation throws it and it passes through Orders to the central renderer.
 */
class InsufficientStockException extends ApiException
{
    public function __construct(string $message = 'Insufficient stock.', array $details = [])
    {
        parent::__construct(409, 'INSUFFICIENT_STOCK', $message, $details);
    }
}
