<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\WhatsAppMessagePack;
use Illuminate\Support\Facades\DB;

/**
 * حزمةُ رسائلَ تُشترى حين تنفد الحصّة — من الطلب إلى الرصيد.
 *
 * ═══ ولمَ لا يُشحن الرصيد بضغطةٍ واحدة ═══
 *
 * لا بوّابةَ دفعٍ في المستودع كلِّه: لا ثواني ولا سواها، ولا مسارَ ردٍّ
 * يستقبل نتيجةَ عمليّة (انظر `Website\Commerce`). فـ«ادفع الآن» زرٌّ يكذب.
 *
 * والطريقُ هو ما يجري فعلًا اليوم في تجديد الاشتراكات: يطلب التاجر، تُصدَر
 * فاتورةُ منصّةٍ برقمها، يُحوِّل بنكيًّا، يُسجّل صاحبُ المنصّة السداد —
 * **وعندها وحدها** يُضاف الرصيد. لا قبله.
 *
 * ═══ وأربعُ حالاتٍ لا ثلاث ═══
 *
 *   `مطلوبة` — كتبها التاجر ولم يرَها أحدٌ بعد. تُلغى بلا أثر.
 *   `مصدَّرة` — عليها فاتورةٌ برقمٍ في دفتر المنصّة. إلغاؤها يترك الفاتورة
 *               قائمةً: رقمٌ صُرف لا يُعاد، وإلغاؤه شأنُ دفتر الفواتير.
 *   `مدفوعة` — أُضيف رصيدُها. نهاية.
 *   `ملغاة`  — انتهت بلا رصيد.
 *
 * والفصلُ بين `مطلوبة` و`مصدَّرة` ليس زينة: الأولى تعني «ينتظرك عمل» في
 * لوحة المنصّة، والثانية تعني «ينتظرنا مال». وخلطُهما يجعل صاحبَ المنصّة
 * يُصدر فاتورةً ثانيةً لمن أصدر له.
 */
class WhatsAppPacks
{
    public const REQUESTED = 'مطلوبة';

    public const INVOICED = 'مصدَّرة';

    public const PAID = 'مدفوعة';

    public const CANCELLED = 'ملغاة';

    /** ما لم يُحسم بعد — طلبٌ مفتوحٌ يمنع طلبًا ثانيًا */
    public const OPEN = [self::REQUESTED, self::INVOICED];

    /** إعدادا المنصّة: حجمُ الحزمة وثمنُها */
    public const SIZE_KEY = 'whatsapp_pack_size';

    public const PRICE_KEY = 'whatsapp_pack_price';

    public const FALLBACK_SIZE = 500;

    public const FALLBACK_PRICE = 5.0;

    private static function setting(string $key): ?string
    {
        $raw = Setting::whereNull('business_id')->where('key', $key)->value('value');

        return $raw === null || $raw === '' ? null : (string) $raw;
    }

    /** كم رسالةً في الحزمة الواحدة */
    public static function size(): int
    {
        return max(1, (int) (self::setting(self::SIZE_KEY) ?? self::FALLBACK_SIZE));
    }

    /** ثمنُ الحزمة بالريال العُماني */
    public static function price(): float
    {
        return max(0.0, (float) (self::setting(self::PRICE_KEY) ?? self::FALLBACK_PRICE));
    }

    /** الطلبُ المفتوح لهذا المتجر إن كان — واحدٌ لا أكثر */
    public static function open(Business|int $business): ?WhatsAppMessagePack
    {
        $id = $business instanceof Business ? $business->id : $business;

        return WhatsAppMessagePack::where('business_id', $id)
            ->whereIn('status', self::OPEN)
            ->latest('id')->first();
    }

    /**
     * يطلب التاجر حزمة — ولا يُنشئ ثانيةً وله واحدةٌ مفتوحة.
     *
     * والثمنُ والحجمُ يُنسخان في الصفّ لا يُقرآن من الإعداد لاحقًا: من طلب
     * خمسمئةً بخمسةٍ لا تُصدَر له فاتورةُ سبعةٍ لأنّ السعر تغيّر بين طلبه
     * واعتماده.
     *
     * @return array{pack: WhatsAppMessagePack, created: bool}
     */
    public static function request(Business $business, ?string $byName = null): array
    {
        return DB::transaction(function () use ($business, $byName) {
            if ($open = self::open($business)) {
                return ['pack' => $open, 'created' => false];
            }

            $pack = WhatsAppMessagePack::create([
                'business_id' => $business->id,
                'messages' => self::size(),
                'amount' => self::price(),
                'status' => self::REQUESTED,
                'requested_by_name' => $byName,
            ]);

            Activity::log('created', 'طلب حزمة رسائل واتساب: '.$pack->messages.' رسالة', [
                'business_id' => $business->id,
                'subject_id' => $pack->id,
            ]);

            return ['pack' => $pack, 'created' => true];
        });
    }

