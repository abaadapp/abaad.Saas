import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * ورقةُ المعاينة — بمقاسها الحقيقيّ، مُصغَّرةً بصريًّا لتسع الشاشة.
 *
 * ═══ العطبُ الذي يعالجه ═══
 *
 * كان الإطارُ صندوقًا بعرض العمود وارتفاعٍ من `svh`: مستطيلٌ عريضٌ يتغيّر
 * شكلُه بحجم النافذة. فيضبط التاجر قالبَه على شكلٍ **لا وجود له** — يرى
 * ترويسةً ممدودةً على عرضٍ لن تُطبع عليه، ولا يرى أين تنتهي الصفحة الأولى،
 * ولا يعرف أنّ توقيعَه سيقع في صفحةٍ ثانية حتى تخرج الورقة من الطابعة.
 *
 * ═══ والحلُّ أن تُرسم بمقاسها ثمّ تُصغَّر ═══
 *
 * الإطارُ يأخذ عرضَ الورقة الحقيقيّ بالبكسل (٢١٠ مم = ٧٩٤ بكسلًا عند ٩٦
 * نقطة/بوصة)، فيتصرّف المحتوى داخله كما يتصرّف على الورق تمامًا: السطرُ
 * ينكسر حيث ينكسر، والجدولُ يتوزّع كما يتوزّع. ثمّ يُصغَّر كلُّه بـ`scale`
 * — وهو تحويلٌ بصريٌّ لا يُعيد التخطيط.
 *
 * ولو ضُيِّق الإطارُ نفسُه بدل تصغيره لتغيّر التخطيط: عمودٌ ينكمش فينكسر
 * اسمُ الصنف سطرين، فيرى التاجر ورقةً أطولَ ممّا ستُطبع.
 *
 * ═══ وحدودُ الصفحات تُرسم ═══
 *
 * فاتورةٌ بأربعين صنفًا تمتدّ صفحتين. وخطٌّ متقطّعٌ عند كلّ حدٍّ يقول ذلك
 * قبل الطباعة لا بعدها.
 */

/** بكسلٌ لكلّ مليمتر عند ٩٦ نقطة/بوصة — وهو ما يفترضه المتصفّح في `mm` */
const PX_PER_MM = 96 / 25.4;

const mm = (value: number) => Math.round(value * PX_PER_MM);

/** A4 قائمة: ٢١٠ × ٢٩٧ مم */
export const A4 = { width: mm(210), page: mm(297) };

export type Medium =
    /** ورقةٌ بصفحات — تُرسم بحدودها */
    | { kind: 'sheet' }
    /** شريطُ طابعةٍ حراريّة — بعرض ورقها وبطول محتواه، بلا صفحات */
    | { kind: 'strip'; widthMm: number };

type Props = {
    html: string;
    medium?: Medium;
    /** ارتفاعُ النافذة التي تُعرض فيها الورقة — قيمةُ CSS */
    viewport?: string;
    title?: string;
    className?: string;
};

