# Changelog

## [Unreleased] (0.1.0)

### Adăugat
- `FacturaProvider` pentru `webrteu/invoicing`: emitere prin `POST /api/v1/invoice`, PDF-ul serverului, status ANAF.
- URL-ul serverului de facturare și tokenul configurabile (orice instalare factura.webrt.eu).
- Verificări pentru limitările serverului: o singură cotă TVA, email obligatoriu, doar facturi fiscale.
- `RateLimitedException` (HTTP 429, `Retry-After`), `InvoiceStateUnknownException` (fără răspuns la emitere).
- Evenimentul `AnafStatusChanged` și comanda `factura:sync`.
- Teste Pest (coverage 100%), PHPStan nivel 8, CI.
