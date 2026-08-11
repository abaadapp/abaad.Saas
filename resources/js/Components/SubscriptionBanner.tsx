import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** يبدأ التحذير قبل أسبوع — أقصر من ذلك لا يكفي لترتيب دفعة */
const WARN_WITHIN_DAYS = 7;

/**
 * تحذير قرب انتهاء الاشتراك.
 *
 * كان الانتهاء يقع بلا سابق إنذار: يفتح التاجر لوحته صباحًا فيجدها مقفلة،
 * ولا يعرف السبب ولا إلى متى. والتجديد يحدث حين يُذكَّر به قبل الموعد، لا
 * حين تُقفل الأبواب — فالإقفال المفاجئ يُكلّف المنصّة عميلًا لا يُكلّفه شيئًا.
 *
 * ولا يظهر لمن لا مدّة محدَّدة له، ولا قبل الأسبوع الأخير: تحذيرٌ يُرى كل
 * يوم يُقرأ زخرفةً بعد ثلاثة أيام.
 */
export default function SubscriptionBanner() {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const sub = context?.subscription;

    if (!sub || sub.daysLeft > WARN_WITHIN_DAYS) {
        return null;
    }

    // اليوم الأخير وما بعده أحمر؛ ما قبله كهرماني
    const urgent = sub.daysLeft <= 1;

    /*
     * بعد الانتهاء تبدأ المهلة، والشريط يعدّ ما بقي منها لا يكتفي بـ«انتهى».
     * من لا يعرف موعد الإقفال يؤجّل إلى أن يفاجئه — وهو ما كان يحدث.
     */
    const line =
        sub.daysLeft < 0
            ? (sub.graceLeft ?? 0) > 0
                ? t('انتهى اشتراك المتجر. يتوقّف النظام بعد :n يوم.', { n: sub.graceLeft ?? 0 })
                : sub.graceLeft === 0
                  ? t('انتهى اشتراك المتجر. اليوم آخر يوم عمل.')
                  : t('انتهى اشتراك المتجر. جدّده لمتابعة العمل.')
            : sub.daysLeft === 0
              ? t('ينتهي اشتراك المتجر اليوم.')
              : // «خلال ١ يومًا» ركيكة — والعدد واحدٌ له لفظه في العربية
                sub.daysLeft === 1
                ? t('ينتهي اشتراك المتجر غدًا.')
                : t('ينتهي اشتراك المتجر خلال :n يومًا.', { n: sub.daysLeft });

    return (
        <div
            className={
                'mb-4 flex flex-wrap items-center gap-2 rounded-[12px] border px-4 py-3 text-[13px] ' +
                (urgent
                    ? 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]'
                    : 'border-[#fde68a] bg-[#fffbeb] text-[#b45309]')
            }
        >
            <AlertTriangle className="size-4 shrink-0" />
            <span className="font-medium">{line}</span>
            <span className="text-[12px] opacity-80" dir="ltr">
                {sub.endsAt}
            </span>
        </div>
    );
}
