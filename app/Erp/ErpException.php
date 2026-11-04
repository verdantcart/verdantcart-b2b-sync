<?php

namespace App\Erp;

use RuntimeException;

/** A failed ERP call. retryable = worth trying again later (rate limit, outage, network). */
class ErpException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
