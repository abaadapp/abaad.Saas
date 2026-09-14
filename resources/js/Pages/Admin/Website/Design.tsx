import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Monitor, Settings2, Smartphone, Tablet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import LayoutPicker, { type Family } from './fields/LayoutPicker';
import SitePreview from './preview/SitePreview';
import { DEVICE_WIDTH, type Device, type SiteDocument } from './preview/types';
import type { SiteShell } from './shell';

interface Template {
    key: string;
    label: string;
    hint: string;
    legacy: boolean;
    swatch: string[];
    tokens: Record<string, string | number>;
}

interface Choice {
    key: string;
    label: string;
    options: { value: string; label: string }[];
}

interface Props extends SiteShell {
    templates: Template[];
    theme: Record<string, string>;
    layout: Record<string, string>;
    options: {
        fonts: { value: string; label: string }[];
        radii: { value: string; label: string }[];
        buttons: { value: string; label: string }[];
    };
    layout_options: { primary: Choice[]; advanced: Choice[] };
    document: SiteDocument;
}

const DEVICES: { key: Device; icon: typeof Monitor; label: string }[] = [
    { key: 'desktop', icon: Monitor, label: 'كمبيوتر' },
    { key: 'tablet', icon: Tablet, label: 'لوحي' },
    { key: 'mobile', icon: Smartphone, label: 'جوال' },
];

/** الرموز التي تُختار بالنظر — وما سواها صفُّ أزرارٍ أو قائمة */
const PICKERS: Record<string, Family> = {
    header: 'header',
    hero: 'hero',
    grid: 'grid',
    categories: 'categories',
    card: 'card',
    footer: 'footer',
};

/**
 * التصميم — قالبٌ، ثمّ ما يعدّله عليه، والنتيجة أمامه.
 *
 * ولا يُطلب من التاجر أن يتخيّل ما يفعله لونٌ اختاره ولا شكلُ شبكةٍ سمعنا
 * نحن باسمها: يختاره فيرى موقعه به. وما لا يختاره يُشتقّ (`Theme`) أو يأتي
 * من قالبه (`Layout`) — فيبقى الموقع متناسقًا مهما اختار، والنصُّ الذي لا
 * يُقرأ على خلفيته يُصحَّح عند الحفظ.
 *
 * وثلاثةُ مقابضَ في الوجه وتسعةٌ تحت زرّ: شاشةُ تصميمٍ فيها ثلاثة عشر مقبضًا
 * تُقرأ استمارةَ إعدادات، فيُغلقها من فتحها. والقالب يحدّدها كلَّها، فمن لم
 * يفتح «خيارات إضافية» أبدًا لا ينقصه شيء.
 */
