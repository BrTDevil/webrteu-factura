<?php

declare(strict_types=1);

use WeBRTeu\Invoicing\Exceptions\InvoicingException;

arch('nu folosește funcții de debug')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('toate fișierele au strict types')
    ->expect('WeBRTeu\Factura')
    ->toUseStrictTypes();

arch('fără Guzzle direct: HTTP doar prin Http::')
    ->expect('WeBRTeu\Factura')
    ->not->toUse('GuzzleHttp');

arch('excepțiile extind InvoicingException')
    ->expect('WeBRTeu\Factura\Exceptions')
    ->toExtend(InvoicingException::class);
