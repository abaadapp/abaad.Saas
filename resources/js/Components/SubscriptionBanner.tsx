import { usePage } from '@inertiajs/react';
import { AlertTriangle, Sparkles } from 'lucide-react';
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

    /*
     * والتجربةُ المجّانيّة تُقال من أوّل يوم: اسمُ الباقة، و«تجربة مجانية»، ويومُ
     * انتهائها. ومن جرّب الأساسيّة بلا نقطة البيع يُقال له أين تُفعَّل — لا يبحث
     * عنها في القائمة فلا يجدها ويظنّ العطب في النظام.
     */
    if (sub?.trial && sub.daysLeft >= 0) {
        const soon = sub.daysLeft <= WARN_WITHIN_DAYS;

        return (
            <div
                data-testid="trial-banner"
                className={
                    'mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[12px] border px-4 py-3 text-[13px] ' +
                    (soon ? 'border-[#fde68a] bg-[#fffbeb] text-[#b45309]' : 'border-[#ddd6fe] bg-[#f5f3ff] text-[#5b21b6]')
                }
            >
                <Sparkles className="size-4 shrink-0" />
                <span className="font-semibold" data-testid="trial-plan">{sub.plan ? t(sub.plan) : t('الباقة الأساسية')}</span>
                <span className="rounded-full bg-white/70 px-2 py-0.5 text-[12px] font-medium">{t('تجربة مجانية')}</span>
                <span>
                    {t('تنتهي في')}{' '}
                    <bdi className="tabular-nums" dir="ltr" data-testid="trial-ends">{sub.endsAt}</bdi>
                    {' · '}
                    {sub.daysLeft === 0 ? t('اليوم آخر يوم') : sub.daysLeft === 1 ? t('بقي يوم واحد') : t('بقي :n يومًا', { n: sub.daysLeft })}
                </span>
                {sub.trialWithoutPos && (
                    <span className="basis-full text-[12px] opacity-80" data-testid="trial-without-pos">
                        {t('نقطة البيع والإضافات غير مشمولة في التجربة، ويفعّلها فريق أبعاد عند الاشتراك.')}
                    </span>
                )}
            </div>
        );
    }

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
