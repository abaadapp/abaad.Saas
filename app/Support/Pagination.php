<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * شكل الترقيم الذي يقرأه DataTable في وضعه الخادمي.
 *
 * القوائم الكبيرة (المنتجات، العملاء، الطلبات، سجل النشاط) تبقى مرقّمة على
 * الخادم: إرسالها كاملة إلى المتصفح ليرشّحها محليًا لا يحتمله متجر بآلاف السجلات.
 */
class Pagination
{
    public static function meta(LengthAwarePaginator $p): array
    {
        return [
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'from' => $p->firstItem(),
            'to' => $p->lastItem(),
            'total' => $p->total(),
            'prev_page_url' => $p->previousPageUrl(),
            'next_page_url' => $p->nextPageUrl(),
        ];
    }
}
