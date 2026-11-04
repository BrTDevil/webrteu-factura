<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Exceptions;

/**
 * HTTP 429: serverul nu a creat nimic, deci cererea poate fi reluată după $retryAfter secunde.
 */
class RateLimitedException extends FacturaException
{
    public function __construct(public readonly int $retryAfter, string $message)
    {
        parent::__construct($message);
    }
}
