<?php

namespace App\Providers;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
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
        // Indexed strings must fit MySQL's utf8mb4 index limit in production.
        Builder::defaultStringLength(191);

        // Relative asset URLs: the same page is served on localhost, the Wi-Fi address and the tunnel address.
        Vite::createAssetPathsUsing(fn (string $path) => '/'.ltrim($path, '/'));

        // Online, every link and redirect is https (phones need it for the camera) once the certificate exists.
        URL::forceHttps(config('attendance.force_https'));
    }
}
