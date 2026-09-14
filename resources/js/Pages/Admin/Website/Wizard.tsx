import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    Expand,
    Image as ImageIcon,
    LayoutGrid,
    Monitor,
    Rocket,
    ShoppingBag,
    Smartphone,
    Sparkles,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SitePreview from './preview/SitePreview';
import type { Device, SiteDocument, Tokens } from './preview/types';

interface Goal {
    key: string;
    label: string;
    hint: string;
    icon: string;
}

interface Template {
    key: string;
    label: string;
    hint: string;
    legacy: boolean;
    swatch: string[];
    theme: Record<string, string>;
    /** رموزُ المستند كما يقرؤها العارض — لونًا وبنية */
    tokens: Tokens;
}

interface Props {
    goals: Goal[];
    templates: Template[];
    /**
     * الموقع كما سيُبنى لكلّ وجهة — بمنتجات التاجر وشعاره.
     *
     * ولقطةٌ لكلّ وجهة لا لكلّ قالب: البنيةُ تتبع الوجهة والرموزُ تتبع
     * القالب، فتُبدَّل الرموزُ هنا بلا طلبٍ ثانٍ. انظر `Builder::blueprint`.
     */
    previews: Record<string, SiteDocument>;
    identity: {
        name: string;
        logo: string;
        tagline: string;
        about: string;
        phone: string;
        email: string;
        address: string;
        whatsapp: string;
        instagram: string;
    };
    available: Record<string, boolean>;
    counts: { products: number; categories: number; reviews: number };
    domain: { domain: string; subdomain: string | null };
}

const GOAL_ICONS: Record<string, typeof ShoppingBag> = {
    'shopping-bag': ShoppingBag,
    'layout-grid': LayoutGrid,
    'building-2': Building2,
};

const STEPS = ['ماذا تريد من موقعك؟', 'اختر تصميم متجرك', 'تأكيد بياناتك'];

/**
 * إنشاء الموقع — سؤالان وتأكيد.
 *
 * ولا يُطلب من التاجر شيءٌ يعرفه النظام. اسمُه وشعارُه وهاتفُه ومنتجاتُه
 * موجودةٌ منذ فُتح حسابه، فتُعرض عليه ليؤكّدها لا ليُدخلها. وهذه هي الخطوة
 * الثالثة: ليست استمارةً، بل قائمةُ ما سيُستعمل.
 *
 * وثلاثُ خطواتٍ لا ثمان: كلّ خطوةٍ تُضاف تُسقط ربعَ من بدأ. وما لا يُسأل عنه
 * هنا يُعدَّل بعد أن يرى التاجر موقعه — وهو حينئذٍ يعرف ما يريد تغييره.
 */
