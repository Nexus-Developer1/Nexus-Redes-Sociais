<?php

namespace App\Providers;

use App\Mail\MicrosoftGraphTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        // Login: 10 tentativas por minuto por computador e, por conta, 5 por minuto e 20 por hora
        // (quem tenta adivinhar a palavra-passe de uma conta a partir de vários computadores também pára).
        RateLimiter::for('login', function (Request $request) {
            $conta = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('conta:'.$conta),
                Limit::perHour(20)->by('conta-hora:'.$conta),
            ];
        });
        // Pedidos à IA (pedir um post, a IA faz, trocar tema, propor ideias, repetir): no máximo 10 por minuto por pessoa.
        RateLimiter::for('ia', fn (Request $request) => Limit::perMinute(10)->by('ia:'.($request->user()?->id ?? $request->ip())));

        // MAIL_MAILER=msgraph: email pela API Microsoft Graph (Microsoft 365).
        Mail::extend('msgraph', fn (array $config) => new MicrosoftGraphTransport(
            (string) $config['tenant_id'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['sender'],
        ));
    }
}
