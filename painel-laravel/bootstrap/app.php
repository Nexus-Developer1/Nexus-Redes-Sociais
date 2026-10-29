<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 419 = a página esteve aberta tempo demais e o formulário já não é válido.
        // Em vez da página "Page Expired", volta atrás com o texto escrito e um aviso.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }
            if (! $request->user()) {
                return redirect()->route('login')
                    ->with('erro', 'A sessão expirou. Entre outra vez e repita o que estava a fazer.');
            }

            return back()->withInput($request->except('_token', 'password'))
                ->with('erro', 'A página esteve aberta muito tempo. Confirme e envie outra vez.');
        });
    })->create();