export default function Wizard() {
    const { goals, templates, previews, identity, counts, domain } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [step, setStep] = useState(0);
    const [zoom, setZoom] = useState<string | null>(null);
    const [device, setDevice] = useState<Device>('desktop');

    const form = useForm({
        goal: '',
        template: '',
        name: identity.name,
        tagline: identity.tagline,
    });

    const chosen = templates.find((x) => x.key === form.data.template) ?? null;
    const canNext = step === 0 ? !!form.data.goal : step === 1 ? !!form.data.template : true;

    /*
     * المعاينة مستندٌ واحد ورموزٌ متبدّلة.
     *
     * القالب لم يعد لونًا يُرى في ثلاث بقع — صار ترويسةً وواجهةً وشبكةً
     * وتذييلًا. فالشاشةُ تعرض الموقعَ نفسَه أربع مرّات برموزٍ أربعة، وهو
     * الفرق بين «اختر لونًا» و«اختر تصميمًا».
     */
    const base = previews[form.data.goal] ?? previews.store;

    const docs = useMemo(
        () =>
            Object.fromEntries(
                templates.map((x) => [x.key, { ...base, theme: x.theme, tokens: x.tokens } satisfies SiteDocument]),
            ),
        [base, templates],
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.website.create'));
    };

    return (
        <AdminLayout title="إنشاء الموقع">
            <PageHeader
                title="أنشئ موقع متجرك"
                subtitle={t('جاوب سؤالين، ونبني لك موقعًا جاهزًا للنشر ببياناتك ومنتجاتك.')}
            />

            {/* الخطوات تُعرض كلّها: من يعرف كم بقي يُكمل */}
            <div className="mb-8 flex items-center gap-2 sm:gap-3">
                {STEPS.map((label, i) => (
                    <div key={label} className="flex flex-1 items-center gap-2 sm:gap-3">
                        <span
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center rounded-full text-[12px] font-bold transition-colors',
                                i < step
                                    ? 'bg-[#15803d] text-white'
                                    : i === step
                                      ? 'bg-[#111] text-white'
                                      : 'bg-[#f0f0f0] text-[#9ca3af]',
                            )}
                        >
                            {i < step ? <Check className="size-3.5" /> : i + 1}
                        </span>
                        <span
                            className={cn(
                                'hidden truncate text-[13px] sm:block',
                                i === step ? 'font-semibold text-[#111]' : 'text-[#9ca3af]',
                            )}
                        >
                            {t(label)}
                        </span>
                        {i < STEPS.length - 1 && (
                            <span className="h-px flex-1 bg-[var(--ui-border,#e8e8e8)]" aria-hidden />
                        )}
                    </div>
                ))}
            </div>

            <form onSubmit={submit}>
                {/* ===== ١ · الوجهة ===== */}
                {step === 0 && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {goals.map((g) => {
                            const Icon = GOAL_ICONS[g.icon] ?? ShoppingBag;
                            const on = form.data.goal === g.key;

                            return (
                                <button
                                    key={g.key}
                                    type="button"
                                    onClick={() => form.setData('goal', g.key)}
                                    className={cn(
                                        'rounded-[14px] border p-5 text-start transition-all',
                                        on
                                            ? 'border-[#111] bg-[#fafafa] ring-1 ring-[#111]'
                                            : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-11 items-center justify-center rounded-[12px]',
                                            on ? 'bg-[#111] text-white' : 'bg-[#f5f5f5] text-[#6b7280]',
                                        )}
                                    >
                                        <Icon className="size-5" />
                                    </span>
                                    <h3 className="mt-4 font-bold text-[#111]">{g.label}</h3>
                                    <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">{g.hint}</p>
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* ===== ٢ · القالب ===== */}
                {step === 1 && (
                    <>
                        <p className="mb-5 text-[13.5px] leading-7 text-[#6b7280]">
                            {t('هذه معاينةٌ حقيقية لموقعك ببياناتك ومنتجاتك. ابدأ بتصميمٍ جاهز — ويمكنك تعديل كلّ شيء بعده.')}
                        </p>

                        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            {templates.map((x) => {
                                const on = form.data.template === x.key;

                                return (
                                    <Card
                                        key={x.key}
                                        className={cn(
                                            'overflow-hidden p-0 transition-all',
                                            on ? 'border-[#111] ring-1 ring-[#111]' : 'border-[var(--ui-border,#e8e8e8)]',
                                        )}
                                    >
                                        <div className="flex items-start gap-3 p-4">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <h3 className="font-bold text-[#111]">{x.label}</h3>
                                                    {on && <Badge variant="success">{t('مختار')}</Badge>}
                                                </div>
                                                <p className="mt-1 text-[12.5px] leading-6 text-[#6b7280]">{x.hint}</p>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => setZoom(x.key)}
                                                className="flex shrink-0 items-center gap-1.5 rounded-[9px] border border-[var(--ui-border,#e8e8e8)] px-2.5 py-2 text-[12px] font-semibold text-[#6b7280] transition-colors hover:border-[#c9c9c9] hover:text-[#111]"
                                            >
                                                <Expand className="size-3.5" />
                                                {t('معاينة')}
                                            </button>
                                        </div>

                                        {/*
                                            والمعاينة تُقصّ ولا تُصغَّر إلى لا شيء: تُرسم
                                            بعرض حاسوبٍ كامل ثمّ تُصغَّر بعرض البطاقة،
                                            فيُرى التركيبُ كلُّه — ترويسةٌ وواجهةٌ وشبكة —
                                            ويُقرأ الفرقُ بين قالبٍ وقالب بالنظر لا بالاسم.
                                        */}
                                        <button
                                            type="button"
                                            onClick={() => form.setData('template', x.key)}
                                            aria-pressed={on}
                                            aria-label={`${t('استخدم تصميم')} ${x.label}`}
                                            className="relative block w-full cursor-pointer border-t border-[var(--ui-border,#e8e8e8)] bg-[#f5f5f5] text-start"
                                        >
                                            <SitePreview doc={docs[x.key]} device="desktop" maxHeight={340} />
                                            <span
                                                aria-hidden
                                                className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-white/95 to-transparent"
                                            />
                                        </button>

                                        <div className="border-t border-[var(--ui-border,#e8e8e8)] p-3">
                                            <Button
                                                type="button"
                                                variant={on ? 'primary' : 'outline'}
                                                className="w-full"
                                                onClick={() => form.setData('template', x.key)}
                                            >
                                                {on ? <Check /> : null}
                                                {t(on ? 'هذا تصميمي' : 'استخدم هذا التصميم')}
                                            </Button>
                                        </div>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* ومعاينةٌ أكبر لمن أراد أن يقرأ لا أن يقارن */}
                        <Dialog open={zoom !== null} onOpenChange={(open) => !open && setZoom(null)}>
                            <DialogContent className="max-w-[min(1180px,95vw)] p-0">
                                {zoom && (
                                    <>
                                        <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] p-4">
                                            <DialogTitle>
                                                {templates.find((x) => x.key === zoom)?.label}
                                            </DialogTitle>

                                            <div className="ms-auto flex items-center gap-1">
                                                {([
                                                    ['desktop', Monitor, 'كمبيوتر'],
                                                    ['mobile', Smartphone, 'جوال'],
                                                ] as const).map(([key, Icon, label]) => (
                                                    <button
                                                        key={key}
                                                        type="button"
                                                        aria-label={t(label)}
                                                        aria-pressed={device === key}
                                                        onClick={() => setDevice(key)}
                                                        className={cn(
                                                            'rounded-[8px] p-2 transition-colors',
                                                            device === key
                                                                ? 'bg-[#111] text-white'
                                                                : 'text-[#9ca3af] hover:text-[#111]',
                                                        )}
                                                    >
                                                        <Icon className="size-4" />
                                                    </button>
                                                ))}
                                            </div>

                                            <Button
                                                type="button"
                                                className="ms-2"
                                                onClick={() => {
                                                    form.setData('template', zoom);
                                                    setZoom(null);
                                                }}
                                            >
                                                {t('استخدم هذا التصميم')}
                                            </Button>
                                        </div>

                                        <div className="max-h-[70dvh] overflow-y-auto bg-[#f5f5f5] p-4">
                                            <SitePreview
                                                doc={docs[zoom]}
                                                device={device}
                                                className={cn(
                                                    'mx-auto border border-[var(--ui-border,#e8e8e8)] bg-white',
                                                    device === 'mobile' ? 'max-w-[390px] rounded-[20px]' : 'rounded-[10px]',
                                                )}
                                            />
                                        </div>
                                    </>
                                )}
                            </DialogContent>
                        </Dialog>
                    </>
                )}

                {/* ===== ٣ · بياناتك ===== */}
                {step === 2 && (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <Card className="p-5 lg:col-span-2">
                            <h3 className="font-bold text-[#111]">{t('هذه بياناتك — عدّل ما تشاء')}</h3>
                            <p className="mt-1 text-[13px] text-[#6b7280]">
                                {t('أخذناها من حسابك، فلا داعي لكتابتها من جديد.')}
                            </p>

                            <div className="mt-5 grid grid-cols-1 gap-4">
                                <Field label="اسم الموقع" required error={form.errors.name}>
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                    />
                                </Field>
                                <Field
                                    label="جملةٌ تصف نشاطك"
                                    hint="تظهر في واجهة موقعك — «أجمل الورود في مسقط»"
                                    error={form.errors.tagline}
                                >
                                    <Input
                                        value={form.data.tagline}
                                        onChange={(e) => form.setData('tagline', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <dl className="mt-6 grid grid-cols-1 gap-3 border-t border-[var(--ui-border,#e8e8e8)] pt-5 text-[13px] sm:grid-cols-2">
                                {[
                                    ['الهاتف', identity.phone],
                                    ['واتساب', identity.whatsapp],
                                    ['البريد', identity.email],
                                    ['العنوان', identity.address],
                                ].map(([label, value]) => (
                                    <div key={label} className="flex items-center justify-between gap-3">
                                        <dt className="text-[#9ca3af]">{t(label)}</dt>
                                        <dd className="truncate text-[#374151]" dir="auto">
                                            {value || <span className="text-[#d1d5db]">{t('غير مضبوط')}</span>}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </Card>

                        <Card className="p-5">
                            <h3 className="font-bold text-[#111]">{t('ما سيدخل موقعك')}</h3>

                            <div className="mt-4 flex items-center gap-3">
                                <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                                    {identity.logo ? (
                                        <img src={identity.logo} alt="" className="size-full object-contain" />
                                    ) : (
                                        <ImageIcon className="size-5 text-[#d1d5db]" />
                                    )}
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-[#111]">{form.data.name || identity.name}</p>
                                    <p className="text-[12px] text-[#9ca3af]">{t('الشعار من بيانات نشاطك')}</p>
                                </div>
                            </div>

                            <ul className="mt-5 space-y-2.5 text-[13px]">
                                {[
                                    ['منتجًا', counts.products],
                                    ['تصنيفًا', counts.categories],
                                    ['تقييمًا منشورًا', counts.reviews],
                                ].map(([label, n]) => (
                                    <li key={label as string} className="flex items-center gap-2 text-[#374151]">
                                        <Check className="size-4 shrink-0 text-[#15803d]" />
                                        {number(n as number)} {t(label as string)}
                                    </li>
                                ))}
                            </ul>

                            {chosen && (
                                <p className="mt-5 flex items-center gap-2 rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] text-[#6b7280]">
                                    <Sparkles className="size-3.5 shrink-0" />
                                    {t('قالب')} «{chosen.label}»
                                </p>
                            )}

                            {!domain.domain && (
                                <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                                    {t('لا نطاق لموقعك بعد — تضبطه بعد الإنشاء، ولا يمنعك ذلك من البناء الآن.')}
                                </p>
                            )}
                        </Card>
                    </div>
                )}

                {/* ===== التنقّل ===== */}
                <div className="mt-8 flex items-center justify-between gap-3">
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={step === 0}
                        onClick={() => setStep((s) => Math.max(0, s - 1))}
                    >
                        <ChevronRight />
                        {t('السابق')}
                    </Button>

                    {step < STEPS.length - 1 ? (
                        <Button type="button" disabled={!canNext} onClick={() => setStep((s) => s + 1)}>
                            {t('التالي')}
                            <ChevronLeft />
                        </Button>
                    ) : (
                        <Button type="submit" loading={form.processing}>
                            <Rocket />
                            {t('أنشئ موقعي')}
                        </Button>
                    )}
                </div>
            </form>
        </AdminLayout>
    );
}