export default function PaperFrame({
    html,
    medium = { kind: 'sheet' },
    viewport = 'min(70svh, 900px)',
    title,
    className,
}: Props) {
    const t = useTranslate();

    const box = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLIFrameElement>(null);

    const [avail, setAvail] = useState(0);
    const [content, setContent] = useState(0);

    const paperWidth = medium.kind === 'sheet' ? A4.width : mm(medium.widthMm);

    /*
     * وارتفاعُ الورقة يتبع نوعَها.
     *
     * الصفحةُ تُقاس بصفحاتٍ كاملة: محتوًى يتجاوز الأولى بسطرٍ يعني صفحتين،
     * وعرضُ ٢٩٧ مم ونصف كذبٌ على من يقرأ. أمّا الشريطُ فطابعةٌ لا تعرف
     * الصفحات: يطول بطول محتواه ويُقصّ.
     */
    const paperHeight =
        medium.kind === 'sheet'
            ? Math.max(1, Math.ceil((content || A4.page) / A4.page)) * A4.page
            : Math.max(content, mm(60));

    const pages = medium.kind === 'sheet' ? Math.round(paperHeight / A4.page) : 1;

    /*
     * والتصغيرُ لا يتجاوز الحجم الطبيعيّ.
     *
     * شريطُ ٥٨ مم مكبَّرٌ إلى عرض عمودٍ عريض يخرج ضبابيًّا وبمقاسِ خطٍّ لا
     * يشبه ما يُطبع — والمعاينةُ تكذب حين تُجمِّل.
     */
    const scale = avail > 0 ? Math.min(1, avail / paperWidth) : 1;

    /* عرضُ الحاوية — يُقاس ولا يُفترض: العمودُ يتغيّر بتغيّر النافذة */
    useLayoutEffect(() => {
        const el = box.current;

        if (!el) {
            return;
        }

        const read = () => setAvail(el.clientWidth);
        read();

        const observer = new ResizeObserver(read);
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    /**
     * قياسُ ما رُسم داخل الإطار.
     *
     * و`allow-same-origin` بلا `allow-scripts`: الورقةُ تبقى نصًّا لا
     * يُنفَّذ — لا سكربت فيها يعمل — وتُقرأ من الخارج لتُقاس. وبلا هذا
     * يكون الإطارُ أصلًا معزولًا لا يُقرأ ارتفاعُه، فتُرسم الورقةُ بصفحةٍ
     * واحدة أبدًا ويُقصّ ما زاد بلا أن يقول ذلك شيء.
     */
    const measure = useCallback(() => {
        const doc = frame.current?.contentDocument;

        if (!doc?.documentElement) {
            return;
        }

        const height = Math.max(
            doc.documentElement.scrollHeight,
            doc.body?.scrollHeight ?? 0,
        );

        if (height > 0) {
            setContent(height);
        }
    }, []);

    /*
     * ويُعاد القياسُ بعد الرسم لا عنده وحده.
     *
     * `load` يقع قبل أن تحطّ الصورُ وتُحسب الخطوط، فارتفاعٌ يُقرأ عندها
     * ينقص ثمّ يزيد. وإطارٌ يُقاس مرّةً يترك شعارًا ثقيلًا يمتدّ خارج
     * الورقة بلا أن يظهر.
     */
    useEffect(() => {
        const id = window.setTimeout(measure, 120);

        return () => window.clearTimeout(id);
    }, [html, measure]);

    return (
        <div
            ref={box}
            className={cn(
                'overflow-y-auto overflow-x-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)]',
                'bg-[#f1f1f0] p-4',
                className,
            )}
            style={{ height: viewport }}
        >
            {/* والورقةُ في وسط النافذة، بظلٍّ يقول إنّها ورقةٌ لا لوحة */}
            <div
                className="relative mx-auto bg-white shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.06)]"
                style={{ width: paperWidth * scale, height: paperHeight * scale }}
            >
                <iframe
                    ref={frame}
                    title={title ?? t('معاينة الورقة')}
                    srcDoc={html}
                    /*
                     * بلا `allow-scripts`: لا يُنفَّذ شيءٌ من الورقة في اللوحة.
                     * و`allow-same-origin` لقياس ارتفاعها وحده — انظر `measure`.
                     */
                    sandbox="allow-same-origin"
                    onLoad={measure}
                    scrolling="no"
                    className="absolute left-0 top-0 border-0 bg-white"
                    /*
                     * والمنشأُ من أعلى اليسار دائمًا — لا يتبع اتّجاه اللوحة.
                     *
                     * `transform` يعمل في فضاء الصندوق لا في فضاء النصّ،
                     * والصندوقُ محجوزٌ بعرضٍ محسوب. واتّجاهُ الورقة نفسِها
                     * شأنُ ما بداخلها.
                     */
                    style={{
                        width: paperWidth,
                        height: paperHeight,
                        transform: `scale(${scale})`,
                        transformOrigin: 'top left',
                    }}
                />

                {/*
                    حدودُ الصفحات — خطٌّ متقطّعٌ عند كلّ قطع.

                    ولا يُرسم على الورقة الواحدة: خطٌّ أسفلَ صفحةٍ وحيدة يقول
                    إنّ ثمّة صفحةً ثانية فارغة.
                */}
                {pages > 1
                    && Array.from({ length: pages - 1 }, (_, i) => (
                        <div
                            key={i}
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-0 border-t border-dashed border-[#cbd5e1]"
                            style={{ top: (i + 1) * A4.page * scale }}
                        >
                            <span className="absolute -top-[9px] left-1/2 -translate-x-1/2 bg-[#f1f1f0] px-2 text-[9px] text-[#94a3b8]">
                                {t('صفحة')} <span dir="ltr">{i + 2}</span>
                            </span>
                        </div>
                    ))}
            </div>
        </div>
    );
}
