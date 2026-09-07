<?php

namespace App\Support;

use App\Models\Business;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * أمرُ الشراء — حالاتُه ورقمُه.
 *
 * ═══ وهو نيّةٌ لا حدث ═══
 *
 * لا يزيد رصيدًا ولا يُنشئ ذمّةً ولا يكتب قيدًا. البضاعةُ تدخل الرفَّ
 * باعتماد الاستلام، والذمّةُ تنشأ باعتماد سند المورّد — وهذا الملفّ لا
 * يمسّ أيًّا منهما.
 *
 * ═══ والحالاتُ الأربع كما هي في القاعدة ═══
 *
 * «مسودة» افتراضُ العمود منذ أوّل هجرة، و«مُرسل» ما كان يُكتب عند الإنشاء،
 * و«مستلم جزئيًا» و«مستلم» يكتبهما `GoodsReceipts::approve`. فلا حالةَ
 * تُخترع هنا: تُسمّى القائمةُ فقط لتُقرأ في موضعٍ واحد.
 */
final class PurchaseOrders
{
    /** لم يُرسل بعد: يُعدَّل ويُحذف، ولا يُستلم عليه شيء */
    public const DRAFT = 'مسودة';

    /** أُرسل إلى المورّد وينتظر شحنته */
    public const SENT = 'مُرسل';

    public const PARTIAL = 'مستلم جزئيًا';

    public const RECEIVED = 'مستلم';

    /**
     * رقمُ الأمر التالي — تسلسلٌ تحت قفلٍ على صفّ المتجر.
     *
     * وكان `'PO-'.random_int(10000, 99999)`: تسعون ألف احتمال، ولا فهرسَ
     * تفرّدٍ على العمود. فمتجرٌ يكتب مئتي أمرٍ احتمالُ تصادمِ رقمين فيه يقارب
     * العشرين بالمئة (مفارقةُ أعياد الميلاد) — ورقمان لأمرين يجعلان البحث
     * يفتح غيرَ ما طُلب، والمطابقةَ تُنسب إلى الأمر الخطأ.
     *
     * والقفلُ ضروريّ: نافذتان تفتحان الشاشة معًا تقرآن آخرَ رقمٍ فتكتبانه
     * كلتاهما.
     */
    public static function nextNumber(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $last = PurchaseOrder::where('business_id', $businessId)
                ->where('number', 'like', 'PO-%')
                ->orderByDesc('id')->value('number');

            /*
             * والأرقامُ القديمة عشوائيّة (`PO-48213`)، فآخرُها ليس أكبرَها.
             *
             * فيُقرأ الأكبرُ عددًا لا الأحدثُ صفًّا حين يُخلط القديمُ بالجديد،
             * وإلّا لَعاد التسلسلُ إلى رقمٍ استُعمل.
             */
            $highest = (int) PurchaseOrder::where('business_id', $businessId)
                ->where('number', 'like', 'PO-%')
                ->get(['number'])
                ->map(fn ($r) => (int) substr($r->number, 3))
                ->max();

            $n = max($highest, $last ? (int) substr($last, 3) : 0) + 1;

            return 'PO-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        });
    }
}
