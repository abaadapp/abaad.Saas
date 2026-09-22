<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use App\Support\Document\Snapshot;
use Illuminate\Support\Facades\DB;

/**
 * رقمُ الفاتورة — بادئتُه كما اختارها التاجر، وتسلسلُه من الدفتر نفسِه.
 *
 * نُقلت من `PosController` بالحرف يومَ صار للموقع بابُ بيعٍ ثانٍ: فاتورةُ
 * الموقع وفاتورةُ الصندوق تتلوان في تسلسلٍ واحد، لا عدّادان يتصادمان.
 * انظر `SaleLines` للسبب نفسِه.
 */
final class OrderNumbers
{
    public function __construct(private readonly int $businessId) {}

    private function bid(): int
    {
        return $this->businessId;
    }

    private function setting(string $key, $default = null)
    {
        return Setting::where('business_id', $this->bid())->where('key', $key)->value('value') ?? $default;
    }

    /**
     * رقم متسلسل لكل نشاط.
     *
     * كان random_int(78900, 99999) بلا قيد فريد: 21,100 قيمة فقط تعني احتمال
     * تصادم ≈61% خلال 200 فاتورة — أي فاتورتين مختلفتين تحملان الرقم نفسه.
     */
    public function nextNumber(string $prefix, int $start = 1): string
    {
        $offset = strlen($prefix) + 1; // عدد صحيح من strlen، فلا خطر حقن هنا

        // SQLite تتساهل مع CAST لنصّ غير رقمي فتُرجع 0، أما PostgreSQL فترفع
        // «invalid input syntax for type integer». ولأن هذا السطر يجري مع كل
        // بيعة، رقمٌ واحد شاذّ — من نسخة مستعادة أو إدخال يدوي — كان يكفي
        // لتعطيل الصندوق كلّه بعد النقل إلى PostgreSQL.
        $driver = DB::connection()->getDriverName();
        $suffix = match ($driver) {
            'pgsql' => "NULLIF(regexp_replace(SUBSTRING(number FROM {$offset}), '\\D', '', 'g'), '')::bigint",
            'mysql', 'mariadb' => "CAST(SUBSTRING(number, {$offset}) AS UNSIGNED)",
            default => "CAST(SUBSTR(number, {$offset}) AS INTEGER)",
        };

        $last = Order::where('business_id', $this->bid())
            ->where('number', 'like', $prefix.'%')
            ->orderByRaw("{$suffix} DESC")
            ->value('number');

        // أوّل فاتورةٍ بهذه البادئة تبدأ من الرقم الذي اختاره التاجر، وما بعدها
        // يتبع الأخير. ولذلك تغيير البادئة يفتح تسلسلًا جديدًا لا يصطدم بالقديم.
        $n = $last ? (int) substr($last, strlen($prefix)) : max(0, $start - 1);

        return $prefix.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * بادئة رقم الفاتورة كما اختارها التاجر.
     *
     * كانت 'INV-' مثبّتةً في الكود بينما الإعدادات تعرض حقلًا يُحفظ ولا يُقرأ.
     * والتنقية ليست زينة: البادئة تدخل شرط LIKE، فـ«%» فيها تجعل كل فاتورةٍ
     * مطابقةً فيقفز العدّاد إلى رقمٍ لا يخصّها.
     */
    public function salePrefix(): string
    {
        $raw = trim((string) $this->setting('inv_prefix', 'INV-'));
        $clean = str_replace(['%', '_', '\\'], '', $raw);

        return $clean === '' ? 'INV-' : mb_substr($clean, 0, 12);
    }

    /**
     * ينشئ الطلب برقم فريد، ويعيد المحاولة إن سبقه كاشير آخر إلى الرقم نفسه.
     *
     * وكلُّ محاولةٍ في نقطة حفظها: على PostgreSQL تُجهض المحاولةُ الأولى
     * الفاشلة المعاملةَ كلَّها، فتسقط الأربعُ الباقيات لسببٍ وقع مرّةً —
     * ويُردّ الكاشيرُ عن بيعةٍ رقمُها كان متاحًا. انظر `Contention`.
     */
    public function createNumbered(array $attrs, string $prefix, int $start = 1): Order
    {
        return Contention::retry(
            /*
             * والرقمُ المولَّد يسبق `$attrs` في الدمج.
             *
             * `+` على المصفوفات **لا يدهس** مفتاحًا موجودًا: فلو حمل
             * `$attrs` يومًا `number` — سطرٌ يُضاف بحسن نيّة يقرأ الطلب —
             * لسقط الرقمُ المولَّد صامتًا ومضى رقمُ العميل. ولا يظهر ذلك في
             * اختبار: الرقمُ يبقى فريدًا لأنّ فهرسَ التفرّد لا يعرف من كتبه.
             *
             * وتُختم الورقةُ بحال متجرها يومها — انظر `Document\Snapshot`.
             */
            fn () => Order::create([
                'number' => $this->nextNumber($prefix, $start),
                Snapshot::COLUMN => Snapshot::capture($this->bid()),
            ] + $attrs),
        );
    }
}
