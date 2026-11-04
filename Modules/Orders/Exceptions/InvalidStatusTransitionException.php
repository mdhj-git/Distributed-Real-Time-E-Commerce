<?php

namespace Modules\Orders\Exceptions;

use Modules\Shared\Exceptions\ApiException;

class InvalidStatusTransitionException extends ApiException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            422,
            'INVALID_STATUS_TRANSITION',
            "Cannot change the order status from {$from} to {$to}.",
            ['from' => $from, 'to' => $to],
        );
    }
}
