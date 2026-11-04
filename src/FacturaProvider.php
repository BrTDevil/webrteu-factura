<?php

declare(strict_types=1);

namespace WeBRTeu\Factura;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use WeBRTeu\Factura\Data\AnafStatusData;
use WeBRTeu\Factura\Enums\AnafStatus;
use WeBRTeu\Factura\Events\AnafStatusChanged;
use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Requests\InvoicePayload;
use WeBRTeu\Invoicing\Contracts\InvoiceProvider;
use WeBRTeu\Invoicing\Contracts\ProvidesPdf;
use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\IssuedInvoiceData;
use WeBRTeu\Invoicing\Enums\InvoiceType;
use WeBRTeu\Invoicing\Exceptions\UnsupportedOperationException;
use WeBRTeu\Invoicing\Models\Invoice;
use WeBRTeu\Invoicing\Support\Money;

/**
 * Emite facturi prin serverul de facturare (factura.webrt.eu instalat la client), care generează XML-ul UBL / CIUS-RO,
 * PDF-ul și îl transmite în SPV. Numărul facturii vine din seria configurată pe server.
 */
class FacturaProvider implements InvoiceProvider, ProvidesPdf
{
    public function __construct(private readonly FacturaClient $client) {}

    public function name(): string
    {
        return 'factura';
    }

    public function issue(InvoiceData $invoice): IssuedInvoiceData
    {
        if ($invoice->type === InvoiceType::Proforma) {
            throw new UnsupportedOperationException('Serverul de facturare emite doar facturi fiscale. Pentru proforme folosește furnizorul local.');
        }

        $test = Config::get('factura.test');
        $caen = Config::get('factura.caen');
        $appName = Config::get('factura.app_name');

        $json = $this->client->send('POST', '/api/v1/invoice', body: InvoicePayload::make(
            $invoice,
            is_string($appName) && $appName !== '' ? $appName : null,
            is_bool($test) ? $test : null,
            is_scalar($caen) && (string) $caen !== '' ? (string) $caen : null,
        ));

        $number = is_scalar($json['invoice_number'] ?? null) ? (string) $json['invoice_number'] : '';

        if ($number === '') {
            throw new FacturaException('Serverul de facturare nu a întors numărul facturii.');
        }

        $this->checkTotal($invoice, $json);

        return new IssuedInvoiceData(
            series: '',
            number: $number,
            providerId: is_scalar($json['invoice_id'] ?? null) ? (string) $json['invoice_id'] : null,
            raw: $json + ['anaf' => (new AnafStatusData(
                AnafStatus::fromServer(is_scalar($json['anaf_status'] ?? null) ? (string) $json['anaf_status'] : null),
                is_scalar($json['anaf_index_incarcare'] ?? null) ? (string) $json['anaf_index_incarcare'] : null,
            ))->toArray()],
        );
    }

    /**
     * Serverul nu are anulare prin API: factura se stornează din aplicația de facturare.
     */
    public function cancel(Invoice $invoice): void
    {
        throw new UnsupportedOperationException('Serverul de facturare nu permite anularea prin API. Stornează factura din aplicația de facturare.');
    }

    public function pdf(Invoice $invoice): string
    {
        if ($invoice->provider_id === null || $invoice->provider_id === '') {
            throw new FacturaException('Factura nu are id-ul de pe serverul de facturare.');
        }

        return $this->client->download('/api/v1/invoice/pdf', ['id' => $invoice->provider_id]);
    }

    /**
     * Cere statusul ANAF (serverul îl actualizează live din SPV), îl salvează în raw.anaf și emite AnafStatusChanged la schimbare.
     */
    public function anafStatus(Invoice $invoice): AnafStatusData
    {
        $json = $this->client->send('GET', '/api/v1/invoice/anaf-status', ['number' => (string) $invoice->number]);
        $status = new AnafStatusData(
            AnafStatus::fromServer(is_scalar($json['anaf_status'] ?? null) ? (string) $json['anaf_status'] : null),
            is_scalar($json['anaf_index_incarcare'] ?? null) ? (string) $json['anaf_index_incarcare'] : null,
            is_scalar($json['anaf_id_descarcare'] ?? null) ? (string) $json['anaf_id_descarcare'] : null,
            $json,
        );

        $from = self::storedStatus($invoice);
        $invoice->forceFill(['raw' => array_merge($invoice->raw ?? [], ['anaf' => $status->toArray() + ['checked_at' => Carbon::now()->toIso8601String()]])])->save();

        if ($from !== $status->status) {
            Event::dispatch(new AnafStatusChanged($invoice, $from, $status->status));
        }

        return $status;
    }

    /**
     * Ultimul status ANAF cunoscut, fără apel la server.
     */
    public static function storedStatus(Invoice $invoice): AnafStatus
    {
        $anaf = $invoice->raw['anaf'] ?? null;

        return AnafStatus::fromServer(is_array($anaf) && is_string($anaf['status'] ?? null) ? $anaf['status'] : null);
    }

    /**
     * Serverul recalculează TVA-ul din prețul fără TVA; o diferență de bani față de comandă se loghează.
     *
     * @param  array<array-key, mixed>  $json
     */
    private function checkTotal(InvoiceData $invoice, array $json): void
    {
        $serverTotal = is_numeric($json['total_gross'] ?? null) ? (int) round((float) $json['total_gross'] * 100) : null;

        if ($serverTotal !== null && $serverTotal !== $invoice->total()) {
            Log::warning('factura.total_mismatch', [
                'reference' => $invoice->reference,
                'invoice_number' => $json['invoice_number'] ?? null,
                'expected' => Money::toDecimalString($invoice->total()),
                'server' => Money::toDecimalString($serverTotal),
            ]);
        }
    }
}
