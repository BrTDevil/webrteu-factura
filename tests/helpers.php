<?php

declare(strict_types=1);

use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\InvoiceLineData;
use WeBRTeu\Invoicing\Data\PartyData;
use WeBRTeu\Invoicing\Enums\InvoiceType;

const FACTURA = 'https://efactura.client.ro/api/v1';

function fixtureFile(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
}

/**
 * @param  list<InvoiceLineData>|null  $lines
 */
function invoiceData(InvoiceType $type = InvoiceType::Fiscal, ?PartyData $buyer = null, ?array $lines = null): InvoiceData
{
    return new InvoiceData(
        type: $type,
        buyer: $buyer ?? new PartyData('Client SRL', 'RO987654', 'J12/3/2021', 'Str. Mare 2', 'Cluj-Napoca', 'Cluj', email: 'client@srl.ro', phone: '0722000000'),
        lines: $lines ?? [new InvoiceLineData('Servicii IT', 12100, 10, unit: 'ora')],
        reference: 'CMD-123',
        issueDate: new DateTimeImmutable('2026-11-04'),
    );
}
