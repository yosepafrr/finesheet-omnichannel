<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        if ($this->app->runningInConsole()) return;
        
        $request = request();
        
        // Otomatis force HTTPS jika diakses via ngrok atau proxy HTTPS
        if ($request->server('HTTP_X_FORWARDED_PROTO') === 'https' || 
            str_contains($request->getHost(), 'ngrok')) {
            URL::forceScheme('https');
        }
    }
}
