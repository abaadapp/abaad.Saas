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
    /** سقوف الباقة — null يعني بلا حدّ */
    max_branches: number | null;
    max_employees: number | null;
    max_products: number | null;
    /**
     * ما تفتحه الباقة فعلًا — و`null` تعني «كلّ شيء» لا «لا شيء».
     *
     * الفرق بينهما هو الفرق بين باقةٍ لم تُضبط بعد وباقةٍ أُغلقت عمدًا.
     */
    capabilities: string[] | null;
}

interface Props {
    plans: Plan[];
    capabilityOptions: { key: string; label: string; hint: string }[];
    currency: Currency;
}

export default function Plans() {
    const { plans, capabilityOptions, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    /** null = مغلق، 'new' = إضافة، أو الباقة المراد تعديلها */
    const [editing, setEditing] = useState<Plan | 'new' | null>(null);

    return (
        <PlatformLayout title="الباقات">
            <PageHeader
                title="الباقات"
                subtitle={t('إدارة باقات الاشتراك والأسعار والمزايا المتاحة للشركات')}
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
                    options={capabilityOptions}
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
function PlanDialog({
    plan,
    options,
    onClose,
}: {
    plan: Plan | null;
    options: { key: string; label: string; hint: string }[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({
        name: plan?.name ?? '',
        monthly_price: plan ? String(plan.monthly) : '',
        yearly_price: plan ? String(plan.yearly) : '',
        color: plan?.color ?? 'primary',
        features: (plan?.features ?? []).join('\n'),
        is_popular: plan?.popular ?? false,
        max_branches: plan?.max_branches != null ? String(plan.max_branches) : '',
        max_employees: plan?.max_employees != null ? String(plan.max_employees) : '',
        max_products: plan?.max_products != null ? String(plan.max_products) : '',
        /*
         * والباقةُ التي لم تُضبط قدراتُها تُقرأ مفتوحةً كلَّها.
         *
         * فتُملأ المربّعات كما تعمل الباقة اليوم لا فارغة: من فتح النافذة
         * ليُغيّر سعرًا وحفظ، يحفظ ما يراه — ولو رآها فارغةً لأقفل على تجّاره
         * كلَّ شيءٍ بضغطةٍ لم يقصدها.
         */
        capabilities: plan?.capabilities ?? options.map((o) => o.key),
    });

    const toggleCapability = (key: string, on: boolean) =>
        form.setData(
            'capabilities',
            on ? [...form.data.capabilities, key] : form.data.capabilities.filter((k) => k !== key),
        );

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

                    {/*
                        السقوف: تُكتب في «المزايا» نصًّا ويقرؤها التاجر وعدًا،
                        وما كان يفرضها شيء لأن الحقول لم تكن في هذه النافذة —
                        فتُولد كل باقةٍ جديدة بلا حدّ.
                    */}
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-[#374151]">
                            {t('السقوف — تُفرض عند الإنشاء')}
                        </legend>
                        <div className="grid grid-cols-3 gap-3">
                            {(
                                [
                                    ['max_branches', 'الفروع'],
                                    ['max_employees', 'الموظفون'],
                                    ['max_products', 'المنتجات'],
                                ] as const
                            ).map(([key, label]) => (
                                <Field key={key} label={label} error={form.errors[key]}>
                                    <Input
                                        type="number"
                                        min="1"
                                        dir="ltr"
                                        placeholder={t('بلا حدّ')}
                                        value={form.data[key]}
                                        onChange={(e) => form.setData(key, e.target.value)}
                                    />
                                </Field>
                            ))}
                        </div>
                        <p className="mt-1.5 text-[12px] text-[#9ca3af]">
                            {t('الفراغ يعني بلا حدّ — واكتبه فراغًا عن قصد لا سهوًا.')}
                        </p>
                    </fieldset>

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

                    {/*
                        القدرات فوق المزايا لا تحتها.

                        `features` سطورٌ حرّة تُقرأ في صفحة التسعير ولا يقرؤها
                        حارس: «تقارير متقدمة» كلمةٌ تُكتب ولا تعمل. وهذه هي ما
                        يُفتح ويُغلق فعلًا — فتُوضع قبلها كي لا يُظنّ أنّ كتابة
                        السطر تكفي.
                    */}
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-[#374151]">
                            {t('ما تفتحه الباقة — يُفرض في النظام')}
                        </legend>
                        <div className="divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                            {options.map((o) => (
                                <label
                                    key={o.key}
                                    className="flex cursor-pointer items-start justify-between gap-3 px-4 py-3"
                                >
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium text-[#374151]">
                                            {t(o.label)}
                                        </span>
                                        <span className="mt-0.5 block text-[12px] text-[#9ca3af]">
                                            {t(o.hint)}
                                        </span>
                                    </span>
                                    <input
                                        type="checkbox"
                                        checked={form.data.capabilities.includes(o.key)}
                                        onChange={(e) => toggleCapability(o.key, e.target.checked)}
                                        className="mt-0.5 size-5 shrink-0 rounded accent-[#111]"
                                    />
                                </label>
                            ))}
                        </div>
                        <p className="mt-1.5 text-[12px] text-[#9ca3af]">
                            {t('المرفوعة تُردّ بـ403 عند التاجر مع رسالةٍ تدلّه على الترقية.')}
                        </p>
                    </fieldset>

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
                        <Button type="submit" loading={form.processing}>
                            {t(plan ? 'حفظ التغييرات' : 'إضافة الباقة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
