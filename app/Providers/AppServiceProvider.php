<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;

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
        Socialite::extend('telegram', function ($app) {
            $config = [
                'bot' => config('services.telegram.bot'),
                'client_id' => config('services.telegram.client_id'),
                'client_secret' => config('services.telegram.client_secret'),
                'redirect' => config('services.telegram.redirect'),
            ];

            return new \App\Socialite\TelegramProvider(
                $app['request'],
                $config['client_id'],
                $config['client_secret'],
                $config['redirect']
            );
        });
    }
}