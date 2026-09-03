import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslate } from '@/lib/i18n';
import { Site } from './renderer/Site';
import { DEVICE_WIDTH, type Device, type SiteDocument } from './types';

/**
 * المعاينة — الموقع نفسه، لا صورةٌ عنه.
 *
 * ولم تعد شبيهةً به: `preview/renderer/` هو **الشيفرة نفسها** التي يرسم بها
 * العارضُ العامّ (`abaadapp/Storefront`) موقعَ التاجر المنشور — تُنسخ إليه
 * بأمرٍ واحد وتُحرسها بصمةٌ في `RENDERER_HASH`. فما يراه التاجر هنا هو ما
 * سيراه زبونه بالضبط، لا تقريبٌ له.
 *
 * وما يبقى في هذا الملفّ هو ما يخصّ المحرّر وحده: إطارُ الجهاز، والتصغير
 * ليُرى الموقع كاملًا، وإبرازُ القسم المفتوح في اللوحة.
 *
 * والجهاز يُبدَّل بعرض الإطار لا بتصغير الصفحة: يُرسم الموقع بعرض ١٢٨٠ أو
 * ٨٣٤ أو ٣٩٠ فتعمل استعلامات العرض ويلتفّ المحتوى كما يلتفّ في الهاتف
 * حقيقةً، ثمّ يُصغَّر الناتج ليُرى كاملًا. والتصغير بصريٌّ بحت — `transform`
 * لا `width` — فلا يُغيّر ما يقرؤه التخطيط.
 */

interface Props {
    doc: SiteDocument;
    /** أيّ صفحةٍ تُعرض — بمفتاحها. وبلا مفتاح: الرئيسية */
    pageKey?: string;
    device: Device;
    /** القسم المفتوح في اللوحة — يُبرَز في المعاينة فيُعرف موضعُه */
    activeIndex?: number | null;
    onSelect?: (index: number) => void;
    className?: string;
}

export default function SitePreview({ doc, pageKey, device, activeIndex, onSelect, className }: Props) {
    const t = useTranslate();
    const frame = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(1);
    const [height, setHeight] = useState(0);

    const width = DEVICE_WIDTH[device];

    /*
     * التصغير يتبع عرض الحاوية لا رقمًا ثابتًا.
     *
     * لوحةُ المحرّر تنكمش على الشاشات الصغيرة، ونسبةٌ مكتوبة كانت تجعل
     * المعاينة تفيض خارج إطارها على حاسوبٍ وتظهر ضئيلةً على آخر.
     */
    useEffect(() => {
        const el = frame.current;
        const content = inner.current;

        if (!el || !content) return;

        /*
         * وارتفاع الإطار يتبع المحتوى مضروبًا في نسبة التصغير.
         *
         * `transform` تصغّر ما يُرسم ولا تصغّر ما يشغله في التخطيط: يبقى تحت
         * المعاينة فراغٌ بمقدار ما صُغّر — شريطٌ أبيض في نصف الشاشة لا يفهم
         * أحدٌ من أين جاء. فيُقاس المحتوى ويُضبط الارتفاع عليه.
         */
        const fit = () => {
            const next = Math.min(1, el.clientWidth / width);

            setScale(next);
            setHeight(content.scrollHeight * next);
        };

        fit();

        const observer = new ResizeObserver(fit);

        observer.observe(el);
        observer.observe(content);

        return () => observer.disconnect();
    }, [width, doc]);

    /** إبرازُ القسم المفتوح، والمخفيُّ باهتًا — زينةُ محرّرٍ لا زينةُ موقع */
    const wrap = useMemo(
        () =>
            (node: React.ReactNode, section: { visible: boolean }, index: number) => (
                <div
                    onClick={onSelect ? () => onSelect(index) : undefined}
                    style={{
                        position: 'relative',
                        cursor: onSelect ? 'pointer' : undefined,
                        // القسم المخفيّ يُرى باهتًا في المعاينة ولا يُرى في الموقع:
                        // من أخفاه يحتاج أن يعرف أين هو ليُظهره
                        opacity: section.visible ? 1 : 0.35,
                        outline: activeIndex === index ? '2px solid var(--w-primary)' : undefined,
                        outlineOffset: -2,
                    }}
                >
                    {node}
                </div>
            ),
        [activeIndex, onSelect],
    );

    return (
        <div ref={frame} className={className} style={{ overflow: 'hidden', height: height || undefined }}>
            <div ref={inner} style={{ width, transform: `scale(${scale})`, transformOrigin: 'top right' }}>
                <Site doc={doc} mode="edit" page={pageKey} wrap={wrap} t={t} />
            </div>
        </div>
    );
}
