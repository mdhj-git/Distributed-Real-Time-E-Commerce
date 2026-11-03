<?php

namespace Modules\Products\Contracts\Exceptions;

use Modules\Shared\Exceptions\ApiException;

/**
 * Lives in Contracts because it crosses the module boundary: the inventory
 * implementation throws it and it passes through Orders to the central renderer.
 * Callers must never put gRPC error text into the message: internal details go
 * to the log with request_id only.
 */
class InventoryUnavailableException extends ApiException
{
    public function __construct(string $message = 'Inventory service is currently unavailable.', array $details = [])
    {
        parent::__construct(503, 'INVENTORY_UNAVAILABLE', $message, $details);
    }
}
