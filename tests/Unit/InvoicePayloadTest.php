<?php

declare(strict_types=1);

use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Requests\InvoicePayload;
use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\InvoiceLineData;
use WeBRTeu\Invoicing\Data\PartyData;
use WeBRTeu\Invoicing\Enums\InvoiceType;

it('builds the request with net prices, one VAT rate and the client', function (): void {
    $data = invoiceData()->withDates(new DateTimeImmutable('2026-11-04'), new DateTimeImmutable('2026-11-19'));

    expect(InvoicePayload::make($data, 'Magazin', true, '6201'))->toBe([
        'app_name' => 'Magazin',
        'client' => [
            'name' => 'Client SRL', 'cif' => 'RO987654', 'reg_com' => 'J12/3/2021', 'address' => 'Str. Mare 2', 'city' => 'Cluj-Napoca',
            'county' => 'Cluj', 'country' => 'RO', 'email' => 'client@srl.ro', 'phone' => '0722000000', 'is_company' => true,
        ],
        'invoice' => ['invoice_date' => '2026-11-04', 'due_date' => '2026-11-19', 'currency' => 'RON', 'vat_rate' => 21, 'caen' => '6201', 'is_test' => true],
        'lines' => [['description' => 'Servicii IT', 'unit' => 'ora', 'quantity' => 10.0, 'price' => 100.0, 'vat_rate' => 21]],
    ]);
});

it('omits optional values and keeps is_test false', function (): void {
    $payload = InvoicePayload::make(invoiceData(buyer: new PartyData('Ion Popescu', email: 'ion@example.com')), test: false);

    expect($payload)->not->toHaveKey('app_name')
        ->and($payload['client'])->toBe(['name' => 'Ion Popescu', 'country' => 'RO', 'email' => 'ion@example.com', 'is_company' => false])
        ->and($payload['invoice'])->toBe(['invoice_date' => '2026-11-04', 'currency' => 'RON', 'vat_rate' => 21, 'is_test' => false]);
});

it('converts prices to net lei with 4 decimals', function (InvoiceLineData $line, float $expected): void {
    expect(InvoicePayload::netUnitPrice($line))->toBe($expected);
})->with([
    'cu TVA inclus' => [new InvoiceLineData('A', 5000), 41.3223],
    'fără TVA' => [new InvoiceLineData('B', 4999, priceIncludesVat: false), 49.99],
    'cota 0' => [new InvoiceLineData('C', 1234, vatRate: 0), 12.34],
]);

it('refuses what the server cannot handle', function (InvoiceData $data, string $message): void {
    expect(fn (): array => InvoicePayload::make($data))->toThrow(FacturaException::class, $message);
})->with([
    'cote diferite' => [fn (): InvoiceData => invoiceData(lines: [new InvoiceLineData('A', 100), new InvoiceLineData('B', 100, vatRate: 11)]), 'o singură cotă de TVA'],
    'fără email' => [fn (): InvoiceData => invoiceData(buyer: new PartyData('X')), 'Emailul clientului'],
    'email gol' => [fn (): InvoiceData => invoiceData(buyer: new PartyData('X', email: '')), 'Emailul clientului'],
    'fără dată' => [fn (): InvoiceData => new InvoiceData(InvoiceType::Fiscal, new PartyData('X', email: 'x@y.ro'), [new InvoiceLineData('A', 100)]), 'data facturii'],
]);
