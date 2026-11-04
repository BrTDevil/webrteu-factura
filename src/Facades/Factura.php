<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Facades;

use Illuminate\Support\Facades\Facade;
use WeBRTeu\Factura\FacturaProvider;

/**
 * @method static \WeBRTeu\Factura\Data\AnafStatusData anafStatus(\WeBRTeu\Invoicing\Models\Invoice $invoice)
 * @method static string pdf(\WeBRTeu\Invoicing\Models\Invoice $invoice)
 *
 * @see FacturaProvider
 */
class Factura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FacturaProvider::class;
    }
}
