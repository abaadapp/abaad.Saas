import { useForm, usePage } from '@inertiajs/react';
import { MessageCircle, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Toggle from '@/Components/Toggle';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    settings: Record<string, string>;
    storeName: string | null;
    /** ما يقبله القالب من متغيّرات — تُعرض للتاجر بدل أن يخمّنها */
    variables: Record<string, string>;
}

const TEMPLATES = [
    { key: 'wa_template_order', toggle: 'wa_on_order', label: 'عند استلام الطلب' },
    { key: 'wa_template_ready', toggle: 'wa_on_ready', label: 'عند جاهزية الطلب' },
    { key: 'wa_template_delivered', toggle: 'wa_on_delivered', label: 'عند التسليم' },
] as const;

export default function Whatsapp() {
    const { settings, storeName, variables } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm<Record<string, string | boolean>>({
        wa_enabled: settings.wa_enabled === '1',
        wa_number: settings.wa_number ?? '',
        wa_on_order: settings.wa_on_order === '1',
        wa_on_ready: settings.wa_on_ready === '1',
        wa_on_delivered: settings.wa_on_delivered === '1',
        wa_template_order: settings.wa_template_order ?? '',
        wa_template_ready: settings.wa_template_ready ?? '',
        wa_template_delivered: settings.wa_template_delivered ?? '',
    });

    /** المعاينة بقيمٍ حقيقية: قالبٌ بمتغيّراته الخام لا يُقرأ رسالةً */
    const preview = (template: string) =>
        template
            .replace(':store', storeName ?? t('متجري'))
            .replace(':number', 'ORD-1042')
            .replace(':total', '12.500')
            .replace(':customer', t('أحمد'));

    return (
        <AdminLayout title="إشعارات واتساب">
            <PageHeader
                title="إشعارات واتساب"
                subtitle={t('رسائل تُرسَل للعميل عند تغيّر حال طلبه')}
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.marketing.whatsapp.save'), { preserveScroll: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <Card className="p-6">
                    <div className="mb-5">
                        <Toggle
                            label="تفعيل الإشعارات"
                            hint="الرسائل تُفتح من رقم المتجر على واتساب."
                            on={form.data.wa_enabled as boolean}
                            onChange={(v) => form.setData('wa_enabled', v)}
                        />
                    </div>

                    <Field
                        label="رقم المتجر على واتساب"
                        hint="بصيغة دولية بلا + ولا مسافات — مثل: 96890000000"
                        error={form.errors.wa_number}
                    >
                        <Input
                            dir="ltr"
                            value={form.data.wa_number as string}
                            onChange={(e) => form.setData('wa_number', e.target.value)}
                            placeholder="96890000000"
                        />
                    </Field>

                    {(form.data.wa_enabled as boolean) && !(form.data.wa_number as string) && (
                        <p className="mt-3 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                            {t('الإشعارات مفعّلة بلا رقم — لن تُرسَل رسالة واحدة.')}
                        </p>
                    )}
                </Card>

                {TEMPLATES.map((tpl) => (
                    <Card key={tpl.key} className="p-6">
                        <div className="mb-4">
                            <Toggle
                                label={tpl.label}
                                on={form.data[tpl.toggle] as boolean}
                                onChange={(v) => form.setData(tpl.toggle, v)}
                            />
                        </div>

                        <Field error={form.errors[tpl.key]}>
                            <textarea
                                rows={3}
                                value={form.data[tpl.key] as string}
                                onChange={(e) => form.setData(tpl.key, e.target.value)}
                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                            />
                        </Field>

                        {(form.data[tpl.key] as string) && (
                            <div className="mt-3 flex gap-2 rounded-[12px] bg-[#ecfdf5] px-4 py-3">
                                <MessageCircle className="mt-0.5 size-4 shrink-0 text-[#047857]" />
                                <p className="text-[13px] text-[#065f46]">
                                    {preview(form.data[tpl.key] as string)}
                                </p>
                            </div>
                        )}
                    </Card>
                ))}

                <Card className="p-6">
                    <h3 className="mb-3 font-bold text-[#111]">{t('المتغيّرات المتاحة')}</h3>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(variables).map(([key, label]) => (
                            <span
                                key={key}
                                className="rounded-[8px] bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#4b4b4b]"
                            >
                                <span dir="ltr" className="font-mono">
                                    {key}
                                </span>{' '}
                                — {label}
                            </span>
                        ))}
                    </div>
                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                        {t('ما لا يُعرف من المتغيّرات يبقى مكتوبًا كما هو في الرسالة.')}
                    </p>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" loading={form.processing}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
