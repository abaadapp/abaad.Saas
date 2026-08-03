import { type FormEvent, type ReactNode, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Flower, Image as ImageIcon, Layers, Upload } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface ShopOptions {
    cities: string[];
    statuses: string[];
    plans: string[];
}

export interface ShopValues {
    name: string;
    owner: string;
    city: string;
    phone: string;
    email: string;
    branches: string;
    plan: string;
    status: string;
    start: string;
    end: string;
}

interface Props {
    options: ShopOptions;
    initial: ShopValues;
    logoUrl?: string | null;
    action: string;
    method: 'post' | 'put';
    submitLabel: string;
}

/** نموذج محل الورود — مشترك بين الإنشاء والتعديل، كما في نموذج الشركة */
export default function ShopForm({ options, initial, logoUrl, action, method, submitLabel }: Props) {
    const t = useTranslate();
    const form = useForm<ShopValues & { logo: File | null }>({ ...initial, logo: null });
    const [preview, setPreview] = useState<string | null>(logoUrl ?? null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (method === 'put') {
            form.transform((data) => ({ ...data, _method: 'put' }));
        }
        form.post(action, { forceFormData: true });
    };

    const section = (icon: ReactNode, title: string, children: ReactNode) => (
        <Card className="p-6">
            <div className="mb-5 flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                    {icon}
                </span>
                <h3 className="font-bold text-[#111]">{t(title)}</h3>
            </div>
            {children}
        </Card>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            {section(
                <Flower className="size-5" />,
                'بيانات المحل',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field label="اسم المحل" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثال: زهرة مسقط')}
                            required
                        />
                    </Field>
                    <Field label="اسم المالك" error={form.errors.owner}>
                        <Input
                            value={form.data.owner}
                            onChange={(e) => form.setData('owner', e.target.value)}
                            placeholder={t('الاسم الكامل')}
                        />
                    </Field>
                    <Field label="المدينة" error={form.errors.city}>
                        <Select
                            value={form.data.city}
                            onChange={(e) => form.setData('city', e.target.value)}
                            options={options.cities.map((c) => ({ label: c, value: c }))}
                            placeholder="اختر المدينة…"
                        />
                    </Field>
                    <Field label="رقم الهاتف" error={form.errors.phone}>
                        <Input
                            type="tel"
                            dir="ltr"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            placeholder="+968 9xxxxxxx"
                        />
                    </Field>
                    <Field label="البريد الإلكتروني" error={form.errors.email}>
                        <Input
                            type="email"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="info@example.com"
                        />
                    </Field>
                    <Field label="عدد الفروع" error={form.errors.branches}>
                        <Input
                            type="number"
                            min="1"
                            dir="ltr"
                            value={form.data.branches}
                            onChange={(e) => form.setData('branches', e.target.value)}
                        />
                    </Field>
                </div>,
            )}

            {section(
                <Layers className="size-5" />,
                'الاشتراك والباقة',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field label="الباقة" error={form.errors.plan}>
                        <Select
                            value={form.data.plan}
                            onChange={(e) => form.setData('plan', e.target.value)}
                            options={options.plans.map((p) => ({ label: p, value: p }))}
                            placeholder="اختر الباقة…"
                        />
                    </Field>
                    <Field label="حالة الحساب" error={form.errors.status}>
                        <Select
                            value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}
                            options={options.statuses.map((s) => ({ label: s, value: s }))}
                            placeholder="اختر الحالة…"
                        />
                    </Field>
                    <Field label="تاريخ البداية" error={form.errors.start}>
                        <Input
                            type="date"
                            dir="ltr"
                            value={form.data.start}
                            onChange={(e) => form.setData('start', e.target.value)}
                        />
                    </Field>
                    <Field label="تاريخ الانتهاء" error={form.errors.end}>
                        <Input
                            type="date"
                            dir="ltr"
                            value={form.data.end}
                            onChange={(e) => form.setData('end', e.target.value)}
                        />
                    </Field>
                </div>,
            )}

            {section(
                <ImageIcon className="size-5" />,
                'شعار المحل',
                <>
                    <div className="flex items-center gap-5">
                        {preview && (
                            <img
                                src={preview}
                                alt={t('معاينة الشعار')}
                                className="size-20 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                            />
                        )}
                        <label className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-[16px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] p-8 text-center transition-colors hover:border-[#d1d5db] hover:bg-[#fafafa]">
                            <span className="flex size-12 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#9ca3af]">
                                <Upload className="size-6" />
                            </span>
                            <span className="text-sm font-medium text-[#374151]">
                                {t(preview ? 'تغيير الشعار' : 'اسحب الشعار هنا أو انقر للرفع')}
                            </span>
                            <span className="text-[12px] text-[#9ca3af]">
                                {t('PNG أو JPG بحد أقصى 2 ميجابايت')}
                            </span>
                            <input
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    form.setData('logo', file);
                                    setPreview(file ? URL.createObjectURL(file) : (logoUrl ?? null));
                                }}
                            />
                        </label>
                    </div>
                    {form.errors.logo && <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.logo}</p>}
                </>,
            )}

            <div className="flex items-center justify-end gap-3">
                <Button variant="outline" asChild>
                    <SmartLink
                        routeName="super-admin.flower-shops.index"
                        href={route('super-admin.flower-shops.index')}
                    >
                        {t('إلغاء')}
                    </SmartLink>
                </Button>
                <Button type="submit" loading={form.processing}>
                    <Check />
                    {t(submitLabel)}
                </Button>
            </div>
        </form>
    );
}
