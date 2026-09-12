import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslate } from '@/lib/i18n';
import { Site } from './renderer/Site';
import { DEVICE_WIDTH, type Device, type SiteDocument } from './types';

/**
 * المعاينة — الموقع نفسه، لا صورةٌ عنه.
 *
 * ولم تعد شبيهةً به: `preview/renderer/` هو **الشيفرة نفسها** التي يرسم بها
 * أبعادُ موقعَ التاجر المنشور — تُنسخ من مستودع العارض بأمرٍ واحد وتحرسها
 * بصمةٌ في `RENDERER_HASH`. فما يراه التاجر هنا هو ما سيراه زبونه بالضبط،
 * لا تقريبٌ له.
 *
 * وما يبقى في هذا الملفّ هو ما يخصّ المحرّر وحده: إطارُ الجهاز، والتصغير
 * ليُرى الموقع كاملًا، وطبقةُ الإمساك التي تجعل الموقعَ نفسَه هو لوحةَ
 * التحكّم.
 *
 * والجهاز يُبدَّل بعرض الإطار لا بتصغير الصفحة: يُرسم الموقع بعرض ١٢٨٠ أو
 * ٨٣٤ أو ٣٩٠ فتعمل استعلامات العرض ويلتفّ المحتوى كما يلتفّ في الهاتف
 * حقيقةً، ثمّ يُصغَّر الناتج ليُرى كاملًا. والتصغير بصريٌّ بحت — `transform`
 * لا `width` — فلا يُغيّر ما يقرؤه التخطيط.
 *
 * ═══ طبقةُ الإمساك ═══
 *
 * التاجر لا يبحث عن اسم القسم في قائمةٍ ليعدّله: يضغطه في موقعه. وذلك يلزمه
 * صندوقٌ فوق كلّ قسم — يُبرزه عند المرور، ويسمّيه، ويفتحه عند الضغط.
 *
 * والصناديق **فوق** المحتوى المصغَّر لا داخله، وهذا مقصود لسببين: أنّ نصَّها
 * لا يُصغَّر معه فيبقى مقروءًا مهما صغُر الجهاز، وأنّ ما تحتها يصير خاملًا
 * (`pointer-events: none`) — فلا يضغط التاجرُ رابطًا في المعاينة فيظنّ أنّ
 * موقعه ينتقل ولا ينتقل.
 *
 * والترويسةُ والتذييل يُمسكان كما يُمسك أيُّ قسم — وهما ليسا في `wrap` لأنّ
 * طبقة الرسم ترسمهما خارج أقسام الصفحة (انظر `renderer/Site`). فيُقاسان من
 * الشجرة نفسها: `header` و`footer` تحت `.w-site`.
 */

/**
 * قسمٌ ممسوك في المعاينة — موضعُه بالبكسل بعد التصغير.
 *
 * والاسمُ ليس فيه: الموضعُ يُقاس والاسمُ يُقرأ عند الرسم. ولو كان فيه لَصار
 * كلُّ تبديلِ اسمٍ قياسًا جديدًا — و`labels` تصل خاصيّةً جديدةَ الهويّة في
 * كلّ رسمة، فيدور القياس على نفسه بلا نهاية.
 */
interface Target {
    key: string;
    top: number;
    height: number;
}

interface Props {
    doc: SiteDocument;
    /** أيّ صفحةٍ تُعرض — بمفتاحها. وبلا مفتاح: الرئيسية */
    pageKey?: string;
    device: Device;
    /** القسم المفتوح في اللوحة — يُبرَز في المعاينة فيُعرف موضعُه */
    activeKey?: string | null;
    /**
     * ما يُنادى عند ضغط قسم — بمفتاحه: `slot:header` أو `index:3`.
     *
     * وبلا هذا لا طبقةَ إمساك أصلًا: المعاينة في شاشة الاختيار تُرى ولا
     * تُمسك، وصناديقُ لا تفعل شيئًا تُوهم أنّها تفعل.
     */
    onPick?: (key: string) => void;
    /** أسماءُ الأقسام كما تُعرض على الصناديق — بمفاتيحها */
    labels?: Record<string, string>;
    /** الأقسام المخفيّة عن الزوّار — تُقال في الصندوق */
    hidden?: Record<string, boolean>;
    /** أقصى ارتفاعٍ يُعرض من الصفحة — لبطاقةٍ تعرض أعلى الموقع لا كلَّه */
    clip?: number;
    /** صنفُ الورقة نفسِها — الحدُّ والخلفيةُ والظلّ */
    className?: string;
}

export const SLOT_KEYS = ['slot:header', 'slot:footer'] as const;

