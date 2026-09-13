import { type ReactNode, useEffect, useRef, useState } from 'react';

import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * عرضُ المنتج إلى جانب النموذج — على الشاشة الواسعة وحدها.
 *
 * ═══ ولمَ رسمٌ لا لقطةُ شاشة ═══
 *
 * لقطةُ شاشةٍ حقيقيّة تحمل بياناتِ متجرٍ ما: أرقامَ مبيعاتٍ وأسماءَ زبائن.
 * ولقطةٌ «تجريبيّة» تحمل أرقامًا مخترعةً تُقرأ وعدًا بما لم يُقَس. فالرسمُ
 * تجريدٌ صادق: أعمدةٌ وبطاقاتٌ بلا رقمٍ واحد، تقول **شكلَ** ما يُدار لا
 * كم رَبِح أحد.
 *
 * وهو رسمٌ في الترميز لا صورة: لا ملفَّ يُحمَّل، ولا يبهت على شاشةٍ عالية
 * الدقّة، ويتبع خطَّ النظام وألوانَه وحدَها.
 *
 * ═══ ولا يزاحم الدخول ═══
 *
 * يقع بعد النموذج في ترتيب الشيفرة — فمفتاحُ Tab وقارئُ الشاشة يبلغان
 * الحقولَ أوّلًا — ويختفي كلَّه دون `lg`. والتبديلُ بطيءٌ (سبعُ ثوانٍ)
 * ويتوقّف عند المرور بالفأرة أو بالتركيز، ولا يقع إطلاقًا لمن طلب تقليلَ
 * الحركة من نظامه.
 */

interface Slide {
    title: string;
    body: string;
    art: ReactNode;
}

/* ——— قطعُ الرسم: أشكالٌ بلا أرقام ——— */

const Bar = ({ h }: { h: number }) => (
    <span className="w-full rounded-t-[3px] bg-[#111]/55" style={{ height: `${h}%` }} />
);

const Line = ({ w, dark = false }: { w: number; dark?: boolean }) => (
    <span
        className={cn('block h-2 rounded-full', dark ? 'bg-[#111]/70' : 'bg-[#111]/12')}
        style={{ width: `${w}%` }}
    />
);

/** إطارُ اللقطة — نافذةٌ بحافّةٍ وشريطِ عنوانٍ، تُوحي بلوحةٍ لا ببطاقة */
const Frame = ({ children }: { children: ReactNode }) => (
    <div className="w-full max-w-[420px] rounded-[18px] border border-[#ececec] bg-white p-4 shadow-[0_30px_60px_-32px_rgba(17,17,17,0.35)]">
        <div className="mb-4 flex gap-1.5" aria-hidden>
            <span className="size-2 rounded-full bg-[#111]/10" />
            <span className="size-2 rounded-full bg-[#111]/10" />
            <span className="size-2 rounded-full bg-[#111]/10" />
        </div>
        {children}
    </div>
);

const DASHBOARD = (
    <Frame>
        <div className="flex items-end gap-2" style={{ height: 96 }} aria-hidden>
            {[38, 62, 45, 80, 55, 92, 70].map((h, i) => (
                <Bar key={i} h={h} />
            ))}
        </div>
        <div className="mt-4 space-y-2" aria-hidden>
            <Line w={72} dark />
            <Line w={46} />
        </div>
    </Frame>
);

const PRODUCTS = (
    <Frame>
        <div className="grid grid-cols-3 gap-2.5" aria-hidden>
            {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="space-y-1.5">
                    <div className="aspect-square rounded-[10px] bg-[#111]/[0.06]" />
                    <Line w={80} />
                </div>
            ))}
        </div>
    </Frame>
);

const POS = (
    <Frame>
        <div className="grid grid-cols-[1fr_auto] gap-3" aria-hidden>
            <div className="space-y-2">
                {[92, 76, 84, 60].map((w, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <span className="size-6 shrink-0 rounded-[7px] bg-[#111]/[0.06]" />
                        <Line w={w} />
                    </div>
                ))}
            </div>
            <div className="grid w-20 grid-cols-3 gap-1.5">
                {Array.from({ length: 9 }).map((_, i) => (
                    <span key={i} className="aspect-square rounded-[5px] bg-[#111]/[0.07]" />
                ))}
            </div>
        </div>
        <div className="mt-3 h-8 rounded-[9px] bg-[#111]" aria-hidden />
    </Frame>
);