    /**
     * يعتمدها صاحبُ المنصّة فتُصدَر فاتورتُها — ولا رصيدَ بعد.
     *
     * و`plan_id` فارغٌ عمدًا: ليست دورةَ باقة. وفاتورةٌ تحمل باقةً تُقرأ في
     * الدفتر تجديدًا، ثمّ يُسأل بعد شهرين لمَ جُدّدت باقةٌ بخمسة ريالات.
     */
    public static function approve(WhatsAppMessagePack $pack): WhatsAppMessagePack
    {
        if ($pack->status !== self::REQUESTED) {
            return $pack;
        }

        return DB::transaction(function () use ($pack) {
            $invoice = Invoice::create([
                'number' => Billing::nextNumber(),
                'business_id' => $pack->business_id,
                'plan_id' => null,
                'amount' => (float) $pack->amount,
                'issued_at' => now(),
                'status' => Billing::UNPAID,
                'kind' => Billing::KIND_MESSAGES,
                'note' => $pack->messages.' رسالة واتساب',
            ]);

            $pack->update(['status' => self::INVOICED, 'invoice_id' => $invoice->id]);

            Activity::log('created', 'أُصدرت فاتورة حزمة رسائل: '.$invoice->number, [
                'business_id' => null,
                'subject_id' => $pack->business_id,
                'subject_type' => 'business',
            ]);

            return $pack->fresh();
        });
    }

    /** إلغاء طلبٍ لم يُدفع — والفاتورةُ المصدَّرة تبقى في دفترها */
    public static function cancel(WhatsAppMessagePack $pack): WhatsAppMessagePack
    {
        if (! in_array($pack->status, self::OPEN, true)) {
            return $pack;
        }

        $pack->update(['status' => self::CANCELLED, 'decided_at' => now()]);

        Activity::log('deleted', 'أُلغي طلب حزمة رسائل واتساب', [
            'business_id' => null,
            'subject_id' => $pack->business_id,
            'subject_type' => 'business',
        ]);

        return $pack->fresh();
    }

    /**
     * سُجّل سدادُ فاتورة — فإن كانت فاتورةَ حزمةٍ أُضيف رصيدُها.
     *
     * تُنادى من `Billing::markPaid` وحدَها، وداخل معاملتها: إضافةُ الرصيد
     * وتقييدُ السداد إمّا أن يقعا معًا أو لا يقع أحدُهما. ولو وقعا منفصلين
     * لَأمكن أن تُقيَّد الفاتورة مدفوعةً ويبقى التاجر بلا رسائل — ولا شيء
     * في النظام يقول إنّ شيئًا نقص.
     *
     * والحارسُ `INVOICED` لا `!= PAID`: نداءٌ ثانٍ على فاتورةٍ سُدّدت مرّةً
     * لا يُضاعف الرصيد.
     */
    public static function settle(Invoice $invoice): void
    {
        $pack = WhatsAppMessagePack::where('invoice_id', $invoice->id)
            ->where('status', self::INVOICED)->first();

        if (! $pack) {
            return;
        }

        $business = Business::find($pack->business_id);

        if (! $business) {
            return;
        }

        WhatsAppQuota::grant($business, (int) $pack->messages);

        $pack->update(['status' => self::PAID, 'decided_at' => now()]);

        Activity::log('created', 'أُضيفت '.$pack->messages.' رسالة إلى رصيد «'.$business->name.'»', [
            'business_id' => null,
            'subject_id' => $business->id,
            'subject_type' => 'business',
        ]);
    }

    /**
     * ما تقرؤه شاشةُ التاجر — العرضُ وحالُ طلبه.
     *
     * ولا يُعرض له معرّفُ الفاتورة ولا حالةُ دفتر المنصّة: رقمُها يكفيه
     * ليُحوّل ويذكره.
     *
     * @return array{size:int, price:float, status:string|null, invoice_number:string|null, requested_at:string|null}
     */
    public static function view(Business $business): array
    {
        $pack = self::open($business);

        return [
            'size' => self::size(),
            'price' => self::price(),
            'status' => $pack?->status,
            'invoice_number' => $pack?->invoice?->number,
            'requested_at' => $pack?->created_at?->format('Y-m-d'),
        ];
    }

    /**
     * طلباتُ المتجر كما تقرؤها لوحةُ المنصّة — المفتوحُ أوّلًا ثمّ التاريخ.
     *
     * @return list<array{id:int, messages:int, amount:float, status:string, invoice_number:string|null, requested_by_name:string|null, created_at:string}>
     */
    public static function forPlatform(Business $business, int $limit = 10): array
    {
        return WhatsAppMessagePack::with('invoice:id,number')
            ->where('business_id', $business->id)
            ->latest('id')->limit($limit)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'messages' => (int) $p->messages,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'invoice_number' => $p->invoice?->number,
                'requested_by_name' => $p->requested_by_name,
                'created_at' => $p->created_at?->format('Y-m-d'),
            ])->all();
    }
}
