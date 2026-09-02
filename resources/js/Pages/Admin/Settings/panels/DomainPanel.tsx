import { type FormEvent, useState } from 'react';
import { type InertiaFormProps, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Clock,
    ExternalLink,
    Globe,
    Link2,
    Save,
    ShoppingCart,
    Sparkles,
    X,
} from 'lucide-react';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** طلبُ تجهيزٍ كما يرسله الخادم — آخر طلبٍ وحده */
export interface DomainRequestView {
    id: number;
    domain: string;
    note: string | null;
    status: string;
}

export interface DomainData {
    /** '' تعني لم يختر بعد — فتُعرض البطاقات الثلاث */
    mode: '' | 'own' | 'subdomain' | 'new';
    /** لاحقة النطاقات الفرعية كما ضبطها المشغّل */
    suffix: string;
    /** الاسم المحجوز وحده بلا لاحقة — '' إن لم يُحجز شيء */
    subdomain: string;
    /** ما يدفعه لأبعاد — وnull تعني «لم يُسعَّر بعد» */
    pricing: { own: number | null; subdomain: number | null; setup: number | null };
    request: DomainRequestView | null;
}

/*
 * السعر بثلاث منازل و«ر.ع» — كما تعرضه شاشة انتهاء الاشتراك.
 *
 * ولا يمرّ بـ`money()`: تلك تضرب في سعر صرف عملة العرض التي اختارها التاجر
 * لمتجره، وهذا سعرُ المنصّة لا سعرُ متجره. فلو مرّ بها لرأى تاجرٌ بدّل عرضه
 * إلى الدولار رسومَ تجهيزٍ محوّلةً بسعر صرفٍ ضبطه هو لبضاعته.
 */
function priceLine(t: (s: string, r?: Record<string, string | number>) => string, value: number | null): string {
    if (value === null) return t('يُحدَّد بالتواصل');
    if (value === 0) return t('مجاني');

    return `${number(value, 3)} ${t('ر.ع')}`;
}

interface CardSpec {
    mode: 'own' | 'subdomain' | 'new';
    icon: typeof Globe;
    title: string;
    desc: string;
    /** سطر التكلفة الكبير */
    price: string;
    /** ما تعنيه التكلفة فعلًا — الفرق بين «لا تدفع لأحد» و«لا تدفع لنا» */
    priceNote: string;
}

/**
 * مجموعة `website` — حقلٌ واحد.
 *
 * كانت ثمانية، ورُفعت السبعة لأنّها تصف واجهة متجرٍ لا وجود لها في النظام:
 * «نشر الموقع» و«عرض الأسعار» ونبذةٌ وجملةٌ تعريفية. وبقي النطاق وحده لأنّه
 * وحده يُقرأ.
 */
export interface SiteFormData {
    site_domain: string;
}

interface Props {
    domain: DomainData;
    /** نموذج الموقع — النطاق حقلُه الوحيد */
    siteForm: InertiaFormProps<SiteFormData>;
    /** حفظ النطاق — المسار نفسه الذي تستعمله بطاقة الدومين الأصلية */
    onSaveSite: (e: FormEvent) => void;
}

/**
 * إعدادات الدومين — سؤالٌ قبل حقل.
 *
 * كانت حقلًا واحدًا: «اكتب النطاق». وهو يفترض في التاجر أنّه يملك نطاقًا،
 * وأنّه يعرف ما النطاق ومن أين يُشترى وبكم. ومن لا يملك واحدًا — وهم أكثر من
 * يفتح الشاشة أوّل مرّة — يقف أمام حقلٍ فارغ لا يقول له ماذا يفعل، فيتركه
 * ويبقى متجره بلا عنوان.
 *
 * فصار أوّل ما يُعرض ثلاثَ بطاقات، ولكلٍّ تكلفتُها مكتوبةً قبل المتابعة لا
 * بعدها. والاختيار لا يُقفل: «تغيير الطريقة» يعيد البطاقات، ولا يُمحى بالعودة
 * نطاقٌ كُتب ولا اسمٌ حُجز.
 */
