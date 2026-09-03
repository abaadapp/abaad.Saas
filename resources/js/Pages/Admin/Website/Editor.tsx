import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ChevronDown,
    Copy,
    Eye,
    EyeOff,
    LayoutPanelTop,
    Loader2,
    Monitor,
    Plus,
    Rocket,
    Smartphone,
    Tablet,
    Trash2,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SectionForm, { type FieldSpec, type PickerProduct } from './fields/SectionForm';
import SitePreview from './preview/SitePreview';
import type { Device, SiteDocument } from './preview/types';
import { STATE_LABEL, STATE_TONE, type SiteShell } from './shell';

interface EditorSection {
    id: number;
    type: string;
    slot: string | null;
    label: string;
    hint: string;
    visible: boolean;
    source: string | null;
    data: Record<string, unknown>;
    schema: FieldSpec[];
}

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
    library: {
        type: string;
        label: string;
        hint: string;
        group: string;
        unique: boolean;
        source: string | null;
    }[];
    groups: string[];
    networks: { value: string; label: string }[];
    products: PickerProduct[];
    document: SiteDocument;
    statuses: { value: string; label: string }[];
}

const DEVICES: { key: Device; label: string; icon: typeof Monitor }[] = [
    { key: 'desktop', label: 'كمبيوتر', icon: Monitor },
    { key: 'tablet', label: 'لوحي', icon: Tablet },
    { key: 'mobile', label: 'جوال', icon: Smartphone },
];

/**
 * المحرّر — لوحةٌ إلى جانب الموقع نفسه.
 *
 * التاجر لا يتخيّل النتيجة: يكتب في اليمين فيرى في اليسار. والمعاينة ليست
 * صورةً تقريبيّة — هي المستند الذي سيقرؤه العارض، مرسومًا بالرموز نفسها
 * (انظر `preview/SitePreview`).
 *
 * وثلاث قواعد تحكم هذه الشاشة:
 *
 * ١) **لا يُعرض إلا قسمٌ واحد.** عشرون قسمًا بحقولها معًا شاشةٌ لا تُقرأ.
 *    فالقائمة أوّلًا، ثمّ حقولُ ما اختير وحده.
 * ٢) **لا زرَّ حفظ.** يُحفظ بنفسه بعد أن يكفّ التاجر عن الكتابة بثانية، ويُقال
 *    له «تم الحفظ ✓». وزرُّ الحفظ في محرّرٍ يعني تعديلًا يضيع لأنّ أحدًا نسي
 *    أن يضغطه.
 * ٣) **الحفظ ليس نشرًا.** كلّ ما يُكتب هنا في المسوّدة، ولا يراه زائرٌ حتى
 *    يُضغط «نشر». فيجرّب التاجر ويحذف ويعيد بلا خوف على موقعٍ يعمل.
 */
