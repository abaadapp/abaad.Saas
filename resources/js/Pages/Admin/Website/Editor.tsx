import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ChevronLeft,
    CircleAlert,
    ExternalLink,
    Loader2,
    Monitor,
    Palette,
    Rocket,
    Smartphone,
    Tablet,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import Tabs from '@/Components/Tabs';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SectionForm, { type PickerProduct } from './fields/SectionForm';
import SitePreview from './preview/SitePreview';
import type { Device, SiteDocument } from './preview/types';
import AddSection, { type LibraryItem } from './editor/AddSection';
import DesignPanel, { type TemplateCard, type ThemeOptions } from './editor/DesignPanel';
import SectionList from './editor/SectionList';
import { type EditorSection, keyOf } from './editor/types';
import { type SiteShell } from './shell';

interface Props extends SiteShell {
    pages: { id: number; title: string; slug: string; status: string; is_home: boolean; current: boolean }[];
    page: {
        id: number;
        key: string;
        title: string;
        slug: string;
        status: string;
        is_home: boolean;
        removable: boolean;
        seo: Record<string, string>;
    };
    sections: EditorSection[];
    globals: EditorSection[];
    library: LibraryItem[];
    groups: string[];
    networks: { value: string; label: string }[];
    products: PickerProduct[];
    document: SiteDocument;
    templates: TemplateCard[];
    theme: Record<string, string>;
    themeOptions: ThemeOptions;
    panel: 'sections' | 'design';
}

const DEVICES: { key: Device; label: string; icon: typeof Monitor }[] = [
    { key: 'desktop', label: 'كمبيوتر', icon: Monitor },
    { key: 'tablet', label: 'لوحي', icon: Tablet },
    { key: 'mobile', label: 'جوال', icon: Smartphone },
];

/** ما يقوله سطرُ الحال — وكلٌّ منها حالٌ وقعت، لا حالٌ مفترضة */
type Status = 'clean' | 'saving' | 'saved' | 'failed';

/**
 * محرّرُ الموقع — مساحةُ عملٍ لا صفحةُ إعدادات.
 *
 * الموقعُ نفسُه في وسط الشاشة، واللوحةُ إلى جانبه. والتاجر لا يتخيّل النتيجة:
 * يضغط ما يريد تغييره **في موقعه**، فتُفتح حقولُه؛ ويكتب فيرى؛ ويرتّب فيرى.
 * والمعاينةُ ليست صورةً تقريبيّة — هي المستند الذي سيقرؤه العارض، مرسومًا
 * بالشيفرة نفسها (انظر `preview/SitePreview`).
 *
 * ═══ وخمسُ قواعدَ تحكم هذه الشاشة ═══
 *
 * ١) **المعاينةُ هي بابُ التحرير الأوّل.** ضغطُ القسم في الموقع يفتحه؛
 *    والقائمةُ طريقٌ ثانٍ لما لا يُضغط: الترتيبُ والإخفاءُ والحذف.
 * ٢) **لا يُعرض إلا قسمٌ واحد.** عشرون قسمًا بحقولها معًا شاشةٌ لا تُقرأ.
 * ٣) **لا زرَّ حفظ.** يُحفظ بنفسه بعد أن يكفّ التاجر عن الكتابة بثانية،
 *    ويُقال له «تم الحفظ». وزرُّ الحفظ في محرّرٍ يعني تعديلًا يضيع لأنّ أحدًا
 *    نسي أن يضغطه — وحفظٌ يُقال إنّه وقع ولم يقع أسوأ منهما، فللإخفاق حالٌ
 *    تُقال كما تُقال للنجاح.
 * ٤) **الحفظ ليس نشرًا.** كلّ ما يُكتب هنا في المسوّدة، ولا يراه زائرٌ حتى
 *    يُضغط «نشر». فيجرّب التاجر ويحذف ويعيد بلا خوف على موقعٍ يعمل.
 * ٥) **التصميمُ لوحةٌ هنا لا شاشةٌ أخرى.** تبديلُ لونٍ وتعديلُ نصٍّ عملٌ
 *    واحد في ذهن صاحب المتجر، فلا يُقطع بانتقالٍ بين شاشتين.
 */
