<?php

declare(strict_types=1);

namespace WeBRTeu\Factura;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use WeBRTeu\Factura\Commands\SyncCommand;
use WeBRTeu\Invoicing\InvoiceManager;

class FacturaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/factura.php', 'factura');

        $this->app->singleton(FacturaClient::class);
        $this->app->singleton(FacturaProvider::class);

        $this->callAfterResolving(InvoiceManager::class, function (InvoiceManager $invoicing): void {
            $invoicing->extend('factura', fn (Container $app): FacturaProvider => $app->make(FacturaProvider::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/factura.php' => $this->app->configPath('factura.php')], 'factura-config');

        $this->commands([SyncCommand::class]);
    }
}
