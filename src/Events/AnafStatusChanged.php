<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Events;

use WeBRTeu\Factura\Enums\AnafStatus;
use WeBRTeu\Invoicing\Models\Invoice;

class AnafStatusChanged
{
    public function __construct(public Invoice $invoice, public AnafStatus $from, public AnafStatus $to) {}
}
