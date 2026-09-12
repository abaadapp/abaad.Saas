import { useForm, usePage } from '@inertiajs/react';
import { Check, Rocket } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SitePreview from './preview/SitePreview';
import type { SiteDocument } from './preview/types';
import { type DomainState, domainHost } from './shell';

interface TemplateProposal {
    key: string;
    label: string;
    hint: string;
    /** الموقع كما سيُبنى بهذا القالب — ببيانات التاجر ومنتجاته */
    document: SiteDocument;
}

interface Props {
    goal: string;
    templates: TemplateProposal[];
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
    counts: { products: number; categories: number; reviews: number };
    domain: DomainState;
}

/** كم يُعرض من أعلى الصفحة في البطاقة — يكفي لتُقرأ الهيئة ولا يطول */
const CARD_HEIGHT = 300;

/**
 * إنشاء الموقع — قرارٌ واحد.
 *
 * ═══ ما كان ═══
 *
 * ثلاثُ خطوات: «ماذا تريد من موقعك؟» ثمّ «اختر شكلًا» ثمّ «أكّد بياناتك».
 * والأولى تسأل صاحبَ المتجر عن بنيةٍ لا يعرفها، والثالثة تعرض عليه بياناتٍ
 * أدخلها بنفسه يوم فُتح حسابه ليضغط «التالي». والوسطى — وهي القرار الحقيقيّ
 * — كانت ستّةَ مربّعاتِ ألوان لا يُخرج منها قرارًا.
 *
 * ═══ ما صار ═══
 *
 * ثلاثةُ قوالب، وكلٌّ منها **متجرُه هو** مرسومًا: باسمه وشعاره ومنتجاته
 * وأسعارها (انظر `Builder::proposal`). يمسحها بعينه، يضغط ما يعجبه، ويدخل
 * المحرّر. والوجهةُ تُستنتج ولا تُسأل: من يفتح موقعًا من نظامٍ لإدارة متجرٍ
 * يريد متجرًا — ويبدّلها من «المتجر» بعد الإنشاء إن شاء.
 *
 * وبياناتُه تُعرض ولا تُطلب: سطرٌ يقول ما سيدخل موقعه، لا استمارةٌ يملؤها.
 */
export default function Wizard() {
    const { templates, identity, counts, domain } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /* وأوّلُها مختارٌ سلفًا: زرٌّ معطَّل في شاشةٍ من قرارٍ واحد بابٌ مغلق بلا سبب */
    const form = useForm({ template: templates[0]?.key ?? '' });

    return (
        <AdminLayout title="إنشاء الموقع">
            <PageHeader
                title="اختر شكل موقعك"
                subtitle={t('هذا متجرك بثلاثة أشكال — اضغط ما يعجبك، وعدّل كل شيء بعدها.')}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {templates.map((x) => {
                    const on = form.data.template === x.key;

                    return (
                        <button
                            key={x.key}
                            type="button"
                            onClick={() => form.setData('template', x.key)}
                            aria-pressed={on}
                            className={cn(
                                'overflow-hidden rounded-[14px] border bg-white text-start transition-all',
                                on
                                    ? 'border-[#111] ring-1 ring-[#111]'
                                    : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                            )}
                        >
                            {/*
                                ولا صورةَ قالبٍ جاهزة: الصورة تكذب — تعرض متجرًا
                                ليس متجرَه، وبمنتجاتٍ ليست منتجاته. وهذه معاينةُ
                                المستند نفسِه بطبقة الرسم نفسها.
                            */}
                            <span className="block bg-[#f4f4f5] p-3">
                                <SitePreview
                                    doc={x.document}
                                    device="desktop"
                                    clip={CARD_HEIGHT}
                                    className="rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white"
                                />
                            </span>

                            <span className="flex items-start justify-between gap-2 border-t border-[var(--ui-border,#e8e8e8)] p-4">
                                <span className="min-w-0">
                                    <span className="block font-bold text-[#111]">{x.label}</span>
                                    <span className="mt-0.5 block text-[12px] leading-6 text-[#6b7280]">
                                        {x.hint}
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors',
                                        on
                                            ? 'border-[#111] bg-[#111] text-white'
                                            : 'border-[#d1d5db] text-transparent',
                                    )}
                                    aria-hidden
                                >
                                    <Check className="size-3" />
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-4">
                <p className="text-[13px] leading-7 text-[#6b7280]">
                    {t('سيُبنى موقعك من بياناتك:')}{' '}
                    <span className="font-medium text-[#374151]">
                        {[
                            identity.name,
                            `${number(counts.products)} ${t('منتجًا')}`,
                            `${number(counts.categories)} ${t('تصنيفًا')}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                    {!domainHost(domain) && (
                        <span className="block text-[12px] text-[#9ca3af]">
                            {t('والنطاق تضبطه بعد الإنشاء — لا يمنعك ذلك من البناء الآن.')}
                        </span>
                    )}
                </p>

                <Button
                    size="lg"
                    loading={form.processing}
                    onClick={() => form.post(route('admin.website.create'))}
                >
                    <Rocket />
                    {t('استخدم هذا القالب')}
                </Button>
            </div>
        </AdminLayout>
    );
}
