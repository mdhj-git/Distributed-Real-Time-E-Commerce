<?php

namespace Modules\Shared\Exceptions;

use Exception;

/**
 * Base class for every business exception. The central renderer needs no knowledge of any module.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