export default function Editor() {
    const {
        site,
        pages,
        page,
        sections,
        globals,
        library,
        groups,
        networks,
        products,
        document,
        templates,
        theme,
        themeOptions,
        panel: initialPanel,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();

    const [device, setDevice] = useState<Device>('desktop');
    const [panel, setPanel] = useState<'sections' | 'design'>(initialPanel);
    const [pane, setPane] = useState<'edit' | 'preview'>('preview');
    const [adding, setAdding] = useState(false);
    const [openKey, setOpenKey] = useState<string | null>(null);
    const [status, setStatus] = useState<Status>('clean');

    /** كلُّ أقسام الشاشة بمفاتيحها — العامّةُ أوّلًا ثمّ أقسام الصفحة */
    const byKey = useMemo(() => {
        const map = new Map<string, EditorSection>();

        globals.forEach((s, i) => map.set(keyOf(s, i), s));
        sections.forEach((s, i) => map.set(`index:${i}`, s));

        return map;
    }, [globals, sections]);

    const open = openKey ? (byKey.get(openKey) ?? null) : null;

    /* ما تعرضه صناديقُ المعاينة — وتُبنى مرّةً لا في كلّ رسمة */
    const labels = useMemo(
        () => Object.fromEntries([...byKey].map(([key, s]) => [key, s.label])),
        [byKey],
    );
    const hidden = useMemo(
        () => Object.fromEntries([...byKey].map(([key, s]) => [key, !s.visible])),
        [byKey],
    );

    /*
     * المسوّدة المحلّية: ما يكتبه التاجر الآن قبل أن يصل الخادم.
     *
     * وبدونها يقفز المؤشّر إلى آخر الحقل مع كلّ حفظ: تصل حمولةٌ جديدة من
     * الخادم فتُعاد كتابة الحقل من أوّله.
     */
    const [draft, setDraft] = useState<Record<string, unknown> | null>(null);
    const timer = useRef<number | null>(null);
    /** آخر ما كُتب ولم يصل الخادم بعد — يُرسل قبل أيّ مغادرة */
    const pending = useRef<{ id: number; data: Record<string, unknown> } | null>(null);

    const save = useCallback((id: number, data: Record<string, unknown>) => {
        pending.current = null;
        setStatus('saving');
        router.put(
            route('admin.website.sections.update', id),
            // الحمولة متداخلة، و`FormDataConvertible` لا تصفها — والخادم يقرأ JSON
            { data } as never,
            {
                preserveScroll: true,
                preserveState: true,
                // المعاينة وحدها تُعاد: الحمولة كلّها في كلّ ضغطة مفتاح ثقيلة
                only: ['document', 'site'],
                onSuccess: () => setStatus('saved'),
                /*
                 * ولا يُقال «تم الحفظ» لما لم يُحفظ.
                 *
                 * الشبكةُ تنقطع والجلسةُ تنتهي، ومحرّرٌ يقول «محفوظ» في
                 * الحالين يجعل التاجر يغلق الشاشة على عملٍ ضاع. فالإخفاق
                 * يبقى معروضًا حتى يُحفظ شيءٌ بعده.
                 */
                onError: () => setStatus('failed'),
            },
        );
    }, []);

    /**
     * ما لم يُرسل بعد يُرسل الآن.
     *
     * والتأخير تسعُ مئة جزءٍ من الثانية: يكفي ألّا يُرسَل مع كلّ حرف، ويكفي
     * أن يغادر التاجر الشاشة قبله. فيُستدعى هذا عند تبديل القسم أو الصفحة أو
     * الخروج — وإلا ضاع آخرُ سطرٍ كتبه ولا شيء يقول لماذا. و«لا تفقد تعديلات
     * المستخدم» قاعدةٌ لا نيّة: الحفظ التلقائيّ بلا إفراغٍ يفقدها.
     */
    const flush = useCallback(() => {
        if (timer.current) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }

        const wait = pending.current;

        if (wait) {
            save(wait.id, wait.data);
        }
    }, [save]);

    /** يُحفظ بعد أن يكفّ التاجر عن الكتابة — لا مع كلّ حرف */
    const edit = (data: Record<string, unknown>) => {
        if (!open) return;

        setDraft(data);
        setStatus('saving');
        pending.current = { id: open.id, data };

        if (timer.current) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(flush, 900);
    };

    // تبديل القسم يُرسل ما قبله: القسمان لا يتبادلان محتواهما
    useEffect(() => {
        flush();
        setDraft(open ? open.data : null);
    }, [openKey]); // eslint-disable-line react-hooks/exhaustive-deps

    // ومغادرة الشاشة كذلك — بالتنقّل أو بإغلاق الصفحة
    useEffect(() => {
        const off = router.on('before', () => {
            flush();
        });

        return () => {
            flush();
            off();
        };
    }, [flush]);

    const act = (url: string, method: 'post' | 'delete' = 'post') =>
        router[method](url, {}, { preserveScroll: true });

    /** ضغطُ قسمٍ في المعاينة — يفتح حقولَه، وعلى الجوّال ينقل إلى اللوحة */
    const pick = (key: string) => {
        setPanel('sections');
        setOpenKey(key);
        setPane('edit');
    };

    const status_ = {
        clean: null,
        saving: (
            <span className="flex items-center gap-1.5 text-[12px] text-[#9ca3af]">
                <Loader2 className="size-3.5 animate-spin" />
                {t('جاري الحفظ…')}
            </span>
        ),
        saved: (
            <span className="flex items-center gap-1.5 text-[12px] text-[#15803d]">
                <Check className="size-3.5" />
                {t('تم الحفظ')}
            </span>
        ),
        failed: (
            <span className="flex items-center gap-1.5 text-[12px] font-medium text-[#b91c1c]">
                <CircleAlert className="size-3.5" />
                {t('تعذّر الحفظ — تحقّق من اتصالك')}
            </span>
        ),
    }[status];

    /* ─────────────────────────── الشريط العلويّ ─────────────────────────── */

    const toolbar = (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-[var(--ui-border,#e8e8e8)] px-3 py-2.5">
            <Link
                href={route('admin.website.index')}
                className="flex shrink-0 items-center gap-1.5 rounded-[8px] px-1.5 py-1 text-[13px] text-[#6b7280] transition-colors hover:text-[#111]"
            >
                <ArrowRight className="size-4" />
                <span className="hidden sm:inline">{t('الموقع الإلكتروني')}</span>
            </Link>

            <span className="hidden h-5 w-px bg-[var(--ui-border,#e8e8e8)] sm:block" aria-hidden />

            <span className="min-w-0 max-w-[10rem] truncate text-[13px] font-semibold text-[#111]">
                {site.name}
            </span>

            <div className="w-[9.5rem] shrink-0">
                <Select
                    aria-label={t('الصفحة')}
                    value={String(page.id)}
                    options={pages.map((p) => ({
                        value: String(p.id),
                        label: `${p.title}${p.status !== 'published' ? ' — ' + t('مسوّدة') : ''}`,
                    }))}
                    onChange={(e) => router.visit(route('admin.website.editor', e.target.value))}
                />
            </div>

            {/*
                والأجهزةُ في الوسط: هي شأنُ الورقة لا شأنُ اللوحة ولا شأنُ النشر.

                وتظهر من اللوحيّ صعودًا لا من الحاسوب وحده: في وضع التبويبين
                تملأ المعاينةُ الشاشة، فيبقى سؤال «كيف يبدو في الجوّال؟» قائمًا
                — ومقبضُه هنا. وتحته لا موضعَ له: الشاشةُ نفسُها بعرض جوّال.
            */}
            <div className="mx-auto hidden items-center gap-0.5 rounded-[10px] bg-[#f3f4f6] p-0.5 sm:flex">
                {DEVICES.map((d) => (
                    <button
                        key={d.key}
                        type="button"
                        aria-label={t(d.label)}
                        aria-pressed={device === d.key}
                        onClick={() => setDevice(d.key)}
                        className={cn(
                            'rounded-[8px] p-1.5 transition-colors',
                            device === d.key
                                ? 'bg-white text-[#111] shadow-sm'
                                : 'text-[#9ca3af] hover:text-[#111]',
                        )}
                    >
                        <d.icon className="size-4" />
                    </button>
                ))}
            </div>

            <div className="ms-auto flex items-center gap-2 lg:ms-0">
                {status_}

                {/* «افتح موقعك» لا يُعرض لما لم يُنشر: رابطٌ يفتح على لا شيء */}
                {site.url && (
                    <a
                        href={site.url}
                        target="_blank"
                        rel="noopener"
                        className="hidden items-center gap-1.5 rounded-[8px] px-2 py-1 text-[13px] text-[#6b7280] transition-colors hover:text-[#111] sm:flex"
                    >
                        <ExternalLink className="size-3.5" />
                        {t('افتح موقعك')}
                    </a>
                )}

                <Button
                    size="sm"
                    disabled={!site.changes}
                    onClick={() => {
                        flush();
                        router.post(route('admin.website.publish'), {}, { preserveScroll: true });
                    }}
                >
                    <Rocket />
                    {t('نشر')}
                </Button>
            </div>
        </div>
    );

    /* ───────────────────────────── اللوحة ───────────────────────────── */

    const body = open ? (
        <div>
            <button
                type="button"
                onClick={() => setOpenKey(null)}
                className="mb-3 flex items-center gap-1.5 text-[12px] text-[#6b7280] transition-colors hover:text-[#111]"
            >
                <ArrowRight className="size-3.5" />
                {t('كل الأقسام')}
            </button>

            <h2 className="text-[15px] font-bold text-[#111]">{open.label}</h2>
            {open.hint && <p className="mt-1 text-[12px] leading-6 text-[#9ca3af]">{open.hint}</p>}

            {open.slot && (
                <p className="mt-3 rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] leading-6 text-[#6b7280]">
                    {t('يظهر في جميع الصفحات')}
                </p>
            )}

            {/*
                ولا نسخةَ ثانية من المنتج في الموقع.
                اسمُه وسعرُه وصورتُه تُقرأ من «المنتجات» وتتحدّث وحدها — وما
                يُضبط هنا عرضُها لا بياناتُها. انظر `Sections::SOURCES`.
            */}
            {open.source && (
                <p className="mt-3 rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] leading-6 text-[#6b7280]">
                    {t('محتوى هذا القسم يُقرأ من نظامك ويتحدّث وحده — لا تكتبه هنا.')}
                    {open.source === 'products' && (
                        <Link
                            href={route('admin.products.index')}
                            className="ms-1 inline-flex items-center gap-0.5 font-medium text-[#111] underline underline-offset-2"
                        >
                            {t('إدارة المنتجات')}
                            <ChevronLeft className="size-3" />
                        </Link>
                    )}
                    {open.source === 'reviews' && (
                        <Link
                            href={route('admin.marketing.reviews')}
                            className="ms-1 inline-flex items-center gap-0.5 font-medium text-[#111] underline underline-offset-2"
                        >
                            {t('إدارة التقييمات')}
                            <ChevronLeft className="size-3" />
                        </Link>
                    )}
                </p>
            )}

            <div className="mt-5">
                <SectionForm
                    schema={open.schema}
                    data={draft ?? open.data}
                    onChange={edit}
                    networks={networks}
                    products={products}
                />
            </div>
        </div>
    ) : panel === 'design' ? (
        <DesignPanel
            template={site.template}
            templates={templates}
            theme={theme}
            options={themeOptions}
        />
    ) : (
        <SectionList
            pageTitle={page.title}
            globals={globals}
            sections={sections}
            activeKey={openKey}
            onOpen={(key) => setOpenKey(key)}
            onReorder={(order) =>
                router.post(
                    route('admin.website.sections.reorder', page.id),
                    { order },
                    { preserveScroll: true, preserveState: true },
                )
            }
            onToggle={(s) => act(route('admin.website.sections.toggle', s.id))}
            onDuplicate={(s) => act(route('admin.website.sections.duplicate', s.id))}
            onDelete={async (s) => {
                if (!(await ask({ message: 'حذف :name؟', values: { name: s.label }, danger: true, action: 'حذف' })))
                    return;
                setOpenKey(null);
                act(route('admin.website.sections.destroy', s.id), 'delete');
            }}
            onAdd={() => setAdding(true)}
        />
    );

    const aside = (
        <div className="flex min-h-0 w-full flex-col">
            {/* بابان لا أكثر: ما في الصفحة، وكيف تبدو */}
            {!open && (
                <div className="flex shrink-0 gap-1 border-b border-[var(--ui-border,#e8e8e8)] p-2">
                    {(
                        [
                            ['sections', 'الأقسام'],
                            ['design', 'التصميم'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setPanel(key)}
                            aria-pressed={panel === key}
                            className={cn(
                                'flex flex-1 items-center justify-center gap-1.5 rounded-[8px] px-3 py-1.5 text-[13px] transition-colors',
                                panel === key
                                    ? 'bg-[#f3f4f6] font-semibold text-[#111]'
                                    : 'text-[#6b7280] hover:text-[#111]',
                            )}
                        >
                            {key === 'design' && <Palette className="size-3.5" />}
                            {t(label)}
                        </button>
                    ))}
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto p-3">{body}</div>
        </div>
    );

    return (
        <AdminLayout title="محرّر الموقع">
            {/* على الشاشات الضيّقة: لوحةٌ أو معاينة — لا نصفان لا يُقرأ أحدهما */}
            <div className="mb-3 lg:hidden">
                <Tabs
                    current={pane}
                    onChange={(k) => setPane(k as 'edit' | 'preview')}
                    tabs={[
                        { key: 'preview', label: 'المعاينة' },
                        { key: 'edit', label: 'التحرير' },
                    ]}
                />
            </div>

            <div
                className="flex flex-col overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white"
                style={{
                    height: 'calc(100svh - var(--chrome-top, 0px) - 9.5rem)',
                    minHeight: 520,
                }}
            >
                {toolbar}

                <div className="flex min-h-0 flex-1">
                    <aside
                        className={cn(
                            'w-full shrink-0 border-[var(--ui-border,#e8e8e8)] lg:flex lg:w-[336px] lg:border-e',
                            pane === 'edit' ? 'flex' : 'hidden',
                        )}
                    >
                        {aside}
                    </aside>

                    {/*
                        وسطُ الشاشة هو الموقع — لا نموذجٌ ومعاينةٌ صغيرة بجانبه.
                        خلفيةٌ هادئة وورقةٌ بيضاء عليها: التاجر يعدّل متجره لا
                        يملأ استمارة.
                    */}
                    <div
                        className={cn(
                            'min-w-0 flex-1 overflow-y-auto overscroll-contain bg-[#f4f4f5] p-3 lg:block lg:p-6',
                            pane === 'preview' ? 'block' : 'hidden',
                        )}
                    >
                        <SitePreview
                            doc={document}
                            pageKey={page.key}
                            device={device}
                            activeKey={openKey}
                            onPick={pick}
                            labels={labels}
                            hidden={hidden}
                            className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-sm"
                        />

                        <p className="mt-3 text-center text-[12px] text-[#9ca3af]">
                            {t('اضغط أيّ جزءٍ من موقعك لتعديله — ولا يراه زوّارك حتى تنشر')}
                        </p>
                    </div>
                </div>
            </div>

            <AddSection
                open={adding}
                onOpenChange={setAdding}
                library={library}
                groups={groups}
                onAdd={(type) =>
                    router.post(
                        route('admin.website.sections.add', page.id),
                        { type },
                        { preserveScroll: true, onSuccess: () => setAdding(false) },
                    )
                }
            />

            {confirmDialog}
        </AdminLayout>
    );
}