export default function Design() {
    const { site, templates, theme, layout, options, layout_options, document } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [device, setDevice] = useState<Device>('desktop');
    const [localTheme, setLocalTheme] = useState(theme);
    const [localLayout, setLocalLayout] = useState(layout);
    const [advanced, setAdvanced] = useState(false);

    const fresh = templates.filter((x) => !x.legacy);
    const legacy = templates.filter((x) => x.legacy);

    const saveTheme = (next: Record<string, string>) => {
        setLocalTheme(next);
        router.put(
            route('admin.website.design.palette'),
            { theme: next },
            { preserveScroll: true, preserveState: true, only: ['document', 'theme', 'site'] },
        );
    };

    const saveLayout = (key: string, value: string) => {
        const next = { ...localLayout, [key]: value };

        setLocalLayout(next);
        router.put(
            route('admin.website.design.layout'),
            { layout: next },
            { preserveScroll: true, preserveState: true, only: ['document', 'layout', 'site'] },
        );
    };

    const pickTemplate = (key: string) =>
        router.put(route('admin.website.design.update'), { template: key, adopt: true }, { preserveScroll: true });

    const colors: { key: string; label: string; hint?: string }[] = [
        { key: 'primary', label: 'اللون الأساسي', hint: 'الأزرار والروابط وما يُبرَز' },
        { key: 'background', label: 'لون الخلفية' },
        { key: 'text', label: 'لون النص', hint: 'يُصحَّح تلقائيًّا إن لم يُقرأ على خلفيتك' },
    ];

    /** رمزُ بنيةٍ واحد — مُنتقًى بالنظر أو صفَّ أزرار */
    const choice = (c: Choice) => {
        const family = PICKERS[c.key];

        if (family) {
            return (
                <Field key={c.key} label={c.label}>
                    <LayoutPicker
                        value={localLayout[c.key] ?? ''}
                        options={c.options}
                        family={family}
                        onChange={(v) => saveLayout(c.key, v)}
                        columns={2}
                    />
                </Field>
            );
        }

        // وثلاثةُ خياراتٍ أو أقلّ صفُّ أزرارٍ لا قائمة: تُقرأ كلُّها دفعةً واحدة
        if (c.options.length <= 3) {
            return (
                <Field key={c.key} label={c.label}>
                    <div className="flex gap-1.5">
                        {c.options.map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                onClick={() => saveLayout(c.key, o.value)}
                                aria-pressed={localLayout[c.key] === o.value}
                                className={cn(
                                    'flex-1 rounded-[9px] border px-2 py-2 text-[12.5px] font-semibold transition-all',
                                    localLayout[c.key] === o.value
                                        ? 'border-[#111] bg-[#111] text-white'
                                        : 'border-[var(--ui-border,#e8e8e8)] text-[#6b7280] hover:border-[#c9c9c9]',
                                )}
                            >
                                {o.label}
                            </button>
                        ))}
                    </div>
                </Field>
            );
        }

        return (
            <Field key={c.key} label={c.label}>
                <Select
                    value={localLayout[c.key] ?? ''}
                    options={c.options}
                    onChange={(e) => saveLayout(c.key, e.target.value)}
                />
            </Field>
        );
    };

    const templateCard = (x: Template) => {
        const on = site.template === x.key;

        return (
            <button
                key={x.key}
                type="button"
                onClick={() => pickTemplate(x.key)}
                className={cn(
                    'overflow-hidden rounded-[12px] border text-start transition-all',
                    on ? 'border-[#111] ring-1 ring-[#111]' : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                )}
            >
                <span className="flex h-14 items-center justify-center gap-2" style={{ background: x.swatch[1] }}>
                    <span className="h-5 w-12 rounded" style={{ background: x.swatch[0] }} aria-hidden />
                    <span className="flex flex-col gap-1" aria-hidden>
                        <span className="block h-1 w-8 rounded-full" style={{ background: x.swatch[2], opacity: 0.8 }} />
                        <span className="block h-1 w-5 rounded-full" style={{ background: x.swatch[2], opacity: 0.4 }} />
                    </span>
                </span>
                <span className="block px-3 py-2.5">
                    <span className="flex items-center gap-1.5">
                        <span className="text-[13px] font-bold text-[#111]">{x.label}</span>
                        {on && <Check className="size-3.5 text-[#15803d]" />}
                    </span>
                    <span className="mt-0.5 block text-[11.5px] leading-5 text-[#9ca3af]">{x.hint}</span>
                </span>
            </button>
        );
    };

    return (
        <AdminLayout title="تصميم الموقع">
            <PageHeader title="التصميم" subtitle={t('اختر شكلًا، ثمّ غيّر ما تشاء — والنتيجة أمامك مباشرة')} />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.design" />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[380px_minmax(0,1fr)]">
                <div className="space-y-4">
                    <Card className="p-4">
                        <h3 className="mb-3 font-bold text-[#111]">{t('القالب')}</h3>
                        <div className="grid grid-cols-2 gap-2">{fresh.map(templateCard)}</div>

                        {legacy.length > 0 && (
                            <>
                                <p className="mt-4 text-[12px] leading-6 text-[#9ca3af]">
                                    {t('قالبك من الجيل الأول — يعمل كما هو، والقوالب أعلاه أحدث منه.')}
                                </p>
                                <div className="mt-2 grid grid-cols-2 gap-2">{legacy.map(templateCard)}</div>
                            </>
                        )}

                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('تبديل القالب يغيّر الألوان والتخطيط — ولا يمسّ صفحاتك ولا محتواك.')}
                        </p>
                    </Card>

                    <Card className="space-y-4 p-4">
                        <h3 className="font-bold text-[#111]">{t('الألوان')}</h3>

                        {colors.map((c) => (
                            <Field key={c.key} label={c.label} hint={c.hint}>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="color"
                                        aria-label={t(c.label)}
                                        value={localTheme[c.key] ?? '#000000'}
                                        onChange={(e) => saveTheme({ ...localTheme, [c.key]: e.target.value })}
                                        className="size-10 shrink-0 cursor-pointer rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1"
                                    />
                                    <span dir="ltr" className="font-mono text-[13px] text-[#6b7280]">
                                        {localTheme[c.key]}
                                    </span>
                                    {c.key === 'text' && localTheme.text !== theme.text && (
                                        <Badge variant="warning">{t('صُحّح ليُقرأ')}</Badge>
                                    )}
                                </div>
                            </Field>
                        ))}
                    </Card>

                    <Card className="space-y-4 p-4">
                        <h3 className="font-bold text-[#111]">{t('الخط والشكل')}</h3>

                        <Field label="الخط">
                            <Select
                                value={localTheme.font}
                                options={options.fonts}
                                onChange={(e) => saveTheme({ ...localTheme, font: e.target.value })}
                            />
                        </Field>

                        <Field label="تدوير الحواف">
                            <Select
                                value={localTheme.radius}
                                options={options.radii}
                                onChange={(e) => saveTheme({ ...localTheme, radius: e.target.value })}
                            />
                        </Field>

                        <Field label="شكل الأزرار">
                            <Select
                                value={localTheme.button}
                                options={options.buttons}
                                onChange={(e) => saveTheme({ ...localTheme, button: e.target.value })}
                            />
                        </Field>
                    </Card>

                    <Card className="space-y-4 p-4">
                        <h3 className="font-bold text-[#111]">{t('التخطيط')}</h3>
                        {layout_options.primary.map(choice)}

                        <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                            <button
                                type="button"
                                onClick={() => setAdvanced((v) => !v)}
                                aria-expanded={advanced}
                                className="flex w-full items-center gap-2 text-[13px] font-medium text-[#6b7280] hover:text-[#111]"
                            >
                                <Settings2 className="size-4" />
                                {t('خيارات إضافية')}
                                <ChevronDown className={cn('ms-auto size-4 transition-transform', advanced && 'rotate-180')} />
                            </button>

                            {advanced && <div className="mt-4 space-y-4">{layout_options.advanced.map(choice)}</div>}
                        </div>
                    </Card>
                </div>

                <Card className="overflow-hidden">
                    <div className="flex items-center gap-1 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-2.5">
                        {DEVICES.map((d) => (
                            <button
                                key={d.key}
                                type="button"
                                aria-label={t(d.label)}
                                aria-pressed={device === d.key}
                                onClick={() => setDevice(d.key)}
                                className={cn(
                                    'rounded-[8px] p-2 transition-colors',
                                    device === d.key ? 'bg-[#111] text-white' : 'text-[#9ca3af] hover:text-[#111]',
                                )}
                            >
                                <d.icon className="size-4" />
                            </button>
                        ))}
                        <span className="ms-auto text-[12px] text-[#9ca3af]" dir="ltr">
                            {DEVICE_WIDTH[device]}px
                        </span>
                    </div>

                    <div className="bg-[#f5f5f5] p-4">
                        <SitePreview
                            doc={document}
                            device={device}
                            className="mx-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-sm"
                        />
                    </div>
                </Card>
            </div>
        </AdminLayout>
    );
}
