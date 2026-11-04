<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WeBRTeu\Invoicing\Enums\InvoiceStatus;
use WeBRTeu\Invoicing\Models\Invoice;

/**
 * @param  array<string, mixed>|null  $anaf
 */
function storedInvoice(string $number, ?array $anaf, string $provider = 'factura', string $created = 'now'): Invoice
{
    return Invoice::query()->forceCreate([
        'provider' => $provider, 'type' => 'fiscal', 'status' => InvoiceStatus::Issued, 'series' => '', 'number' => $number,
        'net_total' => 100, 'vat_total' => 21, 'total' => 121, 'seller' => [], 'buyer' => [], 'lines' => [],
        'raw' => $anaf === null ? [] : ['anaf' => $anaf], 'created_at' => now()->modify($created),
    ]);
}

it('syncs pending invoices, oldest checks first, within the batch', function (): void {
    storedInvoice('INV1', ['status' => 'Transmis', 'checked_at' => '2026-11-04T10:00:00+00:00']);
    storedInvoice('INV2', ['status' => 'Netransmis']);
    storedInvoice('INV3', ['status' => 'ok']);
    storedInvoice('INV4', null, 'local');
    storedInvoice('INV5', null, created: '-60 days');
    storedInvoice('INV6', ['status' => 'in prelucrare', 'checked_at' => '2026-11-04T11:00:00+00:00']);
    Http::fake([FACTURA.'/invoice/anaf-status*' => Http::response(fixtureFile('anaf-status-processing.json'))]);

    $this->artisan('factura:sync')->expectsTable(['Factura', 'Status ANAF'], [
        ['INV2', 'În prelucrare la ANAF'],
        ['INV1', 'În prelucrare la ANAF'],
    ])->assertSuccessful();

    Http::assertSentCount(2);
});

it('syncs one invoice by number and honours --limit', function (): void {
    storedInvoice('INV1', null);
    storedInvoice('INV2', null);
    Http::fake([FACTURA.'/invoice/anaf-status*' => Http::response(fixtureFile('anaf-status-ok.json'))]);

    $this->artisan('factura:sync', ['--number' => 'INV2'])->expectsTable(['Factura', 'Status ANAF'], [['INV2', 'Validată de ANAF']]);
    $this->artisan('factura:sync', ['--limit' => 0])->expectsTable(['Factura', 'Status ANAF'], []);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'number=INV2'));
    Http::assertSentCount(1);
});

it('stops on the rate limit and keeps going after other errors', function (): void {
    Log::spy();
    storedInvoice('INV1', null);
    storedInvoice('INV2', null);
    storedInvoice('INV3', null);
    Http::fake([FACTURA.'/invoice/anaf-status*' => Http::sequence()
        ->push(['success' => false, 'error' => "Factura 'INV1' nu a fost găsită."], 404)
        ->push(['success' => false, 'error' => 'Prea multe cereri.'], 429, ['Retry-After' => '30'])]);

    $this->artisan('factura:sync', ['--limit' => 3])
        ->expectsOutputToContain('reia peste 30 de secunde')
        ->assertSuccessful();

    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'factura.sync_failed');
});
