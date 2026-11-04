# webrteu/factura

[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%E2%80%9313-ff2d20)](https://laravel.com/)
[![Licență MIT](https://img.shields.io/badge/licen%C8%9B%C4%83-MIT-green)](LICENSE.md)
[![Packagist](https://img.shields.io/packagist/v/webrteu/factura)](https://packagist.org/packages/webrteu/factura)

Furnizor pentru `webrteu/invoicing` care emite facturile prin **serverul de facturare e-Factura** (aplicația
factura.webrt.eu, instalată pe serverul fiecărui client). Serverul generează XML-ul UBL 2.1 / CIUS-RO și PDF-ul,
le trimite în SPV ANAF și trimite emailul clientului. Site-ul Laravel nu are nevoie de certificatul digital sau
de conexiunea OAuth cu ANAF: acestea stau o singură dată pe serverul de facturare.

Adresa serverului e configurabilă: același pachet merge cu orice instalare, nu doar cu factura.webrt.eu.

## Cerințe

- PHP `^8.2`, Laravel 12–13
- `webrteu/invoicing` ^2.0
- un server de facturare (factura.webrt.eu v1.0.3+) cu furnizorul configurat și, pentru SPV, conectat la ANAF

## Instalare

```bash
composer require webrteu/factura
php artisan vendor:publish --tag=factura-config
```

## Configurare

```dotenv
INVOICING_PROVIDER=factura

FACTURA_URL=https://efactura.clientul-meu.ro   # adresa serverului de facturare, fără /api/v1
FACTURA_TOKEN=                                 # server → /api-settings/clients (un token per site, recomandat)
FACTURA_APP_NAME="Magazinul meu"               # ignorat de server când tokenul e per-magazin
FACTURA_TEST=                                  # true = test, false = real, gol = cum e setat pe server
FACTURA_CAEN=                                  # opțional, apare în notele facturii
FACTURA_SYNC_BATCH=2                           # câte statusuri ANAF citește factura:sync la o rulare
FACTURA_TIMEOUT=30
```

Tokenul per-magazin vede doar facturile create cu el, poate avea listă de IP-uri permise și se poate revoca separat.
Seria și numerotarea facturilor sunt cele setate pe server.

## Utilizare

```php
$invoice = Invoicing::issue($invoiceData, invoiceable: $order);   // POST /api/v1/invoice
$invoice->fullNumber();                                           // "INV0005", de la server

Invoicing::pdf($invoice, 'provider');   // PDF-ul generat de server
Invoicing::pdf($invoice, 'local');      // PDF-ul din șablonul webrteu/invoicing, cu numărul serverului

Factura::anafStatus($invoice);          // statusul din SPV (serverul îl citește live de la ANAF)
```

| Metodă | Server |
|---|---|
| `Invoicing::issue()` | `POST /api/v1/invoice` |
| `Invoicing::pdf($invoice, 'provider')` / `Factura::pdf()` | `GET /api/v1/invoice/pdf?id=` |
| `Factura::anafStatus($invoice)` | `GET /api/v1/invoice/anaf-status?number=` |

Statusul ANAF se salvează pe factură (`raw.anaf`), iar la schimbare se emite `AnafStatusChanged($invoice, $from, $to)`.
Statusurile posibile (`AnafStatus`): `Netransmis`, `Transmis`, `in prelucrare`, `ok` (validată), `nok` (respinsă),
`Eroare Transmitere`. Ultimul status cunoscut, fără apel la server: `FacturaProvider::storedStatus($invoice)`.

Facturile ajung în SPV doar dacă serverul are `ANAF_API_AUTO_SEND=automat`. Altfel rămân `Netransmis` până le
transmiți din interfața serverului.

## Comenzi Artisan

`factura:sync {--limit=} {--number=}`: citește statusul ANAF al facturilor încă nefinalizate (nu `ok`/`nok`), din
ultimele 30 de zile, începând cu cele verificate cel mai demult.

```php
// routes/console.php
Schedule::command('factura:sync')->everyFiveMinutes()->withoutOverlapping();
```

## Limitările serverului (versiunea actuală)

Pachetul le verifică înainte să trimită, ca să nu ajungă la ANAF o factură pe care o respinge:

- **O singură cotă de TVA pe factură.** Serverul pune în XML cota de la nivel de factură pe toate liniile. O factură cu
  21% și 11% e refuzată de pachet cu un mesaj clar: emite facturi separate pe cote.
- **Emailul clientului e obligatoriu.** Serverul trimite automat PDF-ul pe email. Dacă și site-ul trimite factura,
  clientul o primește de două ori.
- **Doar facturi fiscale.** Proformele se emit cu furnizorul `local`.
- **Fără anulare prin API.** `Invoicing::cancel()` aruncă `UnsupportedOperationException`: stornezi din interfața serverului.
- **Prețurile se trimit fără TVA** (cu 4 zecimale), iar serverul recalculează TVA-ul. La prețuri cu TVA inclus, totalul
  facturii poate ieși cu 1 ban diferit de cel plătit. Diferența se loghează ca `factura.total_mismatch`.
- **3 cereri pe minut per IP**, pe toate endpoint-urile. La HTTP 429 primești `RateLimitedException` cu `retryAfter`
  (în secunde). La 429 nu s-a creat nimic, deci poți relua în siguranță, ideal dintr-un job pe coadă.
- **Fără cheie de idempotență.** Dacă serverul nu răspunde la emitere, primești `InvoiceStateUnknownException`:
  factura poate exista deja pe server. Verifică înainte să o retrimiți.

## Ce nu face pachetul

- nu vorbește direct cu ANAF (OAuth, upload SPV, XML): asta face serverul de facturare
- nu descarcă răspunsul ANAF (ZIP-ul semnat); îl găsești pe server

## Testare

```bash
composer test
```

Fixture-urile sunt scrise după `docs/api.md` din aplicația de facturare. **De înlocuit cu răspunsuri reale.**

## Changelog

Vezi [CHANGELOG.md](CHANGELOG.md).

## Autor

[WeBRTeu](https://webrt.eu)

## Licență

MIT. Vezi [LICENSE.md](LICENSE.md).
