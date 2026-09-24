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
        /*
         * مزوّدُ نطاقات التجّار — واحدٌ اليوم وقد يُبدَّل غدًا.
         *
         * والربطُ هنا لا في مكان الاستعمال: يوم يُتعاقَد مع مزوّدٍ يتولّى
         * الشهادات، يُبدَّل هذا السطرُ وحده — ولا تُفتح شاشةٌ ولا نموذج.
         */
        $this->app->bind(
            \App\Support\Website\Domain\CustomDomainProvider::class,
            \App\Support\Website\Domain\ManualDnsProvider::class,
        );

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // إتاحة استخدام <x-layouts.xxx> للتخطيطات الموجودة في resources/views/layouts
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');

        // إعدادات المنصة تُطبَّق على النظام — لا تُحفظ وتُنسى (انظر PlatformConfig)
        \App\Support\PlatformConfig::apply();

        /*
         * وذاكرةُ الإعدادات تموت مع الطلب — وعاملُ الطابور لا طلبَ له.
         *
         * `MarketingSettings::group` تحفظ ما قرأت لتُجيب مرّةً بدل سبعَ عشرةَ
         * مرّةً في صفحةٍ واحدة، وتُبطَل عند كلّ كتابةٍ في هذه العمليّة
         * (`Setting::booted`). وتحت php-fpm تموت الذاكرةُ بنهاية كلّ طلب،
         * فلا يبقى إلّا العامل: يعيش ساعات، ويُبدّل تاجرٌ إعداداته من
         * المتصفّح، فيبقى العاملُ يقرأ ما كان.
         *
         * وهي القاعدةُ المكتوبة فوق `Demo::$baseCur` نفسُها — ذاكرةٌ لا
         * تُعرف حدودُها تُخدِّم متجرًا بإعدادات غيره.
         */
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => \App\Support\MarketingSettings::forget(),
        );

        /*
         * من يسمع تغيّر حالة الطلب.
         *
         * المراقب على النموذج لا على المتحكّمات: ثلاثة مواضع تكتب الحالة
         * اليوم، ورابعٌ يُضاف غدًا فينساه من يكتبه — والمراقب يسمع الكتابة
         * نفسها فلا يفوته موضع.
         */
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderStatusChanged::class,
            \App\Listeners\SendWhatsAppOnOrderStatus::class,
        );

        // لغة افتراضية لـ Carbon قبل معالجة الطلب (الأوامر المجدولة مثلًا).
        // داخل الطلب يعيد SetLocale ضبطها على لغة المستخدم، وإلا بقيت التواريخ
        // النسبية («منذ 19 دقيقة») عربية حتى في الواجهة الإنجليزية.
        \Carbon\Carbon::setLocale('ar');
        setlocale(LC_TIME, 'ar_OM.UTF-8', 'ar_SA.UTF-8', 'ar');
    }
}
