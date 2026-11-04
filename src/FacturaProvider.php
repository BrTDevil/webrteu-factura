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
 * Emite facturi prin serverul de facturare (aplicația de facturare instalată pe serverul tău, ex. factura.serverul-tau.ro), care generează XML-ul UBL / CIUS-RO,
 * PDF-ul și îl transmite în SPV. Numărul facturii vine din seria configurată pe server. Anularea = stornare.
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
            $this->legacy(),
        ));

        $number = is_scalar($json['invoice_number'] ?? null) ? (string) $json['invoice_number'] : '';

        if ($number === '') {
            throw new FacturaException('Serverul de facturare nu a întors numărul facturii.');
        }

        if (($json['duplicate'] ?? false) === true) {
            // Cerere repetată (același external_id): serverul a întors factura deja creată, fără una nouă.
            Log::info('factura.duplicate', ['reference' => $invoice->reference, 'invoice_number' => $number]);
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
     * Anularea = stornare (server 1.0.5+): serverul emite aceeași factură cu cantități negative, cu număr nou,
     * cu trimitere la factura originală. Storno-ul se salvează în raw.storno. Idempotent pe server.
     */
    public function cancel(Invoice $invoice): void
    {
        if ($this->legacy()) {
            throw new UnsupportedOperationException('Serverul de facturare (versiune veche) nu permite stornarea prin API. Stornează factura din aplicația de facturare sau actualizează serverul la 1.0.5+.');
        }

        $json = $this->client->send('POST', '/api/v1/invoice/storno', body: $invoice->provider_id !== null && $invoice->provider_id !== ''
            ? ['invoice_id' => (int) $invoice->provider_id]
            : ['invoice_number' => (string) $invoice->number]);

        $invoice->forceFill(['raw' => array_merge($invoice->raw ?? [], ['storno' => [
            'invoice_number' => is_scalar($json['invoice_number'] ?? null) ? (string) $json['invoice_number'] : null,
            'invoice_id' => is_numeric($json['invoice_id'] ?? null) ? (int) $json['invoice_id'] : null,
            'total_payable' => is_numeric($json['total_payable'] ?? null) ? (float) $json['total_payable'] : null,
            'anaf_status' => is_scalar($json['anaf_status'] ?? null) ? (string) $json['anaf_status'] : null,
        ]])]);
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

    private function legacy(): bool
    {
        return Config::boolean('factura.legacy_server', false);
    }

    /**
     * Totalul de plată al serverului (total_payable de la 1.0.5, altfel total_gross) trebuie să fie cel al
     * comenzii; o diferență se loghează. La serverele vechi pot apărea diferențe de 1 ban.
     *
     * @param  array<array-key, mixed>  $json
     */
    private function checkTotal(InvoiceData $invoice, array $json): void
    {
        $total = $json['total_payable'] ?? $json['total_gross'] ?? null;
        $serverTotal = is_numeric($total) ? (int) round((float) $total * 100) : null;

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
