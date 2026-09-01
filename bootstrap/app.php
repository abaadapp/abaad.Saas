<?php

use App\Http\Middleware\BindPosBranch;
use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\CheckPlanFeature;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\EntersPanel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeMoneyInput;
use App\Http\Middleware\RequiresBusiness;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'ability' => CheckAbility::class,
            // قدرات الباقة — أخو 'ability' وليس هو: ذاك يسأل عن الموظّف وهذا عن المشترَك
            'plan' => CheckPlanFeature::class,
            'panel' => EntersPanel::class,
            'business' => RequiresBusiness::class,
            'tenant' => CheckTenantStatus::class,
            'pos.branch' => BindPosBranch::class,
        ]);
        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            NormalizeMoneyInput::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));

        /*
         * إشعارات ميتا لا تحمل رمز CSRF — وليست متصفّحًا.
         *
         * وحارسها ليس غياب الفحص: التوقيع (HMAC بسرّ التطبيق) يُفحص في
         * المتحكّم، ولا يُقبل شيءٌ بدونه. واستثناءٌ بمسارٍ واحد لا بنمطٍ
         * واسع — `webhooks/*` غدًا قد تشمل ما لا يُوقَّع.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/whatsapp']);
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
