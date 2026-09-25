<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\StorePaymentIntent;
use App\Support\Store\Paymob;
use App\Support\Store\WebCheckout;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * جوابُ البوّابة — الإشعارُ يكتب، وصفحةُ العودة تقرأ.
 *
 * ═══ ولمَ لا يُصدَّق ما يعود مع الزائر ═══
 *
 * Paymob تُعيد الزائرَ إلى متجرنا برابطٍ فيه نتيجةُ الدفع. وذاك الرابط في
 * يد الزائر: من كتب `success=true` بيده صار طلبُه مدفوعًا بلا أن يدفع.
 *
 * فالكتابةُ من الإشعار الموقَّع وحدَه (`callback`)، وصفحةُ العودة
 * (`back`) تقرأ ما كُتب ولا تكتب شيئًا. وقد تسبق العودةُ الإشعارَ بثوانٍ
 * — فتقول للزبون «نؤكّد دفعتك» ولا تدّعي فشلًا لم يقع.
 */
class PaymobController extends Controller
{
    /**
     * إشعارُ Paymob — وهو مصدرُ الحقيقة.
     *
     * ويُردّ ٢٠٠ في كلّ حال: البوّابةُ تُعيد الإرسال على كلّ جوابٍ غيرِ
     * ناجح، فإشعارٌ مرفوضٌ لتوقيعٍ خاطئ يُعاد إلينا أبدًا. والرفضُ يُسجَّل
     * ولا يُفعَل به شيء.
     */
    public function callback(Request $request)
    {
        $obj = (array) $request->input('obj', []);
        $reference = (string) data_get($obj, 'order.merchant_order_id', '');

        $intent = $reference !== ''
            ? StorePaymentIntent::where('reference', $reference)->first()
            : null;

        if ($intent === null) {
            Log::warning('paymob: إشعارٌ بمرجعٍ لا نعرفه', ['reference' => $reference]);

            return response('ok', 200);
        }

        $gateway = Paymob::gateway((int) $intent->business_id);

        if ($gateway === null || ! Paymob::verify($obj, $request->query('hmac'), (string) $gateway->hmac_secret)) {
            Log::warning('paymob: توقيعٌ لا يطابق', ['intent' => $intent->id]);

            return response('ok', 200);
        }

        $flag = fn (string $key) => filter_var(data_get($obj, $key), FILTER_VALIDATE_BOOL);

        /*
         * ═══ و«نجحت» وحدَها لا تعني أنّ المالَ صار لصاحب المحلّ ═══
         *
         * كان هذا البابُ يسأل `success` ولا شيءَ غيرَها. و Paymob لا ترسل
         * نوعَ حدثٍ، بل **أعلامًا** تُقرأ معًا — وتوقيعُنا يحمل منها
         * `pending` و`is_voided` و`is_refunded` (انظر `Paymob::HMAC_FIELDS`)
         * ثمّ لا يقرؤها أحد. فثلاثُ حالاتٍ تمرّ على أنّها بيعٌ تامّ:
         *
         *   ١) `pending` مع `success`: العمليّةُ **مُعلَّقةٌ بانتظار
         *      التأكيد** — أُذن بها ولم تُقبض. فيُنشأ الطلبُ ويُخصم
         *      المخزونُ على مالٍ قد لا يصل أبدًا.
         *   ٢) `is_voided`: أُلغيت قبل التسوية، والمالُ رُدّ.
         *   ٣) `is_refunded`: استُرجعت بعدها.
         *
         * والثانيةُ والثالثةُ تصلان بـ`success = true` لأنّ العمليّةَ
         * الأصليّةَ نجحت فعلًا — فالإلغاءُ حدثٌ عليها لا نفيٌ لها. ولو
         * سبق إشعارُ الإلغاءِ إشعارَ النجاح (أو ضاع الثاني) لَخرجت
         * البضاعةُ من الرفّ على مالٍ عاد إلى الزبون.
         *
         * ولا يُشترط `is_capture`: هي `true` في **قبضٍ لاحقٍ لإذنٍ سابق**
         * وحدَه، والدفعةُ العاديّةُ ذاتُ الخطوة الواحدة تصل بها `false`.
         * فاشتراطُها يردّ كلّ دفعةٍ سليمة.
         */
        $settled = $flag('success') && ! $flag('pending') && ! $flag('is_voided') && ! $flag('is_refunded');

        if (! $settled) {
            /*
             * والمعلَّقةُ ليست فشلًا فلا تُكتب خطأً: إشعارُها الأخيرُ آتٍ،
             * وكتابةُ «رُدّت الدفعة» الآن تكذب على التاجر وعلى الزبون معًا.
             */
            if ($flag('pending') && $flag('success')) {
                Log::info('paymob: دفعةٌ معلَّقةٌ بانتظار التأكيد', ['intent' => $intent->id]);

                return response('ok', 200);
            }

            // ومحاولةٌ ردّها البنكُ لا تُغلق النيّة: قد يعيد الكرّة ببطاقةٍ أخرى
            $intent->fill(['error' => Str::limit($this->why($obj, $flag), 500)])->save();

            return response('ok', 200);
        }

        /*
         * ═══ وما قُبض هو ما طُلب — مبلغًا وعملة ═══
         *
         * التوقيعُ يشمل `amount_cents` و`currency`، فلا يبدّلهما غريب.
         * لكنّه **لا يقول إنّهما يطابقان طلبَنا**: توقيعٌ صحيحٌ على مبلغٍ
         * آخر يبقى صحيحًا. وتكاملٌ مضبوطٌ على عملةٍ أخرى، أو قبضٌ جزئيّ،
         * أو نيّةٌ أُعيد استعمالُها بمبلغٍ مختلف — كلُّها تصل موقَّعةً
         * وتمرّ. فتخرج باقةٌ بـ٢١ ريالًا على بيسةٍ واحدةٍ قُبضت.
         *
         * والمقارنةُ بوحدة القبض نفسِها: ألفُ بيسةٍ في الريال (انظر
         * `Paymob::open`)، فلا يُقارَن عشريٌّ بعشريّ.
         */
        $wanted = (int) round(((float) $intent->amount) * 1000);
        $got = (int) data_get($obj, 'amount_cents', 0);
        $currency = mb_strtoupper(trim((string) data_get($obj, 'currency', '')));

        if ($got !== $wanted || $currency !== mb_strtoupper((string) $intent->currency)) {
            Log::warning('paymob: مبلغٌ أو عملةٌ لا تطابق النيّة', [
                'intent' => $intent->id, 'wanted' => $wanted, 'got' => $got, 'currency' => $currency,
            ]);

            $intent->fill(['error' => 'المبلغُ أو العملةُ لا يطابقان ما طُلب'])->save();

            return response('ok', 200);
        }

        $this->settle($intent, (string) data_get($obj, 'id', ''));

        return response('ok', 200);
    }

