<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Exceptions\InvoiceStateUnknownException;
use WeBRTeu\Factura\Exceptions\RateLimitedException;
use WeBRTeu\Factura\FacturaProvider;
use WeBRTeu\Factura\FacturaServiceProvider;
use WeBRTeu\Invoicing\Data\InvoiceLineData;
use WeBRTeu\Invoicing\Enums\InvoiceStatus;
use WeBRTeu\Invoicing\Enums\InvoiceType;
use WeBRTeu\Invoicing\Exceptions\UnsupportedOperationException;
use WeBRTeu\Invoicing\Facades\Invoicing;
use WeBRTeu\Invoicing\Models\Invoice;

it('issues through the server and stores its number, id and ANAF status', function (): void {
    Http::fake([FACTURA.'/invoice' => Http::response(fixtureFile('invoice-created.json'))]);

    $invoice = Invoicing::issue(invoiceData());

    expect($invoice)->provider->toBe('factura')->status->toBe(InvoiceStatus::Issued)->number->toBe('INV0005')->provider_id->toBe('42')
        ->and($invoice->fullNumber())->toBe('INV0005')
        ->and(FacturaProvider::storedStatus($invoice)->value)->toBe('Netransmis');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === FACTURA.'/invoice'
        && $request->hasHeader('Authorization', 'Bearer tok_secret_123')
        && $request['app_name'] === 'Magazin'
        && $request['invoice']['vat_rate'] === 21
        && $request['lines'][0]['price'] === 100.0
        && ! isset($request['invoice']['is_test']));
});

it('sends the test flag and CAEN from config, without an app name', function (): void {
    Config::set('factura.test', true);
    Config::set('factura.caen', '6201');
    Config::set('factura.app_name', '');
    Http::fake([FACTURA.'/invoice' => Http::response(fixtureFile('invoice-created.json'))]);

    Invoicing::issue(invoiceData());

    Http::assertSent(fn (Request $request): bool => $request['invoice']['is_test'] === true && $request['invoice']['caen'] === '6201' && ! isset($request['app_name']));
});

it('logs when the server total differs from the order total', function (): void {
    Log::spy();
    Http::fake([FACTURA.'/invoice' => Http::response(['success' => true, 'invoice_number' => 'INV0006', 'total_gross' => 99.99])]);

    $invoice = Invoicing::issue(invoiceData(lines: [new InvoiceLineData('Tricou', 5000, 2)]));

    expect($invoice->provider_id)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('factura.total_mismatch', ['reference' => 'CMD-123', 'invoice_number' => 'INV0006', 'expected' => '100.00', 'server' => '99.99']);
});

it('reports server errors and keeps a Failed invoice, without logging the token', function (array|string $body, int $status, string $exception, string $message): void {
    Log::spy();
    Http::fake([FACTURA.'/invoice' => Http::response($body, $status, ['Retry-After' => '42'])]);

    expect(fn (): Invoice => Invoicing::issue(invoiceData()))->toThrow($exception, $message)
        ->and(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Failed);
    Log::shouldNotHaveReceived('warning', [fn (string $m, array $context): bool => str_contains((string) json_encode($context), 'tok_secret_123')]);
})->with([
    'validare' => [['success' => false, 'error' => 'CIF-ul este obligatoriu pentru clienți persoane juridice.'], 422, FacturaException::class, 'CIF-ul este obligatoriu'],
    'token' => [['success' => false, 'error' => 'Token invalid sau lipsă.'], 401, FacturaException::class, 'Token invalid'],
    'success false la 200' => [['success' => false, 'error' => 'Furnizorul nu este configurat.'], 200, FacturaException::class, 'Furnizorul nu este configurat'],
    'fără corp' => ['', 500, FacturaException::class, 'eroare necunoscută'],
    'fără număr' => [['success' => true], 200, FacturaException::class, 'nu a întors numărul'],
    'limită' => [['success' => false, 'error' => 'Prea multe cereri.'], 429, RateLimitedException::class, 'Prea multe cereri'],
]);

it('exposes Retry-After on 429', function (): void {
    Http::fake([FACTURA.'/invoice' => Http::response(['success' => false, 'error' => 'Prea multe cereri.'], 429, ['Retry-After' => '42'])]);

    try {
        Invoicing::issue(invoiceData());
    } catch (RateLimitedException $exception) {
        expect($exception->retryAfter)->toBe(42);
    }
});

it('warns that the invoice may exist when the server does not answer', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(fn (): Invoice => Invoicing::issue(invoiceData()))->toThrow(InvoiceStateUnknownException::class, 'verifică în aplicația de facturare');
});

it('requires the server URL', function (): void {
    Config::set('factura.url');
    Http::fake();

    expect(fn (): Invoice => Invoicing::issue(invoiceData()))->toThrow(FacturaException::class, 'FACTURA_URL');
    Http::assertNothingSent();
});

it('does not issue proformas and does not cancel', function (): void {
    Http::fake([FACTURA.'/invoice' => Http::response(fixtureFile('invoice-created.json'))]);

    expect(fn (): Invoice => Invoicing::issue(invoiceData(InvoiceType::Proforma)))->toThrow(UnsupportedOperationException::class, 'furnizorul local')
        ->and(fn (): Invoice => Invoicing::cancel(Invoicing::issue(invoiceData())))->toThrow(UnsupportedOperationException::class, 'Stornează');
});

it('registers the provider, the config tag and the command', function (): void {
    expect(Invoicing::available())->toContain('factura')
        ->and(ServiceProvider::pathsToPublish(FacturaServiceProvider::class, 'factura-config'))->toHaveCount(1);
});
