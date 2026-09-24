import { useEffect, useState } from 'react';

import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface RailItem {
    /** مُعرّفُ القسم في الصفحة — هو نفسُه ما يُكتب في الرابط بعد `#` */
    id: string;
    label: string;
    /** سطرٌ صغير تحت الاسم يقول ما في القسم الآن — «نقدٌ عند الاستلام» */
    note?: string | null;
}

/**
 * عمودُ التنقّل في صفحة إعداداتٍ طويلة — يقفز ولا ينقل.
 *
 * ═══ العطبُ الذي فُتح لأجله ═══
 *
 * كان ضبطُ المتجر ستَّ شاشاتٍ بشريط تبويبات، وفوقها شاشةُ «عام» التي هي
 * **قائمةٌ ثانية**: سبعُ بطاقاتٍ تقود إلى التبويبات نفسِها. فصاحبُ المتجر
 * يمرّ بقائمتين قبل أن يبلغ حقلًا واحدًا، ويحمّل الصفحةَ مرّتين.
 *
 * وكان التوزيعُ غيرَ عادل: ثلاثُ شاشاتٍ فيها ثلاثةُ مقابض، وواحدةٌ فيها
 * اثنان وثلاثون ومعها زرّا حفظٍ مختلفان.
 *
 * ═══ والقفزُ لا النقل ═══
 *
 * الضبطُ كلُّه في صفحةٍ واحدة، وهذا العمودُ يقفز إلى قسمها. فلا تحميلَ
 * ولا انتظار، ومن لا يعرف أين يبحث يمرّ بعينه على السبعة في نظرة.
 *
 * والقسمُ الذي تقف عليه العينُ مضاءٌ في العمود: بلا ذلك يفقد القارئُ موضعَه
 * في صفحةٍ تُمرَّر، فيقرأ حقلًا ولا يعرف أيَّ قسمٍ يضبط.
 */
export default function SettingsRail({ items }: { items: RailItem[] }) {
    const t = useTranslate();
    const [active, setActive] = useState(items[0]?.id ?? '');

    /*
     * والإضاءةُ تُقاس بما في الشاشة لا بما ضُغط.
     *
     * `IntersectionObserver` تُخبرنا أيُّ قسمٍ عبَر أعلى الشاشة، فيبقى
     * العمودُ صادقًا حين يُمرَّر بالإصبع لا بالضغط. ومن مرّر إلى الأسفل
     * ورأى العمودَ ما يزال مضيئًا على الأوّل يظنّه معطوبًا.
     */
    useEffect(() => {
        /*
         * والقفزُ يعمل بلا المراقب.
         *
         * `IntersectionObserver` غيرُ موجودةٍ في بيئاتٍ قديمةٍ وفي التصيير
         * على الخادم. ولو نُودِيت بلا سؤالٍ لَسقط العمودُ كلُّه — فيبقى
         * صاحبُ الصفحة بلا تنقّلٍ أصلًا من أجل إضاءةٍ هي زينةٌ لا عمل.
         */
        if (typeof IntersectionObserver === 'undefined') return;

        const seen = new Map<string, number>();

        const io = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    seen.set(e.target.id, e.isIntersecting ? e.boundingClientRect.top : Number.POSITIVE_INFINITY);
                }

                // الأعلى بين ما يُرى الآن — لا آخرُ ما تقاطع
                let best: string | null = null;
                let top = Number.POSITIVE_INFINITY;
                seen.forEach((y, id) => {
                    if (y < top) {
                        top = y;
                        best = id;
                    }
                });

                if (best) setActive(best);
            },
            // الهامشُ العلويّ بقدر الترويسة اللاصقة: بلا ذلك يُضاء القسمُ وهو تحتها
            { rootMargin: '-96px 0px -60% 0px', threshold: 0 },
        );

        for (const item of items) {
            const el = document.getElementById(item.id);
            if (el) io.observe(el);
        }

        return () => io.disconnect();
    }, [items]);

    const jump = (id: string) => {
        const el = document.getElementById(id);
        if (! el) return;

        setActive(id);
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // والعنوانُ يحمل الموضع: رابطٌ يُرسَل أو يُحفظ يفتح على القسم نفسِه
        history.replaceState(null, '', `#${id}`);
    };

    return (
        <nav aria-label={t('أقسام الإعدادات')} className="sticky top-24 space-y-1">
            {items.map((item) => (
                <button
                    key={item.id}
                    type="button"
                    data-testid={`rail-${item.id}`}
                    aria-current={active === item.id ? 'true' : undefined}
                    onClick={() => jump(item.id)}
                    className={cn(
                        'block w-full rounded-[10px] px-3 py-2 text-start transition-colors',
                        active === item.id ? 'bg-[#f3f4f6] text-[#111]' : 'text-[#6b7280] hover:bg-[#f9fafb]',
                    )}
                >
                    <span className="block text-[13px] font-medium">{t(item.label)}</span>
                    {/* وما في القسم الآن يُقرأ من العمود: من يبحث عن رسم التوصيل يراه قبل أن يقفز */}
                    {item.note && <span className="mt-0.5 block truncate text-[12px] text-[#9ca3af]">{item.note}</span>}
                </button>
            ))}
        </nav>
    );
}
