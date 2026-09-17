<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

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
        Password::defaults(function () {
            return Password::min(8)
                ->letters()
                ->numbers();
        });

        if ($this->app->runningInConsole()) return;
        
        $request = request();
        
        // Otomatis force HTTPS & Root URL jika diakses via ngrok atau proxy HTTPS
        $isNgrok = $request->server('HTTP_X_FORWARDED_PROTO') === 'https' || 
                   str_contains($request->getHost(), 'ngrok');

        if ($isNgrok) {
            URL::forceScheme('https');
            URL::forceRootUrl('https://' . $request->getHost());
        }

        // Jika request datang dari NGROK atau host eksternal (bukan localhost/127.0.0.1 dev machine):
        // Device lain tidak bisa mengakses Vite dev server (localhost:5173).
        // Oleh karena itu, arahkan Vite untuk menggunakan manifest bundle di public/build.
        $isLocalDevMachine = in_array($request->getHost(), ['localhost', '127.0.0.1']);
        if (!$isLocalDevMachine) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('vite.hot'));
        }
    }
}
