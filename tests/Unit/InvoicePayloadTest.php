<?php

declare(strict_types=1);

use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Requests\InvoicePayload;
use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\InvoiceLineData;
use WeBRTeu\Invoicing\Data\PartyData;
use WeBRTeu\Invoicing\Enums\InvoiceType;

it('sends each line with its VAT rate and the price as in the order, plus the order reference as external_id', function (): void {
    $data = invoiceData(lines: [
        new InvoiceLineData('Tricou', 5000, 2),
        new InvoiceLineData('Carte', 3000, vatRate: 11),
        new InvoiceLineData('Consultanță', 10000, 1.5, unit: 'ora', priceIncludesVat: false),
    ])->withDates(new DateTimeImmutable('2026-11-04'), new DateTimeImmutable('2026-11-19'));

    expect(InvoicePayload::make($data, 'Magazin', true, '6201'))->toBe([
        'app_name' => 'Magazin',
        'client' => [
            'name' => 'Client SRL', 'cif' => 'RO987654', 'reg_com' => 'J12/3/2021', 'address' => 'Str. Mare 2', 'city' => 'Cluj-Napoca',
            'county' => 'Cluj', 'country' => 'RO', 'email' => 'client@srl.ro', 'phone' => '0722000000', 'is_company' => true,
        ],
        'invoice' => ['invoice_date' => '2026-11-04', 'due_date' => '2026-11-19', 'currency' => 'RON', 'vat_rate' => 21, 'external_id' => 'CMD-123', 'caen' => '6201', 'is_test' => true],
        'lines' => [
            ['description' => 'Tricou', 'unit' => 'buc', 'quantity' => 2.0, 'price' => 50.0, 'vat_rate' => 21, 'price_includes_vat' => true],
            ['description' => 'Carte', 'unit' => 'buc', 'quantity' => 1.0, 'price' => 30.0, 'vat_rate' => 11, 'price_includes_vat' => true],
            ['description' => 'Consultanță', 'unit' => 'ora', 'quantity' => 1.5, 'price' => 100.0, 'vat_rate' => 21, 'price_includes_vat' => false],
        ],
    ]);
});

it('omits optional values and keeps is_test false', function (): void {
    $data = new InvoiceData(InvoiceType::Fiscal, new PartyData('Ion Popescu', email: 'ion@example.com'), [new InvoiceLineData('A', 100)], issueDate: new DateTimeImmutable('2026-11-04'));
    $payload = InvoicePayload::make($data, test: false);

    expect($payload)->not->toHaveKey('app_name')
        ->and($payload['client'])->toBe(['name' => 'Ion Popescu', 'country' => 'RO', 'email' => 'ion@example.com', 'is_company' => false])
        ->and($payload['invoice'])->toBe(['invoice_date' => '2026-11-04', 'currency' => 'RON', 'vat_rate' => 21, 'is_test' => false]);
});

it('uses the most common rate as the general rate', function (): void {
    $data = invoiceData(lines: [new InvoiceLineData('A', 100, vatRate: 11), new InvoiceLineData('B', 100, vatRate: 11), new InvoiceLineData('C', 100)]);

    expect(InvoicePayload::generalRate($data))->toBe(11);
});

it('talks to an older server with one rate, net prices and no external_id', function (): void {
    $payload = InvoicePayload::make(invoiceData(lines: [new InvoiceLineData('Tricou', 5000)]), legacy: true);

    expect($payload['invoice'])->not->toHaveKey('external_id')
        ->and($payload['lines'][0])->toBe(['description' => 'Tricou', 'unit' => 'buc', 'quantity' => 1.0, 'price' => 41.3223, 'vat_rate' => 21]);
});

it('converts prices to net lei with 4 decimals for older servers', function (InvoiceLineData $line, float $expected): void {
    expect(InvoicePayload::netUnitPrice($line))->toBe($expected);
})->with([
    'cu TVA inclus' => [new InvoiceLineData('A', 5000), 41.3223],
    'fără TVA' => [new InvoiceLineData('B', 4999, priceIncludesVat: false), 49.99],
    'cota 0' => [new InvoiceLineData('C', 1234, vatRate: 0), 12.34],
]);

it('refuses what the server cannot handle', function (InvoiceData $data, bool $legacy, string $message): void {
    expect(fn (): array => InvoicePayload::make($data, legacy: $legacy))->toThrow(FacturaException::class, $message);
})->with([
    'cote diferite pe server vechi' => [fn (): InvoiceData => invoiceData(lines: [new InvoiceLineData('A', 100), new InvoiceLineData('B', 100, vatRate: 11)]), true, 'versiune veche'],
    'fără email' => [fn (): InvoiceData => invoiceData(buyer: new PartyData('X')), false, 'Emailul clientului'],
    'email gol' => [fn (): InvoiceData => invoiceData(buyer: new PartyData('X', email: '')), false, 'Emailul clientului'],
    'fără dată' => [fn (): InvoiceData => new InvoiceData(InvoiceType::Fiscal, new PartyData('X', email: 'x@y.ro'), [new InvoiceLineData('A', 100)]), false, 'data facturii'],
    'referință prea lungă' => [fn (): InvoiceData => new InvoiceData(InvoiceType::Fiscal, new PartyData('X', email: 'x@y.ro'), [new InvoiceLineData('A', 100)], reference: str_repeat('x', 101), issueDate: new DateTimeImmutable('2026-11-04')), false, '100 de caractere'],
]);

it('accepts several VAT rates on a current server', function (): void {
    $payload = InvoicePayload::make(invoiceData(lines: [new InvoiceLineData('A', 100), new InvoiceLineData('B', 100, vatRate: 11)]));

    expect(array_column($payload['lines'], 'vat_rate'))->toBe([21, 11]);
});