    /** لمَ لم تُحتسب هذه الدفعة — بلفظٍ يقرؤه صاحبُ المحلّ في جرسه */
    private function why(array $obj, callable $flag): string
    {
        if ($flag('is_voided')) {
            return 'أُلغيت الدفعةُ قبل تسويتها';
        }

        if ($flag('is_refunded')) {
            return 'استُرجعت الدفعةُ إلى الزبون';
        }

        return (string) data_get($obj, 'data.message', 'رُدّت الدفعة');
    }

    /**
     * يُثبّت الدفعةَ وينشئ طلبَها — مرّةً واحدةً مهما تكرّر الإشعار.
     *
     * والحارسُ فهرسٌ فريدٌ على معرّف العمليّة، لا فحصٌ يقرأ ثمّ يكتب: Paymob
     * تُعيد الإرسال، وإشعاران متقاربان يمرّان من الفحص معًا فيُنشأ للطلب
     * الواحد طلبان ويُخصم المخزونُ مرّتين.
     */
    private function settle(StorePaymentIntent $intent, string $transactionId): void
    {
        try {
            $claimed = DB::transaction(fn () => StorePaymentIntent::where('id', $intent->id)
                ->whereNull('provider_transaction_id')
                ->update([
                    'provider_transaction_id' => $transactionId,
                    'status' => StorePaymentIntent::PAID,
                    'paid_at' => now(),
                ]));
        } catch (QueryException) {
            // سبقَنا إشعارٌ بالمعرّف نفسِه — وهو نجاحٌ لا خطأ
            return;
        }

        if ($claimed === 0) {
            return;
        }

        $business = Business::find($intent->business_id);

        if ($business === null) {
            return;
        }

        /*
         * ═══ والمالُ وصل — فلا يُردّ إنشاءُ الطلب بصمت ═══
         *
         * قد ينفد الصنفُ بين لحظةِ الدفع ولحظةِ التصديق، فتردّ `place`
         * الطلبَ والمالُ مقبوض. ولا تُحلّ في كود: يردُّ صاحبُ المحلّ المالَ
         * أو يجهّز بديلًا، وكلاهما قرارُه. فتُحفظ الدفعةُ بلا طلبٍ ويُقال
         * له في جرسه (انظر `StorePaymentIntent::strayPayment`).
         */
        try {
            $order = WebCheckout::place($business, (array) $intent->payload, (string) $intent->lang, paid: true);

            $intent->fill(['order_id' => $order->id, 'error' => null])->save();
        } catch (\Throwable $e) {
            Log::error('paymob: دُفع ولم يُنشأ طلب', ['intent' => $intent->id, 'why' => $e->getMessage()]);

            $intent->fill(['error' => Str::limit($e->getMessage(), 500)])->save();
        }
    }
}
