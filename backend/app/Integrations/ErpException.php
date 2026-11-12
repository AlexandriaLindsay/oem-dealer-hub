<?php

namespace App\Integrations;

use RuntimeException;

/** Something went wrong talking to their ERP, in a sentence the Integrations page shows as is. */
class ErpException extends RuntimeException
{
    public function __construct(string $message, public ?int $status = null)
    {
        parent::__construct($message);
    }

    /** Worth trying again later: no answer, too many requests, or their ERP's own error. */
    public function retryable(): bool
    {
        return $this->status === null || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
