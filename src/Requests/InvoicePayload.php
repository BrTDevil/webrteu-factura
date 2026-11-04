<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Requests;

use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Invoicing\Data\InvoiceData;
use WeBRTeu\Invoicing\Data\InvoiceLineData;

/**
 * InvoiceData → corpul POST /api/v1/invoice.
 *
 * Limitări ale serverului (versiunea actuală), verificate aici ca să nu plece un XML pe care ANAF îl respinge:
 * - o singură cotă de TVA pe factură (XML-ul folosește cota de la nivel de factură pentru toate liniile)
 * - prețul liniei e fără TVA; serverul calculează TVA-ul din net, deci totalul poate diferi cu 1 ban
 * - emailul clientului e obligatoriu (serverul trimite PDF-ul automat)
 */
final class InvoicePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(InvoiceData $invoice, ?string $appName = null, ?bool $test = null, ?string $caen = null): array
    {
        $rates = array_values(array_unique(array_map(fn (InvoiceLineData $line): int => $line->vatRate, $invoice->lines)));

        if (count($rates) > 1) {
            throw new FacturaException(sprintf('Serverul de facturare acceptă o singură cotă de TVA pe factură (primit: %s%%). Emite facturi separate pe cote.', implode('%, ', $rates)));
        }

        if ($invoice->buyer->email === null || $invoice->buyer->email === '') {
            throw new FacturaException('Emailul clientului este obligatoriu: serverul de facturare trimite PDF-ul automat pe email.');
        }

        if (! $invoice->issueDate instanceof \DateTimeInterface) {
            throw new FacturaException('Lipsește data facturii.');
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
                'vat_rate' => $rates[0],
                'caen' => $caen,
                'is_test' => $test,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'lines' => array_map(fn (InvoiceLineData $line): array => [
                'description' => $line->name,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'price' => self::netUnitPrice($line),
                'vat_rate' => $line->vatRate,
            ], $invoice->lines),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Prețul unitar fără TVA, în lei, cu 4 zecimale (cât mai aproape de totalul plătit de client).
     */
    public static function netUnitPrice(InvoiceLineData $line): float
    {
        $net = $line->priceIncludesVat ? $line->unitPrice * 100 / (100 + $line->vatRate) : $line->unitPrice;

        return round($net / 100, 4);
    }
}