export default function DomainPanel({ domain, siteForm, onSaveSite }: Props) {
    const t = useTranslate();
    const { mode, suffix, subdomain, pricing, request } = domain;

    const CARDS: CardSpec[] = [
        {
            mode: 'own',
            icon: Link2,
            title: 'أملك نطاقًا وأريد ربطه',
            desc: 'اشتريتَ نطاقك من مزوّدٍ (مثل GoDaddy أو Namecheap) وتريد أن يظهر على فواتيرك وفي نتائج البحث.',
            price: priceLine(t, pricing.own),
            priceNote: 'لا تدفع لأبعاد شيئًا مقابل الربط. وتجديد النطاق السنوي تدفعه لمزوّدك كما تدفعه اليوم.',
        },
        {
            mode: 'new',
            icon: ShoppingCart,
            title: 'أريد نطاقًا جديدًا تجهّزه أبعاد',
            desc: 'لا تملك نطاقًا ولا تريد أن تتعامل مع مزوّد. تكتب الاسم الذي تريده ونشتريه ونضبطه لك.',
            price: priceLine(t, pricing.setup),
            priceNote: 'رسوم تجهيزٍ لمرّة واحدة تُدفع لأبعاد. أمّا ثمن النطاق نفسه وتجديده السنوي فيتبع سعر المزوّد ونُبلغك به قبل الشراء.',
        },
        {
            mode: 'subdomain',
            icon: Sparkles,
            title: 'نطاق فرعي تابع لأبعاد',
            desc: 'عنوانٌ تحت نطاق أبعاد — مثل my-store.:suffix. بلا شراءٍ ولا مزوّد ولا تجديد سنوي.',
            price: priceLine(t, pricing.subdomain),
            priceNote: 'يُحجز الاسم لك الآن. والاستضافة قيد التجهيز — سنُبلغك حين يصير العنوان جاهزًا للفتح.',
        },
    ];

    /* ------------------------------- الاختيار ------------------------------- */

    const [choosing, setChoosing] = useState<CardSpec['mode'] | null>(null);

    const pickMode = (next: '' | CardSpec['mode']) => {
        setChoosing(next === '' ? null : next);
        router.post(
            route('admin.settings.domain.mode'),
            { site_domain_mode: next },
            { preserveScroll: true, onFinish: () => setChoosing(null) },
        );
    };

    /* --------------------------- النطاق الفرعي --------------------------- */

    const subForm = useForm({ site_subdomain: subdomain });

    const saveSubdomain = (e: FormEvent) => {
        e.preventDefault();
        subForm.post(route('admin.settings.domain.subdomain'), { preserveScroll: true });
    };

    /* ----------------------------- طلب التجهيز ----------------------------- */

    const reqForm = useForm({ domain: '', note: '' });

    const sendRequest = (e: FormEvent) => {
        e.preventDefault();
        reqForm.post(route('admin.settings.domain.request'), {
            preserveScroll: true,
            onSuccess: () => reqForm.reset(),
        });
    };

    const header = (
        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 className="font-bold text-[#111]">{t('إعدادات الدومين')}</h3>
                <p className="mt-1 text-[13px] text-[#6b7280]">
                    {/*
                        وعدٌ يُقال بقدر ما يقع.
                        كانت الجملة تقول إنّ النطاق «يُكتب في الفاتورة ويقرؤه
                        محرّك البحث»، وليس واحدٌ منهما واقعًا: لا قالبُ فاتورةٍ
                        يقرأ النطاق، ولا صفحةَ عامّةٍ في النظام يقرؤها محرّك بحث.
                        فالتاجر يضبطه منتظرًا أثرًا لا يأتي، ولا يعرف لماذا.
                    */}
                    {t('موقعك أو صفحتك خارج النظام — يصير زرًّا في الشريط يفتحه، ويُكتب في الفاتورة.')}
                </p>
            </div>
            {mode !== '' && (
                /* بابُ الرجوع مفتوحٌ دائمًا: من اختار الفرعيّ ثمّ اشترى نطاقه
                   يجب أن يجد ما يبدّل به، لا شاشةً أقفلها اختيارُ يومٍ مضى */
                <Button variant="ghost" size="sm" type="button" onClick={() => pickMode('')}>
                    <ArrowRight />
                    {t('تغيير الطريقة')}
                </Button>
            )}
        </div>
    );

    /* ------------------------ أوّل مرّة: البطاقات الثلاث ------------------------ */

    if (mode === '') {
        return (
            <Card className="p-6">
                {header}

                <p className="mb-5 text-[13px] leading-relaxed text-[#4b4b4b]">
                    {t('اختر كيف تريد أن يصل زبائنك إلى متجرك. تكلفةُ كلّ طريقةٍ مكتوبةٌ تحتها، ويمكنك تغيير اختيارك لاحقًا.')}
                </p>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {CARDS.map((c) => (
                        <div
                            key={c.mode}
                            className="flex flex-col rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 transition hover:border-[#d4d4d4]"
                        >
                            <span className="mb-4 flex size-[52px] shrink-0 items-center justify-center rounded-[14px] bg-[#f5f5f4] text-[#111]">
                                <c.icon className="size-6" />
                            </span>

                            <h4 className="text-[15px] font-semibold text-[#111]">{t(c.title)}</h4>
                            <p className="mt-1.5 text-[13px] leading-relaxed text-[#6b7280]">{t(c.desc, { suffix })}</p>

                            {/*
                                السعر لا يُطوى ولا يُصغَّر: هو ما جاءت البطاقات
                                لأجله. ويُفصل بخطٍّ عمّا فوقه فلا يُقرأ سطرًا
                                في الوصف، ومعه سطرٌ يقول ما تعنيه التكلفة —
                                «مجاني» وحدها تُقرأ «لا تدفع لأحد» وهي تعني
                                «لا تدفع لنا».
                            */}
                            <div className="mt-4 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                                <p className="text-[15px] font-bold text-[#111]">{c.price}</p>
                                <p className="mt-1.5 text-[12px] leading-relaxed text-[#9ca3af]">{t(c.priceNote)}</p>
                            </div>

                            <div className="mt-4 flex-1" />

                            <Button
                                type="button"
                                className="w-full"
                                loading={choosing === c.mode}
                                onClick={() => pickMode(c.mode)}
                            >
                                {t('متابعة')}
                            </Button>
                        </div>
                    ))}
                </div>
            </Card>
        );
    }

    /* ---------------------------- نطاقٌ يملكه ---------------------------- */

    if (mode === 'own') {
        return (
            <form onSubmit={onSaveSite}>
                <Card className="p-6">
                    {header}

                    <Field
                        label="النطاق"
                        hint="اكتب النطاق وحده بلا https:// — مثل: mystore.om"
                        error={siteForm.errors.site_domain}
                    >
                        <Input
                            dir="ltr"
                            value={siteForm.data.site_domain}
                            onChange={(e) => siteForm.setData('site_domain', e.target.value)}
                            placeholder="mystore.om"
                        />
                    </Field>

                    {/*
                        وربطُ النطاق ليس كتابته.
                        
                        الحقل يقول للنظام ما عنوانك؛ أمّا أن يصل الزائر فيحتاج
                        سجلًّا في لوحة مزوّدك. ومن يكتب النطاق ويحفظ ثمّ يفتحه
                        فلا يجده يظنّ العطب فينا.
                    */}
                    <p className="mt-4 rounded-[12px] bg-[#eff6ff] px-4 py-3 text-[12px] leading-relaxed text-[#1d4ed8]">
                        {t('كتابةُ النطاق هنا تجعل النظام يعرفه ويكتبه في فواتيرك. أمّا توجيهُ النطاق إلى موقعك فيتمّ من لوحة مزوّد النطاق — وإن لم يكن لديك موقعٌ بعد فتواصل معنا.')}
                    </p>

                    <div className="mt-6 flex flex-wrap items-center justify-end gap-2">
                        {!!siteForm.data.site_domain && (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`https://${siteForm.data.site_domain}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink />
                                    {t('فتح الموقع')}
                                </a>
                            </Button>
                        )}
                        <Button type="submit" loading={siteForm.processing}>
                            <Save />
                            {t('حفظ التغييرات')}
                        </Button>
                    </div>
                </Card>
            </form>
        );
    }

    /* -------------------------- نطاقٌ فرعيّ لأبعاد -------------------------- */

    if (mode === 'subdomain') {
        const reserved = subdomain;

        return (
            <Card className="p-6">
                {header}

                {reserved !== '' && (
                    /*
                        المحجوز يُعرض ولا يُفتح — ولا زرّ «فتح الموقع» عليه.
                        
                        لا شيء على الخادم يقدّم هذا العنوان بعد، ورابطٌ يقود
                        إلى لا شيء أسوأ من غيابه: من يضغطه يظنّ متجره معطوبًا.
                    */
                    <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-[12px] bg-[#f5f5f4] px-4 py-3">
                        <span className="font-mono text-[14px] text-[#111]" dir="ltr">
                            {reserved}.{suffix}
                        </span>
                        <Badge status="قيد التجهيز">
                            <Clock className="size-3.5" />
                            {t('محجوز — قيد التجهيز')}
                        </Badge>
                    </div>
                )}

                <form onSubmit={saveSubdomain}>
                    <Field
                        label="اسم النطاق الفرعي"
                        hint="حروف لاتينية صغيرة وأرقام وشرطة بينها — مثل: my-store"
                        error={subForm.errors.site_subdomain}
                    >
                        <div className="flex items-center gap-2" dir="ltr">
                            <Input
                                dir="ltr"
                                value={subForm.data.site_subdomain}
                                onChange={(e) => subForm.setData('site_subdomain', e.target.value.toLowerCase())}
                                placeholder="my-store"
                                className="max-w-[240px]"
                            />
                            <span className="shrink-0 font-mono text-[14px] text-[#6b7280]">.{suffix}</span>
                        </div>
                    </Field>

                    <p className="mt-4 rounded-[12px] bg-[#eff6ff] px-4 py-3 text-[12px] leading-relaxed text-[#1d4ed8]">
                        {t('نحجز الاسم لك الآن فلا يأخذه غيرك. والاستضافة قيد التجهيز — سنُبلغك حين يصير العنوان جاهزًا للفتح، ولن يعمل الرابط قبل ذلك.')}
                    </p>

                    <div className="mt-6 flex items-center justify-between gap-3">
                        <p className="text-[12px] text-[#9ca3af]">{priceLine(t, pricing.subdomain)}</p>
                        <Button type="submit" loading={subForm.processing}>
                            <Save />
                            {reserved === '' ? t('احجز الاسم') : t('تغيير الاسم')}
                        </Button>
                    </div>
                </form>
            </Card>
        );
    }

    /* ---------------------- نطاقٌ جديد تجهّزه أبعاد ---------------------- */

    const pending = request?.status === 'معلّق';

    return (
        <Card className="p-6">
            {header}

            {request && (
                <div
                    className={cn(
                        'mb-5 rounded-[12px] px-4 py-3',
                        pending ? 'bg-[#fffbeb]' : request.status === 'مكتمل' ? 'bg-[#ecfdf5]' : 'bg-[#fef2f2]',
                    )}
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <span className="flex items-center gap-2">
                            {pending ? (
                                <Clock className="size-4 shrink-0 text-[#d97706]" />
                            ) : request.status === 'مكتمل' ? (
                                <CheckCircle2 className="size-4 shrink-0 text-[#047857]" />
                            ) : (
                                <AlertTriangle className="size-4 shrink-0 text-[#b91c1c]" />
                            )}
                            <span className="font-mono text-[14px] text-[#111]" dir="ltr">
                                {request.domain}
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <Badge status={request.status} />
                            {pending && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    type="button"
                                    onClick={() =>
                                        router.delete(route('admin.settings.domain.request.cancel', request.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <X />
                                    {t('سحب الطلب')}
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* ردّ المشغّل يُقرأ هنا: «مرفوض» بلا سببٍ تجعل صاحبها
                        يعيد الطلب نفسه فيُرفض بالصمت نفسه */}
                    {request.note && (
                        <p className="mt-2 text-[12px] leading-relaxed text-[#4b4b4b]">{request.note}</p>
                    )}
                </div>
            )}

            {pending ? (
                <p className="text-[13px] leading-relaxed text-[#6b7280]">
                    {t('طلبك عندنا وسنتواصل معك قريبًا. ولن يُشترى شيءٌ قبل أن نُبلغك بالسعر النهائي وتوافق عليه.')}
                </p>
            ) : (
                <form onSubmit={sendRequest}>
                    <Field
                        label="النطاق الذي تريده"
                        hint="اكتب النطاق وحده بلا https:// — مثل: mystore.om"
                        error={reqForm.errors.domain}
                    >
                        <Input
                            dir="ltr"
                            value={reqForm.data.domain}
                            onChange={(e) => reqForm.setData('domain', e.target.value)}
                            placeholder="mystore.om"
                        />
                    </Field>

                    <Field
                        className="mt-4"
                        label="ملاحظة (اختياري)"
                        hint="أسماءٌ بديلة تقبلها، أو أيّ شيء تريد أن نعرفه قبل الشراء."
                        error={reqForm.errors.note}
                    >
                        <Textarea
                            rows={3}
                            value={reqForm.data.note}
                            onChange={(e) => reqForm.setData('note', e.target.value)}
                        />
                    </Field>

                    <div className="mt-4 rounded-[12px] bg-[#f5f5f4] px-4 py-3">
                        <p className="text-[13px] font-semibold text-[#111]">
                            {t('رسوم التجهيز:')} {priceLine(t, pricing.setup)}
                        </p>
                        <p className="mt-1.5 text-[12px] leading-relaxed text-[#6b7280]">
                            {t('تُدفع لأبعاد مرّةً واحدة. أمّا ثمن النطاق نفسه وتجديده السنوي فيتبع سعر المزوّد — نُبلغك به قبل الشراء ولا نشتري قبل موافقتك.')}
                        </p>
                    </div>

                    <div className="mt-6 flex justify-end">
                        <Button type="submit" loading={reqForm.processing}>
                            <ShoppingCart />
                            {t('أرسل الطلب')}
                        </Button>
                    </div>
                </form>
            )}
        </Card>
    );
}
