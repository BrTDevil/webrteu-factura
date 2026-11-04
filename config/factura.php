<?php

declare(strict_types=1);

return [

    /*
    | Adresa serverului de facturare (aplicația factura.webrt.eu instalată la client), fără /api/v1.
    */
    'url' => env('FACTURA_URL'),

    /*
    | Token API: din /api-settings/clients (recomandat, câte unul per site) sau API_TOKEN global.
    */
    'token' => env('FACTURA_TOKEN'),

    /*
    | Numele aplicației, afișat pe server. Ignorat de server când tokenul e per-magazin.
    */
    'app_name' => env('FACTURA_APP_NAME', env('APP_NAME')),

    /*
    | true = factură de test (fără valoare fiscală), false = reală, null = cum e setat pe server (ANAF_TEST_MODE).
    */
    'test' => env('FACTURA_TEST') === null ? null : (bool) env('FACTURA_TEST'),

    /*
    | Codul CAEN pus în notele facturii (opțional).
    */
    'caen' => env('FACTURA_CAEN'),

    /*
    | Statusul ANAF se citește cu factura:sync. Serverul permite 3 cereri pe minut per IP, în total.
    */
    'sync' => [
        'batch' => (int) env('FACTURA_SYNC_BATCH', 2),
        'max_age_days' => 30,
    ],

    'timeout' => (int) env('FACTURA_TIMEOUT', 30),

];