const WEBSITE = (
    <Frame>
        <div className="space-y-2.5" aria-hidden>
            <div className="h-14 rounded-[10px] bg-[#111]/[0.07]" />
            <div className="grid grid-cols-2 gap-2.5">
                <div className="h-16 rounded-[10px] bg-[#111]/[0.05]" />
                <div className="h-16 rounded-[10px] bg-[#111]/[0.05]" />
            </div>
            <Line w={64} dark />
            <Line w={40} />
        </div>
    </Frame>
);

export default function AuthShowcase() {
    const t = useTranslate();

    const slides: Slide[] = [
        {
            title: 'كل أعمالك في مكان واحد',
            body: 'أدر المبيعات والمخزون والعملاء والموقع الإلكتروني من لوحةٍ واحدة.',
            art: DASHBOARD,
        },
        {
            title: 'منتجاتك ومخزونك تحت السيطرة',
            body: 'أصناف وتصنيفات وكميّات — وما نفد يُعرف قبل أن يُطلب.',
            art: PRODUCTS,
        },
        {
            title: 'نقطة بيع تعمل على أي جهاز',
            body: 'بيعٌ سريع وطباعة إيصال، وكلُّ بيعةٍ تدخل الدفتر في لحظتها.',
            art: POS,
        },
        {
            title: 'متجرك على الإنترنت بلا مطوّر',
            body: 'صفحاتٌ تُبنى بالسحب، ومنتجاتُك عليها هي منتجاتُك نفسها.',
            art: WEBSITE,
        },
    ];

    const [index, setIndex] = useState(0);
    const [held, setHeld] = useState(false);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        /*
         * ومن طلب تقليلَ الحركة لا تتحرّك عنده.
         *
         * إعدادُ نظامٍ يضبطه من يُصيبه الدوارُ من الحركة — وشريطٌ يتبدّل
         * وحده خلف نموذجِ دخولٍ أسوأُ ما يُقابله. ويبقى له التبديلُ بالنقاط.
         */
        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;

        if (still || held) {
            return;
        }

        timer.current = setInterval(() => setIndex((i) => (i + 1) % slides.length), 7000);

        return () => {
            if (timer.current) {
                clearInterval(timer.current);
            }
        };
    }, [held, slides.length]);

    return (
        <aside
            /* لا يُقرأ بالصوت: هو تكرارُ ما تقوله صفحةُ التعريف، وليس بابًا يُدخل منه */
            aria-hidden
            className="hidden bg-[#f2f3f4] lg:flex lg:h-dvh lg:flex-col lg:items-center lg:justify-center lg:gap-8 lg:px-10 lg:py-12"
            onMouseEnter={() => setHeld(true)}
            onMouseLeave={() => setHeld(false)}
        >
            <div className="flex h-[300px] w-full items-center justify-center">{slides[index].art}</div>

            <div className="max-w-[380px] text-center">
                <h2 className="text-[20px] font-bold leading-tight text-[#111]">{t(slides[index].title)}</h2>
                <p className="mt-2 text-[14px] leading-relaxed text-[#6b7280]">{t(slides[index].body)}</p>
            </div>

            {/* والنقاطُ تُضغط: من أراد أن يعود إلى شريحةٍ مرّت لا ينتظر دورة */}
            <div className="flex items-center gap-2">
                {slides.map((s, i) => (
                    <button
                        key={i}
                        type="button"
                        tabIndex={-1}
                        aria-label={t(s.title)}
                        onClick={() => setIndex(i)}
                        className={cn(
                            'h-1.5 rounded-full transition-all',
                            i === index ? 'w-6 bg-[#111]' : 'w-1.5 bg-[#111]/20 hover:bg-[#111]/35',
                        )}
                    />
                ))}
            </div>
        </aside>
    );
}
