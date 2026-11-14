<?php

namespace App\Erp;

use RuntimeException;

/**
 * A failed ERP call. retryable = worth trying again later (rate limit, outage, network).
 * errorCode = the ERP's own error code when it sent one (Fortnox: ErrorInformation.Code).
 */
class ErpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $status = 0,
        public readonly ?int $errorCode = null,
    ) {
        parent::__construct($message, $status);
    }
}
