import { router, useForm } from '@inertiajs/react';
import { Check, MessageCircle, Save, X } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/** ما يصل من الخادم — بلا رمزٍ ولا سرّ (انظر SuperAdmin\WhatsAppController::businessView) */
export interface BusinessWhatsApp {
    enabled: boolean;
    mode: string;
    own_allowed: boolean;
    limit_override: number | null;
    platform_default: number;
    usage: {
        used: number;
        limit: number;
        unlimited: boolean;
        remaining: number | null;
        percentage: number | null;
        credits: number;
        monthly_exhausted: boolean;
        is_exhausted: boolean;
    };
    /** حزمُ الرسائل التي طلبها هذا المتجر — الأحدثُ أوّلًا */
    packs: {
        id: number;
        messages: number;
        amount: number;
        status: string;
        invoice_number: string | null;
        requested_by_name: string | null;
        created_at: string;
    }[];
    pack_offer: { size: number; price: number };
    own_connection: {
        status: string;
        usable: boolean;
        display_phone_number: string | null;
        phone_number_id?: string | null;
    } | null;
}

/**
 * واتساب هذا المتجر — بطاقةٌ في ملفّه لا شاشةٌ جديدة.
 *
 * وما فيها كلّه ممّا لا يملكه التاجر: الحدّ، وصلاحية الرقم الخاص، والتفعيل.
 * ومكانها هنا لأنّ السؤال يُسأل وأنت تنظر إلى المتجر — «كم استهلك؟ أأرفع
 * له؟» — لا في شاشةٍ ثالثة تُفتح بعد أن تبحث عن اسمه فيها من جديد.
 */
export default function WhatsAppCard({ businessId, data }: { businessId: number; data: BusinessWhatsApp }) {
    const t = useTranslate();

    const form = useForm({
        whatsapp_enabled: data.enabled,
        whatsapp_own_allowed: data.own_allowed,
        // فارغٌ يعني «افتراضي المنصّة» — لا صفرًا، والصفر يعني المنع
        whatsapp_monthly_limit: data.limit_override === null ? '' : String(data.limit_override),
    });

    const submit = () => {
        // الفارغ يصل `null` — «افتراضي المنصّة»؛ ولو أُرسل نصًّا فارغًا لَقُرئ صفرًا، وهو المنع
        form.transform((d) => ({
            ...d,
            whatsapp_monthly_limit: d.whatsapp_monthly_limit === '' ? null : Number(d.whatsapp_monthly_limit),
        }));

        form.put(route('super-admin.businesses.whatsapp.update', businessId), { preserveScroll: true });
    };

    return (
        <Card className="mb-6 p-6">
            <h3 className="mb-1 flex items-center gap-2 font-bold text-[#111]">
                <MessageCircle className="size-4" />
                {t('واتساب')}
            </h3>
            <p className="mb-5 text-[13px] text-[#6b7280]">
                {t('الحدّ وصلاحية الرقم الخاص يضبطهما مدير المنصة وحده — لا يراهما التاجر ولا يغيّرهما.')}
            </p>

            <dl className="mb-5 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('وضع الإرسال')}</dt>
                    <dd className="font-medium text-[#111]">
                        {t(data.mode === 'business_own' ? 'رقم المتجر' : 'رقم أبعاد')}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رسائل هذا الشهر')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.usage.unlimited
                            ? String(data.usage.used)
                            : `${data.usage.used} / ${data.usage.limit}`}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('المتبقّي')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.usage.unlimited ? t('بلا حد') : String(data.usage.remaining)}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رصيد مشترًى')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {String(data.usage.credits)}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رقم المتجر الخاص')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.own_connection
                            ? `${data.own_connection.display_phone_number ?? data.own_connection.phone_number_id ?? ''} · ${data.own_connection.usable ? t('يعمل') : t('متوقف')}`
                            : t('غير مربوط')}
                    </dd>
                </div>
            </dl>

            <div className="space-y-5">
                <Toggle
                    on={form.data.whatsapp_enabled}
                    onChange={(v) => form.setData('whatsapp_enabled', v)}
                    label="تفعيل واتساب لهذا المتجر"
                    hint="إطفاؤه يوقف رسائله وحده ولا يمسّ غيره"
                />
                <Toggle
                    on={form.data.whatsapp_own_allowed}
                    onChange={(v) => form.setData('whatsapp_own_allowed', v)}
                    label="صلاحية ربط رقمه الخاص"
                    hint="ممنوحة لكل متجر افتراضًا — وسحبها يوقف الإرسال من رقمه ولا يحذف وصلته"
                />
                <Field
                    label={t('الحد الشهري لهذا المتجر')}
                    hint={`${t('اتركه فارغًا ليأخذ افتراضي المنصة')} (${data.platform_default}) · ${t('و‎-1 تعني بلا حد')}`}
                    error={form.errors.whatsapp_monthly_limit}
                >
                    <Input
                        type="number"
                        dir="ltr"
                        value={form.data.whatsapp_monthly_limit}
                        onChange={(e) => form.setData('whatsapp_monthly_limit', e.target.value)}
                        placeholder={String(data.platform_default)}
                    />
                </Field>
            </div>

            <div className="mt-5 flex justify-end">
                <Button type="button" loading={form.processing} onClick={submit}>
                    <Save />
                    {t('حفظ')}
                </Button>
            </div>

            {/*
                ═══ حزمُ الرسائل — وما ينتظر فعلًا منها ═══

                طلبٌ `مطلوبة` ينتظر ضغطةً من هنا: تُصدَر فاتورتُه. وما بعدها
                ينتظر مالًا لا ضغطة — فالسدادُ يُسجَّل في شاشة الفواتير،
                والرصيدُ يُضاف وحدَه عندها (انظر `Billing::markPaid`).

                ولا يُضاف رصيدٌ من هنا بزرّ: لو أُضيف لَصار الاعتمادُ هديّةً
                تُعطى قبل أن يُدفع شيء.
            */}
            {data.packs.length > 0 && (
                <div className="mt-6 border-t border-[#f0f0f0] pt-5">
                    <h4 className="mb-3 text-[13px] font-bold text-[#111]">
                        {t('حزم الرسائل')}
                        <span className="me-2 font-normal text-[#6b7280]" dir="ltr">
                            {' '}
                            ({data.pack_offer.size} · {data.pack_offer.price.toFixed(3)})
                        </span>
                    </h4>

                    <ul className="space-y-2">
                        {data.packs.map((p) => (
                            <li
                                key={p.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] bg-[#fafafa] px-3 py-2 text-[13px]"
                            >
                                <span className="text-[#111]">
                                    <span dir="ltr">
                                        {p.messages} · {p.amount.toFixed(3)}
                                    </span>
                                    <span className="me-2 text-[#6b7280]">
                                        {' '}
                                        {p.status}
                                        {p.invoice_number ? ` · ${p.invoice_number}` : ''}
                                    </span>
                                </span>

                                <span className="flex items-center gap-2">
                                    <span className="text-[12px] text-[#9ca3af]" dir="ltr">
                                        {p.created_at}
                                    </span>
                                    {p.status === 'مطلوبة' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    route('super-admin.whatsapp.packs.approve', p.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Check />
                                            {t('أصدر الفاتورة')}
                                        </Button>
                                    )}
                                    {(p.status === 'مطلوبة' || p.status === 'مصدَّرة') && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(route('super-admin.whatsapp.packs.cancel', p.id), {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <X />
                                            {t('إلغاء')}
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}
