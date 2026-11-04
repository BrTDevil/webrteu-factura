<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use WeBRTeu\Factura\Enums\AnafStatus;
use WeBRTeu\Factura\Events\AnafStatusChanged;
use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Facades\Factura;
use WeBRTeu\Factura\FacturaProvider;
use WeBRTeu\Invoicing\Facades\Invoicing;
use WeBRTeu\Invoicing\Models\Invoice;

function issuedInvoice(): Invoice
{
    Http::fake([FACTURA.'/invoice' => Http::response(fixtureFile('invoice-created.json'))]);

    return Invoicing::issue(invoiceData());
}

beforeEach(function (): void {
    Storage::fake('local');
    Config::set('invoicing.pdf.store', false);
});

it('downloads the server PDF or renders the own template', function (): void {
    $invoice = issuedInvoice();
    Http::fake([FACTURA.'/invoice/pdf*' => Http::response('%PDF-server', 200, ['Content-Type' => 'application/pdf'])]);

    expect(Invoicing::pdf($invoice, 'provider'))->toBe('%PDF-server')
        ->and(Invoicing::pdf($invoice, 'local'))->toStartWith('%PDF-1');
    Http::assertSent(fn (Request $request): bool => $request->url() === FACTURA.'/invoice/pdf?id=42' && $request->hasHeader('Accept', 'application/pdf'));
});

it('reports PDF errors', function (): void {
    $invoice = issuedInvoice();
    Http::fake([FACTURA.'/invoice/pdf*' => Http::sequence()->push(['success' => false, 'error' => 'Fișierul PDF nu există pe server.'], 404)->push('x', 500)]);

    expect(fn (): string => Factura::pdf($invoice))->toThrow(FacturaException::class, 'nu există pe server')
        ->and(fn (): string => Factura::pdf($invoice))->toThrow(FacturaException::class, 'eroare necunoscută');

    $invoice->forceFill(['provider_id' => null])->save();
    expect(fn (): string => Factura::pdf($invoice))->toThrow(FacturaException::class, 'nu are id-ul');
});

it('reports a lost connection on PDF downloads', function (): void {
    $invoice = issuedInvoice();
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(fn (): string => Factura::pdf($invoice))->toThrow(FacturaException::class, 'nu a răspuns: down');
});

it('reads the ANAF status, stores it and fires the event only on change', function (): void {
    Event::fake([AnafStatusChanged::class]);
    $invoice = issuedInvoice();
    Http::fake([FACTURA.'/invoice/anaf-status*' => Http::sequence()->push(fixtureFile('anaf-status-ok.json'))->push(fixtureFile('anaf-status-ok.json'))]);

    $status = Factura::anafStatus($invoice);
    Factura::anafStatus($invoice);

    expect($status)->status->toBe(AnafStatus::Ok)->uploadIndex->toBe('5008123456')->downloadId->toBe('3456789')
        ->and($invoice->fresh()?->raw['anaf'])->toMatchArray(['status' => 'ok', 'upload_index' => '5008123456', 'download_id' => '3456789'])
        ->and($invoice->fresh()?->raw['invoice_number'])->toBe('INV0005');
    Event::assertDispatchedTimes(AnafStatusChanged::class, 1);
    Event::assertDispatched(AnafStatusChanged::class, fn (AnafStatusChanged $event): bool => $event->from === AnafStatus::NotSent && $event->to === AnafStatus::Ok);
    Http::assertSent(fn (Request $request): bool => $request->url() === FACTURA.'/invoice/anaf-status?number=INV0005');
});

it('reports a lost connection on status reads as a plain error', function (): void {
    $invoice = issuedInvoice();
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(fn () => Factura::anafStatus($invoice))->toThrow(FacturaException::class, 'nu a răspuns: down');
});

it('treats a missing stored status as unknown', function (): void {
    expect(FacturaProvider::storedStatus(new Invoice(['raw' => null])))->toBe(AnafStatus::Unknown)
        ->and(FacturaProvider::storedStatus(new Invoice(['raw' => ['anaf' => 'x']])))->toBe(AnafStatus::Unknown);
});
