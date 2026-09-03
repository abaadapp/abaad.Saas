import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Monitor, Smartphone, Tablet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SitePreview from './preview/SitePreview';
import { DEVICE_WIDTH, type Device, type SiteDocument } from './preview/types';
import type { SiteShell } from './shell';

interface Template {
    key: string;
    label: string;
    hint: string;
    swatch: string[];
}

interface Props extends SiteShell {
    templates: Template[];
    theme: Record<string, string>;
    options: {
        fonts: { value: string; label: string }[];
        radii: { value: string; label: string }[];
        buttons: { value: string; label: string }[];
    };
    document: SiteDocument;
}

const DEVICES: { key: Device; icon: typeof Monitor; label: string }[] = [
    { key: 'desktop', icon: Monitor, label: 'كمبيوتر' },
    { key: 'tablet', icon: Tablet, label: 'لوحي' },
    { key: 'mobile', icon: Smartphone, label: 'جوال' },
];

/**
 * التصميم — قالبٌ وستّة اختيارات، والنتيجة أمامه.
 *
 * ولا يُطلب من التاجر أن يتخيّل ما يفعله لونٌ اختاره: يختاره فيرى الموقع كلّه
 * به. وما لا يختاره يُشتقّ (انظر `Theme`) فيبقى الموقع متناسقًا ومقروءًا مهما
 * اختار — والنصّ الذي لا يُقرأ على خلفيته يُصحَّح عند الحفظ.
 */
export default function Design() {
    const { site, templates, theme, options, document } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [device, setDevice] = useState<Device>('desktop');
    const [local, setLocal] = useState(theme);

    const save = (next: Record<string, string>) => {
        setLocal(next);
        router.put(
            route('admin.website.design.palette'),
            { theme: next },
            { preserveScroll: true, preserveState: true, only: ['document', 'theme', 'site'] },
        );
    };

    const pickTemplate = (key: string) =>
        router.put(
            route('admin.website.design.update'),
            { template: key, adopt: true },
            {
                preserveScroll: true,
                onSuccess: () => {
                    /* الألوان تتبع القالب — والشاشة تقرؤها من الحمولة الجديدة */
                },
            },
        );

    const colors: { key: string; label: string; hint?: string }[] = [
        { key: 'primary', label: 'اللون الأساسي', hint: 'الأزرار والروابط وما يُبرَز' },
        { key: 'background', label: 'لون الخلفية' },
        { key: 'text', label: 'لون النص', hint: 'يُصحَّح تلقائيًّا إن لم يُقرأ على خلفيتك' },
    ];

    return (
        <AdminLayout title="تصميم الموقع">
            <PageHeader
                title="التصميم"
                subtitle={t('اختر شكلًا، ثمّ غيّر ما تشاء — والنتيجة أمامك مباشرة')}
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.design" />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[360px_minmax(0,1fr)]">
                <div className="space-y-4">
                    <Card className="p-4">
                        <h3 className="mb-3 font-bold text-[#111]">{t('القالب')}</h3>
                        <div className="grid grid-cols-2 gap-2">
                            {templates.map((x) => {
                                const on = site.template === x.key;

                                return (
                                    <button
                                        key={x.key}
                                        type="button"
                                        onClick={() => pickTemplate(x.key)}
                                        className={cn(
                                            'overflow-hidden rounded-[10px] border text-start transition-all',
                                            on ? 'border-[#111] ring-1 ring-[#111]' : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                                        )}
                                    >
                                        <span
                                            className="flex h-12 items-center justify-center gap-2"
                                            style={{ background: x.swatch[1] }}
                                        >
                                            <span className="h-4 w-10 rounded" style={{ background: x.swatch[0] }} aria-hidden />
                                            <span className="h-1 w-6 rounded-full" style={{ background: x.swatch[2], opacity: 0.6 }} aria-hidden />
                                        </span>
                                        <span className="flex items-center gap-1.5 px-2.5 py-2 text-[12px] font-semibold text-[#111]">
                                            {x.label}
                                            {on && <Check className="size-3.5 text-[#15803d]" />}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('تبديل القالب يغيّر الألوان والخط — ولا يمسّ صفحاتك ولا محتواك.')}
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
                                        value={local[c.key] ?? '#000000'}
                                        onChange={(e) => save({ ...local, [c.key]: e.target.value })}
                                        className="size-10 shrink-0 cursor-pointer rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1"
                                    />
                                    <span dir="ltr" className="font-mono text-[13px] text-[#6b7280]">
                                        {local[c.key]}
                                    </span>
                                    {c.key === 'text' && local.text !== theme.text && (
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
                                value={local.font}
                                options={options.fonts}
                                onChange={(e) => save({ ...local, font: e.target.value })}
                            />
                        </Field>

                        <Field label="تدوير الحواف">
                            <Select
                                value={local.radius}
                                options={options.radii}
                                onChange={(e) => save({ ...local, radius: e.target.value })}
                            />
                        </Field>

                        <Field label="شكل الأزرار">
                            <Select
                                value={local.button}
                                options={options.buttons}
                                onChange={(e) => save({ ...local, button: e.target.value })}
                            />
                        </Field>
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
