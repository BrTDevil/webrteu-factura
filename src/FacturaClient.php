<?php

declare(strict_types=1);

namespace WeBRTeu\Factura;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WeBRTeu\Factura\Exceptions\FacturaException;
use WeBRTeu\Factura\Exceptions\InvoiceStateUnknownException;
use WeBRTeu\Factura\Exceptions\RateLimitedException;

/**
 * HTTP brut spre API-ul serverului de facturare (/api/v1/*): Bearer token, JSON. Fără retry.
 */
class FacturaClient
{
    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function send(string $method, string $path, array $query = [], array $body = []): array
    {
        try {
            $response = $body === []
                ? $this->request()->acceptJson()->send($method, $this->url($path), ['query' => $query])
                : $this->request()->acceptJson()->asJson()->send($method, $this->url($path), ['query' => $query, 'json' => $body]);
        } catch (ConnectionException $exception) {
            Log::error('factura.connection_failed', ['path' => $path, 'error' => $exception->getMessage()]);

            if ($method === 'POST') {
                throw new InvoiceStateUnknownException('Serverul de facturare nu a răspuns, deci factura poate fi deja creată. Cu server 1.0.5+ și referința comenzii (external_id), retrimiterea e sigură: întoarce factura existentă. Altfel verifică în aplicația de facturare înainte să o retrimiți.', 0, $exception);
            }

            throw new FacturaException('Serverul de facturare nu a răspuns: '.$exception->getMessage(), 0, $exception);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->failed() || ($json['success'] ?? true) === false) {
            $this->fail($method, $path, $response, is_scalar($json['error'] ?? null) ? (string) $json['error'] : null);
        }

        return $json;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public function download(string $path, array $query): string
    {
        try {
            $response = $this->request()->accept('application/pdf')->get($this->url($path), $query);
        } catch (ConnectionException $exception) {
            throw new FacturaException('Serverul de facturare nu a răspuns: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            $json = $response->json();

            $this->fail('GET', $path, $response, is_array($json) && is_scalar($json['error'] ?? null) ? (string) $json['error'] : null);
        }

        return $response->body();
    }

    private function request(): PendingRequest
    {
        return Http::withToken(Config::string('factura.token', ''))->timeout(Config::integer('factura.timeout', 30));
    }

    private function url(string $path): string
    {
        $base = Config::get('factura.url');

        if (! is_string($base) || $base === '') {
            throw new FacturaException('Lipsește FACTURA_URL (adresa serverului de facturare).');
        }

        return rtrim($base, '/').$path;
    }

    private function fail(string $method, string $path, Response $response, ?string $error): never
    {
        Log::warning('factura.request_failed', ['path' => $path, 'status' => $response->status(), 'error' => $error]);

        $message = sprintf('Factura %s %s a eșuat (HTTP %d): %s', $method, $path, $response->status(), $error ?? 'eroare necunoscută');

        if ($response->status() === 429) {
            throw new RateLimitedException(max(1, (int) $response->header('Retry-After')), $message);
        }

        throw new FacturaException($message);
    }
}
