<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Requests;

use DateTimeInterface;
use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\InvoiceLineData;

/**
 * InvoiceData → corpul POST /api/v1/invoice.
 *
 * Server 1.0.5+ (implicit): cota TVA pe fiecare linie, prețul exact cum e în comandă (cu sau fără TVA,
 * `price_includes_vat`), iar `reference` (numărul comenzii) devine `external_id`, cheia de idempotență.
 * Totalul facturii iese exact cât a plătit clientul.
 *
 * Server mai vechi (`legacy`): o singură cotă pe factură, prețuri fără TVA, fără external_id.
 */
final class InvoicePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(InvoiceData $invoice, ?string $appName = null, ?bool $test = null, ?string $caen = null, bool $legacy = false): array
    {
        $rates = array_values(array_unique(array_map(fn (InvoiceLineData $line): int => $line->vatRate, $invoice->lines)));

        if ($legacy && count($rates) > 1) {
            throw new FacturaException(sprintf('Serverul de facturare (versiune veche) acceptă o singură cotă de TVA pe factură (primit: %s%%). Actualizează serverul la 1.0.5+ sau emite facturi separate pe cote.', implode('%, ', $rates)));
        }

        if ($invoice->buyer->email === null || $invoice->buyer->email === '') {
            throw new FacturaException('Emailul clientului este obligatoriu: serverul de facturare trimite PDF-ul automat pe email.');
        }

        if (! $invoice->issueDate instanceof DateTimeInterface) {
            throw new FacturaException('Lipsește data facturii.');
        }

        $externalId = $legacy ? null : $invoice->reference;

        if ($externalId !== null && mb_strlen($externalId) > 100) {
            throw new FacturaException('Referința comenzii (external_id) poate avea maximum 100 de caractere.');
        }

        $buyer = $invoice->buyer;

        return array_filter([
            'app_name' => $appName,
            'client' => array_filter([
                'name' => $buyer->name,
                'cif' => $buyer->cui,
                'reg_com' => $buyer->regCom,
                'address' => $buyer->address,
                'city' => $buyer->city,
                'county' => $buyer->county,
                'country' => $buyer->country,
                'email' => $buyer->email,
                'phone' => $buyer->phone,
                'is_company' => $buyer->isCompany(),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'invoice' => array_filter([
                'invoice_date' => $invoice->issueDate->format('Y-m-d'),
                'due_date' => $invoice->dueDate?->format('Y-m-d'),
                'currency' => $invoice->currency,
                'vat_rate' => self::generalRate($invoice),
                'external_id' => $externalId,
                'caen' => $caen,
                'is_test' => $test,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'lines' => array_map(fn (InvoiceLineData $line): array => $legacy ? [
                'description' => $line->name,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'price' => self::netUnitPrice($line),
                'vat_rate' => $line->vatRate,
            ] : [
                'description' => $line->name,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'price' => round($line->unitPrice / 100, 2),
                'vat_rate' => $line->vatRate,
                'price_includes_vat' => $line->priceIncludesVat,
            ], $invoice->lines),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Cota generală a facturii: cea mai folosită cotă de pe linii (fiecare linie își trimite oricum cota).
     */
    public static function generalRate(InvoiceData $invoice): int
    {
        $counts = array_count_values(array_map(fn (InvoiceLineData $line): int => $line->vatRate, $invoice->lines));
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * Prețul unitar fără TVA, în lei, cu 4 zecimale (doar pentru serverele vechi).
     */
    public static function netUnitPrice(InvoiceLineData $line): float
    {
        $net = $line->priceIncludesVat ? $line->unitPrice * 100 / (100 + $line->vatRate) : $line->unitPrice;

        return round($net / 100, 4);
    }
}
