<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Tests;

use Illuminate\Database\Migrations\Migration;
use Orchestra\Testbench\TestCase as Orchestra;
use WeBRTeu\Factura\FacturaServiceProvider;
use WeBRTeu\Invoicing\InvoicingServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [InvoicingServiceProvider::class, FacturaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('invoicing.default', 'factura');
        $app['config']->set('invoicing.seller', ['name' => 'Firma Mea SRL', 'cui' => 'RO12345678', 'vat_payer' => true]);
        $app['config']->set('factura.url', 'https://efactura.client.ro/');
        $app['config']->set('factura.token', 'tok_secret_123');
        $app['config']->set('factura.app_name', 'Magazin');
    }

    protected function defineDatabaseMigrations(): void
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../vendor/webrteu/invoicing/database/migrations/create_invoices_tables.php.stub';
        $migration->up();
    }
}
