<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * قاعدةُ العزل: البيانات الوهميّة لا تُكتب إلا في متجرٍ موسومٍ تجريبيًّا.
 *
 * العزل بين التجّار قائمٌ أصلًا بـ`business_id`، وهو يمنع تاجرًا أن يقرأ
 * بيانات آخر. لكنّه لا يمنع شيئًا آخر: أن يُكتب الوهميّ في الحقيقيّ. أمرٌ
 * يُشغَّل بمعرّفٍ خاطئ، أو زرٌّ في اللوحة يُضغط والمتجر المفتوح غير المقصود —
 * فتدخل مئةُ فاتورةٍ ملفَّقة دفترَ تاجرٍ يحاسب بها ضريبته.
 *
 * ولا يُدفع هذا بالانتباه: يُدفع ببابٍ واحد لا يُفتح إلا للموسوم. فكلّ ما
 * يكتب بيانات ديمو يمرّ من هنا، ومن نسي أن يمرّ يكسر اختبارًا.
 *
 * وحدٌّ ثانٍ فوقه: لا يُوسَم متجرٌ فيه بيانات تاجرٍ فعليّة. الوسمُ يُمنح عند
 * الإنشاء لا بعده، فلا يُحوَّل حسابُ تاجرٍ قائمٍ إلى «ديمو» ثمّ يُمسح.
 */
class DemoGuard
{
    /**
     * يتحقّق أن هذا المتجر موسومٌ تجريبيًّا — أو يرفض.
     *
     * @throws RuntimeException
     */
    public static function assertDemo(?Business $business): Business
    {
        if (! $business) {
            throw new RuntimeException(__('لا متجر تجريبيّ — أنشئه أوّلًا.'));
        }

        if (! $business->is_demo) {
            throw new RuntimeException(__(
                'المتجر «:name» ليس تجريبيًّا. البيانات الوهميّة لا تُكتب في حساب تاجر.',
                ['name' => $business->name],
            ));
        }

        return $business;
    }

    /**
     * ‏‏ويُمحى المتجرُ التجريبيّ (إعادةُ البناء أو الحذف) ما لم يكن عليه سجلُّ واتساب يُحاسَب به — أو يرفض.
     *
     * المحوُ يمرّ على كلّ جدولٍ فيه `business_id` (`DemoStore::wipe`)، ومنها مراجعةُ جهة الرسوم وتجربتُها
     * وأحداثُها، وأسعارُ ما خرج، والرسائلُ التي وصلت Meta. وهي دليلُ من دفع ماذا: لا تُحذف بزرّ، ولو
     * كان المتجرُ تجريبيًّا — يُغلق ما يُغلق منها بآليّته، ويبقى ما كُتب.
     *
     * @throws RuntimeException
     */
    public static function assertWipeable(Business $business): void
    {
        $id = (int) $business->id;
        $kept = collect(['whatsapp_billing_trials', 'whatsapp_billings', 'whatsapp_billing_events', 'whatsapp_message_prices'])
            ->contains(fn (string $table) => DB::table($table)->where('business_id', $id)->exists())
            || DB::table('whatsapp_messages')->where('business_id', $id)->whereNotNull('provider_message_id')->exists();

        if ($kept) {
            throw new RuntimeException(__(
                'على المتجر «:name» سجلات واتساب (جهة الرسوم أو تجربتها أو رسائل خرجت إلى Meta) — لا يُعاد بناؤه ولا يُحذف، فهي دليل ما دُفع.',
                ['name' => $business->name],
            ));
        }
    }

    /**
     * هل يجوز وسمُ هذا المتجر تجريبيًّا؟
     *
     * لا يجوز لمتجرٍ فيه حركةٌ فعليّة. والوسمُ يُخرجه من الإحصاءات ويُبيح
     * مسحَه بزرٍّ واحد، فوسمُ حسابِ تاجرٍ عاملٍ بالخطأ يعني اختفاءه من
     * التقارير ثمّ حذفَه بلا سؤال.
     */
    public static function markable(Business $business): bool
    {
        return $business->is_demo || ! $business->orders()->exists();
    }
}
