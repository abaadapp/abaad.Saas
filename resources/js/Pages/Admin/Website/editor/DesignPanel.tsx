import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface TemplateCard {
    key: string;
    label: string;
    hint: string;
    swatch: string[];
    featured: boolean;
}

export interface ThemeOptions {
    fonts: { value: string; label: string }[];
    radii: { value: string; label: string }[];
    buttons: { value: string; label: string }[];
}

interface Props {
    template: string;
    templates: TemplateCard[];
    theme: Record<string, string>;
    options: ThemeOptions;
}

/**
 * التصميم — لوحةٌ في المحرّر لا شاشةٌ إلى جانبه.
 *
 * كان بابًا مستقلًّا بمعاينته: من يعدّل نصَّ واجهته ثمّ يريد تجربة لونٍ يغادر
 * المحرّر ويعود. وهما عملٌ واحد في ذهن صاحب المتجر — «أُحسّن شكل موقعي» —
 * فصارا شاشةً واحدة ولوحتين.
 *
 * وستّةُ اختيارات لا أكثر: قالبٌ ولونٌ أساسيّ وخلفيةٌ ولونُ نصٍّ وخطٌّ وحوافُّ
 * وشكلُ زر. وما بقي يُشتقّ في الخادم (انظر `Theme`) — لونُ ما يُكتب فوق
 * الأساسيّ، ولونُ البطاقات، ولونُ السطور الخافتة. فلا يقع التاجر في تركيبةٍ
 * سيّئة لأنّه لم يُعطَ سبيلًا إليها، ونصٌّ لا يُقرأ على خلفيته يُصحَّح عند
 * الحفظ.
 *
 * ولا محرّرَ أنماطٍ هنا ولا مقاسٌ بالبكسل: من يريد ذلك ليس من نبني له.
 */
export default function DesignPanel({ template, templates, theme, options }: Props) {
    const t = useTranslate();

    /*
     * نسخةٌ محلّية تسبق الخادم.
     *
     * منتقي اللون يُطلق حدثًا مع كلّ تحريكة، وبلا هذه النسخة يرتدّ المقبض
     * إلى موضعه القديم بين الإرسال والردّ.
     */
    const [local, setLocal] = useState(theme);
    const [more, setMore] = useState(false);

    const save = (next: Record<string, string>) => {
        setLocal(next);
        router.put(
            route('admin.website.design.palette'),
            { theme: next },
            // المعاينةُ وحدها تُعاد: الحمولة كلّها في كلّ تحريكةِ لونٍ ثقيلة
            { preserveScroll: true, preserveState: true, only: ['document', 'theme', 'site'] },
        );
    };

    const pick = (key: string) =>
        router.put(
            route('admin.website.design.update'),
            /*
             * و«تبديل القالب» يعني ألوانه لا ألوانَ التاجر.
             *
             * من يختار «جريء» يريد برتقاليَّه على فحميّه، لا لونَه الأزرق على
             * خلفيةٍ فحميّة لم يخترها. و`adopt` تقول للخادم: هذا تبديلُ قالبٍ
             * لا ضبطُ لون — فتُؤخذ رموزُه كاملة.
             */
            { template: key, adopt: true },
            { preserveScroll: true },
        );

    const colors: { key: string; label: string; hint?: string }[] = [
        { key: 'primary', label: 'اللون الأساسي', hint: 'الأزرار والروابط وما يُبرَز' },
        { key: 'background', label: 'لون الخلفية' },
        { key: 'text', label: 'لون النص', hint: 'يُصحَّح تلقائيًّا إن لم يُقرأ على خلفيتك' },
    ];

    const card = (x: TemplateCard) => {
        const on = template === x.key;

        return (
            <button
                key={x.key}
                type="button"
                onClick={() => pick(x.key)}
                title={x.hint}
                className={cn(
                    'overflow-hidden rounded-[10px] border text-start transition-all',
                    on
                        ? 'border-[#111] ring-1 ring-[#111]'
                        : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                )}
            >
                {/* لوحةُ ألوانه لا صورةُ معاينة: المعاينةُ الحقيقية إلى جانبها */}
                <span
                    className="flex h-11 items-center justify-center gap-2"
                    style={{ background: x.swatch[1] }}
                >
                    <span
                        className="h-4 w-9 rounded"
                        style={{ background: x.swatch[0] }}
                        aria-hidden
                    />
                    <span
                        className="h-1 w-5 rounded-full"
                        style={{ background: x.swatch[2], opacity: 0.6 }}
                        aria-hidden
                    />
                </span>
                <span className="flex items-center gap-1.5 px-2.5 py-1.5 text-[12px] font-semibold text-[#111]">
                    {x.label}
                    {on && <Check className="size-3.5 text-[#15803d]" />}
                </span>
            </button>
        );
    };

    const featured = templates.filter((x) => x.featured);
    /* والقالبُ الذي يستعمله التاجرُ يبقى ظاهرًا وإن لم يكن من الثلاثة */
    const rest = templates.filter((x) => !x.featured && x.key !== template);
    const current = templates.find((x) => x.key === template);
    const top = current && !current.featured ? [...featured, current] : featured;

    return (
        <div className="space-y-6">
            <section>
                <h3 className="mb-2 px-1 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                    {t('الشكل العام')}
                </h3>
                <div className="grid grid-cols-2 gap-2">{top.map(card)}</div>

                {rest.length > 0 && (
                    <>
                        <button
                            type="button"
                            onClick={() => setMore((v) => !v)}
                            className="mt-2 flex w-full items-center gap-1.5 px-1 py-1 text-[12px] text-[#6b7280] hover:text-[#111]"
                        >
                            {t('قوالب أخرى')}
                            <ChevronDown
                                className={cn('size-3.5 transition-transform', more && 'rotate-180')}
                            />
                        </button>
                        {more && <div className="grid grid-cols-2 gap-2">{rest.map(card)}</div>}
                    </>
                )}

                <p className="mt-2 px-1 text-[12px] leading-6 text-[#9ca3af]">
                    {t('تبديل القالب يغيّر الألوان والخط — ولا يمسّ صفحاتك ولا محتواك.')}
                </p>
            </section>

            <section className="space-y-3.5">
                <h3 className="px-1 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                    {t('الألوان')}
                </h3>

                {colors.map((c) => (
                    <Field key={c.key} label={c.label} hint={c.hint}>
                        <div className="flex items-center gap-3">
                            <input
                                type="color"
                                aria-label={t(c.label)}
                                value={local[c.key] ?? '#000000'}
                                onChange={(e) => save({ ...local, [c.key]: e.target.value })}
                                className="size-9 shrink-0 cursor-pointer rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1"
                            />
                            <span dir="ltr" className="font-mono text-[12px] text-[#6b7280]">
                                {local[c.key]}
                            </span>
                        </div>
                    </Field>
                ))}
            </section>

            <section className="space-y-3.5">
                <h3 className="px-1 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                    {t('الخط والشكل')}
                </h3>

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
            </section>
        </div>
    );
}
