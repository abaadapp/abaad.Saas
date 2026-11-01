import { useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, Save, ShoppingBag } from 'lucide-react';
import StatusPill from '@/Components/StatusPill';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import Toggle from '@/Components/Toggle';
import { useTranslate } from '@/lib/i18n';

/** ما يُعرض من الطلبات الآليّة — `WhatsAppOrders::view` */
export interface GuidedOrdersView {
    enabled: boolean;
    active: boolean;
    checks: {
        number: boolean;
        inbox: boolean;
        preparation: boolean;
        payment_links: boolean;
    };
}

interface Props {
    view: GuidedOrdersView;
    mayManage: boolean;
}

/** شروطُ العمل بأسمائها — بترتيب `WhatsAppOrders::readiness` */
const CHECKS: { key: keyof GuidedOrdersView['checks']; label: string }[] = [
    { key: 'number', label: 'رقم متجرك مربوط ويُرسل، وفوترة ميتا مراجَعة' },
    { key: 'inbox', label: 'صندوق محادثات واتساب متاح لمتجرك' },
    { key: 'preparation', label: 'تجهيز الفواتير بعد السداد مفعّل (يفتحه فريق أبعاد)' },
    { key: 'payment_links', label: 'روابط الدفع من Paymob جاهزة' },
];

/**
 * «الطلبات الآليّة عبر واتساب» — مفتاحٌ مستقلٌّ عن الردّ التلقائيّ.
 *
 * يكتب الزبونُ «طلب» إلى رقم المتجر، فيختار من الكتالوج ويُكمل التسليم، ثمّ
 * يصله رابطُ الدفع؛ وبعد الدفع تظهر المهمّةُ في لوحة التجهيز.
 *
 * والحالُ من الخادم لا من المقبض: «يعمل» حين تكتمل الشروطُ كلُّها، و«بانتظار»
 * حين يُشعَل وينقص شرط — والناقصُ مسمًّى.
 */
export default function WhatsappGuidedOrders({ view, mayManage }: Props) {
    const t = useTranslate();
    const form = useForm({ enabled: view.enabled });

    return (
        <SettingsSection
            title="الطلبات الآلية عبر واتساب"
            description="يطلب الزبون من المحادثة خطوة بخطوة، ويصله رابط الدفع من رقم متجرك، وبعد الدفع يظهر الطلب في لوحة التجهيز."
            icon={ShoppingBag}
            status={
                ! view.enabled
                    ? <StatusPill state="off" label="متوقف" />
                    : view.active
                      ? <StatusPill state="ready" label="يعمل" />
                      : <StatusPill state="progress" label="بانتظار اكتمال الشروط" />
            }
        >
            <div className="space-y-4" data-testid="guided-orders">
                <Toggle
                    label="تشغيل الطلبات الآلية"
                    hint="مستقل عن الرد التلقائي — ويمكنك إيقافه متى شئت. الطلبات الخاصة تُحوَّل إلى صندوق المحادثات."
                    on={form.data.enabled}
                    onChange={(v) => mayManage && form.setData('enabled', v)}
                />

                <ul className="space-y-1.5" data-testid="guided-orders-checks">
                    {CHECKS.map((c) => (
                        <li key={c.key} className="flex items-start gap-2 text-[12.5px] leading-relaxed" data-ok={view.checks[c.key] ? '1' : '0'}>
                            {view.checks[c.key]
                                ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#16a34a]" />
                                : <CircleAlert className="mt-0.5 size-4 shrink-0 text-[#d97706]" />}
                            <span className={view.checks[c.key] ? 'text-[#374151]' : 'text-[#b45309]'}>{t(c.label)}</span>
                        </li>
                    ))}
                </ul>

                <p className="text-[12px] leading-relaxed text-[#9ca3af]">
                    {t('الأسعار والتوافر والإضافات والمواعيد تُقرأ من متجرك نفسه، ولا يُصدَر رابط دفع قبل موافقة الزبون على الملخّص.')}
                </p>

                {mayManage && (
                    <PageActions>
                        <Button
                            type="button"
                            loading={form.processing}
                            onClick={() => form.post(route('admin.integrations.whatsapp.guidedOrders'), { preserveScroll: true })}
                            data-testid="guided-orders-save"
                        >
                            <Save />
                            {t('حفظ')}
                        </Button>
                    </PageActions>
                )}
            </div>
        </SettingsSection>
    );
}
