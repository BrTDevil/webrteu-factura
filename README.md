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
- un server de facturare (factura.webrt.eu **1.0.5+**; pentru 1.0.3–1.0.4 vezi `FACTURA_LEGACY_SERVER`) cu furnizorul configurat și, pentru SPV, conectat la ANAF

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
FACTURA_LEGACY_SERVER=false                    # true doar pentru servere mai vechi de 1.0.5
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
Invoicing::cancel($invoice);            // stornare pe server (aceeași factură cu minus)
```

| Metodă | Server |
|---|---|
| `Invoicing::issue()` | `POST /api/v1/invoice` |
| `Invoicing::pdf($invoice, 'provider')` / `Factura::pdf()` | `GET /api/v1/invoice/pdf?id=` |
| `Factura::anafStatus($invoice)` | `GET /api/v1/invoice/anaf-status?number=` |
| `Invoicing::cancel($invoice)` | `POST /api/v1/invoice/storno` |

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

## Cum se trimite factura (server 1.0.5+)

- **Cote TVA pe linie:** fiecare linie își trimite cota (21%, 11%, 0%), iar factura poate avea mai multe cote.
- **Prețul exact din comandă:** cu TVA inclus sau fără (`price_includes_vat`). Serverul calculează TVA-ul pe cotă,
  iar totalul de plată (`total_payable`) iese exact cât a plătit clientul. O diferență totuși apărută se loghează
  ca `factura.total_mismatch`.
- **Idempotență:** `reference` din `InvoiceData` (numărul comenzii) se trimite ca `external_id`. Dacă cererea se
  repetă (timeout, job reîncercat), serverul întoarce factura deja creată în loc să facă una nouă
  (`factura.duplicate` în log). Numărul comenzii apare și în XML, ca referința comenzii (BT-13).
- **Anulare = stornare:** `Invoicing::cancel($invoice)` cere serverului storno-ul (aceeași factură cu cantități
  negative, număr nou, trimitere la originală). Factura rămâne `Cancelled`, iar storno-ul se salvează în
  `raw.storno` (număr, id, total, status ANAF). Storno-ul se transmite la ANAF ca orice factură.

### Server mai vechi de 1.0.5

Cu `FACTURA_LEGACY_SERVER=true`, pachetul vorbește în formatul vechi: o singură cotă TVA pe factură (altfel refuză
cu mesaj clar), prețuri trimise fără TVA (totalul poate ieși cu 1 ban diferit), fără `external_id` și fără storno
prin API.

## Limitări

- **Emailul clientului e obligatoriu.** Serverul trimite automat PDF-ul pe email; dacă și site-ul trimite factura,
  clientul o primește de două ori.
- **Doar facturi fiscale.** Proformele se emit cu furnizorul `local`.
- **3 cereri pe minut per IP**, pe toate endpoint-urile. La HTTP 429 primești `RateLimitedException` cu `retryAfter`
  (secunde). La 429 nu s-a creat nimic, deci poți relua în siguranță, ideal dintr-un job pe coadă.
- **Fără răspuns la emitere** → `InvoiceStateUnknownException`. Pe server 1.0.5+ cu `reference` completat,
  retrimiterea e sigură (întoarce factura existentă); altfel verifică în aplicația de facturare înainte.

## Ce nu face pachetul

- nu vorbește direct cu ANAF (OAuth, upload SPV, XML): asta face serverul de facturare
- nu descarcă răspunsul ANAF (ZIP-ul semnat); îl găsești pe server

## Testare

```bash
composer test
```

Fixture-urile sunt scrise după `docs/api.md` și `docs/openapi.yaml` (server 1.0.5). Specificația completă e pe orice server la `/api/openapi.yaml`. **De înlocuit cu răspunsuri reale.**

## Changelog

Vezi [CHANGELOG.md](CHANGELOG.md).

## Autor

[WeBRTeu](https://webrt.eu)

## Licență

MIT. Vezi [LICENSE.md](LICENSE.md).
