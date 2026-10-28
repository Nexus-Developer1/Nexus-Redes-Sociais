<?php

namespace App\Providers;

use App\Mail\MicrosoftGraphTransport;
use Illuminate\Support\Facades\Mail;
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
        // MAIL_MAILER=msgraph: email pela API Microsoft Graph (Microsoft 365).
        Mail::extend('msgraph', fn (array $config) => new MicrosoftGraphTransport(
            (string) $config['tenant_id'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['sender'],
        ));
    }
}
