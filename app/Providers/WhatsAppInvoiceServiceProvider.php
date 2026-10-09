<?php

namespace App\Providers;

use App\Http\Controllers\Admin\WhatsAppInvoiceController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * بابُ إرسال فاتورة العميل عبر رقم المتجر في واتساب.
 *
 * يُسجَّل بعد اكتمال إقلاع التطبيق حتى نستطيع إبقاء مسار `/remind` القديم
 * متوافقًا مع الواجهة الحالية، مع توفير الاسم الصريح الجديد `/whatsapp`.
 * وبعد نقل الزرّ إلى المسار الجديد يمكن حذف الاسم القديم بلا كسر روابطٍ
 * محفوظة أو نسخة واجهة لم تُحدَّث بعد.
 */
class WhatsAppInvoiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $middleware = ['web', 'auth', 'tenant', 'business', 'panel', 'ability', 'plan'];

            Route::post('/admin/customer-invoices/{id}/whatsapp', [WhatsAppInvoiceController::class, 'send'])
                ->middleware($middleware)
                ->name('admin.customerInvoices.whatsapp');

            /* توافقٌ مؤقّت مع زرّ الفاتورة القائم الذي ينادي `/remind`. */
            Route::post('/admin/customer-invoices/{id}/remind', [WhatsAppInvoiceController::class, 'send'])
                ->middleware($middleware)
                ->name('admin.customerInvoices.remind');
        });
    }
}
