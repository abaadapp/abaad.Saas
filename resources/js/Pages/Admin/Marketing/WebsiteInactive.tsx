import { usePage } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /** '' | 'own' | 'subdomain' | 'new' — الطريق الذي اختاره التاجر */
    mode: string;
    /** العنوان الفرعي المحجوز كاملًا، أو null */
    subdomain: string | null;
    /** آخر طلب تجهيزٍ إن وُجد */
    request: { domain: string; note: string | null; status: string } | null;
}

/**
 * زرّ «الموقع الإلكتروني» حين لا عنوان يُفتح.
 *
 * كان الزرّ يقذف صاحبه في نموذج الإعدادات بلا مقدّمة: من ضغطه ليرى متجره
 * يجد حقولًا لا يعرف لماذا فُتحت له.
 *
 * وأحوالُ «لا عنوان» خمسة لا واحد — ولكلٍّ خطوةٌ غير خطوة الآخر. فمن حجز
 * اسمًا فرعيًّا ينتظر الاستضافة لا يُقال له «اضبط نطاقك»، ومن طلب من أبعاد
 * وينتظر لا يُدفع إلى نموذجٍ يملؤه من جديد.
 */
export default function WebsiteInactive() {
    const { mode, subdomain, request, auth } = usePage<PageProps<Props>>().props;
    const canSettings = auth?.abilities.includes('settings') ?? false;
    const t = useTranslate();

    const pending = request?.status === 'معلّق';
    const rejected = request?.status === 'مرفوض';

    const { body, cta } = (() => {
        if (mode === 'subdomain' && subdomain) {
            return {
                body: t('حجزتَ العنوان أدناه، والاستضافة قيد التجهيز — سنُبلغك حين يصير جاهزًا للزوّار.'),
                cta: t('مراجعة إعدادات الدومين'),
            };
        }
        if (pending) {
            return {
                body: t('طلبك على :domain وصلنا وهو قيد المعالجة — سنتواصل معك قريبًا.', {
                    domain: request!.domain,
                }),
                cta: t('متابعة الطلب'),
            };
        }
        if (rejected) {
            return {
                body: t('تعذّر تجهيز :domain. السبب مكتوبٌ في شاشة الدومين، ويمكنك طلب عنوانٍ آخر.', {
                    domain: request!.domain,
                }),
                cta: t('اقرأ السبب واطلب غيره'),
            };
        }
        if (mode === 'own') {
            return {
                body: t('اخترتَ أن تربط نطاقك الخاصّ ولم تكتبه بعد. اكتبه ليفتح هذا الزرّ متجرك.'),
                cta: t('اكتب النطاق'),
            };
        }
        return {
            body: t('لم تختر بعد كيف يصل زبائنك إلى متجرك. ثلاث طرقٍ أمامك، وتكلفةُ كلٍّ منها مكتوبةٌ قبل أن تختار.'),
            cta: t('اختر طريقتك'),
        };
    })();

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <PageHeader title="الموقع الإلكتروني" subtitle={t('لا عنوان يُفتح بعد')} />

            <Card className="p-8 text-center sm:p-12">
                <span className="mx-auto flex size-14 items-center justify-center rounded-[12px] bg-[#f5f3ff] text-[#6d28d9]">
                    <Globe className="size-7" />
                </span>

                <h2 className="mt-5 text-[18px] font-bold text-[#111]">
                    {t('متجرك بلا عنوانٍ على الإنترنت بعد')}
                </h2>

                <p className="mx-auto mt-3 max-w-md text-[13px] leading-relaxed text-[#6b7280]">{body}</p>

                {/* العنوان المحجوز يُعرض ولا يُجعل رابطًا: لا شيء يردّ عليه بعد */}
                {mode === 'subdomain' && subdomain && (
                    <p className="mt-3 font-mono text-[13px] text-[#9ca3af]" dir="ltr">
                        {subdomain}
                    </p>
                )}

                <div className="mt-7">
                    {canSettings ? (
                        <Button asChild>
                            <SmartLink
                                routeName="admin.settings.index"
                                href={route('admin.settings.index', { section: 'domain' })}
                            >
                                <Globe />
                                {cta}
                            </SmartLink>
                        </Button>
                    ) : (
                        /*
                            العنوان يُضبط من «الإعدادات»، وهذه الصفحة يراها من
                            يملك «التسويق». فمن لا يملكها يُقال له بمن يتّصل —
                            وزرٌّ يقود إلى ٤٠٣ أسوأ من جملةٍ تشرح.
                        */
                        <p className="text-[13px] font-medium text-[#6b7280]">
                            {t('يضبطه صاحب النشاط من الإعدادات ‹ إعدادات الدومين.')}
                        </p>
                    )}
                </div>
            </Card>
        </AdminLayout>
    );
}
