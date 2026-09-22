<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $ca = storage_path('certificates/cacert.pem');
        if (!is_file($ca)) {
            return;
        }

        // PHP Windows n'a souvent pas de magasin CA : on force le bundle
        // fourni avec l'API pour tous les appels HTTPS Laravel.
        if (!ini_get('curl.cainfo')) {
            @ini_set('curl.cainfo', $ca);
        }
        if (!ini_get('openssl.cafile')) {
            @ini_set('openssl.cafile', $ca);
        }
        Http::globalOptions(['verify' => $ca]);
    }
}
