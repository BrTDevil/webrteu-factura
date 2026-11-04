<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeBRTeu\Factura\Exceptions\RateLimitedException;
use WeBRTeu\Factura\FacturaProvider;
use WeBRTeu\Invoicing\Enums\InvoiceStatus;
use WeBRTeu\Invoicing\Models\Invoice;

class SyncCommand extends Command
{
    protected $signature = 'factura:sync {--limit= : Câte facturi la o rulare (implicit factura.sync.batch)} {--number= : O singură factură}';

    protected $description = 'Citește statusul ANAF (SPV) al facturilor emise prin serverul de facturare';

    public function handle(FacturaProvider $provider): int
    {
        $number = $this->option('number');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : Config::integer('factura.sync.batch', 2);

        $invoices = Invoice::query()
            ->where('provider', 'factura')
            ->where('status', InvoiceStatus::Issued)
            ->when(is_string($number) && $number !== '', fn (Builder $query): Builder => $query->where('number', $number))
            ->where('created_at', '>=', Carbon::now()->subDays(Config::integer('factura.sync.max_age_days', 30)))
            ->oldest('id')
            ->get()
            ->reject(fn (Invoice $invoice): bool => FacturaProvider::storedStatus($invoice)->isFinal())
            ->sortBy(fn (Invoice $invoice): string => is_array($invoice->raw['anaf'] ?? null) && is_string($invoice->raw['anaf']['checked_at'] ?? null) ? $invoice->raw['anaf']['checked_at'] : '')
            ->take($limit);

        $rows = [];

        foreach ($invoices as $invoice) {
            try {
                $rows[] = [(string) $invoice->number, $provider->anafStatus($invoice)->status->label()];
            } catch (RateLimitedException $exception) {
                $this->warn(sprintf('Limita serverului a fost atinsă; reia peste %d de secunde.', $exception->retryAfter));

                break;
            } catch (Throwable $exception) {
                Log::warning('factura.sync_failed', ['number' => $invoice->number, 'error' => $exception->getMessage()]);
                $rows[] = [(string) $invoice->number, 'Eroare: '.$exception->getMessage()];
            }
        }

        $this->table(['Factura', 'Status ANAF'], $rows);

        return self::SUCCESS;
    }
}
