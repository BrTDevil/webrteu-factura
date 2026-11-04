# Changelog

## [Unreleased] (0.1.0)

Țintă: serverul de facturare 1.0.5+ (`FACTURA_LEGACY_SERVER=true` pentru servere mai vechi).

### Adăugat
- `FacturaProvider` pentru `webrteu/invoicing`: emitere prin `POST /api/v1/invoice`, PDF-ul serverului, status ANAF.
- URL-ul serverului de facturare și tokenul configurabile (orice instalare a aplicației de facturare).
- Cote TVA pe linie, prețuri cu TVA inclus trimise exact, `reference` → `external_id` (idempotență, BT-13 în XML).
- `Invoicing::cancel()` = stornare pe server (`POST /api/v1/invoice/storno`), salvată în `raw.storno`.
- Mod `legacy_server` pentru serverele mai vechi de 1.0.5 (o cotă, prețuri nete, fără storno).
- Verificări: email obligatoriu, doar facturi fiscale, `external_id` max. 100 caractere.
- `RateLimitedException` (HTTP 429, `Retry-After`), `InvoiceStateUnknownException` (fără răspuns la emitere).
- Evenimentul `AnafStatusChanged` și comanda `factura:sync`.
- Teste Pest (coverage 100%), PHPStan nivel 8, CI.
