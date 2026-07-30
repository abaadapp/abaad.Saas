import { type FormEvent, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Layers, Pencil, Plus, RefreshCw, Star } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency, PageProps } from '@/types';

interface Plan {
    id: number;
    name: string;
    monthly: number;
    yearly: number;
    color: string;
    popular: boolean;
    features: string[];
}

interface Props {
    plans: Plan[];
    currency: Currency;
}

export default function Plans() {
    const { plans, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    /** null = مغلق، 'new' = إضافة، أو الباقة المراد تعديلها */
    const [editing, setEditing] = useState<Plan | 'new' | null>(null);

    return (
        <PlatformLayout title="الباقات">
            <PageHeader
                title="الباقات"
                subtitle={t('إدارة باقات الاشتراك والأسعار والمزايا المتاحة للشركات')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'الاشتراكات', href: route('super-admin.subscriptions.index') },
                    { label: 'الباقات' },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.index"
                                href={route('super-admin.subscriptions.index')}
                            >
                                <RefreshCw />
                                {t('الاشتراكات')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            {t('باقة جديدة')}
                        </Button>
                    </>
                }
            />

            {plans.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Layers className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد باقات بعد')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أضف أول باقة اشتراك لتظهر للشركات عند التسجيل.')}
                    </p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <Card
                            key={plan.id}
                            className={cn(
                                'relative flex flex-col p-6',
                                plan.popular && 'ring-2 ring-[#111]',
                            )}
                        >
                            {plan.popular && (
                                <span className="absolute -top-3 end-6 inline-flex items-center gap-1 rounded-full bg-[#111] px-3 py-1 text-[12px] font-semibold text-white">
                                    <Star className="size-3.5" />
                                    {t('الأكثر شيوعًا')}
                                </span>
                            )}

                            <div className="mb-4 flex items-center gap-3">
                                <span className="flex size-12 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                                    <Layers className="size-6" />
                                </span>
                                <h3 className="text-[18px] font-bold text-[#111]">{t(plan.name)}</h3>
                            </div>

                            <div className="mb-1">
                                <span className="text-[28px] font-bold tabular-nums text-[#111]">
                                    {money(plan.monthly, currency)}
                                </span>
                                <span className="ms-1 text-sm text-[#9ca3af]">{t('/ شهريًا')}</span>
                            </div>
                            <p className="mb-5 text-sm text-[#6b7280]">
                                {t('أو')} {money(plan.yearly, currency)} {t('سنويًا')}
                            </p>

                            <ul className="mb-6 flex-1 space-y-3">
                                {plan.features.map((f) => (
                                    <li key={f} className="flex items-center gap-2 text-sm text-[#4b4b4b]">
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[#f2f2f0] text-[#111]">
                                            <Check className="size-3.5" />
                                        </span>
                                        {t(f)}
                                    </li>
                                ))}
                            </ul>

                            <Button
                                variant={plan.popular ? 'primary' : 'outline'}
                                className="w-full"
                                onClick={() => setEditing(plan)}
                            >
                                <Pencil />
                                {t('تعديل الباقة')}
                            </Button>
                        </Card>
                    ))}
                </div>
            )}

            {editing && (
                <PlanDialog
                    plan={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </PlatformLayout>
    );
}

/**
 * نافذة الباقة — تُستخدم للإضافة والتعديل.
 *
 * نافذة التعديل في القالب كانت واجهة بلا أثر: قيمها ثابتة (الباقة الاحترافية
 * و24.900 …) مهما كانت الباقة المضغوطة، وبلا action، وزرّ الحفظ يعرض toast
 * نجاح فقط. صارت تُحمَّل من الباقة نفسها وتُرسل إلى plans.update.
 */
function PlanDialog({ plan, onClose }: { plan: Plan | null; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({
        name: plan?.name ?? '',
        monthly_price: plan ? String(plan.monthly) : '',
        yearly_price: plan ? String(plan.yearly) : '',
        color: plan?.color ?? 'primary',
        features: (plan?.features ?? []).join('\n'),
        is_popular: plan?.popular ?? false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const done = { onSuccess: () => onClose() };
        if (plan) {
            form.put(route('super-admin.plans.update', plan.id), done);
        } else {
            form.post(route('super-admin.plans.store'), done);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(plan ? 'تعديل الباقة' : 'إضافة باقة اشتراك جديدة')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field label="اسم الباقة" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثال: الباقة الاحترافية')}
                            required
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="السعر الشهري" required error={form.errors.monthly_price}>
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={form.data.monthly_price}
                                onChange={(e) => form.setData('monthly_price', e.target.value)}
                                required
                            />
                        </Field>
                        <Field label="السعر السنوي" required error={form.errors.yearly_price}>
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={form.data.yearly_price}
                                onChange={(e) => form.setData('yearly_price', e.target.value)}
                                required
                            />
                        </Field>
                    </div>

                    <Field label="اللون" error={form.errors.color}>
                        <Select
                            value={form.data.color}
                            onChange={(e) => form.setData('color', e.target.value)}
                            options={[
                                { label: 'أساسي', value: 'primary' },
                                { label: 'ثانوي', value: 'secondary' },
                            ]}
                        />
                    </Field>

                    <Field label="المزايا (ميزة في كل سطر)" error={form.errors.features}>
                        <Textarea
                            rows={5}
                            value={form.data.features}
                            onChange={(e) => form.setData('features', e.target.value)}
                            placeholder={`${t('عدد فروع غير محدود')}\n${t('تقارير متقدمة')}\n${t('دعم فني على مدار الساعة')}`}
                        />
                    </Field>

                    <label className="flex cursor-pointer items-center justify-between rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                        <span className="text-sm font-medium text-[#374151]">
                            {t('باقة مميّزة (الأكثر شيوعًا)')}
                        </span>
                        <input
                            type="checkbox"
                            checked={form.data.is_popular}
                            onChange={(e) => form.setData('is_popular', e.target.checked)}
                            className="size-5 rounded accent-[#111]"
                        />
                    </label>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? '…' : t(plan ? 'حفظ التغييرات' : 'إضافة الباقة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
