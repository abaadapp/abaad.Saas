<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'ability' => \App\Http\Middleware\CheckAbility::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\NormalizeMoneyInput::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * لا توجد مسارات api/* في هذا التطبيق، فقصرُ JSON عليها كان يعني أن
         * كل نداءات fetch (نقطة البيع خصوصًا) تتلقّى تحويلة 302 إلى صفحة HTML
         * بدل 422 بأخطائها — فتُبتلع رسالة الخطأ ويبدو الطلب وكأنه نجح.
         * expectsJson() لا يشمل طلبات Inertia (تقبل text/html)، فتبقى
         * تحويلاتها ورسائلها في الجلسة كما هي.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