export default function Editor() {
    const { site, pages, page, sections, globals, library, groups, networks, products, document } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();

    const [device, setDevice] = useState<Device>('desktop');
    const [pane, setPane] = useState<'edit' | 'preview'>('edit');
    const [adding, setAdding] = useState(false);
    const [openId, setOpenId] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const all = useMemo(() => [...globals, ...sections], [globals, sections]);
    const open = all.find((s) => s.id === openId) ?? null;

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
        setSaving(true);
        router.put(
            route('admin.website.sections.update', id),
            // الحمولة متداخلة، و`FormDataConvertible` لا تصفها — والخادم يقرأ JSON
            { data } as never,
            {
                preserveScroll: true,
                preserveState: true,
                // المعاينة وحدها تُعاد: الحمولة كلّها في كلّ ضغطة مفتاح ثقيلة
                only: ['document', 'site'],
                onFinish: () => setSaving(false),
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
        pending.current = { id: open.id, data };

        if (timer.current) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(flush, 900);
    };

    // تبديل القسم يُرسل ما قبله: القسمان لا يتبادلان محتواهما
    useEffect(() => {
        flush();
        setDraft(open ? open.data : null);
    }, [openId]); // eslint-disable-line react-hooks/exhaustive-deps

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

    const move = (index: number, delta: number) => {
        const target = index + delta;

        if (target < 0 || target >= sections.length) return;

        const order = sections.map((s) => s.id);
        [order[index], order[target]] = [order[target], order[index]];

        router.post(
            route('admin.website.sections.reorder', page.id),
            { order },
            { preserveScroll: true, preserveState: true },
        );
    };

    const panel = (
        <div className="space-y-4">
            {/* ===== الصفحة المفتوحة ===== */}
            <Card className="p-4">
                <label className="mb-2 block text-[12px] font-medium text-[#6b7280]">{t('الصفحة')}</label>
                <Select
                    value={String(page.id)}
                    options={pages.map((p) => ({
                        value: String(p.id),
                        label: `${p.title}${p.status !== 'published' ? ' — ' + t('مسوّدة') : ''}`,
                    }))}
                    onChange={(e) => router.visit(route('admin.website.editor', e.target.value))}
                />
            </Card>

            {open ? (
                /* ===== حقول القسم المفتوح وحده ===== */
                <Card className="p-4">
                    <div className="mb-4 flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <button
                                type="button"
                                onClick={() => setOpenId(null)}
                                className="mb-2 flex items-center gap-1.5 text-[12px] text-[#6b7280] hover:text-[#111]"
                            >
                                <ArrowRight className="size-3.5" />
                                {t('كل الأقسام')}
                            </button>
                            <h3 className="font-bold text-[#111]">{open.label}</h3>
                            {open.hint && (
                                <p className="mt-1 text-[12px] leading-6 text-[#9ca3af]">{open.hint}</p>
                            )}
                        </div>
                        {saving ? (
                            <Loader2 className="size-4 shrink-0 animate-spin text-[#9ca3af]" />
                        ) : (
                            <span className="flex shrink-0 items-center gap-1 text-[12px] text-[#15803d]">
                                <Check className="size-3.5" />
                                {t('محفوظ')}
                            </span>
                        )}
                    </div>

                    {open.source && (
                        <p className="mb-4 rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] leading-6 text-[#6b7280]">
                            {t('محتوى هذا القسم يُقرأ من نظامك ويتحدّث وحده — لا تكتبه هنا.')}
                        </p>
                    )}

                    <SectionForm
                        schema={open.schema}
                        data={draft ?? open.data}
                        onChange={edit}
                        networks={networks}
                        products={products}
                    />
                </Card>
            ) : (
                /* ===== قائمة الأقسام ===== */
                <>
                    <Card className="overflow-hidden">
                        <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[12px] font-medium text-[#6b7280]">
                            {t('في كل الصفحات')}
                        </p>
                        {globals.map((s) => (
                            <button
                                key={s.id}
                                type="button"
                                onClick={() => setOpenId(s.id)}
                                className="flex w-full items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-start last:border-0 hover:bg-[#fafafa]"
                            >
                                <LayoutPanelTop className="size-4 shrink-0 text-[#9ca3af]" />
                                <span className="flex-1 text-[13px] font-medium text-[#111]">{s.label}</span>
                                <ChevronDown className="size-4 shrink-0 -rotate-90 text-[#d1d5db]" />
                            </button>
                        ))}
                    </Card>

                    <Card className="overflow-hidden">
                        <div className="flex items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                            <p className="text-[12px] font-medium text-[#6b7280]">
                                {t('أقسام')} «{page.title}»
                            </p>
                            <Button size="sm" variant="ghost" onClick={() => setAdding(true)}>
                                <Plus />
                                {t('قسم')}
                            </Button>
                        </div>

                        {sections.length === 0 && (
                            <p className="px-4 py-8 text-center text-[13px] text-[#9ca3af]">
                                {t('لا أقسام في هذه الصفحة — أضف أوّل قسم')}
                            </p>
                        )}

                        {sections.map((s, i) => (
                            <div
                                key={s.id}
                                className={cn(
                                    'flex items-center gap-1 border-b border-[var(--ui-border,#e8e8e8)] px-2 py-2 last:border-0 hover:bg-[#fafafa]',
                                    !s.visible && 'opacity-55',
                                )}
                            >
                                {/*
                                    الترتيب بزرّين لا بالسحب وحده: السحب لا يعمل
                                    بلوحة المفاتيح ولا يسهل على شاشةٍ صغيرة،
                                    والزرّان يعملان في الحالين.
                                */}
                                <div className="flex shrink-0 flex-col">
                                    <button
                                        type="button"
                                        aria-label={t('لأعلى')}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                        className="px-1 text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                                    >
                                        <ChevronDown className="size-3.5 rotate-180" />
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={t('لأسفل')}
                                        disabled={i === sections.length - 1}
                                        onClick={() => move(i, 1)}
                                        className="px-1 text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                                    >
                                        <ChevronDown className="size-3.5" />
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setOpenId(s.id)}
                                    className="min-w-0 flex-1 text-start"
                                >
                                    <span className="block truncate text-[13px] font-medium text-[#111]">
                                        {s.label}
                                    </span>
                                    {!s.visible && (
                                        <span className="text-[11px] text-[#9ca3af]">
                                            {t('مخفيّ عن الزوّار')}
                                        </span>
                                    )}
                                </button>

                                <div className="flex shrink-0 items-center">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(s.visible ? 'إخفاء' : 'إظهار')}
                                        onClick={() => act(route('admin.website.sections.toggle', s.id))}
                                    >
                                        {s.visible ? <Eye /> : <EyeOff />}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('نسخ')}
                                        onClick={() => act(route('admin.website.sections.duplicate', s.id))}
                                    >
                                        <Copy />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-[#b91c1c]"
                                        aria-label={t('حذف')}
                                        onClick={async () => {
                                            if (! await ask({ message: 'حذف :name؟', values: { name: s.label }, danger: true, action: 'حذف' })) return;
                                            act(route('admin.website.sections.destroy', s.id), 'delete');
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </Card>
                </>
            )}
        </div>
    );

    const preview = (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-2.5">
                <div className="flex items-center gap-1">
                    {DEVICES.map((d) => (
                        <button
                            key={d.key}
                            type="button"
                            aria-label={t(d.label)}
                            aria-pressed={device === d.key}
                            onClick={() => setDevice(d.key)}
                            className={cn(
                                'rounded-[8px] p-2 transition-colors',
                                device === d.key
                                    ? 'bg-[#111] text-white'
                                    : 'text-[#9ca3af] hover:text-[#111]',
                            )}
                        >
                            <d.icon className="size-4" />
                        </button>
                    ))}
                </div>
                <p className="text-[12px] text-[#9ca3af]">{t('هذه معاينة — لا يراها زوّارك حتى تنشر')}</p>
            </div>

            <div className="bg-[#f5f5f5] p-4">
                <SitePreview
                    doc={document}
                    pageKey={page.key}
                    device={device}
                    activeIndex={open && !open.slot ? sections.findIndex((s) => s.id === open.id) : null}
                    onSelect={(i) => setOpenId(sections[i]?.id ?? null)}
                    className="mx-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-sm"
                />
            </div>
        </Card>
    );

    return (
        <AdminLayout title="محرّر الموقع">
            <PageHeader
                title={site.name}
                subtitle={t('عدّل ما تشاء — يُحفظ تلقائيًّا، ولا يراه زوّارك حتى تنشر')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={STATE_TONE[site.state]}>{t(STATE_LABEL[site.state])}</Badge>
                        <Button
                            disabled={!site.changes}
                            onClick={() =>
                                router.post(route('admin.website.publish'), {}, { preserveScroll: true })
                            }
                        >
                            <Rocket />
                            {t('نشر التغييرات')}
                        </Button>
                    </div>
                }
            />

            {/* على الشاشات الضيّقة: لوحةٌ أو معاينة — لا نصفان لا يُقرأ أحدهما */}
            <div className="mb-4 lg:hidden">
                <Tabs
                    current={pane}
                    onChange={(k) => setPane(k as 'edit' | 'preview')}
                    tabs={[
                        { key: 'edit', label: 'التحرير' },
                        { key: 'preview', label: 'المعاينة' },
                    ]}
                />
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[380px_minmax(0,1fr)]">
                <div className={cn(pane === 'edit' ? 'block' : 'hidden', 'lg:block')}>{panel}</div>
                <div className={cn(pane === 'preview' ? 'block' : 'hidden', 'lg:block')}>{preview}</div>
            </div>

            {/* ===== مكتبة الأقسام ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('أضف قسمًا')}</DialogTitle>
                    </DialogHeader>

                    <div className="max-h-[60vh] space-y-6 overflow-y-auto px-5 pb-5">
                        {groups.map((group) => {
                            const items = library.filter((x) => x.group === group);

                            if (items.length === 0) return null;

                            return (
                                <div key={group}>
                                    <h4 className="mb-2 text-[12px] font-semibold text-[#9ca3af]">{group}</h4>
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        {items.map((x) => (
                                            <button
                                                key={x.type}
                                                type="button"
                                                onClick={() => {
                                                    router.post(
                                                        route('admin.website.sections.add', page.id),
                                                        { type: x.type },
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () => setAdding(false),
                                                        },
                                                    );
                                                }}
                                                className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 text-start transition-colors hover:border-[#c9c9c9] hover:bg-[#fafafa]"
                                            >
                                                <span className="flex items-center gap-2">
                                                    <span className="font-semibold text-[13px] text-[#111]">
                                                        {x.label}
                                                    </span>
                                                    {x.source && <Badge variant="info">{t('تلقائي')}</Badge>}
                                                </span>
                                                <span className="mt-1 block text-[12px] leading-6 text-[#6b7280]">
                                                    {x.hint}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}
