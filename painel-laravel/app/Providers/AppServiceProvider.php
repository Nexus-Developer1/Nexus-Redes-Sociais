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
        // Ficheiros novos legíveis por todos (rw-r--r--): as imagens dos posts e da biblioteca são servidas pelo
        // Apache (www-data), que não é do grupo da app. No servidor o PHP corre com umask 027, o que deixava as
        // imagens geradas sem leitura para o Apache (imagem partida no painel). Vale também para o Chrome que
        // gera as imagens, porque herda a umask. O que é privado está em pastas que o Apache não atravessa.
        umask(0022);
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
