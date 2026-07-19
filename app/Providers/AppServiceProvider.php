<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
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
        // إتاحة استخدام <x-layouts.xxx> للتخطيطات الموجودة في resources/views/layouts
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');

        // أسماء الشهور والأيام بالعربية في كل استخدامات translatedFormat (Carbon)
        \Carbon\Carbon::setLocale('ar');
        setlocale(LC_TIME, 'ar_OM.UTF-8', 'ar_SA.UTF-8', 'ar');
    }
}