export default function SitePreview({
    doc,
    pageKey,
    device,
    activeKey,
    onPick,
    labels,
    hidden,
    clip,
    className,
}: Props) {
    const t = useTranslate();
    const host = useRef<HTMLDivElement>(null);
    const paper = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);

    const [scale, setScale] = useState(1);
    const [height, setHeight] = useState(0);
    const [targets, setTargets] = useState<Target[]>([]);
    const [hover, setHover] = useState<string | null>(null);

    const width = DEVICE_WIDTH[device];
    const grab = !!onPick;

    /*
     * القياسُ كلُّه في نداءٍ واحد — والتصغيرُ أوّلُه.
     *
     * كانت النسبةُ مكتوبةً فتفيض المعاينة خارج إطارها على حاسوبٍ وتظهر
     * ضئيلةً على آخر. وهي الآن تتبع عرض الحاوية، ويتبعها ارتفاعُ الورقة
     * وموضعُ كلّ صندوق: `transform` تصغّر ما يُرسم ولا تصغّر ما يشغله في
     * التخطيط، فبلا ضبط الارتفاع يبقى تحت المعاينة فراغٌ لا يفهم أحدٌ من
     * أين جاء.
     */
    const fit = useCallback(() => {
        const box = host.current;
        const content = inner.current;

        if (!box || !content) return;

        const next = Math.min(1, box.clientWidth / width);
        const full = content.scrollHeight * next;

        setScale(next);
        setHeight(clip ? Math.min(full, clip) : full);

        if (!grab) {
            setTargets([]);

            return;
        }

        const root = paper.current;
        const site = content.querySelector('.w-site');

        if (!root || !site) return;

        const base = root.getBoundingClientRect();
        const found: Target[] = [];

        const push = (el: Element | null | undefined, key: string) => {
            if (!el) return;

            const rect = el.getBoundingClientRect();

            found.push({ key, top: rect.top - base.top, height: rect.height });
        };

        /* ولا `:scope` — الأبناءُ يُقرآن بأسمائهم فيعمل القياس في كلّ بيئة */
        const child = (tag: string) =>
            Array.from(site.children).find((el) => el.tagName.toLowerCase() === tag);

        push(child('header'), 'slot:header');

        content.querySelectorAll('[data-w-index]').forEach((el) => {
            push(el, `index:${el.getAttribute('data-w-index')}`);
        });

        push(child('footer'), 'slot:footer');

        setTargets(found);
    }, [width, clip, grab]);

    useEffect(() => {
        const box = host.current;
        const content = inner.current;

        if (!box || !content) return;

        fit();

        const observer = new ResizeObserver(fit);

        observer.observe(box);
        observer.observe(content);

        return () => observer.disconnect();
    }, [fit, doc]);

    /*
     * والقسمُ المخفيّ يُرى باهتًا في المعاينة ولا يُرى في الموقع.
     *
     * من أخفاه يحتاج أن يعرف أين هو ليُظهره — و`data-w-index` هو ما تقيس
     * به طبقةُ الإمساك أعلاه، فالرقمُ الذي تعرفه اللوحة هو الذي في الشجرة.
     */
    const wrap = useCallback(
        (node: React.ReactNode, section: { visible: boolean }, index: number) => (
            <div data-w-index={index} style={{ opacity: section.visible ? 1 : 0.4 }}>
                {node}
            </div>
        ),
        [],
    );

    return (
        <div ref={host} className="w-full">
            <div
                ref={paper}
                className={className}
                style={{
                    position: 'relative',
                    overflow: 'hidden',
                    width: width * scale,
                    height: height || undefined,
                    marginInline: 'auto',
                }}
            >
                <div
                    ref={inner}
                    style={{
                        width,
                        transform: `scale(${scale})`,
                        transformOrigin: 'top right',
                        // خاملٌ تحت طبقة الإمساك: المعاينة تُقرأ وتُمسك ولا تُتصفّح
                        pointerEvents: grab ? 'none' : undefined,
                    }}
                >
                    <Site doc={doc} mode="edit" page={pageKey} wrap={grab ? wrap : undefined} t={t} />
                </div>

                {targets.map((target) => {
                    const on = activeKey === target.key;
                    const lit = on || hover === target.key;
                    const name = labels?.[target.key] ?? t('قسم');
                    const dim = hidden?.[target.key] ?? false;

                    return (
                        <button
                            key={target.key}
                            type="button"
                            onClick={() => onPick?.(target.key)}
                            onMouseEnter={() => setHover(target.key)}
                            onMouseLeave={() => setHover((k) => (k === target.key ? null : k))}
                            onFocus={() => setHover(target.key)}
                            onBlur={() => setHover((k) => (k === target.key ? null : k))}
                            aria-label={t('عدّل :name', { name })}
                            aria-pressed={on}
                            style={{
                                position: 'absolute',
                                insetInline: 0,
                                top: target.top,
                                height: target.height,
                                background: 'transparent',
                                border: 0,
                                padding: 0,
                                cursor: 'pointer',
                                outline: lit
                                    ? `${on ? 2 : 1}px solid var(--w-primary, #2563eb)`
                                    : 'none',
                                outlineOffset: -1,
                            }}
                        >
                            {lit && (
                                <span
                                    style={{
                                        position: 'absolute',
                                        top: 0,
                                        insetInlineStart: 0,
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        maxWidth: '100%',
                                        padding: '3px 8px',
                                        borderEndEndRadius: 8,
                                        background: 'var(--w-primary, #2563eb)',
                                        color: '#fff',
                                        fontSize: 11,
                                        fontWeight: 600,
                                        lineHeight: 1.6,
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {name}
                                    {dim && <span style={{ opacity: 0.75 }}>· {t('مخفيّ')}</span>}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
