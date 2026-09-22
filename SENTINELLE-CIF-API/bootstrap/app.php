<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\NormalizeApiJson;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // L'application est une API : une session expirée doit produire un
        // JSON 401, jamais tenter une redirection vers une route web /login.
        $middleware->redirectGuestsTo(null);

        // Normalisation uniforme des réponses JSON de l'API sans modifier
        // la logique métier des contrôleurs ni les vues/procédures SQL.
        $middleware->append(NormalizeApiJson::class);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
