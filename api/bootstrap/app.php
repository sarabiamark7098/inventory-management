<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        /*
         * Laravel defaults to redirecting unauthenticated users to route('login').
         * This application is an API with no such route, so that default throws
         * RouteNotFoundException from *inside* the auth middleware — before the
         * exception handler can turn it into a 401 — and the caller gets a 500.
         *
         * Returning null means "do not redirect", which lets the handler below
         * render a clean 401 regardless of the request's Accept header.
         */
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * This application has no web login page. Without this, an
         * unauthenticated request that does not send `Accept: application/json`
         * — a browser opened straight at an API URL, a monitoring probe, a
         * misconfigured client — makes Laravel try to redirect to route('login')
         * and die with "Route [login] not defined". The caller sees a 500 and
         * the real cause (no credentials) is buried in a stack trace.
         */
        $exceptions->render(
            fn (AuthenticationException $e) => response()->json(
                ['error' => 'Authentication required.'],
                401,
            ),
        );
    })->create();
